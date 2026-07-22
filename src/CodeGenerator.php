<?php

declare(strict_types=1);

class CodeGenerator implements ASTVisitor
{
    private string $className = '';
    /** 当前类的 PHP 名（带命名空间，用于 __CLASS__/__METHOD__ 输出原始类名） */
    private string $phpClassName = '';
    private int $indent = 0;
    private int $scopeDepth = 0; // 嵌套块深度（for/while/if/foreach 体内为 1+）
    private string $phpFile = '';

    /** 变量类型追踪：varName → className（对象）或 C 类型（基础类型） */
    private array $varTypes = [];
    /** 当前方法名字（用于 __METHOD__） */
    private string $currentMethodName = '';
    /** P2-6: 当前 PHP 函数名（全局函数用，方法用 currentMethodName） */
    private string $currentFuncName = '';
    /** P2-6: 当前是否在类方法内（区分 __FUNCTION__/__METHOD__ 语义） */
    private bool $inMethod = false;
    /** P2-6: 当前 PHP 命名空间名（用于 __NAMESPACE__） */
    private string $currentNamespace = '';
    /** 数组元素类型追踪：varName → C 类型（int key 的默认类型） */
    private array $arrElementTypes = [];
    /** 实例属性数组元素类型追踪："cn::prop" → CType
     *  用于 $this->prop[$key] 和 $obj->prop[$key] 的元素类型推断
     *  （arrElementTypes 仅追踪局部变量，不覆盖属性访问） */
    private array $propArrElementTypes = [];
    /** 数组 per-key 类型追踪：arrVarName → [strKey → CType]（字符串键专用） */
    private array $arrValueTypes = [];
    /** 嵌套数组元素类型追踪：arrVarName → CType（当数组元素是数组时，记录子数组的元素类型） */
    private array $arrNestedTypes = [];
    /** 多层嵌套数组深度追踪：arrVarName → ['depth' => N, 'leafType' => CType]
     *  用于正确推断 $arr[0][1][2] 等深层访问的元素类型 */
    private array $arrNestedDepth = [];
    /** 数组字面量 AST 追踪：arrVarName → ArrayLiteralExpr
     *  用于精确追踪嵌套访问 $m["items"][0]["id"] 中特定键的值类型
     *  （当叶子层为混合类型关联数组时，inferArrayElementType 只能返回单一类型，无法区分 "id"=>int 与 "name"=>string） */
    private array $arrLiteralAST = [];
    /** 函数返回数组的 per-key 类型追踪：fnCName → [strKey → CType]
     *  当函数 return ["key" => $val, ...] 时记录，供调用者 $var = func() 后 $var["key"] 类型推断 */
    private array $fnReturnArrKeyTypes = [];
    /** 当前函数/方法的 C 名（用于 fnReturnArrKeyTypes 注册） */
    private string $currentFuncCName = '';
    /** 已声明变量集合 */
    private array $declaredVars = [];
    /** for 循环提升到函数作用域的变量声明：varName => cType */
    private array $funcScopeDecls = [];
    /** defer 栈：当前函数内已注册的 defer 清理代码（LIFO 执行） */
    private array $deferStack = [];
    /** C 指针所有权追踪：varName => ['type' => cType, 'cleaned' => bool, 'line' => int]
     *  用于编译期泄漏提醒：函数末尾扫描未清理的 transfer 指针 */
    private array $cPtrOwnership = [];
    /** 函数内 const 常量名集合：name => true（用于 visitVariable 区分局部 const 与全局 const） */
    private array $localConsts = [];
    /** 当前 __construct 内已赋值的 readonly 属性集合: "className::propName" => true */
    private array $assignedReadonlyProps = [];

    // ── 统一符号表 ──────────────────────────────────────────
    // 替代了 13 个散落的类型追踪数组
    private SymbolTable $symbols;

    private bool $inGenerator = false;     // 当前是否在生成器入口函数体内

    /** Property Hook 追踪：className → [propName → ['get' => bool, 'set' => bool]] */
    private array $hookedProps = [];
    /** 当前是否在 hook 体内（hook 体内 $this->prop 直接访问 backing field） */
    private bool $inHookBody = false;

    /** ProgramNode 引用（visitConst 扫描注解使用） */
    private ?ProgramNode $program = null;
    /** 注解常量注册表：shortName/FQName → [
     *      'fqName' => string, 'shortName' => string,
     *      'constName' => 'TPHP_CONST_XXX', 'initFn' => '_annot_XXX_init',
     *      'entryVarPrefix' => '_annot_XXX_',
     *      'entries' => [ ['kind'=>'method'|'static_method'|'class'|'function',
     *                       'class'=>string,'method'=>string,'function'=>string,
     *                       'namespace'=>string,'name'=>string,'args'=>ExprNode[]], ... ]
     *  ] */
    private array $annotationRegistry = [];
    /** 注解初始化函数列表（generateCEntry 中调用） */
    private array $annotationInitFns = [];
    /** 已导入的 PDO 驱动 C init 函数列表（在 main() 入口自动调用，类似 PHP MINIT） */
    private array $pdoDriverInits = [];
    /** 变量 → 注解常量名追踪（foreach 遍历注解数组时，记录 $v 来自哪个注解常量） */
    private array $varAnnotSource = [];

    /** 循环/switch end label 栈（支持 break N; / continue N;） */
    private array $loopEndLabelStack = [];
    /** 循环 start label 栈（支持 continue N;） */
    private array $loopStartLabelStack = [];
    /** 循环 continue label 栈（continue N; 跳到第 N 层外层的 step 前） */
    private array $loopContLabelStack = [];

    /** 是否 -shared 共享库模式（生成导出 trampoline + 库自动初始化） */
    public bool $isShared = false;

    /** 字面量 → C 类型的映射 */
    private static array $litTypeMap = [
        IntLiteralExpr::class    => 't_int',
        FloatLiteralExpr::class  => 't_float',
        StringLiteralExpr::class => 't_string',
        BoolLiteralExpr::class   => 't_bool',
        MagicConstExpr::class    => 't_string',
    ];

    private static array $typeMap = [
        'int' => 't_int', 'float' => 't_float', 'string' => 't_string',
        'bool' => 't_bool', 'void' => 'void', 'never' => 'void', 'array' => 't_array*',
        'mixed' => 't_var', 'null' => 'void*',
        'Generator' => 'tphp_class_Generator*',
    ];

    /** 内置函数返回类型注册表（替代 inferCallReturnType 中的 140+ if-else） */
    private static array $builtinRetTypes = [
        // ── t_int ──
        'time' => 't_int', 'hrtime' => 't_int', 'count' => 't_int', 'sleep' => 't_int', 'usleep' => 't_int',
        'array_push' => 't_int', 'array_unshift' => 't_int', 'mb_strlen' => 't_int', 'filter_id' => 't_int',
        'strlen' => 't_int', 'strpos' => 't_int', 'strrpos' => 't_int',
        'stripos' => 't_int', 'strripos' => 't_int',
        'abs' => 't_int', 'array_search' => 't_int',
        'intval' => 't_int', 'rand' => 't_int', 'mt_rand' => 't_int', 'random_int' => 't_int',
        'intdiv' => 't_int', 'ord' => 't_int', 'bindec' => 't_int', 'hexdec' => 't_int', 'octdec' => 't_int',
        'array_key_first' => 't_int', 'array_key_last' => 't_int', 'strtotime' => 't_int', 'mktime' => 't_int',
        'substr_count' => 't_int', 'crc32' => 't_int', 'preg_last_error' => 't_int',
        'iconv_strlen' => 't_int', 'iconv_strpos' => 't_int',
        'zip_num_files' => 't_int',
        // ── zlib gz/增量 API int 返回 ──
        'gzwrite' => 't_int', 'gzputs' => 't_int', 'gzseek' => 't_int',
        'gztell' => 't_int', 'gzpassthru' => 't_int', 'readgzfile' => 't_int',
        'inflate_get_status' => 't_int', 'inflate_get_read_len' => 't_int',
        // ── zip 新增 int 返回 ──
        'zip_entry_filesize' => 't_int', 'zip_entry_compressedsize' => 't_int', 'zip_locate' => 't_int',
        // ── t_string ──
        'date' => 't_string', 'implode' => 't_string', 'join' => 't_string', 'json_encode' => 't_string',
        'htmlspecialchars' => 't_string', 'nl2br' => 't_string', 'base64_encode' => 't_string',
        'base64_decode' => 't_string', 'http_build_query' => 't_string', 'sha256' => 't_string', 'sha512' => 't_string',
        'password_hash' => 't_string', 'base_convert' => 't_string', 'mb_substr' => 't_string', 'hash_hmac' => 't_string',
        'sprintf' => 't_string', 'str_replace' => 't_string', 'strtolower' => 't_string', 'strtoupper' => 't_string',
        'trim' => 't_string', 'ltrim' => 't_string', 'rtrim' => 't_string', 'substr' => 't_string',
        'file_get_contents' => 't_string', 'strval' => 't_string', 'chr' => 't_string', 'getenv' => 't_string',
        'decbin' => 't_string', 'decoct' => 't_string', 'dechex' => 't_string', 'number_format' => 't_string',
        'uniqid' => 't_string', 'ucfirst' => 't_string', 'lcfirst' => 't_string', 'strrev' => 't_string',
        'str_repeat' => 't_string', 'str_pad' => 't_string', 'str_shuffle' => 't_string',
        'addslashes' => 't_string', 'stripslashes' => 't_string', 'bin2hex' => 't_string', 'hex2bin' => 't_string',
        'urlencode' => 't_string', 'urldecode' => 't_string', 'md5' => 't_string', 'sha1' => 't_string',
        'strtr' => 't_string', 'preg_replace' => 't_string', 'preg_quote' => 't_string',
        'preg_last_error_msg' => 't_string', 'php_str' => 't_string', 'php_str_clone' => 't_string',
        'random_bytes' => 't_string', 'gettype' => 't_string',
        // ── iconv (内置) ──
        'iconv' => 't_string', 'iconv_substr' => 't_string',
        'iconv_mime_encode' => 't_string', 'iconv_mime_decode' => 't_string',
        // ── fileinfo (内置) ──
        'finfo_file' => 't_string', 'finfo_buffer' => 't_string',
        'mime_content_type' => 't_string',
        // ── zlib (gzip) 压缩/解压 ──
        'gzcompress' => 't_string', 'gzuncompress' => 't_string',
        'gzencode' => 't_string', 'gzdecode' => 't_string',
        'gzdeflate' => 't_string', 'gzinflate' => 't_string',
        // ── zlib encode/decode 别名 + gz 文件流 + 增量上下文 string 返回 ──
        'zlib_encode' => 't_string', 'zlib_decode' => 't_string',
        'gzread' => 't_string', 'gzgets' => 't_string', 'gzgetc' => 't_string',
        'deflate_add' => 't_string', 'inflate_add' => 't_string',
        // ── zip 字符串返回 ──
        'zip_entry_read' => 't_string', 'zip_get_error_string' => 't_string',
        'zip_entry_name' => 't_string', 'zip_entry_compressionmethod' => 't_string',
        // ── t_bool ──
        'shuffle' => 't_bool', 'json_validate' => 't_bool', 'password_verify' => 't_bool',
        'in_array' => 't_bool', 'array_key_exists' => 't_bool', 'str_contains' => 't_bool',
        'boolval' => 't_bool', 'str_starts_with' => 't_bool', 'str_ends_with' => 't_bool',
        'array_is_list' => 't_bool', 'file_put_contents' => 't_bool', 'unlink' => 't_bool',
        'iconv_set_encoding' => 't_bool',
        'finfo_set_flags' => 't_bool',
        // ── zip bool 返回 ──
        'zip_close' => 't_bool', 'zip_entry_open' => 't_bool', 'zip_entry_close' => 't_bool',
        'zip_add_file' => 't_bool', 'zip_add_dir' => 't_bool',
        'zip_delete' => 't_bool', 'zip_rename' => 't_bool',
        // ── zlib gz bool 返回 ──
        'gzclose' => 't_bool', 'gzeof' => 't_bool',
        'gzrewind' => 't_bool', 'gzflush' => 't_bool',
        // ── t_float ──
        'sin' => 't_float', 'cos' => 't_float', 'tan' => 't_float', 'asin' => 't_float', 'acos' => 't_float',
        'atan' => 't_float', 'exp' => 't_float', 'log' => 't_float', 'log10' => 't_float', 'fmod' => 't_float',
        'microtime' => 't_float', 'pi' => 't_float', 'deg2rad' => 't_float', 'rad2deg' => 't_float',
        'round' => 't_float', 'ceil' => 't_float', 'floor' => 't_float', 'sqrt' => 't_float', 'floatval' => 't_float',
        // ── t_array* ──
        'array_keys' => 't_array*', 'array_values' => 't_array*', 'array_merge' => 't_array*',
        'array_map' => 't_array*', 'array_filter' => 't_array*', 'array_reverse' => 't_array*',
        'array_slice' => 't_array*', 'array_unique' => 't_array*', 'range' => 't_array*',
        'array_fill' => 't_array*', 'explode' => 't_array*', 'array_diff' => 't_array*',
        'array_intersect' => 't_array*', 'array_column' => 't_array*', 'array_flip' => 't_array*',
        'array_chunk' => 't_array*', 'array_combine' => 't_array*', 'array_count_values' => 't_array*',
        'array_pad' => 't_array*',
        'filter_list' => 't_array*', 'str_split' => 't_array*', 'parse_url' => 't_array*',
        'parse_str' => 't_array*', 'preg_match' => 't_array*', 'preg_match_all' => 't_array*',
        'preg_split' => 't_array*', 'preg_grep' => 't_array*',
        'iconv_get_encoding' => 't_array*',
        // ── zip 数组返回 ──
        'zip_read' => 't_array*', 'zip_stat' => 't_array*',
        // ── zlib gz 数组返回 ──
        'gzfile' => 't_array*',
        // ── stream (内置 ext) ──
        'stream_last_error' => 't_int', 'stream_set_read_buffer' => 't_int',
        'stream_set_write_buffer' => 't_int',
        'stream_select' => 't_int', 'stream_socket_server' => 't_int',
        'stream_socket_accept' => 't_int', 'stream_socket_client' => 't_int',
        'stream_socket_sendto' => 't_int', 'stream_socket_enable_crypto' => 't_int',
        'stream_context_create' => 't_int',
        'stream_strerror' => 't_string', 'stream_socket_recvfrom' => 't_string',
        'stream_socket_get_name' => 't_string',
        'stream_get_contents' => 't_string', 'stream_get_line' => 't_string',
        'stream_set_blocking' => 't_bool', 'stream_isatty' => 't_bool',
        'stream_set_timeout' => 't_bool', 'stream_socket_shutdown' => 't_bool',
        'stream_get_meta_data' => 't_array*', 'stream_socket_pair' => 't_array*',
        'stream_close' => 'void',
        // ── openssl (内置 ext, TLS/加密) ──
        'openssl_ctx_new' => 't_int', 'openssl_ctx_set_options' => 't_int',
        'openssl_ssl_new' => 't_int', 'openssl_ssl_connect' => 't_int',
        'openssl_ssl_accept' => 't_int', 'openssl_ssl_write' => 't_int',
        'openssl_error_string' => 't_string', 'openssl_ssl_get_cipher_name' => 't_string',
        'openssl_ssl_get_version' => 't_string', 'openssl_encrypt' => 't_string',
        'openssl_decrypt' => 't_string', 'openssl_random_pseudo_bytes' => 't_string',
        'openssl_digest' => 't_string',
        'openssl_ctx_use_certificate_file' => 't_bool',
        'openssl_ctx_use_private_key_file' => 't_bool',
        'openssl_ssl_set_fd' => 't_bool', 'openssl_ssl_shutdown' => 't_bool',
        'openssl_ctx_free' => 'void', 'openssl_ssl_free' => 'void',
        'openssl_ctx_set_verify' => 'void',
        // ── pdo (内置 ext, SQLite 驱动) ──
        //   指针以 t_int 句柄形式在 PHP 层流转（phpc_ptr_to_int/phpc_int_to_ptr 转换）
        //   const char* 返回为借用指针，由 php_str()/pdo_str_from_ptr() 转为 t_string
        'pdo_open_db' => 't_int', 'pdo_prepare' => 't_int',
        'pdo_exec' => 't_int', 'pdo_str_len' => 't_int',
        'pdo_bind_text' => 't_int', 'pdo_bind_blob' => 't_int',
        'pdo_bind_params' => 'void',
        'pdo_str_from_ptr' => 't_string', 'pdo_sqlite_errstate' => 't_string',
        'pdo_quote' => 't_string', 'pdo_column_double' => 't_float',
        'pdo_column_text' => 'const char*', 'pdo_column_name' => 'const char*',
        'pdo_column_decltype' => 'const char*', 'pdo_errmsg' => 'const char*',
        'pdo_libversion' => 'const char*',
        'pdo_throw_msg' => 'void', 'pdo_throw_db_error' => 'void',
        'pdo_throw_stmt_error' => 'void',
        // ── PDO driver 抽象层 ──
        //   通过 driver 函数指针表分发，支持 sqlite/mysql/pgsql...
        //   void 返回类型无需注册：pdo_driver_close/reset/clear_bindings/finalize
        //                              /busy_timeout/extended_result_codes/bind_params
        'pdo_driver_find' => 't_int',
        'pdo_driver_open' => 't_int',
        'pdo_driver_exec' => 't_int',
        'pdo_driver_prepare' => 't_int',
        'pdo_driver_bind_int' => 't_int',
        'pdo_driver_bind_text' => 't_int',
        'pdo_driver_bind_blob' => 't_int',
        'pdo_driver_bind_null' => 't_int',
        'pdo_driver_bind_param_index' => 't_int',
        'pdo_driver_step' => 't_int',
        'pdo_driver_column_count' => 't_int',
        'pdo_driver_column_type' => 't_int',
        'pdo_driver_column_int64' => 't_int',
        'pdo_driver_column_bytes' => 't_int',
        'pdo_driver_data_count' => 't_int',
        'pdo_driver_changes' => 't_int',
        'pdo_driver_last_insert_rowid' => 't_int',
        'pdo_driver_errcode' => 't_int',
        'pdo_driver_column_double' => 't_float',
        'pdo_driver_column_text' => 'const char*',
        'pdo_driver_column_name' => 'const char*',
        'pdo_driver_column_decltype' => 'const char*',
        'pdo_driver_errmsg' => 'const char*',
        'pdo_driver_last_open_error' => 'const char*',
        'pdo_driver_name' => 'const char*',
        'pdo_driver_server_version' => 'const char*',
        'pdo_driver_quote' => 't_string',
        // ── sqlite3 (内置 ext, 函数式 SQLite API) ──
        //   sqlite3* 指针以 t_int 句柄形式在 PHP 层流转（与 pdo 一致的转换模式）
        //   查询结果返回 t_array*，元素类型在 $builtinArrElemTypes 注册
        'sqlite_open' => 't_int', 'sqlite_close' => 'void',
        'sqlite_exec' => 't_bool',
        'sqlite_query' => 't_array*', 'sqlite_query_single' => 't_array*',
        'sqlite_escape_string' => 't_string',
        'sqlite_changes' => 't_int', 'sqlite_last_insert_rowid' => 't_int',
        'sqlite_last_error_msg' => 't_string', 'sqlite_last_error_code' => 't_int',
        'sqlite_version' => 't_string',
        // ── posix (内置 ext, POSIX 系统函数) ──
        'posix_getpid' => 't_int', 'posix_getppid' => 't_int',
        'posix_getuid' => 't_int', 'posix_geteuid' => 't_int',
        'posix_getgid' => 't_int', 'posix_getegid' => 't_int',
        'posix_isatty' => 't_int', 'posix_kill' => 't_int',
        'posix_get_last_error' => 't_int',
        'posix_getcwd' => 't_string', 'posix_strerror' => 't_string',
        'posix_ttyname' => 't_string',
        // ── pcntl (内置 ext, 进程控制, POSIX only) ──
        'pcntl_fork' => 't_int', 'pcntl_waitpid' => 't_int',
        'pcntl_wait' => 't_int', 'pcntl_alarm' => 't_int',
        'pcntl_get_last_error' => 't_int',
        'pcntl_strerror' => 't_string',
        'pcntl_exec' => 'void',
        'phpc_new_arr_int' => 't_array*', 'phpc_new_arr_dbl' => 't_array*',
        'phpc_new_arr_str' => 't_array*', 'phpc_new_arr' => 't_array*',
        // ── t_var ──
        'array_pop' => 't_var', 'array_shift' => 't_var', 'array_sum' => 't_var', 'array_product' => 't_var',
        'max' => 't_var', 'min' => 't_var', 'json_decode' => 't_var', 'array_rand' => 't_var',
        'current' => 't_var', 'key' => 't_var', 'next' => 't_var', 'prev' => 't_var',
        'end' => 't_var', 'reset' => 't_var', 'pow' => 't_var', 'filter_var' => 't_var',
        // ── void ──
        'print_r' => 'void', 'var_dump' => 'void', 'sort' => 'void', 'rsort' => 'void',
        'asort' => 'void', 'arsort' => 'void', 'ksort' => 'void', 'krsort' => 'void',
        'putenv' => 'void', 'phpc_unregister_obj' => 'void', 'phpc_free' => 'void', 'phpc_free_str_arr' => 'void',
        'phpc_obj_steal' => 'void', 'phpc_env_unpin' => 'void',
        'finfo_close' => 'void',
        // ── t_object / t_callback / null (指针/无返回) ──
        'phpc_new_obj' => 't_object',
        'finfo_open' => 'tphp_class_Resource*',
        'zip_open' => 'tphp_class_Resource*',
        // ── zlib Resource 返回（gz 文件流 + 增量上下文）──
        'gzopen' => 'tphp_class_Resource*',
        'deflate_init' => 'tphp_class_Resource*', 'inflate_init' => 'tphp_class_Resource*',
        'phpc_new_fn' => 't_callback', 'phpc_new_fn_env' => 't_callback',
        'phpc_arr_int' => 'null', 'phpc_arr_dbl' => 'null', 'phpc_arr_str' => 'null', 'phpc_obj' => 'null',
        'phpc_fn' => 'null', 'phpc_env' => 'null', 'phpc_fn_i32' => 'null', 'phpc_fn_i64' => 'null', 'phpc_fn_f64' => 'null',
        'phpc_thunk' => 'null',
        'phpc_assert_ptr' => 'null', 'phpc_env_pin' => 'null',
        'phpc_auto' => 'null',
        'phpc_ptr_to_int' => 't_int', 'phpc_int_to_ptr' => 'null',
        // ── phpc 互操作 ──
        'c_int' => 't_int', 'php_int' => 't_int',
        'c_str' => 'const char*', 'php_str' => 't_string', 'php_str_ptr' => 't_string',
        'c_void_ptr' => 'void*',
    ];

    /** 内置函数返回数组的元素类型注册表（替代 visitAssign 中的 switch-case） */
    private static array $builtinArrElemTypes = [
        'array_keys' => 't_int', 'array_values' => 't_int', 'array_merge' => 't_int',
        'explode' => 't_string', 'preg_match' => 't_string', 'preg_split' => 't_string',
        'preg_grep' => 't_string', 'filter_list' => 't_string',
        'gzfile' => 't_string',
        'stream_socket_pair' => 't_int',
        // ── PDO 方法返回数组的元素类型（方法 C 名作为键）──
        //   fetch() 返回 array<string>（所有列值统一转为字符串）
        'tphp_class_PDOStatement_fetch' => 't_string',
        //   fetchAll() 返回 array<array<string>>（外层元素是数组，内层元素是字符串）
        'tphp_class_PDOStatement_fetchAll' => 't_array*',
        'tphp_class_PDOStatement_fetchAll[]' => 't_string',
        //   errorInfo() 返回 array<string|int>（混合类型，默认按 int 访问）
        'tphp_class_PDO_errorInfo' => 't_string',
        'tphp_class_PDOStatement_errorInfo' => 't_string',
        'tphp_class_PDOStatement_getColumnMeta' => 't_string',
        //   getAvailableDrivers() 返回 array<string>（驱动名列表）
        'tphp_class_PDO_getAvailableDrivers' => 't_string',
        // ── sqlite3 函数返回数组的元素类型 ──
        //   sqlite_query() 返回 array<array<string>>（外层是行数组，内层是列值字符串）
        'sqlite_query' => 't_array*',
        'sqlite_query[]' => 't_string',
        //   sqlite_query_single() 返回 array<string>（单行，元素是列值字符串）
        'sqlite_query_single' => 't_string',
    ];

    /**
     * 简单转发函数映射表（visitCall 第二步拆分）
     *
     * 每个条目字段：
     *  - cName:       C 函数名（必填）
     *  - modes:       按参数位置的 argMode 数组（缺省='direct'）；不设置=变长全 direct
     *  - defaults:    缺省参数默认值 [位置 => C 字面量]
     *  - order:       输出参数重排顺序（如 [1,0] 表示先 arg1 再 arg0）
     *  - cNameNoArgs: 0 参时的 C 函数名（如 uniqid → uniqid0）
     *
     * argMode 取值：direct | data | floatcast | wrapvar | wraparr
     */
    private static array $simpleFnMap = [
        // ── 变长 direct（无 modes，参数全透传）──
        'count'              => ['cName' => 'tphp_fn_arr_count', 'modes' => ['direct'], 'dispatch' => 'count'],
        'array_chunk'        => ['cName' => 'tphp_fn_arr_chunk'],
        'array_combine'      => ['cName' => 'tphp_fn_arr_combine'],
        'array_count_values' => ['cName' => 'tphp_fn_arr_count_values'],
        'array_pad'          => ['cName' => 'tphp_fn_arr_pad', 'modes' => ['direct', 'direct', 'wrapvar']],
        'filter_id'          => ['cName' => 'tphp_fn_filter_id'],
        'iconv'              => ['cName' => 'tphp_fn_iconv'],
        'iconv_set_encoding' => ['cName' => 'tphp_fn_iconv_set_encoding'],
        // ── fileinfo (内置) ──
        'mime_content_type'  => ['cName' => 'tphp_fn_mime_content_type', 'modes' => ['direct']],
        'finfo_close'        => ['cName' => 'tphp_fn_finfo_close', 'modes' => ['direct']],
        'finfo_set_flags'    => ['cName' => 'tphp_fn_finfo_set_flags', 'modes' => ['direct', 'direct']],
        'finfo_open'         => ['cName' => 'tphp_fn_finfo_open', 'modes' => ['direct', 'direct'], 'defaults' => [0 => 'TPHP_CONST_FILEINFO_NONE', 1 => '(t_string){0}']],
        'finfo_file'         => ['cName' => 'tphp_fn_finfo_file', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => 'TPHP_CONST_FILEINFO_NONE']],
        'finfo_buffer'       => ['cName' => 'tphp_fn_finfo_buffer', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => 'TPHP_CONST_FILEINFO_NONE']],
        // ── 0 参 ──
        'time'               => ['cName' => 'tphp_fn_time'],
        'hrtime'             => ['cName' => 'tphp_fn_hrtime'],
        'filter_list'        => ['cName' => 'tphp_fn_filter_list'],
        // ── 单参 direct ──
        'array_keys'         => ['cName' => 'tphp_fn_array_keys', 'modes' => ['direct'], 'dispatch' => 'array_keys'],
        'array_values'       => ['cName' => 'tphp_fn_array_values', 'modes' => ['direct']],
        'array_sum'          => ['cName' => 'tphp_fn_arr_sum', 'modes' => ['direct']],
        'array_product'      => ['cName' => 'tphp_fn_arr_product', 'modes' => ['direct']],
        'array_unique'       => ['cName' => 'tphp_fn_arr_unique', 'modes' => ['direct']],
        'max'                => ['cName' => 'tphp_fn_max', 'modes' => ['direct'], 'dispatch' => 'variadic_pack'],
        'min'                => ['cName' => 'tphp_fn_min', 'modes' => ['direct'], 'dispatch' => 'variadic_pack'],
        'strlen'             => ['cName' => 'tphp_fn_strlen', 'modes' => ['direct']],
        'trim'               => ['cName' => 'tphp_fn_trim', 'modes' => ['direct']],
        'ltrim'              => ['cName' => 'tphp_fn_ltrim', 'modes' => ['direct']],
        'rtrim'              => ['cName' => 'tphp_fn_rtrim', 'modes' => ['direct']],
        'random_bytes'       => ['cName' => 'tphp_fn_random_bytes', 'modes' => ['direct']],
        'sort'               => ['cName' => 'tphp_fn_sort', 'modes' => ['direct']],
        'rsort'              => ['cName' => 'tphp_fn_rsort', 'modes' => ['direct']],
        'shuffle'            => ['cName' => 'tphp_fn_shuffle', 'modes' => ['direct']],
        'json_decode'        => ['cName' => 'tphp_fn_json_decode', 'modes' => ['direct']],
        'array_is_list'      => ['cName' => 'tphp_fn_array_is_list_int', 'modes' => ['direct']],
        'crc32'              => ['cName' => 'tphp_fn_crc32_str', 'modes' => ['direct']],
        // ── 单参 wrapvar ──
        'print_r'            => ['cName' => 'tphp_fn_print_r', 'modes' => ['wrapvar']],
        'json_encode'        => ['cName' => 'tphp_fn_json_encode', 'modes' => ['wrapvar']],
        'intval'             => ['cName' => 'tphp_fn_intval', 'modes' => ['wrapvar']],
        'floatval'           => ['cName' => 'tphp_fn_floatval', 'modes' => ['wrapvar']],
        'strval'             => ['cName' => 'tphp_fn_strval', 'modes' => ['wrapvar']],
        'boolval'            => ['cName' => 'tphp_fn_boolval', 'modes' => ['wrapvar']],
        // ── 单参 data ──
        'file_get_contents'  => ['cName' => 'tphp_fn_file_get_contents', 'modes' => ['data']],
        // ── 单参 floatcast ──
        'deg2rad'            => ['cName' => 'tphp_fn_deg2rad', 'modes' => ['floatcast']],
        'rad2deg'            => ['cName' => 'tphp_fn_rad2deg', 'modes' => ['floatcast']],
        // ── 单参带默认值 ──
        'exit'               => ['cName' => 'tphp_fn_exit', 'modes' => ['direct'], 'defaults' => [0 => '0']],
        'die'                => ['cName' => 'tphp_fn_exit', 'modes' => ['direct'], 'defaults' => [0 => '0']],
        'sleep'              => ['cName' => 'tphp_fn_sleep', 'modes' => ['direct'], 'defaults' => [0 => '0']],
        'usleep'             => ['cName' => 'tphp_fn_usleep', 'modes' => ['direct'], 'defaults' => [0 => '0']],
        'iconv_get_encoding' => ['cName' => 'tphp_fn_iconv_get_encoding', 'modes' => ['direct'], 'defaults' => [0 => 'STR_LIT("all")']],
        // ── uniqid：0 参走 uniqid0，否则 uniqid(arg) ──
        'uniqid'             => ['cName' => 'tphp_fn_uniqid', 'cNameNoArgs' => 'tphp_fn_uniqid0', 'modes' => ['direct']],
        // ── 双参 direct ──
        'array_merge'        => ['cName' => 'tphp_fn_array_merge', 'modes' => ['direct', 'direct']],
        'strpos'             => ['cName' => 'tphp_fn_strpos', 'modes' => ['direct', 'direct']],
        'strrpos'            => ['cName' => 'tphp_fn_strrpos', 'modes' => ['direct', 'direct']],
        'stripos'            => ['cName' => 'tphp_fn_stripos', 'modes' => ['direct', 'direct']],
        'strripos'           => ['cName' => 'tphp_fn_strripos', 'modes' => ['direct', 'direct']],
        'str_contains'       => ['cName' => 'tphp_fn_str_contains', 'modes' => ['direct', 'direct']],
        'implode'            => ['cName' => 'tphp_fn_implode', 'modes' => ['direct', 'direct']],
        'join'               => ['cName' => 'tphp_fn_implode', 'modes' => ['direct', 'direct']],
        'explode'            => ['cName' => 'tphp_fn_explode', 'modes' => ['direct', 'direct']],
        'rand'               => ['cName' => 'tphp_fn_rand', 'modes' => ['direct', 'direct']],
        'mt_rand'            => ['cName' => 'tphp_fn_mt_rand', 'modes' => ['direct', 'direct']],
        'random_int'         => ['cName' => 'tphp_fn_random_int', 'modes' => ['direct', 'direct']],
        'array_column'       => ['cName' => 'tphp_fn_array_column_str', 'modes' => ['direct', 'direct']],
        'array_diff'         => ['cName' => 'tphp_fn_arr_diff', 'modes' => ['direct', 'direct']],
        'array_intersect'    => ['cName' => 'tphp_fn_arr_intersect', 'modes' => ['direct', 'direct']],
        'array_flip'         => ['cName' => 'tphp_fn_arr_flip', 'modes' => ['direct']],
        // ── 双参带默认值 ──
        'array_reverse'      => ['cName' => 'tphp_fn_arr_reverse', 'modes' => ['direct', 'direct'], 'defaults' => [1 => 'false']],
        'str_split'          => ['cName' => 'tphp_fn_str_split', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '1']],
        'iconv_strlen'       => ['cName' => 'tphp_fn_iconv_strlen', 'modes' => ['direct', 'direct'], 'defaults' => [1 => 'STR_LIT("UTF-8")']],
        'date'               => ['cName' => 'tphp_fn_date', 'modes' => ['direct', 'direct'], 'defaults' => [0 => 'STR_LIT("%c")', 1 => '-1']],
        // ── 双参 wrapvar + direct ──
        'in_array'           => ['cName' => 'tphp_fn_in_array', 'modes' => ['wrapvar', 'direct']],
        // ── 双参 data + direct ──
        'file_put_contents'  => ['cName' => 'tphp_fn_file_put_contents', 'modes' => ['data', 'direct']],
        // ── 双参 direct + wraparr ──
        'array_unshift'      => ['cName' => 'tphp_fn_arr_unshift', 'modes' => ['direct', 'wraparr']],
        // ── array_search：重排 [1,0]，needle 经 wrapvar ──
        'array_search'       => ['cName' => 'tphp_fn_arr_search', 'modes' => ['wrapvar', 'direct'], 'order' => [1, 0]],
        // ── 三参带默认值 ──
        'substr'             => ['cName' => 'tphp_fn_substr', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'range'              => ['cName' => 'tphp_fn_range', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '1']],
        'iconv_mime_encode'  => ['cName' => 'tphp_fn_iconv_mime_encode', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => 'NULL']],
        'password_hash'      => ['cName' => 'tphp_fn_password_hash', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [0 => 'STR_LIT("")', 1 => '1', 2 => 'NULL']],
        'hash_hmac'          => ['cName' => 'tphp_fn_hash_hmac', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [3 => 'false']],
        // ── 三参 direct + direct + wraparr ──
        'array_fill'         => ['cName' => 'tphp_fn_arr_fill', 'modes' => ['direct', 'direct', 'wraparr']],
        // ── 四参带默认值 ──
        'str_pad'            => ['cName' => 'tphp_fn_str_pad', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [2 => '(t_string){NULL,0}', 3 => '0']],
        'iconv_strpos'       => ['cName' => 'tphp_fn_iconv_strpos', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [2 => '0', 3 => 'STR_LIT("UTF-8")']],
        'iconv_substr'       => ['cName' => 'tphp_fn_iconv_substr', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [2 => '0', 3 => 'STR_LIT("UTF-8")']],
        'iconv_mime_decode'  => ['cName' => 'tphp_fn_iconv_mime_decode', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '0', 2 => 'STR_LIT("UTF-8")']],
        // ── 六参 direct（固定）──
        'mktime'             => ['cName' => 'tphp_fn_mktime', 'modes' => ['direct', 'direct', 'direct', 'direct', 'direct', 'direct']],
        // ── zlib (gzip) 压缩/解压（依赖系统 zlib -lz）──
        'gzcompress'         => ['cName' => 'tphp_fn_gzcompress', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '-1', 2 => '15']],
        'gzuncompress'       => ['cName' => 'tphp_fn_gzuncompress', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '0', 2 => '15']],
        'gzencode'           => ['cName' => 'tphp_fn_gzencode', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '-1', 2 => '31']],
        'gzdecode'           => ['cName' => 'tphp_fn_gzdecode', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '0']],
        'gzdeflate'          => ['cName' => 'tphp_fn_gzdeflate', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '-1', 2 => '-15']],
        'gzinflate'          => ['cName' => 'tphp_fn_gzinflate', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '0']],
        // ── zlib encode/decode 别名 ──
        'zlib_encode'        => ['cName' => 'tphp_fn_zlib_encode', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '-1']],
        'zlib_decode'        => ['cName' => 'tphp_fn_zlib_decode', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '0']],
        // ── gz 文件流 API（gzFile 封装为 Resource）──
        'gzopen'             => ['cName' => 'tphp_fn_gzopen', 'modes' => ['direct', 'direct']],
        'gzclose'            => ['cName' => 'tphp_fn_gzclose', 'modes' => ['direct']],
        'gzread'             => ['cName' => 'tphp_fn_gzread', 'modes' => ['direct', 'direct']],
        'gzwrite'            => ['cName' => 'tphp_fn_gzwrite', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'gzputs'             => ['cName' => 'tphp_fn_gzputs', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'gzeof'              => ['cName' => 'tphp_fn_gzeof', 'modes' => ['direct']],
        'gzgets'             => ['cName' => 'tphp_fn_gzgets', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '0']],
        'gzgetc'             => ['cName' => 'tphp_fn_gzgetc', 'modes' => ['direct']],
        'gzrewind'           => ['cName' => 'tphp_fn_gzrewind', 'modes' => ['direct']],
        'gzseek'             => ['cName' => 'tphp_fn_gzseek', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'gztell'             => ['cName' => 'tphp_fn_gztell', 'modes' => ['direct']],
        'gzpassthru'         => ['cName' => 'tphp_fn_gzpassthru', 'modes' => ['direct']],
        'gzflush'            => ['cName' => 'tphp_fn_gzflush', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '2']],
        'gzfile'             => ['cName' => 'tphp_fn_gzfile', 'modes' => ['direct']],
        'readgzfile'         => ['cName' => 'tphp_fn_readgzfile', 'modes' => ['direct']],
        // ── zlib 增量上下文 API（deflate/inflate init + add）──
        'deflate_init'       => ['cName' => 'tphp_fn_deflate_init', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '-1']],
        'deflate_add'        => ['cName' => 'tphp_fn_deflate_add', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '2']],
        'inflate_init'       => ['cName' => 'tphp_fn_inflate_init', 'modes' => ['direct']],
        'inflate_add'        => ['cName' => 'tphp_fn_inflate_add', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '2']],
        'inflate_get_status' => ['cName' => 'tphp_fn_inflate_get_status', 'modes' => ['direct']],
        'inflate_get_read_len' => ['cName' => 'tphp_fn_inflate_get_read_len', 'modes' => ['direct']],
        // ── ZIP 归档读写（依赖系统 zlib -lz）──
        'zip_open'           => ['cName' => 'tphp_fn_zip_open', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '0']],
        'zip_close'          => ['cName' => 'tphp_fn_zip_close', 'modes' => ['direct']],
        'zip_read'           => ['cName' => 'tphp_fn_zip_read', 'modes' => ['direct']],
        'zip_entry_open'     => ['cName' => 'tphp_fn_zip_entry_open', 'modes' => ['direct', 'direct']],
        'zip_entry_read'     => ['cName' => 'tphp_fn_zip_entry_read', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'zip_entry_close'    => ['cName' => 'tphp_fn_zip_entry_close', 'modes' => ['direct']],
        'zip_add_file'       => ['cName' => 'tphp_fn_zip_add_file', 'modes' => ['direct', 'direct', 'direct', 'direct', 'direct'], 'defaults' => [3 => '0', 4 => '8']],
        'zip_add_dir'        => ['cName' => 'tphp_fn_zip_add_dir', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'zip_delete'         => ['cName' => 'tphp_fn_zip_delete', 'modes' => ['direct', 'direct']],
        'zip_rename'         => ['cName' => 'tphp_fn_zip_rename', 'modes' => ['direct', 'direct', 'direct']],
        'zip_stat'           => ['cName' => 'tphp_fn_zip_stat', 'modes' => ['direct', 'direct']],
        'zip_num_files'      => ['cName' => 'tphp_fn_zip_num_files', 'modes' => ['direct']],
        'zip_get_error_string' => ['cName' => 'tphp_fn_zip_get_error_string', 'modes' => ['direct']],
        // ── zip 新增条目信息查询 ──
        'zip_entry_name'             => ['cName' => 'tphp_fn_zip_entry_name', 'modes' => ['direct', 'direct']],
        'zip_entry_filesize'         => ['cName' => 'tphp_fn_zip_entry_filesize', 'modes' => ['direct', 'direct']],
        'zip_entry_compressedsize'   => ['cName' => 'tphp_fn_zip_entry_compressedsize', 'modes' => ['direct', 'direct']],
        'zip_entry_compressionmethod' => ['cName' => 'tphp_fn_zip_entry_compressionmethod', 'modes' => ['direct', 'direct']],
        'zip_locate'                 => ['cName' => 'tphp_fn_zip_locate', 'modes' => ['direct', 'direct']],
        // ── stream (内置 ext, 跨平台 socket) ──
        'stream_close'                => ['cName' => 'tphp_fn_stream_close', 'modes' => ['direct']],
        'stream_last_error'           => ['cName' => 'tphp_fn_stream_last_error'],
        'stream_strerror'             => ['cName' => 'tphp_fn_stream_strerror', 'modes' => ['direct']],
        'stream_set_blocking'         => ['cName' => 'tphp_fn_stream_set_blocking', 'modes' => ['direct', 'direct']],
        'stream_set_read_buffer'      => ['cName' => 'tphp_fn_stream_set_read_buffer', 'modes' => ['direct', 'direct']],
        'stream_isatty'               => ['cName' => 'tphp_fn_stream_isatty', 'modes' => ['direct']],
        'stream_select'               => ['cName' => 'tphp_fn_stream_select', 'modes' => ['direct', 'direct', 'direct', 'direct', 'direct'], 'defaults' => [4 => '0']],
        'stream_context_create'       => ['cName' => 'tphp_fn_stream_context_create', 'modes' => ['direct'], 'defaults' => [0 => '(t_array*)NULL']],
        'stream_socket_server'        => ['cName' => 'tphp_fn_stream_socket_server', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '12', 2 => '(t_array*)NULL']],
        'stream_socket_accept'        => ['cName' => 'tphp_fn_stream_socket_accept', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '-1']],
        'stream_socket_client'        => ['cName' => 'tphp_fn_stream_socket_client', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [1 => '-1', 2 => '2', 3 => '(t_array*)NULL']],
        'stream_socket_recvfrom'      => ['cName' => 'tphp_fn_stream_socket_recvfrom', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'stream_socket_sendto'        => ['cName' => 'tphp_fn_stream_socket_sendto', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [2 => '0', 3 => '(t_string){0}']],
        'stream_socket_get_name'      => ['cName' => 'tphp_fn_stream_socket_get_name', 'modes' => ['direct', 'direct']],
        'stream_socket_shutdown'      => ['cName' => 'tphp_fn_stream_socket_shutdown', 'modes' => ['direct', 'direct']],
        'stream_socket_enable_crypto' => ['cName' => 'tphp_fn_stream_socket_enable_crypto', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        // ── stream 补充 API（对齐 PHP 原生） ──
        'stream_set_write_buffer'     => ['cName' => 'tphp_fn_stream_set_write_buffer', 'modes' => ['direct', 'direct']],
        'stream_set_timeout'          => ['cName' => 'tphp_fn_stream_set_timeout', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '0']],
        'stream_get_contents'         => ['cName' => 'tphp_fn_stream_get_contents', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '-1', 2 => '-1']],
        'stream_get_line'             => ['cName' => 'tphp_fn_stream_get_line', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '(t_string){0}']],
        'stream_get_meta_data'        => ['cName' => 'tphp_fn_stream_get_meta_data', 'modes' => ['direct']],
        'stream_socket_pair'          => ['cName' => 'tphp_fn_stream_socket_pair', 'modes' => ['direct', 'direct', 'direct']],
        // ── openssl (内置 ext, TLS/加密) ──
        'openssl_ctx_new'                 => ['cName' => 'tphp_fn_openssl_ctx_new', 'modes' => ['direct']],
        'openssl_ctx_free'                => ['cName' => 'tphp_fn_openssl_ctx_free', 'modes' => ['direct']],
        'openssl_ctx_use_certificate_file' => ['cName' => 'tphp_fn_openssl_ctx_use_certificate_file', 'modes' => ['direct', 'direct', 'direct']],
        'openssl_ctx_use_private_key_file' => ['cName' => 'tphp_fn_openssl_ctx_use_private_key_file', 'modes' => ['direct', 'direct', 'direct']],
        'openssl_ctx_set_verify'          => ['cName' => 'tphp_fn_openssl_ctx_set_verify', 'modes' => ['direct', 'direct']],
        'openssl_ctx_set_options'         => ['cName' => 'tphp_fn_openssl_ctx_set_options', 'modes' => ['direct', 'direct']],
        'openssl_ssl_new'                 => ['cName' => 'tphp_fn_openssl_ssl_new', 'modes' => ['direct']],
        'openssl_ssl_free'                => ['cName' => 'tphp_fn_openssl_ssl_free', 'modes' => ['direct']],
        'openssl_ssl_set_fd'              => ['cName' => 'tphp_fn_openssl_ssl_set_fd', 'modes' => ['direct', 'direct']],
        'openssl_ssl_connect'             => ['cName' => 'tphp_fn_openssl_ssl_connect', 'modes' => ['direct']],
        'openssl_ssl_accept'              => ['cName' => 'tphp_fn_openssl_ssl_accept', 'modes' => ['direct']],
        'openssl_ssl_read'                => ['cName' => 'tphp_fn_openssl_ssl_read', 'modes' => ['direct', 'direct']],
        'openssl_ssl_write'               => ['cName' => 'tphp_fn_openssl_ssl_write', 'modes' => ['direct', 'direct']],
        'openssl_ssl_shutdown'            => ['cName' => 'tphp_fn_openssl_ssl_shutdown', 'modes' => ['direct']],
        'openssl_ssl_get_cipher_name'     => ['cName' => 'tphp_fn_openssl_ssl_get_cipher_name', 'modes' => ['direct']],
        'openssl_ssl_get_version'         => ['cName' => 'tphp_fn_openssl_ssl_get_version', 'modes' => ['direct']],
        'openssl_error_string'            => ['cName' => 'tphp_fn_openssl_error_string'],
        'openssl_encrypt'                 => ['cName' => 'tphp_fn_openssl_encrypt', 'modes' => ['direct', 'direct', 'direct', 'direct', 'direct'], 'defaults' => [4 => '0']],
        'openssl_decrypt'                 => ['cName' => 'tphp_fn_openssl_decrypt', 'modes' => ['direct', 'direct', 'direct', 'direct', 'direct'], 'defaults' => [4 => '0']],
        'openssl_random_pseudo_bytes'     => ['cName' => 'tphp_fn_openssl_random_pseudo_bytes', 'modes' => ['direct']],
        'openssl_digest'                  => ['cName' => 'tphp_fn_openssl_digest', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => 'false']],
        // ── sqlite3 (内置 ext, 函数式 SQLite API) ──
        //   sqlite_open(filename, flags=6, enc_key=""): flags 默认 READWRITE|CREATE
        'sqlite_open'                     => ['cName' => 'tphp_fn_sqlite_open', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '6', 2 => '(t_string){0}']],
        'sqlite_close'                    => ['cName' => 'tphp_fn_sqlite_close', 'modes' => ['direct']],
        'sqlite_exec'                     => ['cName' => 'tphp_fn_sqlite_exec', 'modes' => ['direct', 'direct']],
        //   sqlite_query(db, sql, mode=1): mode 默认 SQLITE3_ASSOC
        'sqlite_query'                    => ['cName' => 'tphp_fn_sqlite_query', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '1']],
        'sqlite_query_single'             => ['cName' => 'tphp_fn_sqlite_query_single', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [2 => '1']],
        'sqlite_escape_string'            => ['cName' => 'tphp_fn_sqlite_escape_string', 'modes' => ['direct']],
        'sqlite_changes'                  => ['cName' => 'tphp_fn_sqlite_changes', 'modes' => ['direct']],
        'sqlite_last_insert_rowid'        => ['cName' => 'tphp_fn_sqlite_last_insert_rowid', 'modes' => ['direct']],
        'sqlite_last_error_msg'           => ['cName' => 'tphp_fn_sqlite_last_error_msg', 'modes' => ['direct']],
        'sqlite_last_error_code'          => ['cName' => 'tphp_fn_sqlite_last_error_code', 'modes' => ['direct']],
        'sqlite_version'                  => ['cName' => 'tphp_fn_sqlite_version'],
    ];

    /** 临时变量计数器，用于数组字面量的复合表达式 */
    private int $tmpVarCounter = 0;

    /** 闭包函数计数器 */
    private int $closureCounter = 0;
    /** 捕获类型计数器（用于预扫描阶段的唯一 ID 分配） */
    private int $capTypeCounter = 0;

    // ── Thunk 生成（phpc_thunk_i32 / phpc_thunk('name')）─
    private int $thunkCounter = 0;
    /** #callback 声明的回调签名: name → ['ret'=>'int32_t','params_str'=>'int32_t a, double b'] */
    private array $phpcCallbackSigs = [];
    /** #cstruct 声明的结构体字段: StructName → [['type'=>'C.double','name'=>'x'], ...] */
    private array $cstructFields = [];

    /** P3-3: resolveMethodClass 缓存 (cn\0method → resolvedClass) */
    private array $methodClassCache = [];

    // ── 多段输出 (V-style multi-section codegen) ──────────
    // 所有代码生成写入命名段，最后由 renderSections() 按序组装
    // 替代了原来的 $p = [] + 5 个 deferred 数组 + 字符串插入 hack
    private const SEC_HEADER    = 'header';     // 文件注释
    private const SEC_INCLUDES  = 'includes';   // #include 行
    private const SEC_CAPTYPES  = 'captypes';   // 闭包捕获类型 struct 定义
    private const SEC_FWDDECLS  = 'fwddecls';   // 函数前置声明
    private const SEC_THUNKVARS = 'thunkvars';  // Thunk 静态回调副本
    private const SEC_CONSTS    = 'consts';     // 全局常量
    private const SEC_ENUMS     = 'enums';      // 枚举定义
    private const SEC_CLSFWDS   = 'clsfwds';    // 类 struct + 方法前置声明
    private const SEC_FUNCFWDS  = 'funcfwds';   // 独立函数前置声明
    private const SEC_CLSIMPL   = 'clsimpl';    // 类方法实现 + allocator
    private const SEC_FUNCIMPL  = 'funcimpl';   // 独立函数实现
    private const SEC_CLOSURES  = 'closures';   // 闭包函数实现
    private const SEC_THUNKS    = 'thunks';     // Thunk 函数实现
    private const SEC_EXPORTS   = 'exports';    // 导出函数 trampoline + 库初始化（-shared 模式）
    private const SEC_MAIN      = 'main';       // C entry main()

    private array $sections = [];

    // ── 类型/作用域 ──────────────────────────────────────
    /** 当前方法/函数的返回类型（用于 return 语句的 t_var 包裹） */
    private string $currentRetType = '';
    /** 当前方法/函数的 PHP 返回类型（用于 throw/error 语法检查 |Exception） */
    private string $currentPhpRetType = '';

    // ============================================================
    public function generate(ProgramNode $program, string $phpFile, string $outputDir): string
    {
        $this->phpFile = $phpFile;
        $this->className = $program->mainClass ? self::classCName($program->mainClass) : '';
        $this->phpClassName = $program->mainClass ? ($program->mainClass->namespace !== '' ? $program->mainClass->namespace . '\\' . $program->mainClass->name : $program->mainClass->name) : '';
        $this->resetState();
        $outPath = $outputDir . '/' . pathinfo($phpFile, PATHINFO_FILENAME) . '.c';
        $code = $program->accept($this);
        file_put_contents($outPath, $code);
        return $outPath;
    }

    // ============================================================
    public function visitProgram(ProgramNode $node): string
    {
        $this->indent = 0;
        $this->resetState();
        $this->preScanGenerators($node);
        $this->program = $node;

        // 收集 #callback 声明
        foreach ($node->callbacks as $cb) {
            $this->phpcCallbackSigs[$cb['name']] = $cb;
        }

        // 收集 #cstruct 声明：结构体名 → 字段列表 [['type'=>'C.double','name'=>'x'], ...]
        //   用于 $obj->field 原生访问（编译期展开为 ((StructType*)$obj)->field）
        $this->cstructFields = [];
        foreach ($node->cstructs as $cs) {
            $this->cstructFields[$cs['name']] = $cs['fields'];
        }

        // 预扫描源码：是否用到了 Phase1/2 函数（需要 builtin_extra.h）
        $needExtra = false;
        $src = @file_get_contents($this->phpFile);
        if ($src !== false) {
            $extraFuncs = [
                'htmlspecialchars', 'nl2br', 'base64_encode', 'base64_decode', 'http_build_query',
                'array_flip', 'array_diff', 'array_intersect', 'array_column',
                'array_chunk', 'array_combine', 'array_count_values',
                'mb_strlen', 'mb_substr', 'mb_strpos',
            ];
            foreach ($extraFuncs as $fn) {
                if (str_contains($src, $fn . '(')) {
                    $needExtra = true;
                    break;
                }
            }
        }
        // 检测是否使用了 bcrypt (password_hash/verify)
        $needBcrypt = ($src !== false) && (str_contains($src, 'password_hash(') || str_contains($src, 'password_verify('));

        // 检测是否使用了 zlib/zip 函数（需要条件引入 zlib.h/zip.h + 链接 -lz）
        $zlibFns = ['gzcompress(', 'gzuncompress(', 'gzencode(', 'gzdecode(', 'gzdeflate(', 'gzinflate(',
                    'zlib_encode(', 'zlib_decode(',
                    'gzopen(', 'gzclose(', 'gzread(', 'gzwrite(', 'gzputs(', 'gzeof(', 'gzgets(', 'gzgetc(',
                    'gzrewind(', 'gzseek(', 'gztell(', 'gzpassthru(', 'gzflush(', 'gzfile(', 'readgzfile(',
                    'deflate_init(', 'deflate_add(', 'inflate_init(', 'inflate_add(',
                    'inflate_get_status(', 'inflate_get_read_len(',
                    'zip_open(', 'zip_close(', 'zip_read(', 'zip_entry_open(', 'zip_entry_read(', 'zip_entry_close(',
                    'zip_add_file(', 'zip_add_dir(', 'zip_delete(', 'zip_rename(', 'zip_stat(', 'zip_num_files(',
                    'zip_get_error_string(', 'zip_entry_name(', 'zip_entry_filesize(',
                    'zip_entry_compressedsize(', 'zip_entry_compressionmethod(', 'zip_locate('];
        $needZlib = false;
        if ($src !== false) {
            foreach ($zlibFns as $fn) {
                if (str_contains($src, $fn)) { $needZlib = true; break; }
            }
        }

        // stream/openssl: 不再自动检测，由 #import 显式引入（.php 中 #include 头文件）
        //   - #import stream  → ext/stream/src/stream.php 中 #include __EXT__ . "stream/src/stream.h"
        //   - #import openssl → ext/openssl/src/openssl.php 中 #include __EXT__ . "openssl/src/openssl.h"
        // 同时使用时 CodeGenerator 自动排序确保 openssl.h 先于 stream.h include
        // （openssl.h 定义 TPHP_STREAM_TLS_IMPLEMENTED 覆盖 stream.h 的 stub，顺序无关用户书写）

        // ── SEC_HEADER ──
        $this->sectionLine(self::SEC_HEADER, "/* Generated by TinyPHP — PHP → C (TCC) */");
        $this->sectionLine(self::SEC_HEADER, '');

        // ── SEC_INCLUDES ──
        // 用户 #include 分两组：
        //   - 非 ext/ 路径 → common.h 之前（如 raylib_compat.h 需要 #define 在 windows.h 之前）
        //   - ext/ 路径 → common.h 之后（扩展头文件依赖 common.h 的前向声明，如 stream.h 依赖
        //     common.h 中的 tphp_rt_str_free/tphp_rt_str_dup 等前向声明）
        $userIncBefore = [];
        $userIncAfter  = [];
        foreach ($node->includes as $inc) {
            $file = is_array($inc) ? ($inc['file'] ?? '') : $inc;
            $normalized = str_replace('\\', '/', $file);
            if (str_contains($normalized, '/ext/')) {
                $userIncAfter[] = $inc;
            } else {
                $userIncBefore[] = $inc;
            }
        }
        // 检测已导入的 PDO 驱动扩展，记录 C init 函数（在 main() 入口自动调用）
        //   类似 PHP MINIT：用户只需 #import pdo_mysql，CodeGenerator 自动注入注册调用
        //   不依赖 __attribute__((constructor))（部分 TCC 版本会死代码消除）
        foreach ($userIncAfter as $inc) {
            $f = str_replace('\\', '/', is_array($inc) ? ($inc['file'] ?? '') : $inc);
            if (str_contains($f, 'pdo_mysql/pdo_mysql.h')) {
                $this->pdoDriverInits[] = 'tphp_fn_pdo_mysql_init';
            }
        }
        foreach ($userIncBefore as $inc) {
            if (is_array($inc)) {
                $delim = ($inc['quoted'] ?? true) ? '"' : '<';
                $end   = ($inc['quoted'] ?? true) ? '"' : '>';
                $this->sectionLine(self::SEC_INCLUDES, '#include ' . $delim . $inc['file'] . $end);
            } else {
                $this->sectionLine(self::SEC_INCLUDES, '#include "' . $inc . '"');
            }
        }
        $this->sectionLine(self::SEC_INCLUDES, '#include "common.h"');
        if ($needZlib) {
            $this->sectionLine(self::SEC_INCLUDES, '#include "os/zlib.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "os/zip.h"');
        }
        // openssl.h 不再自动 include：由 #import openssl 引入 ext/openssl/src/openssl.php
        //   中的 #include __EXT__ . "openssl/src/openssl.h" 负责（放 common.h 之后）
        // ext/ 路径的用户 #include 放在 common.h 之后（由 #import 引入的扩展头文件）
        //
        // 自动排序：openssl.h 必须在 stream.h 之前 include
        //   原因：openssl.h 无条件 #define TPHP_STREAM_TLS_IMPLEMENTED 并提供
        //   stream_socket_enable_crypto 的真实 TLS 实现；stream.h 用 #ifndef 保护 stub。
        //   若 stream.h 在前，stub 先生效，openssl.h 的真实实现会导致重复定义编译错误。
        //   用户 #import 书写顺序无关，CodeGenerator 强制保证 openssl.h 优先。
        usort($userIncAfter, function ($a, $b) {
            $fa = str_replace('\\', '/', is_array($a) ? ($a['file'] ?? '') : $a);
            $fb = str_replace('\\', '/', is_array($b) ? ($b['file'] ?? '') : $b);
            $pa = str_contains($fa, 'openssl/src/openssl.h') ? 0 : 1;
            $pb = str_contains($fb, 'openssl/src/openssl.h') ? 0 : 1;
            return $pa <=> $pb;
        });
        foreach ($userIncAfter as $inc) {
            if (is_array($inc)) {
                $delim = ($inc['quoted'] ?? true) ? '"' : '<';
                $end   = ($inc['quoted'] ?? true) ? '"' : '>';
                $this->sectionLine(self::SEC_INCLUDES, '#include ' . $delim . $inc['file'] . $end);
            } else {
                $this->sectionLine(self::SEC_INCLUDES, '#include "' . $inc . '"');
            }
        }
        if ($needExtra) {
            $this->sectionLine(self::SEC_INCLUDES, '#include "builtin_extra.h"');
        }

        // ── SEC_CONSTS ──
        if ($needBcrypt) {
            $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PASSWORD_BCRYPT 1');
            $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PASSWORD_BCRYPT_DEFAULT_COST 10');
        }
        // OpenSSL 常量已在 openssl.h 中以 TPHP_CONST_OPENSSL_* 定义（由 #import openssl 引入）
        foreach ($node->constants as $c) {
            $this->sectionLine(self::SEC_CONSTS, $c->accept($this));
        }

        // ── SEC_ENUMS ──
        foreach ($node->enums as $e) {
            $this->sectionBlock(self::SEC_ENUMS, $e->accept($this));
        }
        // ── SEC_CLSIMPL: 枚举方法实现 + 自动 cases()/from()/tryFrom() ──
        // （前置声明在 SEC_ENUMS 中，渲染顺序 ENUMS < CLSIMPL 保证声明在前）
        foreach ($node->enums as $e) {
            $this->sectionBlock(self::SEC_CLSIMPL, $this->emitEnumImpl($e));
        }

        $allClasses = array_merge(
            $node->mainClass ? [$node->mainClass] : [],
            $node->extraClasses
        );
        // Topological sort: parent classes before children
        //   key 必须用 classCName($c)（含命名空间前缀），否则命名空间类的 key
        //   会是 tphp_class_X（全局类格式），与父类 C 名
        //   tphp_na_NS_tphp_class_X 不匹配 → isset 查不到 → 拓扑排序退化为原始顺序
        //   子类 struct 在父类 struct 之前定义 → C 编译报
        //   "field '_parent' has incomplete type"
        $sorted = [];
        $seen = [];
        $byRefName = [];
        foreach ($allClasses as $c) { $byRefName[self::classCName($c)] = $c; }
        $addClass = function ($cn) use (&$addClass, &$seen, &$sorted, $byRefName) {
            if (isset($seen[$cn])) return;
            $seen[$cn] = true;
            if (isset($byRefName[$cn]) && $byRefName[$cn]->parentName !== null) {
                // parentName 已通过 resolveClassName() 解析为 FQ 名，
                // classRefName(FQ name) 能正确生成命名空间 C 名
                $pcn = self::classRefName($byRefName[$cn]->parentName);
                if (isset($byRefName[$pcn])) $addClass($pcn);
            }
            if (isset($byRefName[$cn])) $sorted[] = $byRefName[$cn];
        };
        foreach ($byRefName as $cn => $_) $addClass($cn);
        $allClasses = $sorted;
        $mainClassName = $node->mainClass ? self::classCName($node->mainClass) : '';

        // ── 预扫描：注册所有类名到 nameMap，确保 emitClassForward 中 mapType 能正确解析
        //   跨文件类引用（如 Worker 类的属性类型为 Select，但 Select 在文件列表中位于 Worker 之后）
        //   不注册属性/方法，仅注册类名映射，避免 mapType 落入 fallback 生成 "Select*" 而非 "tphp_class_Select*"
        foreach ($allClasses as $class) {
            $cn = self::classCName($class);
            $this->symbols->addClassName($class->name, $cn);
            // 同时注册 FQ 名（命名空间内类型注解经 resolveClassName() 解析为 FQ 名）
            //   例如 Demo\Sub\Util 类型注解需要查到 tphp_na_Demo_Sub_tphp_class_Util
            if ($class->namespace !== '') {
                $this->symbols->addClassName($class->namespace . '\\' . $class->name, $cn);
            }
        }

        // ── SEC_CLSFWDS: Phase 1 — 所有类的 struct + 前置声明 ──
        // 先为所有非接口类生成不完整类型前向声明（typedef struct cn cn;）
        //   解决跨类属性类型引用顺序问题（如 Worker 属性类型为 Text，但 Text struct 定义在 Worker 之后）
        //   C 语言允许不完整类型的指针引用，完整定义可后置
        foreach ($allClasses as $class) {
            if ($class->isAbstract && $class->parentName === null && empty($class->properties)) {
                continue; // 接口在 emitClassForward 中单独处理
            }
            $cn = self::classCName($class);
            $this->sectionLine(self::SEC_CLSFWDS, "typedef struct {$cn} {$cn};");
        }
        $this->sectionLine(self::SEC_CLSFWDS, '');
        foreach ($allClasses as $class) {
            $this->className = self::classCName($class);
            $this->phpClassName = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            $isMain = (self::classCName($class) === $mainClassName);
            $this->sectionBlock(self::SEC_CLSFWDS, $this->emitClassForward($class, $isMain));
        }

        // ── SEC_FUNCFWDS: 独立函数前置声明 ──
        foreach ($node->functions as $fn) {
            $fnCName = self::funcCName($fn);
            $existingFn = $this->symbols->getFunc($fnCName);
            if ($existingFn !== null && $existingFn->isGenerator) {
                $ret = 'tphp_class_Generator*';
            } else {
                $ret = self::mapType($fn->returnType);
            }
            $paramTypes = array_map(fn($p) => $this->paramCTypeResolved($p), $fn->params);
            // 计算默认值参数数量
            $defaultCount = 0;
            $totalParams = count($fn->params);
            for ($i = $totalParams - 1; $i >= 0; $i--) {
                if ($fn->params[$i]->default !== null) {
                    $defaultCount++;
                } else {
                    break;
                }
            }
            $isGen = $existingFn !== null && $existingFn->isGenerator;
            $this->symbols->addFunc($fnCName, new FunctionInfo(
                $ret,
                $paramTypes,
                $defaultCount,
                $totalParams,
                $isGen,
            ));
            $params = array_map(fn($p) => $this->visitParam($p), $fn->params);
            $this->sectionLine(self::SEC_FUNCFWDS,
                'static ' . $ret . ' ' . $fnCName . '(' . implode(', ', $params) . ');');
            // 为有默认值的函数生成重载函数前置声明
            if ($defaultCount > 0) {
                for ($cutIdx = $totalParams - $defaultCount; $cutIdx < $totalParams; $cutIdx++) {
                    $overloadName = $fnCName . '_' . ($totalParams - $cutIdx);
                    $cutParams = array_slice($fn->params, 0, $cutIdx);
                    $overloadParams = array_map(fn($p) => $this->visitParam($p), $cutParams);
                    $this->sectionLine(self::SEC_FUNCFWDS,
                        'static ' . $ret . ' ' . $overloadName . '(' . implode(', ', $overloadParams) . ');');
                }
            }
        }

        // ── SEC_FUNCIMPL: 独立函数实现（先于类实现处理，使 fnReturnArrKeyTypes 可用）──
        foreach ($node->functions as $fn) {
            $this->sectionBlock(self::SEC_FUNCIMPL, $fn->accept($this));
        }

        // ── SEC_CLSIMPL: Phase 2 — 所有类的方法实现 + allocator ──
        // 前向声明所有类描述符（catch 子句引用 _class_tphp_class_* 时需要）
        $clsFwdDecls = [];
        foreach ($allClasses as $class) {
            $cn = self::classCName($class);
            if ($class->isAbstract && $class->parentName === null && empty($class->properties)) continue;
            $clsFwdDecls[] = "static const t_class _class_{$cn};";
        }
        // Exception 内置类
        $clsFwdDecls[] = "static const t_class _class_tphp_class_Exception;";
        if (!empty($clsFwdDecls)) {
            $this->sectionBlock(self::SEC_CLSIMPL, "/* ── Class descriptor forward declarations ── */\n" . implode("\n", $clsFwdDecls) . "\n");
        }
        foreach ($allClasses as $class) {
            $this->className = self::classCName($class);
            $this->phpClassName = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            $isMain = (self::classCName($class) === $mainClassName);
            $this->sectionBlock(self::SEC_CLSIMPL, $this->emitClassImpl($class, $isMain));
        }

        // ── SEC_EXPORTS: 导出函数 trampoline + 库初始化（-shared 模式） ──
        $this->sectionBlock(self::SEC_EXPORTS, $this->emitExports($node));

        // ── SEC_MAIN: C 入口 ──
        if ($node->mainClass !== null) {
            $this->className = self::classCName($node->mainClass);
            $this->phpClassName = $node->mainClass->namespace !== '' ? $node->mainClass->namespace . '\\' . $node->mainClass->name : $node->mainClass->name;
            $this->sectionBlock(self::SEC_MAIN, $this->generateCEntry());
        }

        return $this->renderSections();
    }

    /** 预扫描生成器函数：填充 SymbolTable */
    private function preScanGenerators(ProgramNode $node): void
    {
        foreach ($node->functions as $fn) {
            if ($fn->isGenerator) {
                $cn = self::funcCName($fn);
                $this->symbols->addFunc($cn, new FunctionInfo(
                    'tphp_class_Generator*',
                    [],
                    0,
                    0,
                    true,
                ));
            }
        }
    }

    /** 重置状态（每次 generate 调用时） */
    private function resetState(): void
    {
        $this->varTypes = [];
        $this->declaredVars = [];
        $this->localConsts = [];
        $this->assignedReadonlyProps = [];
        $this->tmpVarCounter = 0;
        $this->closureCounter = 0;
        $this->capTypeCounter = 0;
        $this->thunkCounter = 0;
        $this->methodClassCache = [];
        $this->currentFuncName = '';
        $this->currentFuncCName = '';
        $this->fnReturnArrKeyTypes = [];
        $this->inMethod = false;
        $this->currentNamespace = '';
        $this->sections = [];
        $this->inGenerator = false;
        $this->symbols = new SymbolTable();
        // 内置 Exception 类
        $this->symbols->addClass('tphp_class_Exception');
        $this->symbols->addClassName('Exception', 'tphp_class_Exception');
        $this->symbols->getClass('tphp_class_Exception')->methods['getMessage']    = new MethodInfo('t_string');
        $this->symbols->getClass('tphp_class_Exception')->methods['__construct'] = new MethodInfo('void', ['t_string'], false, 'public', 1, 1);
        $this->symbols->getClass('tphp_class_Exception')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->addClassProp('tphp_class_Exception', 'message', 't_string');

        // 内置 Generator 类（基于 minicoro 协程）
        $this->symbols->addClass('tphp_class_Generator');
        $this->symbols->addClassName('Generator', 'tphp_class_Generator');
        $this->symbols->getClass('tphp_class_Generator')->methods['current']   = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['key']       = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['next']      = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['send']      = new MethodInfo('t_var', ['t_var']);
        $this->symbols->getClass('tphp_class_Generator')->methods['valid']    = new MethodInfo('t_int');
        $this->symbols->getClass('tphp_class_Generator')->methods['getReturn'] = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['rewind']    = new MethodInfo('void');

        // 内置 Resource 类（资源对象化根，用户可 extends Resource）
        $this->symbols->addClass('tphp_class_Resource');
        $this->symbols->addClassName('Resource', 'tphp_class_Resource');
        $this->symbols->getClass('tphp_class_Resource')->methods['__construct'] = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Resource')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Resource')->methods['getType']     = new MethodInfo('t_int');

        // 内置 File 类（Resource 子类，替代 fopen resource）
        $this->symbols->addClass('tphp_class_File', 'tphp_class_Resource');
        $this->symbols->addClassName('File', 'tphp_class_File');
        $this->symbols->getClass('tphp_class_File')->methods['__construct'] = new MethodInfo('void', ['t_string', 't_string']);
        $this->symbols->getClass('tphp_class_File')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_File')->methods['getType']     = new MethodInfo('t_int');
        $this->symbols->getClass('tphp_class_File')->methods['read']        = new MethodInfo('t_string', ['t_int']);
        $this->symbols->getClass('tphp_class_File')->methods['write']       = new MethodInfo('t_int', ['t_string']);
        $this->symbols->getClass('tphp_class_File')->methods['eof']         = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_File')->methods['close']       = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_File')->methods['isOpen']      = new MethodInfo('t_bool');

        // 内置 Thread 类（基于 tinycthread 的线程封装）
        $this->symbols->addClass('tphp_class_Thread');
        $this->symbols->addClassName('Thread', 'tphp_class_Thread');
        $this->symbols->getClass('tphp_class_Thread')->methods['__construct'] = new MethodInfo('void', ['t_callback']);
        $this->symbols->getClass('tphp_class_Thread')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Thread')->methods['start']       = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Thread')->methods['join']        = new MethodInfo('t_int');
        $this->symbols->getClass('tphp_class_Thread')->methods['detach']      = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Thread')->methods['yield']       = new MethodInfo('void', [], true);
        $this->symbols->getClass('tphp_class_Thread')->methods['sleep']       = new MethodInfo('void', ['t_float'], true);
        $this->symbols->getClass('tphp_class_Thread')->methods['id']          = new MethodInfo('t_int', [], true);

        // 内置 Mutex 类
        $this->symbols->addClass('tphp_class_Mutex');
        $this->symbols->addClassName('Mutex', 'tphp_class_Mutex');
        $this->symbols->getClass('tphp_class_Mutex')->methods['__construct'] = new MethodInfo('void', ['t_bool']);
        $this->symbols->getClass('tphp_class_Mutex')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Mutex')->methods['lock']        = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Mutex')->methods['tryLock']     = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Mutex')->methods['unlock']      = new MethodInfo('t_bool');

        // 内置 CondVar 类
        $this->symbols->addClass('tphp_class_CondVar');
        $this->symbols->addClassName('CondVar', 'tphp_class_CondVar');
        $this->symbols->getClass('tphp_class_CondVar')->methods['__construct'] = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_CondVar')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_CondVar')->methods['wait']        = new MethodInfo('t_bool', ['tphp_class_Mutex*']);
        $this->symbols->getClass('tphp_class_CondVar')->methods['signal']      = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_CondVar')->methods['broadcast']   = new MethodInfo('t_bool');

        // 内置 WaitGroup 类
        $this->symbols->addClass('tphp_class_WaitGroup');
        $this->symbols->addClassName('WaitGroup', 'tphp_class_WaitGroup');
        $this->symbols->getClass('tphp_class_WaitGroup')->methods['__construct'] = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_WaitGroup')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_WaitGroup')->methods['add']         = new MethodInfo('void', ['t_int']);
        $this->symbols->getClass('tphp_class_WaitGroup')->methods['done']        = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_WaitGroup')->methods['wait']        = new MethodInfo('void');

        // 内置 Parallel 类（数据并行 API — 纯函数并行）
        $this->symbols->addClass('tphp_class_Parallel');
        $this->symbols->addClassName('Parallel', 'tphp_class_Parallel');
        // for(int $n, callable $fn, int $threads = 0): void — 3 params, 1 default
        $this->symbols->getClass('tphp_class_Parallel')->methods['for']  = new MethodInfo('void', ['t_int', 't_callback', 't_int'], true, 'public', 1, 3);
        // map(array $data, callable $fn, int $threads = 0): array — 3 params, 1 default
        $this->symbols->getClass('tphp_class_Parallel')->methods['map']  = new MethodInfo('t_array*', ['t_array*', 't_callback', 't_int'], true, 'public', 1, 3);

        // 内置 AnnotationEntry 类（注解系统）
        $this->symbols->addClass('tphp_class_AnnotationEntry');
        $this->symbols->addClassName('AnnotationEntry', 'tphp_class_AnnotationEntry');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'data', 't_array*');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'type', 't_string');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'name', 't_string');
        $this->symbols->getClass('tphp_class_AnnotationEntry')->methods['__construct'] = new MethodInfo('void', ['t_array*', 't_string', 't_string']);
        $this->symbols->getClass('tphp_class_AnnotationEntry')->methods['__destruct']  = new MethodInfo('void');
    }

    // ── 多段输出方法 ─────────────────────────────────────────

    /** 向指定段追加一行 */
    private function sectionLine(string $section, string $line): void
    {
        $this->sections[$section][] = $line;
    }

    /** 向指定段追加多行 */
    private function sectionLines(string $section, array $lines): void
    {
        if (empty($lines)) return;
        if (!isset($this->sections[$section])) $this->sections[$section] = [];
        array_push($this->sections[$section], ...$lines);
    }

    /** 向指定段追加字符串块（含换行） */
    private function sectionBlock(string $section, string $block): void
    {
        $block = rtrim($block);
        if ($block === '') return;
        $this->sections[$section][] = $block;
    }

    /** 按固定顺序渲染所有段 → 最终 C 代码字符串 */
    private function renderSections(): string
    {
        $order = [
            self::SEC_HEADER,
            self::SEC_INCLUDES,
            self::SEC_CLSFWDS,    // 类 struct + 前置声明（须在 CAPTYPES 之前，捕获结构体引用用户类类型）
            self::SEC_CAPTYPES,   // 闭包捕获 struct（可能引用用户类）
            self::SEC_FWDDECLS,
            self::SEC_THUNKVARS,
            self::SEC_CONSTS,
            self::SEC_ENUMS,
            self::SEC_FUNCFWDS,
            self::SEC_CLSIMPL,
            self::SEC_FUNCIMPL,
            self::SEC_CLOSURES,
            self::SEC_THUNKS,
            self::SEC_EXPORTS,
            self::SEC_MAIN,
        ];
        // 段注释头（仅在段有内容时输出）
        $labels = [
            self::SEC_CAPTYPES  => "/* ── 闭包捕获类型 ──────────────────────────── */",
            self::SEC_FWDDECLS  => "/* ── 前置声明 ────────────────────────────────── */",
            self::SEC_THUNKVARS => "/* ── 闭包 Thunk 静态副本 ──────────────────── */",
            self::SEC_CLOSURES  => "/* ── 闭包函数实现 ──────────────────────────── */",
            self::SEC_THUNKS    => "/* ── 闭包 Thunk（C 回调适配） ──────────────────── */",
        ];
        $lines = [];
        foreach ($order as $sec) {
            if (empty($this->sections[$sec])) continue;
            // 段间空行
            if (!empty($lines)) $lines[] = '';
            // 段注释头
            if (isset($labels[$sec])) {
                $lines[] = $labels[$sec];
            }
            $lines[] = implode("\n", $this->sections[$sec]);
        }
        return implode("\n", $lines) . "\n";
    }

    /** Phase 1: struct + 前置声明 */
    private function emitClassForward(ClassNode $class, bool $isMain): string
    {
        // Skip interface-only classes (abstract + no parent + no properties)
        if ($class->isAbstract && $class->parentName === null && empty($class->properties)) {
            // 接口方法签名仍需注册到 SymbolTable，供实现类查找方法返回类型
            // （如 ProtocolInterface::input() 在 Worker 中通过 $this->protocol->input() 调用）
            $cn = self::classCName($class);
            $this->symbols->addClass($cn, '', true, $class->implements, false);
            foreach ($class->methods as $m) {
                $mr = $m->isGenerator ? 'tphp_class_Generator*' : $this->mapType($m->returnType);
                $pts = array_map(fn($p) => $this->mapType($p->type), $m->params);
                $tp = count($m->params);
                $dc = 0;
                for ($i = $tp - 1; $i >= 0; $i--) {
                    if ($m->params[$i]->default !== null) { $dc++; } else { break; }
                }
                $this->symbols->getClass($cn)->methods[$m->name] = new MethodInfo($mr, $pts, $m->isStatic, $m->visibility, $dc, $tp);
            }
            // 接口需要空 typedef 以支持指针类型引用（如 ProtocolInterface* protocol）
            // 实际方法调用通过实现类（Text）的 vtable 分发，接口 struct 仅占位
            return "/* interface {$class->name} — compile-time only */\ntypedef struct {$cn}_stub {} {$cn};\n";
        }
        $cn = self::classCName($class);
        $ctor = $dtor = null;
        $methods = [];
        foreach ($class->methods as $m) {
            if ($m->name === '__construct') $ctor = $m;
            elseif ($m->name === '__destruct') $dtor = $m;
            else $methods[] = $m;
        }

        $o = [];
        $o[] = "/* ── Struct: {$cn} ──────────────────────────── */";
        $o[] = "typedef struct {$cn} {";
        $o[] = $this->ind('t_object _obj;');   // COS-style header (cls ptr + refcount)
        // Parent struct (COS inheritance: struct nesting)
        $parentCN = '';
        if ($class->parentName !== null) {
            $parentCN = self::classRefName($class->parentName);
            $o[] = $this->ind($parentCN . ' _parent;');
        }
        // 属性字段 + 记录类型
        $propTypes = [];
        $hookedPropsList = [];
        $staticPropDecls = []; // 静态属性 → 文件作用域变量声明
        foreach ($class->properties as $prop) {
            $ptype = self::mapType($prop->type);
            $pname = ltrim($prop->name, '$');
            if ($prop->isStatic) {
                // 静态属性 → 文件作用域 static 变量（AOT: 编译期固定地址，零运行时开销）
                $varName = "{$cn}_{$pname}";
                $init = $this->staticPropInitializer($ptype, $prop);
                $staticPropDecls[] = "static {$ptype} {$varName}{$init};";
                continue; // 不进实例结构体
            }
            $o[] = $this->ind("{$ptype} {$pname};");
            $propTypes[$pname] = $ptype;
            // 注册 Property Hook
            if (!empty($prop->hooks)) {
                $hasGet = false; $hasSet = false;
                foreach ($prop->hooks as $hook) {
                    if ($hook->kind === 'get') $hasGet = true;
                    if ($hook->kind === 'set') $hasSet = true;
                }
                $this->hookedProps[$cn][$pname] = ['get' => $hasGet, 'set' => $hasSet, 'type' => $ptype];
                $hookedPropsList[] = [$pname, $ptype, $prop->hooks, $hasGet, $hasSet];
            }
        }
        // 数组类常量字段（每个实例持有，简单可靠）
        foreach ($class->classConsts as $cc) {
            if ($cc->value instanceof ArrayLiteralExpr) {
                $fname = '_const_' . $cc->name;
                $o[] = $this->ind("t_array* {$fname};");
            }
        }
        // ── 注册到统一符号表 ──
        $this->symbols->addClass($cn, $parentCN, $class->isAbstract, $class->implements, $class->isReadonly);
        $this->symbols->addClassName($class->name, $cn);
        // 同时注册 FQ 名（命名空间内类型注解经 resolveClassName() 解析为 FQ 名）
        if ($class->namespace !== '') {
            $this->symbols->addClassName($class->namespace . '\\' . $class->name, $cn);
        }
        foreach ($class->properties as $prop) {
            $pname = ltrim($prop->name, '$');
            $ptype = self::mapType($prop->type);
            $this->symbols->addClassProp($cn, $pname, $ptype, !$prop->isStatic, $prop->isStatic);
            // readonly 属性注册（readonly class 中所有属性自动 readonly）
            if (($prop->isReadonly || $class->isReadonly) && !$prop->isStatic) {
                $this->symbols->addClassReadonlyProp($cn, $pname);
            }
        }
        // 方法返回类型 + 参数类型 + 默认值信息（第一遍即注册完整信息，
        // 确保跨类方法调用在 emitClassImpl 阶段即可解析重载版本）
        // __construct 也需注册 defaultCount/totalParams，否则 visitNew 无法选择重载版本
        if ($ctor !== null) {
            $cpts = array_map(fn($p) => $this->mapType($p->type), $ctor->params);
            $ctp = count($ctor->params);
            $cdc = 0;
            for ($i = $ctp - 1; $i >= 0; $i--) {
                if ($ctor->params[$i]->default !== null) { $cdc++; } else { break; }
            }
            $this->symbols->getClass($cn)->methods['__construct'] = new MethodInfo('void', $cpts, false, 'public', $cdc, $ctp);
        } else {
            $this->symbols->getClass($cn)->methods['__construct'] = new MethodInfo('void');
        }
        $this->symbols->getClass($cn)->methods['__destruct']  = new MethodInfo('void');
        foreach ($methods as $m) {
            $mr = $m->isGenerator ? 'tphp_class_Generator*' : $this->mapType($m->returnType);
            $pts = array_map(fn($p) => $this->mapType($p->type), $m->params);
            $tp = count($m->params);
            $dc = 0;
            for ($i = $tp - 1; $i >= 0; $i--) {
                if ($m->params[$i]->default !== null) { $dc++; } else { break; }
            }
            $this->symbols->getClass($cn)->methods[$m->name] = new MethodInfo($mr, $pts, $m->isStatic, $m->visibility, $dc, $tp);
        }
        $o[] = "} {$cn};";
        $o[] = '';
        // 静态属性 → 文件作用域 static 变量（AOT: 编译期固定地址，零运行时查找开销）
        foreach ($staticPropDecls as $decl) {
            $o[] = $decl;
        }
        if (!empty($staticPropDecls)) $o[] = '';
        // 类常量 → #define（简单类型）或 static 变量（array）
        foreach ($class->classConsts as $cc) {
            $cname = 'TPHP_CONST_' . strtoupper($cn . '_' . $cc->name);
            $fullName = $cn . '_' . $cc->name;
            $vis = $cc->visibility ?? 'public';
            // 声明类型 vs 字面量类型一致性校验，并以声明类型注册
            $declCType = self::mapType($cc->type);
            $litCType  = self::$litTypeMap[$cc->value::class] ?? null;
            if ($litCType !== null && $declCType !== $litCType) {
                throw new \RuntimeException(
                    "Class constant {$cn}::{$cc->name} type mismatch: "
                    . "declared '{$cc->type}' ({$declCType}) but value is {$litCType}"
                );
            }
            if ($cc->value instanceof StringLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->symbols->addConst($cname, $declCType, $vis);
                $val = str_replace('"', '\\"', $cc->value->value);
                $o[] = "#define {$cname} STR_LIT(\"{$val}\")";
            } elseif ($cc->value instanceof IntLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->symbols->addConst($cname, $declCType, $vis);
                $o[] = "#define {$cname} {$cc->value->value}";
            } elseif ($cc->value instanceof FloatLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->symbols->addConst($cname, $declCType, $vis);
                $fv = $cc->value->value;
                $o[] = '#define ' . $cname . ' ' .
                    (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
            } elseif ($cc->value instanceof BoolLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->symbols->addConst($cname, $declCType, $vis);
                $o[] = "#define {$cname} " . ($cc->value->value ? 'true' : 'false');
            } elseif ($cc->value instanceof ArrayLiteralExpr) {
                // 数组常量：static 变量（不注册到 SymbolTable，访问走独立路径）
                $o[] = "static t_array* {$cname} = NULL;";
                $o[] = "/* initialized on first access via {$cn} class */";
            }
        }
        if (!empty($class->classConsts)) $o[] = '';

        // __construct 声明
        if ($isMain) {
            $o[] = "void {$cn}___construct({$cn}* self, t_int argc, t_array* argv);";
        } else {
            $ctorParams = $this->ctorParamStr($ctor);
            $o[] = "void {$cn}___construct({$cn}* self" . ($ctorParams ? ', ' . $ctorParams : '') . ");";
        }
        $o[] = "void {$cn}___destruct({$cn}* self);";
        foreach ($methods as $m) {
            $o[] = $this->methodDecl($m) . ';';
            // 为有默认值的方法生成重载前置声明（与 generateMethodOverloads 对应）
            $tp = count($m->params);
            $firstDefaultIdx = $tp;
            for ($i = 0; $i < $tp; $i++) {
                if ($m->params[$i]->default !== null) { $firstDefaultIdx = $i; break; }
            }
            if ($firstDefaultIdx < $tp) {
                $ret = self::mapType($m->returnType);
                for ($cutIdx = $firstDefaultIdx; $cutIdx < $tp; $cutIdx++) {
                    $overloadName = $cn . '_' . $m->name . '_' . ($tp - $cutIdx);
                    $cutParams = array_slice($m->params, 0, $cutIdx);
                    $paramStr = empty($cutParams)
                        ? $cn . '* self'
                        : $cn . '* self, ' . implode(', ', array_map(fn($p) => $this->visitParam($p), $cutParams));
                    $o[] = "static {$ret} {$overloadName}({$paramStr});";
                }
            }
        }
        if ($isMain) {
            $o[] = "{$cn}* new_{$cn}(t_int argc, t_array* argv);";
        } else {
            $ctorParams = $this->ctorParamStr($ctor);
            $o[] = "{$cn}* new_{$cn}(" . ($ctorParams ? $ctorParams : 'void') . ");";
            // 默认参数重载前置声明（static，需在使用前声明）
            if ($ctor !== null) {
                $ctorDefCount = 0;
                foreach ($ctor->params as $p) {
                    if ($p->default !== null) $ctorDefCount++;
                }
                if ($ctorDefCount > 0) {
                    $total = count($ctor->params);
                    $firstDef = $total - $ctorDefCount;
                    for ($cutIdx = $firstDef; $cutIdx < $total; $cutIdx++) {
                        $overloadName = "new_{$cn}_" . ($total - $cutIdx);
                        $cutParams = array_slice($ctor->params, 0, $cutIdx);
                        $overloadParamStr = implode(', ', array_map(fn($p) => self::paramDecl($p), $cutParams));
                        if (empty($overloadParamStr)) $overloadParamStr = 'void';
                        $o[] = "static {$cn}* {$overloadName}({$overloadParamStr});";
                    }
                }
            }
        }

        // Property Hook getter/setter 前置声明
        foreach ($hookedPropsList as [$pname, $ptype, $hooks, $hasGet, $hasSet]) {
            if ($hasGet) {
                $o[] = "static {$ptype} {$cn}_get_{$pname}({$cn}* self);";
            }
            if ($hasSet) {
                $o[] = "static void {$cn}_set_{$pname}({$cn}* self, {$ptype} value);";
            }
        }

        $o[] = '';

        return implode("\n", $o);
    }

    /** 生成构造参数声明字符串（不含 self），如 "t_string bb" */
    private function ctorParamStr(?MethodNode $ctor): string
    {
        if ($ctor === null) return '';
        return implode(', ', array_map(fn($p) => $this->visitParam($p), $ctor->params));
    }

    /** Phase 2: VTable + 方法实现 + allocator */
    private function emitClassImpl(ClassNode $class, bool $isMain): string
    {
        // Skip interface-only classes
        if ($class->isAbstract && $class->parentName === null && empty($class->properties)) {
            return '';
        }
        $this->currentNamespace = $class->namespace; // P2-6: __NAMESPACE__ 上下文
        $this->inMethod = true; // P2-6: 标记进入类方法生成阶段
        $cn = self::classCName($class);
        $ctor = $dtor = null;
        $methods = [];
        foreach ($class->methods as $m) {
            if ($m->name === '__construct') $ctor = $m;
            elseif ($m->name === '__destruct') $dtor = $m;
            else $methods[] = $m;
            // 记录方法参数类型（用于 visitCall 中 t_var 参数包裹）
            $pts = array_map(fn($p) => $this->mapType($p->type), $m->params);
            // 计算默认值参数数量（尾部连续默认值）
            $totalParams = count($m->params);
            $defaultCount = 0;
            for ($i = $totalParams - 1; $i >= 0; $i--) {
                if ($m->params[$i]->default !== null) {
                    $defaultCount++;
                } else {
                    break;
                }
            }
            if ($mi = $this->symbols->getClassMethod($cn, $m->name)) {
                $mi = new MethodInfo($mi->retType, $pts, $mi->isStatic, $mi->visibility, $defaultCount, $totalParams);
            }
            $this->symbols->getClass($cn)->methods[$m->name] = $mi ?? new MethodInfo('void', $pts, false, 'public', $defaultCount, $totalParams);
        }

        $o = [];

        // Class descriptor (COS-style)
        $parentPtr = ($class->parentName !== null)
            ? '&_class_' . self::classRefName($class->parentName)
            : 'NULL';
        $o[] = "/* ── Class: {$cn} ──────────────────────────── */";
        $o[] = "static void* _vtable_{$cn}[1] = { NULL };";
        $o[] = "static const t_class _class_{$cn} = {";
        $o[] = $this->ind("    .name          = \"{$cn}\",");
        $o[] = $this->ind("    .parent        = {$parentPtr},");
        $o[] = $this->ind("    .instance_size = sizeof({$cn}),");
        $o[] = $this->ind("    .exception_offset = " . $this->computeExceptionOffset($cn) . ",");
        $o[] = $this->ind("    .dtor          = (void*){$cn}___destruct,");
        $o[] = $this->ind("    .vtable        = _vtable_{$cn},");
        $o[] = $this->ind("    .vtable_len    = 0,");
        $o[] = $this->ind("};");
        $o[] = '';

        // __construct — 注入参数类型到 varTypes
        $this->declaredVars = ['self' => true];
        $this->varTypes = [];
        $this->localConsts = [];
        $this->assignedReadonlyProps = [];
        $this->symbols->clearScopeObjects();

        if ($isMain) {
            $this->declaredVars['argc'] = true;
            $this->declaredVars['argv'] = true;
            $this->varTypes['argc'] = 't_int';
            $this->varTypes['argv'] = 't_array*';
        } else if ($ctor) {
            foreach ($ctor->params as $p) {
                $vn = self::varName($p->name);
                $this->declaredVars[$vn] = true;
                $this->varTypes[$vn] = self::mapType($p->type);
            }
        }

        $ctorSig = $isMain
            ? "void {$cn}___construct({$cn}* self, t_int argc, t_array* argv) {"
            : "void {$cn}___construct({$cn}* self" . ($this->ctorParamStr($ctor) ? ', ' . $this->ctorParamStr($ctor) : '') . ") {";
        $o[] = $ctorSig;
        $o[] = $this->ind('if (self == NULL) return;');

        // 自动调用父类构造器（初始化 _parent 部分）— PHP 语义：子类构造器不会自动调用父类，
        // 但 COS 结构体继承要求 _parent 部分必须被初始化，否则 parent::method() 访问未初始化内存。
        // 仅当父类有无参构造器时自动调用；若父类构造器有参数（含默认值参数），C 签名仍要求显式传参，
        // 用户需显式 parent::__construct(args)。
        // 注意：PHP 默认参数不等于 C 默认参数（C 无默认参数语法），不能用 defaultCount 判断。
        if ($class->parentName !== null) {
            $parentCName = self::classRefName($class->parentName);
            $parentCtor = $this->symbols->getClassMethod($parentCName, '__construct');
            // Main 类构造器签名不同（argc, argv），不自动调用
            if ($parentCtor !== null && !$this->isMainClassCName($parentCName)
                && $parentCtor->totalParams === 0) {
                $o[] = $this->ind("{$parentCName}___construct(&self->_parent);");
            }
        }

        // 属性默认值初始化 — 字符串用深拷贝（静态属性已在文件作用域初始化，跳过）
        foreach ($class->properties as $prop) {
            if ($prop->isStatic) continue;
            if ($prop->default !== null) {
                $pname = ltrim($prop->name, '$');
                $def = $prop->default->accept($this);
                if ($prop->type === 'string' && $prop->default instanceof StringLiteralExpr) {
                    // 字符串默认值：深拷贝到堆
                    $o[] = $this->ind("self->{$pname} = tphp_rt_str_dup({$def});");
                } else {
                    $o[] = $this->ind("self->{$pname} = {$def};");
                }
            }
        }

        // 数组类常量初始化（每个实例持有一份拷贝）
        foreach ($class->classConsts as $cc) {
            if ($cc->value instanceof ArrayLiteralExpr) {
                $fname = '_const_' . $cc->name;
                $tn = '_c_' . $cc->name;
                $o[] = $this->ind("self->{$fname} = ({ {$this->genArrayLiteralInline($cc->value, $tn)} {$tn}; });");
            }
        }

        // 构造函数参数中的字符串属性：深拷贝到堆（防止栈/临时内存悬空）
        if (!$isMain && $ctor) {
            foreach ($ctor->params as $p) {
                $pname = ltrim($p->name, '$');
                // 检查是否为字符串属性参数
                $isStrProp = false;
                foreach ($class->properties as $prop) {
                    if (ltrim($prop->name, '$') === $pname && $prop->type === 'string') {
                        $isStrProp = true;
                        break;
                    }
                }
                if ($isStrProp) {
                    $o[] = $this->ind("self->{$pname} = tphp_rt_str_dup({$pname});");
                }
            }
        }

        if ($ctor && !empty($ctor->body)) {
            $savedMethodName = $this->currentMethodName;
            $this->currentMethodName = '__construct';
            // 三阶段生成：与 visitMethod 一致，支持 if/for/while 块内变量声明提升到函数作用域
            $this->funcScopeDecls = [];
            $this->scopeDepth = 0;
            $ctorBodyLines = [];
            foreach ($ctor->body as $s) $ctorBodyLines[] = $this->ind($s->accept($this));
            // Phase 3: 注入提升到函数作用域的变量声明（在 body 之前）
            foreach ($this->funcScopeDecls as $vn => $ct) {
                $o[] = $this->ind("{$ct} {$vn} = {0};");
            }
            foreach ($ctorBodyLines as $bl) $o[] = $bl;
            $this->currentMethodName = $savedMethodName;
        } else if ($isMain) {
            $o[] = $this->ind('(void)argc;');
            $o[] = $this->ind('(void)argv;');
        }
        $o[] = '}';
        $o[] = '';

        // __destruct — 先跑用户代码，再释放字符串属性
        $o[] = "void {$cn}___destruct({$cn}* self) {";
        $o[] = $this->ind('if (self == NULL) return;');
        if ($dtor && !empty($dtor->body)) {
            // 同 __construct，使用三阶段生成机制
            // 注意：必须重置 declaredVars/varTypes 等，避免 __construct 中已声明的变量
            //（如 $dbh）被误判为已声明而跳过类型声明 + funcScopeDecls 注册
            $this->declaredVars = ['self' => true];
            $this->varTypes = [];
            $this->localConsts = [];
            $this->symbols->clearScopeObjects();
            $this->funcScopeDecls = [];
            $this->scopeDepth = 0;
            $dtorBodyLines = [];
            foreach ($dtor->body as $s) $dtorBodyLines[] = $this->ind($s->accept($this));
            foreach ($this->funcScopeDecls as $vn => $ct) {
                $o[] = $this->ind("{$ct} {$vn} = {0};");
            }
            foreach ($dtorBodyLines as $bl) $o[] = $bl;
        }
        // 自动释放所有 t_string 属性的堆内存（静态属性为文件作用域变量，不在此释放）
        foreach ($class->properties as $prop) {
            if ($prop->isStatic) continue;
            if ($prop->type === 'string') {
                $pname = ltrim($prop->name, '$');
                $o[] = $this->ind("tphp_rt_str_free(&self->{$pname});");
            }
        }
        $o[] = '}';
        $o[] = '';

        // 用户方法
        $this->indent = 1;  // reset for scope tracking
        foreach ($methods as $m) {
            $o[] = $m->accept($this);
            $o[] = '';
        }

        // Property Hook getter/setter 实现
        foreach ($class->properties as $prop) {
            if (empty($prop->hooks)) continue;
            $pname = ltrim($prop->name, '$');
            $ptype = self::mapType($prop->type);
            foreach ($prop->hooks as $hook) {
                $savedInHook = $this->inHookBody;
                $savedMethodName = $this->currentMethodName;
                $savedFuncName = $this->currentFuncName;
                $savedDeclaredVars = $this->declaredVars;
                $savedVarTypes = $this->varTypes;
                $savedRetType = $this->currentRetType;
                $savedPhpRetType = $this->currentPhpRetType;
                $savedLocalConsts = $this->localConsts;

                $this->inHookBody = true;
                $this->inMethod = true;
                $this->declaredVars = ['self' => true];
                $this->varTypes = ['self' => $cn];
                $this->localConsts = [];
                $this->funcScopeDecls = [];
                $this->currentPhpRetType = ''; // hook 无显式返回类型声明，跳过 |Exception 检查
                $this->symbols->clearScopeObjects();
                $this->symbols->clearScopeVars();

                if ($hook->kind === 'get') {
                    $this->currentMethodName = "get_{$pname}";
                    $this->currentFuncName = "get_{$pname}";
                    $this->currentRetType = $ptype;
                    $o[] = "static {$ptype} {$cn}_get_{$pname}({$cn}* self) {";
                    $o[] = $this->ind('if (self == NULL) ' . $this->zeroReturn($ptype));
                    if ($hook->expr !== null) {
                        // 短形式: get => expr;
                        $exprCode = $hook->expr->accept($this);
                        $o[] = $this->ind("return {$exprCode};");
                    } else {
                        // 块形式: get { stmts }
                        foreach ($hook->body as $s) $o[] = $this->ind($s->accept($this));
                    }
                    $o[] = '}';
                    $o[] = '';
                } elseif ($hook->kind === 'set') {
                    $this->currentMethodName = "set_{$pname}";
                    $this->currentFuncName = "set_{$pname}";
                    $this->currentRetType = 'void';
                    $this->declaredVars['value'] = true;
                    $this->varTypes['value'] = $ptype;
                    $o[] = "static void {$cn}_set_{$pname}({$cn}* self, {$ptype} value) {";
                    $o[] = $this->ind('if (self == NULL) return;');
                    if ($hook->expr !== null) {
                        // 短形式: set => expr;  → self->prop = expr; ($value 是新值)
                        $exprCode = $hook->expr->accept($this);
                        if ($ptype === 't_string') {
                            $o[] = $this->ind("tphp_rt_str_free(&self->{$pname});");
                            $o[] = $this->ind("self->{$pname} = tphp_rt_str_dup({$exprCode});");
                        } else {
                            $o[] = $this->ind("self->{$pname} = {$exprCode};");
                        }
                    } else {
                        // 块形式: set { stmts }
                        foreach ($hook->body as $s) $o[] = $this->ind($s->accept($this));
                    }
                    $o[] = '}';
                    $o[] = '';
                }

                // restore context
                $this->inHookBody = $savedInHook;
                $this->currentMethodName = $savedMethodName;
                $this->currentFuncName = $savedFuncName;
                $this->declaredVars = $savedDeclaredVars;
                $this->varTypes = $savedVarTypes;
                $this->currentRetType = $savedRetType;
                $this->currentPhpRetType = $savedPhpRetType;
                $this->localConsts = $savedLocalConsts;
            }
        }

        // Allocator — skip for abstract classes
        if (!$class->isAbstract) {
            $o[] = "/* ── Allocator: new_{$cn} ──────────────── */";
            $ctorParams = $isMain ? 't_int argc, t_array* argv' : ($this->ctorParamStr($ctor) ?: 'void');
            $o[] = "{$cn}* new_{$cn}({$ctorParams}) {";
            $o[] = $this->ind("{$cn}* self = ({$cn}*)tp_obj_alloc(&_class_{$cn});");
            $o[] = $this->ind('if (self == NULL) return NULL;');
            if ($isMain) {
                $o[] = $this->ind("{$cn}___construct(self, argc, argv);");
            } else {
                $ctorArgs = $ctor ? implode(', ', array_map(fn($p) => self::varName($p->name), $ctor->params)) : '';
                $o[] = $this->ind("{$cn}___construct(self" . ($ctorArgs ? ', ' . $ctorArgs : '') . ");");
            }
            $o[] = $this->ind('return self;');
            $o[] = '}';
            $o[] = '';
            // 默认参数重载：生成 new_cn_<missing>(partial args) → new_cn(full args with defaults)
            if (!$isMain && $ctor !== null) {
                $ctorDefCount = 0;
                foreach ($ctor->params as $p) {
                    if ($p->default !== null) $ctorDefCount++;
                }
                if ($ctorDefCount > 0) {
                    $total = count($ctor->params);
                    $firstDef = $total - $ctorDefCount;
                    for ($cutIdx = $firstDef; $cutIdx < $total; $cutIdx++) {
                        $overloadName = "new_{$cn}_" . ($total - $cutIdx);
                        $cutParams = array_slice($ctor->params, 0, $cutIdx);
                        $overloadParams = array_map(fn($p) => self::paramDecl($p), $cutParams);
                        if (empty($overloadParams)) $overloadParams[] = 'void';
                        $callArgs = [];
                        for ($i = 0; $i < $total; $i++) {
                            if ($i < $cutIdx) {
                                $callArgs[] = self::varName($ctor->params[$i]->name);
                            } else {
                                $callArgs[] = $this->defaultExprCode($ctor->params[$i]);
                            }
                        }
                        $o[] = "static {$cn}* {$overloadName}(" . implode(', ', $overloadParams) . ") {";
                        $o[] = $this->ind("return new_{$cn}(" . implode(', ', $callArgs) . ");");
                        $o[] = '}';
                        $o[] = '';
                    }
                }
            }
        }

        return implode("\n", $o);
    }

    // ============================================================
    public function visitClass(ClassNode $node): string { return ''; }

    /** 独立函数返回类型追踪：funcCName → C 类型 */

    public function visitFunction(FunctionNode $node): string
    {
        if ($node->isGenerator) {
            return $this->emitGeneratorFunction($node);
        }
        $this->currentFuncName = $node->name; // P2-6: __FUNCTION__ 全局函数名
        $this->currentFuncCName = self::funcCName($node);
        $this->inMethod = false;
        $this->currentNamespace = $node->namespace; // P2-6: __NAMESPACE__
        $this->declaredVars = [];
        $this->varTypes = [];
        $this->localConsts = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->deferStack = [];
        $this->cPtrOwnership = [];
        $this->currentPhpRetType = $node->returnType;
        $ret = self::mapType($node->returnType);
        $this->currentRetType = $ret;
        // 注册返回类型，供 inferCallReturnType 使用
        // 同步到 SymbolTable（保留已有 paramTypes/defaultCount/totalParams/isGenerator）
        $fnCName = self::funcCName($node);
        $existingFn = $this->symbols->getFunc($fnCName);
        if ($existingFn !== null) {
            $this->symbols->addFunc($fnCName, new FunctionInfo(
                $ret,
                $existingFn->paramTypes,
                $existingFn->defaultCount,
                $existingFn->totalParams,
                $existingFn->isGenerator,
            ));
        }

        // 检查是否有默认值参数
        $hasDefaults = false;
        foreach ($node->params as $p) {
            if ($p->default !== null) {
                $hasDefaults = true;
                break;
            }
        }

        $parts = [];

        // 生成重载函数（如果有默认值参数）
        if ($hasDefaults) {
            $parts[] = $this->generateFunctionOverloads($node, $ret);
        }

        // 生成主函数（完整参数版本）
        $this->declaredVars = [];
        $this->varTypes = [];
        $this->localConsts = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->deferStack = [];
        $this->cPtrOwnership = [];

        $params = array_map(fn($p) => self::paramDecl($p), $node->params);
        $paramVars = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $this->paramCTypeResolved($p);
            $paramVars[$vn] = true;
        }
        $header = [];
        $header[] = 'static ' . $ret . ' ' . self::funcCName($node) . '(' . implode(', ', $params) . ') {';

        $bodyLines = [];
        foreach ($node->body as $s) $bodyLines[] = $this->ind($s->accept($this));

        // 注入 for 循环提升声明
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn} = {0};");
        }

        // 自动生成作用域结束时的释放代码（defer LIFO → scope cleanup → 对象释放）
        $tail = [];
        $tail = array_merge($tail, $this->generateDeferCleanup());
        $tail = array_merge($tail, $this->generateScopeCleanup($paramVars));
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tail[] = $this->ind("tp_obj_release({$ov});");
        }
        $tail[] = '}';
        $parts[] = implode("\n", array_merge($header, $declLines, $bodyLines, $tail));

        // 编译期泄漏提醒：扫描未清理的 C 指针变量
        $this->warnLeakedCPtrs(self::funcCName($node));

        return implode("\n\n", $parts);
    }

    /**
     * 生成器函数变换：PHP function gen(): Generator { yield ...; }
     * 编译为两个 C 函数：
     *   1) 协程入口 static void tphp_gen_<name>_entry(mco_coro* co) { 函数体 }
     *   2) 包装函数   tphp_class_Generator* tphp_fn_<name>(params) { 创建协程 }
     */
    private function emitGeneratorFunction(FunctionNode $node): string
    {
        $fnCName = self::funcCName($node);
        $entryName = 'tphp_gen_' . $fnCName . '_entry';
        $paramsStruct = '_gen_params_' . $fnCName;

        // 保存状态
        $savedDeclaredVars = $this->declaredVars;
        $savedVarTypes = $this->varTypes;
        $savedCurrentRetType = $this->currentRetType;
        $savedCurrentPhpRetType = $this->currentPhpRetType;
        $savedInGenerator = $this->inGenerator;
        $savedLocalConsts = $this->localConsts;

        // 重置作用域
        $this->declaredVars = [];
        $this->varTypes = [];
        $this->localConsts = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->currentRetType = 't_var';
        $this->currentPhpRetType = $node->returnType;
        $this->inGenerator = true;

        // 注册参数到局部变量表（与 visitFunction 一致）
        $paramVars = [];
        $paramFields = [];
        $paramLocalDecls = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $ct = $this->paramCTypeResolved($p);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $ct;
            $paramVars[$vn] = true;
            $paramFields[] = "    {$ct} {$vn};";
            $paramLocalDecls[] = "    {$ct} {$vn};";
        }

        // 解包参数：从 user_data 复制到局部变量
        $unpackLines = [];
        $unpackLines[] = "    {$paramsStruct}* _p = ({$paramsStruct}*)mco_get_user_data(co);";
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $unpackLines[] = "    {$vn} = _p->{$vn};";
        }
        $unpackLines[] = '    free(_p);';
        $unpackLines[] = '    int _auto_key = 0;';

        // 生成函数体（yield→visitYieldExpr, return→visitReturnStmt 生成器分支）
        $bodyLines = [];
        foreach ($node->body as $s) {
            $bodyLines[] = $this->ind($s->accept($this));
        }

        // for 循环提升声明
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn} = {0};");
        }

        // 末尾释放（局部字符串/数组/对象）
        $tailLines = [];
        foreach ($this->generateScopeCleanup($paramVars) as $l) {
            $tailLines[] = $l;
        }
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tailLines[] = $this->ind("tp_obj_release({$ov});");
        }

        // 恢复状态
        $this->declaredVars = $savedDeclaredVars;
        $this->varTypes = $savedVarTypes;
        $this->currentRetType = $savedCurrentRetType;
        $this->currentPhpRetType = $savedCurrentPhpRetType;
        $this->inGenerator = $savedInGenerator;
        $this->localConsts = $savedLocalConsts;
        // 参数结构体 typedef → SEC_FWDDECLS
        $typedef = "typedef struct {\n" . implode("\n", $paramFields) . "\n} {$paramsStruct};";
        $this->sectionLine(self::SEC_FWDDECLS, $typedef);

        // 协程入口函数
        $entryLines = array_merge(
            ["static void {$entryName}(mco_coro* co) {"],
            $paramLocalDecls,
            $unpackLines,
            $declLines,
            $bodyLines,
            $tailLines,
            ["}"]
        );
        $entryFn = implode("\n", $entryLines);

        // 包装函数
        $paramDecls = array_map(fn($p) => self::paramDecl($p), $node->params);
        $paramAssigns = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $paramAssigns[] = "    _p->{$vn} = {$vn};";
        }
        $wrapperLines = array_merge(
            ["tphp_class_Generator* {$fnCName}(" . implode(', ', $paramDecls) . ") {"],
            ["    {$paramsStruct}* _p = ({$paramsStruct}*)calloc(1, sizeof({$paramsStruct}));"],
            $paramAssigns,
            ["    mco_desc desc = mco_desc_init({$entryName}, 0);"],
            ["    desc.user_data = _p;"],
            ["    mco_coro* co;"],
            ["    if (mco_create(&co, &desc) != MCO_SUCCESS) { free(_p); return NULL; }"],
            ["    return new_tphp_class_Generator(co);"],
            ["}"]
        );
        $wrapperFn = implode("\n", $wrapperLines);

        return $entryFn . "\n\n" . $wrapperFn;
    }

    /**
     * 生成器方法变换：与 emitGeneratorFunction 类似，但 self 指针打包进 params struct。
     *
     *   1) 协程入口 static void tphp_gen_{cn}_{name}_entry(mco_coro* co) { 方法体 }
     *   2) 包装方法   tphp_class_Generator* {cn}_{name}({cn}* self, params) { 创建协程 }
     *
     * self 指针借用调用方引用（与独立生成器函数的对象参数一致，不做额外 retain/release）。
     */
    private function emitGeneratorMethod(MethodNode $node): string
    {
        $cn = $this->className;  // e.g., tphp_class_Foo / tphp_enum_Color
        $fnCName = "{$cn}_{$node->name}";
        $entryName = 'tphp_gen_' . $fnCName . '_entry';
        $paramsStruct = '_gen_params_' . $fnCName;

        // 保存状态
        $savedDeclaredVars = $this->declaredVars;
        $savedVarTypes = $this->varTypes;
        $savedCurrentRetType = $this->currentRetType;
        $savedCurrentPhpRetType = $this->currentPhpRetType;
        $savedInGenerator = $this->inGenerator;
        $savedCurrentMethodName = $this->currentMethodName;
        $savedCurrentFuncName = $this->currentFuncName;
        $savedInMethod = $this->inMethod;
        $savedLocalConsts = $this->localConsts;

        // 重置作用域
        $this->declaredVars = ['self' => true];
        $this->varTypes = ['self' => $cn . '*'];
        $this->localConsts = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->currentRetType = 't_var';
        $this->currentPhpRetType = $node->returnType;
        $this->inGenerator = true;
        $this->currentMethodName = $node->name;
        $this->currentFuncName = $node->name;
        $this->inMethod = true;

        // 检查默认值参数 → 生成重载
        $hasDefaults = false;
        foreach ($node->params as $p) {
            if ($p->default !== null) { $hasDefaults = true; break; }
        }

        $overloadCode = '';
        if ($hasDefaults) {
            $overloadCode = $this->generateMethodOverloads($node) . "\n\n";
        }

        // params struct 包含 self + 用户参数
        $paramVars = ['self' => true];
        $paramFields = ["    {$cn}* self;"];
        $paramLocalDecls = ["    {$cn}* self;"];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $ct = $this->paramCTypeResolved($p);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $ct;
            $paramVars[$vn] = true;
            $paramFields[] = "    {$ct} {$vn};";
            $paramLocalDecls[] = "    {$ct} {$vn};";
        }

        // 解包参数：从 user_data 复制到局部变量
        $unpackLines = [];
        $unpackLines[] = "    {$paramsStruct}* _p = ({$paramsStruct}*)mco_get_user_data(co);";
        $unpackLines[] = "    self = _p->self;";
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $unpackLines[] = "    {$vn} = _p->{$vn};";
        }
        $unpackLines[] = '    free(_p);';
        $unpackLines[] = '    int _auto_key = 0;';

        // 生成函数体
        $bodyLines = [];
        if ($node->body === null) {
            return $overloadCode; // abstract method — no body
        }
        foreach ($node->body as $s) {
            $bodyLines[] = $this->ind($s->accept($this));
        }

        // for 循环提升声明
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn} = {0};");
        }

        // 末尾释放
        $tailLines = [];
        foreach ($this->generateScopeCleanup($paramVars) as $l) {
            $tailLines[] = $l;
        }
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tailLines[] = $this->ind("tp_obj_release({$ov});");
        }

        // 恢复状态
        $this->declaredVars = $savedDeclaredVars;
        $this->varTypes = $savedVarTypes;
        $this->currentRetType = $savedCurrentRetType;
        $this->currentPhpRetType = $savedCurrentPhpRetType;
        $this->inGenerator = $savedInGenerator;
        $this->localConsts = $savedLocalConsts;
        $this->currentMethodName = $savedCurrentMethodName;
        $this->currentFuncName = $savedCurrentFuncName;
        $this->inMethod = $savedInMethod;

        // params struct typedef → SEC_CLSIMPL（在类结构体定义之后，方法实现之前）
        $typedef = "typedef struct {\n" . implode("\n", $paramFields) . "\n} {$paramsStruct};";
        $this->sectionLine(self::SEC_CLSIMPL, $typedef);

        // 协程入口函数
        $entryLines = array_merge(
            ["static void {$entryName}(mco_coro* co) {"],
            $paramLocalDecls,
            $unpackLines,
            $declLines,
            $bodyLines,
            $tailLines,
            ["}"]
        );
        $entryFn = implode("\n", $entryLines);

        // 包装方法（方法签名与普通方法一致，返回 tphp_class_Generator*）
        $paramDecls = array_map(fn($p) => self::paramDecl($p), $node->params);
        $wrapperParams = "{$cn}* self" . (empty($paramDecls) ? '' : ', ' . implode(', ', $paramDecls));
        $paramAssigns = ["    _p->self = self;"];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $paramAssigns[] = "    _p->{$vn} = {$vn};";
        }
        $wrapperLines = array_merge(
            ["tphp_class_Generator* {$fnCName}({$wrapperParams}) {"],
            ["    if (self == NULL) return NULL;"],
            ["    {$paramsStruct}* _p = ({$paramsStruct}*)calloc(1, sizeof({$paramsStruct}));"],
            $paramAssigns,
            ["    mco_desc desc = mco_desc_init({$entryName}, 0);"],
            ["    desc.user_data = _p;"],
            ["    mco_coro* co;"],
            ["    if (mco_create(&co, &desc) != MCO_SUCCESS) { free(_p); return NULL; }"],
            ["    return new_tphp_class_Generator(co);"],
            ["}"]
        );
        $wrapperFn = implode("\n", $wrapperLines);

        return $overloadCode . $entryFn . "\n\n" . $wrapperFn;
    }

    /**
     * 为有默认值的函数生成重载版本
     * 例如: function foo(int $a, int $b = 10, int $c = 20)
     * 生成: foo_2($a) → foo($a, 10, 20)
     *        foo_1($a, $b) → foo($a, $b, 20)
     */
    private function generateFunctionOverloads(FunctionNode $node, string $ret): string
    {
        $parts = [];
        $funcName = self::funcCName($node);

        // 找到第一个有默认值的参数位置
        $firstDefaultIdx = count($node->params);
        for ($i = 0; $i < count($node->params); $i++) {
            if ($node->params[$i]->default !== null) {
                $firstDefaultIdx = $i;
                break;
            }
        }

        // 生成从 firstDefaultIdx 到 count-1 的重载版本
        for ($cutIdx = $firstDefaultIdx; $cutIdx < count($node->params); $cutIdx++) {
            $overloadName = $funcName . '_' . (count($node->params) - $cutIdx);
            $cutParams = array_slice($node->params, 0, $cutIdx);

            // 重载函数参数列表
            $overloadParams = array_map(fn($p) => self::paramDecl($p), $cutParams);

            // 调用完整参数版本时传递的参数
            $callArgs = [];
            for ($i = 0; $i < count($node->params); $i++) {
                if ($i < $cutIdx) {
                    // 直接传递参数
                    $callArgs[] = self::varName($node->params[$i]->name);
                } else {
                    // 使用默认值（按参数类型适配 null 字面量）
                    $callArgs[] = $this->defaultExprCode($node->params[$i]);
                }
            }

            $overloadBody = "    return {$funcName}(" . implode(', ', $callArgs) . ");";
            $parts[] = "static {$ret} {$overloadName}(" . implode(', ', $overloadParams) . ") {\n{$overloadBody}\n}";
        }

        return implode("\n\n", $parts);
    }

    /** 根据 C 返回类型生成零值 return（兼容 GCC/Clang -Wreturn-mismatch） */
    private function zeroReturn(string $cType): string
    {
        return match ($cType) {
            'void'    => 'return;',
            't_int'   => 'return 0;',
            't_float' => 'return 0.0;',
            't_bool'  => 'return false;',
            't_string'=> 'return (t_string){NULL, 0};',
            't_array*'=> 'return NULL;',
            't_var'   => 'return (t_var){0};',
            't_callback' => 'return (t_callback){NULL, NULL};',
            'void*'   => 'return NULL;',
            default   => str_ends_with($cType, '*')
                ? 'return NULL;'
                : 'return 0;',
        };
    }

    public function visitMethod(MethodNode $node): string
    {
        if ($node->isGenerator) {
            return $this->emitGeneratorMethod($node);
        }
        $isStatic = $node->isStatic;
        $this->currentMethodName = $node->name;
        $this->currentFuncName = $node->name; // P2-6: __FUNCTION__ 在方法内返回方法名
        $this->currentFuncCName = $this->className . '_' . $node->name;
        $this->inMethod = true;
        // 静态方法无 self 变量
        $this->declaredVars = $isStatic ? [] : ['self' => true];
        $this->varTypes = [];
        $this->localConsts = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->deferStack = [];
        $this->cPtrOwnership = [];
        $this->currentPhpRetType = $node->returnType;
        $this->currentRetType = $this->mapType($node->returnType);

        // 检查是否有默认值参数
        $hasDefaults = false;
        foreach ($node->params as $p) {
            if ($p->default !== null) {
                $hasDefaults = true;
                break;
            }
        }

        $parts = [];

        // 生成重载函数（如果有默认值参数）
        if ($hasDefaults) {
            $parts[] = $this->generateMethodOverloads($node);
        }

        // 生成主方法（完整参数版本）
        $this->currentMethodName = $node->name;
        $this->currentFuncName = $node->name; // P2-6
        $this->currentFuncCName = $this->className . '_' . $node->name;
        $this->inMethod = true;
        $this->declaredVars = $isStatic ? [] : ['self' => true];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->deferStack = [];
        $this->cPtrOwnership = [];
        $this->currentPhpRetType = $node->returnType;
        $this->currentRetType = $this->mapType($node->returnType);

        $paramVars = $isStatic ? [] : ['self' => true];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $this->paramCTypeResolved($p);
            $paramVars[$vn] = true;
        }
        // Phase 1: header
        $header = [];
        $header[] = $this->methodImpl($node) . ' {';
        // 静态方法无 self，跳过 NULL 检查
        if (!$isStatic) {
            $header[] = $this->ind('if (self == NULL) ' . $this->zeroReturn($this->currentRetType));
        }

        // Phase 2: body (侧作用: 填充 funcScopeDecls)
        $bodyLines = [];
        if ($node->body === null) {
            // abstract method — forward declaration only, no implementation
            return '';
        }
        if (empty($node->body)) {
            foreach ($node->params as $p) $bodyLines[] = $this->ind("(void)" . self::varName($p->name) . ";");
        } else {
            foreach ($node->body as $s) $bodyLines[] = $this->ind($s->accept($this));
        }

        // Phase 3: 注入 for 循环提升到函数作用域的变量声明（在 body 之前）
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn} = {0};");
        }

        // 自动生成作用域结束时的释放代码（defer LIFO → scope cleanup → 对象释放）
        $tail = [];
        $tail = array_merge($tail, $this->generateDeferCleanup());
        $tail = array_merge($tail, $this->generateScopeCleanup($paramVars));
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tail[] = $this->ind("tp_obj_release({$ov});");
        }
        $tail[] = '}';

        $parts[] = implode("\n", array_merge($header, $declLines, $bodyLines, $tail));

        // 编译期泄漏提醒：扫描未清理的 C 指针变量
        $this->warnLeakedCPtrs($this->className . '::' . $node->name);

        return implode("\n\n", $parts);
    }

    /**
     * 为有默认值的方法生成重载版本
     */
    private function generateMethodOverloads(MethodNode $node): string
    {
        $parts = [];
        $ret = $node->isGenerator ? 'tphp_class_Generator*' : $this->mapType($node->returnType);
        $methodImpl = $this->methodImpl($node);
        // 获取类名（从 methodImpl 中提取）
        $cn = $this->className;
        $isStatic = $node->isStatic;

        // 找到第一个有默认值的参数位置
        $firstDefaultIdx = count($node->params);
        for ($i = 0; $i < count($node->params); $i++) {
            if ($node->params[$i]->default !== null) {
                $firstDefaultIdx = $i;
                break;
            }
        }

        // 生成从 firstDefaultIdx 到 count-1 的重载版本
        for ($cutIdx = $firstDefaultIdx; $cutIdx < count($node->params); $cutIdx++) {
            $overloadName = $cn . '_' . $node->name . '_' . (count($node->params) - $cutIdx);
            $cutParams = array_slice($node->params, 0, $cutIdx);

            // 重载函数参数列表（静态方法无 self）
            // 注意：$cn 已是 classCName() 返回值（含 tphp_class_ 前缀），不再重复添加
            $overloadParams = [];
            if (!$isStatic) $overloadParams[] = $cn . '* self';
            foreach ($cutParams as $p) {
                $overloadParams[] = self::paramDecl($p);
            }
            if (empty($overloadParams)) $overloadParams[] = 'void';

            // 调用完整参数版本时传递的参数
            $callArgs = [];
            if (!$isStatic) $callArgs[] = 'self';
            for ($i = 0; $i < count($node->params); $i++) {
                if ($i < $cutIdx) {
                    $callArgs[] = self::varName($node->params[$i]->name);
                } else {
                    $callArgs[] = $this->defaultExprCode($node->params[$i]);
                }
            }

            $overloadBody = "    return {$cn}_{$node->name}(" . implode(', ', $callArgs) . ");";
            $parts[] = "static {$ret} {$overloadName}(" . implode(', ', $overloadParams) . ") {\n{$overloadBody}\n}";
        }

        return implode("\n\n", $parts);
    }

    public function visitParam(ParamNode $node): string
    {
        $ct = self::mapType($node->type);
        return $node->byRef ? "{$ct} *" . self::varName($node->name) : "{$ct} " . self::varName($node->name);
    }

    /**
     * 生成作用域结束时的自动释放代码
     * @param array $paramVars 参数变量名集合（排除在自动释放之外）
     * @return string[] 释放代码行
     */
    private function generateScopeCleanup(array $paramVars): array
    {
        $lines = [];
        $released = [];
        $returnedVars = $this->symbols->returnedVars();

        // 释放字符串变量
        foreach ($this->symbols->scopeStrings() as $vn => $ct) {
            if (isset($paramVars[$vn]) || isset($released[$vn]) || isset($returnedVars[$vn])) continue;
            $lines[] = $this->ind("tphp_fn_unset_str(&{$vn});");
            $released[$vn] = true;
        }

        // 释放数组变量
        foreach ($this->symbols->scopeArrays() as $vn => $ct) {
            if (isset($paramVars[$vn]) || isset($released[$vn]) || isset($returnedVars[$vn])) continue;
            $lines[] = $this->ind("tphp_fn_unset_arr(&{$vn});");
            $released[$vn] = true;
        }

        return $lines;
    }

    // ============================================================
    public function visitEchoStmt(EchoStmtNode $node): string
    {
        $parts = [];
        foreach ($node->exprs as $e) {
            $code = $e->accept($this);
            // 如果表达式不是字符串字面量/变量引用，可能需要转换
            if ($e instanceof StringLiteralExpr) {
                $parts[] = "tphp_fn_echo({$code});";
            } elseif ($e instanceof VariableExpr) {
                // 变量：推导类型决定是否需要转换
                $vn = self::varName($e->name);
                $vt = $this->varTypes[$vn] ?? '';
                if ($vt === 't_var') {
                    // t_var 变量用 var_dump 输出
                    $parts[] = 'tphp_fn_var_dump(' . $this->wrapVar($e) . ');';
                } elseif ($vt === 't_string' || $vt === 't_int' || $vt === 't_float' || $vt === 't_bool') {
                    $parts[] = $this->echoWrap($vt, $code);
                } elseif ($vt === 'tphp_class_Exception*') {
                    // Exception 对象：echo $e 等价 echo $e->getMessage()
                    $parts[] = "tphp_fn_echo(tphp_class_Exception_getMessage({$code}));";
                } else {
                    $parts[] = "tphp_fn_echo({$code});";
                }
            } elseif ($e instanceof EnumAccessExpr) {
                // 枚举访问 → 输出 ->value
                $bt = $this->enumBackingType($e->enumName);
                if ($bt === 'string') {
                    $parts[] = "tphp_fn_echo(({$code})->value);";
                } else {
                    $parts[] = "tphp_fn_echo(tphp_rt_str_from_int(({$code})->value));";
                }
            } elseif ($e instanceof PropertyAccessExpr) {
                // 属性访问：查找属性类型（含 enum 属性 ->value/->name）
                $pt = $this->getPropType($e);
                if ($pt !== '') {
                    $parts[] = $this->echoWrap($pt, $code);
                } else {
                    $parts[] = "tphp_fn_echo({$code});";
                }
            } elseif ($e instanceof CastExpr) {
                // 类型转换：根据目标类型包装
                $ct = $e->castType;
                $parts[] = $ct === 'string' ? "tphp_fn_echo({$code});"
                         : $this->echoWrap(self::$typeMap[$ct] ?? 't_int', $code);
            } elseif ($e instanceof CallExpr || $e instanceof BinaryExpr
                   || $e instanceof PostfixExpr || $e instanceof CompoundAssignExpr
                   || $e instanceof UnaryExpr || $e instanceof TernaryExpr
                   || $e instanceof NullCoalesceExpr || $e instanceof MatchExpr
                   || $e instanceof ArrayAccessExpr) {
                // 表达式：通过 inferType 推导实际类型后包装
                $et = $this->inferType($e);
                $parts[] = $this->echoWrap($et, $code);
            } else {
                $parts[] = "tphp_fn_echo({$code});";
            }
        }
        return implode("\n" . $this->indentStr(), $parts);
    }

    private function echoWrap(string $type, string $code): string
    {
        return match ($type) {
            't_string' => "tphp_fn_echo({$code});",
            't_int'    => "tphp_fn_echo(tphp_rt_str_from_int({$code}));",
            't_float'  => "tphp_fn_echo(tphp_rt_str_from_float({$code}));",
            't_bool'   => "tphp_fn_echo(tphp_rt_str_from_bool({$code}));",
            default    => "tphp_fn_echo({$code});",
        };
    }

    public function visitReturnStmt(ReturnStmtNode $node): string
    {
        // return throw expr; → throw 永不返回，直接生成 throw 语句
        if ($node->expr !== null && $node->expr instanceof ThrowExprNode) {
            return $this->genThrowCode($node->expr->expr) . ';';
        }
        // defer 清理代码（LIFO）— 在 return 前执行
        $deferLines = $this->generateDeferCleanup();
        $deferCode = empty($deferLines) ? '' : implode("\n", $deferLines) . "\n";
        if ($this->inGenerator) {
            // 生成器内：push 返回值（t_var），然后裸 return
            if ($node->expr !== null) {
                if ($node->expr instanceof VariableExpr) {
                    $vn = self::varName($node->expr->name);
                    $this->symbols->addReturnedVar($vn);
                }
                $code = $node->expr->accept($this);
                $valVar = $this->wrapTvarAssign($node->expr, $code);
                return "{ t_var _gen_ret = {$valVar}; mco_push(mco_running(), &_gen_ret, sizeof(t_var));\n{$deferCode}    return; }";
            }
            return "{ t_var _gen_ret = VAR_NULL(); mco_push(mco_running(), &_gen_ret, sizeof(t_var));\n{$deferCode}    return; }";
        }
        if ($node->expr) {
            // 追踪返回的变量名（用于排除自动释放）
            if ($node->expr instanceof VariableExpr) {
                $vn = self::varName($node->expr->name);
                $this->symbols->addReturnedVar($vn);
                // 返回 C 指针变量 = 所有权转移给调用者，不算泄漏
                $this->markCPtrCleaned($vn);
            }
            // 追踪函数返回数组的 per-key 类型（供调用者 $var = func() 后 $var["key"] 类型推断）
            if ($this->currentFuncCName !== '') {
                if ($node->expr instanceof ArrayLiteralExpr) {
                    // case 1: return ["key" => $val, ...]
                    $this->fnReturnArrKeyTypes[$this->currentFuncCName] ??= [];
                    foreach ($node->expr->entries as $entry) {
                        if ($entry->key instanceof StringLiteralExpr) {
                            $valType = $this->inferType($entry->value);
                            if ($valType !== 'null') {
                                $this->fnReturnArrKeyTypes[$this->currentFuncCName][$entry->key->value] = $valType;
                            }
                        }
                    }
                } elseif ($node->expr instanceof VariableExpr) {
                    // case 2: return $var — 传播 $var 的 arrValueTypes
                    $rvn = self::varName($node->expr->name);
                    if (isset($this->arrValueTypes[$rvn])) {
                        $this->fnReturnArrKeyTypes[$this->currentFuncCName] ??= [];
                        foreach ($this->arrValueTypes[$rvn] as $k => $t) {
                            $this->fnReturnArrKeyTypes[$this->currentFuncCName][$k] = $t;
                        }
                    }
                } elseif ($node->expr instanceof CallExpr && $node->expr->callee === null) {
                    // case 3: return func() — 传播被调用函数的 fnReturnArrKeyTypes
                    $calledFnCName = self::funcCNameFromCall($node->expr);
                    if ($calledFnCName !== '' && isset($this->fnReturnArrKeyTypes[$calledFnCName])) {
                        $this->fnReturnArrKeyTypes[$this->currentFuncCName] ??= [];
                        foreach ($this->fnReturnArrKeyTypes[$calledFnCName] as $k => $t) {
                            $this->fnReturnArrKeyTypes[$this->currentFuncCName][$k] = $t;
                        }
                    }
                }
            }
            $code = $node->expr->accept($this);
            if ($this->currentRetType === 't_var') {
                $code = $this->wrapTvarAssign($node->expr, $code);
            }
            if ($deferCode !== '') {
                // 先求值返回表达式到临时变量，执行 defer 清理，再 return 临时变量
                // 避免返回表达式中引用的变量被 defer 释放后变成野指针
                return "{ {$this->currentRetType} __defer_ret = {$code};\n{$deferCode}    return __defer_ret; }";
            }
            return 'return ' . $code . ';';
        }
        if ($deferCode !== '') {
            return "{\n{$deferCode}    return; }";
        }
        return 'return;';
    }

    /**
     * yield 表达式 → GCC statement expression
     * 推送 {key, value} 到协程存储，mco_yield 挂起，恢复后弹出 send 值作为表达式结果
     */
    public function visitYieldExpr(YieldExpr $node): string
    {
        // 计算 value（转 t_var）
        if ($node->value !== null) {
            $valCode = $node->value->accept($this);
            $valVar = $this->wrapTvarAssign($node->value, $valCode);
        } else {
            $valVar = 'VAR_NULL()';
        }

        // 计算 key
        if ($node->key !== null) {
            $keyCode = $node->key->accept($this);
            $keyExpr = $this->wrapTvarAssign($node->key, $keyCode);
        } else {
            $keyExpr = '((t_var){.type = TYPE_INT, .value._int = _auto_key++})';
        }

        // statement expression：push yield pair → yield → pop sent → 返回 t_var
        return "({ _gen_yield_pair _yp; _yp.key = {$keyExpr}; _yp.value = {$valVar}; " .
               "mco_push(mco_running(), &_yp, sizeof(_yp)); mco_yield(mco_running()); " .
               "t_var _sent; if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) { _sent = VAR_NULL(); } _sent; })";
    }

    /**
     * yield from expr — 委托子生成器或数组
     *
     * Generator 路径：rewind → 循环(current/key 透传 yield + send 转发) → getReturn
     * Array 路径：foreach 透传 yield（send 值丢弃）
     *
     * 返回值为 t_var：Generator 委托返回 getReturn()，array 委托返回 NULL
     */
    public function visitYieldFromExpr(YieldFromExpr $node): string
    {
        $innerCode = $node->expr->accept($this);
        $innerType = $this->inferType($node->expr);

        // Generator 委托
        if ($innerType === 'tphp_class_Generator*' || str_contains($innerType, 'tphp_class_Generator')) {
            return "({ tphp_class_Generator* _sub = {$innerCode}; " .
                   "tphp_class_Generator_rewind(_sub); " .
                   "t_var _yf_ret = VAR_NULL(); " .
                   "while (tphp_class_Generator_valid(_sub)) { " .
                   "_gen_yield_pair _yp; _yp.key = tphp_class_Generator_key(_sub); " .
                   "_yp.value = tphp_class_Generator_current(_sub); " .
                   "mco_push(mco_running(), &_yp, sizeof(_yp)); mco_yield(mco_running()); " .
                   "t_var _sent; if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) { _sent = VAR_NULL(); } " .
                   "tphp_class_Generator_send(_sub, _sent); } " .
                   "_yf_ret = tphp_class_Generator_getReturn(_sub); " .
                   "tp_obj_release(_sub); _yf_ret; })";
        }

        // Array 委托
        if ($innerType === 't_array*') {
            $arrVar = $this->wrapTvarAssign($node->expr, $innerCode);
            return "({ t_var _av = {$arrVar}; t_array* _arr = _av.value._array; " .
                  "t_var _yf_ret = VAR_NULL(); " .
                  "if (_arr) { for (size_t _i = 0; _i < (size_t)_arr->length; _i++) { " .
                  "_gen_yield_pair _yp; _yp.key = _arr->entries[_i].key; " .
                  "_yp.value = _arr->entries[_i].val; " .
                  "mco_push(mco_running(), &_yp, sizeof(_yp)); mco_yield(mco_running()); " .
                  "t_var _sent; mco_pop(mco_running(), &_sent, sizeof(t_var)); } } _yf_ret; })";
        }

        // 默认：按 Generator 处理（类型推断失败时的兜底）
        return "({ tphp_class_Generator* _sub = {$innerCode}; " .
               "tphp_class_Generator_rewind(_sub); " .
               "t_var _yf_ret = VAR_NULL(); " .
               "while (tphp_class_Generator_valid(_sub)) { " .
               "_gen_yield_pair _yp; _yp.key = tphp_class_Generator_key(_sub); " .
               "_yp.value = tphp_class_Generator_current(_sub); " .
               "mco_push(mco_running(), &_yp, sizeof(_yp)); mco_yield(mco_running()); " .
               "t_var _sent; if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) { _sent = VAR_NULL(); } " .
               "tphp_class_Generator_send(_sub, _sent); } " .
               "_yf_ret = tphp_class_Generator_getReturn(_sub); " .
               "tp_obj_release(_sub); _yf_ret; })";
    }

    public function visitAssignStmt(AssignStmtNode $node): string
    {
        // $x = throw expr; → throw 永不返回，直接生成 throw 语句（不赋值）
        if ($node->expr instanceof ThrowExprNode) {
            return $this->genThrowCode($node->expr->expr) . ';';
        }
        $var = self::varName($node->varName);
        $isDeclared = isset($this->declaredVars[$var]);
        $prevType = $this->varTypes[$var] ?? '';
        $isTVar = ($prevType === 't_var');

        // 对 t_var 变量，值需包装为 VAR_XXX 宏
        if ($isTVar) {
            $valCode = $node->expr->accept($this);
            $wrap = $this->wrapTvarAssign($node->expr, $valCode);
            $this->declaredVars[$var] = true;
            return "{$var} = {$wrap};";
        }

        $expr = $node->expr->accept($this);
        $this->declaredVars[$var] = true;

        // new ClassName(...) → tphp_ClassName* var = expr; + 注册到全局资源表
        if ($node->expr instanceof NewExpr) {
            $cn = self::classRefName($node->expr->className);
            // 有声明类型时校验一致性
            if ($node->type !== null) {
                $declCType = self::mapType($node->type);
                if ($declCType !== $cn . '*') {
                    throw new \RuntimeException(
                        "Variable \${$var} type mismatch: declared '{$node->type}' ({$declCType}) "
                        . "but assigned new {$node->expr->className} ({$cn}*)"
                    );
                }
            }
            $this->varTypes[$var] = $cn . '*';
            if ($this->indent == 1) {
                $this->symbols->addScopeObject($var);  // 仅顶层作用域自动析构
            }
            if ($isDeclared) {
                return "{$var} = {$expr}; tphp_rt_register((void*){$var}, 0);";
            }
            if ($this->scopeDepth > 0) {
                $this->funcScopeDecls[$var] = "{$cn}*";
                return "{$var} = {$expr}; tphp_rt_register((void*){$var}, 0);";
            }
            return "{$cn}* {$var} = {$expr}; tphp_rt_register((void*){$var}, 0);";
        }

        // (array)xxx → 标量转单元素数组
        if ($node->expr instanceof CastExpr && $node->expr->castType === 'array') {
            $this->varTypes[$var] = 't_array*';
            // 推导 cast 源类型作为数组元素类型
            $srcType = $this->inferType($node->expr->expr);
            $this->arrElementTypes[$var] = ($srcType === 'null' || $srcType === 'void*') ? 't_int' : $srcType;
            if (!$isDeclared) {
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = 't_array*';
                    return "{$var} = {$expr};";
                }
                return "t_array* {$var} = {$expr};";
            }
            return "{$var} = {$expr};";
        }

        // null 赋值 → PHP 类型为 null，C 类型用 void* 占位
        if ($node->expr instanceof NullLiteralExpr) {
            $this->varTypes[$var] = 'null';
            if (!$isDeclared) {
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = 'void*';
                    return "{$var} = null;";
                }
                return "void* {$var} = null;";
            }
            return "{$var} = null;";
        }

        // 首次赋值 → 推导类型并声明
        if (!$isDeclared) {
            $inferredType = $this->inferType($node->expr);
            // 有声明类型时校验一致性并优先使用
            if ($node->type !== null) {
                $cType = self::mapType($node->type);
                if ($inferredType !== 'null' && $inferredType !== $cType) {
                    // Raw C 调用/常量返回类型编译期不可靠（默认推导为 t_int），
                    //   信任用户的显式声明（覆盖 tphp 标准类型和 C 指针类型）
                    $isRawCAccess = ($node->expr instanceof CallExpr && $node->expr->isRawC)
                        || ($node->expr instanceof PropertyAccessExpr
                            && $node->expr->object instanceof VariableExpr
                            && $node->expr->object->name === 'C');
                    if (!$isRawCAccess) {
                        throw new \RuntimeException(
                            "Variable \${$var} type mismatch: declared '{$node->type}' ({$cType}) "
                            . "but inferred {$inferredType}"
                        );
                    }
                }
            } else {
                // Raw C 调用/常量必须显式声明类型（AOT 类型安全）
                //   原因：C->foo() 返回类型编译期不可靠（inferCallReturnType 默认 t_int），
                //   强制声明可消除白名单和默认 t_int 假设，编译期即捕获类型错误
                $isRawCAccess = ($node->expr instanceof CallExpr && $node->expr->isRawC)
                    || ($node->expr instanceof PropertyAccessExpr
                        && $node->expr->object instanceof VariableExpr
                        && $node->expr->object->name === 'C');
                if ($isRawCAccess) {
                    throw new \RuntimeException(
                        "Variable \${$var} requires explicit type declaration for raw C access. "
                        . "Use 'int \$x = C->foo()' or 'C.void* \$x = C->foo()' to declare."
                    );
                }
                // tphp 标准类型可自动推导；phpc C 指针类型须显式声明
                //   原因：C->func() 返回 void* 时 inferType 统一推导为 'null'，类型信息丢失，
                //   后续 cstruct 字段访问（$p->x）等操作无法正确展开。
                //   但用户定义函数的返回类型来自 PHP 类型注解（如 : C.Point*），类型明确，
                //   应允许自动推导（区分于 raw C 调用的 'null' 不可靠推导）。
                if (!self::isAutoInferableType($inferredType)) {
                    // 用户定义函数的明确返回类型 → 允许自动推导
                    $isUserFuncRet = ($node->expr instanceof CallExpr
                        && !$node->expr->isRawC
                        && $inferredType !== 'null');
                    if (!$isUserFuncRet) {
                        throw new \RuntimeException(
                            "Variable \${$var} requires explicit type declaration: "
                            . "inferred C pointer type '{$inferredType}'. "
                            . "Use 'C.Type \${$var} = ...' (e.g. C.void*, C.Point*, C.int*) to declare."
                        );
                    }
                }
                $cType = $inferredType;
            }
            $this->varTypes[$var] = $cType;
            $declType = ($cType === 'null') ? 'void*' : $cType;
            $w = $this->varWrite($var, $cType);
            // 追踪需要自动释放的局部变量（仅在函数/方法作用域内）
            if ($this->indent >= 1 && $cType === 't_string') {
                $this->symbols->addScopeString($var);
            } elseif ($this->indent >= 1 && $cType === 't_array*') {
                $this->symbols->addScopeArray($var);
            }
            // C 指针所有权追踪：记录 transfer 指针（需用户手动 defer/free）
            //   排除 borrow（c_str/c_int 等透传）和已托管（phpc_new_obj/phpc_auto）
            if ($this->isCTransferPtr($node->expr, $cType)) {
                $this->cPtrOwnership[$var] = [
                    'type' => $cType,
                    'cleaned' => false,
                    'line' => $node->line ?? 0,
                ];
            }
            if ($this->scopeDepth > 0) {
                $this->funcScopeDecls[$var] = $declType;
                $code = "{$w} = {$expr};";
            } else {
                $code = "{$declType} {$var} = {$expr};";
            }
        } else {
            // 自动释放：对象/t_string 重赋值时先求值再释放（防止 $var=$var->method() 的 use-after-free）
            $w = $this->varWrite($var, $prevType);
            if (self::isClassCType($prevType) || self::isEnumCType($prevType)) {
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                $code = "{$prevType} {$tmp} = {$expr}; tp_obj_release((void*){$var}); {$var} = {$tmp};";
            } elseif ($prevType === 't_string') {
                $code = "tphp_rt_str_free(&{$var}); {$w} = {$expr};";
            } else {
                $code = "{$w} = {$expr};";
            }
        }

        // 数组赋值 → 推导元素类型（支持对象/回调/嵌套数组）
        if ($node->expr instanceof ArrayLiteralExpr) {
            $elemType = $this->inferArrayElementType($node->expr);
            if (str_contains($elemType, 'tphp_class_') && !str_ends_with($elemType, '*')) $elemType .= '*';
            // 空数组字面量不设置 arrElementTypes（元素类型未知，避免误判为 t_int）
            // — 后续 $arr[$k] = val 用变量键赋值时，arrElementTypes 不会被错误地锁定为 t_int
            if (!empty($node->expr->entries)) {
                $this->arrElementTypes[$var] = $elemType;
            }
            // 保存数组字面量 AST，用于精确追踪嵌套访问中特定键的值类型
            $this->arrLiteralAST[$var] = $node->expr;
            // 若元素是数组，记录嵌套级别元素类型（含 t_int）
            if ($elemType === 't_array*') {
                $nested = $this->inferArrayDeepElementType($node->expr);
                $this->arrNestedTypes[$var] = $nested;
                // 记录多层嵌套深度和叶子类型（用于 $arr[0][1][2] 深层访问）
                $this->arrNestedDepth[$var] = $this->inferArrayNestedDepth($node->expr);
            }
            // 追踪字符串键的 per-key 值类型（用于 foreach string key 检测）
            foreach ($node->expr->entries as $entry) {
                if ($entry->key instanceof StringLiteralExpr) {
                    $valType = $this->inferType($entry->value);
                    if ($valType !== 'null') {
                        $this->arrValueTypes[$var] ??= [];
                        $this->arrValueTypes[$var][$entry->key->value] = $valType;
                    }
                }
            }
        } elseif ($node->expr instanceof NewExpr) {
            $this->arrElementTypes[$var] = self::classRefName($node->expr->className) . '*';
        }

        // 传播数组嵌套类型：$sub = $arr[0] 时，把 $arr 的 arrNestedTypes 传给 $sub
        if ($node->expr instanceof ArrayAccessExpr) {
            [$rootArr] = $this->resolveRootArray($node->expr);
            if ($rootArr !== '') {
                // 传播 arrElementTypes（子数组的元素类型来自于父数组的嵌套类型）
                if (isset($this->arrNestedTypes[$rootArr])) {
                    $this->arrElementTypes[$var] = $this->arrNestedTypes[$rootArr];
                }
            }
        }
        // $sub = $arr 时，传播 arrElementTypes 和 arrNestedTypes
        if ($node->expr instanceof VariableExpr) {
            $srcVar = self::varName($node->expr->name);
            if (isset($this->arrElementTypes[$srcVar])) {
                $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
            }
            if (isset($this->arrNestedTypes[$srcVar])) {
                $this->arrNestedTypes[$var] = $this->arrNestedTypes[$srcVar];
            }
        }

        // $x = array_keys/values/explode/merge(...) → 追踪返回数组的元素类型
        if ($node->expr instanceof CallExpr && $node->expr->callee === null) {
            $fnName = $node->expr->name;
            // 查元素类型注册表
            if (isset(self::$builtinArrElemTypes[$fnName])) {
                $et = self::$builtinArrElemTypes[$fnName];
                $this->arrElementTypes[$var] = $et;
                // 嵌套数组：查 "<fnName>[]" 获取内层元素类型
                //   与方法调用路径（resolveMethodCNameForElem）保持一致
                if ($et === 't_array*') {
                    $nestedKey = $fnName . '[]';
                    if (isset(self::$builtinArrElemTypes[$nestedKey])) {
                        $this->arrNestedTypes[$var] = self::$builtinArrElemTypes[$nestedKey];
                    }
                }
            }
            // 特殊处理：需要运行时分析的函数
            switch ($fnName) {
                case 'array_map':
                    // 元素类型 = callback 返回类型
                    $sig = $this->inferCallbackSig($node->expr->args[0] ?? null);
                    $this->arrElementTypes[$var] = $sig['ret'] ?? 't_int';
                    break;
                case 'array_filter':
                    // 元素类型 = 输入数组元素类型（从源数组变量推导）
                    if (isset($node->expr->args[0]) && $node->expr->args[0] instanceof VariableExpr) {
                        $srcVar = self::varName($node->expr->args[0]->name);
                        if (isset($this->arrElementTypes[$srcVar])) {
                            $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
                        }
                    }
                    break;
                case 'preg_match_all':
                    $this->arrElementTypes[$var] = 't_array*';
                    $this->arrNestedTypes[$var] = 't_string';
                    break;
            }
            // 传播用户函数返回数组的 per-key 类型（$var = func() 后 $var["key"] 类型推断）
            $fnCName = self::funcCNameFromCall($node->expr);
            if ($fnCName !== '' && isset($this->fnReturnArrKeyTypes[$fnCName])) {
                $this->arrValueTypes[$var] ??= [];
                foreach ($this->fnReturnArrKeyTypes[$fnCName] as $k => $t) {
                    $this->arrValueTypes[$var][$k] = $t;
                }
            }
        }

        // $var = $obj->method() → 追踪方法返回数组的元素类型
        //   查 $builtinArrElemTypes 注册表（键为方法 C 名 tphp_class_X_method）
        if ($node->expr instanceof CallExpr && $node->expr->callee !== null) {
            $methodCName = $this->resolveMethodCNameForElem($node->expr);
            if ($methodCName !== null && isset(self::$builtinArrElemTypes[$methodCName])) {
                $et = self::$builtinArrElemTypes[$methodCName];
                $this->arrElementTypes[$var] = $et;
                // 嵌套数组：查 "<methodCName>[]" 获取内层元素类型
                if ($et === 't_array*') {
                    $nestedKey = $methodCName . '[]';
                    if (isset(self::$builtinArrElemTypes[$nestedKey])) {
                        $this->arrNestedTypes[$var] = self::$builtinArrElemTypes[$nestedKey];
                    }
                }
            }
        }

        // 记录闭包变量名→函数名映射
        if ($node->expr instanceof ClosureExpr) {
            $closureName = "_closure_{$this->closureCounter}";
            $this->symbols->addVarClosure($var, $closureName);
        }

        return $code;
    }

    /**
     * 检查类型是否可自动推导（tphp 标准类型）。
     *
     * phpc C 指针类型（void*、结构体指针等）必须显式声明，
     * 因为 inferType 对 C->func() 返回的 void* 统一推导为 'null'，类型信息丢失，
     * 后续 cstruct 字段访问（$p->x）等操作无法正确展开。
     */
    private static function isAutoInferableType(string $type): bool
    {
        // tphp 标准标量/复合类型
        static $tphpTypes = ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_var', 't_callback'];
        if (in_array($type, $tphpTypes, true)) return true;
        // TinyPHP 类对象 / 枚举对象（含指针）
        //   全局: tphp_class_Foo* / tphp_enum_Color*
        //   命名空间: tphp_na_Ns_tphp_class_Foo* / tphp_na_Ns_tphp_enum_Color*
        if (self::isClassCType($type) || self::isEnumCType($type)) return true;
        // null (void*) / void* / char* / int* / Point* 等 C 指针 → 必须显式声明
        return false;
    }

    /**
     * 判断是否为 TinyPHP 类对象的 C 类型（含指针）
     *   全局: tphp_class_Foo / tphp_class_Foo*
     *   命名空间: tphp_na_Ns_tphp_class_Foo / tphp_na_Ns_tphp_class_Foo*
     */
    private static function isClassCType(string $type): bool
    {
        if (str_starts_with($type, 'tphp_class_')) return true;
        if (str_starts_with($type, 'tphp_na_') && str_contains($type, '_tphp_class_')) return true;
        return false;
    }

    /**
     * 判断是否为 TinyPHP 枚举对象的 C 类型（含指针）
     *   全局: tphp_enum_Color / tphp_enum_Color*
     *   命名空间: tphp_na_Ns_tphp_enum_Status / tphp_na_Ns_tphp_enum_Status*
     */
    private static function isEnumCType(string $type): bool
    {
        if (str_starts_with($type, 'tphp_enum_')) return true;
        if (str_starts_with($type, 'tphp_na_') && str_contains($type, '_tphp_enum_')) return true;
        return false;
    }

    /** 从 AST 表达式推导 C 类型 */
    private function inferType(ExprNode $expr): string
    {
        $class = get_class($expr);
        if (isset(self::$litTypeMap[$class])) {
            return self::$litTypeMap[$class];
        }
        if ($expr instanceof NullLiteralExpr) {
            return 'null';
        }
        if ($expr instanceof UnaryExpr) {
            if ($expr->operator === '!') return 't_bool';
            return $this->inferType($expr->expr);
        }
        if ($expr instanceof BinaryExpr) {
            if ($expr->operator === '.') return 't_string';
            if ($expr->operator === '<=>') return 't_int';
            if ($expr->operator === '**') return $this->inferType($expr->left);
            // 比较/逻辑运算符返回 bool
            if (in_array($expr->operator, ['<', '>', '<=', '>=', '==', '!=', '===', '!==', '&&', '||', 'instanceof'], true)) {
                return 't_bool';
            }
            // 位运算/算术：取左操作数类型（int/float 保持）
            return $this->inferType($expr->left);
        }
        if ($expr instanceof PostfixExpr) {
            return $this->inferType($expr->expr);
        }
        if ($expr instanceof CompoundAssignExpr) {
            return $this->inferType($expr->target);
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return 't_array*';
        }
        if ($expr instanceof ClosureExpr) {
            return 't_callback';
        }
        if ($expr instanceof YieldExpr || $expr instanceof YieldFromExpr) {
            return 't_var';
        }
        if ($expr instanceof VariableExpr) {
            // $this → 当前类指针类型（$this 不在 varTypes 中，需特殊处理）
            if ($expr->name === '$this') {
                return $this->className . '*';
            }
            $vn = self::varName($expr->name);
            $t = $this->varTypes[$vn] ?? 't_int';
            // byRef 变量：推导类型去掉一级指针（t_int*→t_int, t_array**→t_array*）
            if ($this->isByRefType($t)) return substr($t, 0, -1);
            return $t;
        }
        if ($expr instanceof EnumAccessExpr) {
            // case 访问 → enum 实例指针类型；常量访问 → 常量声明类型
            if ($this->symbols->hasEnumCase($expr->enumName, $expr->caseName)) {
                return $this->symbols->getEnumCType($expr->enumName) ?? 't_int';
            }
            $ct = $this->symbols->getEnumConstType($expr->enumName, $expr->caseName);
            return $ct ?? 't_int';
        }
        if ($expr instanceof PropertyAccessExpr) {
            // 静态属性访问: self::$prop / ClassName::$prop → 查 SymbolTable.getStaticPropType
            //   (property 名以 $ 开头标识静态属性，object 名无 $ 前缀标识类名/self)
            if ($expr->object instanceof VariableExpr
                && !str_starts_with($expr->object->name, '$')
                && str_starts_with($expr->property, '$')) {
                $rawName = $expr->object->name;
                $cn = ($rawName === 'self' || $rawName === 'static')
                    ? $this->className
                    : ($this->symbols->resolveClass($rawName) ?? $rawName);
                $propName = ltrim($expr->property, '$');
                $staticType = $this->symbols->getStaticPropType($cn, $propName);
                if ($staticType !== null) {
                    return $staticType;
                }
            }
            // C->CONST — C constant/enum/macro, default to t_int
            if ($expr->object instanceof VariableExpr && $expr->object->name === 'C') {
                return 't_int';
            }
            // #cstruct 字段类型推导
            if ($expr->object instanceof VariableExpr && str_starts_with($expr->object->name, '$')) {
                $vn = self::varName($expr->object->name);
                $objType = $this->varTypes[$vn] ?? '';
                $structName = rtrim($objType, '*');
                if (isset($this->cstructFields[$structName])) {
                    foreach ($this->cstructFields[$structName] as $f) {
                        if ($f['name'] === $expr->property) {
                            return $this->cstructFieldType($f['type']);
                        }
                    }
                }
            }
            $objKey = ($expr->object instanceof VariableExpr) ? self::varName($expr->object->name) : '';
            $objType = $this->varTypes[$objKey] ?? '';
            // $this->prop → 使用当前类名作为对象类型
            if ($objType === '' && $expr->object instanceof VariableExpr
                && $expr->object->name === '$this') {
                $objType = $this->className;
            }
            // 链式数组访问: $catalog[0][0]->prop — 用 inferType 推导对象类型
            if ($objType === '' && $expr->object instanceof ArrayAccessExpr) {
                $objType = rtrim($this->inferType($expr->object), '*');
            }
            // EnumName::CASE->value → 直接取 backing 类型
            if ($objType === '' && $expr->object instanceof EnumAccessExpr) {
                $objType = $this->symbols->getEnumCType($expr->object->enumName) ?? '';
            }
            // 枚举属性访问 → enum->value 返回 backing 类型, enum->name 返回 t_string
            if ($objType !== '' && self::isEnumCType($objType)) {
                if ($expr->property === 'name') return 't_string';
                if ($expr->property === 'value') {
                    $base = rtrim($objType, '*');
                    foreach ($this->symbols->allEnums() as $name => $ct) {
                        if (rtrim($ct, '*') === $base) {
                            return ($this->symbols->getEnumBacking($name)) === 'string' ? 't_string' : 't_int';
                        }
                    }
                    return 't_int';
                }
            }
            // 尝试从 SymbolTable 查找（沿父类链查找继承属性）
            if ($objType !== '' && $this->symbols->hasClass($objType)) {
                $propName = ltrim($expr->property, '$');
                $pt = $this->symbols->getClassPropType($objType, $propName);
                if ($pt !== null) return $pt;
                // 沿父类链查找继承属性
                //   修复前: 只查当前类，找不到返回 t_int，导致子类访问父类属性时
                //           inferType 返回 t_int，后续方法调用报 undefined method t_int::xxx
                $cur = $objType;
                while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
                    $cur = $this->symbols->getClassParent($cur);
                    $pt = $this->symbols->getClassPropType($cur, $propName);
                    if ($pt !== null) return $pt;
                }
                return 't_int';
            }
        }
        if ($expr instanceof ArrayAccessExpr) {
            // 优先：通过数组字面量 AST 精确追踪嵌套访问的叶子值类型
            // （处理混合类型关联数组：$m["items"][0]["id"] 中 "id" 是 int，"name" 是 string）
            if ($expr->array instanceof ArrayAccessExpr) {
                $traced = $this->traceNestedAccessType($expr);
                if ($traced !== null) return $traced;
            }
            // per-key 类型追踪（字符串字面量键）
            if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                $arrName = self::varName($expr->array->name);
                $keyStr  = $expr->index->value;
                if (isset($this->arrValueTypes[$arrName][$keyStr])) {
                    $et = $this->arrValueTypes[$arrName][$keyStr];
                    if (str_contains($et, 'tphp_class_') && !str_ends_with($et, '*')) $et .= '*';
                    return $et;
                }
                // 未知字符串键：全局查找是否在其他数组中有该键的类型信息
                foreach ($this->arrValueTypes as $vKeys) {
                    if (isset($vKeys[$keyStr])) return $vKeys[$keyStr];
                }
                // 先查数组元素类型，再默认 string
                return $this->arrElementTypes[$arrName] ?? 't_string';
            }
            // 先查数组变量的元素类型（支持对象/回调/数组）
            if ($expr->array instanceof VariableExpr) {
                $arrName = self::varName($expr->array->name);
                if (isset($this->arrElementTypes[$arrName])) {
                    $et = $this->arrElementTypes[$arrName];
                    if (str_contains($et, 'tphp_class_') && !str_ends_with($et, '*')) $et .= '*';
                    return $et;
                }
                // 注解常量数组：元素类型为 tphp_class_AnnotationEntry*
                if (isset($this->annotationRegistry[$arrName])) {
                    return 'tphp_class_AnnotationEntry*';
                }
            }
            // 实例属性数组访问：$this->prop[$key] 或 $obj->prop[$key]
            //   查 propArrElementTypes 注册表获取元素类型
            if ($expr->array instanceof PropertyAccessExpr) {
                $key = $this->propArrElemKey($expr->array);
                if ($key !== null && isset($this->propArrElementTypes[$key])) {
                    $et = $this->propArrElementTypes[$key];
                    if (str_contains($et, 'tphp_class_') && !str_ends_with($et, '*')) $et .= '*';
                    return $et;
                }
            }
            // 链式访问 $arr[0][0]：向上查找根数组的嵌套类型
            if ($expr->array instanceof ArrayAccessExpr) {
                [$rootArr, $depth] = $this->resolveRootArray($expr->array);
                if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                    // 多层嵌套：用 arrNestedDepth 判断当前深度是否到达叶子层
                    if (isset($this->arrNestedDepth[$rootArr])) {
                        $nd = $this->arrNestedDepth[$rootArr];
                        if ($depth >= $nd['depth'] - 1) {
                            return $nd['leafType'];
                        }
                        return 't_array*';  // 中间层仍是数组
                    }
                    return $this->arrNestedTypes[$rootArr];
                }
            }
            // 整数键默认 int
            return 't_int';
        }
        if ($expr instanceof NewExpr) {
            return self::classRefName($expr->className) . '*';
        }
        if ($expr instanceof CastExpr) {
            // C.XXX cast → 值类型映射为 PHP 类型，指针类型保留 C 类型
            if (str_starts_with($expr->castType, 'C.')) {
                $ct = substr($expr->castType, 2);
                // C 值类型 → 对应 PHP 类型（用于 varTypes 追踪和 castToStr 分发）
                if ($ct === 'int' || $ct === 'int32' || $ct === 'int64' || $ct === 'uint32' || $ct === 'uint64') return 't_int';
                if ($ct === 'float' || $ct === 'double') return 't_float';
                if ($ct === 'bool') return 't_bool';
                if ($ct === 'char') return 't_int';
                // 指针类型保留 C 类型 (void*, char*, int*, 结构体指针)
                return self::mapType($expr->castType);
            }
            return self::$typeMap[$expr->castType] ?? 't_int';
        }
        if ($expr instanceof CallExpr) {
            return $this->inferCallReturnType($expr);
        }
        if ($expr instanceof TernaryExpr) {
            return $this->inferType($expr->thenExpr);
        }
        if ($expr instanceof NullCoalesceExpr) {
            $lt = $this->inferType($expr->left);
            return ($lt === 'null') ? $this->inferType($expr->right) : $lt;
        }
        if ($expr instanceof MatchExpr) {
            // 返回第一个非 default arm 的 body 类型
            foreach ($expr->arms as $arm) {
                if (!empty($arm->values)) return $this->inferType($arm->body);
            }
            return 't_int';
        }
        if ($expr instanceof PipeExpr) {
            // pipe 表达式类型 = 右操作数（调用）的返回类型
            $right = $expr->right;
            if ($right instanceof CallExpr) {
                // 构造与 visitPipeExpr 相同的变换后 CallExpr 来推导返回类型
                $hasPlaceholder = false;
                foreach ($right->args as $arg) {
                    if ($arg instanceof PlaceholderExpr) { $hasPlaceholder = true; break; }
                }
                if ($hasPlaceholder) {
                    $newArgs = [];
                    foreach ($right->args as $arg) {
                        $newArgs[] = $arg instanceof PlaceholderExpr ? $expr->left : $arg;
                    }
                    return $this->inferCallReturnType(new CallExpr($right->callee, $right->name, $newArgs, $right->isNullsafe, $right->isRawC));
                }
                return $this->inferCallReturnType(new CallExpr($right->callee, $right->name, array_merge($right->args, [$expr->left]), $right->isNullsafe, $right->isRawC));
            }
            // callable 变量 → 查闭包签名
            if ($right instanceof VariableExpr) {
                $vn = self::varName($right->name);
                $fnName = $this->symbols->getVarClosure($vn) ?? '';
                if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                    return $this->symbols->getClosureSig($fnName)['ret'];
                }
            }
            return 't_int';
        }
        return 't_int'; // fallback
    }

    /** 推导 CallExpr 的返回类型 */
    private function inferCallReturnType(CallExpr $expr): string
    {
        // ── 注解常量静态索引 call() / newInstance() 返回类型推导 ──
        // AST: CallExpr { callee: ArrayAccessExpr { array: VariableExpr, index: IntLiteral }, name: 'call'|'newInstance' }
        if ($expr->callee instanceof ArrayAccessExpr
            && $expr->callee->array instanceof VariableExpr
            && !str_starts_with($expr->callee->array->name, '$')
            && $expr->callee->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$expr->callee->array->name])
            && ($expr->name === 'call' || $expr->name === 'newInstance')) {
            $reg = $this->annotationRegistry[$expr->callee->array->name];
            $idx = (int)$expr->callee->index->value;
            if (isset($reg['entries'][$idx])) {
                $entry = $reg['entries'][$idx];
                if ($expr->name === 'newInstance') {
                    return self::classRefName($entry['class']) . '*';
                }
                // call() → 方法/函数返回类型
                if ($entry['kind'] === 'function') {
                    $fnCName = $entry['namespace'] !== ''
                        ? 'tphp_na_' . self::mangleCName($entry['namespace']) . '_tphp_fn_' . $entry['function']
                        : 'tphp_fn_' . $entry['function'];
                    return $this->symbols->getFuncRet($fnCName) ?? 't_int';
                }
                $classCName = self::classRefName($entry['class']);
                $m = $this->symbols->getClassMethod($classCName, $entry['method']);
                return $m !== null ? $m->returnType : 't_int';
            }
        }

        // ── 注解常量动态索引 / foreach 变量 call() / newInstance() 返回类型推导 ──
        // $v->call() → void（运行时分发，返回类型不确定时用 void）
        // $v->newInstance() → 若只有一个 class entry，返回该类指针类型；否则 void*
        if ($expr->callee instanceof VariableExpr
            && str_starts_with($expr->callee->name, '$')
            && ($expr->name === 'call' || $expr->name === 'newInstance')) {
            $valVar = self::varName($expr->callee->name);
            if (isset($this->varAnnotSource[$valVar])) {
                $annotName = $this->varAnnotSource[$valVar];
                $reg = $this->annotationRegistry[$annotName];
                if ($expr->name === 'newInstance') {
                    // 收集所有 class entry
                    $classEntries = array_filter($reg['entries'], fn($e) => $e['kind'] === 'class');
                    if (count($classEntries) === 1) {
                        $entry = reset($classEntries);
                        return self::classRefName($entry['class']) . '*';
                    }
                    // 多个 class entry：检查共同基类
                    $commonBase = $this->findCommonBaseClass($classEntries);
                    if ($commonBase !== '') {
                        return self::classRefName($commonBase) . '*';
                    }
                    return 'void*';
                }
                // call() → 收集所有非 class entry 的返回类型
                $callEntries = array_filter($reg['entries'], fn($e) => $e['kind'] !== 'class');
                $retTypes = [];
                foreach ($callEntries as $e) {
                    if ($e['kind'] === 'function') {
                        $fnCName = $e['namespace'] !== ''
                            ? 'tphp_na_' . self::mangleCName($e['namespace']) . '_tphp_fn_' . $e['function']
                            : 'tphp_fn_' . $e['function'];
                        $retTypes[] = $this->symbols->getFuncRet($fnCName) ?? 't_int';
                    } else {
                        $classCName = self::classRefName($e['class']);
                        $m = $this->symbols->getClassMethod($classCName, $e['method']);
                        $retTypes[] = $m !== null ? $m->returnType : 't_int';
                    }
                }
                $retTypes = array_unique($retTypes);
                if (count($retTypes) === 1) {
                    return $retTypes[0];
                }
                return 'void';
            }
        }
        // 内置函数返回类型 — 查注册表
        if ($expr->callee === null) {
            $name = $expr->name;
            // 前缀规则：is_* / ctype_* → t_bool
            if (str_starts_with($name, 'is_')) return 't_bool';
            if (str_starts_with($name, 'ctype_')) return 't_bool';
            // 后缀规则：\phpc_thunk → null
            if (str_ends_with($name, '\\phpc_thunk')) return 'null';
            // array_reduce 返回类型 = callback 返回类型
            if ($name === 'array_reduce') {
                $sig = $this->inferCallbackSig($expr->args[1] ?? null);
                return $sig['ret'] ?? 't_int';
            }
            // abs(int|float) → 返回类型随参数类型
            if ($name === 'abs' && !empty($expr->args)) {
                return $this->inferType($expr->args[0]) === 't_float' ? 't_float' : 't_int';
            }
            // C-only 函数 → 查注册表
            if (isset(self::$builtinRetTypes[$name])) {
                return self::$builtinRetTypes[$name];
            }
            // 命名空间 fallback：NS\func() 若 NS 下未定义，剥掉前缀查全局内置函数
            // 符合 PHP 语义：命名空间下未定义的函数调用查全局
            if (($pos = strrpos($name, '\\')) !== false) {
                $nsFnCName = self::funcCNameFromCall($expr);
                if ($this->symbols->getFuncRet($nsFnCName) === null) {
                    $baseName = substr($name, $pos + 1);
                    if (str_starts_with($baseName, 'is_')) return 't_bool';
                    if (str_starts_with($baseName, 'ctype_')) return 't_bool';
                    if ($baseName === 'array_reduce') {
                        $sig = $this->inferCallbackSig($expr->args[1] ?? null);
                        return $sig['ret'] ?? 't_int';
                    }
                    if ($baseName === 'abs' && !empty($expr->args)) {
                        return $this->inferType($expr->args[0]) === 't_float' ? 't_float' : 't_int';
                    }
                    if (isset(self::$builtinRetTypes[$baseName])) {
                        return self::$builtinRetTypes[$baseName];
                    }
                }
            }
            // 用户定义的函数 → 查 SymbolTable
            $fnCName = self::funcCNameFromCall($expr);
            if ($fnCName && $this->symbols->getFuncRet($fnCName) !== null) {
                return $this->symbols->getFuncRet($fnCName);
            }
            // 未注册的 C-only 函数 → 编译错误（避免静默截断为 int）
            throw new \LogicException(
                "Unknown function return type: {$name}. " .
                "Please register it in \$builtinRetTypes."
            );
        }
        // 闭包调用 → 查 SymbolTable
        if ($expr->name === '__invoke' && $expr->callee instanceof VariableExpr) {
            $varName = self::varName($expr->callee->name);
            $fnName = $this->symbols->getVarClosure($varName) ?? '';
            if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                $sig = $this->symbols->getClosureSig($fnName);
                return $sig['ret'];
            }
        }
        // Raw C call → 分配/映射函数返回指针，用 void*；否则 t_int（值类型）
        if ($expr->isRawC) {
            $rcName = $expr->name;
            $ptrFns = ['map_ints','map_ints_ne','map_dbls','copy_ints','transform_ints',
                'point_create','str_dup','malloc','calloc'];
            if (in_array($rcName, $ptrFns, true)) return 'null';
            return 't_int';
        }
        // 方法调用 → 查 SymbolTable
        if ($expr->callee !== null) {
            $objKey = '';
            if ($expr->callee instanceof VariableExpr) {
                $objKey = self::varName($expr->callee->name);
                $objType = ($objKey === '$this' || $objKey === 'self')
                    ? $this->className
                    : ($objKey === 'parent'
                        ? (self::classRefName($this->lookupParentClass($this->phpClassName) ?? '') ?: $this->className)
                        : ($this->varTypes[$objKey] ?? ''));
                // 枚举静态调用 Color::cases() — callee=VariableExpr(Color)，varTypes 无此键
                //   但名称是已知枚举 → 用枚举 C 结构体名
                if ($objType === '' && $this->symbols->resolveEnumCName($expr->callee->name) !== null) {
                    $objType = $this->symbols->resolveEnumCName($expr->callee->name);
                }
                // 静态方法调用 Thread::yield() — callee=VariableExpr(Thread)，varTypes 无此键
                //   但名称是已知类 → 用 C 类名
                if ($objType === '') {
                    $resolved = $this->symbols->resolveClass($expr->callee->name);
                    if ($resolved !== null) $objType = $resolved;
                }
            } elseif ($expr->callee instanceof CallExpr) {
                // 链式调用：递归推导
                $objType = $this->inferCallChainClass($expr->callee);
            } elseif ($expr->callee instanceof EnumAccessExpr) {
                // Color::Red->method() → 实例方法
                $enumCName = $this->symbols->getEnumCName($expr->callee->enumName);
                if ($enumCName !== null) {
                    $mi = $this->symbols->getEnumMethodByCName($enumCName, $expr->name);
                    if ($mi !== null && $mi->retType !== 'void') return $mi->retType;
                }
                return 't_int';
            } else {
                return 't_int';
            }
            $objClean = rtrim($objType, '*');
            // 枚举静态方法调用 Color::cases() 等 → callee 是 VariableExpr(name=Color)
            //   此时 $objType 是枚举名或 C 结构体名，先查枚举方法
            $enumCName = $this->symbols->resolveEnumCName($objClean);
            if ($enumCName !== null) {
                $mi = $this->symbols->getEnumMethodByCName($enumCName, $expr->name);
                if ($mi !== null && $mi->retType !== 'void') return $mi->retType;
                return 't_int';
            }
            if ($objClean !== '') {
                $mInfo = $this->symbols->getClassMethod($objClean, $expr->name);
                if ($mInfo !== null) {
                    $retType = $mInfo->retType;
                    if ($retType === 'void') return 't_int'; return $retType;
                }
            }
            // Inherited method
            $parentCN = $this->resolveMethodClass($objClean, $expr->name);
            if ($parentCN !== '') {
                $mInfo = $this->symbols->getClassMethod($parentCN, $expr->name);
                if ($mInfo !== null) {
                    $retType = $mInfo->retType;
                    if ($retType !== 'void') return $retType;
                }
            }
        }
        // 原始 C 调用 → 可能返回指针，用 void* 安全存储
        if ($expr->isRawC) return 'null';
        // 方法调用未命中 → 默认 t_int（不在此抛错，方法返回类型由 getClassMethod 路径处理）
        return 't_int';
    }

    public function visitAssignPropStmt(AssignPropStmtNode $node): string
    {
        // Property Hook: set 拦截 — 不在 hook 体内时调用 setter
        if (!$this->inHookBody) {
            $pa = $node->target;
            $prop = ltrim($pa->property, '$');
            // 确定对象类名
            $objCN = '';
            if ($pa->object instanceof VariableExpr) {
                if ($pa->object->name === 'self' || ($pa->object->name === '$this')) {
                    $objCN = $this->className;
                } elseif (str_starts_with($pa->object->name, '$')) {
                    $objType = $this->varTypes[self::varName($pa->object->name)] ?? '';
                    $objCN = rtrim($objType, '*');
                }
            }
            // ── readonly 属性编译期检查 ──
            // PHP 8.2 语义: readonly 属性只能在声明它的类的 __construct 内赋值一次
            if ($objCN !== '' && !ctype_upper($prop[0] ?? '')) {
                if ($this->symbols->isPropReadonly($objCN, $prop)) {
                    $declCN = $this->symbols->getReadonlyPropDeclaringClass($objCN, $prop);
                    // 必须在声明该 readonly 属性的类的 __construct 内
                    if ($this->currentMethodName !== '__construct' || $this->className !== $declCN) {
                        $phpCls = $this->phpClassName;
                        throw new \RuntimeException(
                            "Cannot assign readonly property '{$phpCls}::\${$prop}' "
                            . "outside of its declaring class's __construct "
                            . "(readonly properties can only be initialized in the class that declares them)"
                        );
                    }
                    // 检查重复赋值
                    $key = "{$declCN}::{$prop}";
                    if (isset($this->assignedReadonlyProps[$key])) {
                        $phpCls = $this->phpClassName;
                        throw new \RuntimeException(
                            "Cannot reassign readonly property '{$phpCls}::\${$prop}' "
                            . "(readonly properties can only be initialized once)"
                        );
                    }
                    $this->assignedReadonlyProps[$key] = true;
                }
            }
            if ($objCN !== '' && !ctype_upper($prop[0] ?? '')) {
                $hookInfo = $this->resolveHookInfo($objCN, $prop);
                if ($hookInfo !== null && $hookInfo['set']) {
                    $val = $node->value->accept($this);
                    // 字符串类型需深拷贝
                    if ($hookInfo['type'] === 't_string') {
                        $val = "tphp_rt_str_dup({$val})";
                    }
                    $obj = $pa->object->accept($this);
                    return $hookInfo['cn'] . '_set_' . $prop . '(' . $obj . ', ' . $val . ');';
                }
            }
        }

        $target = $node->target->accept($this);
        $val = $node->value->accept($this);
        $propType = $this->getPropType($node->target);
        if ($propType === 't_string') {
            return "tphp_rt_str_free(&{$target}); {$target} = tphp_rt_str_dup({$val});";
        }
        return "{$target} = {$val};";
    }

    public function visitAssignArrayPushStmt(AssignArrayPushStmtNode $node): string
    {
        $vCode   = $node->value->accept($this);
        $val     = $this->wrapArrayElement($node->value, $vCode);

        // 目标可能是 $var 或 $obj->prop / $this->prop
        $target = $node->target;
        if ($target instanceof VariableExpr) {
            $var    = self::varName($target->name);
            $varT   = $this->varTypes[$var] ?? '';
            $isByRef = $this->isByRefType($varT);
            // byRef 数组：变量已是 t_array**，直接传；非 byRef：取地址
            $arrCode = $isByRef ? $var : ('&' . $var);

            // 元素类型追踪（$arr[] = value 总是 int key 自增追加）
            // 与 visitAssignArrayStmt 的 int key 路径一致：
            // 非 int/float/bool/null 的值类型需记录到 arrElementTypes，
            // 否则后续 $arr[0] 访问会用默认 get_int_int 截断指针。
            $elemType = $this->inferType($node->value);
            if ($elemType !== 'null' && $elemType !== 't_int' && $elemType !== 't_float' && $elemType !== 't_bool') {
                $this->arrElementTypes[$var] = $elemType;
                // 若赋的值是数组字面量，记录嵌套元素类型（供 $arr[0][i] 链式访问）
                if ($elemType === 't_array*' && $node->value instanceof ArrayLiteralExpr) {
                    $nested = $this->inferArrayDeepElementType($node->value);
                    $this->arrNestedTypes[$var] = $nested;
                }
            }
        } elseif ($target instanceof PropertyAccessExpr) {
            // $obj->prop[] = value 或 $this->prop[] = value
            // 生成为取属性地址：&($obj->prop)
            $arrCode = '&(' . $target->accept($this) . ')';
            // 追踪实例/静态属性数组元素类型
            $key = $this->propArrElemKey($target);
            if ($key !== null) {
                $elemType = $this->inferType($node->value);
                if ($elemType !== 'null' && $elemType !== 't_int' && $elemType !== 't_float' && $elemType !== 't_bool') {
                    $this->propArrElementTypes[$key] = $elemType;
                }
            }
        } else {
            // 其他目标（如数组元素 $arr[$i][]=）：回退到 accept 取地址
            $arrCode = '&(' . $target->accept($this) . ')';
        }

        return 'tphp_fn_array_push(' . $arrCode . ', ' . $val . ');';
    }

    public function visitAssignArrayStmt(AssignArrayStmtNode $node): string
    {
        $arr   = $node->target->array->accept($this);
        $idx   = $node->target->index->accept($this);
        $vCode = $node->value->accept($this);
        $val   = $this->wrapArrayElement($node->value, $vCode);

        // per-key 类型追踪：记录每个字符串键的值类型
        if ($node->target->index instanceof StringLiteralExpr && $node->target->array instanceof VariableExpr) {
            $arrName = self::varName($node->target->array->name);
            $valType = $this->inferType($node->value);
            if ($valType !== 'null') {
                $this->arrValueTypes[$arrName] ??= [];
                $this->arrValueTypes[$arrName][$node->target->index->value] = $valType;
            }
        }

        $idxType = $this->inferType($node->target->index);
        // 跟踪 int key 元素类型（仅非默认类型）
        if (($idxType !== 't_string' && !($node->target->index instanceof StringLiteralExpr)) && $node->target->array instanceof VariableExpr) {
            $arrName = self::varName($node->target->array->name);
            $elemType = $this->inferType($node->value);
            if ($elemType !== 'null' && $elemType !== 't_int' && $elemType !== 't_float' && $elemType !== 't_bool') {
                $this->arrElementTypes[$arrName] = $elemType;
                // 若赋的值是数组字面量，记录嵌套类型
                if ($elemType === 't_array*' && $node->value instanceof ArrayLiteralExpr) {
                    $nested = $this->inferArrayDeepElementType($node->value);
                    $this->arrNestedTypes[$arrName] = $nested;
                }
            }
        }
        // 跟踪实例属性数组元素类型：$this->prop[$key] = $val 或 $obj->prop[$key] = $val
        //   （arrElementTypes 仅追踪局部变量，需额外注册表覆盖属性访问）
        if ($idxType !== 't_string' && !($node->target->index instanceof StringLiteralExpr)
            && $node->target->array instanceof PropertyAccessExpr) {
            $key = $this->propArrElemKey($node->target->array);
            if ($key !== null) {
                $elemType = $this->inferType($node->value);
                if ($elemType !== 'null' && $elemType !== 't_int' && $elemType !== 't_float' && $elemType !== 't_bool') {
                    $this->propArrElementTypes[$key] = $elemType;
                }
            }
        }
        if ($idxType === 't_string' || $node->target->index instanceof StringLiteralExpr) {
            return "{$arr} = tphp_fn_arr_set_str({$arr}, {$idx}, {$val});";
        }
        return "{$arr} = tphp_fn_arr_set_int({$arr}, {$idx}, {$val});";
    }

    /**
     * 解析方法调用的 C 函数名（用于 $builtinArrElemTypes 查表）。
     * 复用 inferCallReturnType 中的类名推导逻辑，返回 "{cnClean}_{methodName}" 或 null。
     */
    private function resolveMethodCNameForElem(CallExpr $node): ?string
    {
        $calleeNode = $node->callee;
        if ($calleeNode === null) return null;
        $cn = '';
        if ($calleeNode instanceof VariableExpr) {
            $key = self::varName($calleeNode->name);
            if ($key === '$this' || $key === 'self') {
                $cn = $this->className;
            } elseif ($key === 'parent') {
                $parentPhp = $this->lookupParentClass($this->phpClassName);
                $cn = $parentPhp !== null ? self::classRefName($parentPhp) : $this->className;
            } else {
                $raw = $this->varTypes[$key] ?? '';
                if ($raw === '' && !str_starts_with($calleeNode->name, '$')) {
                    // 静态方法调用 ClassName::method() — callee 是 VariableExpr("ClassName")
                    // 当 ClassName 不在 varTypes 中时，将其视为 PHP 类名
                    $cn = $key;
                } else {
                    $cn = str_contains($raw, '\\') ? self::classRefName($raw) : $raw;
                }
            }
        } elseif ($calleeNode instanceof CallExpr) {
            $cn = $this->inferCallChainClass($calleeNode);
        } elseif ($calleeNode instanceof EnumAccessExpr) {
            $cn = $this->symbols->getEnumCName($calleeNode->enumName) ?? '';
        } else {
            return null;
        }
        $cnClean = rtrim($cn, '*');
        // 解析 PHP 类名 → C 类名
        if ($cnClean !== '' && !$this->symbols->hasClass($cnClean)
            && $this->symbols->resolveEnumCName($cnClean) === null) {
            $resolved = $this->symbols->resolveClass($cnClean);
            if ($resolved !== null) $cnClean = $resolved;
        }
        // 继承方法：查找父类定义
        if ($cnClean !== '' && $this->symbols->getClassMethod($cnClean, $node->name) === null) {
            $parentCN = $this->resolveMethodClass($cnClean, $node->name);
            if ($parentCN !== '') $cnClean = $parentCN;
        }
        return $cnClean !== '' ? "{$cnClean}_{$node->name}" : null;
    }

    /** 从数组字面量推导元素类型（取第一个非空元素的类型） */
    private function inferArrayElementType(ArrayLiteralExpr $expr): string
    {
        foreach ($expr->entries as $entry) {
            $val = $entry->value ?? $entry;
            if ($val === null) continue;
            // spread 元素: ...$arr → 取源数组的元素类型
            if ($entry->isSpread) {
                if ($val instanceof VariableExpr) {
                    $vn = self::varName($val->name);
                    $et = $this->arrElementTypes[$vn] ?? null;
                    if ($et !== null && $et !== 't_int') return $et;
                }
                $cType = $this->inferType($val);
                if ($cType === 't_array*') {
                    // 无法确定元素类型时，回退到 t_int（元素类型追踪不覆盖所有情况）
                    continue;
                }
                if ($cType !== 'null' && $cType !== 't_int') return $cType;
                continue;
            }
            $cType = $this->inferType($val);
            if ($cType !== 'null' && $cType !== 't_int') return $cType;
        }
        return 't_int';
    }

    /** 解析链式数组访问的根变量名 + 嵌套层数
     *  如 $arr[0][1] → ['arr', 1]（嵌套了1层 ArrayAccessExpr）
     *  如 $arr → ['arr', 0] */
    private function resolveRootArray(ExprNode $expr): array
    {
        $depth = 0;
        while ($expr instanceof ArrayAccessExpr) {
            $expr = $expr->array;
            $depth++;
        }
        if ($expr instanceof VariableExpr) {
            return [self::varName($expr->name), $depth];
        }
        return ['', $depth];
    }

    /** 从数组字面量推导深一层嵌套数组的元素类型 */
    private function inferArrayDeepElementType(ArrayLiteralExpr $expr): string
    {
        foreach ($expr->entries as $entry) {
            $val = $entry->value ?? $entry;
            // spread 嵌套数组: ...$arr（其中 $arr 元素本身是数组）→ 取源数组嵌套元素类型
            if ($entry->isSpread && $val instanceof VariableExpr) {
                $vn = self::varName($val->name);
                if (isset($this->arrNestedTypes[$vn])) return $this->arrNestedTypes[$vn];
            }
            if ($val instanceof ArrayLiteralExpr) {
                return $this->inferArrayElementType($val);
            }
        }
        return 't_int';
    }

    /** 从数组字面量推导嵌套数组的总深度和叶子元素类型
     *  返回 ['depth' => N, 'leafType' => 't_int']
     *  - depth=1: 元素本身是标量（如 [1,2,3]），leafType=t_int
     *  - depth=2: 元素是数组，子元素是标量（如 [[1,2],[3,4]]），leafType=t_int
     *  - depth=3: 元素是数组的数组，叶子是标量（如 [[[1,2,3]]]），leafType=t_int
     */
    private function inferArrayNestedDepth(ArrayLiteralExpr $expr): array
    {
        $depth = 1;
        $leafType = $this->inferArrayElementType($expr);
        $current = $expr;
        while ($leafType === 't_array*') {
            $foundNested = false;
            foreach ($current->entries as $entry) {
                $val = $entry->value ?? $entry;
                if ($val instanceof ArrayLiteralExpr) {
                    $depth++;
                    $leafType = $this->inferArrayElementType($val);
                    $current = $val;
                    $foundNested = true;
                    break;
                }
                // spread: ...$arr，取源数组的嵌套信息
                if ($entry->isSpread && $val instanceof VariableExpr) {
                    $vn = self::varName($val->name);
                    if (isset($this->arrNestedDepth[$vn])) {
                        $depth += $this->arrNestedDepth[$vn]['depth'];
                        $leafType = $this->arrNestedDepth[$vn]['leafType'];
                        $foundNested = true;
                    }
                    break;
                }
            }
            if (!$foundNested) break;
        }
        return ['depth' => $depth, 'leafType' => $leafType];
    }

    /** 通过数组字面量 AST 精确追踪嵌套访问的叶子值类型
     *  用于混合类型关联数组：$m["items"][0]["id"] 中 "id" 是 int，"name" 是 string
     *  inferArrayElementType 只能返回单一类型（首个非 int），无法区分 per-key 类型
     *
     *  返回 null 表示追踪失败（动态索引、变量源数组、非字面量等），调用方应回退到默认逻辑
     *  返回 't_array*' 表示中间层（非叶子）
     *  返回其他 CType 表示叶子值的具体类型 */
    private function traceNestedAccessType(ArrayAccessExpr $node): ?string
    {
        // 构建访问链（最外层在最后）
        $chain = [];
        $current = $node;
        while ($current instanceof ArrayAccessExpr) {
            $chain[] = $current;
            $current = $current->array;
        }
        if (!($current instanceof VariableExpr)) return null;
        $vn = self::varName($current->name);
        if (!isset($this->arrLiteralAST[$vn])) return null;

        $arrayExpr = $this->arrLiteralAST[$vn];
        $type = 't_array*';

        // 从最内层到最外层依次访问
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $access = $chain[$i];
            if (!$arrayExpr instanceof ArrayLiteralExpr) return null;
            $idx = $access->index;
            $found = null;
            if ($idx instanceof IntLiteralExpr) {
                $intIdx = (int)$idx->value;
                $i2 = 0;
                foreach ($arrayExpr->entries as $entry) {
                    if ($i2 === $intIdx) {
                        $found = $entry->value ?? $entry;
                        break;
                    }
                    $i2++;
                }
            } elseif ($idx instanceof StringLiteralExpr) {
                $keyStr = $idx->value;
                foreach ($arrayExpr->entries as $entry) {
                    $entryKey = $entry->key ?? null;
                    if ($entryKey instanceof StringLiteralExpr && $entryKey->value === $keyStr) {
                        $found = $entry->value ?? $entry;
                        break;
                    }
                }
            } else {
                return null;  // 动态索引，无法静态追踪
            }
            if ($found === null) return null;
            if ($found instanceof ArrayLiteralExpr) {
                $arrayExpr = $found;
                $type = 't_array*';
            } else {
                $type = $this->inferType($found);
                $arrayExpr = null;  // 已到达叶子
            }
        }
        return $type;
    }

    /** 检测 ArrayAccess 是否用字符串键 */
    private function hasStrKey(ArrayAccessExpr $expr): bool
    {
        if ($expr->index instanceof StringLiteralExpr) return true;
        return $this->inferType($expr->index) === 't_string';
    }

    /** #cstruct 字段的 C 类型 → PHP 类型映射（用于 inferType / getPropType）
     *   C.double/C.float → t_float, C.int/C.char → t_int, C.bool → t_bool
     *   C.char* → t_string, 其他指针类型 → 保留 C 类型 */
    private function cstructFieldType(string $cType): string
    {
        if (str_starts_with($cType, 'C.')) {
            $ct = substr($cType, 2);
            // 解析指针后缀
            $stars = '';
            while (str_ends_with($ct, '*')) {
                $stars .= '*';
                $ct = substr($ct, 0, -1);
            }
            // char* → t_string (C 字符串)
            if ($ct === 'char' && $stars !== '') return 't_string';
            if ($ct === 'int' || $ct === 'int32' || $ct === 'int64' || $ct === 'uint32' || $ct === 'uint64' || $ct === 'char') return 't_int';
            if ($ct === 'float' || $ct === 'double') return 't_float';
            if ($ct === 'bool') return 't_bool';
            // void*/结构体指针等 → 保留 C 类型
            return self::mapType($cType);
        }
        // 非 C. 前缀 = 嵌套结构体值类型 → 返回结构体指针类型
        return $cType . '*';
    }

    /** 获取属性类型（通过 SymbolTable 查找） */
    private function getPropType(PropertyAccessExpr $pa): string
    {        // C->CONST — C constant/enum/macro, default to t_int
        if ($pa->object instanceof VariableExpr && $pa->object->name === 'C') {
            return 't_int';
        }
        // #cstruct 字段类型查找：根据 #cstruct 声明的字段 C 类型映射为 PHP 类型
        if ($pa->object instanceof VariableExpr && str_starts_with($pa->object->name, '$')) {
            $vn = self::varName($pa->object->name);
            $objType = $this->varTypes[$vn] ?? '';
            $structName = rtrim($objType, '*');
            if (isset($this->cstructFields[$structName])) {
                foreach ($this->cstructFields[$structName] as $f) {
                    if ($f['name'] === $pa->property) {
                        return $this->cstructFieldType($f['type']);
                    }
                }
            }
        }
        $objKey = ($pa->object instanceof VariableExpr) ? self::varName($pa->object->name) : '';
        $objType = ($objKey === '$this' || $objKey === 'self')
            ? $this->className
            : ($this->varTypes[$objKey] ?? '');
        // 去掉尾部 *（指针类型）以匹配 SymbolTable key
        $objType = rtrim($objType, '*');
        // 静态属性访问: ClassName::$prop — 解析类名
        if ($objType === '' && $pa->object instanceof VariableExpr
            && !str_starts_with($pa->object->name, '$') && $pa->object->name !== 'self') {
            $resolved = $this->symbols->resolveClass($pa->object->name);
            if ($resolved !== null) $objType = $resolved;
        }
        // 链式数组访问: $catalog[0][0]->prop — 用 inferType 推导对象类型
        if ($objType === '' && $pa->object instanceof ArrayAccessExpr) {
            $inferred = $this->inferType($pa->object);
            $objType = rtrim($inferred, '*');
        }
        // EnumName::CASE->value → 直接取 backing 类型
        if ($objType === '' && $pa->object instanceof EnumAccessExpr) {
            $objType = rtrim($this->symbols->getEnumCType($pa->object->enumName) ?? '', '*');
        }
        // 枚举属性 → enum->value 返回 backing 类型, enum->name 返回 t_string
        if ($objType !== '' && self::isEnumCType($objType)) {
            if ($pa->property === 'name') return 't_string';
            if ($pa->property === 'value') {
                $base = rtrim($objType, '*');
                foreach ($this->symbols->allEnums() as $name => $ct) {
                    if (rtrim($ct, '*') === $base) {
                        return ($this->symbols->getEnumBacking($name)) === 'string' ? 't_string' : 't_int';
                    }
                }
                return 't_int';
            }
        }
        if ($objType !== '' && $this->symbols->hasClass($objType)) {
            $propName = ltrim($pa->property, '$');
            $pt = $this->symbols->getClassPropType($objType, $propName);
            if ($pt !== null) return $pt;
        }
        // Search parent chain for inherited properties
        $cur = $objType;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            $propName = ltrim($pa->property, '$');
            $pt = $this->symbols->getClassPropType($cur, $propName);
            if ($pt !== null) {
                return $pt;
            }
        }
        return '';
    }

    public function visitExprStmt(ExprStmtNode $node): string
    {
        return $node->expr->accept($this) . ';';
    }

    /** 空语句 — 条件编译指令占位，不生成任何 C 代码 */
    public function visitNopStmt(NopStmtNode $node): string
    {
        return '';
    }

    /**
     * 函数内 static 局部变量 → C 函数内 static 变量
     *   static int $n = 0;   → static t_int n = 0;
     *   static $n = 0;       → static t_int n = 0;  (类型从字面量推导)
     *   static string $s = "hi"; → static t_string s = STR_LIT("hi");
     *
     * 语义：首次调用时初始化，后续调用保持上次值（C static 语义完全匹配）
     * 注意：不加入 scopeStrings/scopeArrays — static 变量跨调用持久，不在作用域结束时释放
     */
    public function visitStaticStmt(StaticStmtNode $node): string
    {
        $var = self::varName($node->varName);
        // 确定类型：有声明用声明，无则从初始值推导
        if ($node->type !== null) {
            $cType = self::mapType($node->type);
        } elseif ($node->init !== null) {
            $cType = $this->inferType($node->init);
            if ($cType === 'null' || $cType === 'void*') {
                // null 初值 → 用 void* 占位（PHP 语义: static $x; 默认 null）
                $cType = 'void*';
            }
        } else {
            // static $var; 无初值无类型 → void* (null)
            $cType = 'void*';
        }
        // 注册到作用域变量追踪（后续引用需知道类型）
        $this->declaredVars[$var] = true;
        $this->varTypes[$var] = $cType;
        // 生成 C static 变量声明
        if ($node->init === null) {
            return "static {$cType} {$var} = null;";
        }
        $initCode = $node->init->accept($this);
        return "static {$cType} {$var} = {$initCode};";
    }

    /**
     * 函数内 const → C 函数内 static const 变量
     *   const int MAX = 100;      → static const t_int MAX = 100;
     *   const PI = 3.14;          → static const t_float PI = 3.14;  (类型从字面量推导)
     *   const string GREETING = "hi"; → static const t_string GREETING = STR_LIT("hi");
     *
     * 语义：编译期常量，C 编译器优化为立即数（零运行时开销）
     * 注意：常量名注册到 localConsts，visitVariable 据此区分局部 const 与全局 const
     */
    public function visitConstStmt(ConstStmtNode $node): string
    {
        $name = $node->name;
        // 确定类型：有声明用声明，无则从字面量推导
        $litCType = self::$litTypeMap[$node->value::class] ?? 't_int';
        if ($node->type !== null) {
            $declCType = self::mapType($node->type);
            if ($litCType !== null && $declCType !== $litCType) {
                throw new \RuntimeException(
                    "Constant {$name} type mismatch: "
                    . "declared '{$node->type}' ({$declCType}) but value is {$litCType}"
                );
            }
            $cType = $declCType;
        } else {
            $cType = $litCType ?? 't_int';
        }
        // 注册到局部常量集合（visitVariable 据此直接引用变量名而非 TPHP_CONST_）
        $this->localConsts[$name] = true;
        $this->declaredVars[$name] = true;
        $this->varTypes[$name] = $cType;
        // 生成 C static const 变量声明（字面量初始化）
        if ($node->value instanceof StringLiteralExpr) {
            $val = str_replace('"', '\\"', $node->value->value);
            return "static const {$cType} {$name} = STR_LIT(\"{$val}\");";
        }
        $valCode = $node->value->accept($this);
        return "static const {$cType} {$name} = {$valCode};";
    }

    public function visitBlockStmt(BlockStmtNode $node): string
    {
        $code = '';
        foreach ($node->stmts as $stmt) {
            $code .= $stmt->accept($this);
        }
        return $code;
    }

    /**
     * defer 语句：注册清理代码，编译期展开到所有 return 点和 fall-through 尾部（LIFO）。
     *   defer EXPR;  /  defer { body }
     * 生成的清理代码压入 $deferStack，不在当前位置输出。
     * visitReturnStmt 和 visitMethod/visitFunction 尾部调用 generateDeferCleanup() 输出。
     */
    public function visitDeferStmt(DeferStmtNode $node): string
    {
        // 生成 defer body 的 C 代码（每条语句一行，带缩进）
        // 注意：visit 方法返回的代码已含分号，不再追加
        $lines = [];
        foreach ($node->body as $s) {
            $lines[] = $this->ind($s->accept($this));
        }
        $this->deferStack[] = implode("\n", $lines);
        // defer 语句本身在当前位置不生成任何代码（清理代码已延迟到 return/fall-through）
        return '';
    }

    /**
     * 生成所有已注册 defer 的清理代码（LIFO 逆序）。
     * 在 return 语句前和函数 fall-through 尾部调用。
     */
    private function generateDeferCleanup(): array
    {
        if (empty($this->deferStack)) return [];
        // LIFO：后注册的先执行
        $lines = [];
        for ($i = count($this->deferStack) - 1; $i >= 0; $i--) {
            $lines[] = $this->deferStack[$i];
        }
        return $lines;
    }

    /**
     * 判断表达式是否返回 transfer 所有权指针（需用户手动 defer/free）。
     *   - C->func() 返回 T*：默认 transfer（保守，可能泄漏）
     *   - phpc_arr_int/phpc_arr_dbl/phpc_arr_str：transfer（malloc 返回）
     *   - c_str/c_int/c_void_ptr/php_str/php_int：borrow/值类型（不追踪）
     *   - phpc_new_obj/phpc_auto：已托管（不需要 defer）
     */
    private function isCTransferPtr(ExprNode $expr, string $cType): bool
    {
        // 非指针类型不追踪
        if (!str_contains($cType, '*')) return false;
        // 排除 tphp 管理的类型（t_string/t_array*/tphp_class_*/tphp_enum_*）
        if (str_contains($cType, 'tphp_') || $cType === 't_string' || $cType === 't_array*') return false;

        // 借用函数（不追踪）— 透传指针，不转移所有权
        static $borrowFns = ['c_str', 'c_int', 'c_void_ptr', 'phpc_obj',
            'phpc_int_to_ptr',  // t_int → void*，仅还原指针值，不转移所有权
        ];
        // 已托管函数（不需要 defer，内部 tphp_rt_register 自动释放）
        //   phpc_arr_int/dbl: malloc + tphp_rt_register (见 phpc.h)
        //   phpc_new_obj: 对象包装 + register
        //   phpc_auto: 显式注册自动释放
        static $managedFns = ['phpc_new_obj', 'phpc_auto', 'phpc_arr_int', 'phpc_arr_dbl'];

        if ($expr instanceof CallExpr) {
            // CallExpr::$name 始终为 string（见 AST\Node.php CallExpr 定义）
            $name = $expr->name;
            if (in_array($name, $borrowFns, true)) return false;
            if (in_array($name, $managedFns, true)) return false;
            // phpc_arr_str: 不自动注册（需手动 phpc_free_str_arr），是 transfer
            // C->func() 返回 T*：transfer
            if ($expr->isRawC || $name === 'phpc_arr_str') return true;
            // 用户定义函数返回 C.T*：transfer（用户需 defer 或在函数内 free）
            return true;
        }
        return false;
    }

    /**
     * 标记 C 指针变量已被清理（phpc_free/return/php_str 接管等）。
     */
    private function markCPtrCleaned(string $varName): void
    {
        if (isset($this->cPtrOwnership[$varName])) {
            $this->cPtrOwnership[$varName]['cleaned'] = true;
        }
    }

    /**
     * 扫描未清理的 C 指针变量，输出编译期泄漏警告。
     * 在函数/方法体生成完成后调用。
     */
    private function warnLeakedCPtrs(string $funcName): void
    {
        foreach ($this->cPtrOwnership as $var => $info) {
            if (!$info['cleaned']) {
                $baseVar = ltrim($var, '$');
                $line = $info['line'] > 0 ? " at line {$info['line']}" : '';
                fprintf(STDERR, "[WARN] %s: C pointer \${$baseVar} (type: {$info['type']}){$line} "
                    . "may leak — consider adding 'defer C->free(\${$baseVar});' or calling phpc_free(\${$baseVar})\n",
                    $funcName);
            }
        }
    }

    // ============================================================
    public function visitStringLiteral(StringLiteralExpr $node): string
    {
        // Escape chars invalid in C string literals
        $val = $node->value;
        $val = str_replace('"', '\\"', $val);     // "  → \"
        $val = str_replace("\n", '\\n', $val);    // LF → \n
        $val = str_replace("\r", '\\r', $val);    // CR → \r
        $val = str_replace("\t", '\\t', $val);    // TAB → \t
        // Escape backslashes not part of recognized C escape sequences
        // (e.g. \w, \d, \s in regex patterns → \\w, \\d, \\s to survive C compilation)
        $result = '';
        $len = strlen($val);
        for ($i = 0; $i < $len; $i++) {
            if ($val[$i] === '\\' && $i + 1 < $len) {
                $next = $val[$i + 1];
                if (strpos('"\\ntrabefvx?\'01234567', $next) !== false) {
                    $result .= '\\' . $next;
                } else {
                    $result .= '\\\\' . $next;
                }
                $i++;
            } elseif ($val[$i] === '\\') {
                $result .= '\\\\';
            } else {
                $result .= $val[$i];
            }
        }
        return "STR_LIT(\"{$result}\")";
    }

    public function visitIntLiteral(IntLiteralExpr $node): string    { return (string)$node->value; }
    public function visitFloatLiteral(FloatLiteralExpr $node): string {
        // 确保 C 侧保留浮点语义：整数部分后面加 .0
        $val = $node->value;
        return ($val == (float)(int)$val) ? sprintf('%.1f', $val) : rtrim(rtrim(sprintf('%.15g', $val), '0'), '.');
    }
    public function visitBoolLiteral(BoolLiteralExpr $node): string   { return $node->value ? 'true' : 'false'; }
    public function visitNullLiteral(NullLiteralExpr $node): string   { return 'null'; }

    public function visitMagicConst(MagicConstExpr $node): string
    {
        $this->varTypes['__magic_tmp__'] = 't_string';
        if ($node->name === '__LINE__') return 'tphp_rt_str_from_int(' . $node->line . ')';
        if ($node->name === '__FILE__') return 'tphp_rt_str_dup((t_string){.data="' . str_replace('\\', '\\\\', $this->phpFile) . '", .length=' . strlen($this->phpFile) . ', .is_lit=true})';
        if ($node->name === '__DIR__')  return 'tphp_rt_str_dup((t_string){.data="' . str_replace('\\', '\\\\', dirname($this->phpFile)) . '", .length=' . strlen(dirname($this->phpFile)) . ', .is_lit=true})';
        if ($node->name === 'DIRECTORY_SEPARATOR') return PHP_OS_FAMILY === 'Windows' ? 'STR_LIT("\\\\")' : 'STR_LIT("/")';
        if ($node->name === '__CLASS__')  return 'STR_LIT("' . str_replace('\\', '\\\\', $this->phpClassName) . '")';
        if ($node->name === '__METHOD__') {
            // 方法内: Class::method；全局函数内: 函数名
            if ($this->inMethod) return 'STR_LIT("' . str_replace('\\', '\\\\', $this->phpClassName) . '::' . ($this->currentMethodName ?? '') . '")';
            return 'STR_LIT("' . $this->currentFuncName . '")';
        }
        if ($node->name === '__FUNCTION__') {
            // 方法内: 仅方法名；全局函数内: 函数名
            return 'STR_LIT("' . ($this->inMethod ? ($this->currentMethodName ?? '') : $this->currentFuncName) . '")';
        }
        if ($node->name === '__NAMESPACE__') {
            $ns = $this->currentNamespace;
            $escaped = str_replace('\\', '\\\\', $ns);
            return 'STR_LIT("' . $escaped . '")';
        }
        return 'STR_LIT("")';
    }

    public function visitArrayLiteral(ArrayLiteralExpr $node): string
    {
        // 生成复合语句表达式，创建 t_array* 并填充
        $tmpName = "_arr_" . (++$this->tmpVarCounter);
        return "({ " . $this->genArrayLiteralInline($node, $tmpName) . " " . $tmpName . "; })";
    }

    /** 生成数组字面量的声明+填充代码（不含外层 ({})） */
    private function genArrayLiteralInline(ArrayLiteralExpr $node, string $varName): string
    {
        $count = count($node->entries);
        $parts = [];
        // 预分配容量（至少 4，避免大数组逐次 realloc）
        $cap = max(4, $count);
        $parts[] = "t_array* {$varName} = tphp_fn_arr_create({$cap}); tphp_rt_register((void*){$varName}, 1);";
        $parts[] = "if ({$varName} != NULL) {";
        foreach ($node->entries as $entry) {
            // spread 元素: ...$arr → 调用 tphp_fn_arr_spread 展开源数组
            if ($entry->isSpread) {
                $srcCode = $entry->value->accept($this);
                $parts[] = "{$varName} = tphp_fn_arr_spread({$varName}, {$srcCode});";
                continue;
            }
            $valCode = $entry->value->accept($this);
            $wrap = $this->wrapArrayElement($entry->value, $valCode);

            if ($entry->key !== null) {
                $keyExpr = $entry->key;
                if ($keyExpr instanceof StringLiteralExpr) {
                    $kc = $keyExpr->accept($this);
                    $parts[] = "{$varName} = tphp_fn_arr_set_str({$varName}, {$kc}, {$wrap});";
                } else {
                    $kc = $keyExpr->accept($this);
                    $parts[] = "{$varName} = tphp_fn_arr_set_int({$varName}, {$kc}, {$wrap});";
                }
            } else {
                $parts[] = "{$varName} = tphp_fn_arr_push({$varName}, {$wrap});";
            }
        }
        $parts[] = '}';
        return implode(' ', $parts);
    }

    /** 将数组元素值包装为 t_var 宏 */
    private function wrapArrayElement(ExprNode $el, string $code): string
    {
        if ($el instanceof StringLiteralExpr)  return "VAR_STRING({$code})";
        if ($el instanceof IntLiteralExpr)     return "VAR_INT({$code})";
        if ($el instanceof FloatLiteralExpr)   return "VAR_FLOAT({$code})";
        if ($el instanceof BoolLiteralExpr)    return "VAR_BOOL({$code})";
        if ($el instanceof NullLiteralExpr)    return "VAR_NULL()";
        if ($el instanceof ArrayLiteralExpr)   return "VAR_ARRAY({$code})";
        if ($el instanceof ClosureExpr)        return "VAR_CALLBACK({$code})";
        if ($el instanceof VariableExpr) {
            $vn = self::varName($el->name);
            // 常量引用（不以 $ 开头）→ 加 TPHP_CONST_ 前缀
            $isConst = !str_starts_with($el->name, '$');
            $ref = $isConst ? ('TPHP_CONST_' . strtoupper($vn)) : $vn;
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_int' => "VAR_INT({$ref})", 't_float' => "VAR_FLOAT({$ref})",
                't_string' => "VAR_STRING({$ref})", 't_bool' => "VAR_BOOL({$ref})",
                't_array*' => "VAR_ARRAY({$ref})", 't_callback' => "VAR_CALLBACK({$ref})",
                default => (str_contains($vt, 'tphp_class_') || str_contains($vt, 'tphp_enum_'))
                    ? "VAR_OBJ({$ref})" : "VAR_NULL()",
            };
        }
        if ($el instanceof NewExpr) return "VAR_OBJ({$code})";
        // 复杂表达式：用 inferType 动态推导类型
        $type = $this->inferType($el);
        return match ($type) {
            't_string'   => "VAR_STRING({$code})",
            't_float'    => "VAR_FLOAT({$code})",
            't_bool'     => "VAR_BOOL({$code})",
            't_array*'   => "VAR_ARRAY({$code})",
            't_callback' => "VAR_CALLBACK({$code})",
            't_var'      => $code,
            'null'       => "VAR_NULL()",
            default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                ? "VAR_OBJ({$code})"
                : "VAR_INT({$code})",
        };
    }

    public function visitClosure(ClosureExpr $node): string
    {
        if ($node->isGenerator) {
            return $this->emitGeneratorClosure($node);
        }
        $id = ++$this->closureCounter;
        $name  = "_closure_{$id}";
        $capName = "_cap_{$id}";
        $hasCapture = !empty($node->useVars);
        $ret = self::mapType($node->returnType);

        // 查询捕获变量的类型（外层作用域）
        $capFields = [];
        $capInits  = [];
        $capDecls  = [];
        $capAssigns = []; // heap allocation assignments: _env_N->var = var;
        foreach ($node->useVars as [$vn, $_]) {
            $ct = $this->varTypes[$vn] ?? 't_int';
            // null 类型 → void*，t_var 保持原样，对象类型加 *
            if ($ct === 'null') {
                $ct = 'void*';
            } elseif (str_contains($ct, 'tphp_class_') && !str_ends_with($ct, '*')) {
                $ct .= '*';
            }
            $capFields[]  = "    {$ct} {$vn};";
            $capInits[]   = "    .{$vn} = {$vn}";
            $capDecls[]   = "    {$ct} {$vn} = _e->{$vn};";
            $capAssigns[] = "    _env_{$id}->{$vn} = {$vn};";
        }

        $paramDecls = array_map(fn($p) => $this->visitParam($p), $node->params);
        $paramDecls[] = "void* _env";  // 统一签名，无捕获时 env=NULL
        $paramStr = implode(', ', $paramDecls);

        // 构建闭包函数 C 实现
        $savedDeclared = $this->declaredVars;
        $savedObjs = $this->symbols->scopeObjects();
        $savedTypes    = $this->varTypes;
        $savedIndent   = $this->indent;
        $savedRetType  = $this->currentRetType;
        $savedPhpRetType = $this->currentPhpRetType;
        $savedLocalConsts = $this->localConsts;
        $savedFuncScopeDecls = $this->funcScopeDecls;

        $this->declaredVars = [];
        $this->symbols->clearScopeObjects();
        $this->varTypes     = [];
        $this->localConsts  = [];
        $this->funcScopeDecls = [];
        $this->indent       = 0;
        $this->currentRetType = $ret;
        $this->currentPhpRetType = $node->returnType;
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = self::mapType($p->type);
        }

        $implLines = [];
        $implLines[] = "{$ret} {$name}({$paramStr}) {";
        if ($hasCapture) {
            // 从 void* env 转回捕获 struct，声明局部引用
            $implLines[] = "    {$capName}* _e = ({$capName}*)_env;";
            foreach ($capDecls as $d) { $implLines[] = $d; }
            foreach ($node->useVars as [$vn, $_]) {
                $this->declaredVars[$vn] = true;
                $ct = $savedTypes[$vn] ?? 't_int';
                $this->varTypes[$vn] = ($ct === 'null') ? 'void*' : $ct;
            }
        } else {
            $implLines[] = '    (void)_env;';
        }
        // Phase: body (侧作用: 填充 funcScopeDecls)
        $bodyLines = [];
        if (empty($node->body)) {
            foreach ($node->params as $p) {
                $bodyLines[] = '    (void)' . self::varName($p->name) . ';';
            }
        } else {
            foreach ($node->body as $s) {
                $bodyLines[] = '    ' . $s->accept($this);
            }
        }
        // for 循环提升声明（闭包内 for (int $i = ...) 需要声明 i）
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $implLines[] = "    {$ct} {$vn} = {0};";
        }
        $implLines = array_merge($implLines, $bodyLines);
        foreach ($this->symbols->scopeObjects() as $ov) {
            $implLines[] = '    ' . "tp_obj_release({$ov});";
        }
        $implLines[] = '}';

        $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $implLines));

        // 记录闭包签名：用于 generateClosureCall 生成正确的函数指针转换
        $sig = [
            'ret'    => $ret,
            'params' => implode(', ', array_map(fn($p) => self::mapType($p->type), $node->params)),
        ];
        $this->symbols->addClosureSig($name, $sig);

        // 恢复外层作用域
        $this->declaredVars = $savedDeclared;
        $this->symbols->clearScopeObjects(); foreach($savedObjs as $so) $this->symbols->addScopeObject($so);
        $this->varTypes     = $savedTypes;
        $this->indent       = $savedIndent;
        $this->currentRetType = $savedRetType;
        $this->currentPhpRetType = $savedPhpRetType;
        $this->localConsts  = $savedLocalConsts;
        $this->funcScopeDecls = $savedFuncScopeDecls;

        // 注册捕获 struct 定义（后处理时插入文件顶部）
        if ($hasCapture) {
            $capDef = "typedef struct {\n" . implode("\n", $capFields) . "\n} {$capName};";
            $this->sectionBlock(self::SEC_CAPTYPES, $capDef);
        }

        // 生成 GNU 复合表达式
        $fwdParams = implode(', ', array_map(fn($p) => $this->visitParam($p), $node->params));
        $fwdParams = ($fwdParams ? $fwdParams . ', ' : '') . "void* _env";
        $envDecl = $hasCapture
            ? "    {$capName}* _env_{$id} = ({$capName}*)calloc(1, sizeof({$capName}));\n"
              . "    if (_env_{$id} != NULL) {\n"
              . implode("\n", $capAssigns) . "\n"
              . "    }\n"
              . "    tphp_rt_register((void*)_env_{$id}, 3);\n"
              . "    (t_callback){ .func = (void*){$name}, .env = _env_{$id} };"
            : "    (t_callback){ .func = (void*){$name}, .env = NULL };";


        return "({ {$ret} {$name}({$fwdParams});\n{$envDecl}\n  })";
    }

    /**
     * 生成器闭包变换：use vars + params 打包进 generator params struct。
     *
     *   1) 协程入口 static void tphp_gen__closure_N_entry(mco_coro* co) { 闭包体 }
     *   2) 包装函数   tphp_class_Generator* _closure_N(params, void* _env) { 创建协程 }
     *
     * 返回 t_callback{.func=_closure_N, .env=_cap_N_ptr}（与普通闭包一致的调用接口）。
     */
    private function emitGeneratorClosure(ClosureExpr $node): string
    {
        $id = ++$this->closureCounter;
        $name  = "_closure_{$id}";
        $capName = "_cap_{$id}";
        $genStruct = "_gen_params_{$name}";
        $entryName = "tphp_gen_{$name}_entry";
        $hasCapture = !empty($node->useVars);

        // 查询捕获变量的类型（外层作用域）
        $capFields = [];
        $capInits  = [];
        $capDecls  = [];
        $capAssigns = [];
        $capTypes = [];  // 保存捕获变量 C 类型（用于 genStruct 字段）
        foreach ($node->useVars as [$vn, $_]) {
            $ct = $this->varTypes[$vn] ?? 't_int';
            if ($ct === 'null') {
                $ct = 'void*';
            } elseif (str_contains($ct, 'tphp_class_') && !str_ends_with($ct, '*')) {
                $ct .= '*';
            }
            $capTypes[$vn] = $ct;
            $capFields[]  = "    {$ct} {$vn};";
            $capInits[]   = "    .{$vn} = {$vn}";
            $capDecls[]   = "    {$ct} {$vn} = _e->{$vn};";
            $capAssigns[] = "    _env_{$id}->{$vn} = {$vn};";
        }

        // 保存外层状态
        $savedDeclared = $this->declaredVars;
        $savedObjs = $this->symbols->scopeObjects();
        $savedTypes    = $this->varTypes;
        $savedIndent   = $this->indent;
        $savedRetType  = $this->currentRetType;
        $savedPhpRetType = $this->currentPhpRetType;
        $savedInGenerator = $this->inGenerator;
        $savedLocalConsts = $this->localConsts;

        // 重置作用域
        $this->declaredVars = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->varTypes     = [];
        $this->localConsts  = [];
        $this->indent       = 0;
        $this->currentRetType = 't_var';
        $this->currentPhpRetType = $node->returnType;
        $this->inGenerator = true;
        $this->funcScopeDecls = [];

        // 注册参数到局部变量表
        $paramVars = [];
        $paramFields = [];   // genStruct 的参数字段
        $paramLocalDecls = []; // entry 函数的局部声明
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $ct = self::mapType($p->type);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $ct;
            $paramVars[$vn] = true;
            $paramFields[] = "    {$ct} {$vn};";
            $paramLocalDecls[] = "    {$ct} {$vn};";
        }
        // 注册捕获变量到局部变量表
        if ($hasCapture) {
            foreach ($node->useVars as [$vn, $_]) {
                $this->declaredVars[$vn] = true;
                $this->varTypes[$vn] = $capTypes[$vn];
                $paramVars[$vn] = true;
            }
        }

        // 解包：从 user_data 复制到局部变量
        $unpackLines = [];
        $unpackLines[] = "    {$genStruct}* _p = ({$genStruct}*)mco_get_user_data(co);";
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $unpackLines[] = "    {$vn} = _p->{$vn};";
        }
        if ($hasCapture) {
            foreach ($node->useVars as [$vn, $_]) {
                $unpackLines[] = "    {$vn} = _p->{$vn};";
            }
        }
        $unpackLines[] = '    free(_p);';
        $unpackLines[] = '    int _auto_key = 0;';

        // 生成函数体
        $bodyLines = [];
        if (empty($node->body)) {
            foreach ($node->params as $p) {
                $bodyLines[] = '    (void)' . self::varName($p->name) . ';';
            }
        } else {
            foreach ($node->body as $s) {
                $bodyLines[] = '    ' . $s->accept($this);
            }
        }

        // for 循环提升声明
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = "    {$ct} {$vn} = {0};";
        }

        // 末尾释放
        $tailLines = [];
        foreach ($this->generateScopeCleanup($paramVars) as $l) {
            $tailLines[] = '    ' . $l;
        }
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tailLines[] = '    ' . "tp_obj_release({$ov});";
        }

        // 恢复外层作用域
        $this->declaredVars = $savedDeclared;
        $this->symbols->clearScopeObjects(); foreach($savedObjs as $so) $this->symbols->addScopeObject($so);
        $this->varTypes     = $savedTypes;
        $this->indent       = $savedIndent;
        $this->currentRetType = $savedRetType;
        $this->currentPhpRetType = $savedPhpRetType;
        $this->localConsts  = $savedLocalConsts;
        $this->inGenerator = $savedInGenerator;

        // capture struct 定义 → SEC_CAPTYPES
        if ($hasCapture) {
            $capDef = "typedef struct {\n" . implode("\n", $capFields) . "\n} {$capName};";
            $this->sectionBlock(self::SEC_CAPTYPES, $capDef);
        }

        // generator params struct 定义 → SEC_CLOSURES（在类结构体定义之后）
        $allFields = array_merge($paramFields, $hasCapture ? $capFields : []);
        $genTypeDef = "typedef struct {\n" . implode("\n", $allFields) . "\n} {$genStruct};";
        $this->sectionLine(self::SEC_CLOSURES, $genTypeDef);

        // 协程入口函数 → SEC_CLOSURES
        $entryLines = array_merge(
            ["static void {$entryName}(mco_coro* co) {"],
            $paramLocalDecls,
            $hasCapture ? array_map(fn($f) => '    ' . ltrim($f), $capFields) : [],
            $unpackLines,
            $declLines,
            $bodyLines,
            $tailLines,
            ["}"]
        );
        $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $entryLines));

        // 包装函数（闭包 func）→ SEC_CLOSURES
        $paramDecls = array_map(fn($p) => $this->visitParam($p), $node->params);
        $paramDecls[] = "void* _env";
        $paramStr = implode(', ', $paramDecls);
        $packAssigns = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $packAssigns[] = "    _p->{$vn} = {$vn};";
        }
        $unpackEnv = [];
        if ($hasCapture) {
            $unpackEnv[] = "    {$capName}* _e = ({$capName}*)_env;";
            foreach ($node->useVars as [$vn, $_]) {
                $packAssigns[] = "    _p->{$vn} = _e->{$vn};";
            }
        }
        $wrapperLines = array_merge(
            ["tphp_class_Generator* {$name}({$paramStr}) {"],
            $hasCapture ? $unpackEnv : ["    (void)_env;"],
            ["    {$genStruct}* _p = ({$genStruct}*)calloc(1, sizeof({$genStruct}));"],
            ["    if (_p == NULL) return NULL;"],
            $packAssigns,
            ["    mco_desc desc = mco_desc_init({$entryName}, 0);"],
            ["    desc.user_data = _p;"],
            ["    mco_coro* co;"],
            ["    if (mco_create(&co, &desc) != MCO_SUCCESS) { free(_p); return NULL; }"],
            ["    return new_tphp_class_Generator(co);"],
            ["}"]
        );
        $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $wrapperLines));

        // 记录闭包签名（返回类型为 Generator*）
        $sig = [
            'ret'    => 'tphp_class_Generator*',
            'params' => implode(', ', array_map(fn($p) => self::mapType($p->type), $node->params)),
        ];
        $this->symbols->addClosureSig($name, $sig);

        // 生成 GNU 复合表达式（与普通闭包一致：创建 env 并返回 t_callback）
        $fwdParams = implode(', ', array_map(fn($p) => $this->visitParam($p), $node->params));
        $fwdParams = ($fwdParams ? $fwdParams . ', ' : '') . "void* _env";
        $envDecl = $hasCapture
            ? "    {$capName}* _env_{$id} = ({$capName}*)calloc(1, sizeof({$capName}));\n"
              . "    if (_env_{$id} != NULL) {\n"
              . implode("\n", $capAssigns) . "\n"
              . "    }\n"
              . "    tphp_rt_register((void*)_env_{$id}, 3);\n"
              . "    (t_callback){ .func = (void*){$name}, .env = _env_{$id} };"
            : "    (t_callback){ .func = (void*){$name}, .env = NULL };";

        return "({ tphp_class_Generator* {$name}({$fwdParams});\n{$envDecl}\n  })";
    }

    /** 生成 C thunk：包装 TinyPHP 闭包为无 env 的 C 回调
     *  @param string $cType  C 回调类型 (int32_t / int64_t / double)
     *  @param ExprNode $expr  闭包表达式（inline ClosureExpr 或 VariableExpr）
     */
    /** 按 #callback 声明的签名生成 thunk
     *  @param string $cbName   #callback 声明的名称
     *  @param ExprNode $expr   闭包表达式
     */
    private function generateThunk(string $cbName, ExprNode $expr): string
    {
        $sig    = $this->phpcCallbackSigs[$cbName];
        $cRet   = $sig['ret'];
        $params = array_map('trim', array_filter(explode(',', $sig['params_str'])));
        if (empty($params) || $params[0] === '') $params = [];

        // 解析每个参数: "type name"
        $cParams = [];
        $cParamTypes = [];
        $tpTypes = [];  // TinyPHP 类型（函数指针 cast 用）
        $casts = [];    // C → TinyPHP cast
        foreach ($params as $p) {
            $parts = preg_split('/\s+/', trim($p), 2);
            $cParams[] = trim($p);           // e.g., "int32_t idx"
            $cParamTypes[] = $parts[0];      // e.g., "int32_t"
            $tpTypes[]    = $this->cToTpType($parts[0]);  // e.g., "t_int"
            $casts[]      = $this->cToCast($parts[0]);     // e.g., "(t_int)"
        }

        $tid = ++$this->thunkCounter;
        $thunkName = "_phpc_thunk_{$tid}";
        $cbStatic  = "_phpc_cb_{$tid}";
        $this->sectionLine(self::SEC_THUNKVARS, "static t_callback {$cbStatic};");

        // 函数指针类型（用于 cast 表达式）
        $castType = ($cRet === 'void' ? 'void' : $cRet)
                  . ' (*)(' . implode(', ', $cParamTypes)
                  . (empty($cParamTypes) ? '' : ', ')
                  . 'void*)';

        // Thunk 签名
        $sigStr = implode(', ', $cParams);
        $retCast = $this->cToReturnCast($cRet);

        $thunkImpl  = "static {$cRet} {$thunkName}({$sigStr}) {\n";
        $thunkImpl .= "    {$cRet} (*_raw)(" . implode(', ', $cParamTypes) . (empty($cParamTypes) ? '' : ', ') . "void*) = ({$castType}){$cbStatic}.func;\n";
        $argList = [];
        foreach ($casts as $i => $cast) {
            $pname = explode(' ', $cParams[$i])[1] ?? "_{$i}";
            $argList[] = "{$cast}{$pname}";
        }
        $argStr = implode(', ', $argList);
        $envStr = (empty($argStr) ? '' : ', ') . "{$cbStatic}.env";
        if ($cRet === 'void') {
            $thunkImpl .= "    _raw({$argStr}{$envStr});\n";
        } else {
            $thunkImpl .= "    return {$retCast}_raw({$argStr}{$envStr});\n";
        }
        $thunkImpl .= '}';
        $this->sectionBlock(self::SEC_THUNKS, $thunkImpl);
        $this->sectionLine(self::SEC_FWDDECLS, "static {$cRet} {$thunkName}({$sigStr});");

        $cbCode = $expr->accept($this);
        return "({$cbStatic} = {$cbCode}, {$thunkName})";
    }

    /** C 类型 → TinyPHP 类型（函数指针 cast） */
    private function cToTpType(string $cType): string {
        // 规范化: 去除所有空格并转小写，避免 "const char *" vs "const char*" 不一致
        $n = strtolower(preg_replace('/\s+/', '', $cType));
        return match ($n) {
            'int32_t','int64_t','int','long','longlong','uint32_t','uint64_t','unsignedint','unsignedlong' => 't_int',
            'double','float' => 't_float',
            'constchar*','char*','constchar','char' => 't_string',
            'bool','_bool' => 't_bool',
            'void' => 'void',
            default => 'void*',
        };
    }

    /** C 类型 → cast 表达式（参数转换） */
    private function cToCast(string $cType): string {
        $n = strtolower(preg_replace('/\s+/', '', $cType));
        return match ($n) {
            'int32_t','int64_t','int','long','longlong','uint32_t','uint64_t','unsignedint','unsignedlong' => '(t_int)',
            'double','float' => '(t_float)',
            'constchar*','char*','constchar','char' => '',
            'bool','_bool' => '(t_bool)',
            default => '(void*)',
        };
    }

    /** C 返回类型 → return cast */
    private function cToReturnCast(string $cRet): string {
        if ($cRet === 'void') return '';
        $n = strtolower(preg_replace('/\s+/', '', $cRet));
        return match ($n) {
            'int32_t'   => '(int32_t)',
            'int64_t'   => '(int64_t)',
            'int'       => '(int)',
            'double'    => '(double)',
            'float'     => '(float)',
            'void*'     => '(void*)',
            'bool','_bool' => '(bool)',
            default     => "({$cRet})",
        };
    }

    public function visitVariable(VariableExpr $node): string
    {
        // 'self' / 'parent' 是关键字，不是常量名
        if ($node->name === 'self') return 'self';
        if ($node->name === 'parent') return 'parent';
        // 原始名字判断是否常量
        if (!str_starts_with($node->name, '$')) {
            // 函数内 const 局部常量 → 直接引用变量名（C static const 变量）
            if (isset($this->localConsts[$node->name])) {
                return $node->name;
            }
            return 'TPHP_CONST_' . strtoupper($node->name);
        }
        $n = self::varName($node->name);
        if ($n === '$this') return 'self';
        // byRef 参数：统一解引用一次（int*→(*x), t_array**→(*arr), tphp_class_X**→(*obj)）
        if ($this->isByRefType($this->varTypes[$n] ?? '')) {
            return "(*{$n})";
        }
        return $n;
    }

    public function visitUnary(UnaryExpr $node): string
    {
        return $node->operator . '(' . $node->expr->accept($this) . ')';
    }

    public function visitBinary(BinaryExpr $node): string
    {
        if ($node->operator === '=') {
            // 用于 for-init 中的赋值
            return $node->left->accept($this) . ' = ' . $node->right->accept($this);
        }
        if ($node->operator === '.') {
            // ROPE 优化：展平 ". . ." 链为多片段拼接，一次分配
            $parts = $this->flattenConcat($node);
            if (count($parts) >= 3) {
                $partCodes = array_map(fn($p) => $this->castToStr($p), $parts);
                $count = count($parts);
                // 生成: tphp_rt_str_concat_multi(N, (t_string[]){a, b, c, ...})
                return "tphp_rt_str_concat_multi({$count}, (t_string[]){"
                    . implode(', ', $partCodes) . '})';
            }
            // 2 片段：保持原有 pair-wise
            $left  = $this->castToStr($node->left);
            $right = $this->castToStr($node->right);
            return 'tphp_rt_str_concat(' . $left . ', ' . $right . ')';
        }

        // <=> 太空船: (a < b) ? -1 : ((a > b) ? 1 : 0)
        if ($node->operator === '<=>') {
            $l = $node->left->accept($this);
            $r = $node->right->accept($this);
            $lt = $this->inferType($node->left);
            $rt = $this->inferType($node->right);
            if ($lt === 't_string' || $rt === 't_string') {
                return '(tphp_rt_str_lt(' . $this->castToStr($node->left) . ', ' . $this->castToStr($node->right) . ') ? -1 : (tphp_rt_str_gt(' . $this->castToStr($node->left) . ', ' . $this->castToStr($node->right) . ') ? 1 : 0))';
            }
            return '((' . $l . ') < (' . $r . ') ? -1 : ((' . $l . ') > (' . $r . ') ? 1 : 0))';
        }

        // ** 幂运算
        if ($node->operator === '**') {
            $l = $node->left->accept($this);
            $r = $node->right->accept($this);
            $lt = $this->inferType($node->left);
            if ($lt === 't_float') {
                return 'tphp_rt_pow_float(' . $l . ', ' . $r . ')';
            }
            return 'tphp_rt_pow_int(' . $l . ', ' . $r . ')';
        }

        // null 比较: null == null → true, null == x → false
        $cmpOps = ['==', '!=', '===', '!=='];
        if (in_array($node->operator, $cmpOps, true)) {
            $lNull = $node->left instanceof NullLiteralExpr;
            $rNull = $node->right instanceof NullLiteralExpr;
            if ($lNull || $rNull) {
                if ($lNull && $rNull) {
                    return in_array($node->operator, ['==', '==='], true) ? 'true' : 'false';
                }
                $otherNode = $lNull ? $node->right : $node->left;
                $otype = $this->inferType($otherNode);
                $other = $otherNode->accept($this);
                $isEq = in_array($node->operator, ['==', '==='], true);
                // struct 类型用成员判空
                if ($otype === 't_string') {
                    return $isEq
                        ? "({$other}.data == NULL && {$other}.length == 0)"
                        : "({$other}.data != NULL || {$other}.length > 0)";
                }
                if ($otype === 't_callback') {
                    return $isEq
                        ? "({$other}.func == NULL)"
                        : "({$other}.func != NULL)";
                }
                return $isEq ? "({$other} == null)" : "({$other} != null)";
            }
        }

        // 字符串比较 → 运行时函数（=== / !== 等同于 == / != 在 AOT 固定类型下）
        $cmpAllOps = ['==', '!=', '===', '!==', '<', '>', '<=', '>='];
        if (in_array($node->operator, $cmpAllOps, true)) {
            $lt = $this->inferType($node->left);
            $rt = $this->inferType($node->right);
            if ($lt === 't_string' || $rt === 't_string') {
                $l = $this->castToStr($node->left);
                $r = $this->castToStr($node->right);
                return match ($node->operator) {
                    '==' => 'tphp_rt_str_eq(' . $l . ', ' . $r . ')',
                    '!=' => 'tphp_rt_str_ne(' . $l . ', ' . $r . ')',
                    '===' => 'tphp_rt_str_eq(' . $l . ', ' . $r . ')',
                    '!==' => 'tphp_rt_str_ne(' . $l . ', ' . $r . ')',
                    '<'  => 'tphp_rt_str_lt(' . $l . ', ' . $r . ')',
                    '>'  => 'tphp_rt_str_gt(' . $l . ', ' . $r . ')',
                    '<=' => 'tphp_rt_str_le(' . $l . ', ' . $r . ')',
                    '>=' => 'tphp_rt_str_ge(' . $l . ', ' . $r . ')',
                };
            }
        }

        $lCode = $node->left->accept($this);
        $rCode = $node->right->accept($this);
        $lt = $this->inferType($node->left);
        $rt = $this->inferType($node->right);
        // 对 t_var 操作数解包
        if ($lt === 't_var') $lCode = $this->unwrapIfMixed($node->left, $lCode, $rt);
        if ($rt === 't_var') $rCode = $this->unwrapIfMixed($node->right, $rCode, $lt);
        // 对 t_string 操作数 vs 标量比较，转 int（PHP 语义：string > int 时 string 转 int）
        // 用类型推断而非 str_contains 模式匹配，避免误匹配嵌套在 strlen(...) 等调用内的 get_str_str
        if ($lt === 't_string' && in_array($rt, ['t_int', 't_float', 't_bool'], true)) {
            $lCode = 'tphp_rt_parse_int(' . $lCode . ')';
        }
        if ($rt === 't_string' && in_array($lt, ['t_int', 't_float', 't_bool'], true)) {
            $rCode = 'tphp_rt_parse_int(' . $rCode . ')';
        }

        // instanceof → tp_obj_is_a check
        if ($node->operator === 'instanceof') {
            $rCN = $node->right instanceof VariableExpr ? rtrim($this->varTypes[self::varName($node->right->name)] ?? '', '*') : '';
            $rCN = ($rCN === '' && $node->right instanceof StringLiteralExpr) ? $node->right->value : $rCN;
            // If right is a class name identifier (not variable), look up in classRefName
            if ($node->right instanceof AST\IdentifierExpr ?? null) {
                // Actually in PHP $obj instanceof ClassName, ClassName is parsed as a class reference
            }
            return 'tp_obj_is_a(' . $lCode . ', &_class_' . $rCode . ')';
        }
        // Map PHP === / !== to C == / !=
        $cOp = match ($node->operator) {
            '==='  => '==',
            '!=='  => '!=',
            default => $node->operator,
        };
        return '(' . $lCode . ' ' . $cOp . ' ' . $rCode . ')';
    }

    public function visitTernary(TernaryExpr $node): string
    {
        $cond = $node->condition->accept($this);
        $then = $node->thenExpr->accept($this);
        $else = $node->elseExpr->accept($this);
        return '(' . $cond . ' ? ' . $then . ' : ' . $else . ')';
    }

    public function visitNullCoalesce(NullCoalesceExpr $node): string
    {
        $lt = $this->inferType($node->left);
        // AOT 类型固定：值类型（int/float/bool/string/array*/object*）永不为 null，直接返回 left
        if ($lt === 'null') return $node->right->accept($this);
        // 数组键访问：?? 需要运行时检查键是否存在（getter 对不存在键返回默认值而非 null）
        if ($node->left instanceof ArrayAccessExpr) {
            return $this->generateNullCoalesceArrayAccess($node);
        }
        $left  = $node->left->accept($this);
        // 只有 t_var（可空联合体）才需要运行时 TYPE_NULL 检查
        if ($lt === 't_var') {
            $right = $node->right->accept($this);
            return '(' . $left . '.type != TYPE_NULL ? ' . $left . ' : ' . $right . ')';
        }
        // void*（nullable 对象指针）需要运行时 null 检查
        if ($lt === 'void*' || $lt === 'null') {
            $right = $node->right->accept($this);
            return '(' . $left . ' != null ? ' . $left . ' : ' . $right . ')';
        }
        // 其他值类型：编译期已知非 null，直接返回 left
        return $left;
    }

    /**
     * 为数组键访问生成 ?? 代码：键存在则返回值，否则返回默认值。
     * 注：arr 和 idx 表达式会被求值两次（存在性检查 + 取值），对简单变量/字面量无副作用。
     */
    private function generateNullCoalesceArrayAccess(NullCoalesceExpr $node): string
    {
        $aa = $node->left;  // ArrayAccessExpr
        $right = $node->right->accept($this);
        $valueCode = $aa->accept($this);  // 完整的数组访问 getter 代码
        $arrCode = $aa->array->accept($this);
        $idxCode = $aa->index->accept($this);
        $idxType = $this->inferType($aa->index);
        if ($idxType === 't_string' || $aa->index instanceof StringLiteralExpr) {
            $existsCheck = "tphp_fn_array_key_exists_str({$idxCode}, {$arrCode})";
        } else {
            $existsCheck = "tphp_fn_array_key_exists_int((t_int)({$idxCode}), {$arrCode})";
        }
        return "({$existsCheck} ? {$valueCode} : {$right})";
    }

    public function visitMatchExpr(MatchExpr $node): string
    {
        $tmp = '_match_' . (++$this->tmpVarCounter);
        $condCode = $node->condition->accept($this);
        $condType = $this->inferType($node->condition);
        $resultType = $this->inferType($node->arms[0]->body ?? null) ?: 't_int';

        $lines = [];
        $lines[] = "({ {$resultType} {$tmp};";
        // 检测是否有 default arm（values 为空即为 default）
        $hasDefault = false;
        foreach ($node->arms as $arm) {
            if (empty($arm->values)) { $hasDefault = true; break; }
        }
        if (!$hasDefault) {
            // 无 default：初始化为零值，防止未初始化使用
            $zeroVal = match ($resultType) {
                't_string' => '(t_string){NULL, 0}',
                't_float' => '0.0',
                't_bool' => 'false',
                't_array*' => 'NULL',
                't_callback' => '(t_callback){NULL, NULL}',
                default => '0',
            };
            $lines[] = "    {$tmp} = {$zeroVal};";
        }
        $first = true;
        foreach ($node->arms as $arm) {
            if (empty($arm->values)) {
                // default arm
                $bodyCode = $arm->body->accept($this);
                $lines[] = "    else { {$tmp} = {$bodyCode}; }";
            } else {
                $prefix = $first ? '    if (' : '    else if (';
                $first = false;
                $conds = [];
                foreach ($arm->values as $v) {
                    $vCode = $v->accept($this);
                    if ($condType === 't_string') {
                        $conds[] = "tphp_rt_str_eq({$condCode}, {$vCode})";
                    } else {
                        $conds[] = "({$condCode} == {$vCode})";
                    }
                }
                $bodyCode = $arm->body->accept($this);
                $lines[] = $prefix . implode(' || ', $conds) . ") { {$tmp} = {$bodyCode}; }";
            }
        }
        $lines[] = "    {$tmp}; })";
        return implode("\n", $lines);
    }

    /**
     * pipe operator: left |> right
     *
     * 纯语法糖，AOT 编译期展开：
     *   - right 为 CallExpr 且含占位符 `...` → 用 left 替换占位符位置
     *   - right 为 CallExpr 无占位符 → left 追加为末参
     *   - right 为 VariableExpr/PropertyAccessExpr（callable）→ 生成闭包调用
     *
     * 左结合：$a |> f(...) |> g(...) 等价于 g(f($a))
     */
    public function visitPipeExpr(PipeExpr $node): string
    {
        $right = $node->right;

        // 情况 1：右操作数为函数/方法调用
        if ($right instanceof CallExpr) {
            $hasPlaceholder = false;
            foreach ($right->args as $arg) {
                if ($arg instanceof PlaceholderExpr) {
                    $hasPlaceholder = true;
                    break;
                }
            }

            if ($hasPlaceholder) {
                // 用 left 替换占位符位置
                $newArgs = [];
                foreach ($right->args as $arg) {
                    if ($arg instanceof PlaceholderExpr) {
                        $newArgs[] = $node->left;
                    } else {
                        $newArgs[] = $arg;
                    }
                }
                $newCall = new CallExpr($right->callee, $right->name, $newArgs, $right->isNullsafe, $right->isRawC);
                return $this->visitCall($newCall);
            }

            // 无占位符 → left 追加为末参
            $newArgs = array_merge($right->args, [$node->left]);
            $newCall = new CallExpr($right->callee, $right->name, $newArgs, $right->isNullsafe, $right->isRawC);
            return $this->visitCall($newCall);
        }

        // 情况 2：右操作数为 callable 变量 → 闭包调用
        if ($right instanceof VariableExpr || $right instanceof PropertyAccessExpr) {
            return $this->generateClosureCall($right, [$node->left]);
        }

        throw new \RuntimeException('Pipe operator right operand must be a function call or callable variable');
    }

    /**
     * 占位符 `...` — 仅在 pipe 上下文有效，直接 visit 属于语法错误
     */
    public function visitPlaceholderExpr(PlaceholderExpr $node): string
    {
        throw new \RuntimeException('Placeholder `...` is only valid in pipe operator context');
    }

    public function visitAttributeDecl(AttributeDeclNode $node): string
    {
        return '';  // 注解类型声明不生成代码，由 visitConst 收集
    }

    public function visitAttributeUse(AttributeUseNode $node): string
    {
        return '';  // 注解使用不生成独立代码，由注解收集器处理
    }

    /**
     * 简单转发通用处理器：按 $simpleFnMap 配置生成 tphp_fn_xxx(args) 代码。
     *
     * 支持的 argMode：direct | data | floatcast | wrapvar | wraparr
     * 支持 defaults（缺省参数填充）、order（参数重排）、cNameNoArgs（0 参变体）。
     */
    private function generateSimpleForward(CallExpr $node, array $info): string
    {
        // 0-arg 变体（如 uniqid → uniqid0）
        if (count($node->args) === 0 && isset($info['cNameNoArgs'])) {
            return $info['cNameNoArgs'] . '()';
        }

        // count($arr, $mode) — 第二参数为 COUNT_RECURSIVE 时切换到递归版本
        if (($info['dispatch'] ?? null) === 'count') {
            $arrCode = $node->args[0]->accept($this);
            if (isset($node->args[1])) {
                $modeCode = $node->args[1]->accept($this);
                return "(($modeCode) == 1 ? tphp_fn_arr_count_recursive($arrCode) : tphp_fn_arr_count($arrCode))";
            }
            return "tphp_fn_arr_count($arrCode)";
        }

        // array_keys($arr, $search) — 有第二参数时切换到 search 版本
        if (($info['dispatch'] ?? null) === 'array_keys') {
            $arrCode = $node->args[0]->accept($this);
            if (isset($node->args[1])) {
                $searchCode = $this->wrapVar($node->args[1]);
                return "tphp_fn_array_keys_search($arrCode, $searchCode)";
            }
            return "tphp_fn_array_keys($arrCode)";
        }

        // max/min variadic 形式：多参数时打包成数组调用 tphp_fn_max/min(arr)
        // max(1, 2, 3) → ({ t_array* _t = arr_create(3); push(_t, 1); push(_t, 2); push(_t, 3); tphp_fn_max(_t); })
        if (($info['dispatch'] ?? null) === 'variadic_pack') {
            $nArgs = count($node->args);
            $cName = $info['cName'];
            if ($nArgs <= 1) {
                $arrCode = $node->args[0]->accept($this);
                return "{$cName}({$arrCode})";
            }
            // 多参数：打包成数组
            $tmpArr = '_vp_' . (++$this->tmpVarCounter);
            $code = "({ t_array* {$tmpArr} = tphp_fn_arr_create({$nArgs}); tphp_rt_register((void*){$tmpArr}, 1);";
            foreach ($node->args as $arg) {
                $v = $this->wrapVar($arg);
                $code .= " {$tmpArr} = tphp_fn_arr_push({$tmpArr}, {$v});";
            }
            $code .= " {$cName}({$tmpArr}); })";
            return $code;
        }

        $modes    = $info['modes'] ?? [];
        $defaults = $info['defaults'] ?? [];
        $order    = $info['order'] ?? null;
        $nArgs    = count($node->args);
        // modes 非空时限制最大输出参数数；为空表示变长（不限制）
        $maxArgs  = !empty($modes) ? count($modes) : PHP_INT_MAX;

        // 输出位置数 = min(实参数, maxArgs)，再用 defaults 延伸至填满默认值
        $nPositions = min($nArgs, $maxArgs);
        if (!empty($defaults)) {
            $maxDefaultPos = max(array_keys($defaults)) + 1;
            $nPositions = max($nPositions, min($maxDefaultPos, $maxArgs));
        }

        $processed = [];
        for ($i = 0; $i < $nPositions; $i++) {
            if (isset($node->args[$i])) {
                $arg  = $node->args[$i];
                $mode = $modes[$i] ?? 'direct';
                $processed[$i] = match ($mode) {
                    'direct'    => $arg->accept($this),
                    'data'      => $arg->accept($this) . '.data',
                    'floatcast' => '(t_float)(' . $arg->accept($this) . ')',
                    'wrapvar'   => $this->wrapVar($arg),
                    'wraparr'   => $this->wrapArrayElement($arg, $arg->accept($this)),
                    default     => $arg->accept($this),
                };
            } elseif (isset($defaults[$i])) {
                $processed[$i] = $defaults[$i];
            }
        }

        // 参数重排（如 array_search: PHP(needle,arr) → C(arr,needle)）
        if ($order !== null) {
            $ordered = [];
            foreach ($order as $pos) {
                if (isset($processed[$pos])) $ordered[] = $processed[$pos];
            }
            $processed = $ordered;
        }

        return $info['cName'] . '(' . implode(', ', $processed) . ')';
    }

    public function visitCall(CallExpr $node): string
    {
        // 命名空间 fallback：NS\func() 调用，若 NS 下未定义则 fallback 到全局 func()
        // 符合 PHP 语义：命名空间下未定义的函数调用查全局
        if ($node->callee === null && ($pos = strrpos($node->name, '\\')) !== false) {
            $nsFnCName = self::funcCNameFromCall($node);
            if ($this->symbols->getFuncRet($nsFnCName) === null) {
                $baseName = substr($node->name, $pos + 1);
                $globalNode = new CallExpr($node->callee, $baseName, $node->args, $node->isNullsafe, $node->isRawC);
                $globalNode->line = $node->line;
                $globalNode->column = $node->column;
                return $this->visitCall($globalNode);
            }
        }

        // ── 注解常量静态索引 call() / newInstance() 编译期展开 ──
        // ROUTE[0]->call(12)        → 直接调用目标方法/函数
        // ROUTE[0]->newInstance(...) → new_tphp_class_X(args)
        // AST: CallExpr { callee: ArrayAccessExpr { array: VariableExpr, index: IntLiteral }, name: 'call'|'newInstance' }
        if ($node->callee instanceof ArrayAccessExpr
            && $node->callee->array instanceof VariableExpr
            && !str_starts_with($node->callee->array->name, '$')
            && $node->callee->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$node->callee->array->name])
            && ($node->name === 'call' || $node->name === 'newInstance')) {
            $reg = $this->annotationRegistry[$node->callee->array->name];
            $idx = (int)$node->callee->index->value;
            if (isset($reg['entries'][$idx])) {
                return $this->emitAnnotationCall($reg['entries'][$idx], $node->name, $node->args);
            }
        }

        // ── 注解常量动态索引 call() / newInstance() 运行时分发 ──
        // ROUTE[$i]->call(12) → _annot_ROUTE_dispatch_call(ROUTE[$i], 1, (t_var[]){VAR_INT(12)})
        if ($node->callee instanceof ArrayAccessExpr
            && $node->callee->array instanceof VariableExpr
            && !str_starts_with($node->callee->array->name, '$')
            && isset($this->annotationRegistry[$node->callee->array->name])
            && ($node->name === 'call' || $node->name === 'newInstance')) {
            $annotName = $node->callee->array->name;
            $calleeCode = $node->callee->accept($this);  // 动态索引 → 运行时 AnnotationEntry*
            return $this->emitAnnotationRuntimeCall($annotName, $node->name, $calleeCode, $node->args);
        }

        // ── foreach 变量 $v->call() / $v->newInstance() 运行时分发 ──
        // $v 来自 foreach(ROUTE as $v)，通过 varAnnotSource 追踪来源
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && ($node->name === 'call' || $node->name === 'newInstance')) {
            $valVar = self::varName($node->callee->name);
            if (isset($this->varAnnotSource[$valVar])) {
                $annotName = $this->varAnnotSource[$valVar];
                $calleeCode = $node->callee->accept($this);
                return $this->emitAnnotationRuntimeCall($annotName, $node->name, $calleeCode, $node->args);
            }
        }

        // PHPC 互操作函数名集合（B 段、C 段共享，避免重复定义）
        static $phpcFns = ['c_int','c_str','php_int','php_str','php_str_clone','php_str_ptr','c_void_ptr',
            'phpc_arr_int','phpc_arr_dbl','phpc_arr_str','phpc_new_arr_int',
            'phpc_new_arr_dbl','phpc_new_arr_str','phpc_new_arr',
            'phpc_obj','phpc_new_obj','phpc_unregister_obj','phpc_free','phpc_free_str_arr',
            'phpc_fn','phpc_env','phpc_fn_i32','phpc_fn_i64','phpc_fn_f64',
            'phpc_new_fn','phpc_new_fn_env','phpc_thunk',
            'phpc_assert_ptr','phpc_obj_steal','phpc_env_pin','phpc_env_unpin','phpc_auto',
            'phpc_ptr_to_int','phpc_int_to_ptr'];

        // 简单转发函数：查 $simpleFnMap 命中则交给通用处理器
        if ($node->callee === null && isset(self::$simpleFnMap[$node->name])) {
            return $this->generateSimpleForward($node, self::$simpleFnMap[$node->name]);
        }

        // var_dump 内置函数 —— 包装参数为 t_var 并调用 tphp_var_dump
        if ($node->callee === null && $node->name === 'var_dump') {
            return $this->generateVarDump($node->args);
        }

        // array_push($arr, $val) → 尾部追加，返回新长度
        if ($node->callee === null && $node->name === 'array_push') {
            $arrCode = $node->args[0]->accept($this);
            $valCode = $this->wrapArrayElement($node->args[1], $node->args[1]->accept($this));
            return 'tphp_fn_array_push(&' . $arrCode . ', ' . $valCode . ')';
        }

        // array_pop($arr) → 弹出尾部元素，返回 t_var（mixed 类型）
        if ($node->callee === null && $node->name === 'array_pop') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_array_pop(&' . $arrCode . ')';
        }

        // array_key_exists($key, $arr) → 键是否存在
        if ($node->callee === null && $node->name === 'array_key_exists') {
            $keyType = $this->inferType($node->args[0]);
            $keyCode = $node->args[0]->accept($this);
            $arrCode = $node->args[1]->accept($this);
            if ($keyType === 't_string') {
                return 'tphp_fn_array_key_exists_str(' . $keyCode . ', ' . $arrCode . ')';
            }
            return 'tphp_fn_array_key_exists_int(' . $keyCode . ', ' . $arrCode . ')';
        }

        // array_shift($arr) → 移除头部元素，返回 t_var
        if ($node->callee === null && $node->name === 'array_shift') {
            $arrCode = $node->args[0]->accept($this);
            $tv = '_ts_' . (++$this->tmpVarCounter);
            return "({ t_var {$tv} = VAR_NULL(); tphp_fn_arr_shift({$arrCode}, &{$tv}); {$tv}; })";
        }

        // array_slice($arr, $offset, $length=0, $preserve_keys=false)
        if ($node->callee === null && $node->name === 'array_slice') {
            $arrCode = $node->args[0]->accept($this);
            $offset  = $node->args[1]->accept($this);
            $len     = isset($node->args[2]) ? $node->args[2]->accept($this) : '0';
            $pk      = isset($node->args[3]) ? $node->args[3]->accept($this) : 'false';
            return 'tphp_fn_arr_slice(' . $arrCode . ', ' . $offset . ', ' . $len . ', ' . $pk . ')';
        }

        // str_replace($search, $replace, $subject) — 支持数组参数
        if ($node->callee === null && $node->name === 'str_replace') {
            $sType = $this->inferType($node->args[0]);
            $rType = $this->inferType($node->args[1]);
            $sCode = $node->args[0]->accept($this);
            $rCode = $node->args[1]->accept($this);
            $subjCode = $node->args[2]->accept($this);
            // 两个都是数组 → 数组变体
            if ($sType === 't_array*' && $rType === 't_array*') {
                return "tphp_fn_str_replace_arr({$sCode}, {$rCode}, {$subjCode})";
            }
            // search 是数组，replace 是字符串 → 同一替换串
            if ($sType === 't_array*') {
                return "tphp_fn_str_replace_arr_str({$sCode}, {$rCode}, {$subjCode})";
            }
            // search 是字符串，replace 是数组 → PHP 对每个 replace 应用同一 search（不常见，按字符串处理）
            return "tphp_fn_str_replace({$sCode}, {$rCode}, {$subjCode})";
        }

        // sprintf($fmt, ...$args) → 动态测量 + str_pool_alloc（无上限，完整 C 格式支持）
        if ($node->callee === null && $node->name === 'sprintf') {
            $tn = '_sf_' . (++$this->tmpVarCounter);
            $fmtCode = $node->args[0]->accept($this);
            $fmtArgs = '';
            for ($i = 1; isset($node->args[$i]); $i++) {
                $arg = $node->args[$i]->accept($this);
                $type = $this->inferType($node->args[$i]);
                if ($type === 't_string')      $fmtArgs .= ', ' . $arg . '.data';
                elseif ($type === 't_float')   $fmtArgs .= ', (double)' . $arg;
                else                           $fmtArgs .= ', (int)' . $arg;
            }
            return "({ int {$tn}_len = snprintf(NULL, 0, {$fmtCode}.data{$fmtArgs});"
                 . " char* {$tn}_buf = str_pool_alloc({$tn}_len + 1);"
                 . " snprintf({$tn}_buf, {$tn}_len + 1, {$fmtCode}.data{$fmtArgs});"
                 . " tphp_rt_str_dup((t_string){{$tn}_buf, {$tn}_len}); })";
        }

        // array_map($callback, $arr) — 编译期内联展开，类型特化
        if ($node->callee === null && $node->name === 'array_map') {
            return $this->generateArrayMap($node);
        }

        // array_filter($arr, $callback) — 编译期内联展开
        if ($node->callee === null && $node->name === 'array_filter') {
            return $this->generateArrayFilter($node);
        }

        // array_reduce($arr, $callback, $initial) — 编译期内联展开
        if ($node->callee === null && $node->name === 'array_reduce') {
            return $this->generateArrayReduce($node);
        }

        // var_export 内置函数 —— 转换为可读字符串输出
        if ($node->callee === null && $node->name === 'var_export') {
            return $this->generateVarExport($node->args);
        }

        // error($msg) → 抛出异常（tp_throw），可被 try-catch 捕获
        // 无 try-catch 时 tp_throw 内部仍会 Fatal error + exit(1)
        if ($node->callee === null && $node->name === 'error') {
            $this->checkExceptionReturnType();
            $msg  = !empty($node->args) ? $this->castToStr($node->args[0]) : 'STR_LIT("")';
            return 'tp_throw(STR_PTR_V(' . $msg . '))';
        }

        // isset($var) → 非 null 检测（非指针类型始终 true）
        if ($node->callee === null && $node->name === 'isset') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'null';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 'null';
            // 非指针类型（int/float/bool/string栈值）不可能为 null，始终 true
            if (in_array($type, ['t_int', 't_float', 't_bool', 't_string'], true)) return 'true';
            return 'tphp_fn_isset((void*)' . $code . ')';
        }

        // empty($var) → PHP falsy 检测，按类型分发到 C 函数
        if ($node->callee === null && $node->name === 'empty') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'true';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 't_int';
            return match ($type) {
                't_int'    => 'tphp_fn_empty_int(' . $code . ')',
                't_float'  => 'tphp_fn_empty_float(' . $code . ')',
                't_bool'   => 'tphp_fn_empty_bool(' . $code . ')',
                't_string' => 'tphp_fn_empty_str(' . $code . ')',
                'null'     => 'tphp_fn_empty_null(' . $code . ')',
                default    => 'tphp_fn_empty_int(' . $code . ')',
            };
        }

        // unset($var) → 按类型释放/重置（不改变变量声明状态）
        if ($node->callee === null && $node->name === 'unset') {
            $lines = [];
            foreach ($node->args as $arg) {
                if (!$arg instanceof VariableExpr) continue;
                $code = $arg->accept($this);
                $type = $this->inferType($arg);
                $lines[] = match ($type) {
                    't_string'   => "{$code} = (t_string){.data = NULL, .length = 0, .is_local = false};",
                    't_array*'   => "tphp_rt_unregister((void*){$code}); if ({$code} != NULL) { tphp_fn_arr_free({$code}); {$code} = NULL; }",
                    't_callback' => "if (({$code}).env != NULL) { tphp_rt_unregister(({$code}).env); free(({$code}).env); ({$code}).env = NULL; } ({$code}).func = NULL;",
                    'null'       => "{$code} = NULL;",
                    default      => "{$code} = 0;",
                };
                if (self::isClassCType($type) || self::isEnumCType($type)) {
                    $lines[count($lines)-1] = "tphp_rt_unregister((void*){$code}); tphp_fn_unset_obj((void**)&{$code});";
                    $vn = self::varName($arg->name);
                    $this->symbols->removeScopeObjects([$vn]);
                }
            }
            return implode('; ', $lines) . ';';
        }

        // is_numeric — 必须在通用 is_* 前处理（它不是类型检测）
        if ($node->callee === null && $node->name === 'is_numeric') {
            if (empty($node->args)) return 'false';
            $argCode = $node->args[0]->accept($this);
            return 'tphp_fn_is_numeric_str(' . $argCode . ')';
        }

        // ctype_* functions (string → bool, direct C mapping)
        if ($node->callee === null && str_starts_with($node->name, 'ctype_')) {
            return 'tphp_fn_' . $node->name . '(' . $node->args[0]->accept($this) . ')';
        }

        // is_int / is_string / is_float / is_bool / is_array / is_object / is_null / is_callable / is_resource
        // 仅拦截内置类型检测函数，避免误吞用户自定义的 is_* 函数（如 is_positive）
        static $builtinIsFns = [
            'is_int' => 1, 'is_float' => 1, 'is_string' => 1, 'is_bool' => 1,
            'is_array' => 1, 'is_null' => 1, 'is_object' => 1, 'is_callable' => 1,
            'is_resource' => 1,
        ];
        if ($node->callee === null && isset($builtinIsFns[$node->name])) {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'false';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 't_int';
            return $this->generateIsCheck($node->name, $code, $type);
        }

        // ── 第一梯队新增 ────────────────────────────────────
        if ($node->callee === null) {
            $n = $node->name;
            $a = array_map(fn($a) => $a->accept($this), $node->args);
            $c = count($a) > 0 ? $a[0] : '';

            // 特殊：需要类型转换或非标准 C 名
            if ($n === 'gettype') {
                $t0 = $this->inferType($node->args[0]);
                $w = match ($t0) {
                    't_int' => "VAR_INT({$c})", 't_float' => "VAR_FLOAT((t_float)({$c}))",
                    't_bool' => "VAR_BOOL({$c})", 't_string' => "VAR_STRING({$c})",
                    default => "VAR_NULL",
                };
                return "tphp_fn_gettype({$w})";
            }
            if ($n === 'number_format') {
                if (count($a) >= 2) return "tphp_fn_number_format2((t_float)({$a[0]}), {$a[1]})";
                return "tphp_fn_number_format((t_float)({$a[0]}))";
            }
            if ($n === 'pow') {
                $ta = ($this->inferType($node->args[0]) === 't_int') ? "VAR_INT({$a[0]})" : "VAR_FLOAT((t_float)({$a[0]}))";
                $tb = ($this->inferType($node->args[1]) === 't_int') ? "VAR_INT({$a[1]})" : "VAR_FLOAT((t_float)({$a[1]}))";
                return "tphp_fn_pow({$ta}, {$tb})";
            }
            if ($n === 'strtr') {
                if (count($a) >= 3) return "tphp_fn_strtr2({$a[0]}, {$a[1]}, {$a[2]})";
                return $c;
            }

            // PHPC 互操作函数：加 tphp_fn_ 前缀
            $shortN = strrchr($n, '\\') !== false ? substr(strrchr($n, '\\'), 1) : $n;
            if (in_array($shortN, $phpcFns, true)) {
                // phpc_thunk 特殊处理：按 #callback 声明生成 thunk
                if ($shortN === 'phpc_thunk' && count($a) >= 2 && $node->args[0] instanceof StringLiteralExpr) {
                    $cbName = $node->args[0]->value;
                    if (isset($this->phpcCallbackSigs[$cbName])) {
                        return $this->generateThunk($cbName, $node->args[1]);
                    }
                    throw new \RuntimeException("Unknown callback: #callback {$cbName} not declared");
                }
                // phpc_free / phpc_free_str_arr: 释放后自动置零变量，防 use-after-free
                // 仅当第一参数是简单变量时置零（避免对表达式置零）
                if ($shortN === 'phpc_free' && count($node->args) >= 1
                    && $node->args[0] instanceof VariableExpr) {
                    $varName = $this->visitVariable($node->args[0]);
                    $this->markCPtrCleaned($varName);
                    return '(tphp_fn_phpc_free(' . $varName . '), (' . $varName . ' = NULL))';
                }
                if ($shortN === 'phpc_free_str_arr' && count($node->args) >= 2
                    && $node->args[0] instanceof VariableExpr) {
                    $varName = $this->visitVariable($node->args[0]);
                    $this->markCPtrCleaned($varName);
                    $lenArg = $a[1];
                    return '(tphp_fn_phpc_free_str_arr(' . $varName . ', (int)(' . $lenArg . ')), (' . $varName . ' = NULL))';
                }
                // phpc_unregister_obj / phpc_obj_steal：标记已清理
                if (($shortN === 'phpc_unregister_obj' || $shortN === 'phpc_obj_steal')
                    && count($node->args) >= 1 && $node->args[0] instanceof VariableExpr) {
                    $this->markCPtrCleaned($this->visitVariable($node->args[0]));
                }
                // phpc_auto($ptr)：接管 $ptr 所有权（注册自动释放），标记已清理
                if ($shortN === 'phpc_auto'
                    && count($node->args) >= 1 && $node->args[0] instanceof VariableExpr) {
                    $this->markCPtrCleaned($this->visitVariable($node->args[0]));
                }
                return 'tphp_fn_' . $shortN . '(' . implode(', ', $a) . ')';
            }

            // filter_var(mixed $value, int $filter, array|int $options = 0): mixed
            // - 第一参数 mixed → wrapVar 包成 t_var
            // - 第三参数 array|int 联合：array → tphp_fn_filter_var_opt；int/省略 → tphp_fn_filter_var
            if ($shortN === 'filter_var') {
                $valVar = $this->wrapVar($node->args[0]);
                $filterCode = $a[1] ?? '0';
                if (isset($node->args[2])) {
                    $optType = $this->inferType($node->args[2]);
                    if ($optType === 't_array*' || $node->args[2] instanceof ArrayLiteralExpr) {
                        $optCode = $a[2];
                        return "tphp_fn_filter_var_opt({$valVar}, {$filterCode}, {$optCode})";
                    }
                }
                $optCode = $a[2] ?? '0';
                return "tphp_fn_filter_var({$valVar}, {$filterCode}, {$optCode})";
            }

            // abs(int|float) → 按参数类型分发 int/float 重载
            if ($shortN === 'abs' && count($node->args) >= 1) {
                $argType = $this->inferType($node->args[0]);
                $argCode = $a[0];
                if ($argType === 't_float') {
                    return "tphp_fn_abs_float({$argCode})";
                }
                return "tphp_fn_abs_int({$argCode})";
            }

            // 通用回退：tphp_fn_函数名(参数) — C 编译器兜底
            // 全局函数: tphp_fn_name, 命名空间函数: tphp_na_Ns_tphp_fn_name
            $fnPos = strrpos($n, '\\');
            if ($fnPos !== false) {
                $fnName = 'tphp_na_' . str_replace('\\', '_', substr($n, 0, $fnPos)) . '_tphp_fn_' . substr($n, $fnPos + 1);
            } else {
                $fnName = 'tphp_fn_' . $n;
            }
            // 检查是否有默认值参数，选择正确的重载版本
            $argCount = count($node->args);
            $fnInfo = $this->symbols->getFunc($fnName);
            $defaultCount = $fnInfo !== null ? $fnInfo->defaultCount : 0;
            if ($defaultCount > 0) {
                // 获取总参数数量
                $totalParams = $fnInfo !== null ? count($fnInfo->paramTypes) : 0;
                if ($totalParams > 0 && $argCount < $totalParams) {
                    // 使用重载版本：fnName_缺失参数数量
                    $missingCount = $totalParams - $argCount;
                    $fnName = $fnName . '_' . $missingCount;
                    // 更新参数类型列表（重载版本只有前 argCount 个参数）
                    $pTypes = array_slice($this->symbols->getFuncParams($fnName), 0, $argCount);
                } else {
                    $pTypes = $fnInfo !== null ? $fnInfo->paramTypes : [];
                }
            } else {
                $pTypes = $fnInfo !== null ? $fnInfo->paramTypes : [];
            }
            if (empty($a)) return "{$fnName}()";
            // byRef 参数：形参是指针时要正确传参
            $callArgs = [];
            foreach ($node->args as $i => $arg) {
                $ct = $pTypes[$i] ?? '';
                $isParamByRef = $this->isByRefType($ct);
                if ($isParamByRef && $arg instanceof VariableExpr) {
                    $avn = self::varName($arg->name);
                    if ($this->isByRefType($this->varTypes[$avn] ?? '')) {
                        // byRef 实参 → byRef 形参：直接传指针（visitVariable 已解引用，必须用原始名）
                        $callArgs[] = $avn;
                    } else {
                        // 普通实参 → byRef 形参：取地址
                        $callArgs[] = '&' . self::varName($arg->name);
                    }
                } else {
                    $aCode = $arg->accept($this);
                    $callArgs[] = $isParamByRef ? '&' . $aCode : $aCode;
                }
            }
            return "{$fnName}(" . implode(', ', $callArgs) . ")";
        }

        // ── 第二/三梯队（已全部移入第一块，此处保留空壳以防后续扩展）──

        // 闭包调用: $h() → ((t_int(*)(...))h.func)(args)
        if ($node->callee !== null && $node->name === '__invoke') {
            return $this->generateClosureCall($node->callee, $node->args);
        }

        // 对 t_var 参数自动包裹 VAR_XXX
        $args = [];
        foreach ($node->args as $i => $a) {
            $code = $a->accept($this);
            // 查找该方法参数类型
            $pt = $this->getMethodParamType($node, $i);
            if ($pt === 't_var') {
                $code = $this->wrapTvarAssign($a, $code);
            }
            $args[] = $code;
        }
        if ($node->callee === null) {
            // phpc 桥接函数 → 直接 C 调用（无 tphp_fn_ 前缀，无命名空间 mangle）
            $baseName = ($pos = strrpos($node->name, '\\')) !== false
                ? substr($node->name, $pos + 1) : $node->name;

            // phpc_thunk('name', $fn) → 按 #callback 声明的签名生成 thunk
            if (($baseName === 'phpc_thunk' || str_ends_with($baseName, '\\phpc_thunk'))
                && count($node->args) >= 2
                && $node->args[0] instanceof StringLiteralExpr) {
                $cbName = $node->args[0]->value;
                if (isset($this->phpcCallbackSigs[$cbName])) {
                    return $this->generateThunk($cbName, $node->args[1]);
                }
                throw new \RuntimeException("Unknown callback: #callback {$cbName} not declared");
            }

            if (in_array($baseName, $phpcFns, true)) {
                return $baseName . '(' . implode(', ', $args) . ')';
            }
            // 独立函数：tphp_fn_ 前缀，命名空间名已 mangled
            $fnName = self::mangleCName($node->name);
            return 'tphp_fn_' . $fnName . '(' . implode(', ', $args) . ')';
        }
        $callee = $node->callee->accept($this);
        // Raw C call: C->function() → direct C function, no name mangling
        if ($node->isRawC) {
            // 清理函数启发式识别：函数名以 free/destroy/release/close/delete 结尾时，
            // 标记第一参数（变量）为已释放，避免误报泄漏警告。
            // 覆盖 point_free / rect_destroy / fclose / SDL_FreeSurface 等常见命名约定。
            // 纯编译期分析，零运行时开销；遗漏不会漏报（仅多/少一条提醒，不阻断编译）。
            if (count($node->args) >= 1 && $node->args[0] instanceof VariableExpr) {
                $lowerName = strtolower($node->name);
                if (str_ends_with($lowerName, 'free')
                    || str_ends_with($lowerName, 'destroy')
                    || str_ends_with($lowerName, 'release')
                    || str_ends_with($lowerName, 'close')
                    || str_ends_with($lowerName, 'delete')) {
                    $this->markCPtrCleaned(self::varName($node->args[0]->name));
                }
            }
            return $node->name . '(' . implode(', ', $args) . ')';
        }
        // 方法调用：类名推导
        if ($callee === 'self') {
            $cn = $this->className;
        } elseif ($callee === 'parent') {
            // parent::method() → 查找当前类的父类
            $parentPhp = $this->lookupParentClass($this->phpClassName);
            $cn = $parentPhp !== null ? self::classRefName($parentPhp) : $this->className;
        } elseif ($node->callee instanceof VariableExpr) {
            $key = self::varName($node->callee->name);
            $raw = $this->varTypes[$key] ?? $key;
            $cn = str_contains($raw, '\\') ? self::classRefName($raw) : $raw;
        } elseif ($node->callee instanceof CallExpr) {
            // 链式调用：从上一个调用的返回类型推导
            $cn = $this->inferCallChainClass($node->callee);
        } elseif ($node->callee instanceof EnumAccessExpr) {
            // Color::Red->method() — 实例方法，cn 取枚举 C 结构体名
            $cn = $this->symbols->getEnumCName($node->callee->enumName) ?? $callee;
        } elseif ($node->callee instanceof ArrayAccessExpr) {
            // 数组元素方法调用：$this->connections[$fd]->close()
            //   通过 inferType() 解析数组元素类型，剥离 * 得到类 C 名
            $cn = rtrim($this->inferType($node->callee), '*');
        } elseif ($node->callee instanceof PropertyAccessExpr) {
            // 属性方法调用：$this->protocol->input(...) 或 $obj->prop->method(...)
            //   通过 inferType() 解析属性类型，剥离 * 得到类 C 名
            $cn = rtrim($this->inferType($node->callee), '*');
        } else {
            $cn = $callee;
        }
        // nullsafe on null-typed variable → no-op
        if ($node->isNullsafe && ($cn === 'null' || $cn === '' || $cn === 'void*')) {
            return '0'; // nullsafe no-op
        }
        // Strip trailing * + resolve parent class for inherited methods
        $cnClean = rtrim($cn, '*');
        // 静态方法调用：PHP 类名 → C 类名解析（如 Thread → tphp_class_Thread）
        if ($cnClean !== '' && !$this->symbols->hasClass($cnClean)
            && $this->symbols->resolveEnumCName($cnClean) === null) {
            $resolved = $this->symbols->resolveClass($cnClean);
            if ($resolved !== null) $cnClean = $resolved;
        }
        // ── 枚举方法调用（静态 Color::method() 或实例 Color::Red->method()）──
        $enumCName = $this->symbols->resolveEnumCName($cnClean);
        if ($enumCName !== null) {
            return $this->emitEnumMethodCall($node, $enumCName, $callee, $args);
        }
        $useParent = false;
        $isParentCall = ($callee === 'parent');
        if ($isParentCall) {
            $useParent = true;  // parent::method() 总是通过 _parent 访问
        } elseif ($cnClean !== '' && $this->symbols->getClassMethod($cnClean, $node->name) === null) {
            $parentCN = $this->resolveMethodClass($cnClean, $node->name);
            if ($parentCN !== '') { $cnClean = $parentCN; $useParent = true; }
        }
        // 校验方法存在性：未定义的方法直接报错，不生成无效 C 代码
        if ($cnClean !== '' && $this->symbols->getClassMethod($cnClean, $node->name) === null
            && $node->name !== '__construct' && $node->name !== '__destruct') {
            throw new \RuntimeException(sprintf(
                "[%d:%d] Call to undefined method %s::%s()",
                $node->line, $node->column, $cnClean, $node->name
            ));
        }
        // 静态方法不传 self，实例方法 self 作为第一个参数
        $mInfoForDefault = $this->symbols->getClassMethod($cnClean, $node->name);
        $isStatic = $mInfoForDefault !== null && $mInfoForDefault->isStatic;
        if ($isStatic) {
            $allArgs = $args;
        } else {
            // parent::method() 用 &self->_parent；继承方法用 &callee->_parent；否则用 callee
            $selfArg = $useParent
                ? ($isParentCall ? '&self->_parent' : ('&' . $callee . '->_parent'))
                : $callee;
            $allArgs = array_merge([$selfArg], $args);
        }
        // 选择重载版本：有默认值参数且实参数量 < 总参数时，使用 fnName_缺失数 重载
        $methodCName = "{$cnClean}_{$node->name}";
        $argCount = count($node->args);
        if ($mInfoForDefault !== null && $mInfoForDefault->defaultCount > 0
            && $argCount < $mInfoForDefault->totalParams) {
            $missingCount = $mInfoForDefault->totalParams - $argCount;
            $methodCName = $methodCName . '_' . $missingCount;
        }
        $call = "{$methodCName}(" . implode(', ', $allArgs) . ')';
        // nullsafe ?-> : wrap in NULL check with temp variable
        if ($node->isNullsafe) {
            $mInfo = $this->symbols->getClassMethod($cnClean, $node->name);
            $ret = $mInfo !== null ? $mInfo->retType : 't_int';
            if ($ret === 'void') {
                return "({ if ((void*){$callee} != NULL) {{ {$call}; }} })";
            }
            $tmp = '_nsr_' . (++$this->tmpVarCounter);
            $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
            return "({ {$ret} {$tmp} = {$zero}; if ((void*){$callee} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
        }
        return $call;
    }

    /**
     * 枚举方法调用发射：
     *   - 静态: Color::cases() / Color::from($v) / Color::tryFrom($v) → Color_cases(), ...
     *   - 实例: Color::Red->label() → Color_label(&$e_..._Red, ...)
     * 自动方法（cases/from/tryFrom）为静态无 self；用户方法为实例方法带 self。
     */
    private function emitEnumMethodCall(CallExpr $node, string $enumCName, string $calleeCode, array $args): string
    {
        $method = $node->name;
        $mInfo = $this->symbols->getEnumMethodByCName($enumCName, $method);
        if ($mInfo === null) {
            throw new \RuntimeException(sprintf(
                "[%d:%d] Call to undefined enum method %s::%s()",
                $node->line, $node->column, $enumCName, $method
            ));
        }
        $methodCName = "{$enumCName}_{$method}";
        $argCount = count($node->args);
        // 静态自动方法（cases/from/tryFrom）：无 self 参数
        if ($mInfo->isStatic) {
            // 重载版本选择（自动方法无默认值，但用户静态方法理论上可走此分支——目前用户方法都是实例）
            if ($mInfo->defaultCount > 0 && $argCount < $mInfo->totalParams) {
                $missing = $mInfo->totalParams - $argCount;
                $methodCName = $methodCName . '_' . $missing;
            }
            return "{$methodCName}(" . implode(', ', $args) . ')';
        }
        // 实例方法：self 作为第一个参数
        // calleeCode 对于 EnumAccessExpr 已是 "&_e_<prefix>_<case>"
        $allArgs = array_merge([$calleeCode], $args);
        if ($mInfo->defaultCount > 0 && $argCount < $mInfo->totalParams) {
            $missing = $mInfo->totalParams - $argCount;
            $methodCName = $methodCName . '_' . $missing;
        }
        $call = "{$methodCName}(" . implode(', ', $allArgs) . ')';
        // nullsafe 包装（实例方法才可能）
        if ($node->isNullsafe) {
            $ret = $mInfo->retType;
            if ($ret === 'void') {
                return "({ if ((void*){$calleeCode} != NULL) {{ {$call}; }} })";
            }
            $tmp = '_nsr_' . (++$this->tmpVarCounter);
            $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
            return "({ {$ret} {$tmp} = {$zero}; if ((void*){$calleeCode} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
        }
        return $call;
    }

    /** 推断链式调用的返回类名 */
    private function inferCallChainClass(CallExpr $expr): string
    {
        if ($expr->callee === null) return '';
        if ($expr->callee instanceof VariableExpr) {
            $key = self::varName($expr->callee->name);
            // 枚举静态调用链：Color::from($v)->label() → callee=VariableExpr(Color)
            //   返回枚举 C 结构体名，供后续 emitEnumMethodCall 识别
            $enumCName = $this->symbols->resolveEnumCName($key);
            if ($enumCName !== null) return $enumCName;
            // 枚举名（FQN 或短名）→ C 结构体名
            $enumCName = $this->symbols->getEnumCName($expr->callee->name);
            if ($enumCName !== null) return $enumCName;
            return $this->varTypes[$key] ?? '';
        }
        if ($expr->callee instanceof CallExpr) {
            // 嵌套调用：用返回类型推导
            return rtrim($this->inferCallReturnType($expr->callee), '*');
        }
        if ($expr->callee instanceof EnumAccessExpr) {
            // Color::Red->method()->chain() → enum 实例类型
            return $this->symbols->getEnumCName($expr->callee->enumName) ?? '';
        }
        return '';
    }

    /** 生成 var_dump 调用：将参数包装为 t_var */
    private function generateVarDump(array $args): string
    {
        $wrapped = [];
        foreach ($args as $arg) {
            $wrapped[] = $this->wrapVar($arg);
        }
        return 'tphp_fn_var_dump(' . implode(', ', $wrapped) . ')';
    }

    /** 生成 var_export 调用：将表达式转为可读字符串并 echo */
    private function generateVarExport(array $args): string
    {
        $parts = [];
        foreach ($args as $arg) {
            if ($arg instanceof BoolLiteralExpr) {
                $parts[] = 'tphp_fn_echo(' . ($arg->value ? 'STR_LIT("true")' : 'STR_LIT("false")') . ')';
            } elseif ($arg instanceof CastExpr && $arg->castType === 'bool') {
                $code = $arg->accept($this);
                $parts[] = 'tphp_fn_echo(' . $code . ' ? STR_LIT("true") : STR_LIT("false"))';
            } else {
                // 默认 var_dump 行为
                $parts[] = 'tphp_fn_var_dump(' . $this->wrapVar($arg) . ')';
            }
        }
        return implode('; ', $parts);
    }

    /** 生成闭包调用: ((t_int(*)(t_int,...))var.func)(args) */
    private function generateClosureCall(ExprNode $callee, array $args): string
    {
        $calleeCode = $callee->accept($this);
        $argCodes = array_map(fn($a) => $a->accept($this), $args);
        $argStr = implode(', ', $argCodes);
        $callArgs = ($argStr ? $argStr . ', ' : '') . "{$calleeCode}.env";

        // 查找闭包签名
        $retType = 't_int';
        // 默认：从实参推导参数类型 + void* env（callable 参数等无签名场景）
        $inferred = [];
        foreach ($args as $a) {
            $inferred[] = $this->inferType($a);
        }
        $paramTypes = (empty($inferred) ? '' : implode(', ', $inferred) . ', ') . 'void*';
        if ($callee instanceof VariableExpr) {
            $varName = self::varName($callee->name);
            $fnName = $this->symbols->getVarClosure($varName) ?? '';
            if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                $sig = $this->symbols->getClosureSig($fnName);
                $retType = $sig['ret'];
                $paramTypes = $sig['params'] ? $sig['params'] . ', void*' : 'void*';
            }
        }

        return "(($retType(*)({$paramTypes})){$calleeCode}.func)({$callArgs})";
    }

    // ── array_map / array_filter / array_reduce 编译期内联展开 ──

    /** 从 AST 推断闭包签名（无需先 visit） */
    private function inferCallbackSig(ExprNode $expr): ?array
    {
        if ($expr instanceof ClosureExpr) {
            $ret = $expr->isGenerator ? 'tphp_class_Generator*' : self::mapType($expr->returnType);
            $params = array_map(fn($p) => self::mapType($p->type), $expr->params);
            return ['ret' => $ret, 'params' => $params];
        }
        if ($expr instanceof VariableExpr) {
            $varName = self::varName($expr->name);
            $fnName = $this->symbols->getVarClosure($varName) ?? '';
            if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                $sig = $this->symbols->getClosureSig($fnName);
                $params = $sig['params'] !== '' ? array_map('trim', explode(',', $sig['params'])) : [];
                return ['ret' => $sig['ret'], 'params' => $params];
            }
        }
        return null;
    }

    /** 类型 → 数组元素 getter 函数名 */
    private function arrGetterForType(string $type): string {
        return match($type) {
            't_int'      => 'tphp_fn_arr_item_int',
            't_float'    => 'tphp_fn_arr_item_float',
            't_string'   => 'tphp_fn_arr_item_str',
            't_bool'     => 'tphp_fn_arr_item_bool',
            't_array*'   => 'tphp_fn_arr_item_array',
            't_callback' => 'tphp_fn_arr_item_callback',
            default      => 'tphp_fn_arr_item_object',
        };
    }

    /** 类型 → VAR_ 包装宏 */
    private function arrVarWrapForType(string $type): string {
        return match($type) {
            't_int'      => 'VAR_INT',
            't_float'    => 'VAR_FLOAT',
            't_string'   => 'VAR_STRING',
            't_bool'     => 'VAR_BOOL',
            't_array*'   => 'VAR_ARRAY',
            't_callback' => 'VAR_CALLBACK',
            default      => 'VAR_OBJ',
        };
    }

    /** array_map($callback, $arr) → 类型特化内联循环 */
    private function generateArrayMap(CallExpr $node): string
    {
        $cbCode  = $node->args[0]->accept($this);
        $arrCode = $node->args[1]->accept($this);
        $sig = $this->inferCallbackSig($node->args[0]);
        $retType   = $sig['ret'] ?? 't_int';
        $paramType = $sig['params'][0] ?? 't_int';
        $getter  = $this->arrGetterForType($paramType);
        $varWrap = $this->arrVarWrapForType($retType);
        $tn = '_am_' . (++$this->tmpVarCounter);
        $paramCast = $paramType;
        return "({ t_callback {$tn}_cb = {$cbCode};"
             . " t_array* {$tn}_r = tphp_fn_arr_create(0);"
             . " t_array* {$tn}_a = {$arrCode};"
             . " for (int {$tn}_i = 0; {$tn}_a && {$tn}_i < {$tn}_a->length; {$tn}_i++) {"
             . " {$paramType} {$tn}_v = {$getter}({$tn}_a, {$tn}_i);"
             . " {$retType} {$tn}_m = (({$retType}(*)({$paramCast}, void*)){$tn}_cb.func)({$tn}_v, {$tn}_cb.env);"
             . " {$tn}_r = tphp_fn_arr_push({$tn}_r, {$varWrap}({$tn}_m));"
             . " } {$tn}_r; })";
    }

    /** array_filter($arr, $callback) → 类型特化内联循环 */
    private function generateArrayFilter(CallExpr $node): string
    {
        $arrCode = $node->args[0]->accept($this);
        $cbCode  = $node->args[1]->accept($this);
        $sig = $this->inferCallbackSig($node->args[1]);
        $paramType = $sig['params'][0] ?? 't_int';
        $retType   = $sig['ret'] ?? 't_bool';
        $getter  = $this->arrGetterForType($paramType);
        $varWrap = $this->arrVarWrapForType($paramType);
        $tn = '_af_' . (++$this->tmpVarCounter);
        return "({ t_callback {$tn}_cb = {$cbCode};"
             . " t_array* {$tn}_r = tphp_fn_arr_create(0);"
             . " t_array* {$tn}_a = {$arrCode};"
             . " for (int {$tn}_i = 0; {$tn}_a && {$tn}_i < {$tn}_a->length; {$tn}_i++) {"
             . " {$paramType} {$tn}_v = {$getter}({$tn}_a, {$tn}_i);"
             . " if ((({$retType}(*)({$paramType}, void*)){$tn}_cb.func)({$tn}_v, {$tn}_cb.env)) {"
             . " {$tn}_r = tphp_fn_arr_push({$tn}_r, {$varWrap}({$tn}_v));"
             . " } } {$tn}_r; })";
    }

    /** array_reduce($arr, $callback, $initial) → 类型特化内联循环 */
    private function generateArrayReduce(CallExpr $node): string
    {
        $arrCode  = $node->args[0]->accept($this);
        $cbCode   = $node->args[1]->accept($this);
        $initCode = $node->args[2]->accept($this);
        $sig = $this->inferCallbackSig($node->args[1]);
        $retType   = $sig['ret'] ?? 't_int';
        $accType   = $sig['params'][0] ?? 't_int';
        $elemType  = $sig['params'][1] ?? 't_int';
        $getter  = $this->arrGetterForType($elemType);
        $tn = '_ar_' . (++$this->tmpVarCounter);
        return "({ t_callback {$tn}_cb = {$cbCode};"
             . " {$retType} {$tn}_acc = {$initCode};"
             . " t_array* {$tn}_a = {$arrCode};"
             . " for (int {$tn}_i = 0; {$tn}_a && {$tn}_i < {$tn}_a->length; {$tn}_i++) {"
             . " {$elemType} {$tn}_v = {$getter}({$tn}_a, {$tn}_i);"
             . " {$tn}_acc = (({$retType}(*)({$accType}, {$elemType}, void*)){$tn}_cb.func)({$tn}_acc, {$tn}_v, {$tn}_cb.env);"
             . " } {$tn}_acc; })";
    }

    /** is_int / is_float / is_string / is_bool / is_array / is_null / is_object / is_callable
     *  静态类型在编译期直接返回 true/false 常量；t_var 类型调用运行时 tphp_fn_is_* */
    private function generateIsCheck(string $fnName, string $argCode, string $argType): string
    {
        $checkType = substr($fnName, 3); // is_int → int, is_float → float, ...

        // t_var (mixed/union) 类型统一走运行时函数
        if ($argType === 't_var') {
            return "tphp_fn_{$fnName}({$argCode})";
        }

        // null 检测：静态 null → true，其他静态类型 → false
        if ($checkType === 'null') {
            return ($argType === 'null') ? 'true' : 'false';
        }

        // is_object: 类名类型 → true，基本类型 → false
        if ($checkType === 'object') {
            $primitives = ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback', 'null', 'void*'];
            return in_array($argType, $primitives, true) ? 'false' : 'true';
        }

        // is_resource: Resource/File 等类型 → true，其他 → false
        if ($checkType === 'resource') {
            // t_var (mixed/union) 已在上方处理
            // 静态类型：以 tphp_class_ 开头且继承自 Resource → true
            if (self::isClassCType($argType)) {
                return 'true';
            }
            return 'false';
        }

        // 其他 is_*：精确匹配
        $typeMap = [
            'int'      => 't_int',
            'float'    => 't_float',
            'string'   => 't_string',
            'bool'     => 't_bool',
            'array'    => 't_array*',
            'callable' => 't_callback',
        ];
        $expectedType = $typeMap[$checkType] ?? '';

        if ($expectedType !== '' && $argType === $expectedType) return 'true';

        return 'false';
    }

    /** 将表达式包装为 t_var */
    private function wrapVar(ExprNode $expr): string
    {
        if ($expr instanceof StringLiteralExpr) {
            return 'VAR_STRING(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof IntLiteralExpr) {
            return 'VAR_INT(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof FloatLiteralExpr) {
            return 'VAR_FLOAT(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof BoolLiteralExpr) {
            return 'VAR_BOOL(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof NullLiteralExpr) {
            return 'VAR_NULL()';
        }
        if ($expr instanceof PropertyAccessExpr) {
            $code = $expr->accept($this);
            // ── 注解 entry 属性访问: ROUTE[0]->data/type/name ──
            // _annot_ROUTE_0->data → VAR_ARRAY, ->type/->name → VAR_STRING
            if ($expr->object instanceof ArrayAccessExpr
                && $expr->object->array instanceof VariableExpr
                && !str_starts_with($expr->object->array->name, '$')
                && $expr->object->index instanceof IntLiteralExpr
                && isset($this->annotationRegistry[$expr->object->array->name])) {
                $prop = ltrim($expr->property, '$');
                return match ($prop) {
                    'data'  => "VAR_ARRAY({$code})",
                    'type'  => "VAR_STRING({$code})",
                    'name'  => "VAR_STRING({$code})",
                    default => "VAR_INT({$code})",
                };
            }
            // 类常量访问 → 查 SymbolTable
            if (str_starts_with($code, 'TPHP_CONST_')) {
                $ct = $this->symbols->getConstType($code) ?? $this->symbols->getConstType(strtoupper(substr($code, 12))) ?? 't_int';
                return match ($ct) {
                    't_string' => "VAR_STRING({$code})",
                    't_float'  => "VAR_FLOAT({$code})",
                    't_bool'   => "VAR_BOOL({$code})",
                    default    => "VAR_INT({$code})",
                };
            }
            // 用 getPropType 查类型（含 enum 属性）
            $propType = $this->getPropType($expr);
            if ($propType === '') $propType = 't_int';
            // Object type → VAR_OBJ
            if (str_contains($propType, '_class_') || str_ends_with($propType, '*')) {
                return "VAR_OBJ({$code})";
            }
            return match ($propType) {
                't_int'      => "VAR_INT({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_string'   => "VAR_STRING({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                default      => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof EnumAccessExpr) {
            $code = $expr->accept($this); // &_e_X_Y (指针)
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "VAR_STRING(({$code})->value)" : "VAR_INT(({$code})->value)";
        }
        if ($expr instanceof VariableExpr) {
            // 常量引用（原始名字不以 $ 开头）—— 根据类型选择 VAR_*
            if (!str_starts_with($expr->name, '$')) {
                $cname = 'TPHP_CONST_' . strtoupper($expr->name);
                $ct = $this->symbols->getConstType($expr->name) ?? 't_string';
                return match ($ct) {
                    't_int'    => "VAR_INT({$cname})",
                    't_float'  => "VAR_FLOAT({$cname})",
                    't_bool'   => "VAR_BOOL({$cname})",
                    't_array*' => "VAR_ARRAY({$cname})",
                    default    => "VAR_STRING({$cname})",
                };
            }
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            // byRef 变量：解引用到值类型
            if ($this->isByRefType($vt)) {
                $vn = '(*' . $vn . ')';
                $vt = substr($vt, 0, -1);
            }
            return match (true) {
                $vt === 't_int'      => "VAR_INT({$vn})",
                $vt === 't_float'    => "VAR_FLOAT({$vn})",
                $vt === 't_string'   => "VAR_STRING({$vn})",
                $vt === 't_bool'     => "VAR_BOOL({$vn})",
                $vt === 't_array*'   => "VAR_ARRAY({$vn})",
                $vt === 't_callback' => "VAR_CALLBACK({$vn})",
                $vt === 't_var'      => $vn,
                $vt === 'null'       => "VAR_NULL()",
                str_contains($vt, 'tphp_class_') || str_contains($vt, 'tphp_enum_') => "VAR_OBJ({$vn})",
                default               => "VAR_NULL()",
            };
        }
        if ($expr instanceof ArrayLiteralExpr) {
            // GNU 复合表达式: VAR_ARRAY(({ t_array* _a = ...; ...; _a; }))
            $tmpName = "_vd_arr_" . (++$this->tmpVarCounter);
            $arrCode = $this->genArrayLiteralInline($expr, $tmpName);
            return "VAR_ARRAY(({ {$arrCode} {$tmpName}; }))";
        }
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return 'VAR_STRING(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof NewExpr) {
            $code = $expr->accept($this);
            return "VAR_OBJ({$code})";
        }
        if ($expr instanceof BinaryExpr) {
            $code = $expr->accept($this);
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => "VAR_STRING({$code})",
                't_float'  => "VAR_FLOAT({$code})",
                't_bool'   => "VAR_BOOL({$code})",
                default    => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof UnaryExpr) {
            $code = $expr->accept($this);
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => "VAR_STRING({$code})",
                't_float'  => "VAR_FLOAT({$code})",
                't_bool'   => "VAR_BOOL({$code})",
                default    => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof TernaryExpr) {
            $code = $expr->accept($this);
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => "VAR_STRING({$code})",
                't_float'  => "VAR_FLOAT({$code})",
                't_bool'   => "VAR_BOOL({$code})",
                default    => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof CastExpr) {
            $code = $expr->accept($this);
            return match ($expr->castType) {
                'bool'   => "VAR_BOOL({$code})",
                'string' => "VAR_STRING({$code})",
                'int'    => "VAR_INT({$code})",
                'float'  => "VAR_FLOAT({$code})",
                default  => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof CallExpr) {
            $code = $expr->accept($this);
            // 使用 inferType 推导返回类型（涵盖 is_*, count, date 等内置函数）
            $retType = $this->inferType($expr);
            if ($retType === 't_int' && $expr->callee === null) {
                // inferType 回退到 t_int，手动补查内置函数
                if ($expr->name === 'date') $retType = 't_string';
                elseif (str_starts_with($expr->name, 'is_') || str_starts_with($expr->name, 'ctype_')) $retType = 't_bool';
            }
            return match ($retType) {
                't_string'   => "VAR_STRING({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                't_var'      => $code,
                'null'       => "VAR_NULL()",
                default      => (str_contains($retType, 'tphp_class_') || str_contains($retType, 'tphp_enum_'))
                    ? "VAR_OBJ({$code})"
                    : "VAR_INT({$code})",
            };
        }
        if ($expr instanceof ArrayAccessExpr) {
            $code = $expr->accept($this);
            // 字符串键：优先 AST 嵌套追踪；否则 per-key 追踪；默认 VAR_STRING
            // （保持兼容性：未追踪的字符串键默认视为 string，避免误判为 int 返回 0）
            if ($this->hasStrKey($expr)) {
                // 嵌套访问优先用 AST 精确追踪（处理 $m["items"][0]["id"] 混合类型）
                if ($expr->array instanceof ArrayAccessExpr) {
                    $traced = $this->traceNestedAccessType($expr);
                    if ($traced === 't_int' || $traced === 't_bool') return "VAR_INT({$code})";
                    if ($traced === 't_float')    return "VAR_FLOAT({$code})";
                    if ($traced === 't_array*')   return "VAR_ARRAY({$code})";
                    if ($traced === 't_callback') return "VAR_CALLBACK({$code})";
                    if ($traced === 'null')       return "VAR_NULL()";
                    if ($traced !== null && (str_contains($traced, 'tphp_class_') || str_contains($traced, 'tphp_enum_')))
                        return "VAR_OBJ({$code})";
                    if ($traced === 't_string')   return "VAR_STRING({$code})";
                }
                // 非嵌套或追踪失败：per-key 类型追踪
                if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $kt = $this->arrValueTypes[$at][$expr->index->value] ?? '';
                    if ($kt === 't_int' || $kt === 't_bool')   return "VAR_INT({$code})";
                    if ($kt === 't_float')    return "VAR_FLOAT({$code})";
                    if ($kt === 't_array*')   return "VAR_ARRAY({$code})";
                    if ($kt === 't_callback') return "VAR_CALLBACK({$code})";
                    if ($kt === 'null')       return "VAR_NULL()";
                    if ($kt && (str_contains($kt, 'tphp_class_') || str_contains($kt, 'tphp_enum_')))
                        return "VAR_OBJ({$code})";
                }
                return "VAR_STRING({$code})";
            }
            // int 键：使用 inferType 判断实际元素类型
            $type = $this->inferType($expr);
            return match ($type) {
                't_string'   => "VAR_STRING({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                'null'       => "VAR_NULL()",
                default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                    ? "VAR_OBJ({$code})"
                    : "VAR_INT({$code})",
            };
        }
        // 默认：使用 inferType 动态判断表达式类型
        $code = $expr->accept($this);
        $type = $this->inferType($expr);
        return match ($type) {
            't_string'   => "VAR_STRING({$code})",
            't_float'    => "VAR_FLOAT({$code})",
            't_bool'     => "VAR_BOOL({$code})",
            't_array*'   => "VAR_ARRAY({$code})",
            't_callback' => "VAR_CALLBACK({$code})",
            'null'       => "VAR_NULL()",
            default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                ? "VAR_OBJ({$code})"
                : "VAR_INT({$code})",
        };
    }

    public function visitCast(CastExpr $node): string
    {
        if ($node->castType === 'string') {
            return $this->castToStr($node->expr, strict: true);
        }
        if ($node->castType === 'int') {
            return $this->castToInt($node->expr);
        }
        if ($node->castType === 'float') {
            return $this->castToFloat($node->expr);
        }
        if ($node->castType === 'bool') {
            return $this->castToBool($node->expr);
        }
        if ($node->castType === 'array') {
            return $this->castToArray($node->expr);
        }
        return '((' . self::mapType($node->castType) . ')(' . $node->expr->accept($this) . '))';
    }

    public function visitNew(NewExpr $node): string
    {
        $cn = self::classRefName($node->className);
        $args = array_map(fn($a) => $a->accept($this), $node->args);
        if (empty($args) && $cn !== $this->className) {
            return "new_{$cn}()";
        }
        // 默认参数重载：构造函数有默认值参数且实参数量 < 总参数时，使用 new_cn_<missing> 重载
        $ctorInfo = $this->symbols->getClassMethod($cn, '__construct');
        $allocName = "new_{$cn}";
        if ($ctorInfo !== null && $ctorInfo->defaultCount > 0
            && count($args) < $ctorInfo->totalParams) {
            $missing = $ctorInfo->totalParams - count($args);
            $allocName = "new_{$cn}_{$missing}";
        }
        return "{$allocName}(" . implode(', ', $args) . ')';
    }

    public function visitPropertyAccess(PropertyAccessExpr $node): string
    {
        // ── 注解常量静态索引属性访问编译期展开 ──
        // ROUTE[0]->data / ->type / ->name → _annot_ROUTE_0->data / ->type / ->name
        if ($node->object instanceof ArrayAccessExpr
            && $node->object->array instanceof VariableExpr
            && !str_starts_with($node->object->array->name, '$')
            && $node->object->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$node->object->array->name])) {
            $reg = $this->annotationRegistry[$node->object->array->name];
            $idx = (int)$node->object->index->value;
            if (isset($reg['entries'][$idx])) {
                $entryVar = $reg['entryVarPrefix'] . $idx;
                $prop = ltrim($node->property, '$');
                if (in_array($prop, ['data', 'type', 'name'], true)) {
                    return "{$entryVar}->{$prop}";
                }
            }
        }

        // C->CONST — direct C constant/enum/macro access (no parentheses)
        if ($node->object instanceof VariableExpr && $node->object->name === 'C') {
            return $node->property;
        }
        $obj = $node->object->accept($this);
        $prop = ltrim($node->property, '$');
        // #cstruct 原生字段访问：$p->x → ((Point*)$p)->x
        //   当对象类型为已声明的 C 结构体指针时，直接 cast 访问字段
        if ($node->object instanceof VariableExpr && str_starts_with($node->object->name, '$')) {
            $vn = self::varName($node->object->name);
            $objType = $this->varTypes[$vn] ?? '';
            // objType 形如 "Point*" — 去掉尾部 * 得到结构体名
            $structName = rtrim($objType, '*');
            if (isset($this->cstructFields[$structName])) {
                // 验证字段存在
                foreach ($this->cstructFields[$structName] as $f) {
                    if ($f['name'] === $prop) {
                        return "(({$structName}*){$obj})->{$prop}";
                    }
                }
                throw new \RuntimeException(
                    sprintf("[%d:%d] C struct %s has no field '%s'", $node->line, $node->column, $structName, $prop)
                );
            }
        }
        // 静态属性访问: Class::$prop / self::$prop → 文件作用域变量 <cn>_<prop>
        //   (property 名以 $ 开头标识静态属性，object 名无 $ 前缀标识类名/self)
        if ($node->object instanceof VariableExpr
            && !str_starts_with($node->object->name, '$')
            && str_starts_with($node->property, '$')) {
            $rawName = $node->object->name;
            $cn = ($rawName === 'self')
                ? $this->className
                : ($this->symbols->resolveClass($rawName) ?? $rawName);
            if ($this->symbols->isStaticProp($cn, $prop)) {
                return "{$cn}_{$prop}";
            }
            throw new \RuntimeException(
                sprintf("[%d:%d] Access to undeclared static property %s::$%s", $node->line, $node->column, $rawName, $prop)
            );
        }
        // COS inheritance: resolve property through _parent chain
        $objCN = '';
        if ($obj === 'self') {
            $objCN = $this->className;
        } elseif ($node->object instanceof VariableExpr) {
            $objType = $this->varTypes[self::varName($node->object->name)] ?? '';
            // tphp_class_Dog* → tphp_class_Dog
            $objCN = rtrim($objType, '*');
        }
        // Property Hook: get 拦截 — 不在 hook 体内时调用 getter
        if (!$this->inHookBody && $objCN !== '' && !ctype_upper($prop[0] ?? '')) {
            $hookInfo = $this->resolveHookInfo($objCN, $prop);
            if ($hookInfo !== null && $hookInfo['get']) {
                return $hookInfo['cn'] . '_get_' . $prop . '(' . $obj . ')';
            }
        }
        if ($objCN !== '' && !$this->symbols->hasClassOwnProp($objCN, $prop)) {
            // 枚举类型直接访问字段（无 COS _parent 包装）
            //   全局: tphp_enum_Color；命名空间: tphp_na_Ns_tphp_enum_Status
            if (self::isEnumCType($objCN)) {
                return $obj . '->' . $prop;
            }
            // 类常量（大写开头）→ 不经过 _parent，由下方 const 逻辑处理
            if ($prop !== '' && ctype_upper($prop[0])) {
                // 交由下方类常量访问逻辑 → TPHP_CONST_ 引用
            } else {
                $prefix = $this->resolvePropPrefix($objCN, $prop);
                return $obj . '->_parent.' . $prefix . $prop;
            }
        }
        // 类常量访问: self::CONST 或 ClassName::CONST → TPHP_CONST_ 引用
        if (ctype_upper($prop[0] ?? '')) {
            $rawObjName = ($node->object instanceof VariableExpr) ? $node->object->name : '';
            // self::CONST
            if ($rawObjName === 'self' || $obj === 'self') {
                $cn = strtoupper($this->className);
                return 'TPHP_CONST_' . $cn . '_' . strtoupper($prop);
            }
            // ClassName::CONST — 解析类名，检查可见性
            $cname = $this->symbols->resolveClass($rawObjName);
            if ($cname !== null) {
                $fullCName = 'TPHP_CONST_' . strtoupper($cname . '_' . $prop);
                $vis = $this->symbols->getConstVis($cname . '_' . $prop);
                if ($vis !== 'public' && $vis !== null) {
                    throw new \RuntimeException(
                        "Cannot access {$vis} const {$rawObjName}::{$prop}"
                    );
                }
                return $fullCName;
            }
        }
        // 加括号防止 &enum->field 被 C 误解析为 &(enum->field)
        if (str_starts_with($obj, '&')) {
            return "({$obj})->{$prop}";
        }
        return "{$obj}->{$prop}";
    }

    public function visitPropertyDecl(PropertyDeclNode $node): string
    {
        return '';
    }

    public function visitConst(ConstNode $node): string
    {
        // 注解类型声明: #[Attribute(...)] const NAME = [];
        //   扫描整个 ProgramNode 收集 #[NAME(...)] 使用，生成 AnnotationEntry 数组
        if ($node->attributeDecl !== null) {
            return $this->emitAnnotationConstant($node);
        }
        $name = 'TPHP_CONST_' . strtoupper($node->name);
        // 有声明类型时校验一致性，并以声明类型注册；无则按字面量推导
        $litCType = self::$litTypeMap[$node->value::class] ?? null;
        if ($node->type !== null) {
            $declCType = self::mapType($node->type);
            if ($litCType !== null && $declCType !== $litCType) {
                throw new \RuntimeException(
                    "Constant {$node->name} type mismatch: "
                    . "declared '{$node->type}' ({$declCType}) but value is {$litCType}"
                );
            }
            $ct = $declCType;
        } else {
            $ct = $litCType ?? 't_int';
        }
        $this->symbols->addConst($node->name, $ct);
        if ($node->value instanceof StringLiteralExpr) {
            $val = str_replace('"', '\\"', $node->value->value);
            return '#define ' . $name . ' STR_LIT("' . $val . '")';
        }
        if ($node->value instanceof IntLiteralExpr) {
            return '#define ' . $name . ' ' . $node->value->value;
        }
        if ($node->value instanceof FloatLiteralExpr) {
            $fv = $node->value->value;
            return '#define ' . $name . ' ' .
                (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
        }
        if ($node->value instanceof BoolLiteralExpr) {
            return '#define ' . $name . ' ' . ($node->value->value ? 'true' : 'false');
        }
        return '/* const ' . $node->name . ' */';
    }

    /**
     * 注解常量发射：扫描 ProgramNode 收集 #[NAME(...)] 使用，生成 AnnotationEntry 数组
     *
     * 生成结构:
     *   static tphp_class_AnnotationEntry* _annot_NAME_0 = NULL;  // 静态索引编译期展开
     *   static t_array* TPHP_CONST_NAME = NULL;
     *   static void _annot_NAME_init(void) { ... 填充数组 ... }
     *
     * 注册到 $annotationRegistry，供 visitArrayAccess/visitCall/visitPropertyAccess 静态展开使用
     */
    private function emitAnnotationConstant(ConstNode $node): string
    {
        $shortName = $node->name;
        $fqName = $node->namespace !== '' ? $node->namespace . '\\' . $node->name : $node->name;
        $constName = 'TPHP_CONST_' . strtoupper($node->name);
        $initFn = '_annot_' . $node->name . '_init';
        $entryPrefix = '_annot_' . $node->name . '_';

        // 收集所有 AttributeUseNode 匹配此注解
        $entries = [];
        $declParams = $node->attributeDecl->params;

        $allClasses = array_merge(
            $this->program->mainClass ? [$this->program->mainClass] : [],
            $this->program->extraClasses
        );
        foreach ($allClasses as $class) {
            $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            // 类级注解
            foreach ($class->attributes as $attr) {
                if ($this->attrNameMatches($attr->name, $shortName, $fqName)) {
                    $this->validateAttrArgs($node->name, $declParams, $attr->args, "class {$classFq}");
                    $entries[] = [
                        'kind' => 'class',
                        'class' => $classFq,
                        'namespace' => $class->namespace,
                        'className' => $class->name,
                        'method' => null,
                        'function' => null,
                        'name' => $classFq,
                        'args' => $attr->args,
                    ];
                }
            }
            // 方法级注解
            foreach ($class->methods as $m) {
                foreach ($m->attributes as $attr) {
                    if ($this->attrNameMatches($attr->name, $shortName, $fqName)) {
                        $kind = $m->isStatic ? 'static_method' : 'method';
                        $qualified = $classFq . ($m->isStatic ? '::' : '->') . $m->name;
                        $this->validateAttrArgs($node->name, $declParams, $attr->args, "method {$qualified}");
                        $entries[] = [
                            'kind' => $kind,
                            'class' => $classFq,
                            'namespace' => $class->namespace,
                            'className' => $class->name,
                            'method' => $m->name,
                            'isStatic' => $m->isStatic,
                            'function' => null,
                            'name' => $qualified,
                            'args' => $attr->args,
                        ];
                    }
                }
            }
        }
        // 函数级注解
        foreach ($this->program->functions as $fn) {
            foreach ($fn->attributes as $attr) {
                if ($this->attrNameMatches($attr->name, $shortName, $fqName)) {
                    $fnFq = $fn->namespace !== '' ? $fn->namespace . '\\' . $fn->name : $fn->name;
                    $this->validateAttrArgs($node->name, $declParams, $attr->args, "function {$fnFq}");
                    $entries[] = [
                        'kind' => 'function',
                        'class' => null,
                        'namespace' => $fn->namespace,
                        'className' => null,
                        'method' => null,
                        'function' => $fn->name,
                        'name' => $fnFq,
                        'args' => $attr->args,
                    ];
                }
            }
        }

        // 注册到符号表 — 注解常量为 t_array* 类型
        $this->symbols->addConst($node->name, 't_array*');

        // 注册到 annotationRegistry（短名 + FQ 名均可查）
        $reg = [
            'fqName' => $fqName,
            'shortName' => $shortName,
            'constName' => $constName,
            'initFn' => $initFn,
            'entryVarPrefix' => $entryPrefix,
            'entries' => $entries,
        ];
        $this->annotationRegistry[$shortName] = $reg;
        if ($fqName !== $shortName) {
            $this->annotationRegistry[$fqName] = $reg;
        }
        $this->annotationInitFns[] = $initFn;

        // ── 生成 C 代码 ──
        $declLines = [];
        $declLines[] = "/* ── Annotation Constant: {$fqName} ──────────── */";
        // 每条 entry 的静态指针变量（供静态索引编译期展开使用）
        foreach ($entries as $i => $e) {
            $declLines[] = "static tphp_class_AnnotationEntry* {$entryPrefix}{$i} = NULL;";
        }
        $declLines[] = "static t_array* {$constName} = NULL;";
        $declLines[] = '';

        // init 函数实现
        $implLines = [];
        $implLines[] = "static void {$initFn}(void) {";
        $implLines[] = "    {$constName} = tphp_fn_arr_create(" . count($entries) . ");";
        foreach ($entries as $i => $e) {
            // 构建 data 数组（位置参数）
            $dataVar = "{$entryPrefix}{$i}_data";
            $implLines[] = "    t_array* {$dataVar} = tphp_fn_arr_create(" . count($e['args']) . ");";
            foreach ($e['args'] as $ai => $arg) {
                $implLines[] = "    tphp_fn_arr_set_int({$dataVar}, {$ai}, " . $this->wrapVar($arg) . ");";
            }
            // 构建 AnnotationEntry
            $typeStr = $e['kind'];
            $nameStr = $e['name'];
            $typeC = 'STR_LIT("' . $typeStr . '")';
            $nameC = 'STR_LIT("' . str_replace('\\', '\\\\', $nameStr) . '")';
            $implLines[] = "    {$entryPrefix}{$i} = new_tphp_class_AnnotationEntry({$dataVar}, {$typeC}, {$nameC});";
            $implLines[] = "    tphp_fn_arr_set_int({$constName}, {$i}, VAR_OBJ({$entryPrefix}{$i}));";
        }
        $implLines[] = "}";

        // ── 生成运行时 dispatch 函数（供 foreach 中 $v->call() / $v->newInstance() 使用） ──
        $dispatchLines = $this->emitAnnotationDispatch($node->name, $entries);
        $dispatchBlock = implode("\n", $dispatchLines);

        // 将声明 + init 函数实现 + dispatch 函数加入 SEC_CLSIMPL（在 main 之前执行）
        $this->sectionBlock(self::SEC_CLSIMPL, implode("\n", $declLines) . implode("\n", $implLines) . $dispatchBlock);
        // 前向声明 init 函数（main 中调用）
        $this->sectionLine(self::SEC_FUNCFWDS, "static void {$initFn}(void);");

        // 注解常量本身不输出 #define（已是 static 变量，由 visitVariable 解析）
        return "/* const {$fqName} — annotation constant, see _annot_*  */";
    }

    /** 生成运行时分发调用代码（$v->call() / $v->newInstance()） */
    private function emitAnnotationRuntimeCall(string $annotName, string $method, string $calleeCode, array $args): string
    {
        $argCodes = array_map(fn($a) => $a->accept($this), $args);
        $argc = count($argCodes);
        $dispatchFn = '_annot_' . $annotName . '_dispatch_' . ($method === 'call' ? 'call' : 'new');

        if ($argc === 0) {
            $argv = 'NULL';
        } else {
            // 参数包装为 t_var 数组
            $wrapped = [];
            for ($i = 0; $i < $argc; $i++) {
                $wrapped[] = $this->wrapVarExpr($argCodes[$i], $args[$i]);
            }
            $argv = '(t_var[]){' . implode(', ', $wrapped) . '}';
        }

        $callExpr = "{$dispatchFn}({$calleeCode}, {$argc}, {$argv})";

        if ($method === 'newInstance') {
            // newInstance 返回 void*，需要 cast 到目标类型
            $reg = $this->annotationRegistry[$annotName] ?? null;
            if ($reg !== null) {
                $classEntries = array_filter($reg['entries'], fn($e) => $e['kind'] === 'class');
                if (count($classEntries) === 1) {
                    $entry = reset($classEntries);
                    $classCName = self::classRefName($entry['class']);
                    return "(({$classCName}*){$callExpr})";
                }
                $commonBase = $this->findCommonBaseClass($classEntries);
                if ($commonBase !== '') {
                    $classCName = self::classRefName($commonBase);
                    return "(({$classCName}*){$callExpr})";
                }
            }
        }

        return $callExpr;
    }

    /** 将表达式代码包装为 t_var（用于运行时分发参数传递） */
    private function wrapVarExpr(string $code, ?ExprNode $expr): string
    {
        if ($expr === null) return "VAR_INT({$code})";
        $type = $this->inferType($expr);
        return match ($type) {
            't_int'    => "VAR_INT({$code})",
            't_float'  => "VAR_FLOAT({$code})",
            't_bool'   => "VAR_BOOL({$code})",
            't_string' => "VAR_STRING({$code})",
            default    => "VAR_INT({$code})",
        };
    }

    /** 检查属性使用名是否匹配注解常量
     *  规则（与普通常量作用域一致）:
     *    - FQ 名（含 \ 或经 use const 导入）→ 精确匹配 FQ 名
     *    - 短名 → 匹配短名（同命名空间常量 + 全局常量回退） */
    private function attrNameMatches(string $attrName, string $shortName, string $fqName): bool
    {
        return $attrName === $fqName || $attrName === $shortName;
    }

    /** 生成注解运行时 dispatch 函数（call / newInstance）
     *  供 foreach 中 $v->call() / $v->newInstance() 使用 — 通过 entry->name 字符串匹配分发 */
    private function emitAnnotationDispatch(string $annotName, array $entries): array
    {
        $lines = [];
        $callFn = '_annot_' . $annotName . '_dispatch_call';
        $newInstFn = '_annot_' . $annotName . '_dispatch_new';

        // ── call() dispatch ──
        $hasCallable = false;
        foreach ($entries as $e) {
            if ($e['kind'] !== 'class') { $hasCallable = true; break; }
        }
        if ($hasCallable) {
            $lines[] = '';
            $lines[] = "/* {$annotName} call() 运行时分发 */";
            $lines[] = "static void {$callFn}(tphp_class_AnnotationEntry* _entry, int _argc, t_var* _argv) {";
            $first = true;
            foreach ($entries as $e) {
                if ($e['kind'] === 'class') continue;
                $nameC = 'STR_LIT("' . str_replace('\\', '\\\\', $e['name']) . '")';
                $kw = $first ? 'if' : 'else if';
                $lines[] = "    {$kw} (tphp_rt_str_eq(_entry->name, {$nameC})) {";
                // 生成目标调用
                $callExpr = $this->buildEntryCallExpr($e, '_argv');
                $lines[] = "        {$callExpr};";
                $lines[] = "    }";
                $first = false;
            }
            $lines[] = "}";
        }

        // ── newInstance() dispatch ──
        $hasClass = false;
        foreach ($entries as $e) {
            if ($e['kind'] === 'class') { $hasClass = true; break; }
        }
        if ($hasClass) {
            $lines[] = '';
            $lines[] = "/* {$annotName} newInstance() 运行时分发 */";
            $lines[] = "static void* {$newInstFn}(tphp_class_AnnotationEntry* _entry, int _argc, t_var* _argv) {";
            $first = true;
            foreach ($entries as $e) {
                if ($e['kind'] !== 'class') continue;
                $nameC = 'STR_LIT("' . str_replace('\\', '\\\\', $e['name']) . '")';
                $kw = $first ? 'if' : 'else if';
                $lines[] = "    {$kw} (tphp_rt_str_eq(_entry->name, {$nameC})) {";
                $classCName = self::classRefName($e['class']);
                if ($this->isMainClassCName($classCName)) {
                    $lines[] = "        return (void*)new_{$classCName}((t_int)0, (t_array*)NULL);";
                } else {
                    // 查找构造器参数类型，从 _argv 提取
                    $ctorParams = $this->lookupMethodParams($e['class'], '__construct');
                    $args = $this->buildRuntimeArgs($ctorParams, '_argv');
                    $lines[] = "        return (void*)new_{$classCName}(" . implode(', ', $args) . ");";
                }
                $lines[] = "    }";
                $first = false;
            }
            $lines[] = "    return NULL;";
            $lines[] = "}";
        }

        return $lines;
    }

    /** 为 dispatch 分支构建目标调用表达式（参数从 _argv 运行时提取） */
    private function buildEntryCallExpr(array $entry, string $argvVar): string
    {
        $kind = $entry['kind'];
        if ($kind === 'function') {
            $fnCName = $entry['namespace'] !== ''
                ? 'tphp_na_' . self::mangleCName($entry['namespace']) . '_tphp_fn_' . $entry['function']
                : 'tphp_fn_' . $entry['function'];
            $params = $this->lookupFunctionParams($entry['function'], $entry['namespace']);
            $args = $this->buildRuntimeArgs($params, $argvVar);
            return "{$fnCName}(" . implode(', ', $args) . ")";
        }
        // method / static_method
        $classCName = self::classRefName($entry['class']);
        $methodCName = $classCName . '_' . $entry['method'];
        $params = $this->lookupMethodParams($entry['class'], $entry['method']);
        $args = $this->buildRuntimeArgs($params, $argvVar);
        if ($kind === 'static_method') {
            return "{$methodCName}(" . implode(', ', $args) . ")";
        }
        // 实例方法：先 new 再调
        $newExpr = $this->isMainClassCName($classCName)
            ? "new_{$classCName}((t_int)0, (t_array*)NULL)"
            : "new_{$classCName}()";
        return "(" . $methodCName . "(" . $newExpr . (empty($args) ? "" : ", " . implode(', ', $args)) . "))";
    }

    /** 从 t_var* _argv 提取参数，按目标函数参数类型转换 */
    private function buildRuntimeArgs(array $paramTypes, string $argvVar): array
    {
        $args = [];
        foreach ($paramTypes as $i => $type) {
            $cType = self::mapType($type);
            $args[] = match ($cType) {
                't_int'    => "(_argc > {$i} && _argv[{$i}].type == TYPE_INT) ? (t_int)_argv[{$i}].value._int : 0",
                't_float'  => "(_argc > {$i} && _argv[{$i}].type == TYPE_FLOAT) ? (t_float)_argv[{$i}].value._float : 0.0",
                't_bool'   => "(_argc > {$i} && _argv[{$i}].type == TYPE_BOOL) ? (t_bool)_argv[{$i}].value._bool : false",
                't_string' => "(_argc > {$i} && _argv[{$i}].type == TYPE_STRING) ? _argv[{$i}].value._string : ((t_string){NULL, 0})",
                default    => "0",
            };
        }
        return $args;
    }

    /** 查找独立函数的参数类型列表 */
    private function lookupFunctionParams(string $fnName, string $namespace): array
    {
        foreach ($this->program->functions as $fn) {
            if ($fn->name === $fnName && $fn->namespace === $namespace) {
                return array_map(fn($p) => $p->type, $fn->params);
            }
        }
        return [];
    }

    /** 查找类方法的参数类型列表 */
    private function lookupMethodParams(string $className, string $methodName): array
    {
        $allClasses = array_merge(
            $this->program->mainClass ? [$this->program->mainClass] : [],
            $this->program->extraClasses
        );
        foreach ($allClasses as $class) {
            $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            if ($classFq === $className || $class->name === $className) {
                foreach ($class->methods as $m) {
                    if ($m->name === $methodName) {
                        return array_map(fn($p) => $p->type, $m->params);
                    }
                }
            }
        }
        return [];
    }

    /** 查找多个 class entry 的共同基类（用于 newInstance() 返回类型推断） */
    private function findCommonBaseClass(array $classEntries): string
    {
        $classLists = [];
        foreach ($classEntries as $e) {
            $chain = [];
            $current = $e['class'];
            while ($current !== null && $current !== '') {
                $chain[] = $current;
                $current = $this->lookupParentClass($current);
            }
            $classLists[] = $chain;
        }
        if (empty($classLists)) return '';
        // 取所有类链的交集
        $common = $classLists[0];
        for ($i = 1; $i < count($classLists); $i++) {
            $common = array_intersect($common, $classLists[$i]);
        }
        return !empty($common) ? reset($common) : '';
    }

    /** 查找类的父类名 */
    private function lookupParentClass(string $className): ?string
    {
        $allClasses = array_merge(
            $this->program->mainClass ? [$this->program->mainClass] : [],
            $this->program->extraClasses
        );
        foreach ($allClasses as $class) {
            $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            if ($classFq === $className || $class->name === $className) {
                return $class->parentName;
            }
        }
        return null;
    }

    /** 编译期校验注解参数（数量、类型） */
    private function validateAttrArgs(string $annotName, array $declParams, array $args, string $context): void
    {
        $total = count($declParams);
        $required = 0;
        foreach ($declParams as $p) {
            if ($p['default'] === null) $required++;
        }
        if (count($args) < $required || count($args) > $total) {
            throw new \RuntimeException(sprintf(
                "Annotation #[%s(...)] on %s expects %d-%d args, got %d",
                $annotName, $context, $required, $total, count($args)
            ));
        }
    }

    /** 检查 C 类名是否为 Main 入口类（构造器签名为 (t_int argc, t_array* argv)） */
    private function isMainClassCName(string $classCName): bool
    {
        return $this->program !== null
            && $this->program->mainClass !== null
            && self::classCName($this->program->mainClass) === $classCName;
    }

    /**
     * 注解 entry 的 call() / newInstance() 编译期展开
     *
     * call(...$args) — 调用目标方法/函数:
     *   - method:        (tphp_class_Main_test(new_tphp_class_Main(), args))
     *   - static_method: (tphp_class_Main_staticMethod(args))
     *   - function:      (tphp_fn_func(args))
     *   - class:         错误（class 目标不支持 call）
     *
     * newInstance(...$args) — 实例化目标类:
     *   - class:         new_tphp_class_Demo(args)
     *   - 其他:          错误
     */
    private function emitAnnotationCall(array $entry, string $method, array $args): string
    {
        $argCodes = array_map(fn($a) => $a->accept($this), $args);

        if ($method === 'call') {
            $kind = $entry['kind'];
            if ($kind === 'class') {
                throw new \RuntimeException(sprintf(
                    "Annotation entry '%s' is a class target, use newInstance() instead of call()",
                    $entry['name']
                ));
            }
            if ($kind === 'function') {
                // 函数调用: tphp_fn_X(args) or tphp_na_Ns_tphp_fn_X(args)
                $fnCName = $entry['namespace'] !== ''
                    ? 'tphp_na_' . self::mangleCName($entry['namespace']) . '_tphp_fn_' . $entry['function']
                    : 'tphp_fn_' . $entry['function'];
                return "{$fnCName}(" . implode(', ', $argCodes) . ")";
            }
            // 方法调用
            $classCName = self::classRefName($entry['class']);
            $methodCName = $classCName . '_' . $entry['method'];
            if ($kind === 'static_method') {
                return "{$methodCName}(" . implode(', ', $argCodes) . ")";
            }
            // 实例方法: 需要先 new 实例再调用
            // Main 入口类构造器签名 (t_int argc, t_array* argv)，传 dummy 参数
            $newExpr = $this->isMainClassCName($classCName)
                ? "new_{$classCName}((t_int)0, (t_array*)NULL)"
                : "new_{$classCName}()";
            return "(" . $methodCName . "(" . $newExpr . (empty($argCodes) ? "" : ", " . implode(', ', $argCodes)) . "))";
        }

        // newInstance
        if ($entry['kind'] !== 'class') {
            throw new \RuntimeException(sprintf(
                "Annotation entry '%s' is a %s target, use call() instead of newInstance()",
                $entry['name'], $entry['kind']
            ));
        }
        $classCName = self::classRefName($entry['class']);
        // Main 入口类构造器签名 (t_int argc, t_array* argv)
        if ($this->isMainClassCName($classCName)) {
            return empty($argCodes)
                ? "new_{$classCName}((t_int)0, (t_array*)NULL)"
                : "new_{$classCName}((t_int)0, (t_array*)NULL)";
        }
        if (empty($argCodes)) {
            return "new_{$classCName}()";
        }
        return "new_{$classCName}(" . implode(', ', $argCodes) . ")";
    }

    public function visitEnum(EnumNode $node): string
    {
        // 注册枚举类型（FQN + 短名均可查）
        $fqName = ($node->namespace !== '')
            ? $node->namespace . '\\' . $node->name
            : $node->name;
        // 全局枚举: tphp_enum_Name, 命名空间枚举: tphp_na_Ns_tphp_enum_Name
        if ($node->namespace !== '') {
            $cName = 'tphp_na_' . self::mangleCName($node->namespace) . '_tphp_enum_' . $node->name;
        } else {
            $cName = 'tphp_enum_' . $node->name;
        }
        $cValueType = ($node->backingType === 'int') ? 't_int' : 't_string';

        $this->symbols->addEnum($fqName, $node->backingType, $cName . '*');
        $this->symbols->addEnum($node->name, $node->backingType, $cName . '*');

        // 将命名空间分隔符转为 C 标识符前缀
        $prefix = self::mangleCName($fqName);

        $lines = [];
        $lines[] = "/* ── Enum: {$fqName} ({$node->backingType}) ──────────────── */";

        // Struct 定义
        $lines[] = "typedef struct {";
        $lines[] = "    t_string name;";
        $lines[] = "    {$cValueType} value;";
        $lines[] = "} {$cName};";

        // Static 单例实例（const → .rodata，零内存泄漏）
        foreach ($node->cases as $case) {
            $valCode = $case->value->accept($this);
            $lines[] = "static {$cName} _e_{$prefix}_{$case->name} = {";
            $lines[] = "    .name = STR_LIT(\"{$case->name}\"),";
            $lines[] = "    .value = {$valCode},";
            $lines[] = "};";
            // 注册 case（FQN + 短名）
            $this->symbols->addEnumCase($fqName, $case->name);
            $this->symbols->addEnumCase($node->name, $case->name);
        }
        $lines[] = '';

        // 枚举常量 → #define（与类常量命名一致：TPHP_CONST_<CN>_<NAME>）
        foreach ($node->classConsts as $cc) {
            $cname = 'TPHP_CONST_' . strtoupper($cName . '_' . $cc->name);
            $vis = $cc->visibility ?? 'public';
            $declCType = self::mapType($cc->type);
            $this->symbols->addEnumConst($fqName, $cc->name, $declCType);
            $this->symbols->addEnumConst($node->name, $cc->name, $declCType);
            $this->symbols->addConst($cName . '_' . $cc->name, $declCType, $vis);
            $this->symbols->addConst($cname, $declCType, $vis);
            if ($cc->value instanceof StringLiteralExpr) {
                $val = str_replace('"', '\\"', $cc->value->value);
                $lines[] = "#define {$cname} STR_LIT(\"{$val}\")";
            } elseif ($cc->value instanceof IntLiteralExpr) {
                $lines[] = "#define {$cname} {$cc->value->value}";
            } elseif ($cc->value instanceof FloatLiteralExpr) {
                $fv = $cc->value->value;
                $lines[] = '#define ' . $cname . ' ' .
                    (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
            } elseif ($cc->value instanceof BoolLiteralExpr) {
                $lines[] = "#define {$cname} " . ($cc->value->value ? 'true' : 'false');
            }
        }
        if (!empty($node->classConsts)) $lines[] = '';

        // 注册用户方法 + 自动 cases()/from()/tryFrom() 到 SymbolTable
        $autoStatic = [
            'cases'    => 't_array*',
            'from'     => $cName . '*',
            'tryFrom'  => $cName . '*',
        ];
        foreach ($node->methods as $m) {
            $mr = $m->isGenerator ? 'tphp_class_Generator*' : self::mapType($m->returnType);
            $pts = array_map(fn($p) => $this->mapType($p->type), $m->params);
            $tp = count($m->params);
            $dc = 0;
            for ($i = $tp - 1; $i >= 0; $i--) {
                if ($m->params[$i]->default !== null) { $dc++; } else { break; }
            }
            $mi = new MethodInfo($mr, $pts, false, 'public', $dc, $tp);
            $this->symbols->addEnumMethod($fqName, $m->name, $mi);
            $this->symbols->addEnumMethod($node->name, $m->name, $mi);
        }
        // 自动方法注册（静态）
        $paramCType = ($node->backingType === 'int') ? 't_int' : 't_string';
        foreach ($autoStatic as $mname => $mret) {
            $mi = new MethodInfo($mret, [$paramCType], true, 'public', 0, 1);
            // cases() 无参数
            if ($mname === 'cases') {
                $mi = new MethodInfo($mret, [], true, 'public', 0, 0);
            }
            $this->symbols->addEnumMethod($fqName, $mname, $mi);
            $this->symbols->addEnumMethod($node->name, $mname, $mi);
        }

        // 方法前置声明（用户实例方法 + 自动静态方法）
        $fwd = [];
        foreach ($node->methods as $m) {
            $ret = $m->isGenerator ? 'tphp_class_Generator*' : self::mapType($m->returnType);
            $params = array_map(fn($p) => $this->visitParam($p), $m->params);
            $fwd[] = "{$ret} {$cName}_{$m->name}({$cName}* self" .
                (empty($params) ? '' : ', ' . implode(', ', $params)) . ');';
        }
        // 自动方法前置声明（静态，无 self）
        $fwd[] = "t_array* {$cName}_cases();";
        $fwd[] = "{$cName}* {$cName}_from({$paramCType} value);";
        $fwd[] = "{$cName}* {$cName}_tryFrom({$paramCType} value);";
        $lines = array_merge($lines, $fwd);
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Phase 2: 枚举方法实现 + 自动 cases()/from()/tryFrom() 实现
     * 输出到 SEC_CLSIMPL（在 SEC_ENUMS 前置声明之后）
     */
    private function emitEnumImpl(EnumNode $node): string
    {
        $fqName = ($node->namespace !== '') ? $node->namespace . '\\' . $node->name : $node->name;
        if ($node->namespace !== '') {
            $cName = 'tphp_na_' . self::mangleCName($node->namespace) . '_tphp_enum_' . $node->name;
        } else {
            $cName = 'tphp_enum_' . $node->name;
        }
        $prefix = self::mangleCName($fqName);
        $savedClassName = $this->className;
        $savedPhpClassName = $this->phpClassName;
        $savedNamespace = $this->currentNamespace;
        $savedInMethod = $this->inMethod;
        $this->className = $cName;
        $this->phpClassName = $fqName;
        $this->currentNamespace = $node->namespace;
        $this->inMethod = true;

        $parts = [];
        $parts[] = "/* ── Enum impl: {$fqName} ──────────────────── */";

        // 用户实例方法实现
        foreach ($node->methods as $m) {
            $parts[] = $this->visitMethod($m);
        }

        // 自动 cases(): 返回 t_array*，元素为 enum 实例指针（VAR_OBJ 包裹）
        $casesImpl = [];
        $casesImpl[] = "t_array* {$cName}_cases() {";
        $casesImpl[] = $this->ind("t_array* a = tphp_fn_arr_create(" . count($node->cases) . ");");
        $casesImpl[] = $this->ind("tphp_rt_register((void*)a, 1);");
        foreach ($node->cases as $case) {
            $casesImpl[] = $this->ind("a = tphp_fn_arr_push(a, VAR_OBJ(&_e_{$prefix}_{$case->name}));");
        }
        $casesImpl[] = $this->ind("return a;");
        $casesImpl[] = "}";
        $parts[] = implode("\n", $casesImpl);

        // 自动 from(): 找不到抛 tp_throw
        $paramCType = ($node->backingType === 'int') ? 't_int' : 't_string';
        $fromImpl = [];
        $fromImpl[] = "{$cName}* {$cName}_from({$paramCType} value) {";
        foreach ($node->cases as $case) {
            if ($node->backingType === 'int') {
                $fromImpl[] = $this->ind("if (value == _e_{$prefix}_{$case->name}.value) return &_e_{$prefix}_{$case->name};");
            } else {
                $fromImpl[] = $this->ind("if (tphp_rt_str_eq(value, _e_{$prefix}_{$case->name}.value)) return &_e_{$prefix}_{$case->name};");
            }
        }
        $fromImpl[] = $this->ind("tp_throw(\"{$node->name}::from(): value not found in enum cases\");");
        $fromImpl[] = $this->ind("return NULL;");
        $fromImpl[] = "}";
        $parts[] = implode("\n", $fromImpl);

        // 自动 tryFrom(): 找不到返回 NULL
        $tryImpl = [];
        $tryImpl[] = "{$cName}* {$cName}_tryFrom({$paramCType} value) {";
        foreach ($node->cases as $case) {
            if ($node->backingType === 'int') {
                $tryImpl[] = $this->ind("if (value == _e_{$prefix}_{$case->name}.value) return &_e_{$prefix}_{$case->name};");
            } else {
                $tryImpl[] = $this->ind("if (tphp_rt_str_eq(value, _e_{$prefix}_{$case->name}.value)) return &_e_{$prefix}_{$case->name};");
            }
        }
        $tryImpl[] = $this->ind("return NULL;");
        $tryImpl[] = "}";
        $parts[] = implode("\n", $tryImpl);

        $this->className = $savedClassName;
        $this->phpClassName = $savedPhpClassName;
        $this->currentNamespace = $savedNamespace;
        $this->inMethod = $savedInMethod;

        return implode("\n\n", $parts);
    }

    // EnumAccessExpr → 返回 static 实例指针（case 访问）或常量引用（const 访问）
    public function visitEnumAccess(EnumAccessExpr $node): string
    {
        // case 访问 → static 实例指针
        if ($this->symbols->hasEnumCase($node->enumName, $node->caseName)) {
            $prefix = self::mangleCName($node->enumName);
            return "&_e_{$prefix}_{$node->caseName}";
        }
        // 枚举常量访问 → #define 引用
        $cName = $this->symbols->getEnumCName($node->enumName);
        if ($cName !== null) {
            return 'TPHP_CONST_' . strtoupper($cName . '_' . $node->caseName);
        }
        // 兜底：当作 case（向后兼容旧路径）
        $prefix = self::mangleCName($node->enumName);
        return "&_e_{$prefix}_{$node->caseName}";
    }

    // ============================================================
    // 控制流
    // ============================================================

    public function visitIfStmt(IfStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $lines = [];
        $lines[] = "if ({$cond}) {";
        foreach ($node->thenBody as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = '}';
        foreach ($node->elseifs as $eif) {
            $econd = $eif->condition->accept($this);
            $lines[] = "else if ({$econd}) {";
            foreach ($eif->body as $s) $lines[] = $this->ind($s->accept($this));
            $lines[] = '}';
        }
        if (!empty($node->elseBody)) {
            $lines[] = 'else {';
            foreach ($node->elseBody as $s) $lines[] = $this->ind($s->accept($this));
            $lines[] = '}';
        }
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    public function visitWhileStmt(WhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $startLabel;  // while 无 step，continue N 跳到 cond 检查
        $lines = [];
        $lines[] = "{$startLabel}:;";
        $lines[] = "while ({$cond}) {";
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        $this->scopeDepth--;
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitDoWhileStmt(DoWhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;  // do-while continue N 跳到 cond 检查前
        $lines = [];
        $lines[] = "{$startLabel}:;";
        $lines[] = 'do {';
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = "} while ({$cond});";
        $lines[] = "{$endLabel}:;";
        $this->scopeDepth--;
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitListStmt(ListStmtNode $node): string
    {
        $lines = [];
        $arrName = '_lst_' . (++$this->tmpVarCounter);
        $expr = $node->expr->accept($this);
        $lines[] = "t_array* {$arrName} = {$expr};";
        // 推断源数组元素类型，用于 list 解构变量类型
        $elemType = 't_int';
        $srcLiteral = null;
        if ($node->expr instanceof VariableExpr) {
            $vn = self::varName($node->expr->name);
            $elemType = $this->arrElementTypes[$vn] ?? 't_int';
        } elseif ($node->expr instanceof ArrayLiteralExpr) {
            $elemType = $this->inferArrayDeepElementType($node->expr);
            $srcLiteral = $node->expr;
        }
        $this->generateListAssign($lines, $arrName, 0, $node->vars, $elemType, $srcLiteral);
        // Keyed destructuring: ['key' => $var, ...] = $arr
        if (!empty($node->keyedEntries)) {
            $this->generateKeyedAssign($lines, $arrName, $node->keyedEntries, $elemType);
        }
        return implode("\n", $lines);
    }

    /** Generate assignments for keyed list destructuring:
     *  ['key' => $var] = $arr  →  $var = tphp_fn_arr_get_str_int($arr, STR_LIT("key"));
     */
    private function generateKeyedAssign(array &$lines, string $arrName, array $entries, string $elemType = 't_int'): void
    {
        // 元素类型 → keyed getter 后缀
        $getterSuffix = match ($elemType) {
            't_float'    => 'float',
            't_string'   => 'str',
            't_bool'     => 'int',  // tphp 无 arr_get_str_bool，用 int
            't_array*'   => 'arr',
            default      => 'int',
        };
        $cType = $this->typeToCType($elemType);
        foreach ($entries as $e) {
            $key = $e['key'];
            $var = $e['var'];
            $klen = strlen($key);
            $isDeclared = isset($this->declaredVars[$var]);
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = $elemType;
            $prefix = $isDeclared ? '' : ($cType . ' ');
            $lines[] = "{$prefix}{$var} = tphp_fn_arr_get_str_{$getterSuffix}({$arrName}, (t_string){.data=\"{$key}\", .length={$klen}});";
        }
    }

    /** 递归生成 list 赋值代码
     * @param array $vars (null|string|ListStmtNode)[]
     * @param ArrayLiteralExpr|null $srcLiteral 源数组字面量（用于 per-index 元素类型推断，处理混合类型数组）
     */
    private function generateListAssign(array &$lines, string $arrName, int $baseIdx, array $vars, string $elemType = 't_int', ?ArrayLiteralExpr $srcLiteral = null): void
    {
        $cType = $this->typeToCType($elemType);
        $idx = $baseIdx;
        $entryIdx = 0;
        foreach ($vars as $item) {
            if ($item === null) {
                $idx++;
                $entryIdx++;
                continue;
            }
            // per-index 元素类型推断：从源数组字面量对应位置推断具体元素类型
            // （处理 [10, [20, [30]]] 等混合类型数组：index 0 是 int，index 1 是 array）
            $itemElemType = $elemType;
            $itemSrcLiteral = null;
            if ($srcLiteral !== null && isset($srcLiteral->entries[$entryIdx])) {
                $srcEntry = $srcLiteral->entries[$entryIdx];
                $srcVal = $srcEntry->value ?? $srcEntry;
                if ($srcVal !== null) {
                    $inferred = $this->inferType($srcVal);
                    if ($inferred !== 'null') {
                        $itemElemType = $inferred;
                        if (str_contains($itemElemType, 'tphp_class_') && !str_ends_with($itemElemType, '*')) $itemElemType .= '*';
                    }
                    if ($srcVal instanceof ArrayLiteralExpr) {
                        $itemSrcLiteral = $srcVal;
                    }
                }
            }
            // 元素类型 → item getter 后缀（注意：arr_item_* 系列用 'array'）
            $getterSuffix = match ($itemElemType) {
                't_float'    => 'float',
                't_string'   => 'str',
                't_bool'     => 'int',
                't_array*'   => 'array',
                default      => 'int',
            };
            $itemCType = $this->typeToCType($itemElemType);
            if ($item instanceof ListStmtNode) {
                // 嵌套 list：先取 t_var*，再取 .value._array
                $subArr = '_sublst_' . (++$this->tmpVarCounter);
                $tv     = '_tv_' . (++$this->tmpVarCounter);
                $lines[] = "t_var* {$tv} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_get_int({$arrName}, {$idx}) : NULL;";
                $lines[] = "t_array* {$subArr} = ({$tv} && {$tv}->type == TYPE_ARRAY) ? {$tv}->value._array : NULL;";
                // 递归：传入子数组的字面量 AST（若有），elemType 用子数组自身的元素类型
                $subElemType = $itemSrcLiteral !== null
                    ? $this->inferArrayDeepElementType($itemSrcLiteral)
                    : $itemElemType;
                $this->generateListAssign($lines, $subArr, 0, $item->vars, $subElemType, $itemSrcLiteral);
                $idx++;
                $entryIdx++;
                continue;
            }
            // 普通变量
            $var = $item;
            $isDeclared = isset($this->declaredVars[$var]);
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = $itemElemType;
            $prefix = $isDeclared ? '' : ($itemCType . ' ');
            $zeroVal = match ($itemElemType) {
                't_string'   => '(t_string){0}',
                't_float'    => '0.0',
                't_array*'   => 'NULL',
                't_callback' => 'NULL',
                default      => '0',  // t_int, t_bool
            };
            $lines[] = "{$prefix}{$var} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_item_{$getterSuffix}({$arrName}, {$idx}) : {$zeroVal};";
            $idx++;
            $entryIdx++;
        }
    }

    /** tphp 类型 → C 类型名（用于变量声明） */
    private function typeToCType(string $tphpType): string
    {
        return match ($tphpType) {
            't_int'      => 't_int',
            't_float'    => 't_float',
            't_string'   => 't_string',
            't_bool'     => 't_bool',
            't_array*'   => 't_array*',
            't_callback' => 't_callback',
            default      => (str_contains($tphpType, 'tphp_class_') ? $tphpType : 't_int'),
        };
    }

    public function visitForStmt(ForStmtNode $node): string
    {
        $init = '';
        if ($node->init) {
            if ($node->init instanceof BinaryExpr && $node->init->operator === '=') {
                $v = $node->init->left->accept($this);
                $e = $node->init->right->accept($this);
                $vn = ($node->init->left instanceof VariableExpr) ? self::varName($node->init->left->name) : '';
                $isDeclared = isset($this->declaredVars[$vn]);
                $this->declaredVars[$vn] = true;
                // 推断初始化表达式的类型（不仅仅是 t_int）
                $initType = $this->inferType($node->init->right);
                $this->varTypes[$vn] = $initType;
                if ($isDeclared) {
                    $init = "{$v} = {$e}";
                } else {
                    // 未声明变量：提升到函数作用域
                    $this->funcScopeDecls[$vn] = $initType;
                    $init = "{$v} = {$e}";
                }
            } else {
                $init = $node->init->accept($this);
            }
        }
        $cond = $node->condition ? $node->condition->accept($this) : '';
        $step = $node->step ? $node->step->accept($this) : '';
        $this->scopeDepth++;
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;
        $lines = [];
        $lines[] = "{$startLabel}:;";
        $lines[] = "for ({$init}; {$cond}; {$step}) {";
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        $this->scopeDepth--;
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitForeachStmt(ForeachStmtNode $node): string
    {
        // 生成器迭代分支：iterable 类型含 tphp_class_Generator
        $iterType = $this->inferType($node->array);
        if (str_contains($iterType, 'tphp_class_Generator')) {
            return $this->emitGeneratorForeach($node);
        }

        $arr  = $node->array->accept($this);
        $cnt  = '_fc_' . (++$this->tmpVarCounter);
        $idx  = '_fi_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        // 推断数组元素类型
        $elemType = 't_int';
        if ($node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            $elemType = $this->arrElementTypes[$arrVarName] ?? 't_int';
            // 若数组无 int-key 元素类型追踪，尝试用 per-key 追踪默认值
            if ($elemType === 't_int' && isset($this->arrValueTypes[$arrVarName])) {
                $values = $this->arrValueTypes[$arrVarName];
                if (!empty($values)) $elemType = reset($values);
            }
            // 注解常量数组：元素类型为 tphp_class_AnnotationEntry*
            if ($elemType === 't_int' && isset($this->annotationRegistry[$arrVarName])) {
                $elemType = 'tphp_class_AnnotationEntry*';
            }
        } elseif ($node->array instanceof PropertyAccessExpr) {
            // foreach 实例属性数组：foreach ($this->prop as $v) 或 foreach ($obj->prop as $v)
            $key = $this->propArrElemKey($node->array);
            if ($key !== null && isset($this->propArrElementTypes[$key])) {
                $elemType = $this->propArrElementTypes[$key];
            }
        }
        // 规范化元素类型名
        if (str_contains($elemType, 'tphp_class_') && !str_ends_with($elemType, '*')) {
            $elemType .= '*';
        }

        // Mark vars as declared (after declaration check)
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));
        $needValDecl = !isset($this->declaredVars[$valVar]);

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = $elemType;
        // 注解常量数组：记录 $v 的来源注解名（供 $v->call() 运行时调度使用）
        if ($elemType === 'tphp_class_AnnotationEntry*' && $node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            if (isset($this->annotationRegistry[$arrVarName])) {
                $this->varAnnotSource[$valVar] = $arrVarName;
            }
        }
        // 传播嵌套类型：foreach($rows as $row) 中 $row 是数组时，记录其元素类型
        if ($elemType === 't_array*' && $node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            if (isset($this->arrNestedTypes[$arrVarName])) {
                $this->arrElementTypes[$valVar] = $this->arrNestedTypes[$arrVarName];
            } elseif (isset($this->arrElementTypes[$arrVarName]) && $this->arrElementTypes[$arrVarName] === 't_array*') {
                // 若源数组元素是数组但无嵌套类型追踪，设为 t_int
                $this->arrElementTypes[$valVar] = 't_int';
            }
        }
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            // 检测数组是否包含字符串 key
            $hasStrKey = false;
            if ($node->array instanceof VariableExpr) {
                $arrVarName = self::varName($node->array->name);
                $hasStrKey = isset($this->arrValueTypes[$arrVarName]) && !empty($this->arrValueTypes[$arrVarName]);
            }
            $keyType = $hasStrKey ? 't_string' : 't_int';
            $this->varTypes[$keyVar] = $keyType;
        }

        // 根据元素类型生成值读取代码
        $valRead = match ($elemType) {
            't_float'    => "(_eval->type == TYPE_FLOAT) ? (t_float)_eval->value._float : 0.0",
            't_string'   => "(_eval->type == TYPE_STRING) ? _eval->value._string : ((t_string){NULL, 0})",
            't_bool'     => "(_eval->type == TYPE_BOOL) ? (t_bool)_eval->value._bool : false",
            't_array*'   => "(_eval->type == TYPE_ARRAY) ? _eval->value._array : NULL",
            't_callback' => "(_eval->type == TYPE_CALLBACK) ? _eval->value._callback : ((t_callback){NULL, NULL})",
            default      => (str_contains($elemType, 'tphp_class_')
                ? "(_eval->type == TYPE_OBJECT) ? (({$elemType})_eval->value._ptr) : NULL"
                : "(_eval->type == TYPE_INT) ? (t_int)_eval->value._int : 0"),
        };

        $valDecl = match ($elemType) {
            't_float'  => 't_float',
            't_string' => 't_string',
            't_bool'   => 't_bool',
            't_array*' => 't_array*',
            't_callback' => 't_callback',
            default    => (str_contains($elemType, 'tphp_class_') ? $elemType : 't_int'),
        };

        $keyType = $keyVar ? ($this->varTypes[$keyVar] ?? 't_int') : '';
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;
        $lines = [];
        if ($needKeyDecl) {
            if ($keyType === 't_string') {
                $lines[] = "t_string {$keyVar};";
            } else {
                $lines[] = "t_int {$keyVar};";
            }
        }
        if ($needValDecl) {
            $lines[] = "{$valDecl} {$valVar};";
        }
        $lines[] = "{$startLabel}:;";
        $lines[] = "for (int {$idx} = 0; {$idx} < tphp_fn_arr_count({$arr}); {$idx}++) {";
        if ($keyVar) {
            $lines[] = $this->ind("if ({$arr} == NULL) break;");
            $lines[] = $this->ind("const t_arr_entry* _ent = &{$arr}->entries[{$idx}];");
            $lines[] = $this->ind("const t_var* _ekey = &_ent->key;");
            if ($keyType === 't_string') {
                $lines[] = $this->ind("{ {$keyVar} = (_ekey->type == TYPE_STRING) ? _ekey->value._string : ((t_string){NULL, 0}); }");
            } else {
                $lines[] = $this->ind("{ {$keyVar} = (_ekey->type == TYPE_INT) ? (t_int)_ekey->value._int : 0; }");
            }
            $lines[] = $this->ind("const t_var* _eval = &_ent->val;");
        } else {
            $lines[] = $this->ind("t_var* _eval = tphp_fn_arr_index({$arr}, {$idx});");
        }
        $lines[] = $this->ind("if (_eval == NULL) continue;");
        $lines[] = $this->ind("{$valVar} = {$valRead};");
        $this->scopeDepth++;
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $this->scopeDepth--;
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    /** 生成器 foreach：while (valid) { key/current; body; next; } */
    private function emitGeneratorForeach(ForeachStmtNode $node): string
    {
        $gExpr = $node->array->accept($this);
        $gTmp = '_gen_iter_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        $needValDecl = !isset($this->declaredVars[$valVar]);
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = 't_var';
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            $this->varTypes[$keyVar] = 't_var';
        }

        $lines = [];
        if ($needKeyDecl) $lines[] = "t_var {$keyVar};";
        if ($needValDecl) $lines[] = "t_var {$valVar};";
        $lines[] = "tphp_class_Generator* {$gTmp} = {$gExpr};";
        $lines[] = "while (tphp_class_Generator_valid({$gTmp})) {";
        if ($keyVar) {
            $lines[] = $this->ind("{$keyVar} = tphp_class_Generator_key({$gTmp});");
        }
        $lines[] = $this->ind("{$valVar} = tphp_class_Generator_current({$gTmp});");
        $this->scopeDepth++;
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $this->scopeDepth--;
        $lines[] = $this->ind("tphp_class_Generator_next({$gTmp});");
        $lines[] = '}';
        return implode("\n", $lines);
    }

    public function visitSwitchStmt(SwitchStmtNode $node): string
    {
        $condCode = $node->condition->accept($this);
        $condType = $this->inferType($node->condition);

        // 字符串 switch → 生成 if-elseif 链（C switch 不支持字符串）
        if ($condType === 't_string') {
            return $this->generateStringSwitch($condCode, $node->cases);
        }

        // int/bool switch：若含动态 case 值（变量/表达式），退化为 if-goto 链以支持 PHP 语义
        if ($this->hasDynamicCases($node->cases)) {
            return $this->generateDynamicSwitch($condCode, $node->cases);
        }

        // int/bool switch → 直接 C switch（所有 case 均为常量）
        $endLabel = '_sw_end_' . (++$this->tmpVarCounter);
        $this->loopEndLabelStack[] = $endLabel;  // switch 也压栈，支持 break N; 跳出
        $this->loopStartLabelStack[] = $endLabel;  // switch 中 continue 等价于 break
        $this->loopContLabelStack[] = $endLabel;
        $lines = [];
        $lines[] = "switch ({$condCode}) {";
        foreach ($node->cases as $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $lines[] = "case {$valCode}:";
            } else {
                $lines[] = 'default:';
            }
            foreach ($case->body as $s) {
                $lines[] = $this->ind($s->accept($this));
            }
        }
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    /**
     * 检测 int/bool switch 中是否含动态 case 值（非字面量）。
     * PHP 允许 case $var: / case foo(): 等动态值；C switch 的 case 必须是常量表达式。
     */
    private function hasDynamicCases(array $cases): bool
    {
        foreach ($cases as $case) {
            if ($case->value === null) continue;
            $v = $case->value;
            // 常量 case：整数/布尔字面量、枚举 case 访问
            if ($v instanceof IntLiteralExpr
                || $v instanceof BoolLiteralExpr
                || $v instanceof EnumAccessExpr) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * 将 int/bool switch 转为 if-goto 链，支持动态 case 值并保留 fall-through 语义。
     * 每个 case 对应一个 label；匹配则跳入，无 break 则顺序执行到下一个 case label。
     * default 单独处理：无 case 匹配时跳到 default label。
     */
    private function generateDynamicSwitch(string $condCode, array $cases): string
    {
        $lines = [];
        $swId = (++$this->tmpVarCounter);
        $endLabel = '_sw_end_' . $swId;
        $defaultLabel = '_sw_default_' . $swId;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $endLabel;  // switch 中 continue 等价于 break
        $this->loopContLabelStack[] = $endLabel;

        $hasDefault = false;
        // 1. 匹配检测：if ((cond) == (val)) goto case_label;
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "if (({$condCode}) == ({$valCode})) goto {$label};";
            } else {
                $hasDefault = true;
            }
        }
        // 无匹配 → default 或跳到结尾
        $lines[] = $hasDefault ? "goto {$defaultLabel};" : "goto {$endLabel};";

        // 2. case body：label + stmts（break → goto end，无 break 则 fall-through 到下一 case）
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "{$label}:;";
            } else {
                $lines[] = "{$defaultLabel}:;";
            }
            foreach ($case->body as $s) {
                if ($s instanceof BreakStmtNode) {
                    $lines[] = $this->ind("goto {$endLabel};");
                } else {
                    $lines[] = $this->ind($s->accept($this));
                }
            }
        }
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    /**
     * 将 switch 字符串转为 if-goto 链，保留 C switch 的 fall-through 语义。
     * 每个 case 对应一个 label；匹配则跳入，无 break 则顺序执行到下一个 case label。
     * default 单独处理：无 case 匹配时跳到 default label（default 可在任意位置）。
     */
    private function generateStringSwitch(string $condCode, array $cases): string
    {
        $lines = [];
        $swId = (++$this->tmpVarCounter);
        $endLabel = '_sw_end_' . $swId;
        $defaultLabel = '_sw_default_' . $swId;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $endLabel;  // switch 中 continue 等价于 break
        $this->loopContLabelStack[] = $endLabel;

        $hasDefault = false;
        // 1. 匹配检测：if (str_eq(cond, val)) goto case_label;
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "if (tphp_rt_str_eq({$condCode}, {$valCode})) goto {$label};";
            } else {
                $hasDefault = true;
            }
        }
        // 无匹配 → default 或跳到结尾
        $lines[] = $hasDefault ? "goto {$defaultLabel};" : "goto {$endLabel};";

        // 2. case body：label + stmts（break → goto end，无 break 则 fall-through 到下一 case）
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "{$label}:;";
            } else {
                $lines[] = "{$defaultLabel}:;";
            }
            foreach ($case->body as $s) {
                if ($s instanceof BreakStmtNode) {
                    $lines[] = $this->ind("goto {$endLabel};");
                } else {
                    $lines[] = $this->ind($s->accept($this));
                }
            }
        }
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitBreakStmt(BreakStmtNode $node): string
    {
        if ($node->level <= 1) return 'break;';
        // break N; → goto 第 N 层外层结构的 end label
        $idx = count($this->loopEndLabelStack) - $node->level;
        if ($idx < 0 || !isset($this->loopEndLabelStack[$idx])) {
            return 'break;';  // 栈不足时退化为 break（防御性）
        }
        return 'goto ' . $this->loopEndLabelStack[$idx] . ';';
    }

    public function visitContinueStmt(ContinueStmtNode $node): string
    {
        if ($node->level <= 1) return 'continue;';
        // continue N; → goto 第 N 层外层循环的 continue label（step 之前）
        $idx = count($this->loopContLabelStack) - $node->level;
        if ($idx < 0 || !isset($this->loopContLabelStack[$idx])) {
            return 'continue;';  // 栈不足时退化为 continue（防御性）
        }
        return 'goto ' . $this->loopContLabelStack[$idx] . ';';
    }

    public function visitTryStmt(TryStmtNode $node): string
    {
        $lines = [];
        $lines[] = 'TP_TRY';
        $this->scopeDepth++;
        foreach ($node->tryBody as $s) {
            $lines[] = '    ' . $s->accept($this);
        }
        $this->scopeDepth--;

        // 多 catch 子句：每个生成 TP_CATCH_EX(var, Type)
        // 最后无类型兜底用 TP_CATCH_ANY（捕获非对象异常如 tp_throw("str")）
        $hasCatch = !empty($node->catchClauses);
        $hasObjCatch = false;
        foreach ($node->catchClauses as $clause) {
            $cv = $clause['var'];
            $ct = $clause['type'];
            $this->declaredVars[$cv] = true;
            // catch 类型为已声明 class 或 Exception → 生成 TP_CATCH_EX；否则按字符串消息兜底
            $resolvedCn = $this->symbols->resolveClass($ct);
            $isClass = $resolvedCn !== null || $ct === 'Exception' || $this->symbols->hasClass('tphp_class_' . $ct);
            if ($isClass) {
                // catch 变量统一用 Exception* 类型（tp_throw_ex 已强转为 Exception*）
                // 子类特有方法暂不支持（PHP 语义中 catch 块内 $e 视为声明的类型，但 AOT 简化为基类）
                $this->varTypes[$cv] = 'tphp_class_Exception*';
                $lines[] = 'TP_CATCH_EX(' . $cv . ', ' . $ct . ')';
                $hasObjCatch = true;
            } else {
                // 未知类型 → 兜底字符串消息
                $this->varTypes[$cv] = 't_string';
                $lines[] = 'TP_CATCH_ANY(' . $cv . ')';
            }
            $this->scopeDepth++;
            foreach ($clause['body'] as $s) {
                $lines[] = '    ' . $s->accept($this);
            }
            $this->scopeDepth--;
        }

        // 有 catch 但全是对象类型：补 ANY 兜底捕获 tp_throw 的字符串异常
        if ($hasCatch && $hasObjCatch && empty($node->finallyBody)) {
            $lines[] = 'TP_CATCH_ANY(_tp_unused_msg) { (void)_tp_unused_msg; }';
        }

        if (!empty($node->finallyBody)) {
            $lines[] = 'TP_FINALLY';
            $this->scopeDepth++;
            foreach ($node->finallyBody as $s) {
                $lines[] = '    ' . $s->accept($this);
            }
            $this->scopeDepth--;
        }
        $lines[] = 'TP_END_TRY';
        return implode("\n", $lines);
    }

    public function visitThrowStmt(ThrowStmtNode $node): string
    {
        $this->checkExceptionReturnType();
        return $this->genThrowCode($node->expr) . ';';
    }

    /** 检查当前函数/方法的返回类型是否声明了 |Exception */
    private function checkExceptionReturnType(): void
    {
        $rt = $this->currentPhpRetType;
        if ($rt === '') return; // 全局作用域或未追踪的上下文，跳过
        // Main::main 是程序入口，try/catch 可捕获异常，无需 |Exception 声明
        if ($this->phpClassName === 'Main' && $this->currentMethodName === 'main') return;
        if (!str_contains($rt, '|')) {
            $fn = $this->currentFuncName !== '' ? $this->currentFuncName : ($this->currentMethodName !== '' ? $this->currentMethodName : '<anonymous>');
            throw new \LogicException(
                "Function/method '{$fn}' contains throw/error() but return type does not declare |Exception. "
                . "Expected: {$rt}|Exception, got: {$rt}"
            );
        }
        $parts = explode('|', $rt);
        $hasExc = false;
        foreach ($parts as $p) {
            if ($this->symbols->isExceptionSubclass($p)) { $hasExc = true; break; }
        }
        if (!$hasExc) {
            $fn = $this->currentFuncName !== '' ? $this->currentFuncName : ($this->currentMethodName !== '' ? $this->currentMethodName : '<anonymous>');
            throw new \LogicException(
                "Function/method '{$fn}' contains throw/error() but return type does not declare |Exception. "
                . "Expected: {$rt}|Exception, got: {$rt}"
            );
        }
    }

    /** 生成 throw 的 C 宏调用（不带分号），供 visitThrowStmt 和 visitThrowExpr 复用 */
    private function genThrowCode(ExprNode $expr): string
    {
        $code = $expr->accept($this);
        // throw new Exception(...) 或 throw new Exception子类(...) → tp_throw_ex()
        if ($expr instanceof NewExpr) {
            return "tp_throw_ex({$code})";
        }
        // throw $exceptionVar (Exception 子类类型) → tp_throw_ex
        $type = $this->inferType($expr);
        if (self::isClassCType($type) && str_ends_with($type, '*')) {
            return "tp_throw_ex({$code})";
        }
        // throw "string" → tp_throw(STR_PTR_V(msg))
        if ($type === 't_string') {
            return "tp_throw(STR_PTR_V({$code}))";
        }
        return "tp_throw((char*)(uintptr_t)(" . $code . "))";
    }

    /** throw 表达式（PHP 8.0+）：出现在表达式位置的 throw
     *  tp_throw_ex/tp_throw 是 do-while 宏，不能直接嵌入表达式位置
     *  利用 TCC 的 GNU 语句表达式扩展包装：({ throw_code; 0; })
     *  throw 永不返回，0 是死代码占位值 */
    public function visitThrowExpr(ThrowExprNode $node): string
    {
        $this->checkExceptionReturnType();
        $throwCode = $this->genThrowCode($node->expr);
        return "({ {$throwCode}; 0; })";
    }

    /**
     * 计算类的 exception_offset（Exception 子类专属）
     * @param string $cName 类的 C 名（如 tphp_class_MyException）
     * 返回 offsetof 表达式字符串（如 "offsetof(tphp_class_MyException, _parent)"），
     * 或 "0"（非 Exception 子类）
     */
    private function computeExceptionOffset(string $cName): string
    {
        if ($cName === 'tphp_class_Exception') return '0';
        // 沿继承链查找 Exception，构建 _parent._parent... 链
        $chain = [];
        $curCn = $cName;
        while ($curCn !== null && $curCn !== '') {
            $class = $this->symbols->getClass($curCn);
            if ($class === null) break;
            $parentName = $class->parent;
            if ($parentName === 'tphp_class_Exception') {
                $chain[] = '_parent';
                return 'offsetof(' . $cName . ', ' . implode('.', $chain) . ')';
            }
            if ($parentName === '' || $parentName === null) break;
            $chain[] = '_parent';
            $curCn = $parentName;
        }
        return '0';
    }

    public function visitLabelStmt(LabelStmtNode $node): string { return $node->name . ':;'; }
    public function visitGotoStmt(GotoStmtNode $node): string { return 'goto ' . $node->label . ';'; }

    // ============================================================
    // 运算符
    // ============================================================

    public function visitPostfix(PostfixExpr $node): string
    {
        $e = $node->expr->accept($this);
        return "{$e}{$node->operator}";
    }

    public function visitCompoundAssign(CompoundAssignExpr $node): string
    {
        $t = $node->target->accept($this);
        $v = $node->value->accept($this);
        // .= 是 PHP 字符串拼接赋值，C 无对应操作符，转译为 tphp_rt_str_concat
        if ($node->operator === '.=') {
            $vs = $this->castToStr($node->value);
            return "{$t} = tphp_rt_str_concat({$t}, {$vs})";
        }
        return "{$t} {$node->operator} {$v}";
    }

    // ============================================================
    private function generateCEntry(): string
    {
        $lines = [
            "/* ── C entry: main() ─────────────────────────── */",
            "int main(int argc, char* argv[]) {",
            $this->ind("tphp_rt_init();"),
        ];
        // PDO 驱动自动注册（类似 PHP MINIT，在用户代码之前）
        foreach ($this->pdoDriverInits as $initFn) {
            $lines[] = $this->ind("{$initFn}();");
        }
        // 注解常量初始化（在用户代码之前填充）
        foreach ($this->annotationInitFns as $initFn) {
            $lines[] = $this->ind("{$initFn}();");
        }
        $lines[] = $this->ind("t_array* _argv = tphp_rt_build_argv(argc, argv);");
        $lines[] = $this->ind("{$this->className}* _main = new_{$this->className}((t_int)argc, _argv);");
        $lines[] = $this->ind("if (_main == NULL) { tphp_fn_arr_free(_argv); return 1; }");
        $lines[] = $this->ind("{$this->className}_main(_main);");
        $lines[] = $this->ind("tp_obj_release(_main);");
        $lines[] = $this->ind("tphp_fn_arr_free(_argv);");
        $lines[] = $this->ind("return 0;");
        $lines[] = "}";
        return implode("\n", $lines);
    }

    /**
     * 静态属性文件作用域初始化器
     *   - 标量: ` = 42` / ` = 3.14` / ` = true`
     *   - 字符串字面量: ` = STR_LIT("hello")`
     *   - 无默认值: ``（零初始化由 C 文件作用域 static 保证）
     *   - 数组/对象: 不支持文件作用域初始化（需运行时），返回空串
     */
    private function staticPropInitializer(string $cType, PropertyDeclNode $prop): string
    {
        if ($prop->default === null) return '';
        $def = $prop->default;
        if ($cType === 't_string' && $def instanceof StringLiteralExpr) {
            $val = str_replace('"', '\\"', $def->value);
            return " = STR_LIT(\"{$val}\")";
        }
        if ($def instanceof IntLiteralExpr) {
            return " = {$def->value}";
        }
        if ($def instanceof FloatLiteralExpr) {
            $fv = $def->value;
            return ' = ' . (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
        }
        if ($def instanceof BoolLiteralExpr) {
            return ' = ' . ($def->value ? 'true' : 'false');
        }
        // 数组/对象/复杂表达式默认值：文件作用域无法初始化，留空（零初始化）
        //   用户可在构造函数或静态 init 方法中手动赋值
        return '';
    }

    // ============================================================
    private function methodDecl(MethodNode $m): string
    {
        $ret = self::mapType($m->returnType);
        $params = array_map(fn($p) => $this->visitParam($p), $m->params);
        // 静态方法签名省略 self 参数（AOT: 编译期已知，无 this 指针）
        if ($m->isStatic) {
            return "{$ret} {$this->className}_{$m->name}(" . (empty($params) ? 'void' : implode(', ', $params)) . ')';
        }
        return "{$ret} {$this->className}_{$m->name}({$this->className}* self" .
            (empty($params) ? '' : ', ' . implode(', ', $params)) . ')';
    }

    private function methodImpl(MethodNode $m): string { return $this->methodDecl($m); }

    /** 将任意表达式转为 t_int（用于 (int) 转换） */
    private function castToInt(ExprNode $expr): string
    {
        if ($expr instanceof IntLiteralExpr) return $expr->accept($this);

        if ($expr instanceof FloatLiteralExpr) {
            return '(t_int)(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof BoolLiteralExpr) {
            return $expr->value ? '1' : '0';
        }
        if ($expr instanceof NullLiteralExpr) {
            return '0';
        }
        if ($expr instanceof StringLiteralExpr) {
            return 'tphp_rt_parse_int(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return empty($expr->entries) ? '0' : '1';
        }
        if ($expr instanceof NewExpr) {
            throw new RuntimeException(
                sprintf("[%d:%d] Object cannot be converted to int", $expr->line, $expr->column)
            );
        }
        if ($expr instanceof UnaryExpr) {
            return $expr->accept($this); // -(inner) already correct int
        }
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return 'tphp_rt_parse_int(' . $expr->accept($this) . ')';
        }

        // 变量：根据类型推导
        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_int'    => $code,
                't_float'  => "(t_int)({$code})",
                't_bool'   => $code,
                't_string' => "tphp_rt_parse_int({$code})",
                'null'     => '0',
                't_array*' => "(({$vn} && tphp_fn_arr_count({$vn}) > 0) ? 1 : 0)",
                default    => throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to int", $expr->line, $expr->column)
                ),
            };
        }
        if ($expr instanceof EnumAccessExpr) {
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "tphp_rt_parse_int(({$code})->value)" : "({$code})->value";
        }

        return "(t_int)({$code})";
    }

    /** 将任意表达式转为 t_float（用于 (float) 转换） */
    private function castToFloat(ExprNode $expr): string
    {
        if ($expr instanceof FloatLiteralExpr) return $expr->accept($this);
        if ($expr instanceof IntLiteralExpr) return '(t_float)(' . $expr->accept($this) . ')';
        if ($expr instanceof BoolLiteralExpr) return $expr->value ? '1.0' : '0.0';
        if ($expr instanceof NullLiteralExpr) return '0.0';
        if ($expr instanceof StringLiteralExpr) {
            return 'tphp_rt_parse_float(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return empty($expr->entries) ? '0.0' : '1.0';
        }
        if ($expr instanceof NewExpr) {
            throw new RuntimeException(
                sprintf("[%d:%d] Object cannot be converted to float", $expr->line, $expr->column)
            );
        }
        if ($expr instanceof UnaryExpr) {
            return $expr->accept($this);
        }
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return 'tphp_rt_parse_float(' . $expr->accept($this) . ')';
        }

        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_float'  => $code,
                't_int'    => "(t_float)({$code})",
                't_bool'   => "(t_float)({$code})",
                't_string' => "tphp_rt_parse_float({$code})",
                'null'     => '0.0',
                't_array*' => "(({$vn} && tphp_fn_arr_count({$vn}) > 0) ? 1.0 : 0.0)",
                default    => throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to float", $expr->line, $expr->column)
                ),
            };
        }

        return "(t_float)({$code})";
    }

    /** 将任意表达式转为 t_bool（用于 (bool) 转换） */
    private function castToBool(ExprNode $expr): string
    {
        if ($expr instanceof BoolLiteralExpr) return $expr->accept($this);
        if ($expr instanceof IntLiteralExpr) return $expr->value ? 'true' : 'false';
        if ($expr instanceof FloatLiteralExpr) return $expr->value != 0.0 ? 'true' : 'false';
        if ($expr instanceof NullLiteralExpr) return 'false';
        if ($expr instanceof StringLiteralExpr) {
            $v = $expr->value;
            return ($v === '' || $v === '0') ? 'false' : 'true';
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return empty($expr->entries) ? 'false' : 'true';
        }
        if ($expr instanceof NewExpr) {
            return 'true'; // 任何对象转 bool 为 true
        }
        if ($expr instanceof UnaryExpr) {
            $code = $expr->accept($this);
            return "((bool)({$code}))";
        }

        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_bool'   => $code,
                't_int'    => "({$code} != 0)",
                't_float'  => "({$code} != 0.0)",
                't_string' => "!tphp_rt_str_is_falsy({$code})",
                'null'     => 'false',
                't_array*' => "({$vn} != NULL && tphp_fn_arr_count({$vn}) > 0)",
                default    => 'true', // 对象
            };
        }

        return "((bool)({$code}))";
    }

    /** 将标量/对象转为单元素数组 */
    private function castToArray(ExprNode $expr): string
    {
        if ($expr instanceof NullLiteralExpr) return 'tphp_fn_arr_create(0)';
        return 'tphp_fn_arr_from_val(' . $this->wrapVar($expr) . ')';
    }

    public function visitArrayAppend(ArrayAppendExpr $node): string
    {
        // $expr[] 在非赋值上下文无意义（PHP 中 $arr[] 单独使用会抛 Notice: Undefined variable）
        // TinyPHP 中 ArrayAppendExpr 仅用于 $expr[] = value 赋值，由 visitAssignArrayPushStmt 处理
        // 若到达此处说明语法误用
        throw new \Exception('$expr[] in expression context (expected assignment $expr[] = value)');
    }

    /**
     * 解析实例属性数组访问的元素类型注册键。
     * 支持 $this->prop（class = currentClassName）和 $obj->prop（class = $obj 的类型）。
     * 返回 "cn::prop" 或 null（无法解析时）。
     */
    private function propArrElemKey(PropertyAccessExpr $node): ?string
    {
        if (!$node->object instanceof VariableExpr) return null;
        $objName = $node->object->name;
        $prop = ltrim($node->property, '$');
        if ($objName === '$this' || $objName === 'self') {
            return $this->className . '::' . $prop;
        }
        if (str_starts_with($objName, '$')) {
            $vn = self::varName($objName);
            $objType = $this->varTypes[$vn] ?? '';
            // 对象类型形如 "tphp_class_Xxx*" → 去掉尾部 *
            $cn = rtrim($objType, '*');
            if ($cn !== '' && $this->symbols->hasClass($cn)) {
                return $cn . '::' . $prop;
            }
        }
        return null;
    }

    public function visitArrayAccess(ArrayAccessExpr $node): string
    {
        // ── 注解常量静态索引编译期展开 ──
        // ROUTE[0] → _annot_ROUTE_0（AnnotationEntry* 指针，零开销）
        if ($node->array instanceof VariableExpr
            && !str_starts_with($node->array->name, '$')
            && $node->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$node->array->name])) {
            $reg = $this->annotationRegistry[$node->array->name];
            $idx = (int)$node->index->value;
            if (isset($reg['entries'][$idx])) {
                return $reg['entryVarPrefix'] . $idx;
            }
        }

        // ── 注解常量动态索引：ROUTE[$i] → 运行时从 t_var* 解包 AnnotationEntry* ──
        if ($node->array instanceof VariableExpr
            && !str_starts_with($node->array->name, '$')
            && isset($this->annotationRegistry[$node->array->name])) {
            $reg = $this->annotationRegistry[$node->array->name];
            $arrCode = $reg['constName'];
            $idxCode = $node->index->accept($this);
            return "((tphp_class_AnnotationEntry*)tphp_fn_arr_get_int_object({$arrCode}, (t_int)({$idxCode})))";
        }

        $arr  = $node->array->accept($this);
        $idx  = $node->index->accept($this);
        $vn   = $node->array instanceof VariableExpr ? self::varName($node->array->name) : '';
        $vt   = $this->varTypes[$vn] ?? 't_int';

        // 字符串键：per-key 类型 → get_str_int/str；无记录用 get_str_str
        $idxType = $this->inferType($node->index);
        if ($idxType === 't_string' || $node->index instanceof StringLiteralExpr) {
            // per-key 类型追踪
            $keyType = $vt;
            // 链式访问 $arr[0]["key"]：优先用 AST 精确追踪，回退到嵌套类型
            if ($node->array instanceof ArrayAccessExpr) {
                // 优先：通过数组字面量 AST 精确追踪嵌套访问的叶子值类型
                // （处理混合类型关联数组：["id"=>42, "name"=>"foo"] 的 per-key 类型）
                $traced = $this->traceNestedAccessType($node);
                if ($traced !== null) {
                    $keyType = $traced;
                } else {
                    [$rootArr, $depth] = $this->resolveRootArray($node->array);
                    if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                        $keyType = $this->arrNestedTypes[$rootArr];
                    }
                }
            }
            if ($node->index instanceof StringLiteralExpr && $node->array instanceof VariableExpr) {
                $arrName = self::varName($node->array->name);
                $keyStr  = $node->index->value;
                $keyType = $this->arrValueTypes[$arrName][$keyStr] ?? null;
                // 全局查找（如 $users = $db["users"] 后，$users["alice"] 跨变量查 alice 键类型）
                if ($keyType === null) {
                    foreach ($this->arrValueTypes as $vKeys) {
                        if (isset($vKeys[$keyStr])) { $keyType = $vKeys[$keyStr]; break; }
                    }
                }
                // 未知字符串键：先查数组元素类型，再默认 string
                // （arrElementTypes 比 varType 更精确：varType 可能是 t_array*）
                $keyType ??= $this->arrElementTypes[$arrName] ?? 't_string';
            }
            return match ($keyType) {
                't_int'   => "tphp_fn_arr_get_str_int({$arr}, {$idx})",
                't_float' => "((t_float)tphp_fn_arr_get_str_int({$arr}, {$idx}))",
                't_bool'  => "(tphp_fn_arr_get_str_int({$arr}, {$idx}) != 0)",
                't_array*' => "tphp_fn_arr_get_str_arr({$arr}, {$idx})",
                default   => "tphp_fn_arr_get_str_str({$arr}, {$idx})",
            };
        }

        // 整数键：先查 arrElementTypes（对象/回调），若未记录且 vt 是基本类型则用 vt，否则默认 int
        $et = 't_int';
        if ($node->array instanceof VariableExpr) {
            $an = self::varName($node->array->name);
            if (isset($this->arrElementTypes[$an])) {
                $et = $this->arrElementTypes[$an];
                // 标准化类/枚举类型（补 *）
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            } elseif (!in_array($vt, ['t_array*', 't_int', 'null'], true)) {
                // $vt 可能是 per-key 追踪的类型（如 t_string）/ 直接 varType
                $et = $vt;
            }
        } elseif ($node->array instanceof ArrayAccessExpr) {
            // 链式访问 $arr[0][0]：向上查找根数组的嵌套类型
            [$rootArr, $depth] = $this->resolveRootArray($node->array);
            if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                // 多层嵌套：用 arrNestedDepth 判断当前深度是否到达叶子层
                if (isset($this->arrNestedDepth[$rootArr])) {
                    $nd = $this->arrNestedDepth[$rootArr];
                    // depth = 链式访问的层数（$arr[0] depth=1, $arr[0][1] depth=2, ...）
                    // nd['depth'] = 数组总深度（[1,2,3] depth=1, [[1,2]] depth=2, ...）
                    // 当 depth == nd['depth']-1 时，到达叶子层
                    if ($depth >= $nd['depth'] - 1) {
                        $et = $nd['leafType'];
                    } else {
                        $et = 't_array*';  // 中间层仍是数组
                    }
                } else {
                    $et = $this->arrNestedTypes[$rootArr];
                }
                // 标准化类/枚举类型（补 * 指针后缀）
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            }
        } elseif ($node->array instanceof PropertyAccessExpr) {
            // 实例属性数组访问：$this->prop[$key] 或 $obj->prop[$key]
            //   查 propArrElementTypes 注册表获取元素类型
            $key = $this->propArrElemKey($node->array);
            if ($key !== null && isset($this->propArrElementTypes[$key])) {
                $et = $this->propArrElementTypes[$key];
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            }
        }
        return match ($et) {
            't_int'      => "tphp_fn_arr_get_int_int({$arr}, (t_int)({$idx}))",
            't_float'    => "tphp_fn_arr_get_int_float({$arr}, (t_int)({$idx}))",
            't_string'   => "tphp_fn_arr_get_int_str({$arr}, (t_int)({$idx}))",
            't_bool'     => "tphp_fn_arr_get_int_bool({$arr}, (t_int)({$idx}))",
            't_array*'   => "tphp_fn_arr_get_int_arr({$arr}, (t_int)({$idx}))",
            't_callback' => "tphp_fn_arr_get_int_callback({$arr}, (t_int)({$idx}))",
            default      => (str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_'))
                ? "((" . $et . ")tphp_fn_arr_get_int_object({$arr}, (t_int)({$idx})))"
                : "tphp_fn_arr_get_int_int({$arr}, (t_int)({$idx}))",
        };
    }

    /** 展平 . 链为叶子节点数组，用于 ROPE 多片段拼接
     *  "a" . "b" . "c" → [StringLit("a"), StringLit("b"), StringLit("c")] */
    private function flattenConcat(BinaryExpr $node): array
    {
        $parts = [];
        $this->flattenConcatRec($node, $parts);
        return $parts;
    }

    private function flattenConcatRec(ExprNode $node, array &$parts): void
    {
        if ($node instanceof BinaryExpr && $node->operator === '.') {
            $this->flattenConcatRec($node->left, $parts);
            $this->flattenConcatRec($node->right, $parts);
        } else {
            $parts[] = $node;
        }
    }

    /** 将任意表达式转为 t_string（用于 (string) 转换和 . 拼接）
     *  @param bool $strict true=显式转换时数组/对象报错，false=.拼接时静默转 "Array"/"Object" */
    private function castToStr(ExprNode $expr, bool $strict = false): string
    {
        if ($expr instanceof StringLiteralExpr) return $expr->accept($this);
        if ($expr instanceof ArrayAccessExpr) {
            $code = $expr->accept($this);
            // 字符串键：check per-key type
            if ($this->hasStrKey($expr)) {
                // per-key 类型可能为 int，需转换
                if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $kt = $this->arrValueTypes[$at][$expr->index->value] ?? null;
                    // 全局查找 per-key 类型
                    if ($kt === null) {
                        $keyStr = $expr->index->value;
                        foreach ($this->arrValueTypes as $vKeys) {
                            if (isset($vKeys[$keyStr])) { $kt = $vKeys[$keyStr]; break; }
                        }
                    }
                    $kt ??= 't_string';
                    if ($kt === 't_int') return "tphp_rt_str_from_int({$code})";
                    if ($kt === 't_float') return "tphp_rt_str_from_float({$code})";
                }
                // 未知 per-key 类型：检查函数名判断是否需要转字符串
                if (str_contains($code, 'get_str_int') || str_contains($code, 'get_str_float')) {
                    return "tphp_rt_str_from_int({$code})";
                }
                // get_str_arr 返回 t_array*，在字符串上下文需要改用 get_str_str
                if (str_contains($code, 'get_str_arr')) {
                    $fixed = str_replace('get_str_arr', 'get_str_str', $code);
                    return $fixed;
                }
                return $code;
            }
            // 整数键：用 inferType 判断元素转 str 的方式
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => $code,
                't_float'  => "tphp_rt_str_from_float({$code})",
                default    => "tphp_rt_str_from_int({$code})",
            };
        }
        if ($expr instanceof CastExpr) {
            $code = $expr->accept($this);
            return match ($expr->castType) {
                'string' => $code,
                'float'  => "tphp_rt_str_from_float({$code})",
                default  => "tphp_rt_str_from_int({$code})",
            };
        }

        if ($expr instanceof IntLiteralExpr) {
            return 'tphp_rt_str_from_int(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof FloatLiteralExpr) {
            return 'tphp_rt_str_from_float(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof BoolLiteralExpr) {
            return $expr->value ? 'STR_LIT("1")' : 'STR_LIT("")';
        }
        if ($expr instanceof NullLiteralExpr) {
            return 'STR_LIT("")';
        }
        if ($expr instanceof MagicConstExpr) {
            return $expr->accept($this); // already t_string
        }
        if ($expr instanceof ArrayLiteralExpr) {
            if ($strict) {
                throw new RuntimeException(
                    sprintf("[%d:%d] Array cannot be converted to string", $expr->line, $expr->column)
                );
            }
            return 'STR_LIT("Array")';
        }
        if ($expr instanceof NewExpr) {
            if ($strict) {
                throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to string", $expr->line, $expr->column)
                );
            }
            return 'STR_LIT("Object")';
        }

        // 变量 / 表达式：根据推导类型转换
        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_string'   => $code,
                't_int'      => "tphp_rt_str_from_int({$code})",
                't_float'    => "tphp_rt_str_from_float({$code})",
                't_bool'     => "({$code} ? STR_LIT(\"1\") : STR_LIT(\"\"))",
                'null'       => 'STR_LIT("")',
                't_array*'   => $strict
                    ? throw new RuntimeException(sprintf("[%d:%d] Array cannot be converted to string", $expr->line, $expr->column))
                    : 'STR_LIT("Array")',
                'tphp_class_Exception*' => "tphp_class_Exception_getMessage({$code})",
                default      => $strict
                    ? throw new RuntimeException(sprintf("[%d:%d] Object cannot be converted to string", $expr->line, $expr->column))
                    : 'STR_LIT("Object")',
            };
        }

        // BinaryExpr — 根据运算符推导类型
        if ($expr instanceof BinaryExpr) {
            if ($expr->operator === '.') return $code; // 已经是 t_string
            return "tphp_rt_str_from_int({$code})";
        }

        // PropertyAccessExpr：查找属性类型（复用 getPropType）
        if ($expr instanceof PropertyAccessExpr) {
            $pt = $this->getPropType($expr);
            if ($pt === 't_string') return $code;
            if ($pt === 't_float') return "tphp_rt_str_from_float({$code})";
        }

        // CallExpr：查找返回类型（内置函数 + 方法调用 + 枚举方法）
        if ($expr instanceof CallExpr) {
            // 内置函数返回 t_string（date 等）
            if ($expr->callee === null) {
                $rt = $this->inferCallReturnType($expr);
                if ($rt === 't_string') return $code;
                if ($rt === 't_float') return "tphp_rt_str_from_float({$code})";
            }
            // 枚举方法调用（静态 Color::cases() 或实例 Color::Red->label()）
            $enumCName = null;
            if ($expr->callee instanceof EnumAccessExpr) {
                $enumCName = $this->symbols->getEnumCName($expr->callee->enumName);
            } elseif ($expr->callee instanceof VariableExpr) {
                $enumCName = $this->symbols->resolveEnumCName($expr->callee->name);
            } elseif ($expr->callee instanceof CallExpr) {
                $chain = $this->inferCallChainClass($expr->callee);
                $enumCName = $this->symbols->resolveEnumCName(rtrim($chain, '*'));
            }
            if ($enumCName !== null) {
                $mi = $this->symbols->getEnumMethodByCName($enumCName, $expr->name);
                if ($mi !== null) {
                    if ($mi->retType === 't_string') return $code;
                    if ($mi->retType === 't_float') return "tphp_rt_str_from_float({$code})";
                }
            }
            // 方法调用
            if ($expr->callee !== null) {
                $objKey = ($expr->callee instanceof VariableExpr) ? self::varName($expr->callee->name) : '';
                $objType = ($objKey === '$this' || $objKey === 'self')
                    ? $this->className
                    : ($this->varTypes[$objKey] ?? '');
                $objClean = rtrim($objType, '*'); // COS objects always have *
                // 静态方法调用 ClassName::method() — 解析类名
                if ($objClean === '' && $expr->callee instanceof VariableExpr) {
                    $resolved = $this->symbols->resolveClass($expr->callee->name);
                    if ($resolved !== null) $objClean = $resolved;
                }
                if ($objClean !== '') {
                    $mInfo = $this->symbols->getClassMethod($objClean, $expr->name);
                    if ($mInfo !== null) {
                        $retType = $mInfo->retType;
                        if ($retType === 't_string') return $code;
                        if ($retType === 't_float') return "tphp_rt_str_from_float({$code})";
                    }
                }
            }
        }

        // EnumAccessExpr → case 访问取 ->value 转 str；常量访问按声明类型转换
        if ($expr instanceof EnumAccessExpr) {
            // 常量访问 → 按声明类型转换
            if (!$this->symbols->hasEnumCase($expr->enumName, $expr->caseName)) {
                $ct = $this->symbols->getEnumConstType($expr->enumName, $expr->caseName);
                if ($ct === 't_string') return $code;
                if ($ct === 't_float') return "tphp_rt_str_from_float({$code})";
                return "tphp_rt_str_from_int({$code})";
            }
            // case 访问 → 用 ->value 取值后转字符串
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "({$code})->value" : "tphp_rt_str_from_int(({$code})->value)";
        }

        // MatchExpr → 查 inferType 决定如何转字符串
        if ($expr instanceof MatchExpr) {
            $bt = $this->inferType($expr);
            return ($bt === 't_string') ? $code : "tphp_rt_str_from_int({$code})";
        }

        // TernaryExpr → 查 inferType 决定如何转字符串
        if ($expr instanceof TernaryExpr) {
            $bt = $this->inferType($expr);
            if ($bt === 't_string') return $code;
            if ($bt === 't_float') return "tphp_rt_str_from_float({$code})";
            return "tphp_rt_str_from_int({$code})";
        }

        // ArrayAccessExpr：字符串键读取返回 t_string
        if ($expr instanceof ArrayAccessExpr) {
            $idxType = $this->inferType($expr->index);
            if ($idxType === 't_string' || $expr->index instanceof StringLiteralExpr) {
                return $code;  // tphp_fn_arr_get_str_str 已返回 t_string
            }
        }

        // TPHP_CONST_ 常量引用 → #define 可能展开为 STR_LIT，直接返回
        if (str_starts_with($code, 'TPHP_CONST_')) {
            return $code;
        }

        // 其他表达式（CallExpr 等）默认假设返回 int
        return "tphp_rt_str_from_int({$code})";
    }

    /** 将表达式值包装为 VAR_XXX 宏（用于 mixed/union 变量赋值） */
    private function wrapTvarAssign(ExprNode $expr, string $code): string
    {
        if ($expr instanceof StringLiteralExpr)  return "VAR_STRING({$code})";
        if ($expr instanceof IntLiteralExpr)     return "VAR_INT({$code})";
        if ($expr instanceof FloatLiteralExpr)   return "VAR_FLOAT({$code})";
        if ($expr instanceof BoolLiteralExpr)    return "VAR_BOOL({$code})";
        if ($expr instanceof NullLiteralExpr)    return "VAR_NULL()";
        if ($expr instanceof ArrayLiteralExpr)   return "VAR_ARRAY({$code})";
        if ($expr instanceof ClosureExpr)        return "VAR_CALLBACK({$code})";
        if ($expr instanceof NewExpr)            return "VAR_OBJ({$code})";
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            // 常量引用（不以 $ 开头）→ 加 TPHP_CONST_ 前缀
            $isConst = !str_starts_with($expr->name, '$');
            $ref = $isConst ? ('TPHP_CONST_' . strtoupper($vn)) : $vn;
            $vt = $this->varTypes[$vn] ?? 't_int';
            // t_var→t_var 直接赋值，无需包裹
            if ($vt === 't_var') return $code;
            return match ($vt) {
                't_int'      => "VAR_INT({$ref})",
                't_float'    => "VAR_FLOAT({$ref})",
                't_string'   => "VAR_STRING({$ref})",
                't_bool'     => "VAR_BOOL({$ref})",
                't_array*'   => "VAR_ARRAY({$ref})",
                't_callback' => "VAR_CALLBACK({$ref})",
                'null'       => "VAR_NULL()",
                default      => (str_contains($vt, 'tphp_class_') || str_contains($vt, 'tphp_enum_'))
                    ? "VAR_OBJ({$ref})"
                    : "VAR_INT({$code})",
            };
        }
        // 复杂表达式：用 inferType 动态推导类型
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return "VAR_STRING({$code})";
        }
        if ($expr instanceof CastExpr) {
            return match ($expr->castType) {
                'bool'   => "VAR_BOOL({$code})",
                'string' => "VAR_STRING({$code})",
                'int'    => "VAR_INT({$code})",
                'float'  => "VAR_FLOAT({$code})",
                'array'  => "VAR_ARRAY({$code})",
                default  => "VAR_INT({$code})",
            };
        }
        // BinaryExpr/TernaryExpr/CallExpr/EnumAccessExpr/MatchExpr/UnaryExpr 等
        $type = $this->inferType($expr);
        return match ($type) {
            't_string'   => "VAR_STRING({$code})",
            't_float'    => "VAR_FLOAT({$code})",
            't_bool'     => "VAR_BOOL({$code})",
            't_array*'   => "VAR_ARRAY({$code})",
            't_callback' => "VAR_CALLBACK({$code})",
            't_var'      => $code,
            'null'       => "VAR_NULL()",
            default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                ? "VAR_OBJ({$code})"
                : "VAR_INT({$code})",
        };
    }

    /** 读取 t_var 变量的值，按预期类型提取 */
    private function readVar(string $var, string $expectType): string
    {
        return match ($expectType) {
            't_int'    => "VAR_AS_INT({$var})",
            't_float'  => "VAR_AS_FLOAT({$var})",
            't_string' => "VAR_AS_STRING({$var})",
            't_bool'   => "VAR_AS_BOOL({$var})",
            default    => $var, // fallback: raw t_var
        };
    }

    /** 如果表达式是 t_var 变量，按期望类型解包 */
    private function unwrapIfMixed(ExprNode $expr, string $code, string $expectType): string
    {
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                return $this->readVar($vn, $expectType);
            }
        }
        return $code;
    }

    /** 查询方法第 $idx 个参数的 C 类型 */
    private function getMethodParamType(CallExpr $call, int $idx): string
    {
        if ($call->callee === null) return '';
        // 查找方法所属类
        $cn = '';
        if ($call->callee instanceof VariableExpr) {
            $key = self::varName($call->callee->name);
            $raw = $this->varTypes[$key] ?? '';
            if (self::isClassCType($raw)) $cn = rtrim($raw, '*');
        } elseif ($call->callee instanceof CallExpr) {
            // 链式调用递归
            return '';
        }
        if ($cn === '' && $call->callee instanceof VariableExpr && self::varName($call->callee->name) === 'self') {
            $cn = $this->className;
        }
        // 静态方法调用：PHP 类名 → C 类名解析（如 Thread → tphp_class_Thread）
        if ($cn === '' && $call->callee instanceof VariableExpr) {
            $resolved = $this->symbols->resolveClass($call->callee->name);
            if ($resolved !== null) $cn = $resolved;
        }
        if ($cn !== '') {
            $mInfo = $this->symbols->getClassMethod($cn, $call->name);
            if ($mInfo !== null) {
                return $mInfo->paramTypes[$idx] ?? '';
            }
        }
        return '';
    }

    public function mapType(string $t): string {
        if ($t === 'self') return $this->className . '*';
        if ($t === 'mixed') return 't_var';
        if ($t === 'callable') return 't_callback';
        // Type|Exception 语法：|Exception 为文档提示，C 仅生成 Type
        if (str_contains($t, '|')) {
            $parts = explode('|', $t);
            $nonExc = array_filter($parts, fn($p) => !$this->symbols->isExceptionSubclass($p));
            if (count($nonExc) === 1) {
                return $this->mapType(reset($nonExc));
            }
            return 't_var'; // 纯联合类型 → t_var
        }
        // C 类型: C.IDENTIFIER — 借鉴 vlang 的 C 命名空间设计
        //   C.X 直接直译为 C 类型 X: C.int→int, C.float→double, C.char→char, C.void→void
        //   指针用 * 后缀: C.void*→void*, C.char*→char*, C.int*→int*, C.Point*→Point*
        //   不再使用 _ptr 别名（C. 前缀就是 C 的类型）
        if (str_starts_with($t, 'C.')) {
            $ct = substr($t, 2);
            // 解析指针后缀: C.void* => void*, C.char** => char**
            $stars = '';
            while (str_ends_with($ct, '*')) {
                $stars .= '*';
                $ct = substr($ct, 0, -1);
            }
            $base = match ($ct) {
                'int' => 'int',
                'int32' => 'int32_t', 'int64' => 'int64_t',
                'uint32' => 'uint32_t', 'uint64' => 'uint64_t',
                'float', 'double' => 'double',
                'char' => 'char',
                'void' => 'void',
                'bool' => 'bool',
                default => $ct,  // 结构体名: C.Point => Point
            };
            return $base . $stars;
        }
        // 枚举类型 → 返回 C struct 指针类型
        $enumCType = $this->symbols->getEnumCType($t);
        if ($enumCType !== null) {
            return $enumCType;
        }
        // 用户定义的类名 → tphp_class_XXX*
        $resolved = $this->symbols->resolveClass($t);
        if ($resolved !== null) {
            return $resolved . '*';
        }
        return self::$typeMap[$t] ?? "{$t}*";
    }
    public static function varName(string $v): string { return $v === '$this' ? 'self' : ltrim($v, '$'); }

    /** 解析类型到 C 类型（参数类型用；联合类型 | → t_var） */
    private static function resolveType(string $type): string {
        if (str_contains($type, '|')) return 't_var';
        if ($type === 'callable') return 't_callback';
        // C 类型: C.IDENTIFIER — 直接映射为对应 C 类型（C. 前缀就是 C 的类型）
        //   C.int→int, C.void*→void*, C.Point→Point, C.Point*→Point*
        if (str_starts_with($type, 'C.')) {
            $ct = substr($type, 2);
            // 解析指针后缀
            $stars = '';
            while (str_ends_with($ct, '*')) {
                $stars .= '*';
                $ct = substr($ct, 0, -1);
            }
            $base = match ($ct) {
                'int' => 'int',
                'int32' => 'int32_t', 'int64' => 'int64_t',
                'uint32' => 'uint32_t', 'uint64' => 'uint64_t',
                'float', 'double' => 'double',
                'char' => 'char',
                'void' => 'void',
                'bool' => 'bool',
                default => $ct,  // 结构体名: C.Point => Point
            };
            return $base . $stars;
        }
        return self::$typeMap[$type] ?? ('tphp_class_' . $type . '*');
    }

    /** 生成参数声明的 C 类型 + 变量名（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramDecl(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        return $p->byRef ? "{$ct} *" . self::varName($p->name) : "{$ct} " . self::varName($p->name);
    }

    /** 生成默认参数值的 C 代码 — 对 null 字面量按目标 C 类型转换为合适的零值 */
    private function defaultExprCode(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        if ($p->default instanceof NullLiteralExpr) {
            return match ($ct) {
                't_callback' => '(t_callback){NULL, NULL}',
                't_string'   => '(t_string){NULL, 0}',
                default      => 'NULL',
            };
        }
        return $p->default->accept($this);
    }

    /** 参数在 varTypes 中的 C 类型（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramCType(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        return $p->byRef ? "{$ct}*" : $ct;
    }

    /** 参数 C 类型（实例方法版：通过 mapType 解析命名空间类名）
     *  resolveType 是静态方法，无法解析 use 导入的命名空间类（如 User → tphp_na_NS_tphp_class_User），
     *  varTypes 和参数 struct 字段必须用此方法才能正确解析跨命名空间类引用 */
    private function paramCTypeResolved(ParamNode $p): string {
        $ct = $this->mapType($p->type);
        return $p->byRef ? "{$ct}*" : $ct;
    }

    /** 如果变量是 byRef 类型，生成写目标（*var） */
    private function varWrite(string $var, string $type): string {
        if ($this->isByRefType($type)) return "(*{$var})";
        return $var;
    }

    // 是否 byRef 指针类型（int* / t_string* / t_array** / tphp_class_X** 等）
    private function isByRefType(string $type): bool {
        if ($type === 'void*') return false;
        // C 类型指针（Point*, char*, FILE* 等）不是 byRef，直接传递
        // 只有 TinyPHP 内部值类型的指针才是 byRef
        if (!str_starts_with($type, 't_') && !str_starts_with($type, 'tphp_')) return false;
        // 值类型的指针：t_int*, t_float*, t_string*, t_bool* → byRef
        // 指针类型的双指针：t_array**, tphp_class_X**, tphp_enum_X** → byRef
        if (str_ends_with($type, '**')) return true;
        if (str_starts_with($type, 't_array') && str_ends_with($type, '*')) return false;
        // str_contains 而非 str_starts_with：命名空间类名 tphp_na_NS_tphp_class_X* 也需排除
        if (str_contains($type, 'tphp_class_') && str_ends_with($type, '*')) return false;
        if (str_contains($type, 'tphp_enum_') && str_ends_with($type, '*')) return false;
        return str_ends_with($type, '*');
    }

    /** 预扫描递归收集闭包的 capDefs（不生成代码，只注册类型） */
    private function collectCapDefs(StmtNode $stmt): void
    {
        if ($stmt instanceof IfStmtNode) {
            foreach ($stmt->thenBody as $s) $this->collectCapDefs($s);
            foreach ($stmt->elseifs as $eif) {
                foreach ($eif->body as $s) $this->collectCapDefs($s);
            }
            foreach ($stmt->elseBody as $s) $this->collectCapDefs($s);
        } elseif ($stmt instanceof WhileStmtNode || $stmt instanceof ForStmtNode || $stmt instanceof ForeachStmtNode) {
            foreach ($stmt->body as $s) $this->collectCapDefs($s);
        } elseif ($stmt instanceof DoWhileStmtNode) {
            foreach ($stmt->body as $s) $this->collectCapDefs($s);
        } elseif ($stmt instanceof SwitchStmtNode) {
            foreach ($stmt->cases as $c) {
                foreach ($c->body as $s) $this->collectCapDefs($s);
            }
        } elseif ($stmt instanceof ExprStmtNode || $stmt instanceof AssignStmtNode || $stmt instanceof EchoStmtNode) {
            $this->collectCapDefsExpr($stmt);
        }
    }

    private function collectCapDefsExpr(StmtNode $stmt): void
    {
        $expr = null;
        if ($stmt instanceof ExprStmtNode) $expr = $stmt->expr;
        elseif ($stmt instanceof AssignStmtNode) $expr = $stmt->expr;
        elseif ($stmt instanceof EchoStmtNode && !empty($stmt->exprs)) $expr = $stmt->exprs[0];

        if ($expr instanceof ClosureExpr && !empty($expr->useVars)) {
            $id = ++$this->capTypeCounter;
            $capFields = [];
            foreach ($expr->useVars as [$vn, $_]) {
                $ct = $this->varTypes[$vn] ?? 't_int';
                $ct = ($ct === 'null') ? 'void*' : $ct;
                $capFields[] = "    {$ct} {$vn};";
            }
            $this->sectionBlock(self::SEC_CAPTYPES,
                "typedef struct {\n" . implode("\n", $capFields) . "\n} _cap_{$id};");
        }
    }

    /** 查询枚举名对应的 backing 类型 ('int'|'string') */
    private function enumBackingType(string $name): string {
        return $this->symbols->getEnumBacking($name);
    }

    /** 将 PHP 命名空间名转为 C 标识符: Demo\Foo → Demo_Foo */
    public static function mangleCName(string $name): string {
        return str_replace('\\', '_', $name);
    }

    /** 从类节点获取 C 标识符
     *  全局类: tphp_class_ClassName
     *  命名空间类: tphp_na_Namespace_tphp_class_ClassName */
    private static function classCName(ClassNode $class): string {
        if ($class->namespace === '') {
            return 'tphp_class_' . $class->name;
        }
        return 'tphp_na_' . self::mangleCName($class->namespace) . '_tphp_class_' . $class->name;
    }

    /** 从已解析类名生成 C 引用名（visitNew/Call 等非 ClassNode 上下文中使用） */
    /** Resolve which class owns a method (for COS inheritance) */
    private function resolveMethodClass(string $cn, string $method): string
    {
        // P3-3: 编译期缓存，避免重复线性扫描父类链
        $cacheKey = $cn . "\0" . $method;
        if (isset($this->methodClassCache[$cacheKey])) {
            return $this->methodClassCache[$cacheKey];
        }
        $cur = $cn;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            if ($this->symbols->getClassMethod($cur, $method) !== null) {
                $this->methodClassCache[$cacheKey] = $cur;
                return $cur;
            }
        }
        $this->methodClassCache[$cacheKey] = '';
        return '';
    }

    /** Resolve property prefix for COS inheritance: _parent._parent. */
    private function resolvePropPrefix(string $cn, string $prop): string
    {
        $prefix = '';
        $cur = $cn;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            if ($this->symbols->hasClassOwnProp($cur, $prop)) {
                return $prefix;
            }
            $prefix .= '_parent.';
        }
        return $prefix; // fallback: try outermost parent
    }

    /** 查找属性的 hook 信息（遍历父类链）
     *  返回 ['cn' => 声明类CName, 'get' => bool, 'set' => bool, 'type' => C类型] 或 null */
    private function resolveHookInfo(string $cn, string $prop): ?array
    {
        $cur = $cn;
        while ($cur !== '') {
            if (isset($this->hookedProps[$cur][$prop])) {
                $info = $this->hookedProps[$cur][$prop];
                $info['cn'] = $cur;
                return $info;
            }
            $cur = $this->symbols->hasClass($cur) ? $this->symbols->getClassParent($cur) : '';
        }
        return null;
    }

    /** 从已解析类名生成 C 引用名
     *  全局类: tphp_class_ClassName
     *  命名空间类: tphp_na_Namespace_tphp_class_ClassName */
    private static function classRefName(string $resolvedName): string {
        $pos = strrpos($resolvedName, '\\');
        if ($pos === false) {
            return 'tphp_class_' . $resolvedName;
        }
        $ns = substr($resolvedName, 0, $pos);
        $cls = substr($resolvedName, $pos + 1);
        return 'tphp_na_' . self::mangleCName($ns) . '_tphp_class_' . $cls;
    }

    /** 从函数节点获取 C 标识符
     *  全局函数: tphp_fn_functionName
     *  命名空间函数: tphp_na_Namespace_tphp_fn_functionName */
    private static function funcCName(FunctionNode $fn): string {
        if ($fn->namespace === '') {
            return 'tphp_fn_' . $fn->name;
        }
        return 'tphp_na_' . self::mangleCName($fn->namespace) . '_tphp_fn_' . $fn->name;
    }

    /** 从 CallExpr 推导 C 函数名（与 funcCName 格式一致）
     *  全局函数: tphp_fn_functionName
     *  命名空间函数: tphp_na_Namespace_tphp_fn_functionName */
    private static function funcCNameFromCall(CallExpr $expr): string {
        if ($expr->callee !== null) return '';  // 方法调用不在此
        // $expr->name 是 FQ 名（如 "Phpc\map_with_closure"）
        $pos = strrpos($expr->name, '\\');
        if ($pos !== false) {
            $ns = substr($expr->name, 0, $pos);
            $fn = substr($expr->name, $pos + 1);
            return 'tphp_na_' . self::mangleCName($ns) . '_tphp_fn_' . $fn;
        }
        return 'tphp_fn_' . $expr->name;
    }

    /**
     * 生成导出函数 trampoline + 共享库自动初始化（-shared 模式）
     *
     * #[Export("name")] function fn(params): ret { ... }
     * → TPHP_EXPORT ret name(params) { return tphp_fn_fn(params); }
     *
     * 验证:
     *   - 仅独立函数可导出（方法上报错）
     *   - 参数/返回值不能是 array
     *   - 导出名必须是合法 C 标识符且全局唯一
     */
    private function emitExports(ProgramNode $node): string
    {
        if (!$this->isShared) return '';

        $exports = [];
        $seenNames = [];

        // 1. 检查方法上的 #[Export] — 报错（仅独立函数可导出）
        $allClasses = array_merge(
            $node->mainClass ? [$node->mainClass] : [],
            $node->extraClasses
        );
        foreach ($allClasses as $class) {
            foreach ($class->methods as $m) {
                foreach ($m->attributes as $attr) {
                    if ($this->isExportAttr($attr)) {
                        $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
                        throw new \RuntimeException(
                            "#[Export] can only be used on standalone functions, not method {$classFq}::{$m->name}"
                        );
                    }
                }
            }
        }

        // 2. 收集独立函数上的 #[Export]
        foreach ($node->functions as $fn) {
            foreach ($fn->attributes as $attr) {
                if (!$this->isExportAttr($attr)) continue;

                if (empty($attr->args)) {
                    throw new \RuntimeException("#[Export] requires a string argument: #[Export(\"name\")]");
                }
                $arg = $attr->args[0];
                if (!($arg instanceof StringLiteralExpr)) {
                    throw new \RuntimeException("#[Export] argument must be a string literal");
                }
                $exportName = $arg->value;

                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $exportName)) {
                    throw new \RuntimeException(
                        "Invalid export name '{$exportName}': must be a valid C identifier"
                    );
                }
                if (isset($seenNames[$exportName])) {
                    throw new \RuntimeException("Duplicate export name '{$exportName}'");
                }
                $seenNames[$exportName] = true;

                if ($fn->returnType === 'array') {
                    throw new \RuntimeException(
                        "#[Export] function {$fn->name} return type cannot be array"
                    );
                }
                foreach ($fn->params as $p) {
                    if ($p->type === 'array') {
                        throw new \RuntimeException(
                            "#[Export] function {$fn->name} parameter {$p->name} type cannot be array"
                        );
                    }
                }

                $exports[] = ['fn' => $fn, 'exportName' => $exportName];
            }
        }

        if (empty($exports)) return '';

        // 生成 C 代码
        $lines = [];
        $lines[] = "/* ── Exported functions (-shared mode) ──────────── */";
        $lines[] = '';
        $lines[] = '#if defined(_WIN32)';
        $lines[] = '  #define TPHP_EXPORT __declspec(dllexport)';
        $lines[] = '#else';
        $lines[] = '  #define TPHP_EXPORT __attribute__((visibility("default")))';
        $lines[] = '#endif';
        $lines[] = '';

        // Trampoline 函数
        foreach ($exports as $e) {
            $fn = $e['fn'];
            $exportName = $e['exportName'];
            $fnCName = self::funcCName($fn);
            $retCType = self::mapType($fn->returnType);

            $params = [];
            $args = [];
            foreach ($fn->params as $p) {
                $cType = $this->paramCTypeResolved($p);
                $varName = ltrim($p->name, '$');
                $params[] = "{$cType} {$varName}";
                $args[] = $varName;
            }
            $paramStr = empty($params) ? 'void' : implode(', ', $params);

            $lines[] = "TPHP_EXPORT {$retCType} {$exportName}({$paramStr}) {";
            if ($fn->returnType === 'void') {
                $lines[] = "    {$fnCName}(" . implode(', ', $args) . ");";
            } else {
                $lines[] = "    return {$fnCName}(" . implode(', ', $args) . ");";
            }
            $lines[] = "}";
            $lines[] = '';
        }

        // 共享库 runtime 自动初始化
        $lines[] = '/* ── Shared library runtime auto-init ── */';
        $lines[] = '#if defined(_WIN32)';
        $lines[] = '#include <windows.h>';
        $lines[] = 'BOOL WINAPI DllMain(HINSTANCE _hinst, DWORD _fdwReason, LPVOID _lpvReserved) {';
        $lines[] = '    if (_fdwReason == DLL_PROCESS_ATTACH) {';
        $lines[] = '        tphp_rt_init();';
        foreach ($this->annotationInitFns as $initFn) {
            $lines[] = "        {$initFn}();";
        }
        $lines[] = '    }';
        $lines[] = '    return TRUE;';
        $lines[] = '}';
        $lines[] = '#else';
        $lines[] = '__attribute__((constructor))';
        $lines[] = 'static void _tphp_shared_init(void) {';
        $lines[] = '    tphp_rt_init();';
        foreach ($this->annotationInitFns as $initFn) {
            $lines[] = "    {$initFn}();";
        }
        $lines[] = '}';
        $lines[] = '#endif';

        return implode("\n", $lines);
    }

    /** 检查是否为 #[Export] 注解 */
    private function isExportAttr(AttributeUseNode $attr): bool
    {
        return $attr->name === 'Export' || $attr->name === '\\Export';
    }

    private function indentStr(): string { return str_repeat('    ', $this->indent); }
    private function ind(string $l): string { return $this->indentStr() . $l; }
}
