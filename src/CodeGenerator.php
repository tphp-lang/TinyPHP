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
    /** 变量可空性追踪：varName → true 表示可能为 null（来自 nullsafe ?-> 或 tryFrom()） */
    private array $varNullable = [];
    /** 类型窄化追踪：varName → tphp_class_X（来自 if ($v instanceof X) 分支内）
     *  用于 t_var 变量在 instanceof 分支内访问属性时生成正确的 C 类型转换 */
    private array $narrowedTypes = [];
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
    /** 当前函数/方法的可变参数名（不含 $），空字符串表示当前不在可变参数函数内 */
    private string $currentVariadicParamName = '';
    /** 当前可变参数的元素 PHP 类型（如 'int'/'string'/'float'/''=mixed），用于 func_get_arg 类型化访问 */
    private string $currentVariadicElementType = '';
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

    /** 目标 OS（null = 宿主平台，'android' 时生成 tphp_android_main 而非 main） */
    public ?string $targetOS = null;

    /** 字面量 → C 类型的映射 */
    private static array $litTypeMap = [
        IntLiteralExpr::class    => 't_int',
        FloatLiteralExpr::class  => 't_float',
        StringLiteralExpr::class => 't_string',
        BoolLiteralExpr::class   => 't_bool',
        MagicConstExpr::class    => 't_string',
    ];

    /**
     * 内置 Exception 子类列表（与 TypeChecker::registerBuiltinClasses 保持一致）
     *   这些类无额外字段，继承 Exception 的 message；CodeGenerator 自动生成 C 结构体和分配器
     *   用途：throw new RuntimeException(...) / match 未匹配抛 UnhandledMatchError
     */
    public const BUILTIN_EXCEPTION_SUBCLASSES = [
        'RuntimeException', 'LogicException', 'InvalidArgumentException',
        'TypeError', 'ValueError', 'Error', 'UnhandledMatchError',
        'OutOfRangeException', 'RangeException', 'DomainException',
        'LengthException', 'OverflowException', 'UnderflowException',
        'UnexpectedValueException',
    ];

    private static array $typeMap = [
        'int' => 't_int', 'float' => 't_float', 'string' => 't_string',
        'bool' => 't_bool', 'void' => 'void', 'never' => 'void', 'array' => 't_array*',
        'mixed' => 't_var', 'null' => 'void*',
        'Generator' => 'tphp_class_Generator*',
        'Channel' => 'tphp_class_Channel*',
        'Future'  => 'tphp_class_Future*',
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
        'chan_select' => 't_int',
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
        'cstr_to_string' => 't_string',
        'random_bytes' => 't_string', 'gettype' => 't_string',
        // ── 文件路径 (内置) ──
        'dirname' => 't_string', 'basename' => 't_string',
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
        'get_object_vars' => 't_array*',
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
        'openssl_digest' => 't_string', 'openssl_ssl_read' => 't_string',
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
        // ── ui (内置 ext, 图形 UI 扩展, 基于 sokol) ──
        //   sapp_event* 指针以 t_int 句柄形式流转（intptr_t 转换）
        //   ui 扩展使用 function C.xxx(...): C.ret; 声明 + C->xxx() 调用模式
        //   C 函数签名在 ext/ui/src/ui.php 中声明，无需在此注册
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
        // ── pgsql (内置 ext, PostgreSQL 协议) ──
        //   pgsql 函数在 ext/pgsql/src/pgsql.php 中以 PHP 包装函数声明，
        //   注册返回类型用于编译期类型推导（查表优先于 SymbolTable）
        //   指针/句柄以 t_int 流转，const char* 通过 php_str() 转为 t_string
        'pg_connect' => 't_int', 'pg_pconnect' => 't_int',
        'pg_connection_status' => 't_int', 'pg_connection_reset' => 't_bool',
        'pg_ping' => 't_bool',
        'pg_query' => 't_int', 'pg_query_params' => 't_int',
        'pg_prepare' => 't_int', 'pg_execute' => 't_int',
        'pg_close' => 't_bool', 'pg_free_result' => 'void',
        'pg_num_rows' => 't_int', 'pg_num_fields' => 't_int',
        'pg_affected_rows' => 't_int', 'pg_last_oid' => 't_int',
        'pg_field_num' => 't_int', 'pg_field_type_oid' => 't_int',
        'pg_field_size' => 't_int', 'pg_field_prtlen' => 't_int',
        'pg_field_table' => 't_int', 'pg_field_is_null' => 't_bool',
        'pg_field_name' => 't_string', 'pg_field_type' => 't_string',
        'pg_fetch_result_str' => 't_string',
        'pg_result_status' => 't_int', 'pg_result_status_str' => 't_string',
        'pg_result_seek' => 't_bool',
        'pg_result_error' => 't_string', 'pg_result_error_field' => 't_string',
        'pg_last_error' => 't_string', 'pg_last_notice' => 't_string',
        'pg_dbname' => 't_string', 'pg_host' => 't_string', 'pg_tty' => 't_string',
        'pg_options' => 't_string', 'pg_parameter_status' => 't_string',
        'pg_client_encoding' => 't_string',
        'pg_port' => 't_int', 'pg_transaction_status' => 't_int',
        'pg_set_client_encoding' => 't_int',
        'pg_version' => 't_array*', 'pg_fetch_row' => 't_array*',
        'pg_fetch_assoc' => 't_array*', 'pg_fetch_array' => 't_array*',
        'pg_fetch_all' => 't_array*', 'pg_fetch_all_columns' => 't_array*',
        'pg_copy_to' => 't_array*',
        'pg_meta_data' => 't_array*', 'pg_convert' => 't_array*',
        'pg_select' => 't_array*',
        'pg_escape_string' => 't_string', 'pg_escape_literal' => 't_string',
        'pg_escape_identifier' => 't_string', 'pg_escape_bytea' => 't_string',
        'pg_unescape_bytea' => 't_string',
        'pg_copy_from' => 't_bool', 'pg_put_copy_data' => 't_bool',
        'pg_put_copy_end' => 't_bool', 'pg_end_copy' => 't_bool',
        'pg_insert_result' => 't_int', 'pg_update_result' => 't_int',
        'pg_delete_result' => 't_int',
        'pg_insert_sql' => 't_string', 'pg_update_sql' => 't_string',
        'pg_delete_sql' => 't_string',
        'pg_lo_create' => 't_int', 'pg_lo_open' => 't_int',
        'pg_lo_write' => 't_int', 'pg_lo_seek' => 't_int',
        'pg_lo_tell' => 't_int', 'pg_lo_import' => 't_int',
        'pg_lo_read' => 't_string', 'pg_lo_read_all' => 't_string',
        'pg_lo_truncate' => 't_bool', 'pg_lo_unlink' => 't_bool',
        'pg_lo_export' => 't_bool', 'pg_lo_close' => 'void',
        'pg_set_notice_callback' => 'void',
        // ── pdo_pgsql (内置 ext, PostgreSQL PDO 驱动) ──
        //   PHP 层包装函数（pdo_pgsql_get_pid / get_notify / pgconn）
        'pdo_pgsql_get_pid' => 't_int', 'pdo_pgsql_pgconn' => 't_int',
        'pdo_pgsql_get_notify' => 't_array*',
        // ── pgsql C 包装函数（_pg_*，由 ext/pgsql/src/pgsql.php 的 PHP 包装函数体内调用）──
        //   注册返回类型用于编译期类型推导（PHP 包装函数体内调用 _pg_* 时查表）
        //   指针/句柄以 t_int 流转，t_array* 为堆分配数组（PHP 层判断 NULL 返回空数组）
        '_pg_connect' => 't_int', '_pg_pconnect' => 't_int',
        '_pg_close' => 't_bool',
        '_pg_connection_status' => 't_int', '_pg_connection_reset' => 't_bool',
        '_pg_ping' => 't_bool',
        '_pg_query' => 't_int', '_pg_query_params' => 't_int',
        '_pg_prepare' => 't_int', '_pg_execute' => 't_int',
        '_pg_free_result' => 'void',
        '_pg_num_rows' => 't_int', '_pg_num_fields' => 't_int',
        '_pg_affected_rows' => 't_int', '_pg_last_oid' => 't_int',
        '_pg_field_name' => 't_string', '_pg_field_num' => 't_int',
        '_pg_field_type' => 't_string', '_pg_field_type_oid' => 't_int',
        '_pg_field_size' => 't_int', '_pg_field_prtlen' => 't_int',
        '_pg_field_is_null' => 't_bool', '_pg_field_table' => 't_int',
        '_pg_fetch_row' => 't_array*', '_pg_fetch_assoc' => 't_array*',
        '_pg_fetch_array' => 't_array*', '_pg_fetch_all' => 't_array*',
        '_pg_fetch_all_columns' => 't_array*',
        '_pg_fetch_result' => 't_string',
        '_pg_result_status' => 't_int', '_pg_result_status_str' => 't_string',
        '_pg_result_seek' => 't_bool',
        '_pg_result_error' => 't_string', '_pg_result_error_field' => 't_string',
        '_pg_last_error' => 't_string', '_pg_last_notice' => 't_string',
        '_pg_dbname' => 't_string', '_pg_host' => 't_string',
        '_pg_port' => 't_int', '_pg_options' => 't_string',
        '_pg_tty' => 't_string', '_pg_version' => 't_array*',
        '_pg_parameter_status' => 't_string',
        '_pg_transaction_status' => 't_int',
        '_pg_client_encoding' => 't_string',
        '_pg_set_client_encoding' => 't_int',
        '_pg_escape_string' => 't_string', '_pg_escape_literal' => 't_string',
        '_pg_escape_identifier' => 't_string', '_pg_escape_bytea' => 't_string',
        '_pg_unescape_bytea' => 't_string',
        '_pg_copy_to' => 't_array*', '_pg_copy_from' => 't_bool',
        '_pg_put_copy_data' => 't_bool', '_pg_put_copy_end' => 't_bool',
        '_pg_end_copy' => 't_bool',
        '_pg_meta_data' => 't_array*', '_pg_convert' => 't_array*',
        '_pg_insert_result' => 't_int', '_pg_insert_sql' => 't_string',
        '_pg_update_result' => 't_int', '_pg_update_sql' => 't_string',
        '_pg_delete_result' => 't_int', '_pg_delete_sql' => 't_string',
        '_pg_select' => 't_array*',
        '_pg_lo_create' => 't_int', '_pg_lo_open' => 't_int',
        '_pg_lo_read' => 't_string', '_pg_lo_write' => 't_int',
        '_pg_lo_seek' => 't_int', '_pg_lo_tell' => 't_int',
        '_pg_lo_truncate' => 't_bool', '_pg_lo_close' => 'void',
        '_pg_lo_unlink' => 't_bool', '_pg_lo_import' => 't_int',
        '_pg_lo_export' => 't_bool', '_pg_lo_read_all' => 't_string',
        '_pg_set_notice_callback' => 'void',
        // ── pdo_pgsql C 包装函数（_pgpdo_*，由 ext/pdo_pgsql/src/pdo_pgsql.php 的 PHP 包装函数体内调用）──
        '_pgpdo_get_pid' => 't_int', '_pgpdo_pgconn' => 't_int',
        '_pgpdo_get_notify' => 't_array*',
        'phpc_new_arr_int' => 't_array*', 'phpc_new_arr_dbl' => 't_array*',
        'phpc_new_arr_str' => 't_array*', 'phpc_new_arr' => 't_array*',
        // ── 跨线程数组标记 API（thread-array-support spec Task 10）──
        //   用户/CodeGenerator 可显式调用，将数组升级为跨线程安全形态（is_shared=1）。
        //   返回值为 same pointer（便于链式调用），返回值通常忽略。
        //   每种特化数组有对应的 make_shared 函数（entry 布局不同，不能通用）。
        'tphp_fn_arr_make_shared' => 't_array*',
        'tphp_fn_arr_var_make_shared' => 't_arr_var*',
        'tphp_fn_arr_int_make_shared' => 't_arr_int*',
        'tphp_fn_arr_str_make_shared' => 't_arr_str*',
        'tphp_fn_arr_float_make_shared' => 't_arr_float*',
        'tphp_fn_arr_bool_make_shared' => 't_arr_bool*',
        'tphp_fn_arr_ptr_make_shared' => 't_arr_ptr*',
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
        'cstr_to_string' => 't_string',
        'c_void_ptr' => 'void*',
    ];

    /** 内置函数返回数组的元素类型注册表（替代 visitAssign 中的 switch-case） */
    private static array $builtinArrElemTypes = [
        'array_keys' => 't_int', 'array_values' => 't_int', 'array_merge' => 't_int',
        'explode' => 't_string', 'preg_match' => 't_string', 'preg_split' => 't_string',
        'preg_grep' => 't_string', 'filter_list' => 't_string',
        'gzfile' => 't_string',
        'stream_socket_pair' => 't_int',
        // ── 返回 array<mixed>（元素统一为 t_var）的函数 ──
        //   array_fill: value 通过 wraparr 包装为 VAR_* 存入数组，元素统一 t_var
        //   array_column: 列值通过 tphp_fn_arr_push(out, val) 存入，元素统一 t_var
        //   不注册则默认 t_int，访问字符串/浮点值会调用 typed getter 返回 0
        'array_fill' => 't_var',
        'array_column' => 't_var',
        // array_count_values: 值是出现次数（int），键是原值（int 或 string）
        'array_count_values' => 't_int',
        // ── 字符串数组函数（元素统一为 VAR_STRING 存储）──
        //   str_split/parse_str/parse_url/iconv_get_encoding 内部用 VAR_STRING 包装
        //   存入数组，访问元素应走 t_string 路径（tphp_fn_arr_get_int_str 会检查
        //   v->type == TYPE_STRING 并返回 v->value._string）。
        //   未注册则默认 t_int，var_dump($arr[0]) 输出 int(0) 而非 string(N) "..."
        'str_split' => 't_string',
        'parse_str' => 't_string',
        'parse_url' => 't_string',
        'iconv_get_encoding' => 't_string',
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
        // ── zip 函数返回数组的元素类型 ──
        //   zip_stat() 返回 array<mixed>（name=string, size/comp_method=int, 全部以 t_var 存储）
        'zip_stat' => 't_var',
        //   zip_read() 返回 array<array<mixed>>（外层是 t_var 持有 VAR_ARRAY，内层同 zip_stat）
        'zip_read' => 't_var',
        // ── pgsql 函数返回数组的元素类型 ──
        //   pg_fetch_row/assoc/array() 返回 array<string>（单行列值，C 层以 VAR_STRING 存储）
        'pg_fetch_row'    => 't_string',
        'pg_fetch_assoc'  => 't_string',
        'pg_fetch_array'  => 't_string',
        'pg_fetch_all_columns' => 't_string',
        //   pg_fetch_all() 返回 array<array<string>>（外层是行数组，内层是列值字符串）
        'pg_fetch_all'    => 't_array*',
        'pg_fetch_all[]'  => 't_string',
        //   pg_version() 返回 array<string>（client/protocol/server 版本字符串）
        'pg_version'      => 't_string',
        //   pg_copy_to() 返回 array<string>（每行一个字符串）
        'pg_copy_to'      => 't_string',
        //   pg_meta_data() 返回 array<array<mixed>>（外层是字段数组，内层是字段属性）
        'pg_meta_data'    => 't_array*',
        'pg_meta_data[]'  => 't_var',
        //   pg_convert() 返回 array<mixed>（转换后的字段值，int/string 混合）
        'pg_convert'      => 't_var',
        //   pg_select() 返回 array<array<mixed>>（外层是行数组，内层是字段值）
        'pg_select'       => 't_array*',
        'pg_select[]'     => 't_var',
        // ── pdo_pgsql 函数返回数组的元素类型 ──
        //   pdo_pgsql_get_notify() 返回 array<int|string>（pid/channel/message，混合类型）
        'pdo_pgsql_get_notify' => 't_var',
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
        // ── chan_select（多通道多路复用）──
        'chan_select'        => ['cName' => 'tphp_fn_chan_select', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '((t_int)-1)']],
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
        'microtime'          => ['cName' => 'tphp_fn_microtime'],
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
        'array_diff'         => ['cName' => 'tphp_fn_arr_diff', 'modes' => ['direct', 'direct'], 'variadic_n' => 'tphp_fn_arr_diff_n'],
        'array_intersect'    => ['cName' => 'tphp_fn_arr_intersect', 'modes' => ['direct', 'direct'], 'variadic_n' => 'tphp_fn_arr_intersect_n'],
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
        'stream_socket_server'        => ['cName' => 'tphp_fn_stream_socket_server', 'modes' => ['direct', 'direct', 'direct'], 'defaults' => [1 => '12', 2 => '0']],
        'stream_socket_accept'        => ['cName' => 'tphp_fn_stream_socket_accept', 'modes' => ['direct', 'direct'], 'defaults' => [1 => '-1']],
        'stream_socket_client'        => ['cName' => 'tphp_fn_stream_socket_client', 'modes' => ['direct', 'direct', 'direct', 'direct'], 'defaults' => [1 => '-1', 2 => '2', 3 => '0']],
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
        // ── ui (内置 ext, 图形 UI 扩展, 基于 sokol) ──
        //   ui 扩展使用 function C.xxx(...): C.ret; 声明 + C->xxx() 调用模式
        //   C 函数签名在 ext/ui/src/ui.php 中声明，无需在此注册
    ];

    /** 临时变量计数器，用于数组字面量的复合表达式 */
    private int $tmpVarCounter = 0;

    /** 闭包函数计数器 */
    private int $closureCounter = 0;
    /** 捕获类型计数器（用于预扫描阶段的唯一 ID 分配） */
    private int $capTypeCounter = 0;

    /** 最近一次 visitCallableConvert 产生的闭包签名名（供 visitAssignStmt 绑定变量）
     *  null 表示当前表达式非 CallableConvertExpr 或无需绑定 */
    private ?string $lastCallableConvertSig = null;

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
        // 确保 Type 静态实例已初始化（tphp.php 直接调用 CodeGenerator 时不经过 TypeChecker）
        Type::init();
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
    // trait 编译期扁平化（与 TypeChecker::flattenTraits 逻辑一致）
    // ============================================================

    /**
     * 收集所有 trait 定义，对使用 trait 的非 trait 类扁平化 trait 成员
     *
     * @param ClassNode[] $allClasses
     */
    private function flattenTraitsInAllClasses(array $allClasses): void
    {
        // 1. 收集 trait 定义：trait 名（短名/FQ 名）=> ClassNode
        $traitDefs = [];
        foreach ($allClasses as $class) {
            if ($class->isTrait) {
                $traitDefs[$class->name] = $class;
                if ($class->namespace !== '') {
                    $traitDefs[$class->namespace . '\\' . $class->name] = $class;
                }
            }
        }
        if (empty($traitDefs)) {
            return;
        }

        // 2. 对使用 trait 的类扁平化
        foreach ($allClasses as $class) {
            if ($class->isTrait || empty($class->traits)) {
                continue;
            }
            $this->flattenTraitsForClass($class, $traitDefs);
        }
    }

    /**
     * 编译期扁平化 trait：将 trait 的 methods/properties/classConsts 复制到类
     *
     * PHP trait 语义（AOT 实现，零运行时开销）：
     *   - 类自身方法优先于 trait 方法（覆盖）
     *   - 多 trait 同名方法必须用 insteadof 解决冲突，否则报错
     *   - insteadof: `B::foo insteadof A` 表示 foo 排除 A 的版本，使用 B 的版本
     *   - as 别名: `A::bar as baz` 创建 A::bar 的克隆，重命名为 baz
     *
     * @param ClassNode $class 使用 trait 的类
     * @param array<string,ClassNode> $traitDefs trait 定义映射
     */
    private function flattenTraitsForClass(ClassNode $class, array $traitDefs): void
    {
        // 收集类自身已定义的成员名（类定义优先）
        $selfMethods = [];
        foreach ($class->methods as $m) {
            $selfMethods[$m->name] = true;
        }
        $selfProps = [];
        foreach ($class->properties as $p) {
            $selfProps[$p->name] = true;
        }
        $selfConsts = [];
        foreach ($class->classConsts as $c) {
            $selfConsts[$c->name] = true;
        }
        // 检测 TypeChecker 是否已扁平化 trait（属性已合并到 $class->properties）
        //   若已合并，则跳过 CodeGenerator 的重复扁平化，避免误报属性冲突
        $alreadyFlattened = false;
        foreach ($class->traits as $traitName) {
            $trait = $this->resolveTraitDef($traitName, $class->namespace, $traitDefs);
            if ($trait === null) continue;
            foreach ($trait->properties as $p) {
                if (isset($selfProps[$p->name])) {
                    $alreadyFlattened = true;
                    break 2;
                }
            }
        }
        if ($alreadyFlattened) {
            return;
        }

        $insteadof = $class->traitAdaptations['insteadof'] ?? [];
        $asRules = $class->traitAdaptations['as'] ?? [];

        $traitMethodOwners = []; // methodName => [traitName, ...]
        $newMethods = [];
        $newProps = [];
        $newConsts = [];

        foreach ($class->traits as $traitName) {
            $trait = $this->resolveTraitDef($traitName, $class->namespace, $traitDefs);
            if ($trait === null) {
                throw new \RuntimeException("Trait '{$traitName}' not found (used by class {$class->name})");
            }

            // 复制方法（应用 insteadof 排除规则 + as 别名规则）
            // 注意：as 别名独立于 insteadof 排除 — 即使主方法被 insteadof 排除，
            //       其 as 别名仍应创建（PHP 语义：as 是独立的方法复制操作）
            foreach ($trait->methods as $m) {
                $mName = $m->name;
                $excluded = isset($insteadof[$mName]) && in_array($traitName, $insteadof[$mName], true);

                // as 别名规则：TraitName::method as alias
                //   先于 insteadof 排除处理，确保别名独立创建
                //   MethodNode 全字段 readonly，必须重建实例（不能反射改 name）
                $asKey = "{$traitName}::{$mName}";
                if (isset($asRules[$asKey]['alias'])) {
                    $alias = $asRules[$asKey]['alias'];
                    if (!isset($selfMethods[$alias])) {
                        $aliasMethod = new MethodNode(
                            $alias,
                            $m->visibility,
                            $m->params,
                            $m->returnType,
                            $m->body,
                            $m->promoted,
                            $m->isGenerator,
                            $m->isStatic,
                            $m->isFinal,
                            $m->attributes,
                        );
                        $newMethods[] = $aliasMethod;
                        $selfMethods[$alias] = true;
                    }
                }

                // 主方法：类自身覆盖 + insteadof 排除
                if (isset($selfMethods[$mName]) || $excluded) {
                    continue;
                }

                $traitMethodOwners[$mName][] = $traitName;

                // 去重：避免重复加入同名方法
                $exists = false;
                foreach ($newMethods as $existing) {
                    if ($existing->name === $mName) { $exists = true; break; }
                }
                if (!$exists) {
                    $newMethods[] = $m;
                }
            }

            // 复制属性（冲突时报错）
            foreach ($trait->properties as $p) {
                if (isset($selfProps[$p->name])) {
                    throw new \RuntimeException("Trait '{$traitName}' property '{$p->name}' conflicts with class {$class->name} property");
                }
                foreach ($newProps as $existing) {
                    if ($existing->name === $p->name) {
                        throw new \RuntimeException("Trait '{$traitName}' property '{$p->name}' conflicts with previous trait property");
                    }
                }
                $newProps[] = $p;
                $selfProps[$p->name] = true;
            }

            // 复制类常量（冲突时报错）
            foreach ($trait->classConsts as $c) {
                if (isset($selfConsts[$c->name])) {
                    throw new \RuntimeException("Trait '{$traitName}' constant '{$c->name}' conflicts with class {$class->name} constant");
                }
                foreach ($newConsts as $existing) {
                    if ($existing->name === $c->name) {
                        throw new \RuntimeException("Trait '{$traitName}' constant '{$c->name}' conflicts with previous trait constant");
                    }
                }
                $newConsts[] = $c;
                $selfConsts[$c->name] = true;
            }
        }

        // 冲突检测：同一方法来自多个 trait 且未用 insteadof 解决 → 报错
        foreach ($traitMethodOwners as $mName => $owners) {
            if (count($owners) > 1) {
                throw new \RuntimeException("Trait method '{$mName}' conflict between traits (" . implode(', ', $owners) . "); use 'insteadof' to resolve");
            }
        }

        // 合并到类
        $class->methods = array_merge($class->methods, $newMethods);
        $class->properties = array_merge($class->properties, $newProps);
        $class->classConsts = array_merge($class->classConsts, $newConsts);
    }

    /** 解析 trait 名（支持短名、FQ 名、命名空间内引用） */
    private function resolveTraitDef(string $name, string $currentNamespace, array $traitDefs): ?ClassNode
    {
        if (isset($traitDefs[$name])) {
            return $traitDefs[$name];
        }
        if ($currentNamespace !== '' && isset($traitDefs[$currentNamespace . '\\' . $name])) {
            return $traitDefs[$currentNamespace . '\\' . $name];
        }
        $trimmed = ltrim($name, '\\');
        if (isset($traitDefs[$trimmed])) {
            return $traitDefs[$trimmed];
        }
        return null;
    }

    public function visitProgram(ProgramNode $node): string
    {
        $this->indent = 0;
        $this->resetState();
        $this->preScanGenerators($node);
        $this->preScanCFunctions($node);
        $this->program = $node;

        // 收集 #callback 声明
        foreach ($node->callbacks as $cb) {
            $this->phpcCallbackSigs[$cb['name']] = $cb;
        }

        // 收集 C 结构体声明（#cstruct 指令 + struct C.Foo{} 声明）：
        //   结构体名 → 字段列表 [['type'=>'C.double','name'=>'x'], ...]
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

        // 检测是否使用了 pgsql 函数（自动引入 ext/pgsql 头文件链）
        //   用户通过 #import pgsql 引入 PHP 包装函数；本检测覆盖以下场景：
        //   1. 用户使用 raw C 调用（_pg_*）但未 #import pgsql
        //   2. 用户 #import pgsql 后 .h 链已由 .php 中的 #include 引入（本检测跳过，避免重复）
        $pgsqlFns = ['pg_connect(', 'pg_pconnect(', 'pg_close(', 'pg_connection_status(',
                     'pg_connection_reset(', 'pg_ping(',
                     'pg_query(', 'pg_query_params(', 'pg_prepare(', 'pg_execute(',
                     'pg_free_result(',
                     'pg_num_rows(', 'pg_num_fields(', 'pg_affected_rows(', 'pg_last_oid(',
                     'pg_field_name(', 'pg_field_num(', 'pg_field_type(', 'pg_field_type_oid(',
                     'pg_field_size(', 'pg_field_prtlen(', 'pg_field_is_null(', 'pg_field_table(',
                     'pg_fetch_row(', 'pg_fetch_assoc(', 'pg_fetch_array(', 'pg_fetch_all(',
                     'pg_fetch_all_columns(', 'pg_fetch_result_str(',
                     'pg_result_status(', 'pg_result_status_str(', 'pg_result_seek(',
                     'pg_result_error(', 'pg_result_error_field(',
                     'pg_last_error(', 'pg_last_notice(',
                     'pg_dbname(', 'pg_host(', 'pg_port(', 'pg_options(', 'pg_tty(',
                     'pg_version(', 'pg_parameter_status(', 'pg_transaction_status(',
                     'pg_client_encoding(', 'pg_set_client_encoding(',
                     'pg_escape_string(', 'pg_escape_literal(', 'pg_escape_identifier(',
                     'pg_escape_bytea(', 'pg_unescape_bytea(',
                     'pg_copy_to(', 'pg_copy_from(', 'pg_put_copy_data(', 'pg_put_copy_end(',
                     'pg_end_copy(',
                     'pg_meta_data(', 'pg_convert(', 'pg_insert_result(', 'pg_insert_sql(',
                     'pg_update_result(', 'pg_update_sql(', 'pg_delete_result(', 'pg_delete_sql(',
                     'pg_select(',
                     'pg_lo_create(', 'pg_lo_open(', 'pg_lo_read(', 'pg_lo_write(',
                     'pg_lo_seek(', 'pg_lo_tell(', 'pg_lo_truncate(', 'pg_lo_close(',
                     'pg_lo_unlink(', 'pg_lo_import(', 'pg_lo_export(', 'pg_lo_read_all(',
                     'pg_set_notice_callback('];
        $needPgsql = false;
        if ($src !== false) {
            foreach ($pgsqlFns as $fn) {
                if (str_contains($src, $fn)) { $needPgsql = true; break; }
            }
        }

        // 检测是否使用了 PDO pgsql DSN（自动链接 pdo_pgsql 驱动）
        //   用户通过 new PDO("pgsql:host=...") 使用，无需显式 #import pdo_pgsql
        //   DSN 前缀 "pgsql:" 和 "postgresql:" 都匹配（pdo_find_driver 按 drv->name 查找）
        $needPdoPgsql = false;
        if ($src !== false) {
            if (str_contains($src, '"pgsql:') || str_contains($src, "'pgsql:")
                || str_contains($src, '"postgresql:') || str_contains($src, "'postgresql:")) {
                $needPdoPgsql = true;
                $needPgsql = true;  // pdo_pgsql 依赖 ext/pgsql 协议实现
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
            // ext/ 和 os/ 路径的 #include 放在 common.h 之后：
            //   - ext/ 头文件依赖 common.h 的前向声明
            //   - os/ 头文件（zlib.h/zip.h 等）依赖 common.h 的 types.h/exception.h
            if (str_contains($normalized, '/ext/') || str_starts_with($normalized, 'os/') || str_contains($normalized, '/os/')) {
                $userIncAfter[] = $inc;
            } else {
                $userIncBefore[] = $inc;
            }
        }
        // 检测已导入的 PDO 驱动扩展，记录 C init 函数（在 main() 入口自动调用）
        //   类似 PHP MINIT：用户只需 #import pdo/pdo_mysql/pdo_sqlite，
        //   CodeGenerator 自动注入对应驱动的注册调用，不依赖 __attribute__((constructor))
        //   多驱动共存无冲突：pdo_register_driver 有去重逻辑，按 DSN 前缀分发
        foreach ($userIncAfter as $inc) {
            $f = str_replace('\\', '/', is_array($inc) ? ($inc['file'] ?? '') : $inc);
            if (str_contains($f, 'pdo/pdo.h')) {
                $this->pdoDriverInits[] = 'tphp_fn_pdo_sqlite_init';
            } elseif (str_contains($f, 'pdo_mysql/pdo_mysql.h')) {
                $this->pdoDriverInits[] = 'tphp_fn_pdo_mysql_init';
            } elseif (str_contains($f, 'pdo_pgsql/pdo_pgsql.h')) {
                $this->pdoDriverInits[] = '_pgpdo_init';
            }
        }
        // 检查 pgsql/pdo_pgsql 是否已通过 #import 引入（避免重复 #include）
        //   #import pgsql     → ext/pgsql/src/pgsql.php 中 #include pgsql.h 链
        //   #import pdo_pgsql → ext/pdo_pgsql/src/pdo_pgsql.php 中 #include pdo_pgsql.h
        $hasPgsqlInc = false;
        $hasPdoPgsqlInc = false;
        foreach ($userIncAfter as $inc) {
            $f = str_replace('\\', '/', is_array($inc) ? ($inc['file'] ?? '') : $inc);
            if (str_contains($f, 'pgsql/pgsql.h')) $hasPgsqlInc = true;
            if (str_contains($f, 'pdo_pgsql/pdo_pgsql.h')) $hasPdoPgsqlInc = true;
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
        // ── pgsql 自动 #include（检测到 pg_* 函数调用但未 #import pgsql 时）──
        //   按依赖顺序包含头文件链（与 ext/pgsql/src/pgsql.php 中的顺序一致）：
        //     stream.h → pgsql.h → pg_crypto.h → pgsql_protocol.h → pgsql_query.h
        //     → pgsql_result.h → pgsql_misc.h → pgsql_copy.h → pgsql_dml.h
        //     → pgsql_lo.h → pgsql_pconnect.h → pgsql_notice.h
        //   放在 $userIncAfter 之后：确保用户 #import 的 openssl.h（若已引入）
        //   先于 stream.h include（openssl.h 定义 TPHP_STREAM_TLS_IMPLEMENTED 覆盖 stream.h stub）
        //   头文件有 #pragma once，重复 #include 安全（用户已 #import pgsql 时本块跳过）
        if ($needPgsql && !$hasPgsqlInc) {
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/stream/src/stream.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pg_crypto.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_protocol.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_query.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_result.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_misc.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_copy.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_dml.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_lo.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_pconnect.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pgsql/pgsql_notice.h"');
        }
        // ── pdo_pgsql 自动 #include（检测到 "pgsql:"/"postgresql:" DSN 但未 #import pdo_pgsql 时）──
        //   依赖 pgsql.h 链（上方已引入）+ pdo_driver.h + pdo_pgsql.h
        //   同时注册驱动 init 函数（类似 #import pdo_pgsql 的效果）
        if ($needPdoPgsql && !$hasPdoPgsqlInc) {
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pdo/pdo_driver.h"');
            $this->sectionLine(self::SEC_INCLUDES, '#include "ext/pdo_pgsql/pdo_pgsql.h"');
            // 注册 PDO pgsql 驱动 init（在 main() 入口自动调用 _pgpdo_init）
            if (!in_array('_pgpdo_init', $this->pdoDriverInits, true)) {
                $this->pdoDriverInits[] = '_pgpdo_init';
            }
        }
        if ($needExtra) {
            $this->sectionLine(self::SEC_INCLUDES, '#include "builtin_extra.h"');
        }

        // ── SEC_CONSTS ──
        // PHP 预定义常量（无条件的编译期 #define，零运行时开销）
        $isWin = PHP_OS_FAMILY === 'Windows';
        $phpOs = $isWin ? 'WINNT' : (PHP_OS_FAMILY === 'Darwin' ? 'Darwin' : 'Linux');
        $phpOsFamily = PHP_OS_FAMILY === 'Darwin' ? 'Darwin' : PHP_OS_FAMILY; // Windows/Linux/Darwin
        $eol = $isWin ? '\r\n' : '\n';
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_EOL STR_LIT("' . $eol . '")');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_OS STR_LIT("' . $phpOs . '")');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_OS_FAMILY STR_LIT("' . $phpOsFamily . '")');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_SAPI STR_LIT("cli")');
        // 从 TPHP_VERSION 拆分主版本和后缀：0.2.0-beta.10 → PHP_VERSION=0.2.0, PHP_EXTRA_VERSION=-beta.10
        $fullVer = defined('TPHP_VERSION') ? TPHP_VERSION : '0.0.0';
        $dashPos = strpos($fullVer, '-');
        $phpVer = $dashPos !== false ? substr($fullVer, 0, $dashPos) : $fullVer;
        $phpExtraVer = $dashPos !== false ? substr($fullVer, $dashPos) : '';
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_VERSION STR_LIT("' . $phpVer . '")');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_EXTRA_VERSION STR_LIT("' . $phpExtraVer . '")');
        // 整数常量
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_INT_MAX INT64_MAX');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_INT_MIN INT64_MIN');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_INT_SIZE 8');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_MAJOR_VERSION 0');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_MINOR_VERSION 2');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_RELEASE_VERSION 0');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_VERSION_ID 20000');
        // 浮点常量
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_FLOAT_MAX DBL_MAX');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_FLOAT_MIN DBL_MIN');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_FLOAT_EPSILON DBL_EPSILON');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PHP_FLOAT_DIG DBL_DIG');
        // 错误级别常量
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_ERROR 1');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_WARNING 2');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_PARSE 4');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_NOTICE 8');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_CORE_ERROR 16');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_CORE_WARNING 32');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_COMPILE_ERROR 64');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_COMPILE_WARNING 128');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_USER_ERROR 256');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_USER_WARNING 512');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_USER_NOTICE 1024');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_STRICT 2048');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_RECOVERABLE_ERROR 4096');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_DEPRECATED 8192');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_USER_DEPRECATED 16384');
        $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_E_ALL 32767');

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
        // trait 编译期扁平化：将 trait 的 methods/properties/classConsts 复制到使用 trait 的类
        // （必须在 emitClassForward 之前完成，确保 SymbolTable 注册的是扁平化后的成员）
        $this->flattenTraitsInAllClasses($allClasses);
        // trait 自身不生成 C 结构体（编译期已扁平化到使用 trait 的类）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
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
                // 接口也需要前向声明（当作为参数类型使用时，如 Widget* w）
                $cn = self::classCName($class);
                $this->sectionLine(self::SEC_CLSFWDS, "typedef struct {$cn} {$cn};");
                continue;
            }
            $cn = self::classCName($class);
            $this->sectionLine(self::SEC_CLSFWDS, "typedef struct {$cn} {$cn};");
        }
        // 内置 Exception 子类前向声明（typedef struct cn cn;）
        foreach (self::BUILTIN_EXCEPTION_SUBCLASSES as $name) {
            $cn = 'tphp_class_' . $name;
            $this->sectionLine(self::SEC_CLSFWDS, "typedef struct {$cn} {$cn};");
        }
        $this->sectionLine(self::SEC_CLSFWDS, '');
        foreach ($allClasses as $class) {
            $this->className = self::classCName($class);
            $this->phpClassName = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            $isMain = (self::classCName($class) === $mainClassName);
            $this->sectionBlock(self::SEC_CLSFWDS, $this->emitClassForward($class, $isMain));
        }
        // 内置 Exception 子类结构体定义（继承 Exception，无额外字段）
        $this->sectionBlock(self::SEC_CLSFWDS, $this->emitBuiltinExceptionForward());

        // ── SEC_FUNCFWDS: 独立函数前置声明 ──
        foreach ($node->functions as $fn) {
            // C 函数签名声明不生成 C 代码（仅类型信息）
            if ($fn->isCDeclaration) continue;
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
            $paramNames = array_map(fn($p) => ltrim($p->name, '$'), $fn->params);
            // 检测最后一个参数是否为可变参数
            $isVariadic = !empty($fn->params) && $fn->params[count($fn->params) - 1]->isVariadic;
            $this->symbols->addFunc($fnCName, new FunctionInfo(
                $ret,
                $paramTypes,
                $defaultCount,
                $totalParams,
                $isGen,
                $paramNames,
                $isVariadic,
            ));
            $params = array_map(fn($p) => $this->visitParam($p), $fn->params);
            // 跳过已在 C 头文件中定义的函数的前置声明（避免 static storage 重定义警告）
            //   场景：ext/pgsql 的 PHP 包装函数（pg_connect 等）的 C 名（_pg_connect）
            //   与 C 头文件中定义的 C 函数同名，C 函数已在 #include 的头文件中声明/定义
            if (!isset(self::$builtinRetTypes[$fnCName])) {
                $this->sectionLine(self::SEC_FUNCFWDS,
                    'static ' . $ret . ' ' . $fnCName . '(' . implode(', ', $params) . ');');
            }
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
            // C 函数签名声明不生成 C 代码（仅类型信息）
            if ($fn->isCDeclaration) continue;
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
        // 内置 Exception 子类类描述符前向声明
        foreach (self::BUILTIN_EXCEPTION_SUBCLASSES as $name) {
            $clsFwdDecls[] = "static const t_class _class_tphp_class_{$name};";
        }
        if (!empty($clsFwdDecls)) {
            $this->sectionBlock(self::SEC_CLSIMPL, "/* ── Class descriptor forward declarations ── */\n" . implode("\n", $clsFwdDecls) . "\n");
        }
        // ── 预扫描：填充 fnReturnArrKeyTypes，确保跨类方法调用返回数组的 per-key 类型可用
        //   解决 "Main 使用 KdHelper::getData() 返回数组" 但 KdHelper 在 Main 之后处理的问题
        //   （拓扑排序仅处理父子关系，不处理使用关系）
        $this->prescanReturnArrKeyTypes($allClasses);
        foreach ($allClasses as $class) {
            $this->className = self::classCName($class);
            $this->phpClassName = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            $isMain = (self::classCName($class) === $mainClassName);
            $this->sectionBlock(self::SEC_CLSIMPL, $this->emitClassImpl($class, $isMain));
        }
        // 内置 Exception 子类实现（类描述符 + 构造函数 + 分配器）
        $this->sectionBlock(self::SEC_CLSIMPL, $this->emitBuiltinExceptionImpl());

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

    /** 预扫描所有类方法和独立函数的 return 语句，填充 fnReturnArrKeyTypes
     *  确保跨类/跨函数方法调用返回数组的 per-key 类型在任何调用点都可用，
     *  解决 "调用者先于被调用者处理" 的顺序依赖问题。
     *  仅处理 `return ["key" => val, ...]` 直接返回数组字面量的情况。
     *  @param ClassNode[] $allClasses 拓扑排序后的所有类 */
    private function prescanReturnArrKeyTypes(array $allClasses): void
    {
        foreach ($allClasses as $class) {
            $cn = self::classCName($class);
            foreach ($class->methods as $m) {
                $funcCName = $cn . '_' . $m->name;
                if ($m->body === null) continue;   // 接口/抽象方法无 body
                foreach ($m->body as $stmt) {
                    if ($stmt instanceof ReturnStmtNode && $stmt->expr instanceof ArrayLiteralExpr) {
                        foreach ($stmt->expr->entries as $entry) {
                            if ($entry->key instanceof StringLiteralExpr) {
                                $valType = $this->inferType($entry->value);
                                // 预扫描阶段 varTypes 为空，VariableExpr 若无 inferredType
                                // 会回退为 t_int（inferType 默认），这会导致 string 变量
                                // 被误注册为 t_int，进而生成 arr_get_str_int 读取器。
                                // 跳过此类不可靠推导，让调用点回退到默认 t_string。
                                if ($valType !== 'null'
                                    && !($entry->value instanceof VariableExpr
                                        && $entry->value->inferredType === null
                                        && $valType === 't_int')) {
                                    $this->fnReturnArrKeyTypes[$funcCName][$entry->key->value] = $valType;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** 预扫描 C 函数签名声明（vlang 风格 function C.foo(...): C.ret;）
     *  将声明的 C 函数签名注册到 SymbolTable，供 inferCallReturnType / visitCall 使用。
     *  C 声明不会生成任何 C 代码（无前向声明、无函数体），仅作为类型信息。 */
    private function preScanCFunctions(ProgramNode $node): void
    {
        foreach ($node->functions as $fn) {
            if (!$fn->isCDeclaration) continue;
            // 名称格式: 'C.' + 函数名 → 剥前缀得到原始 C 函数名
            $rawName = substr($fn->name, 2);
            $retType = self::cTypeToRaw($fn->returnType);
            $paramTypes = array_map(fn($p) => self::cTypeToRaw($p->type), $fn->params);
            $this->symbols->addCFunction($rawName, $retType, $paramTypes);
        }
    }

    /** C 类型字符串 → 原始 C 类型字符串
     *  C.int → int, C.char* → char*, C.FILE → FILE, C.void* → void*
     *  非 C. 前缀的类型原样返回（如 'int' → 'int'） */
    private static function cTypeToRaw(string $cType): string
    {
        if (str_starts_with($cType, 'C.')) {
            return substr($cType, 2);
        }
        return $cType;
    }

    /** 原始 C 类型 → CodeGenerator 推导类型字符串
     *  用于 inferCallReturnType 返回，使其能融入 CodeGenerator 的类型系统。
     *  指针类型（含 *）→ 'null'（视为 void*）；float/double → 't_float'；
     *  void → 'void'；其余整数类型 → 't_int'
     *  TinyPHP 内部类型（t_int/t_string/t_callback/t_float/t_bool/t_array*）原样透传，
     *  用于 phpc 扩展中 C 函数直接返回 TinyPHP 值类型。 */
    private static function rawCTypeToInferred(string $rawType): string
    {
        $rawType = trim($rawType);
        if ($rawType === '' || $rawType === 'void') return 'void';
        // TinyPHP 内部类型原样透传（phpc 扩展 C 函数返回 TinyPHP 值类型）
        if (in_array($rawType, ['t_int', 't_string', 't_callback', 't_float', 't_bool', 't_array*'], true)) {
            return $rawType;
        }
        if (str_ends_with($rawType, '*')) return 'null'; // 指针 → void*
        if ($rawType === 'float' || $rawType === 'double') return 't_float';
        return 't_int';
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
        // PHP 预定义常量类型注册（确保 VAR_* 包装器选择正确类型）
        //   字符串常量（默认即 t_string，显式注册以示清晰）
        foreach (['PHP_EOL','PHP_OS','PHP_OS_FAMILY','PHP_SAPI','PHP_VERSION','PHP_EXTRA_VERSION'] as $c) {
            $this->symbols->addConst($c, 't_string');
        }
        //   整数常量
        foreach (['PHP_INT_MAX','PHP_INT_MIN','PHP_INT_SIZE','PHP_MAJOR_VERSION','PHP_MINOR_VERSION','PHP_RELEASE_VERSION','PHP_VERSION_ID',
                  'E_ERROR','E_WARNING','E_PARSE','E_NOTICE','E_CORE_ERROR','E_CORE_WARNING',
                  'E_COMPILE_ERROR','E_COMPILE_WARNING','E_USER_ERROR','E_USER_WARNING','E_USER_NOTICE',
                  'E_STRICT','E_RECOVERABLE_ERROR','E_DEPRECATED','E_USER_DEPRECATED','E_ALL'] as $c) {
            $this->symbols->addConst($c, 't_int');
        }
        //   浮点常量
        foreach (['PHP_FLOAT_MAX','PHP_FLOAT_MIN','PHP_FLOAT_EPSILON','PHP_FLOAT_DIG'] as $c) {
            $this->symbols->addConst($c, 't_float');
        }
        // 内置 Exception 类
        $this->symbols->addClass('tphp_class_Exception');
        $this->symbols->addClassName('Exception', 'tphp_class_Exception');
        $this->symbols->getClass('tphp_class_Exception')->methods['getMessage']    = new MethodInfo('t_string');
        $this->symbols->getClass('tphp_class_Exception')->methods['__construct'] = new MethodInfo('void', ['t_string'], false, 'public', 1, 1);
        $this->symbols->getClass('tphp_class_Exception')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->addClassProp('tphp_class_Exception', 'message', 't_string');

        // 内置 Exception 子类（RuntimeException / LogicException / UnhandledMatchError 等）
        //   无额外字段，继承 Exception 的 message；CodeGenerator 自动生成 C 结构体和分配器
        //   用途：throw new RuntimeException(...) / match 未匹配抛 UnhandledMatchError
        foreach (self::BUILTIN_EXCEPTION_SUBCLASSES as $name) {
            $cn = 'tphp_class_' . $name;
            $this->symbols->addClass($cn, 'tphp_class_Exception');
            $this->symbols->addClassName($name, $cn);
            $this->symbols->getClass($cn)->methods['__construct'] = new MethodInfo('void', ['t_string'], false, 'public', 1, 1);
            $this->symbols->getClass($cn)->methods['__destruct']  = new MethodInfo('void');
            $this->symbols->getClass($cn)->methods['getMessage']  = new MethodInfo('t_string');
        }

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

        // 内置 Channel 类（CSP 风格有界通道）
        $this->symbols->addClass('tphp_class_Channel');
        $this->symbols->addClassName('Channel', 'tphp_class_Channel');
        // __construct(int $capacity = 64): void — 1 param, 1 default
        $this->symbols->getClass('tphp_class_Channel')->methods['__construct'] = new MethodInfo('void', ['t_int'], false, 'public', 1, 1);
        $this->symbols->getClass('tphp_class_Channel')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Channel')->methods['push']       = new MethodInfo('void', ['t_var']);
        $this->symbols->getClass('tphp_class_Channel')->methods['pop']        = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Channel')->methods['tryPush']    = new MethodInfo('t_bool', ['t_var']);
        $this->symbols->getClass('tphp_class_Channel')->methods['tryPop']     = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Channel')->methods['close']      = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Channel')->methods['isClosed']   = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Channel')->methods['length']     = new MethodInfo('t_int');
        $this->symbols->getClass('tphp_class_Channel')->methods['capacity']   = new MethodInfo('t_int');

        // 内置 Future 类（一次性异步结果）
        $this->symbols->addClass('tphp_class_Future');
        $this->symbols->addClassName('Future', 'tphp_class_Future');
        // static create(): Future
        $this->symbols->getClass('tphp_class_Future')->methods['create']    = new MethodInfo('tphp_class_Future*', [], true);
        $this->symbols->getClass('tphp_class_Future')->methods['__destruct'] = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Future')->methods['resolve']   = new MethodInfo('void', ['t_var']);
        $this->symbols->getClass('tphp_class_Future')->methods['reject']    = new MethodInfo('void', ['t_var']);
        $this->symbols->getClass('tphp_class_Future')->methods['await']     = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Future')->methods['isReady']   = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Future')->methods['isRejected'] = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_Future')->methods['then']      = new MethodInfo('tphp_class_Future*', ['t_callback']);
        $this->symbols->getClass('tphp_class_Future')->methods['catch']     = new MethodInfo('tphp_class_Future*', ['t_callback']);
        // static all(array $futures): Future
        $this->symbols->getClass('tphp_class_Future')->methods['all']       = new MethodInfo('tphp_class_Future*', ['t_array*'], true);
        // static race(array $futures): Future
        $this->symbols->getClass('tphp_class_Future')->methods['race']      = new MethodInfo('tphp_class_Future*', ['t_array*'], true);

        // 内置 ChannelClosedException / FutureRejectedException 类
        $this->symbols->addClass('tphp_class_ChannelClosedException');
        $this->symbols->addClassName('ChannelClosedException', 'tphp_class_ChannelClosedException');
        $this->symbols->addClass('tphp_class_FutureRejected');
        $this->symbols->addClassName('FutureRejectedException', 'tphp_class_FutureRejected');

        // 内置 AnnotationEntry 类（注解系统）
        $this->symbols->addClass('tphp_class_AnnotationEntry');
        $this->symbols->addClassName('AnnotationEntry', 'tphp_class_AnnotationEntry');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'data', 't_array*');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'type', 't_string');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'name', 't_string');
        $this->symbols->getClass('tphp_class_AnnotationEntry')->methods['__construct'] = new MethodInfo('void', ['t_array*', 't_string', 't_string']);
        $this->symbols->getClass('tphp_class_AnnotationEntry')->methods['__destruct']  = new MethodInfo('void');

        // 内置 stdClass 类（动态属性容器，无 struct 字段，属性走 tphp_fn_stdclass_* 运行时函数）
        $this->symbols->addClass('tphp_class_stdClass');
        $this->symbols->addClassName('stdClass', 'tphp_class_stdClass');
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
        if ($class->isInterface || ($class->isAbstract && $class->parentName === null && empty($class->properties))) {
            // 接口方法签名仍需注册到 SymbolTable，供实现类查找方法返回类型
            // （如 ProtocolInterface::input() 在 Worker 中通过 $this->protocol->input() 调用）
            $cn = self::classCName($class);
            $this->symbols->addClass($cn, '', true, $class->implements, false);
            foreach ($class->methods as $m) {
                $mr = $m->isGenerator ? 'tphp_class_Generator*' : $this->mapType($m->returnType);
                $pts = array_map(fn($p) => $this->paramCTypeResolved($p), $m->params);
                $pns = array_map(fn($p) => ltrim($p->name, '$'), $m->params);
                $tp = count($m->params);
                $dc = 0;
                for ($i = $tp - 1; $i >= 0; $i--) {
                    if ($m->params[$i]->default !== null) { $dc++; } else { break; }
                }
                $isVariadic = !empty($m->params) && $m->params[count($m->params) - 1]->isVariadic;
                $this->symbols->getClass($cn)->methods[$m->name] = new MethodInfo($mr, $pts, $m->isStatic, $m->visibility, $dc, $tp, false, false, $pns, $isVariadic);
            }
            $o = [];
            $o[] = "/* interface {$class->name} — compile-time only */";
            // 前向声明已在 Phase 1 中生成（typedef struct cn cn;），此处不再生成 stub typedef
            // 接口类型作为指针使用，不完整类型即可（C 标准允许不完整类型的指针引用）
            // 接口常量 → #define（与类常量一致，在 SEC_CLSFWDS 中定义，确保使用点之前可见）
            foreach ($class->classConsts as $cc) {
                $cname = 'TPHP_CONST_' . strtoupper($cn . '_' . $cc->name);
                $fullName = $cn . '_' . $cc->name;
                $vis = $cc->visibility ?? 'public';
                $declCType = self::mapType($cc->type);
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
                    $o[] = "static t_array* {$cname} = NULL;";
                    $o[] = "/* initialized on first access via {$cn} interface */";
                }
            }
            $o[] = '';
            return implode("\n", $o) . "\n";
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
        // COS-style header (cls ptr + refcount)
        //   根类: 直接持有 t_object _obj（偏移 0）
        //   子类: _parent 作为第一个成员（已包含 _obj），确保 Child* cast 为 Parent*
        //         时地址相同、属性偏移正确（C 标准结构体嵌套继承布局）
        $parentCN = '';
        if ($class->parentName !== null) {
            $parentCN = self::classRefName($class->parentName);
            $o[] = $this->ind($parentCN . ' _parent;');   // 第一个成员(含 _obj)
        } else {
            $o[] = $this->ind('t_object _obj;');
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
        $this->symbols->addClass($cn, $parentCN, $class->isAbstract, $class->implements, $class->isReadonly, $class->isFinal);
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
            // 非对称可见性 private(set) 注册（仅实例属性，static 不支持）
            if ($prop->setVisibility === 'private' && !$prop->isStatic) {
                $this->symbols->addClassPrivateSetProp($cn, $pname);
            }
        }
        // 方法返回类型 + 参数类型 + 默认值信息（第一遍即注册完整信息，
        // 确保跨类方法调用在 emitClassImpl 阶段即可解析重载版本）
        // __construct 也需注册 defaultCount/totalParams，否则 visitNew 无法选择重载版本
        if ($ctor !== null) {
            $cpts = array_map(fn($p) => $this->mapType($p->type), $ctor->params);
            $cpns = array_map(fn($p) => ltrim($p->name, '$'), $ctor->params);
            $ctp = count($ctor->params);
            $cdc = 0;
            for ($i = $ctp - 1; $i >= 0; $i--) {
                if ($ctor->params[$i]->default !== null) { $cdc++; } else { break; }
            }
            $this->symbols->getClass($cn)->methods['__construct'] = new MethodInfo('void', $cpts, false, 'public', $cdc, $ctp, false, false, $cpns, false, $class->isFinal);
        } else {
            $this->symbols->getClass($cn)->methods['__construct'] = new MethodInfo('void', [], false, 'public', 0, 0, false, false, [], false, $class->isFinal);
        }
        $this->symbols->getClass($cn)->methods['__destruct']  = new MethodInfo('void', [], false, 'public', 0, 0, false, false, [], false, $class->isFinal);
        foreach ($methods as $m) {
            $mr = $m->isGenerator ? 'tphp_class_Generator*' : $this->mapType($m->returnType);
            $pts = array_map(fn($p) => $this->paramCTypeResolved($p), $m->params);
            $pns = array_map(fn($p) => ltrim($p->name, '$'), $m->params);
            $tp = count($m->params);
            $dc = 0;
            for ($i = $tp - 1; $i >= 0; $i--) {
                if ($m->params[$i]->default !== null) { $dc++; } else { break; }
            }
            $isVariadic = !empty($m->params) && $m->params[count($m->params) - 1]->isVariadic;
            // Task 5.5: final class 的方法标记 isStaticDispatch（TypeChecker 已同步标记 MethodNode）
            $this->symbols->getClass($cn)->methods[$m->name] = new MethodInfo($mr, $pts, $m->isStatic, $m->visibility, $dc, $tp, $m->isFinal, false, $pns, $isVariadic, $class->isFinal);
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

    /**
     * 内置 Exception 子类结构体定义 + 前置声明（SEC_CLSFWDS 阶段）
     *
     * 每个子类无额外字段，仅继承 Exception 的 _parent（含 _obj + message）。
     * 生成内容：
     *   typedef struct tphp_class_X { tphp_class_Exception _parent; } tphp_class_X;
     *   void tphp_class_X___construct(tphp_class_X* self, t_string msg);
     *   void tphp_class_X___destruct(tphp_class_X* self);
     *   tphp_class_X* new_tphp_class_X(t_string msg);
     */
    private function emitBuiltinExceptionForward(): string
    {
        $o = ["/* ── Builtin Exception subclasses ──────────────────────────── */"];
        foreach (self::BUILTIN_EXCEPTION_SUBCLASSES as $name) {
            $cn = 'tphp_class_' . $name;
            $o[] = "typedef struct {$cn} { tphp_class_Exception _parent; } {$cn};";
            $o[] = "void {$cn}___construct({$cn}* self, t_string msg);";
            $o[] = "void {$cn}___destruct({$cn}* self);";
            $o[] = "{$cn}* new_{$cn}(t_string msg);";
            $o[] = '';
        }
        return implode("\n", $o) . "\n";
    }

    /**
     * 内置 Exception 子类实现（SEC_CLSIMPL 阶段）
     *
     * 生成每个子类的：
     *   - vtable + class descriptor（parent = _class_tphp_class_Exception，exception_offset = offsetof(_parent)）
     *   - ___construct（设置 _parent.message）
     *   - ___destruct（空，Exception 基类负责释放 message）
     *   - new_X 分配器
     */
    private function emitBuiltinExceptionImpl(): string
    {
        $o = ["/* ── Builtin Exception subclass implementations ────────────── */"];
        foreach (self::BUILTIN_EXCEPTION_SUBCLASSES as $name) {
            $cn = 'tphp_class_' . $name;
            $o[] = "static void* _vtable_{$cn}[1] = { NULL };";
            $o[] = "static const t_class _class_{$cn} = {";
            $o[] = "    .name          = \"{$name}\",";
            $o[] = "    .parent        = &_class_tphp_class_Exception,";
            $o[] = "    .instance_size = sizeof({$cn}),";
            $o[] = "    .exception_offset = offsetof({$cn}, _parent),";
            $o[] = "    .dtor          = (void*){$cn}___destruct,";
            $o[] = "    .vtable        = _vtable_{$cn},";
            $o[] = "    .vtable_len    = 0,";
            $o[] = "};";
            $o[] = '';
            $o[] = "void {$cn}___construct({$cn}* self, t_string msg) {";
            $o[] = "    if (self == NULL) return;";
            $o[] = "    tphp_rt_str_free(&self->_parent.message); self->_parent.message = tphp_rt_str_dup(msg);";
            $o[] = "}";
            $o[] = '';
            $o[] = "void {$cn}___destruct({$cn}* self) {";
            $o[] = "    if (self == NULL) return;";
            $o[] = "}";
            $o[] = '';
            $o[] = "{$cn}* new_{$cn}(t_string msg) {";
            $o[] = "    {$cn}* self = ({$cn}*)tp_obj_alloc(&_class_{$cn});";
            $o[] = "    if (self == NULL) return NULL;";
            $o[] = "    {$cn}___construct(self, msg);";
            $o[] = "    tphp_rt_register((void*)self, 0);";
            $o[] = "    return self;";
            $o[] = "}";
            $o[] = '';
        }
        return implode("\n", $o) . "\n";
    }

    /** Phase 2: VTable + 方法实现 + allocator */
    private function emitClassImpl(ClassNode $class, bool $isMain): string
    {
        // Skip interface-only classes (constants already emitted in emitClassForward)
        if ($class->isInterface) {
            return '';
        }
        // Skip abstract classes without implementation (no properties, no concrete methods)
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
            //   可变参数 C 类型固定为 t_array*（与 paramDecl/visitParam 一致）
            $pts = array_map(function($p) {
                if ($p->isVariadic) return 't_array*';
                return $this->mapType($p->type);
            }, $m->params);
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
            // 检测最后一个参数是否为可变参数
            $isVariadic = !empty($m->params) && $m->params[count($m->params) - 1]->isVariadic;
            if ($mi = $this->symbols->getClassMethod($cn, $m->name)) {
                $pns = array_map(fn($p) => ltrim($p->name, '$'), $m->params);
                $mi = new MethodInfo($mi->retType, $pts, $mi->isStatic, $mi->visibility, $defaultCount, $totalParams, $mi->isFinal, $mi->isAbstract, $pns, $isVariadic);
            }
            $pns = array_map(fn($p) => ltrim($p->name, '$'), $m->params);
            $this->symbols->getClass($cn)->methods[$m->name] = $mi ?? new MethodInfo('void', $pts, false, 'public', $defaultCount, $totalParams, false, false, $pns, $isVariadic);
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
                // Task 5.4: array<T> 构造器参数 — 记录元素类型
                $this->applyArrayElemType($vn, $p->type);
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
                } elseif ($prop->type === 'mixed' && $prop->default instanceof NullLiteralExpr) {
                    // mixed（t_var）属性 null 默认值：需 VAR_NULL() 包装
                    $o[] = $this->ind("self->{$pname} = VAR_NULL();");
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
                            // 先求值到临时，再 free 旧值（防止 expr 读取同一属性）
                            $tmp = '_tmp_' . (++$this->tmpVarCounter);
                            $o[] = $this->ind("t_string {$tmp} = {$exprCode};");
                            $o[] = $this->ind("tphp_rt_str_free(&self->{$pname});");
                            $o[] = $this->ind("self->{$pname} = tphp_rt_str_dup({$tmp});");
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
        // 同步到 SymbolTable（保留已有 paramTypes/defaultCount/totalParams/isGenerator/isVariadic）
        $fnCName = self::funcCName($node);
        $existingFn = $this->symbols->getFunc($fnCName);
        if ($existingFn !== null) {
            $this->symbols->addFunc($fnCName, new FunctionInfo(
                $ret,
                $existingFn->paramTypes,
                $existingFn->defaultCount,
                $existingFn->totalParams,
                $existingFn->isGenerator,
                $existingFn->paramNames,
                $existingFn->isVariadic,
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

        // 跳过已在 C 头文件中定义的函数的实现生成（避免 redefinition 错误）
        //   场景：ext/pgsql 的 PHP 包装函数（pg_connect 等）与 C 头文件中的 C 函数同名，
        //   C 函数已在 #include 的头文件中定义，PHP 包装函数的实体会导致 redefinition。
        //   重载函数（fnName_1 等）不与 C 函数同名，仍需生成。
        //   返回类型已通过 $builtinRetTypes 注册，调用方直接链接到 C 函数。
        if (isset(self::$builtinRetTypes[$this->currentFuncCName])) {
            return implode("\n\n", $parts);
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
        // 记录可变参数名（用于 func_get_args() 生成）
        $this->currentVariadicParamName = '';
        $this->currentVariadicElementType = '';
        if (!empty($node->params) && $node->params[count($node->params) - 1]->isVariadic) {
            $lastParam = $node->params[count($node->params) - 1];
            $this->currentVariadicParamName = self::varName($lastParam->name);
            $this->currentVariadicElementType = $lastParam->type;
        }

        $params = array_map(fn($p) => self::paramDecl($p), $node->params);
        $paramVars = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $this->paramCTypeResolved($p);
            // Task 5.4: array<T> 参数 — 记录元素类型
            $this->applyArrayElemType($vn, $p->type);
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
        // 记录可变参数名（用于 func_get_args() 生成）
        $this->currentVariadicParamName = '';
        $this->currentVariadicElementType = '';
        if (!empty($node->params) && $node->params[count($node->params) - 1]->isVariadic) {
            $lastParam = $node->params[count($node->params) - 1];
            $this->currentVariadicParamName = self::varName($lastParam->name);
            $this->currentVariadicElementType = $lastParam->type;
        }

        $paramVars = $isStatic ? [] : ['self' => true];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $this->paramCTypeResolved($p);
            // Task 5.4: array<T> 参数 — 记录元素类型
            $this->applyArrayElemType($vn, $p->type);
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
        // 可变参数：C 类型固定为 t_array*（与 paramDecl 一致）
        if ($node->isVariadic) {
            return 't_array* ' . self::varName($node->name);
        }
        $ct = self::mapType($node->type);
        // Task 5.4: array<T> 参数 — 记录元素类型到 arrElementTypes
        $this->applyArrayElemType(self::varName($node->name), $node->type);
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
                // 常量引用（不以 $ 开头）→ 查 SymbolTable 常量类型
                if (!str_starts_with($e->name, '$') && $vt === '') {
                    $vt = $this->symbols->getConstType($e->name) ?? '';
                }
                if ($vt === 't_var') {
                    // t_var 变量：echo 应转为字符串输出（PHP 语义），而非 var_dump
                    //   tphp_fn_strval 处理所有 t_var 类型（int/float/string/bool/array）
                    $parts[] = 'tphp_fn_echo(tphp_fn_strval(' . $this->wrapVar($e) . '));';
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
            // array<mixed> 元素 → 标量返回类型转换
            //   如 function getKey(array $arr): string { return $arr[$key]; }
            //   $arr[$key] 为 t_var，需 VAR_AS_STRING 解包为 t_string
            //   match 表达式返回 t_var（union 类型）→ 标量返回类型也需解包
            if ($this->currentRetType !== 't_var' && $this->currentRetType !== '') {
                $isTVar = $this->isActualTVarExpr($node->expr)
                    || ($node->expr instanceof MatchExpr && $this->inferType($node->expr) === 't_var');
                if ($isTVar) {
                    $code = match ($this->currentRetType) {
                        't_int'    => "VAR_AS_INT({$code})",
                        't_float'  => "VAR_AS_FLOAT({$code})",
                        't_string' => "VAR_AS_STRING({$code})",
                        't_bool'   => "VAR_AS_BOOL({$code})",
                        default    => $code,
                    };
                }
            }
            // t_var 变量 + smartcast 缩窄 → 对象指针返回类型转换
            //   如 function f(mixed $x): GdFont { if ($x instanceof GdFont) { return $x; } }
            //   $x 为 t_var，instanceof 分支内缩窄为 GdFont，返回类型 tphp_class_GdFont*
            //   需生成 ((tphp_class_GdFont*)($x).value._object) 提取对象指针
            if ($node->expr instanceof VariableExpr
                && str_starts_with($node->expr->name, '$')
                && str_starts_with($this->currentRetType, 'tphp_class_')
                && str_ends_with($this->currentRetType, '*')) {
                $vn = self::varName($node->expr->name);
                if (($this->varTypes[$vn] ?? '') === 't_var'
                    && isset($this->narrowedTypes[$vn])) {
                    $cn = $this->narrowedTypes[$vn];
                    $code = "(({$cn}*)({$vn}).value._object)";
                }
            }
            // 子类→父类 upcast（与参数 upcast 对称）
            //   如 Layout::asWidget(): Widget { return $this; }
            //   $this 为 Layout*，返回类型 Widget*，需生成 (Widget*)this
            if ($node->expr instanceof VariableExpr
                && str_starts_with($node->expr->name, '$')
                && self::isClassCType($this->currentRetType)) {
                // 对 $this 直接用 className，避免 inferType 走 inferredType 路径返回错误类型
                $exprType = ($node->expr->name === '$this')
                    ? $this->className . '*'
                    : $this->inferType($node->expr);
                if (self::isClassCType($exprType)) {
                    $exprCn = rtrim($exprType, '*');
                    $retCn  = rtrim($this->currentRetType, '*');
                    if ($exprCn !== $retCn && $this->isSubclassOf($exprCn, $retCn)) {
                        $code = "({$this->currentRetType}){$code}";
                    }
                }
            }
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
        // $x = expr or { ... }; → 包裹在 TP_TRY/TP_CATCH_EX/TP_END_TRY 中
        if ($node->expr instanceof OrBlockExpr) {
            return $this->generateAssignWithOrBlock($node);
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

        // 有显式声明的泛型数组类型（array<T>）且 TypeChecker 未运行时，
        //   将声明类型注入数组字面量的 inferredType，确保生成正确的泛型数组代码
        if ($node->type !== null
            && $node->expr instanceof ArrayLiteralExpr
            && $node->expr->inferredType === null
            && str_starts_with($node->type, 'array<')
            && str_ends_with($node->type, '>')) {
            $elemStr = substr($node->type, 6, -1);
            $node->expr->inferredType = Type::array($this->resolveArrayElemType($elemStr));
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
            // (array) $stdClass → tphp_fn_stdclass_to_array 生成万能数组，元素为 t_var
            if (str_contains($srcType, 'tphp_class_stdClass')) {
                $this->arrElementTypes[$var] = 't_var';
                // stdClass 属性名为字符串键，标记以供 foreach 键类型推导为 t_string
                $this->arrValueTypes[$var] = ['__stdclass_props__' => 't_var'];
            } else {
                $this->arrElementTypes[$var] = ($srcType === 'null' || $srcType === 'void*') ? 't_int' : $srcType;
            }
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
        //   有显式类型声明时（如 GdImage $im = null;）使用声明的类型，保留属性访问所需的类信息
        if ($node->expr instanceof NullLiteralExpr) {
            if ($node->type !== null) {
                $cType = self::mapType($node->type);
                $this->varTypes[$var] = $cType;
                if (!$isDeclared) {
                    $declType = $cType;
                    if ($this->scopeDepth > 0) {
                        $this->funcScopeDecls[$var] = $declType;
                        return "{$var} = null;";
                    }
                    return "{$declType} {$var} = null;";
                }
                return "{$var} = null;";
            }
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
            // Task 5.4: array<T> 类型注解 — 记录元素类型到 arrElementTypes
            //   优先于字面量推导（字面量推导在 ArrayLiteral 分支已处理）
            if ($node->type !== null && str_starts_with($node->type, 'array<')) {
                $this->applyArrayElemType($var, $node->type);
            }
            // 追踪可空性：来自 nullsafe ?-> 或 tryFrom() 的值可能为 null/零值
            $this->varNullable[$var] = $this->exprMayBeNull($node->expr);
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
                // t_var 变量首次赋值：需将 typed 表达式包装为 VAR_XXX 宏
                //   场景：$r = $rows[$i]; 其中 $rows 是 array<mixed>，$rows[$i] 经 visitArrayAccess
                //   生成 typed getter（如 tphp_fn_arr_get_int_arr 返回 t_array*），但 $r 是 t_var，
                //   需 VAR_ARRAY(...) 包装才能赋值
                if ($cType === 't_var') {
                    $wrap = $this->wrapTvarAssign($node->expr, $expr);
                    $code = "{$var} = {$wrap};";
                } else {
                    $code = "{$w} = {$expr};";
                }
            } else {
                if ($cType === 't_var') {
                    $wrap = $this->wrapTvarAssign($node->expr, $expr);
                    $code = "{$declType} {$var} = {$wrap};";
                } else {
                    $code = "{$declType} {$var} = {$expr};";
                }
            }
        } else {
            // 自动释放：对象/t_string 重赋值时先求值再释放（防止 $var=$var->method() 的 use-after-free）
            $w = $this->varWrite($var, $prevType);
            if (self::isClassCType($prevType) || self::isEnumCType($prevType)) {
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                // self-assignment guard: $obj = $obj->method() 时 method 可能返回 this
                $code = "{$prevType} {$tmp} = {$expr}; if ({$tmp} != (void*){$var}) tp_obj_release((void*){$var}); {$var} = {$tmp};";
            } elseif ($prevType === 't_string') {
                // 先求值 RHS 到临时变量，再 free 旧值，再赋值
                // （防止 RHS 读取自身时读到已 free 的空串，如 $s = substr($s,0,1) . "X" . substr($s,1)）
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                $code = "t_string {$tmp} = {$expr}; tphp_rt_str_free(&{$var}); {$w} = {$tmp};";
            } elseif ($prevType === 't_array*') {
                // 数组重赋值：先求值新值，释放旧数组，再赋值
                // self-assignment guard: $result = func(..., $result, ...) 时 func 可能返回同一个指针
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                // 当 $var 同时作为函数参数时（如 $result = exif_parse_ifd(..., $result, ...)），
                // 函数内部可能 realloc 了 $var 指向的内存，旧指针已失效，不能 free
                // 否则会导致双重释放（realloc 已释放 + guard 再次 free）
                $varInArgs = $this->exprIsCallWithVarArg($node->expr, $var);
                if ($varInArgs) {
                    $code = "t_array* {$tmp} = {$expr}; {$var} = {$tmp};";
                } else {
                    $code = "t_array* {$tmp} = {$expr}; if ({$tmp} != {$var} && {$var} != NULL) tphp_fn_arr_free({$var}); {$var} = {$tmp};";
                }
            } else {
                $code = "{$w} = {$expr};";
            }
        }

        // 重赋值时：标量变量接收 t_var 值需解包
        //   如 $v = 0 (t_int) 后 $v = $arr[$i] (t_var) → v = VAR_AS_INT(*arr_index(...))
        //   C 变量类型固定为 t_int，不能直接赋 t_var 结构体
        if ($isDeclared && in_array($prevType, ['t_int', 't_float', 't_string', 't_bool'], true)) {
            $exprType = $this->inferType($node->expr);
            if ($exprType === 't_var') {
                $unwrapped = $this->unwrapIfMixed($node->expr, $expr, $prevType);
                if ($unwrapped !== $expr) {
                    // 用解包后的值替换 $expr（$code 中已嵌入原 $expr，需重新生成）
                    $w2 = $this->varWrite($var, $prevType);
                    if (self::isClassCType($prevType) || self::isEnumCType($prevType)) {
                        $tmp = '_tmp_' . (++$this->tmpVarCounter);
                        $code = "{$prevType} {$tmp} = {$unwrapped}; if ({$tmp} != (void*){$var}) tp_obj_release((void*){$var}); {$var} = {$tmp};";
                    } elseif ($prevType === 't_string') {
                        // 先求值到临时，再 free 旧值（同 3727 修复，防止 RHS 读取自身）
                        $tmp = '_tmp_' . (++$this->tmpVarCounter);
                        $code = "t_string {$tmp} = {$unwrapped}; tphp_rt_str_free(&{$var}); {$w2} = {$tmp};";
                    } else {
                        $code = "{$w2} = {$unwrapped};";
                    }
                }
            }
        }

        // 重赋值时：t_array* 变量接收 t_var 值（如 $r = $lbl[0]，$lbl 为 array<mixed>）
        //   需从 t_var 提取 .value._array 字段得到 t_array*
        //   场景：嵌套数组的 array<mixed> 元素被赋给已声明为 t_array* 的变量
        if ($isDeclared && $prevType === 't_array*') {
            $exprType = $this->inferType($node->expr);
            if ($exprType === 't_var') {
                $unwrapped = $this->unwrapIfMixed($node->expr, $expr, 't_array*');
                if ($unwrapped !== $expr) {
                    $tmp = '_tmp_' . (++$this->tmpVarCounter);
                    $varInArgs = $this->exprIsCallWithVarArg($node->expr, $var);
                    if ($varInArgs) {
                        $code = "t_array* {$tmp} = {$unwrapped}; {$var} = {$tmp};";
                    } else {
                        $code = "t_array* {$tmp} = {$unwrapped}; if ({$tmp} != {$var} && {$var} != NULL) tphp_fn_arr_free({$var}); {$var} = {$tmp};";
                    }
                }
            }
        }

        // 数组赋值 → 推导元素类型（支持对象/回调/嵌套数组）
        //   默认 array<mixed>：元素为 t_var，visitArrayAccess 走 t_var 路径
        //   显式 array<T>（T 非 mixed）：用字面量推导元素类型，启用 per-key 追踪优化
        if ($node->expr instanceof ArrayLiteralExpr) {
            // 保存数组字面量 AST，用于精确追踪嵌套访问中特定键的值类型
            $this->arrLiteralAST[$var] = $node->expr;

            // 判定是否为 array<mixed>（TypeChecker 默认推导结果）
            //   - 无 inferredType：TypeChecker 未运行，回退到字面量推导（兼容旧路径）
            //   - inferredType 非 array 或元素类型为 null/mixed：视为 array<mixed>
            $arrInferred = $node->expr->inferredType;
            $isMixedArray = ($arrInferred === null)
                ? false
                : (!$arrInferred->isArray()
                    || $arrInferred->elemType() === null
                    || $arrInferred->elemType()->isMixed());

            if ($isMixedArray) {
                // array<mixed>：元素统一为 t_var，但若字面量元素全为同一类对象或回调，
                //   优化为该类指针类型，使 $u = $arr[0] 推导为 tphp_class_X* 而非 t_var
                //   场景：$users = [$u1, $u2]; $u = $users[0]; $u->name
                //         $funcs = [$f1, $f2]; $fn = $funcs[0]; $fn()
                //   运行时仍以 VAR_OBJ/VAR_CALLBACK 包装存储，visitArrayAccess 用 typed getter 提取
                $deepElem = $this->inferArrayDeepElementType($node->expr);
                $literalElemType = $this->inferArrayElementType($node->expr);
                if (!empty($node->expr->entries)
                    && (str_contains($literalElemType, 'tphp_class_') || $literalElemType === 't_callback')
                    && !$this->isMixedArrayLiteral($node->expr)) {
                    // 全部为同类对象/回调：使用具体类型，运行时仍以 VAR_* 存储到 t_array
                    $et = $literalElemType;
                    if (str_contains($et, 'tphp_class_') && !str_ends_with($et, '*')) $et .= '*';
                    $this->arrElementTypes[$var] = $et;
                } elseif (empty($node->expr->entries)) {
                    // 空数组字面量 []：TypeChecker 推断为 array<mixed>，但元素类型实际未知。
                    //   不设置 arrElementTypes，保留默认 t_int，后续 $arr[$k] = int_val 赋值时
                    //   visitAssignArrayStmt 会在检测到非默认类型时更新（保持 t_int 默认）。
                    //   这样 foreach 遍历使用 t_int 而非 t_var，避免 2x 性能回退。
                    //   若后续赋值为 string/object 等，arrElementTypes 会被覆盖为对应类型。
                } else {
                    // array<mixed>：元素为 t_var，不追踪 per-key 类型（保持 t_var 一致性）
                    $this->arrElementTypes[$var] = 't_var';
                    // 但仍需追踪嵌套层级和叶子类型，供 $arr[0][1][2] 深层链式访问推导
                    //   场景：$a = [[[1,2,3],[4,5,6]]]; $a[0][1][2]
                    //   - $a[0] 是 t_var（运行时持有子数组），visitArrayAccess 走 t_var 路径
                    //   - $a[0][1] 需知道是中间层（t_array*）还是叶子层（t_int）
                    //   - arrNestedDepth 记录总深度，arrNestedTypes 记录叶子类型
                    //   嵌套对象数组：$catalog = [[$i1, $i2], [$i3]]
                    //   - arrNestedTypes 记录 tphp_class_Item*，供 $catalog[0][0]->title 推导
                    if ($literalElemType === 't_array*'
                        && $deepElem !== 't_int'
                        && !$this->isMixedArrayLiteral($node->expr)) {
                        // 嵌套数组：记录叶子元素类型（含类对象）
                        $this->arrNestedTypes[$var] = $deepElem;
                        $this->arrNestedDepth[$var] = $this->inferArrayNestedDepth($node->expr);
                    } elseif ($deepElem === 't_array*' || $deepElem === 't_var') {
                        // 元素本身是数组：记录嵌套深度和叶子类型
                        $this->arrNestedTypes[$var] = $this->inferArrayElementType($node->expr);
                        $this->arrNestedDepth[$var] = $this->inferArrayNestedDepth($node->expr);
                    }
                }
            } else {
                // 显式 array<T>（或 TypeChecker 未运行）：字面量推导元素类型
                $elemType = $this->inferArrayElementType($node->expr);
                if (str_contains($elemType, 'tphp_class_') && !str_ends_with($elemType, '*')) $elemType .= '*';
                // 空数组字面量不设置 arrElementTypes（元素类型未知，避免误判为 t_int）
                // — 后续 $arr[$k] = val 用变量键赋值时，arrElementTypes 不会被错误地锁定为 t_int
                if (!empty($node->expr->entries)) {
                    $this->arrElementTypes[$var] = $elemType;
                }
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
                case 'array_values':
                case 'array_slice':
                case 'array_unique':
                    // 元素类型 = 输入数组元素类型（从源数组变量推导）
                    //   覆盖 $builtinArrElemTypes 中的硬编码 t_int 默认值，
                    //   使 array<string> 经 array_values/array_slice/array_unique 后
                    //   仍按 t_string 访问元素，避免字符串值被误当 int 解析为 0。
                    if (isset($node->expr->args[0]) && $node->expr->args[0] instanceof VariableExpr) {
                        $srcVar = self::varName($node->expr->args[0]->name);
                        if (isset($this->arrElementTypes[$srcVar])) {
                            $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
                        }
                    }
                    break;
                case 'array_merge':
                case 'array_reverse':
                case 'array_diff':
                case 'array_intersect':
                case 'array_pad':
                    // 元素类型 = 第一个源数组元素类型
                    //   覆盖 $builtinArrElemTypes 中的硬编码 t_int 默认值，
                    //   使 array<string> 经 array_reverse/array_diff/array_intersect/array_pad 后
                    //   仍按 t_string 访问元素。array_merge 取第一个数组代表（多数组 merge）。
                    if (isset($node->expr->args[0]) && $node->expr->args[0] instanceof VariableExpr) {
                        $srcVar = self::varName($node->expr->args[0]->name);
                        if (isset($this->arrElementTypes[$srcVar])) {
                            $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
                        }
                    }
                    break;
                case 'array_combine':
                    // 元素类型 = values 数组（第二参数）元素类型
                    //   array_combine($keys, $values) 返回 [keys[i] => values[i]]，
                    //   值元素类型由 $values 数组决定。未追踪则默认 t_int，访问字符串值返回 0。
                    if (isset($node->expr->args[1]) && $node->expr->args[1] instanceof VariableExpr) {
                        $srcVar = self::varName($node->expr->args[1]->name);
                        if (isset($this->arrElementTypes[$srcVar])) {
                            $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
                        }
                    }
                    break;
                case 'array_chunk':
                    // 外层元素类型 = t_array*（每个 chunk 是子数组）
                    //   内层元素类型 = 源数组元素类型（chunk 内元素继承源数组）
                    //   未追踪则外层默认 t_int，访问 $chunks[0] 调用 typed getter 返回 0。
                    $this->arrElementTypes[$var] = 't_array*';
                    if (isset($node->expr->args[0]) && $node->expr->args[0] instanceof VariableExpr) {
                        $srcVar = self::varName($node->expr->args[0]->name);
                        if (isset($this->arrElementTypes[$srcVar])) {
                            $this->arrNestedTypes[$var] = $this->arrElementTypes[$srcVar];
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
            // 传播方法返回数组的 per-key 类型（$var = $obj->method() 后 $var["key"] 类型推断）
            //   与普通函数调用（line 3129-3136）对应，方法调用也需查 fnReturnArrKeyTypes
            if ($methodCName !== null && isset($this->fnReturnArrKeyTypes[$methodCName])) {
                $this->arrValueTypes[$var] ??= [];
                foreach ($this->fnReturnArrKeyTypes[$methodCName] as $k => $t) {
                    $this->arrValueTypes[$var][$k] = $t;
                }
            }
            // 枚举 cases() 返回 array<enum> — 直接从 callee 解析枚举 C 名设置元素类型
            //   $all = Color::cases(); 后 $all[0]->name 才能正确推导为 t_string
            if ($node->expr->name === 'cases' && $node->expr->callee instanceof VariableExpr) {
                $enumCName = $this->symbols->resolveEnumCName($node->expr->callee->name);
                if ($enumCName !== null) {
                    $this->arrElementTypes[$var] = $enumCName . '*';
                }
            }
        }

        // 记录闭包变量名→函数名映射
        if ($node->expr instanceof ClosureExpr) {
            $closureName = "_closure_{$this->closureCounter}";
            $this->symbols->addVarClosure($var, $closureName);
        }

        // First-class callable: $cb = foo(...) → 绑定变量到闭包签名
        //   lastCallableConvertSig 由 visitCallableConvert 在 accept 过程中设置
        if ($node->expr instanceof CallableConvertExpr && $this->lastCallableConvertSig !== null) {
            $this->symbols->addVarClosure($var, $this->lastCallableConvertSig);
        }
        // 重置临时状态，避免污染后续表达式
        $this->lastCallableConvertSig = null;

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

    /**
     * 将 TypeChecker 填充的 Type 对象转换为 C 类型字符串
     * @return string|null C 类型字符串，null 表示无法转换（fallback 到 inferType）
     */
    private function inferredTypeToCType(Type $type): ?string
    {
        // mixed 类型
        if ($type->isMixed()) {
            return 't_var';
        }

        // 数组类型
        if ($type->isArray()) {
            // 通用 array（IDX_ARRAY 无 FLAG_ARRAY 标志）→ t_array*
            if (!$type->hasFlag(Type::FLAG_ARRAY)) {
                return 't_array*';
            }
            // 泛型 array<T>
            $elemType = $type->elemType();
            // array<mixed> → t_array*（等价于 t_arr_var*，兼容现有代码）
            if ($elemType === null || $elemType->isMixed()) {
                return 't_array*';
            }
            // array<int>/array<string>/array<float>/array<bool> → t_arr_T*
            return Type::arrayCType($elemType) . '*';
        }

        // 内置类型（通过 idx 匹配）
        $idx = $type->idx();
        return match ($idx) {
            Type::IDX_VOID => 'void',
            Type::IDX_INT => 't_int',
            Type::IDX_FLOAT => 't_float',
            Type::IDX_STRING => 't_string',
            Type::IDX_BOOL => 't_bool',
            Type::IDX_NULL => 'null',
            Type::IDX_ARRAY => 't_array*',
            Type::IDX_OBJECT => 'void*',
            Type::IDX_CALLBACK => 't_callback',
            Type::IDX_NEVER => 'void',
            Type::IDX_MIXED => 't_var',
            // 用户类型 idx（>= 256）无法安全转换，返回 null 让 fallback 处理
            default => null,
        };
    }

    /**
     * Task 5.4: 从 array<T> 类型注解提取元素 C 类型
     *
     * @param string $typeStr 类型字符串（如 'array<int>' / 'array<string>' / 'array<array<int>>'）
     * @return string|null 元素 C 类型（如 't_int'），非数组类型或通用 array 返回 null
     */
    private static function extractArrayElemCType(string $typeStr): ?string
    {
        if (!str_starts_with($typeStr, 'array<') || !str_ends_with($typeStr, '>')) {
            return null;
        }
        $elemStr = substr($typeStr, 6, -1); // 去掉 'array<' 和 '>'
        // 嵌套数组 array<array<T>> → 元素 C 类型为 t_array*（CodeGenerator 已能处理）
        if (str_starts_with($elemStr, 'array<') && str_ends_with($elemStr, '>')) {
            return 't_array*';
        }
        return self::$typeMap[$elemStr] ?? null;
    }

    /**
     * Task 5.4: 为变量应用 array<T> 类型注解的元素类型信息
     *
     * 若 $typeStr 为 array<T> 形式，设置 $this->arrElementTypes[$varName] = elemCType。
     * 这样后续 $arr[$i] 访问能直接生成标量访问代码（Task 5.3 优化）。
     */
    private function applyArrayElemType(string $varName, string $typeStr): void
    {
        // 通用 array（无泛型参数）→ array<mixed>，元素类型为 t_var
        // 保持 PHP 友好性：未注解的 array 参数默认为 array<mixed>（万能数组）
        if ($typeStr === 'array') {
            $this->arrElementTypes[$varName] = 't_var';
            return;
        }
        $elemCType = self::extractArrayElemCType($typeStr);
        if ($elemCType !== null) {
            $this->arrElementTypes[$varName] = $elemCType;
        }
    }

    /** 从 AST 表达式推导 C 类型 */
    private function inferType(ExprNode $expr): string
    {
        // === VariableExpr 优先：已声明 C 变量的类型固定，优先于 TypeChecker inferredType ===
        //   场景：foreach 键 $i 声明为 t_int，但 TypeChecker 标记 inferredType 为 mixed
        //   若用 t_var 会导致 unset/push 等函数误用 t_var 重载，传 t_int 报类型错误
        if ($expr instanceof VariableExpr && $expr->name !== '$this') {
            $vn = self::varName($expr->name);
            if (isset($this->varTypes[$vn])) {
                $t = $this->varTypes[$vn];
                if ($this->isByRefType($t)) return substr($t, 0, -1);
                return $t;
            }
            // 常量引用（name 不以 $ 开头）→ 查 SymbolTable 常量类型，优先于 TypeChecker inferredType。
            //   TypeChecker.checkVariable 对未声明标识符默认设 mixed（t_var），但常量已在 visitConst
            //   注册到 SymbolTable，应查 getConstType 获取真实类型，避免 int 常量被误判为 t_var
            //   后生成 VAR_AS_INT(TPHP_CONST_XXX)（对整数常量取 .type 字段，TCC 报 lvalue expected）
            if (!str_starts_with($expr->name, '$')) {
                $ct = $this->symbols->getConstType($expr->name);
                if ($ct !== null) return $ct;
            }
        }

        // === CallExpr 优先：CodeGenerator 的 builtinRetTypes/inferCallReturnType
        //     知道实际 C 函数返回类型，优先于 TypeChecker 的 inferredType ===
        //   场景：strripos 在 TypeChecker 中未注册 → inferredType 为 mixed → t_var
        //   但 C 函数 tphp_fn_strripos 实际返回 t_int
        if ($expr instanceof CallExpr) {
            try {
                return $this->inferCallReturnType($expr);
            } catch (\LogicException $e) {
                // 未知函数：回退到 TypeChecker inferredType
            }
        }

        // === PipeExpr 优先：pipe 表达式类型 = 右侧调用的返回类型
        //     TypeChecker 对 CallableConvertExpr 的 pipe 标记 mixed，需用 inferCallReturnType 推导
        if ($expr instanceof PipeExpr) {
            $t = $this->inferPipeType($expr);
            if ($t !== null) return $t;
        }

        // === TypeChecker 优先：检查 inferredType 字段 ===
        //   例外1：ArrayAccessExpr/TernaryExpr/PropertyAccessExpr 推导为 t_var（mixed）时，
        //   先查 CodeGenerator 自有的 arrElementTypes/arrValueTypes/SymbolTable 获取更精确的类型，
        //   避免变量声明为 t_var 但 getter/属性访问返回标量导致类型不匹配
        //   场景1：$big[$id] = "V".$id; 跟踪 arrElementTypes['big']='t_string'，
        //          但 TypeChecker 仍标记 $big[777] 及包含它的 ternary inferredType=mixed → t_var
        //   场景2：$this->columnCount 是 int 属性，但 TypeChecker 标记 inferredType=mixed → t_var
        //   例外2：EnumAccessExpr/NullCoalesceExpr 推导为 void*（Type::$object）时，
        //   TypeChecker 丢失具体 enum/class 类型信息，CodeGenerator 自有逻辑可返回更精确的
        //   tphp_enum_* / tphp_class_* 类型，避免变量声明时误报 void* 需显式声明
        //   场景：$n = Direction::NORTH; $coalesce = Color::tryFrom(99) ?? Color::GREEN;
        $skipInferred = false;
        if ($expr->inferredType !== null) {
            if (($expr instanceof ArrayAccessExpr || $expr instanceof TernaryExpr || $expr instanceof PropertyAccessExpr)
                && $this->inferredTypeToCType($expr->inferredType) === 't_var') {
                $skipInferred = true;
            }
            if (($expr instanceof EnumAccessExpr || $expr instanceof NullCoalesceExpr)
                && $this->inferredTypeToCType($expr->inferredType) === 'void*') {
                $skipInferred = true;
            }
            // ArrayAccessExpr 推导为 void*（Type::$object）时，TypeChecker 丢失元素类型信息，
            // CodeGenerator 自有的 arrElementTypes 可返回更精确的 tphp_enum_*/tphp_class_* 类型
            //   场景：$all = Color::cases(); $all[0]->name — $all[0] 应为 tphp_enum_Color*
            if ($expr instanceof ArrayAccessExpr
                && $this->inferredTypeToCType($expr->inferredType) === 'void*') {
                $skipInferred = true;
            }
            // CastExpr C.* 指针类型转换：TypeChecker 将 C.void* 等 C 类型视为 mixed（t_var），
            //   但 CodeGenerator 的 mapType 可正确解析 C 类型（void*/char*/int* 等）
            //   场景：C.void* $ptr = (C.void*)$buf; — TypeChecker 推导 t_var，实际应为 void*
            if ($expr instanceof CastExpr
                && str_starts_with($expr->castType, 'C.')
                && $this->inferredTypeToCType($expr->inferredType) === 't_var') {
                $skipInferred = true;
            }
            // CastExpr (object) 转换：TypeChecker 推导 Type::$object → void*，
            //   但 castToObject 生成 tphp_fn_stdclass_from_array 返回 tphp_class_stdClass*
            //   场景：$obj = (object) $arr; — 需推导为 tphp_class_stdClass* 而非 void*
            if ($expr instanceof CastExpr
                && $expr->castType === 'object'
                && $this->inferredTypeToCType($expr->inferredType) === 'void*') {
                $skipInferred = true;
            }
        }
        // t_var 变量持有数组的元素访问：C 代码（万能数组 getter）始终返回 t_var，
        // 即使 TypeChecker 推导为 t_int/t_string 等标量类型（PHP 语义正确但 C 实现不同）
        //   场景：$sub = $l0[2]; $sub[0] — sub 是 t_var，sub[0] 的 C 代码返回 t_var
        //   若不覆盖，castToStr/visitBinary 误用 tphp_rt_str_from_int(t_var) 导致编译错误
        if ($expr instanceof ArrayAccessExpr && $this->isActualTVarExpr($expr)) {
            return 't_var';
        }
        if ($expr->inferredType !== null && !$skipInferred) {
            $cType = $this->inferredTypeToCType($expr->inferredType);
            if ($cType !== null) {
                return $cType;
            }
            // 无法转换（如用户类类型），继续走原有逻辑
        }

        // === 原有逻辑 ===
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
            //   注意：t_var 操作数在 visitBinary 中按 unwrapIfMixed 解包为标量后运算，
            //   结果类型应为解包后的标量类型，而非 t_var。
            //   两侧 t_var 按 t_int 解包（visitBinary 默认）；单侧 t_var 按另一侧类型解包。
            $lt = $this->inferType($expr->left);
            $rt = $this->inferType($expr->right);
            if ($lt === 't_var' && $rt === 't_var') return 't_int';
            if ($lt === 't_var') return $rt;
            if ($rt === 't_var') return $lt;
            return $lt;
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
        if ($expr instanceof CallableConvertExpr) {
            // First-class callable 表达式类型恒为 t_callback（值类型，非指针）
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
            // 优先使用类型窄化（instanceof 分支内）
            if (isset($this->narrowedTypes[$objKey])) {
                $objType = $this->narrowedTypes[$objKey];
            } else {
                $objType = $this->varTypes[$objKey] ?? '';
            }
            // varTypes 存的类类型带 *（与 mapType 一致），hasClass/getClassPropType 期望不带 *
            if ($objType !== '' && str_ends_with($objType, '*')) {
                $objType = rtrim($objType, '*');
            }
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
            // 方法调用链式属性访问: func()->prop — 推导函数返回类型
            //   例如 Direction::from("N")->value, Color::cases()[0]->name 中的 func() 返回 enum
            //   注意：递归调用 inferType(CallExpr) → inferCallReturnType，不会回调 PropertyAccessExpr
            if ($objType === '' && $expr->object instanceof CallExpr) {
                $inferred = $this->inferType($expr->object);
                $objType = rtrim($inferred, '*');
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
                // stdClass 动态属性访问 → t_var（运行时 tphp_fn_stdclass_get 返回 t_var）
                if ($objType === 'tphp_class_stdClass') {
                    return 't_var';
                }
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
            // 字符串字符访问 $str[$i]：变量类型为 t_string 时，返回 t_string
            //   与 visitArrayAccess 的 tphp_fn_substr 代码生成保持一致
            if ($expr->array instanceof VariableExpr
                && ($this->varTypes[self::varName($expr->array->name)] ?? '') === 't_string'
                && !($expr->index instanceof StringLiteralExpr)) {
                return 't_string';
            }
            // 优先：泛型数组快速路径（array<int>, array<string>, array<float>, array<bool>）
            //   返回 t_arr_{suffix}* 对应的元素类型，避免被默认分支误判为 t_int
            //   场景：explode() 返回 array<string>，$parts[0] 应推导为 t_string 而非 t_int
            if ($expr->array instanceof VariableExpr) {
                $vn = self::varName($expr->array->name);
                $arrCType = $this->varTypes[$vn] ?? '';
                $genElemCType = self::genericArrayElemCType($arrCType);
                if ($genElemCType !== null) {
                    return $genElemCType;
                }
            }
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
            // (object) 转换 → castToObject 生成 tphp_fn_stdclass_from_array，返回 stdClass
            if ($expr->castType === 'object') {
                return 'tphp_class_stdClass*';
            }
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
            // 偏差3修复：优先使用 TypeChecker 推导的 inferredType（公共类型策略），
            //   与 checkMatchExpr 保持一致；无法转换时回退到首个 arm body 类型
            if ($expr->inferredType !== null) {
                $inferredCType = $this->inferredTypeToCType($expr->inferredType);
                if ($inferredCType !== null) return $inferredCType;
            }
            foreach ($expr->arms as $arm) {
                if (!empty($arm->values)) return $this->inferType($arm->body);
            }
            return 't_int';
        }
        if ($expr instanceof PipeExpr) {
            $t = $this->inferPipeType($expr);
            return $t ?? 't_int';
        }
        return 't_int'; // fallback
    }

    /** 推导 PipeExpr 的返回类型（pipe 表达式类型 = 右侧调用的返回类型） */
    private function inferPipeType(PipeExpr $expr): ?string
    {
        $right = $expr->right;
        // first-class callable（foo(...) / $var(...) / Class::method(...)）→ 单参调用
        if ($right instanceof CallableConvertExpr) {
            try {
                return $this->inferCallReturnType(new CallExpr($right->callee, $right->name, [$expr->left], false, $right->isRawC));
            } catch (\LogicException $e) {
                return null;
            }
        }
        if ($right instanceof CallExpr) {
            // 构造与 visitPipeExpr 相同的变换后 CallExpr 来推导返回类型
            $hasPlaceholder = false;
            foreach ($right->args as $arg) {
                if ($arg instanceof PlaceholderExpr) { $hasPlaceholder = true; break; }
            }
            try {
                if ($hasPlaceholder) {
                    $newArgs = [];
                    foreach ($right->args as $arg) {
                        $newArgs[] = $arg instanceof PlaceholderExpr ? $expr->left : $arg;
                    }
                    return $this->inferCallReturnType(new CallExpr($right->callee, $right->name, $newArgs, $right->isNullsafe, $right->isRawC));
                }
                return $this->inferCallReturnType(new CallExpr($right->callee, $right->name, array_merge($right->args, [$expr->left]), $right->isNullsafe, $right->isRawC));
            } catch (\LogicException $e) {
                return null;
            }
        }
        // callable 变量 → 查闭包签名
        if ($right instanceof VariableExpr) {
            $vn = self::varName($right->name);
            $fnName = $this->symbols->getVarClosure($vn) ?? '';
            if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                return $this->symbols->getClosureSig($fnName)['ret'];
            }
        }
        return null;
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
            // 语言构造：isset / empty → t_bool
            if ($name === 'isset' || $name === 'empty') return 't_bool';
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
        // Raw C call → 优先使用 function C.foo(...): C.ret; 声明的返回类型；
        //              未声明时回退到旧有硬编码逻辑（向后兼容）
        if ($expr->isRawC) {
            $rcName = $expr->name;
            $cFuncInfo = $this->symbols->getCFunction($rcName);
            if ($cFuncInfo !== null) {
                return self::rawCTypeToInferred($cFuncInfo->retType);
            }
            // 回退：旧有硬编码逻辑
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
            } elseif ($expr->callee instanceof ArrayAccessExpr
                || $expr->callee instanceof PropertyAccessExpr) {
                // 数组元素/属性方法调用：$this->children[$i]->method() 或 $this->prop->method()
                //   通过 inferType() 解析对象类型，与 visitCall 中的处理一致
                $objType = $this->inferType($expr->callee);
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

    /** 将任意类型值包装为 t_var（用于 stdClass 属性赋值） */
    private function wrapTVar(string $val, string $srcType): string
    {
        return match ($srcType) {
            't_int'      => "VAR_INT({$val})",
            't_float'    => "VAR_FLOAT({$val})",
            't_bool'     => "VAR_BOOL({$val})",
            't_string'   => "VAR_STRING({$val})",
            't_array*'   => "VAR_ARRAY({$val})",
            'null'       => "VAR_NULL()",
            't_var'      => $val,
            default      => (str_contains($srcType, 'tphp_class_') || str_contains($srcType, 'tphp_enum_')
                            ? "VAR_OBJ((void*)({$val}))"
                            : "VAR_INT({$val})"),
        };
    }

    public function visitAssignPropStmt(AssignPropStmtNode $node): string
    {
        // stdClass 动态属性赋值：$obj->prop = $val → tphp_fn_stdclass_set(obj, STR_LIT("prop"), VAR_XXX(val))
        $pa = $node->target;
        $prop = ltrim($pa->property, '$');
        $assignObjCN = '';
        if ($pa->object instanceof VariableExpr) {
            if ($pa->object->name === 'self' || $pa->object->name === '$this') {
                $assignObjCN = $this->className;
            } elseif (str_starts_with($pa->object->name, '$')) {
                $objType = $this->varTypes[self::varName($pa->object->name)] ?? '';
                $assignObjCN = rtrim($objType, '*');
            }
        }
        if ($assignObjCN === 'tphp_class_stdClass') {
            $obj = $pa->object->accept($this);
            $val = $node->value->accept($this);
            $srcType = $this->inferType($node->value);
            // 按 srcType 包装为 t_var
            $wrapped = $this->wrapTVar($val, $srcType);
            return "tphp_fn_stdclass_set({$obj}, STR_LIT(\"{$prop}\"), {$wrapped});";
        }
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
            // ── 非对称可见性 private(set) 编译期检查 ──
            // PHP 8.4 语义: private(set) 属性只能在声明它的类的方法内赋值
            //   （与 readonly 不同，private(set) 允许在声明类的任意方法内赋值，不限 __construct，且可重复赋值）
            if ($objCN !== '' && !ctype_upper($prop[0] ?? '')) {
                if ($this->symbols->isPropPrivateSet($objCN, $prop)) {
                    $declCN = $this->symbols->getPrivateSetPropDeclaringClass($objCN, $prop);
                    if ($this->className !== $declCN) {
                        // 用声明类的 C 名构造可读的 PHP 类名（去掉 tphp_class_ 前缀）
                        $declPhp = preg_replace('/^tphp_class_/', '', $declCN);
                        throw new \RuntimeException(
                            "Cannot assign private(set) property '{$declPhp}::\${$prop}' "
                            . "outside of its declaring class "
                            . "(private(set) properties can only be written from within the class that declares them)"
                        );
                    }
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
        // 源值为 t_var（mixed）时，需按目标属性类型解包
        $srcType = $this->inferType($node->value);
        $isSrcTVar = ($srcType === 't_var');
        if ($propType === 't_string') {
            // 先求值 RHS 到临时变量，再 free 旧值，再 str_dup 赋值
            // （防止 RHS 读取同一属性时读到已 free 的空串，如 $this->text = substr($this->text,0,1) . "X"）
            if ($isSrcTVar) {
                // mixed → string：用 tphp_fn_strval 提取字符串
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                return "t_string {$tmp} = tphp_fn_strval({$val}); tphp_rt_str_free(&{$target}); {$target} = tphp_rt_str_dup({$tmp});";
            }
            $tmp = '_tmp_' . (++$this->tmpVarCounter);
            return "t_string {$tmp} = {$val}; tphp_rt_str_free(&{$target}); {$target} = tphp_rt_str_dup({$tmp});";
        }
        if ($propType === 't_array*') {
            // 属性持有数组引用：retain 新值，释放旧值（防止外层作用域释放后属性悬空）
            if ($isSrcTVar) {
                // mixed → array：从 t_var 解包 t_array* 指针
                $arrVal = "({$val}).value._array";
                return "tphp_fn_arr_retain({$arrVal}); if ({$target} != NULL) tphp_fn_arr_free({$target}); {$target} = {$arrVal};";
            }
            return "tphp_fn_arr_retain({$val}); if ({$target} != NULL) tphp_fn_arr_free({$target}); {$target} = {$val};";
        }
        // mixed → 标量属性：按属性类型解包 t_var
        if ($isSrcTVar) {
            if ($propType === 't_int') {
                return "{$target} = VAR_AS_INT({$val});";
            }
            if ($propType === 't_float') {
                return "{$target} = (VAR_AS_FLOAT({$val}));";
            }
            if ($propType === 't_bool') {
                return "{$target} = tphp_fn_boolval({$val});";
            }
        }
        // 标量/闭包 → mixed (t_var) 属性：需将源值包装为 t_var
        // 例：$obj->onChange = function() {...}; 闭包返回 t_callback，
        //     但属性类型为 t_var，需 VAR_CALLBACK 包装
        if ($propType === 't_var' && $srcType !== 't_var') {
            if ($srcType === 't_callback') {
                return "{$target} = VAR_CALLBACK({$val});";
            }
            if ($srcType === 't_int') {
                return "{$target} = VAR_INT({$val});";
            }
            if ($srcType === 't_float') {
                return "{$target} = VAR_FLOAT({$val});";
            }
            if ($srcType === 't_bool') {
                return "{$target} = VAR_BOOL({$val});";
            }
            if ($srcType === 'null') {
                return "{$target} = VAR_NULL();";
            }
            if ($srcType === 't_string') {
                // 先求值到临时，再 free 旧值（同上，防止 RHS 读取同一 t_var 属性自身）
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                return "t_string {$tmp} = {$val}; tphp_rt_str_free(&({$target})); {$target} = VAR_STRING(tphp_rt_str_dup({$tmp}));";
            }
            if ($srcType === 't_array*') {
                return "tphp_fn_arr_retain({$val}); if ({$target}.value._array != NULL) tphp_fn_arr_free({$target}.value._array); {$target} = VAR_ARRAY({$val});";
            }
            if (str_contains($srcType, 'tphp_class_')) {
                return "tp_obj_retain((void*){$val}); if ({$target}.value._object != NULL) tp_obj_release((void*){$target}.value._object); {$target} = VAR_OBJ({$val});";
            }
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

            // 显式 array<T>（t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool* 指针）：
            //   走特化快路径，直接调用 tphp_fn_arr_{T}_push，无需 t_var 包装。
            //   语义：$arr[] = $val → $arr = tphp_fn_arr_{T}_push($arr, $val);
            //   （特化 push 可能 realloc，需重新赋值）
            if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $varT, $m)) {
                $rawVal = $node->value->accept($this);  // 原始值，不经 wrapArrayElement
                $fn = "tphp_fn_arr_{$m[1]}_push";
                return "{$var} = {$fn}({$var}, {$rawVal});";
            }

            // byRef 数组：变量已是 t_array**，直接传；非 byRef：取地址
            //   t_var 变量持有数组：从 .value._array 取地址，避免 &t_var 类型不匹配
            if ($varT === 't_var') {
                $arrCode = '&((' . $var . ').value._array)';
            } else {
                $arrCode = $isByRef ? $var : ('&' . $var);
            }

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
        // 跟踪元素类型：
        //   - 非字符串字面量键（int 键、动态字符串键如 "key".$i）：统一追踪 arrElementTypes
        //   - 字符串字面量键：由 per-key 追踪（arrValueTypes）处理，不在此处追踪
        //   例外：空数组 [] 首次赋值时 arrElementTypes 未设置，即使是标量类型也需记录，
        //         否则后续访问会误用默认 t_string getter（字符串键）或 t_var 路径（foreach）
        if (!($node->target->index instanceof StringLiteralExpr) && $node->target->array instanceof VariableExpr) {
            $arrName = self::varName($node->target->array->name);
            $elemType = $this->inferType($node->value);
            if ($elemType !== 'null') {
                $current = $this->arrElementTypes[$arrName] ?? null;
                if ($current === null) {
                    // 空数组首次赋值：确定元素类型（包括 t_int/t_float/t_bool 等默认类型）
                    $this->arrElementTypes[$arrName] = $elemType;
                    if ($elemType === 't_array*' && $node->value instanceof ArrayLiteralExpr) {
                        $nested = $this->inferArrayDeepElementType($node->value);
                        $this->arrNestedTypes[$arrName] = $nested;
                    }
                } elseif ($elemType !== 't_int' && $elemType !== 't_float' && $elemType !== 't_bool') {
                    // 非空数组：仅追踪非默认类型（t_string/t_array*/tphp_class_*/t_callback 等）
                    $this->arrElementTypes[$arrName] = $elemType;
                    if ($elemType === 't_array*' && $node->value instanceof ArrayLiteralExpr) {
                        $nested = $this->inferArrayDeepElementType($node->value);
                        $this->arrNestedTypes[$arrName] = $nested;
                    }
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
            // 特化数组 array<T>（t_arr_int*/t_arr_str* 等）：用特化 setter 避免类型不兼容
            //   通用 setter 期望 t_array*，特化数组是 t_arr_int* 等不同类型
            if ($node->target->array instanceof VariableExpr) {
                $vn = self::varName($node->target->array->name);
                $vt = $this->varTypes[$vn] ?? '';
                if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $vt, $m)) {
                    $rawVal = $this->unwrapTVarForGenericArray($node->value, $vCode, $m[1]);
                    return "{$arr} = tphp_fn_arr_{$m[1]}_set_str({$arr}, {$idx}, {$rawVal});";
                }
            }
            return "{$arr} = tphp_fn_arr_set_str({$arr}, {$idx}, {$val});";
        }
        if ($idxType === 't_int' || $idxType === 't_bool' || $idxType === 't_float') {
            // 特化数组 array<T>（t_arr_int*/t_arr_str* 等）：用特化 setter
            if ($node->target->array instanceof VariableExpr) {
                $vn = self::varName($node->target->array->name);
                $vt = $this->varTypes[$vn] ?? '';
                if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $vt, $m)) {
                    $rawVal = $this->unwrapTVarForGenericArray($node->value, $vCode, $m[1]);
                    return "{$arr} = tphp_fn_arr_{$m[1]}_set_int({$arr}, (t_int)({$idx}), {$rawVal});";
                }
            }
            return "{$arr} = tphp_fn_arr_set_int({$arr}, (t_int)({$idx}), {$val});";
        }
        // key 类型不确定（t_var 等），运行时统一分发
        return "{$arr} = tphp_fn_arr_set_var({$arr}, {$idx}, {$val});";
    }

    /**
     * 为特化数组 array<T> 提取原始 C 值（剥去 wrapArrayElement 添加的 VAR_XXX 包装）。
     *   wrapArrayElement 默认将值包装为 t_var（VAR_INT/VAR_STRING/...）以适配通用 t_array*，
     *   但特化数组 setter 接收原始 T 值（t_int/t_string/t_float/t_bool），需还原。
     * @param ExprNode $valueExpr 原始值表达式
     * @param string $wrappedCode wrapArrayElement 后的代码（含 VAR_XXX 包装）
     * @param string $suffix 特化数组后缀（int/str/float/bool）
     * @return string 原始 C 值代码
     */
    private function unwrapTVarForGenericArray(ExprNode $valueExpr, string $wrappedCode, string $suffix): string
    {
        $rawType = $this->inferType($valueExpr);
        // 直接使用原始表达式代码（避免 wrapArrayElement 的 VAR_XXX 包装）
        $rawCode = $valueExpr->accept($this);
        // 类型不匹配时显式 cast（如 int → t_int 已是同一类型；string → t_string 同）
        return match ($suffix) {
            'int'   => $rawType === 't_int' ? $rawCode : "((t_int)({$rawCode}))",
            'float' => $rawType === 't_float' ? $rawCode : "((t_float)({$rawCode}))",
            'bool'  => $rawType === 't_bool' ? $rawCode : "((t_bool)({$rawCode}))",
            'str'   => $rawCode,  // t_string 原样传递
            default => $rawCode,
        };
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

    /**
     * 检测表达式是否为函数/方法调用，且参数列表中直接包含对 $varName 的引用。
     * 用于数组重赋值时判断是否需要跳过旧指针释放：
     *   $result = func(..., $result, ...) → true（函数可能 realloc 了 $result，旧指针已失效）
     *   $arr = other_func()              → false（$arr 不在参数中，旧指针仍有效）
     */
    private function exprIsCallWithVarArg(?ExprNode $expr, string $varName): bool
    {
        if (!$expr instanceof CallExpr) return false;
        $target = '$' . $varName;
        foreach ($expr->args as $arg) {
            if ($arg instanceof VariableExpr && $arg->name === $target) {
                return true;
            }
        }
        return false;
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

    /**
     * 检测数组字面量是否为真正的混合类型数组（元素类型不一致）。
     *   [1, "foo", 2.5]  → true（int + string + float，foreach 需用 t_var）
     *   ["a", "b", "c"]  → false（全 string，foreach 用 t_string）
     *   [1, 2, 3]        → false（全 int）
     *   [[1,2], [3,4]]   → false（全 t_array*）
     *
     * 用于 foreach 值变量类型推导：混合类型数组必须用 t_var，
     * 否则 inferArrayElementType 会锁定为某个具体类型（如 t_string），
     * 导致 int/float 元素被错误解包为空字符串。
     */
    private function isMixedArrayLiteral(ArrayLiteralExpr $expr): bool
    {
        $types = [];
        foreach ($expr->entries as $entry) {
            $val = $entry->value ?? $entry;
            if ($val === null) continue;
            if ($entry->isSpread) continue;  // spread 元素类型不确定，跳过
            $t = $this->inferType($val);
            if ($t === 'null') continue;
            $types[$t] = true;
        }
        return count($types) > 1;
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
        // 优先使用类型窄化（instanceof 分支内）
        if (isset($this->narrowedTypes[$objKey])) {
            $objType = $this->narrowedTypes[$objKey];
        } else {
            $objType = ($objKey === '$this' || $objKey === 'self')
                ? $this->className
                : ($this->varTypes[$objKey] ?? '');
        }
        // 去掉尾部 *（指针类型）以匹配 SymbolTable key
        $objType = rtrim($objType, '*');
        // 静态属性访问: ClassName::$prop — 解析类名
        if ($objType === '' && $pa->object instanceof VariableExpr
            && !str_starts_with($pa->object->name, '$') && $pa->object->name !== 'self') {
            $resolved = $this->symbols->resolveClass($pa->object->name);
            if ($resolved !== null) $objType = $resolved;
        }
        // 链式数组访问: $catalog[0][0]->prop — 用 inferType 推导对象类型
        //   注意：inferType 对 array<mixed> 元素访问可能返回 t_var（C 代码实际返回 t_var），
        //   但对象元素存储在 t_var 中（VAR_OBJ 包装），需查 arrElementTypes/arrNestedTypes 获取实际类类型
        //   场景：$catalog = [[$i1, $i2], [$i3]]; $catalog[0][0]->title — $catalog[0][0] 是 t_var，但实际持有 Item 对象
        if ($objType === '' && $pa->object instanceof ArrayAccessExpr) {
            $inferred = $this->inferType($pa->object);
            // t_var 表示 array<mixed> 元素访问，查 arrNestedTypes/arrElementTypes 获取实际对象类型
            if ($inferred === 't_var') {
                [$rootArr, $depth] = $this->resolveRootArray($pa->object);
                if ($rootArr !== '' && isset($this->arrNestedTypes[$rootArr])) {
                    $nestedType = $this->arrNestedTypes[$rootArr];
                    if (str_contains($nestedType, 'tphp_class_') || str_contains($nestedType, 'tphp_enum_')) {
                        $inferred = $nestedType;
                    }
                }
                if ($inferred === 't_var' && $pa->object->array instanceof VariableExpr) {
                    $vn = self::varName($pa->object->array->name);
                    $et = $this->arrElementTypes[$vn] ?? null;
                    if ($et !== null && (str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_'))) {
                        $inferred = $et;
                    }
                }
            }
            $objType = rtrim($inferred, '*');
        }
        // EnumName::CASE->value → 直接取 backing 类型
        if ($objType === '' && $pa->object instanceof EnumAccessExpr) {
            $objType = rtrim($this->symbols->getEnumCType($pa->object->enumName) ?? '', '*');
        }
        // 方法调用链式属性访问: func()->prop — 推导函数返回类型
        //   例如 Direction::from("N")->value, $obj->method()->prop
        if ($objType === '' && $pa->object instanceof CallExpr) {
            $inferred = $this->inferType($pa->object);
            $objType = rtrim($inferred, '*');
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
            // stdClass 动态属性 → t_var（运行时类型）
            if ($objType === 'tphp_class_stdClass') {
                return 't_var';
            }
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
        // expr or { ... }; → 包裹在 TP_TRY/TP_CATCH_EX/TP_END_TRY 中（无赋值目标）
        if ($node->expr instanceof OrBlockExpr) {
            return $this->generateExprWithOrBlock($node->expr);
        }
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
        // Lexer 保留原始转义序列（如 \" \n \t \r \\），未做反解析。
        // 这里逐字符扫描，按 C 字符串字面量规则重新转义：
        //   - 已有 \X 转义序列：保留原样（\" \n \t \r \\ 等已是合法 C 转义）
        //   - 原始控制字符（LF/CR/TAB）：转为 \n \r \t
        //   - 原始双引号 "：转为 \"
        //   - 原始反斜杠 \ 不构成合法转义序列时：转为 \\
        $val = $node->value;
        $result = '';
        $len = strlen($val);
        for ($i = 0; $i < $len; $i++) {
            $ch = $val[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $val[$i + 1];
                // 已有合法 C 转义序列：保留 \X 原样
                if (strpos('"\\ntrabefvx?\'01234567', $next) !== false) {
                    $result .= '\\' . $next;
                } else {
                    // \X 不是合法 C 转义（如 \w \d \s 等 regex 模式）
                    // 双写反斜杠使其在 C 字符串中保留为 \X
                    $result .= '\\\\' . $next;
                }
                $i++;
            } elseif ($ch === '\\') {
                // 末尾单独的反斜杠
                $result .= '\\\\';
            } elseif ($ch === '"') {
                $result .= '\\"';
            } elseif ($ch === "\n") {
                $result .= '\\n';
            } elseif ($ch === "\r") {
                $result .= '\\r';
            } elseif ($ch === "\t") {
                $result .= '\\t';
            } else {
                $result .= $ch;
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

        // 基于 inferredType 选择泛型数组类型
        //   array<int>    → t_arr_int*,  使用 tphp_fn_arr_int_*
        //   array<string> → t_arr_str*,  使用 tphp_fn_arr_str_*
        //   array<float>  → t_arr_float*,使用 tphp_fn_arr_float_*
        //   array<bool>   → t_arr_bool*, 使用 tphp_fn_arr_bool_*
        //   array<mixed>  → t_array*,    使用 tphp_fn_arr_*（原函数，无后缀）
        //   array<array<T>>/array<Foo> → t_arr_ptr*, 使用 tphp_fn_arr_ptr_*
        $info = $this->arrayElemTypeInfo($node->inferredType);
        $suffix  = $info['suffix'];
        $arrCType = $info['arrCType'];
        $isVarArr = ($suffix === '');  // array<mixed> 需要将元素包装为 t_var
        // 函数名前缀: suffix 为空时为 'tphp_fn_arr'，否则为 'tphp_fn_arr_{suffix}'
        $fnPfx = $suffix === '' ? 'tphp_fn_arr' : "tphp_fn_arr_{$suffix}";

        $parts[] = "{$arrCType}* {$varName} = {$fnPfx}_create({$cap}); tphp_rt_register((void*){$varName}, 1);";
        $parts[] = "if ({$varName} != NULL) {";
        foreach ($node->entries as $entry) {
            // spread 元素: ...$arr → 调用 tphp_fn_arr_spread 展开源数组
            //   目前仅支持 var 数组的 spread，泛型数组的 spread 需要特化（暂未实现）
            if ($entry->isSpread) {
                $srcCode = $entry->value->accept($this);
                if ($isVarArr) {
                    $parts[] = "{$varName} = tphp_fn_arr_spread({$varName}, {$srcCode});";
                }
                continue;
            }
            $valCode = $entry->value->accept($this);
            // array<mixed> 需要将元素包装为 t_var；其他泛型数组直接存储原始值
            $wrap = $isVarArr
                ? $this->wrapArrayElement($entry->value, $valCode)
                : $valCode;

            if ($entry->key !== null) {
                $keyExpr = $entry->key;
                if ($keyExpr instanceof StringLiteralExpr) {
                    $kc = $keyExpr->accept($this);
                    $parts[] = "{$varName} = {$fnPfx}_set_str({$varName}, {$kc}, {$wrap});";
                } else {
                    $kc = $keyExpr->accept($this);
                    $parts[] = "{$varName} = {$fnPfx}_set_int({$varName}, {$kc}, {$wrap});";
                }
            } else {
                $parts[] = "{$varName} = {$fnPfx}_push({$varName}, {$wrap});";
            }
        }
        $parts[] = '}';
        return implode(' ', $parts);
    }

    /**
     * 根据数组表达式的 inferredType 返回泛型数组类型信息。
     *
     * @param Type|null $type 数组表达式的 inferredType（应为 FLAG_ARRAY 类型）
     * @return array{suffix: string, arrCType: string}
     *   - suffix:  操作函数名后缀（''/'int'/'str'/'float'/'bool'/'ptr'）
     *              '' 表示 array<mixed>，使用原函数名 tphp_fn_arr_*（无后缀）
     *   - arrCType: 数组的 C 类型（不含指针 *，如 't_arr_int'、't_array'）
     */
    private static function arrayElemTypeInfo(?Type $type): array
    {
        // 无 inferredType 或非泛型数组 → 默认 array<mixed>（t_array）
        if ($type === null || !$type->hasFlag(Type::FLAG_ARRAY)) {
            return ['suffix' => '', 'arrCType' => 't_array'];
        }
        $elemType = $type->elemType();
        // array<mixed> → t_array（等价于 t_arr_var，使用原函数名兼容现有代码）
        if ($elemType === null || $elemType->isMixed()) {
            return ['suffix' => '', 'arrCType' => 't_array'];
        }
        // 带修饰符（option/result/pointer）或嵌套数组/对象 → t_arr_ptr
        if ($elemType->flags() !== 0) {
            return ['suffix' => 'ptr', 'arrCType' => 't_arr_ptr'];
        }
        return match ($elemType->idx()) {
            Type::IDX_INT    => ['suffix' => 'int',   'arrCType' => 't_arr_int'],
            Type::IDX_STRING => ['suffix' => 'str',   'arrCType' => 't_arr_str'],
            Type::IDX_FLOAT  => ['suffix' => 'float', 'arrCType' => 't_arr_float'],
            Type::IDX_BOOL   => ['suffix' => 'bool',  'arrCType' => 't_arr_bool'],
            default          => ['suffix' => 'ptr',   'arrCType' => 't_arr_ptr'],
        };
    }

    /**
     * 判断 C 类型字符串是否为数组类型（含泛型数组和万能数组）。
     *   t_array*       → true（array<mixed>）
     *   t_arr_int*     → true（array<int>）
     *   t_arr_str*     → true（array<string>）
     *   t_arr_float*   → true（array<float>）
     *   t_arr_bool*    → true（array<bool>）
     *   t_arr_ptr*     → true（array<array<T>>/array<Foo>）
     *   t_arr_var*     → true（array<mixed> 别名）
     *   t_int/t_string/... → false
     */
    private static function isArrayCType(string $cType): bool
    {
        if ($cType === 't_array*') return true;
        // t_arr_int*, t_arr_str*, t_arr_float*, t_arr_bool*, t_arr_ptr*, t_arr_var*
        return str_starts_with($cType, 't_arr_') && str_ends_with($cType, '*');
    }

    /**
     * 返回泛型数组的元素 C 类型（不含指针 *）。
     *   t_arr_int*   → 't_int'
     *   t_arr_str*   → 't_string'
     *   t_arr_float* → 't_float'
     *   t_arr_bool*  → 't_bool'
     *   t_arr_ptr*   → 'void*'
     *   t_array*     → null（万能数组，元素为 t_var，非泛型数组）
     *   其他         → null
     */
    private static function genericArrayElemCType(string $arrCType): ?string
    {
        if ($arrCType === 't_array*') return null;  // 万能数组，非泛型
        if (!str_starts_with($arrCType, 't_arr_') || !str_ends_with($arrCType, '*')) return null;
        $suffix = substr($arrCType, 6, -1);  // t_arr_{suffix}*
        return match ($suffix) {
            'int'   => 't_int',
            'str'   => 't_string',
            'float' => 't_float',
            'bool'  => 't_bool',
            'ptr'   => 'void*',
            default => null,
        };
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
                't_var' => $ref,
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

    /**
     * 检测闭包体内是否引用了 $this（不递归进入嵌套闭包）。
     * 闭包内 $this->prop 会被编译为 self->prop，需要把 self 指针
     * 作为隐式捕获变量传入，否则 C 编译报 'self' undeclared。
     */
    private function closureUsesThis(array $body): bool
    {
        foreach ($body as $stmt) {
            if ($this->astContainsThis($stmt)) return true;
        }
        return false;
    }

    private function astContainsThis(object $node): bool
    {
        if ($node instanceof VariableExpr && $node->name === '$this') return true;
        // 不递归进入嵌套闭包（嵌套闭包的 $this 由自身捕获）
        if ($node instanceof ClosureExpr) return false;
        foreach ((array)$node as $prop) {
            if (is_array($prop)) {
                foreach ($prop as $item) {
                    if (is_object($item) && $this->astContainsThis($item)) return true;
                }
            } elseif (is_object($prop)) {
                if ($this->astContainsThis($prop)) return true;
            }
        }
        return false;
    }

    /**
     * 箭头函数自动捕获（PHP 8 语义）：
     *   扫描 body 中引用的外层作用域变量，自动加入 useVars（按值捕获）。
     *   - 排除箭头函数自身的参数
     *   - 排除 $this（由 closureUsesThis 单独处理）
     *   - 排除嵌套闭包内的变量（属于嵌套闭包自己的作用域）
     *   - 仅捕获外层 varTypes 中已存在的变量（纯局部变量不捕获）
     *
     * @param ClosureExpr $node 箭头函数节点（isArrow=true）
     * @param array<array{string,string}> $existingUseVars 现有 use 列表
     * @return array<array{string,string}> 合并后的捕获列表
     */
    private function arrowAutoCapture(ClosureExpr $node, array $existingUseVars): array
    {
        // 参数名集合（不含 $ 前缀）
        $paramNames = [];
        foreach ($node->params as $p) {
            $paramNames[self::varName($p->name)] = true;
        }
        // 已在捕获列表中的变量名
        $existingNames = [];
        foreach ($existingUseVars as [$vn, $_]) {
            $existingNames[$vn] = true;
        }
        // 扫描 body 收集外层变量引用
        $referenced = [];
        foreach ($node->body as $stmt) {
            $this->collectVarRefsForCapture($stmt, $paramNames, $referenced);
        }
        // 仅捕获外层 varTypes 中存在的变量
        foreach (array_keys($referenced) as $vn) {
            if (isset($existingNames[$vn])) continue;
            if ($vn === 'self') continue;  // $this 由 closureUsesThis 处理
            if (isset($this->varTypes[$vn])) {
                $existingUseVars[] = [$vn, ''];
            }
        }
        return $existingUseVars;
    }

    /**
     * 递归收集 AST 节点中的变量引用（VariableExpr），用于箭头函数自动捕获。
     * 不递归进入嵌套 ClosureExpr（嵌套闭包有自己的作用域）。
     *
     * @param object $node AST 节点
     * @param array<string,bool> $paramNames 箭头函数参数名集合（不含 $）
     * @param array<string,bool> $result 收集到的变量名（不含 $）→ true
     */
    private function collectVarRefsForCapture(object $node, array $paramNames, array &$result): void
    {
        if ($node instanceof VariableExpr) {
            // $this 由 closureUsesThis 单独处理，不参与自动捕获
            if ($node->name === '$this') return;
            $vn = self::varName($node->name);
            if (!isset($paramNames[$vn])) {
                $result[$vn] = true;
            }
            return;
        }
        // 不递归进入嵌套普通闭包（它们有自己的作用域，需显式 use）
        // 但嵌套箭头函数需要递归（PHP 8 语义：外层箭头函数捕获内层箭头函数引用的变量）
        if ($node instanceof ClosureExpr && !$node->isArrow) return;

        foreach ((array)$node as $prop) {
            if (is_array($prop)) {
                foreach ($prop as $item) {
                    if (is_object($item)) {
                        $this->collectVarRefsForCapture($item, $paramNames, $result);
                    }
                }
            } elseif (is_object($prop)) {
                $this->collectVarRefsForCapture($prop, $paramNames, $result);
            }
        }
    }

    public function visitClosure(ClosureExpr $node): string
    {
        if ($node->isGenerator) {
            return $this->emitGeneratorClosure($node);
        }
        $id = ++$this->closureCounter;
        $name  = "_closure_{$id}";
        $capName = "_cap_{$id}";
        // 箭头函数自动捕获：扫描 body 中引用的外层变量，自动加入 useVars
        $effectiveUseVars = $node->useVars;
        if ($node->isArrow) {
            $effectiveUseVars = $this->arrowAutoCapture($node, $effectiveUseVars);
        }
        $hasCapture = !empty($effectiveUseVars);
        $ret = self::mapType($node->returnType);

        // 检测闭包内是否使用 $this：若是，把 self 作为隐式捕获变量
        // （闭包体中 $this->prop 编译为 self->prop，需要 self 指针可用）
        $usesThis = ($this->className !== '') && $this->closureUsesThis($node->body);

        // 查询捕获变量的类型（外层作用域）
        $capFields = [];
        $capInits  = [];
        $capDecls  = [];
        $capAssigns = []; // heap allocation assignments: _env_N->var = var;
        $hasObjCapture = false;  // 是否捕获了对象类型（需要 retain/release）
        $arrCapFields = [];      // Task 9: 数组类型捕获字段 [varName, cType]，需要 make_shared
        foreach ($effectiveUseVars as [$vn, $_]) {
            $ct = $this->varTypes[$vn] ?? 't_int';
            // null 类型 → void*，t_var 保持原样，对象类型加 *
            $isObj = false;
            if ($ct === 'null') {
                $ct = 'void*';
            } elseif (str_contains($ct, 'tphp_class_')) {
                if (!str_ends_with($ct, '*')) $ct .= '*';
                $isObj = true;
                $hasObjCapture = true;
            }
            // Task 9: 检测数组类型捕获（t_array* 或任意 t_arr_* 特化类型）
            //   方案 C（保守策略）：对所有闭包中的数组捕获字段调用 make_shared，
            //   确保 Thread/Parallel 场景下跨线程安全。make_shared 是幂等的，
            //   对单线程数组无副作用（仅多一次遍历）。
            if ($ct === 't_array*' || (str_starts_with($ct, 't_arr_') && str_ends_with($ct, '*'))) {
                $arrCapFields[] = [$vn, $ct];
            }
            $capFields[]  = "    {$ct} {$vn};";
            $capInits[]   = "    .{$vn} = {$vn}";
            $capDecls[]   = "    {$ct} {$vn} = _e->{$vn};";
            // 对象类型: retain 增加引用计数，防止外层作用域释放后闭包内悬空
            $capAssigns[] = $isObj
                ? "    _env_{$id}->{$vn} = {$vn}; tp_obj_retain((void*){$vn});"
                : "    _env_{$id}->{$vn} = {$vn};";
        }
        // $this 隐式捕获：把当前类 self 指针加入 _cap_N 结构
        if ($usesThis) {
            $selfCType = $this->className . '*';
            $capFields[]  = "    {$selfCType} self;";
            $capInits[]   = "    .self = self";
            $capDecls[]   = "    {$selfCType} self = _e->self;";
            // self 是对象指针，需要 retain
            $capAssigns[] = "    _env_{$id}->self = self; tp_obj_retain((void*)self);";
            $hasCapture = true;
            $hasObjCapture = true;
        }

        // 有对象捕获时，env 第一个字段是析构函数指针（type=5 释放时调用）
        if ($hasObjCapture) {
            array_unshift($capFields, "    void (*dtor)(void*);");
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
            // Task 5.4: array<T> 参数 — 记录元素类型
            $this->applyArrayElemType($vn, $p->type);
        }

        $implLines = [];
        $implLines[] = "{$ret} {$name}({$paramStr}) {";
        if ($hasCapture) {
            // 从 void* env 转回捕获 struct，声明局部引用
            $implLines[] = "    {$capName}* _e = ({$capName}*)_env;";
            foreach ($capDecls as $d) { $implLines[] = $d; }
            foreach ($effectiveUseVars as [$vn, $_]) {
                $this->declaredVars[$vn] = true;
                $ct = $savedTypes[$vn] ?? 't_int';
                $this->varTypes[$vn] = ($ct === 'null') ? 'void*' : $ct;
            }
            // $this 隐式捕获：在闭包内设置 self 的类型，供 inferType 使用
            if ($usesThis) {
                $this->declaredVars['self'] = true;
                $this->varTypes['self'] = $this->className . '*';
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

        // 生成 env 析构函数（释放捕获的对象引用）
        if ($hasObjCapture) {
            $dtorName = "_env_dtor_{$id}";
            // 前置声明：析构函数在 SEC_CLOSURES 中定义，但可能在 SEC_CLSIMPL/SEC_FUNCIMPL 中被引用
            $this->sectionLine(self::SEC_FWDDECLS, "static void {$dtorName}(void* env);");
            $dtorLines = ["static void {$dtorName}(void* env) {", "    {$capName}* e = ({$capName}*)env;"];
            foreach ($effectiveUseVars as [$vn, $_]) {
                $ct = $this->varTypes[$vn] ?? 't_int';
                if (str_contains($ct, 'tphp_class_')) {
                    $dtorLines[] = "    if (e->{$vn}) tp_obj_release((void*)e->{$vn});";
                }
            }
            if ($usesThis) {
                $dtorLines[] = "    if (e->self) tp_obj_release((void*)e->self);";
            }
            $dtorLines[] = "}";
            $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $dtorLines));
        }

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
        // Task 9: 构建数组捕获字段的 make_shared 调用（方案 C 保守策略）
        //   对 env 中每个数组字段调用对应的 make_shared 函数，
        //   确保闭包传递给 Thread/Parallel 时数组为跨线程安全形态。
        //   t_array*/t_arr_var* → tphp_fn_arr_make_shared / tphp_fn_arr_var_make_shared
        //   t_arr_int*/t_arr_str* 等特化类型 → tphp_fn_arr_{int,str,...}_make_shared
        //   注意：不能用 (t_array*)cast 调用通用 make_shared，因为特化数组的 entry 布局不同，
        //   通用 make_shared 会以 sizeof(t_arr_entry) 步长遍历，导致内存越界。
        $arrMakeSharedLines = '';
        if (!empty($arrCapFields)) {
            $calls = [];
            foreach ($arrCapFields as [$avn, $act]) {
                if ($act === 't_array*') {
                    $fn = 'tphp_fn_arr_make_shared';
                } elseif ($act === 't_arr_var*') {
                    $fn = 'tphp_fn_arr_var_make_shared';
                } else {
                    // t_arr_int* → tphp_fn_arr_int_make_shared, etc.
                    $fn = 'tphp_fn_arr_' . str_replace(['t_arr_', '*'], '', $act) . '_make_shared';
                }
                $calls[] = "        {$fn}(_env_{$id}->{$avn});";
            }
            $arrMakeSharedLines = implode("\n", $calls) . "\n";
        }
        $envDecl = $hasCapture
            ? "    {$capName}* _env_{$id} = ({$capName}*)calloc(1, sizeof({$capName}));\n"
              . "    if (_env_{$id} != NULL) {\n"
              . ($hasObjCapture ? "        _env_{$id}->dtor = _env_dtor_{$id};\n" : "")
              . implode("\n", $capAssigns) . "\n"
              . $arrMakeSharedLines
              . "    }\n"
              . "    tphp_rt_register((void*)_env_{$id}, " . ($hasObjCapture ? '5' : '3') . ");\n"
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

        // 检测闭包内是否使用 $this：若是，把 self 作为隐式捕获变量
        $usesThis = ($this->className !== '') && $this->closureUsesThis($node->body);

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
        // $this 隐式捕获：把当前类 self 指针加入 _cap_N 结构
        if ($usesThis) {
            $selfCType = $this->className . '*';
            $capTypes['self'] = $selfCType;
            $capFields[]  = "    {$selfCType} self;";
            $capInits[]   = "    .self = self";
            $capDecls[]   = "    {$selfCType} self = _e->self;";
            $capAssigns[] = "    _env_{$id}->self = self;";
            $hasCapture = true;
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
            // $this 隐式捕获：在闭包内设置 self 的类型
            if ($usesThis) {
                $this->declaredVars['self'] = true;
                $this->varTypes['self'] = $this->className . '*';
                $paramVars['self'] = true;
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
            // $this 隐式捕获：从 _p 解包 self 指针（声明在 $capFields 中，这里只赋值）
            if ($usesThis) {
                $unpackLines[] = "    self = _p->self;";
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
            // $this 隐式捕获：从 _e 复制 self 指针到 _p
            if ($usesThis) {
                $packAssigns[] = "    _p->self = _e->self;";
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
        $inner = $node->expr->accept($this);
        // t_var 操作数需要先解包为标量再参与一元运算
        if ($this->isActualTVarExpr($node->expr)) {
            return match ($node->operator) {
                '!'  => '(!VAR_AS_BOOL(' . $inner . '))',
                '-'  => '(-VAR_AS_INT(' . $inner . '))',
                default => $node->operator . '(' . $inner . ')',
            };
        }
        return $node->operator . '(' . $inner . ')';
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
                // t_var（mixed）：检查 .type == TYPE_NULL（如 Channel::pop() 关闭后返回 null）
                if ($otype === 't_var') {
                    return $isEq
                        ? "({$other}.type == TYPE_NULL)"
                        : "({$other}.type != TYPE_NULL)";
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

        // instanceof → tp_obj_is_a check（必须在 t_var 解包之前处理，
        //   因为 instanceof 需要对象指针而非 int 解包）
        if ($node->operator === 'instanceof') {
            // t_var 左操作数：提取对象指针（.value._object）
            if ($lt === 't_var') {
                $lCode = '(' . $lCode . ').value._object';
            }
            // 右操作数为类名（裸标识符，非 $variable）时，直接使用 tphp_class_<Name>
            if ($node->right instanceof VariableExpr && !str_starts_with($node->right->name, '$')) {
                $className = $node->right->name;
                return 'tp_obj_is_a(' . $lCode . ', &_class_tphp_class_' . $className . ')';
            }
            $rCN = $node->right instanceof VariableExpr ? rtrim($this->varTypes[self::varName($node->right->name)] ?? '', '*') : '';
            $rCN = ($rCN === '' && $node->right instanceof StringLiteralExpr) ? $node->right->value : $rCN;
            // If right is a class name identifier (not variable), look up in classRefName
            if ($node->right instanceof AST\IdentifierExpr ?? null) {
                // Actually in PHP $obj instanceof ClassName, ClassName is parsed as a class reference
            }
            return 'tp_obj_is_a(' . $lCode . ', &_class_' . $rCode . ')';
        }

        // 对 t_var 操作数解包
        // 两侧均为 t_var 时，算术/比较运算默认按 int 解包（PHP 数值语义）
        if ($lt === 't_var') {
            $expect = ($rt === 't_var') ? 't_int' : $rt;
            $lCode = $this->unwrapIfMixed($node->left, $lCode, $expect);
        }
        if ($rt === 't_var') {
            $expect = ($lt === 't_var') ? 't_int' : $lt;
            $rCode = $this->unwrapIfMixed($node->right, $rCode, $expect);
        }
        // 对 t_string 操作数 vs 标量比较，转 int（PHP 语义：string > int 时 string 转 int）
        // 用类型推断而非 str_contains 模式匹配，避免误匹配嵌套在 strlen(...) 等调用内的 get_str_str
        if ($lt === 't_string' && in_array($rt, ['t_int', 't_float', 't_bool'], true)) {
            $lCode = 'tphp_rt_parse_int(' . $lCode . ')';
        }
        if ($rt === 't_string' && in_array($lt, ['t_int', 't_float', 't_bool'], true)) {
            $rCode = 'tphp_rt_parse_int(' . $rCode . ')';
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
        // 类型对齐：当一个分支为 t_var（mixed），另一个为标量类型时，
        //   将 t_var 解包为对应标量类型，避免 C 条件表达式类型不匹配
        //   场景：$port = $handle->port != 0 ? $handle->port : $urlInfo["port"];
        //   $handle->port 为 t_int，$urlInfo["port"] 为 t_var → 需解包
        $thenType = $this->inferType($node->thenExpr);
        $elseType = $this->inferType($node->elseExpr);
        if ($thenType === 't_var' && $elseType !== 't_var') {
            $then = $this->unwrapTVarToType($then, $elseType);
        } elseif ($elseType === 't_var' && $thenType !== 't_var') {
            $else = $this->unwrapTVarToType($else, $thenType);
        }
        return '(' . $cond . ' ? ' . $then . ' : ' . $else . ')';
    }

    /**
     * 将 t_var 表达式解包为目标标量类型
     *   用于三元表达式、赋值等需要类型对齐的场景
     */
    private function unwrapTVarToType(string $code, string $targetType): string
    {
        return match ($targetType) {
            't_int'    => "VAR_AS_INT({$code})",
            't_float'  => "VAR_AS_FLOAT({$code})",
            't_bool'   => "tphp_fn_boolval({$code})",
            't_string' => "tphp_fn_strval({$code})",
            default    => $code,
        };
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
        // enum*/class* 指针类型：tryFrom() 等可能返回 NULL，需运行时检查
        //   例如 Color::tryFrom(99) ?? Color::GREEN
        if (self::isEnumCType($lt) || self::isClassCType($lt)) {
            $right = $node->right->accept($this);
            return '(' . $left . ' != NULL ? ' . $left . ' : ' . $right . ')';
        }
        // t_string 来自 nullsafe ?-> 时可能为 {NULL,0}（"null" 哨兵值），需检查 data 字段
        //   例如 $x = Color::tryFrom(99)?->label(); $x ?? "default"
        if ($lt === 't_string' && $this->exprMayBeNull($node->left)) {
            $right = $node->right->accept($this);
            return '(' . $left . '.data != NULL ? ' . $left . ' : ' . $right . ')';
        }
        // 其他值类型：编译期已知非 null，直接返回 left
        return $left;
    }

    /**
     * 判断表达式是否可能产生 "null" 值（用于 ?? 右侧求值决策）
     *   - nullsafe ?-> 调用/属性访问：返回零值（t_string 的 {NULL,0}）
     *   - tryFrom() 调用：返回 NULL 指针
     *   - 变量来自上述表达式：通过 varTypes 追踪 nullable 标记
     */
    private function exprMayBeNull(ExprNode $expr): bool
    {
        // nullsafe 方法调用/属性访问 → 结果可能为零值
        if ($expr instanceof CallExpr && $expr->isNullsafe) return true;
        if ($expr instanceof PropertyAccessExpr && $expr->isNullsafe) return true;
        // 变量引用：检查 varNullable 标记
        if ($expr instanceof VariableExpr && str_starts_with($expr->name, '$')) {
            $vn = self::varName($expr->name);
            return $this->varNullable[$vn] ?? false;
        }
        return false;
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
        // 嵌套访问 $a[k1][k2]：$aa->array 是 ArrayAccessExpr，可能返回 t_var
        //   tphp_fn_array_key_exists_* 期望 t_array*，需从 t_var 提取 .value._array
        if ($aa->array instanceof ArrayAccessExpr) {
            $parentType = $this->inferArrayAccessInnerType($aa->array);
            if ($parentType === 't_var') {
                $arrCode = "(({$arrCode}).value._array)";
            }
        } elseif ($aa->array instanceof VariableExpr) {
            $vn = self::varName($aa->array->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                $arrCode = "(({$arrCode}).value._array)";
            }
        }
        if ($idxType === 't_string' || $aa->index instanceof StringLiteralExpr) {
            $existsCheck = "tphp_fn_array_key_exists_str({$idxCode}, {$arrCode})";
        } else {
            $existsCheck = "tphp_fn_array_key_exists_int((t_int)({$idxCode}), {$arrCode})";
        }
        // 数组元素为 t_var（array<mixed>）但默认值为标量时，解包 t_var 为对应标量类型，
        // 避免 ternary 两端类型不匹配（t_var vs t_string/t_int/...）
        //   注意：inferType 可能基于 arrNestedTypes 返回叶子类型（如 t_string），
        //   但实际 C 代码 visitArrayAccess 对 t_array* 万能数组返回 *tphp_fn_arr_get_str(...) 即 t_var。
        //   因此用 arrElementTypes[rootArr] === 't_var' 判定实际元素类型。
        $leftType = $this->inferType($aa);
        [$rootArr] = $this->resolveRootArray($aa);
        if ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var') {
            $leftType = 't_var';
        }
        $rightType = $this->inferType($node->right);
        if ($leftType === 't_var' && $rightType !== 't_var' && $rightType !== '') {
            $unwrap = match ($rightType) {
                't_int'    => 'VAR_AS_INT',
                't_float'  => 'VAR_AS_FLOAT',
                't_string' => 'VAR_AS_STRING',
                't_bool'   => 'VAR_AS_BOOL',
                default    => null,
            };
            if ($unwrap !== null) {
                $valueCode = "{$unwrap}({$valueCode})";
            }
        }
        return "({$existsCheck} ? {$valueCode} : {$right})";
    }

    public function visitMatchExpr(MatchExpr $node): string
    {
        $tmp = '_match_' . (++$this->tmpVarCounter);
        $condCode = $node->condition->accept($this);
        $condType = $this->inferType($node->condition);
        // 偏差3修复：结果类型优先使用 TypeChecker 推导的 inferredType（公共类型策略），
        //   与 checkMatchExpr 保持一致；无法转换时回退到首个 arm body 类型
        $resultType = 't_int';
        if ($node->inferredType !== null) {
            $inferredCType = $this->inferredTypeToCType($node->inferredType);
            if ($inferredCType !== null) $resultType = $inferredCType;
        }
        if ($resultType === 't_int') {
            // 回退：取首个非 default arm body 类型
            foreach ($node->arms as $arm) {
                if (!empty($arm->values)) {
                    $resultType = $this->inferType($arm->body) ?: 't_int';
                    break;
                }
            }
        }

        $lines = [];
        $lines[] = "({ {$resultType} {$tmp};";
        // 检测是否有 default arm（values 为空即为 default）
        $hasDefault = false;
        foreach ($node->arms as $arm) {
            if (empty($arm->values)) { $hasDefault = true; break; }
        }
        if (!$hasDefault) {
            // 偏差2修复：无 default 且无匹配时抛 UnhandledMatchError（PHP 8.0+ 规范）
            //   仍需初始化零值，因为 tp_throw_ex 是 longjmp，编译器静态分析可能警告未初始化
            $zeroVal = match ($resultType) {
                't_string' => '(t_string){NULL, 0}',
                't_float' => '0.0',
                't_bool' => 'false',
                't_array*' => 'NULL',
                't_callback' => '(t_callback){NULL, NULL}',
                't_var' => 'VAR_NULL()',
                default => str_ends_with($resultType, '*') ? 'NULL' : '0',
            };
            $lines[] = "    {$tmp} = {$zeroVal};";
        }
        $first = true;
        foreach ($node->arms as $arm) {
            if (empty($arm->values)) {
                // default arm
                // throw 表达式永不返回，不赋值给 tmp（避免类型不匹配的编译错误）
                if ($arm->body instanceof ThrowExprNode) {
                    $throwCode = $this->genThrowCode($arm->body->expr);
                    $lines[] = "    else { {$throwCode}; }";
                } else {
                    $bodyCode = $arm->body->accept($this);
                    // 结果类型为 t_var（union 返回类型）：default arm body 需包装为 VAR_* 宏
                    if ($resultType === 't_var') {
                        $bodyCode = $this->wrapTvarAssign($arm->body, $bodyCode);
                    }
                    $lines[] = "    else { {$tmp} = {$bodyCode}; }";
                }
            } else {
                $prefix = $first ? '    if (' : '    else if (';
                $first = false;
                $conds = [];
                foreach ($arm->values as $v) {
                    $vCode = $v->accept($this);
                    $vType = $this->inferType($v);
                    // 偏差1修复：PHP 8.0+ match 使用严格比较 ===
                    //   两边都是 string：tphp_rt_str_eq（内容比较）
                    //   类型一致（非 string）：用 ==（类型相同，等价于 ===）
                    //   类型不一致：生成 (0) 永不匹配（模拟 === 类型严格）
                    if ($condType === 't_string' && $vType === 't_string') {
                        $conds[] = "tphp_rt_str_eq({$condCode}, {$vCode})";
                    } elseif ($condType === $vType) {
                        $conds[] = "({$condCode} == {$vCode})";
                    } else {
                        // 类型不一致：严格比较下永不匹配（如 int vs float, int vs string, int vs bool）
                        $conds[] = "(0)";
                    }
                }
                // throw 表达式永不返回，不赋值给 tmp
                if ($arm->body instanceof ThrowExprNode) {
                    $throwCode = $this->genThrowCode($arm->body->expr);
                    $lines[] = $prefix . implode(' || ', $conds) . ") { {$throwCode}; }";
                } else {
                    $bodyCode = $arm->body->accept($this);
                    // 结果类型为 t_var（union 返回类型）：arm body 需包装为 VAR_* 宏
                    if ($resultType === 't_var') {
                        $bodyCode = $this->wrapTvarAssign($arm->body, $bodyCode);
                    }
                    $lines[] = $prefix . implode(' || ', $conds) . ") { {$tmp} = {$bodyCode}; }";
                }
            }
        }
        // 偏差2修复：无 default 时，所有 arm 都不匹配则抛 UnhandledMatchError
        if (!$hasDefault) {
            $lines[] = "    else { tp_throw_ex(new_tphp_class_UnhandledMatchError(STR_LIT(\"Unhandled match case\"))); }";
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

        // 情况 0：右操作数为 first-class callable（foo(...) / $var(...) / Class::method(...)）
        //   在 pipe 上下文中，占位符 `...` 表示用 left 替换，而非创建闭包
        if ($right instanceof CallableConvertExpr) {
            $newCall = new CallExpr($right->callee, $right->name, [$node->left], false, $right->isRawC);
            return $this->visitCall($newCall);
        }

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

    /**
     * First-class callable: foo(...) / $obj->method(...) / Class::method(...)
     *
     *   生成 t_callback 包装器：
     *   - 内置/用户函数：(t_callback){ .func = (void*)funcName, .env = NULL }
     *   - 方法引用：通过 thunk 函数包装，env 捕获 self
     *
     *   同时注册闭包签名到 SymbolTable，使后续 $cb(...) 调用走 generateClosureCall
     *   时能正确 cast 函数指针。
     */
    public function visitCallableConvert(CallableConvertExpr $node): string
    {
        // 1. 全局函数（内置或用户函数）：foo(...)
        if ($node->callee === null) {
            [$code, $sigName] = $this->emitFunctionCallableConvert($node->name);
            $this->lastCallableConvertSig = $sigName;
            return $code;
        }

        // 2. $var(...) — 闭包变量的 first-class callable（语义上等同于直接传 $var）
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && $node->name === '__invoke') {
            // 直接返回该变量（已是 t_callback）
            $this->lastCallableConvertSig = null;
            return self::varName($node->callee->name);
        }

        // 3. C->func(...) — C 函数引用
        if ($node->isRawC) {
            $cFuncName = $node->name;
            $cInfo = $this->symbols->getCFunction($cFuncName);
            if ($cInfo !== null) {
                $this->symbols->addClosureSig($cFuncName, [
                    'ret'    => $cInfo->retType,
                    'params' => implode(', ', $cInfo->paramTypes),
                ]);
            }
            $this->lastCallableConvertSig = $cFuncName;
            return "(t_callback){ .func = (void*){$cFuncName}, .env = NULL }";
        }

        // 4. Class::method(...) / $obj->method(...) — 方法引用
        [$code, $sigName] = $this->emitMethodCallableConvert($node);
        $this->lastCallableConvertSig = $sigName;
        return $code;
    }

    /**
     * 为全局函数（内置或用户函数）生成 t_callback 包装器
     *   返回 [code, sigName]，sigName 为闭包签名名（用于后续变量绑定）
     *
     * @return array{0:string,1:string}
     */
    private function emitFunctionCallableConvert(string $name): array
    {
        // 内置函数：从 $simpleFnMap 查 C 名
        if (isset(self::$simpleFnMap[$name])) {
            $cName = self::$simpleFnMap[$name]['cName'];
            $sig = $this->getBuiltinFnSig($name);
            if ($sig !== null) {
                $this->symbols->addClosureSig($cName, $sig);
            }
            return ["(t_callback){ .func = (void*){$cName}, .env = NULL }", $cName];
        }

        // 用户函数：推导 C 名（含命名空间）
        $pos = strrpos($name, '\\');
        if ($pos !== false) {
            $ns = substr($name, 0, $pos);
            $fn = substr($name, $pos + 1);
            $cName = 'tphp_na_' . self::mangleCName($ns) . '_tphp_fn_' . $fn;
        } else {
            $cName = 'tphp_fn_' . self::mangleCName($name);
        }

        // 查询用户函数签名
        $fnInfo = $this->symbols->getFunc($cName);
        if ($fnInfo !== null) {
            $this->symbols->addClosureSig($cName, [
                'ret'    => $fnInfo->retType,
                'params' => implode(', ', $fnInfo->paramTypes),
            ]);
        }
        return ["(t_callback){ .func = (void*){$cName}, .env = NULL }", $cName];
    }

    /** 常见内置函数的精确参数 C 类型映射（用于 first-class callable 签名注册）
     *  未列出的内置函数回退到 t_var（万能类型，依赖 ABI 兼容性） */
    private static array $builtinFnParamCtypes = [
        // string → int
        'strlen'    => ['t_string'],
        'strpos'    => ['t_string', 't_string'],
        'strrpos'   => ['t_string', 't_string'],
        'ord'       => ['t_string'],
        'str_word_count' => ['t_string'],
        // string → string
        'trim'      => ['t_string'],
        'ltrim'     => ['t_string'],
        'rtrim'     => ['t_string'],
        'strtolower'=> ['t_string'],
        'strtoupper'=> ['t_string'],
        'ucfirst'   => ['t_string'],
        'lcfirst'   => ['t_string'],
        'strrev'    => ['t_string'],
        'substr'    => ['t_string', 't_int', 't_int'],
        // int → int
        'abs'       => ['t_int'],
        // int → float / float → float
        'sqrt'      => ['t_float'],
        'floor'     => ['t_float'],
        'ceil'      => ['t_float'],
        'round'     => ['t_float'],
        // 文件路径（内置）
        'dirname'   => ['t_string', 't_int'],
        'basename'  => ['t_string', 't_string'],
        // pcntl 扩展（C 函数，参数类型需显式注册以支持 byRef 和 t_var 解包）
        'pcntl_fork'           => [],
        'pcntl_wait'           => ['t_int*'],
        'pcntl_waitpid'        => ['t_int', 't_int*', 't_int'],
        'pcntl_exec'           => ['t_string'],
        'pcntl_alarm'          => ['t_int'],
        'pcntl_get_last_error' => [],
        'pcntl_strerror'       => ['t_int'],
        // posix 扩展（C 函数，参数类型需显式注册以支持 t_var 解包）
        'posix_getpid'         => [],
        'posix_getppid'        => [],
        'posix_getuid'         => [],
        'posix_geteuid'        => [],
        'posix_getgid'         => [],
        'posix_getegid'        => [],
        'posix_isatty'         => ['t_int'],
        'posix_kill'           => ['t_int', 't_int'],
        'posix_get_last_error' => [],
        'posix_getcwd'         => [],
        'posix_strerror'       => ['t_int'],
        'posix_ttyname'        => ['t_int'],
    ];

    /**
     * 内置函数参数名映射表（PHP 官方参数名）
     *
     * 用于命名参数解析：str_replace(search: 'a', replace: 'b', subject: $s)
     *   → str_replace('a', 'b', $s)
     *
     * 仅包含最常用的内置函数；未列入的内置函数不支持命名参数（用户需用位置参数）。
     * 用户函数和方法签名始终支持命名参数。
     */
    private static array $builtinFnParamNames = [
        // 字符串函数
        'substr'         => ['string', 'offset', 'length'],
        'strpos'         => ['haystack', 'needle', 'offset'],
        'strrpos'        => ['haystack', 'needle', 'offset'],
        'str_contains'   => ['haystack', 'needle'],
        'str_starts_with'=> ['haystack', 'needle'],
        'str_ends_with'  => ['haystack', 'needle'],
        'str_replace'    => ['search', 'replace', 'subject'],
        'str_repeat'     => ['string', 'times'],
        'str_pad'        => ['string', 'length', 'pad_string', 'pad_type'],
        'str_split'      => ['string', 'length'],
        'strrev'         => ['string'],
        'strtolower'     => ['string'],
        'strtoupper'     => ['string'],
        'ucfirst'        => ['string'],
        'lcfirst'        => ['string'],
        'trim'           => ['string', 'characters'],
        'ltrim'          => ['string', 'characters'],
        'rtrim'          => ['string', 'characters'],
        'strlen'         => ['string'],
        'explode'        => ['separator', 'string', 'limit'],
        'implode'        => ['separator', 'array'],
        'join'           => ['separator', 'array'],
        'sprintf'        => ['format'],
        'vsprintf'       => ['format', 'args'],
        'printf'         => ['format'],
        'htmlspecialchars' => ['string', 'flags', 'encoding', 'double_encode'],
        'htmlentities'   => ['string', 'flags', 'encoding', 'double_encode'],
        'html_entity_decode' => ['string', 'flags', 'encoding'],
        'number_format'  => ['num', 'decimals', 'decimal_separator', 'thousands_separator'],
        'wordwrap'       => ['string', 'width', 'break', 'cut_long_words'],
        'nl2br'          => ['string', 'use_xhtml'],
        'addslashes'     => ['string'],
        'stripslashes'   => ['string'],
        'urlencode'      => ['string'],
        'urldecode'      => ['string'],
        'rawurlencode'   => ['string'],
        'rawurldecode'   => ['string'],
        'base64_encode'  => ['string'],
        'base64_decode'  => ['string', 'strict'],
        'md5'            => ['string'],
        'sha1'           => ['string'],
        'crc32'          => ['string'],
        'levenshtein'    => ['string1', 'string2'],
        'similar_text'   => ['string1', 'string2'],
        'soundex'        => ['string'],
        'metaphone'      => ['string'],
        'chunk_split'    => ['string', 'length', 'separator'],
        'wordwrap'       => ['string', 'width', 'break', 'cut_long_words'],
        // 数学
        'abs'            => ['num'],
        'ceil'           => ['num'],
        'floor'          => ['num'],
        'round'          => ['num', 'precision', 'mode'],
        'sqrt'           => ['num'],
        'pow'            => ['base', 'exp'],
        'log'            => ['num', 'base'],
        'log10'          => ['num'],
        'log2'           => ['num'],
        'exp'            => ['num'],
        'intdiv'         => ['num1', 'num2'],
        'fmod'           => ['num1', 'num2'],
        'max'            => ['value1'],
        'min'            => ['value1'],
        'rand'           => ['min', 'max'],
        'mt_rand'        => ['min', 'max'],
        'random_int'     => ['min', 'max'],
        'base_convert'   => ['num', 'from_base', 'to_base'],
        'bindec'         => ['binary_string'],
        'octdec'         => ['octal_string'],
        'hexdec'         => ['hex_string'],
        'decbin'         => ['num'],
        'decoct'         => ['num'],
        'dechex'         => ['num'],
        'deg2rad'        => ['num'],
        'rad2deg'        => ['num'],
        'pi'             => [],
        'fmod'           => ['num1', 'num2'],
        // 数组
        'count'              => ['array', 'mode'],
        'array_keys'         => ['array', 'search_value', 'strict'],
        'array_values'       => ['array'],
        'array_key_exists'   => ['key', 'array'],
        'array_search'       => ['needle', 'array', 'strict'],
        'in_array'           => ['needle', 'array', 'strict'],
        'array_push'         => ['array'],
        'array_pop'          => ['array'],
        'array_shift'        => ['array'],
        'array_unshift'      => ['array'],
        'array_slice'        => ['array', 'offset', 'length', 'preserve_keys'],
        'array_splice'       => ['array', 'offset', 'length', 'replacement'],
        'array_merge'        => ['array1'],
        'array_combine'      => ['keys', 'values'],
        'array_flip'         => ['array'],
        'array_reverse'      => ['array', 'preserve_keys'],
        'array_unique'       => ['array', 'flags'],
        'array_filter'       => ['array', 'callback'],
        'array_map'          => ['callback', 'array1'],
        'array_reduce'       => ['array', 'callback', 'initial'],
        'array_fill'         => ['start_index', 'count', 'value'],
        'array_fill_keys'    => ['keys', 'value'],
        'array_pad'          => ['array', 'size', 'value'],
        'array_chunk'        => ['array', 'size', 'preserve_keys'],
        'array_count_values' => ['array'],
        'array_sum'          => ['array'],
        'array_product'      => ['array'],
        'array_column'       => ['array', 'column_key', 'index_key'],
        'range'              => ['start', 'end', 'step'],
        'sort'               => ['array', 'flags'],
        'rsort'              => ['array', 'flags'],
        'asort'              => ['array', 'flags'],
        'arsort'             => ['array', 'flags'],
        'ksort'              => ['array', 'flags'],
        'krsort'             => ['array', 'flags'],
        'usort'              => ['array', 'callback'],
        'uasort'             => ['array', 'callback'],
        'uksort'             => ['array', 'callback'],
        'shuffle'            => ['array'],
        // 类型转换
        'intval'         => ['value', 'base'],
        'floatval'       => ['value'],
        'doubleval'      => ['value'],
        'strval'         => ['value'],
        'boolval'        => ['value'],
        'settype'        => ['var', 'type'],
        'is_int'         => ['value'],
        'is_float'       => ['value'],
        'is_string'      => ['value'],
        'is_bool'        => ['value'],
        'is_array'       => ['value'],
        'is_object'      => ['value'],
        'is_null'        => ['value'],
        'is_callable'    => ['value'],
        'is_resource'    => ['value'],
        'is_numeric'     => ['value'],
        'is_iterable'    => ['value'],
        'is_countable'   => ['value'],
        'gettype'        => ['var'],
        'get_debug_type' => ['value'],
        // JSON
        'json_encode'    => ['value', 'flags', 'depth'],
        'json_decode'    => ['json', 'assoc', 'depth', 'flags'],
        // 加密
        'password_hash'  => ['password', 'algorithm', 'options'],
        'password_verify'=> ['password', 'hash'],
        'hash'           => ['algo', 'data', 'binary'],
        'hash_hmac'      => ['algo', 'data', 'key', 'binary'],
        'hash_pbkdf2'    => ['algo', 'password', 'salt', 'iterations', 'length', 'binary'],
        // 文件
        'file_get_contents' => ['filename', 'use_include_path', 'context', 'offset', 'length'],
        'file_put_contents' => ['filename', 'data', 'flags', 'context'],
        'file_exists'    => ['filename'],
        'is_file'        => ['filename'],
        'is_dir'         => ['filename'],
        'is_readable'    => ['filename'],
        'is_writable'    => ['filename'],
        'filesize'       => ['filename'],
        'filemtime'      => ['filename'],
        'fileatime'      => ['filename'],
        'filectime'      => ['filename'],
        'realpath'       => ['path'],
        'dirname'        => ['path', 'levels'],
        'basename'       => ['path', 'suffix'],
        'pathinfo'       => ['path', 'flags'],
        'unlink'         => ['filename'],
        'rename'         => ['from', 'to'],
        'copy'           => ['from', 'to'],
        'mkdir'          => ['directory', 'permissions', 'recursive'],
        'rmdir'          => ['directory'],
        'tempnam'        => ['directory', 'prefix'],
        'sys_get_temp_dir' => [],
        // 输出
        'echo'           => [],
        'print'          => [],
        'print_r'        => ['value', 'return'],
        'var_dump'       => ['value'],
        'var_export'     => ['value', 'return'],
        'debug_zval_refcount' => ['value'],
        // 时间
        'time'           => [],
        'microtime'      => ['as_float'],
        'hrtime'         => ['as_number'],
        'date'           => ['format', 'timestamp'],
        'mktime'         => ['hour', 'minute', 'second', 'month', 'day', 'year'],
        'strtotime'      => ['datetime', 'baseTimestamp'],
        'sleep'          => ['seconds'],
        'usleep'         => ['micro_seconds'],
        'checkdate'      => ['month', 'day', 'year'],
        // 网络
        'ip2long'        => ['ip'],
        'long2ip'        => ['ip'],
        'inet_pton'      => ['ip'],
        'inet_ntop'      => ['addr'],
        'gethostbyname'  => ['hostname'],
        'gethostbyaddr'  => ['ip_address'],
    ];

    /**
     * 获取内置函数签名（ret + params），用于注册 closureSigs
     *   优先使用 $builtinFnParamCtypes 精确映射，回退到 t_var 占位
     */
    private function getBuiltinFnSig(string $name): ?array
    {
        $info = self::$simpleFnMap[$name] ?? null;
        if ($info === null) return null;

        // 优先使用精确参数类型映射；回退到 t_var（按 modes 数量）
        if (isset(self::$builtinFnParamCtypes[$name])) {
            $paramTypes = self::$builtinFnParamCtypes[$name];
        } else {
            $modes = $info['modes'] ?? [];
            $paramTypes = [];
            for ($i = 0; $i < count($modes); $i++) {
                $paramTypes[] = 't_var';
            }
        }

        // 返回类型：从 TypeChecker::$builtinRetTypes 查询
        $retType = 't_int';  // 默认
        if (isset(TypeChecker::$builtinRetTypes[$name])) {
            $phpRet = TypeChecker::$builtinRetTypes[$name];
            $retType = match ($phpRet) {
                'int'      => 't_int',
                'float'    => 't_float',
                'string'   => 't_string',
                'bool'     => 't_bool',
                'void'     => 'void',
                'array'    => 't_array*',
                'resource' => 'void*',
                'mixed'    => 't_var',
                default    => 't_int',
            };
        }

        return [
            'ret'    => $retType,
            'params' => implode(', ', $paramTypes),
        ];
    }

    /**
     * 为方法引用 $obj->method(...) 生成 t_callback 包装器
     *   通过 thunk 函数包装实例方法调用，env 捕获 self 指针
     *
     *   生成代码模式：
     *   ({
     *     _cap_N* _env_N = ...; _env_N->self = $obj;
     *     tphp_rt_register(_env_N, 5);
     *     (t_callback){ .func = (void*)_thunk_N, .env = _env_N };
     *   })
     *
     * @return array{0:string,1:string} [code, sigName]
     */
    private function emitMethodCallableConvert(CallableConvertExpr $node): array
    {
        $callee = $node->callee;
        // 推导对象类型和类 C 名
        $objCode = $callee->accept($this);
        $objType = $this->inferType($callee);

        // 静态方法引用 Class::method(...)
        //   callee 是 VariableExpr('ClassName')，但类型不是对象指针
        $isStaticRef = ($callee instanceof VariableExpr
            && !str_starts_with($callee->name, '$')
            && $objType !== 'null'
            && !str_ends_with($objType, '*'));

        if ($isStaticRef) {
            // 静态方法：直接用 ClassName_method，env=NULL
            $className = $callee->name;
            $cn = $this->symbols->resolveClass($className) ?? ('tphp_class_' . $className);
            $methodCName = "{$cn}_{$node->name}";
            // 注册签名
            $mInfo = $this->symbols->getClassMethod($cn, $node->name);
            if ($mInfo !== null) {
                $this->symbols->addClosureSig($methodCName, [
                    'ret'    => $mInfo->retType,
                    'params' => implode(', ', $mInfo->paramTypes),
                ]);
            }
            return ["(t_callback){ .func = (void*){$methodCName}, .env = NULL }", $methodCName];
        }

        // 实例方法：$obj->method(...) — 需要 thunk 包装
        // 推导类 C 名
        $cn = '';
        if (str_ends_with($objType, '*') && str_starts_with($objType, 'tphp_class_')) {
            $cn = rtrim($objType, '*');
        } elseif (str_starts_with($objType, 'tphp_class_')) {
            $cn = $objType;
        }
        if ($cn === '') {
            throw new \RuntimeException(
                "First-class callable on method requires known object type, got '{$objType}'"
            );
        }

        // 查询方法签名
        $mInfo = $this->symbols->getClassMethod($cn, $node->name);
        $retType = $mInfo?->retType ?? 't_int';
        $paramTypes = $mInfo?->paramTypes ?? [];

        // 生成 thunk 函数：包装 ClassName_method(self, args) 调用
        $id = ++$this->closureCounter;
        $thunkName = "_method_thunk_{$id}";
        $capName = "_cap_{$id}";

        // thunk 参数列表：原方法参数 + void* _env
        $thunkParams = [];
        foreach ($paramTypes as $pt) {
            $thunkParams[] = "{$pt} p" . count($thunkParams);
        }
        $thunkParams[] = "void* _env";
        $thunkParamStr = implode(', ', $thunkParams);

        // thunk body: 从 env 取 self，调用 ClassName_method(self, args)
        $argNames = [];
        for ($i = 0; $i < count($paramTypes); $i++) {
            $argNames[] = "p{$i}";
        }
        $callArgs = implode(', ', array_merge(['_e->self'], $argNames));
        $methodCName = "{$cn}_{$node->name}";

        $thunkLines = [
            "static {$retType} {$thunkName}({$thunkParamStr}) {",
            "    {$capName}* _e = ({$capName}*)_env;",
            "    return {$methodCName}({$callArgs});",
            "}",
        ];

        // 文件作用域前置声明：使 thunk 在 statement expression 中可见
        //   GCC/Clang 要求 static 函数声明与定义 storage class 一致；
        //   而函数原型不允许在 statement expression 内部声明为 static，
        //   因此放在文件作用域（SEC_FWDDECLS），三编译器都兼容。
        $this->sectionLine(self::SEC_FWDDECLS, "static {$retType} {$thunkName}({$thunkParamStr});");

        // 注册捕获 struct（仅含 self 指针 + dtor）
        $capFields = [
            "    void (*dtor)(void*);",
            "    {$cn}* self;",
        ];

        // 生成 dtor（self 是对象指针，需要 release）
        $dtorName = "_env_dtor_{$id}";
        $this->sectionLine(self::SEC_FWDDECLS, "static void {$dtorName}(void* env);");
        $dtorLines = [
            "static void {$dtorName}(void* env) {",
            "    {$capName}* e = ({$capName}*)env;",
            "    if (e->self) tp_obj_release((void*)e->self);",
            "}",
        ];

        $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $thunkLines) . "\n" . implode("\n", $dtorLines));

        // 注册捕获 struct 定义
        $capDef = "typedef struct {\n" . implode("\n", $capFields) . "\n} {$capName};";
        $this->sectionBlock(self::SEC_CAPTYPES, $capDef);

        // 注册闭包签名（thunk 的签名）
        $this->symbols->addClosureSig($thunkName, [
            'ret'    => $retType,
            'params' => implode(', ', $paramTypes),
        ]);

        // 生成 GNU 复合表达式：分配 env，捕获 self，构造 t_callback
        $envDecl = "    {$capName}* _env_{$id} = ({$capName}*)calloc(1, sizeof({$capName}));\n"
            . "    if (_env_{$id} != NULL) {\n"
            . "        _env_{$id}->dtor = {$dtorName};\n"
            . "        _env_{$id}->self = {$objCode}; tp_obj_retain((void*){$objCode});\n"
            . "    }\n"
            . "    tphp_rt_register((void*)_env_{$id}, 5);\n"
            . "    (t_callback){ .func = (void*){$thunkName}, .env = _env_{$id} };";

        // 注意：statement expression 内不声明原型（已在文件作用域前置声明为 static）
        //   GCC/Clang 不允许在 statement expression 内声明 static 函数原型；
        //   TCC 对此规则宽松，但为了三编译器兼容统一在文件作用域前置声明。
        return ["({\n{$envDecl}\n  })", $thunkName];
    }

    /**
     * 注册 callback 变量到 varClosureMap
     *   使后续 $cb(...) 调用能通过 generateClosureCall 查到签名
     *   使用唯一的虚拟变量名（避免与真实变量冲突）
     */
    private function registerCallbackVar(string $fnName): void
    {
        // 生成唯一虚拟变量名，关联到闭包签名
        $virtualVar = '_cb_' . $fnName;
        $this->symbols->addVarClosure($virtualVar, $fnName);
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

        // microtime(true) — C 函数 tphp_fn_microtime(void) 始终返回 float
        //   忽略参数（PHP 语义：仅当 $as_float=true 时返回 float，此处等价实现）
        if ($node->name === 'microtime') {
            return $info['cName'] . '()';
        }

        // array_unshift 是原地修改函数，拒绝 array<T>（转换会丢失修改）
        if ($node->name === 'array_unshift' && $this->isGenericArrayVar($node->args[0])) {
            throw new \RuntimeException(
                "array_unshift() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                . "Use '\$arr = [\$val, ...\$arr]' or declare as array<mixed>."
            );
        }

        // sort/rsort/shuffle 对 array<T> 原地修改：直接调用特化函数
        //   tphp_fn_arr_{suffix}_sort / _rsort / _shuffle 直接操作 t_arr_int/t_arr_str/...
        //   避免 arrayArgCode 协变转换为临时 t_array* 后修改丢失。
        //   仅处理标量元素（int/str/float/bool）；t_arr_ptr* 走通用路径。
        $typedInplaceFns = ['sort' => 'sort', 'rsort' => 'rsort', 'shuffle' => 'shuffle'];
        if (isset($typedInplaceFns[$node->name])
            && isset($node->args[0]) && $node->args[0] instanceof VariableExpr) {
            $vn = self::varName($node->args[0]->name);
            $vt = $this->varTypes[$vn] ?? '';
            if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $vt, $m)) {
                $argCode = $node->args[0]->accept($this);
                $fn = $typedInplaceFns[$node->name];
                return "tphp_fn_arr_{$m[1]}_{$fn}({$argCode})";
            }
        }

        // count($arr, $mode) — 第二参数为 COUNT_RECURSIVE 时切换到递归版本
        if (($info['dispatch'] ?? null) === 'count') {
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            if (isset($node->args[1])) {
                $modeCode = $node->args[1]->accept($this);
                return "(($modeCode) == 1 ? tphp_fn_arr_count_recursive($arrCode) : tphp_fn_arr_count($arrCode))";
            }
            return "tphp_fn_arr_count($arrCode)";
        }

        // array_keys($arr, $search) — 有第二参数时切换到 search 版本
        if (($info['dispatch'] ?? null) === 'array_keys') {
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            if (isset($node->args[1])) {
                $searchCode = $this->wrapVar($node->args[1]);
                return "tphp_fn_array_keys_search($arrCode, $searchCode)";
            }
            return "tphp_fn_array_keys($arrCode)";
        }

        // array_diff/array_intersect 变参支持（variadic_n dispatch）：
        //   PHP 原生签名：array_diff(array $base, array ...$others): array
        //   2 参数：走原 direct 路径 tphp_fn_arr_diff(a1, a2)（零开销）
        //   3+ 参数：第 1 参数单独传，第 2+ 参数打包为 t_array*（元素为 VAR_ARRAY）
        //   调用 tphp_fn_arr_diff_n(base, packed_others) — 单次分配、单次遍历，符合 PHP 语义
        //   元素类型追踪由 visitAssign 的 case 'array_diff'/'array_intersect' 从第一个源数组推导。
        if (isset($info['variadic_n']) && count($node->args) > 2) {
            $nName = $info['variadic_n'];
            // 第 1 参数：协变转换为 t_array*
            $baseCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            // 第 2+ 参数：每个协变转换为 t_array* 后包装为 VAR_ARRAY，打包成一个 t_array*
            $nPack = count($node->args) - 1;
            $tmpArr = '_vn_' . (++$this->tmpVarCounter);
            $code = "({ t_array* {$tmpArr} = tphp_fn_arr_create({$nPack}); tphp_rt_register((void*){$tmpArr}, 1);";
            for ($i = 1; $i < count($node->args); $i++) {
                $argArr = $this->arrayArgCode($node->args[$i], $node->args[$i]->accept($this));
                $code .= " {$tmpArr} = tphp_fn_arr_push({$tmpArr}, VAR_ARRAY({$argArr}));";
            }
            $code .= " {$nName}({$baseCode}, {$tmpArr}); })";
            return $code;
        }

        // max/min variadic 形式：多参数时打包成数组调用 tphp_fn_max/min(arr)
        // max(1, 2, 3) → ({ t_array* _t = arr_create(3); push(_t, 1); push(_t, 2); push(_t, 3); tphp_fn_max(_t); })
        if (($info['dispatch'] ?? null) === 'variadic_pack') {
            $nArgs = count($node->args);
            $cName = $info['cName'];
            if ($nArgs <= 1) {
                $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
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
                    // direct: 标量/字符串原样传递；array<T> 变量自动协变转换为 t_array*
                    'direct'    => $this->arrayArgCode($arg, $arg->accept($this)),
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

    /**
     * 命名参数映射：将命名参数按函数/方法签名重排到正确位置
     *
     * PHP 8.0+ 命名参数语义：
     *   foo(b: 2, a: 1)            → foo(1, 2)         （用户函数，按签名顺序）
     *   $obj->m(y: 2, x: 1)        → $obj->m(1, 2)     （方法，按签名顺序）
     *   str_replace(subject: $s, search: 'a', replace: 'b') → str_replace('a', 'b', $s)
     *
     * 规则：
     *   1. 位置参数在前，命名参数在后（PHP 语义强制）
     *   2. 命名参数按签名 paramNames 中的位置填入对应槽位
     *   3. 未提供的参数（有默认值）保持缺失，重载选择会处理
     *   4. 找不到签名时不动（保守处理，让后续逻辑按位置生成）
     *
     * 直接修改 $node->args/$argNames（已移除 readonly），避免破坏 visitCall 内部对 $node->args 的所有引用。
     */
    private function reorderNamedArgs(CallExpr $node): void
    {
        // 没有命名参数 → 直接返回
        $hasNamed = false;
        foreach ($node->argNames as $n) {
            if ($n !== '') { $hasNamed = true; break; }
        }
        if (!$hasNamed) return;

        // 查找参数名列表（来自函数/方法签名）
        $paramNames = $this->resolveCallParamNames($node);
        if (empty($paramNames)) return;  // 未知签名，保守不动

        // 构建重排后的 args 和 argNames
        //   $positional[i] 表示第 i 个位置参数（按出现顺序）
        //   $named[name] => argExpr 表示命名参数
        $positional = [];
        $named = [];
        foreach ($node->args as $i => $arg) {
            $name = $node->argNames[$i] ?? '';
            if ($name === '') {
                $positional[] = $arg;
            } else {
                $named[$name] = $arg;
            }
        }

        // 按签名 paramNames 顺序填入参数：
        //   - 位置参数按顺序占用前 N 个槽位
        //   - 命名参数按名字填入对应槽位
        //   - 未提供且未填入的槽位跳过（让后续重载选择处理）
        $nameToIdx = array_flip($paramNames);
        $reordered = [];
        $posIdx = 0;
        $totalSlots = count($paramNames);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $slotName = $paramNames[$slot];
            if (isset($named[$slotName])) {
                // 命名参数填入此槽位
                $reordered[] = $named[$slotName];
                unset($named[$slotName]);
            } elseif ($posIdx < count($positional)) {
                // 位置参数按顺序填入
                $reordered[] = $positional[$posIdx++];
            }
            // else: 未提供的参数（有默认值），跳过
        }

        // 未匹配的命名参数：抛错（PHP 语义：Unknown named parameter）
        if (!empty($named)) {
            $unknown = implode(', ', array_keys($named));
            throw new \RuntimeException(sprintf(
                "[%d:%d] Unknown named parameter \$%s for %s()",
                $node->line, $node->column, $unknown, $node->name
            ));
        }

        // 剩余位置参数追加到末尾（变参场景）
        while ($posIdx < count($positional)) {
            $reordered[] = $positional[$posIdx++];
        }

        // 直接赋值（CallExpr::$args/$argNames 已移除 readonly，支持命名参数重排）
        $node->args = $reordered;
        $node->argNames = array_fill(0, count($reordered), '');
    }

    /**
     * 构造函数命名参数重排（NewExpr）
     * 与 reorderNamedArgs 类似，但从 __construct 方法签名解析 paramNames
     */
    private function reorderNewArgs(NewExpr $node): void
    {
        $hasNamed = false;
        foreach ($node->argNames as $n) {
            if ($n !== '') { $hasNamed = true; break; }
        }
        if (!$hasNamed) return;

        $cn = self::classRefName($node->className);
        $resolved = $this->symbols->resolveClass($cn) ?? $cn;
        $ctor = $this->symbols->getClassMethod($resolved, '__construct');
        // 父类继承的构造函数
        if ($ctor === null) {
            $parentCN = $this->resolveMethodClass($resolved, '__construct');
            if ($parentCN !== '') {
                $ctor = $this->symbols->getClassMethod($parentCN, '__construct');
            }
        }
        if ($ctor === null || empty($ctor->paramNames)) return;

        $paramNames = $ctor->paramNames;
        $positional = [];
        $named = [];
        foreach ($node->args as $i => $arg) {
            $name = $node->argNames[$i] ?? '';
            if ($name === '') {
                $positional[] = $arg;
            } else {
                $named[$name] = $arg;
            }
        }

        $reordered = [];
        $posIdx = 0;
        $totalSlots = count($paramNames);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $slotName = $paramNames[$slot];
            if (isset($named[$slotName])) {
                $reordered[] = $named[$slotName];
                unset($named[$slotName]);
            } elseif ($posIdx < count($positional)) {
                $reordered[] = $positional[$posIdx++];
            }
        }

        if (!empty($named)) {
            $unknown = implode(', ', array_keys($named));
            throw new \RuntimeException(sprintf(
                "[%d:%d] Unknown named parameter \$%s for %s::__construct()",
                $node->line, $node->column, $unknown, $node->className
            ));
        }

        while ($posIdx < count($positional)) {
            $reordered[] = $positional[$posIdx++];
        }

        $node->args = $reordered;
        $node->argNames = array_fill(0, count($reordered), '');
    }

    /**
     * 解析 CallExpr 的目标函数/方法参数名列表
     * @return string[] 参数名数组（不含 $），空数组表示未知签名
     */
    private function resolveCallParamNames(CallExpr $node): array
    {
        // 全局函数（含命名空间）
        if ($node->callee === null && !$node->isRawC) {
            $fnCName = self::funcCNameFromCall($node);
            $fn = $this->symbols->getFunc($fnCName);
            if ($fn !== null && !empty($fn->paramNames)) return $fn->paramNames;
            // 命名空间 fallback
            if (($pos = strrpos($node->name, '\\')) !== false) {
                $baseName = substr($node->name, $pos + 1);
                $fn = $this->symbols->getFunc('tphp_fn_' . $baseName);
                if ($fn !== null && !empty($fn->paramNames)) return $fn->paramNames;
            }
            // 内置函数参数名映射表
            $baseName = ($pos = strrpos($node->name, '\\')) !== false
                ? substr($node->name, $pos + 1) : $node->name;
            return self::$builtinFnParamNames[$baseName] ?? [];
        }

        // C 函数（C->func()）：使用声明的 C 函数签名
        if ($node->isRawC) {
            $cFn = $this->symbols->getCFunction($node->name);
            if ($cFn !== null) return $cFn->paramNames;
            return [];
        }

        // 闭包调用 $var(...)：暂不支持命名参数
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && $node->name === '__invoke') {
            return [];
        }

        // 方法调用 $obj->method() / Class::method()
        if ($node->callee !== null) {
            // 推导 callee 类型对应的类 C 名
            $cn = '';
            if ($node->callee instanceof VariableExpr) {
                $vn = self::varName($node->callee->name);
                if (in_array($node->callee->name, ['self', 'static'], true)) {
                    $cn = $this->className;
                } elseif ($node->callee->name === 'parent') {
                    $parentPhp = $this->lookupParentClass($this->phpClassName);
                    $cn = $parentPhp !== null ? self::classRefName($parentPhp) : $this->className;
                } else {
                    $raw = $this->varTypes[$vn] ?? $vn;
                    $cn = str_contains($raw, '\\') ? self::classRefName($raw) : $raw;
                }
            } elseif ($node->callee instanceof CallExpr) {
                $cn = rtrim($this->inferCallChainClass($node->callee), '*');
            } elseif ($node->callee instanceof ArrayAccessExpr
                || $node->callee instanceof PropertyAccessExpr) {
                $cn = rtrim($this->inferType($node->callee), '*');
            } elseif ($node->callee instanceof EnumAccessExpr) {
                $cn = $this->symbols->getEnumCName($node->callee->enumName) ?? '';
            } else {
                $cn = (string)$node->callee->accept($this);
            }

            // 静态类名调用 Class::method() — callee 是 VariableExpr('ClassName')
            if ($node->callee instanceof VariableExpr
                && !str_starts_with($node->callee->name, '$')
                && !in_array($node->callee->name, ['self', 'static', 'parent'], true)) {
                $cnClean = rtrim($cn, '*');
                $resolved = $this->symbols->resolveClass($cnClean);
                if ($resolved !== null) $cnClean = $resolved;
                $cn = $cnClean;
            }

            $cnClean = rtrim($cn, '*');
            if ($cnClean === '') return [];

            // 解析方法签名（含父类继承）
            $m = $this->symbols->getClassMethod($cnClean, $node->name);
            if ($m === null) {
                $parentCN = $this->resolveMethodClass($cnClean, $node->name);
                if ($parentCN !== '') {
                    $m = $this->symbols->getClassMethod($parentCN, $node->name);
                }
            }
            // 枚举方法
            if ($m === null) {
                $enumCName = $this->symbols->resolveEnumCName($cnClean);
                if ($enumCName !== null) {
                    $m = $this->symbols->getEnumMethodByCName($enumCName, $node->name);
                }
            }
            if ($m !== null) return $m->paramNames;
            return [];
        }

        return [];
    }

    /**
     * 生成可变参数调用的实参列表
     *
     *   规则（参考 vlang 设计：编译期已知元素类型，零运行时类型擦除）：
     *   - 固定参数部分：正常生成 C 代码
     *   - 可变参数部分（最后一个形参位置）：
     *     * 若实参为 `...$arr` 展开形式 → 直接透传数组（零开销）
     *     * 若实参为多个独立值（1, 2, 3）→ 编译期打包为 t_array*（复用 variadic_pack 逻辑）
     *     * 若无可变实参 → 传空数组
     *
     * @param CallExpr $node 调用节点（含 spreads 标记）
     * @param int $variadicPos 可变参数在形参列表中的位置（即 count($fixedParams)）
     * @param array $pTypes 形参 C 类型列表（仅固定参数部分）
     * @return array{0:string[], 1:string} [0]=固定参数 C 代码列表, [1]=可变参数 C 代码（含打包逻辑）
     */
    private function generateVariadicArgs(CallExpr $node, int $variadicPos, array $pTypes): array
    {
        $fixedArgs = [];
        $variadicArgs = [];
        $spreads = $node->spreads;
        // 确保 spreads 数组长度与 args 对齐（旧 AST 可能无 spreads）
        while (count($spreads) < count($node->args)) $spreads[] = false;

        foreach ($node->args as $i => $arg) {
            if ($i < $variadicPos) {
                $fixedArgs[] = $arg;
            } else {
                $variadicArgs[] = ['expr' => $arg, 'spread' => $spreads[$i]];
            }
        }

        // 生成固定参数 C 代码
        $fixedCodes = [];
        foreach ($fixedArgs as $i => $arg) {
            $ct = $pTypes[$i] ?? '';
            $isParamByRef = $this->isByRefType($ct);
            if ($isParamByRef && $arg instanceof VariableExpr) {
                $avn = self::varName($arg->name);
                if ($this->isByRefType($this->varTypes[$avn] ?? '')) {
                    $fixedCodes[] = $avn;
                } else {
                    $fixedCodes[] = '&' . self::varName($arg->name);
                }
            } else {
                $aCode = $arg->accept($this);
                $fixedCodes[] = $isParamByRef ? '&' . $aCode : $aCode;
            }
        }

        // 生成可变参数 C 代码
        if (empty($variadicArgs)) {
            // 无可变实参：传空数组
            $variadicCode = 'tphp_fn_arr_create(0)';
        } else {
            // 检测 spread 形式：若最后一个实参是 spread 且只有一个可变实参 → 直接透传
            if (count($variadicArgs) === 1 && $variadicArgs[0]['spread']) {
                $variadicCode = $variadicArgs[0]['expr']->accept($this);
            } else {
                // 编译期打包为 t_array*（与 max/min variadic_pack 逻辑一致）
                $nArgs = count($variadicArgs);
                $tmpArr = '_vp_' . (++$this->tmpVarCounter);
                $code = "({ t_array* {$tmpArr} = tphp_fn_arr_create({$nArgs}); tphp_rt_register((void*){$tmpArr}, 1);";
                foreach ($variadicArgs as $va) {
                    if ($va['spread']) {
                        // spread 在多实参中：合并数组元素（编译期循环展开）
                        //   $arr 的元素逐个 push 到 _vp_N
                        $arrCode = $va['expr']->accept($this);
                        $iterVar = '_vpi_' . (++$this->tmpVarCounter);
                        $code .= " for (t_int {$iterVar} = 0; {$iterVar} < tphp_fn_count({$arrCode}); {$iterVar}++) {{ {$tmpArr} = tphp_fn_arr_push({$tmpArr}, tphp_fn_arr_get_int({$arrCode}, {$iterVar})); }}";
                    } else {
                        $v = $this->wrapVar($va['expr']);
                        $code .= " {$tmpArr} = tphp_fn_arr_push({$tmpArr}, {$v});";
                    }
                }
                $code .= " {$tmpArr}; })";
                $variadicCode = $code;
            }
        }

        return [$fixedCodes, $variadicCode];
    }

    public function visitCall(CallExpr $node): string
    {
        // ── func_get_args / func_num_args / func_get_arg 特殊处理 ──
        //   仅在可变参数函数/方法内有效（参 GRAMMAR.md:894-896）
        //   定参函数内调用 → 抛异常（user_profile: 禁止静默错误处理）
        //   可变参数函数内：func_get_args() → 返回 args 形参（t_array*）
        if ($node->callee === null && !$node->isRawC) {
            $fn = $node->name;
            // 命名空间 fallback：剥掉命名空间前缀
            if (($pos = strrpos($fn, '\\')) !== false) $fn = substr($fn, $pos + 1);
            if ($fn === 'func_get_args') {
                if ($this->currentVariadicParamName === '') {
                    throw new RuntimeException(sprintf(
                        "[%d:%d] func_get_args() is only available in variadic functions (those declared with ...\$args). ".
                        "Fixed-parameter functions have no unified argument container in AOT mode.",
                        $node->line, $node->column
                    ));
                }
                return $this->currentVariadicParamName;
            }
            if ($fn === 'func_num_args') {
                if ($this->currentVariadicParamName === '') {
                    throw new RuntimeException(sprintf(
                        "[%d:%d] func_num_args() is only available in variadic functions (those declared with ...\$args). ".
                        "Fixed-parameter functions have no unified argument container in AOT mode.",
                        $node->line, $node->column
                    ));
                }
                return "tphp_fn_arr_count({$this->currentVariadicParamName})";
            }
            if ($fn === 'func_get_arg') {
                if ($this->currentVariadicParamName === '') {
                    throw new RuntimeException(sprintf(
                        "[%d:%d] func_get_arg() is only available in variadic functions (those declared with ...\$args). ".
                        "Fixed-parameter functions have no unified argument container in AOT mode.",
                        $node->line, $node->column
                    ));
                }
                $idx = $node->args[0]->accept($this);
                // 根据可变参数元素类型选择类型化访问器（避免 t_var* → 栈值 类型不匹配）
                $elemType = $this->currentVariadicElementType;
                $accessor = match ($elemType) {
                    'int', 't_int'     => "tphp_fn_arr_get_int_int({$this->currentVariadicParamName}, {$idx})",
                    'float', 't_float' => "tphp_fn_arr_get_int_float({$this->currentVariadicParamName}, {$idx})",
                    'string', 't_string' => "tphp_fn_arr_get_int_str({$this->currentVariadicParamName}, {$idx})",
                    default             => "(*tphp_fn_arr_get_int({$this->currentVariadicParamName}, {$idx}))",
                };
                return $accessor;
            }
        }

        // 命名参数映射：将命名参数按函数/方法签名重排到正确位置
        //   foo(b: 2, a: 1) → foo(1, 2)（按 a, b 顺序排列）
        //   仅在存在命名参数时触发，纯位置参数透传
        $this->reorderNamedArgs($node);

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
        static $phpcFns = ['c_int','c_str','php_int','php_str','php_str_clone','php_str_ptr','c_void_ptr','cstr_to_string',
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
            if ($this->isGenericArrayVar($node->args[0])) {
                throw new \RuntimeException(
                    "array_push() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                    . "Use '\$arr[] = \$val' syntax instead, or declare the variable as array<mixed>."
                );
            }
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            $valCode = $this->wrapArrayElement($node->args[1], $node->args[1]->accept($this));
            return 'tphp_fn_array_push(&' . $arrCode . ', ' . $valCode . ')';
        }

        // array_pop($arr) → 弹出尾部元素，返回 t_var（mixed 类型）
        if ($node->callee === null && $node->name === 'array_pop') {
            if ($this->isGenericArrayVar($node->args[0])) {
                throw new \RuntimeException(
                    "array_pop() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                    . "Use '\$v = \$arr[count(\$arr)-1]; \$arr = array_slice(\$arr, 0, -1);' or declare as array<mixed>."
                );
            }
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            return 'tphp_fn_array_pop(&' . $arrCode . ')';
        }

        // array_key_exists($key, $arr) → 键是否存在
        if ($node->callee === null && $node->name === 'array_key_exists') {
            $keyType = $this->inferType($node->args[0]);
            $keyCode = $node->args[0]->accept($this);
            $arrCode = $this->arrayArgCode($node->args[1], $node->args[1]->accept($this));
            if ($keyType === 't_string') {
                return 'tphp_fn_array_key_exists_str(' . $keyCode . ', ' . $arrCode . ')';
            }
            return 'tphp_fn_array_key_exists_int(' . $keyCode . ', ' . $arrCode . ')';
        }

        // array_shift($arr) → 移除头部元素，返回 t_var
        if ($node->callee === null && $node->name === 'array_shift') {
            if ($this->isGenericArrayVar($node->args[0])) {
                throw new \RuntimeException(
                    "array_shift() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                    . "Use '\$v = \$arr[0]; \$arr = array_slice(\$arr, 1);' or declare as array<mixed>."
                );
            }
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            $tv = '_ts_' . (++$this->tmpVarCounter);
            return "({ t_var {$tv} = VAR_NULL(); tphp_fn_arr_shift({$arrCode}, &{$tv}); {$tv}; })";
        }

        // asort/arsort/ksort/krsort/uasort/usort 保留 key-value 关联（或回调比较），
        //   语义不适用于 array<T>（有序列表，元素无独立 key 关联）。拒绝并提示用户
        //   改用 sort/rsort（值排序）或显式声明 array<mixed>。
        //   这些函数未在 $simpleFnMap 中注册，走 fallback 直接调用 tphp_fn_{name}，
        //   会在 array<T> 上导致类型不兼容或协变转换丢失修改。
        if ($node->callee === null
            && in_array($node->name, ['asort', 'arsort', 'ksort', 'krsort', 'uasort', 'usort'], true)
            && isset($node->args[0]) && $this->isGenericArrayVar($node->args[0])) {
            throw new \RuntimeException(
                "{$node->name}() preserves key-value association, which is not applicable to typed array<T>. "
                . "Use sort/rsort (value sort) or declare as array<mixed>."
            );
        }

        // array_slice($arr, $offset, $length=0, $preserve_keys=false)
        if ($node->callee === null && $node->name === 'array_slice') {
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
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
            // Special case: isset($arr['key']) → 混合数组键存在性检查
            //   t_array* (mixed): tphp_fn_arr_get_str/int(arr, idx) != NULL
            //   t_var (holding array): extract .value._array first
            //   避免 *tphp_fn_arr_get_str(...) 返回 t_var 结构体无法转为 void*
            if (!empty($node->args) && $node->args[0] instanceof ArrayAccessExpr) {
                $arg = $node->args[0];
                $arrExpr = $arg->array;
                $vt = '';
                if ($arrExpr instanceof VariableExpr) {
                    $vt = $this->varTypes[self::varName($arrExpr->name)] ?? '';
                } elseif ($arrExpr instanceof PropertyAccessExpr) {
                    $vt = $this->inferType($arrExpr);
                }
                // Only handle t_array* (mixed array) and t_var (holding array)
                // Typed arrays (t_arr_int* etc.) fall through to existing logic
                if ($vt === 't_array*' || $vt === 't_var') {
                    $arrCode = $arrExpr->accept($this);
                    if ($vt === 't_var') {
                        $arrCode = "(({$arrCode}).value._array)";
                    }
                    $idxCode = $arg->index->accept($this);
                    $idxType = $this->inferType($arg->index);
                    if ($idxType === 't_string' || $arg->index instanceof StringLiteralExpr) {
                        return "(tphp_fn_arr_get_str({$arrCode}, {$idxCode}) != NULL)";
                    }
                    return "(tphp_fn_arr_get_int({$arrCode}, (t_int)({$idxCode})) != NULL)";
                }
            }
            // isset($obj->prop) — stdClass 动态属性存在性检查
            if (!empty($node->args) && $node->args[0] instanceof PropertyAccessExpr) {
                $pa = $node->args[0];
                $objCN = '';
                if ($pa->object instanceof VariableExpr) {
                    $vn = self::varName($pa->object->name);
                    $objType = $this->varTypes[$vn] ?? '';
                    $objCN = rtrim($objType, '*');
                }
                if ($objCN === 'tphp_class_stdClass') {
                    $obj = $pa->object->accept($this);
                    $prop = ltrim($pa->property, '$');
                    return "(tphp_fn_stdclass_isset({$obj}, STR_LIT(\"{$prop}\")))";
                }
            }
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
                // unset($obj->prop) — stdClass 动态属性删除
                if ($arg instanceof PropertyAccessExpr) {
                    $objCN = '';
                    if ($arg->object instanceof VariableExpr) {
                        $vn = self::varName($arg->object->name);
                        $objType = $this->varTypes[$vn] ?? '';
                        $objCN = rtrim($objType, '*');
                    }
                    if ($objCN === 'tphp_class_stdClass') {
                        $obj = $arg->object->accept($this);
                        $prop = ltrim($arg->property, '$');
                        $lines[] = "tphp_fn_stdclass_unset({$obj}, STR_LIT(\"{$prop}\"))";
                        continue;
                    }
                }
                // 数组元素 unset: unset($arr[$key]) → 调用 C 运行时删除函数
                if ($arg instanceof ArrayAccessExpr) {
                    $arrCode = $arg->array->accept($this);
                    $idxCode = $arg->index->accept($this);
                    $idxType = $this->inferType($arg->index);
                    if ($idxType === 't_string' || $arg->index instanceof StringLiteralExpr) {
                        $lines[] = "tphp_fn_arr_unset_str({$arrCode}, {$idxCode})";
                    } elseif ($idxType === 't_int' || $idxType === 't_bool' || $idxType === 't_float') {
                        $lines[] = "tphp_fn_arr_unset_int({$arrCode}, (t_int)({$idxCode}))";
                    } else {
                        // key 类型不确定（t_var 等），运行时统一分发
                        $lines[] = "tphp_fn_arr_unset_var({$arrCode}, {$idxCode})";
                    }
                    continue;
                }
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
            if ($n === 'get_object_vars') {
                $argType = $this->inferType($node->args[0]);
                $argCode = $node->args[0]->accept($this);
                // stdClass → 直接提取属性表
                if (str_contains($argType, 'tphp_class_stdClass')) {
                    return "tphp_fn_stdclass_to_array({$argCode})";
                }
                // 其他对象类型：暂不支持（需遍历 public 属性，复杂度高）
                throw new \RuntimeException(
                    sprintf("[%d:%d] get_object_vars() currently only supports stdClass, got %s",
                        $node->line, $node->column, $argType)
                );
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
                // t_var 参数解包：php_int/c_int 期望标量，php_str/c_str 等期望字符串
                //   场景：foreach ($options as $k => $v) { $flags = php_int($v); } — $v 是 t_var
                //   php_int(v) 展开为 ((t_int)(v))，对 struct t_var 编译失败
                $a = $this->unwrapPhpcArgs($shortN, $node, $a);
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
            } elseif (isset(self::$builtinRetTypes[$n]) && (str_starts_with($n, 'tphp_fn_') || str_starts_with($n, '_'))) {
                // 已注册的 C 函数（名称以 tphp_fn_ 或 _ 开头并在 $builtinRetTypes 中登记）：
                //   直接使用原名，避免 tphp_fn_tphp_fn_xxx 或 tphp_fn__pg_xxx 双前缀
                //   tphp_fn_ 前缀：stream/openssl 等扩展的 C API（tphp_fn_stream_init 等）
                //   _ 前缀：pgsql/pdo_pgsql 等扩展的内部 C 实现函数（_pg_connect/_pgpdo_init 等），
                //   不占用 tphp_fn_ 命名空间，避免与 PHP 层函数生成的 tphp_fn_<name> 冲突
                $fnName = $n;
            } else {
                $fnName = 'tphp_fn_' . $n;
            }
            // 检查是否有默认值参数，选择正确的重载版本
            $argCount = count($node->args);
            $fnInfo = $this->symbols->getFunc($fnName);
            $defaultCount = $fnInfo !== null ? $fnInfo->defaultCount : 0;
            // 检测可变参数函数：最后一个形参是 ...$args
            $isVariadic = $fnInfo !== null && $fnInfo->isVariadic;
            if ($isVariadic) {
                // 可变参数函数调用：固定参数 + 可变参数打包
                //   可变参数位置 = 总参数 - 1（最后一个形参是 t_array*）
                $variadicPos = $fnInfo->totalParams - 1;
                $fixedPTypes = array_slice($fnInfo->paramTypes, 0, $variadicPos);
                [$fixedCodes, $variadicCode] = $this->generateVariadicArgs($node, $variadicPos, $fixedPTypes);
                $allCodes = array_merge($fixedCodes, [$variadicCode]);
                return "{$fnName}(" . implode(', ', $allCodes) . ")";
            }
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
            // 回退：内置函数参数类型映射（dirname/basename 等未注册到 SymbolTable 的内置函数）
            if (empty($pTypes) && isset(self::$builtinFnParamCtypes[$n])) {
                $pTypes = self::$builtinFnParamCtypes[$n];
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
                    // t_var (mixed) 参数：自动包裹 VAR_XXX
                    if ($ct === 't_var') {
                        $aCode = $this->wrapTvarAssign($arg, $aCode);
                    } elseif (in_array($ct, ['t_string', 't_int', 't_float', 't_bool'], true)) {
                        // 标量参数：实参为 t_var（mixed）时，按参数类型解包
                        //   场景：dirname($urlInfo["path"]) — $urlInfo["path"] 为 t_var，参数声明 string
                        $argType = $this->inferType($arg);
                        if ($argType === 't_var') {
                            $aCode = $this->unwrapTVarToType($aCode, $ct);
                        }
                    }
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

        // 对 t_var 参数自动包裹 VAR_XXX；父子类参数自动 upcast；array<T> 协变转换为 array<mixed>
        $args = [];
        foreach ($node->args as $i => $a) {
            $code = $a->accept($this);
            // 查找该方法参数类型
            $pt = $this->getMethodParamType($node, $i);
            if ($pt === 't_var') {
                $code = $this->wrapTvarAssign($a, $code);
            } elseif ($pt === 't_array*' && $a instanceof VariableExpr) {
                // 参数声明 array (即 array<mixed>/t_array*)，实参是 array<T>（t_arr_int* 等）
                //   自动协变转换为 t_array*（O(n) 复制包装为 t_var 元素）
                $code = $this->arrayArgCode($a, $code);
            } elseif ($pt !== '' && self::isClassCType($pt) && $a instanceof VariableExpr) {
                // 父子类 upcast：实参是子类，参数声明是父类时生成 (ParentType*)arg
                $argVarKey = self::varName($a->name);
                $argType = $this->varTypes[$argVarKey] ?? '';
                if (self::isClassCType($argType)) {
                    $argCn = rtrim($argType, '*');
                    $ptCn  = rtrim($pt, '*');
                    if ($argCn !== $ptCn && $this->isSubclassOf($argCn, $ptCn)) {
                        $code = "({$pt}){$code}";
                    }
                } elseif ($argType === 't_var') {
                    // t_var 变量（mixed 持有对象）→ 对象指针：提取 .value._object 并转型
                    $code = "(({$pt})({$code}).value._object)";
                }
            } elseif ($pt !== '' && self::isClassCType($pt) && $this->inferType($a) === 't_var') {
                // t_var 表达式（如可变参数数组元素 $children[$i]）→ 对象指针提取
                //   场景：Stack::column(...$children) 中 $children[$i] 为 t_var，传给 Widget 参数
                $code = "(({$pt})({$code}).value._object)";
            } elseif (in_array($pt, ['t_string', 't_int', 't_float', 't_bool'], true)) {
                // 标量参数：实参为 t_var（mixed）时，按参数类型解包
                //   场景：dirname($urlInfo["path"]) — $urlInfo["path"] 为 t_var，参数声明 string
                $argType = $this->inferType($a);
                if ($argType === 't_var') {
                    $code = $this->unwrapTVarToType($code, $pt);
                }
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
            // 参数类型自动转换：使用声明的 C 函数签名（function C.foo(...): C.ret;）
            //   - char* 参数 + 字符串字面量 → 直接传 C 字符串字面量（剥去 STR_LIT(...) 包装）
            //   - char* 参数 + t_string 表达式 → 访问 .data 字段获取 char*
            //   - 变参 `...` 之后的参数不做转换
            $cFuncInfo = $this->symbols->getCFunction($node->name);
            if ($cFuncInfo !== null) {
                $newArgs = [];
                $argCount = count($node->args);
                foreach ($node->args as $i => $a) {
                    $paramType = $cFuncInfo->paramTypes[$i] ?? '';
                    // 变参标记：之后所有参数按原样传递
                    if ($paramType === '...') {
                        for (; $i < $argCount; $i++) {
                            $newArgs[] = $args[$i];
                        }
                        break;
                    }
                    $code = $args[$i];
                    if ($paramType === 'char*') {
                        if ($a instanceof StringLiteralExpr) {
                            // 字符串字面量：剥去 STR_LIT(...) 包装，直接传 C 字符串字面量
                            $generated = $args[$i];
                            if (str_starts_with($generated, 'STR_LIT(') && str_ends_with($generated, ')')) {
                                $code = substr($generated, strlen('STR_LIT('), -1);
                            }
                        } else {
                            // t_string 变量/表达式：访问 .data 字段获取 char*
                            $argType = $this->inferType($a);
                            if ($argType === 't_string') {
                                $code = $args[$i] . '.data';
                            }
                        }
                    }
                    $newArgs[] = $code;
                }
                $args = $newArgs;
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
        // 可变参数方法：最后一个形参是 ...$args
        if ($mInfoForDefault !== null && $mInfoForDefault->isVariadic) {
            $variadicPos = $mInfoForDefault->totalParams - 1;
            $fixedPTypes = array_slice($mInfoForDefault->paramTypes, 0, $variadicPos);
            [$fixedCodes, $variadicCode] = $this->generateVariadicArgs($node, $variadicPos, $fixedPTypes);
            // self 参数（非静态方法）
            if ($isStatic) {
                $allCodes = $fixedCodes;
            } else {
                $selfArg = $useParent
                    ? ($isParentCall ? '&self->_parent' : ('&' . $callee . '->_parent'))
                    : $callee;
                $allCodes = array_merge([$selfArg], $fixedCodes);
            }
            $allCodes[] = $variadicCode;
            $methodCName = "{$cnClean}_{$node->name}";
            $call = "{$methodCName}(" . implode(', ', $allCodes) . ')';
            // nullsafe ?-> : wrap in NULL check with temp variable
            if ($node->isNullsafe) {
                $ret = $mInfoForDefault->retType;
                if ($ret === 'void') {
                    return "({ if ((void*){$callee} != NULL) {{ {$call}; }} })";
                }
                $tmp = '_nsr_' . (++$this->tmpVarCounter);
                $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
                return "({ {$ret} {$tmp} = {$zero}; if ((void*){$callee} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
            }
            return $call;
        }
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
            // 静态方法调用链：Color::green()->toUint() → callee=VariableExpr(Color)
            //   VariableExpr 的 name 是类名（非 $var），查 resolveClass 获取 C 类名
            $resolved = $this->symbols->resolveClass($expr->callee->name);
            if ($resolved !== null) return rtrim($resolved, '*');
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

        // mixed (t_var) 回调通过 value._callback 访问 func/env 字段；
        // t_callback 直接访问 .func/.env
        // 场景：$cb = $this->onClick; $cb(); — onClick 是 mixed 属性，存储为 t_var
        $calleeType = $this->inferType($callee);
        if ($calleeType === 't_var') {
            $cbFunc = "{$calleeCode}.value._callback.func";
            $cbEnv = "{$calleeCode}.value._callback.env";
        } else {
            $cbFunc = "{$calleeCode}.func";
            $cbEnv = "{$calleeCode}.env";
        }
        $callArgs = ($argStr !== '' ? $argStr . ', ' : '') . $cbEnv;

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

        return "(($retType(*)({$paramTypes})){$cbFunc})({$callArgs})";
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
        if ($expr instanceof CallableConvertExpr) {
            // First-class callable: 查询 visitCallableConvert 注册的闭包签名
            $sigName = $this->callableConvertSigName($expr);
            if ($sigName !== null && $this->symbols->getClosureSig($sigName) !== null) {
                $sig = $this->symbols->getClosureSig($sigName);
                $params = $sig['params'] !== '' ? array_map('trim', explode(',', $sig['params'])) : [];
                return ['ret' => $sig['ret'], 'params' => $params];
            }
        }
        return null;
    }

    /**
     * 推导 CallableConvertExpr 对应的闭包签名名（与 visitCallableConvert 一致）
     *   注意：调用前需确保 visitCallableConvert 已运行（注册签名到 SymbolTable）
     * @return string|null 签名名，null 表示无法推导
     */
    private function callableConvertSigName(CallableConvertExpr $node): ?string
    {
        // 1. 全局函数
        if ($node->callee === null) {
            $name = $node->name;
            if (isset(self::$simpleFnMap[$name])) {
                return self::$simpleFnMap[$name]['cName'];
            }
            // 用户函数
            $pos = strrpos($name, '\\');
            if ($pos !== false) {
                $ns = substr($name, 0, $pos);
                $fn = substr($name, $pos + 1);
                return 'tphp_na_' . self::mangleCName($ns) . '_tphp_fn_' . $fn;
            }
            return 'tphp_fn_' . self::mangleCName($name);
        }

        // 2. $var(...) — 闭包变量引用，签名通过 getVarClosure 查询
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && $node->name === '__invoke') {
            $varName = self::varName($node->callee->name);
            return $this->symbols->getVarClosure($varName);
        }

        // 3. C->func(...)
        if ($node->isRawC) {
            return $node->name;
        }

        // 4. Class::method(...) / $obj->method(...)
        $callee = $node->callee;
        if ($callee instanceof VariableExpr && !str_starts_with($callee->name, '$')) {
            // 静态方法
            $cn = $this->symbols->resolveClass($callee->name) ?? ('tphp_class_' . $callee->name);
            return "{$cn}_{$node->name}";
        }
        // 实例方法：签名名为 thunk 名，但 thunk 名是动态生成的（_method_thunk_N）
        //   无法在此静态推导，返回 null 让调用方使用默认类型
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
        // array<T> 源数组自动协变转换为 t_array*（arrayArgCode 处理 t_arr_int*/t_arr_str* 等）
        $arrCode = $this->arrayArgCode($node->args[1], $node->args[1]->accept($this));
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
        // array<T> 源数组自动协变转换
        $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
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
        // array<T> 源数组自动协变转换
        $arrCode  = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
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
            // t_array* → VAR_ARRAY（必须在通用指针分支前处理）
            if ($propType === 't_array*') {
                return "VAR_ARRAY({$code})";
            }
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
            $code = $expr->accept($this);
            // 枚举常量访问（非 case）→ 按声明类型包装
            if (!$this->symbols->hasEnumCase($expr->enumName, $expr->caseName)) {
                $ct = $this->symbols->getEnumConstType($expr->enumName, $expr->caseName) ?? 't_string';
                return match ($ct) {
                    't_int'    => "VAR_INT({$code})",
                    't_float'  => "VAR_FLOAT({$code})",
                    't_bool'   => "VAR_BOOL({$code})",
                    default    => "VAR_STRING({$code})",
                };
            }
            // case 访问 → 用 ->value 取值
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
            // array<mixed> 元素已经是 t_var，visitArrayAccess 返回 t_var 值，无需 VAR_* 包装
            if ($expr->array instanceof VariableExpr) {
                $vn = self::varName($expr->array->name);
                if (($this->arrElementTypes[$vn] ?? null) === 't_var') {
                    return $expr->accept($this);
                }
                // t_var 变量持有数组（$sub = $arr[0]; $sub[1]）：
                //   visitArrayAccess 走 t_var 路径，返回 *tphp_fn_arr_index(...) 即 t_var 值，无需 VAR_* 包装
                if (($this->varTypes[$vn] ?? '') === 't_var') {
                    return $expr->accept($this);
                }
            }
            // 链式访问 $arr[0][1]：若内层为 t_var，外层也是 t_var，无需 VAR_* 包装
            if ($expr->array instanceof ArrayAccessExpr) {
                $innerType = $this->inferArrayAccessInnerType($expr->array);
                if ($innerType === 't_var') {
                    return $expr->accept($this);
                }
            }
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
                // arrElementTypes 追踪（动态字符串键 $a[$key]）：visitArrayAccess 生成 typed getter
                //   如 $dict[$key] = $freeEnt（int）→ arrElementTypes['dict']='t_int'
                //   visitArrayAccess 生成 tphp_fn_arr_get_str_int 返回 t_int，需 VAR_INT 包装
                //   仅对动态键生效：字面量键已有 arrValueTypes 精确追踪，arrElementTypes 可能与之冲突
                if (!($expr->index instanceof StringLiteralExpr) && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $et = $this->arrElementTypes[$at] ?? '';
                    if ($et === 't_int' || $et === 't_bool')   return "VAR_INT({$code})";
                    if ($et === 't_float')    return "VAR_FLOAT({$code})";
                    if ($et === 't_array*')   return "VAR_ARRAY({$code})";
                    if ($et === 't_callback') return "VAR_CALLBACK({$code})";
                    if ($et === 'null')       return "VAR_NULL()";
                    if ($et && (str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')))
                        return "VAR_OBJ({$code})";
                    if ($et === 't_string')   return "VAR_STRING({$code})";
                }
                return "VAR_STRING({$code})";
            }
            // int 键：优先用 inferArrayAccessActualType（与 visitArrayAccess 的 et 推导一致），
            //   回退到 inferType（含 TypeChecker inferredType）
            //   场景：$keys = array_keys($arr); $keys[0] — arrElementTypes='t_int'，
            //         visitArrayAccess 生成 typed getter 返回 t_int，需 VAR_INT 包装
            $type = $this->inferArrayAccessActualType($expr) ?? $this->inferType($expr);
            return match ($type) {
                't_string'   => "VAR_STRING({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                't_var'      => $code,  // array<mixed> 元素已是 t_var，无需包装
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
            't_var'      => $code,  // 已是 t_var，无需包装
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
        if ($node->castType === 'object') {
            return $this->castToObject($node->expr);
        }
        return '((' . self::mapType($node->castType) . ')(' . $node->expr->accept($this) . '))';
    }

    public function visitNew(NewExpr $node): string
    {
        // 构造函数命名参数重排
        $this->reorderNewArgs($node);

        $cn = self::classRefName($node->className);
        if ($cn === 'tphp_class_stdClass') {
            if (!empty($node->args)) {
                throw new \RuntimeException(
                    sprintf("[%d:%d] stdClass does not accept constructor arguments", $node->line, $node->column)
                );
            }
            return 'new_stdClass()';
        }
        $args = array_map(fn($a) => $a->accept($this), $node->args);
        // 默认参数重载：构造函数有默认值参数且实参数量 < 总参数时，使用 new_cn_<missing> 重载
        $ctorInfo = $this->symbols->getClassMethod($cn, '__construct');
        $allocName = "new_{$cn}";
        if ($ctorInfo !== null && $ctorInfo->defaultCount > 0
            && count($args) < $ctorInfo->totalParams) {
            $missing = $ctorInfo->totalParams - count($args);
            $allocName = "new_{$cn}_{$missing}";
        }
        if (empty($args) && $cn !== $this->className && $allocName === "new_{$cn}") {
            return "new_{$cn}()";
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
        // t_var 对象属性访问：需提取对象指针 .value._object
        //   场景1：foreach ($handle->postFields as $v) — $v 是 t_var，含 CURLFile 对象
        //          $v->postname → $v.value._object->_parent.postname（未知具体类型，走默认路径）
        //   场景2：if ($v instanceof CURLFile) { $v->postname } — 类型窄化后
        //          $v->postname → ((tphp_class_CURLFile*)($v).value._object)->postname
        if ($node->object instanceof VariableExpr && str_starts_with($node->object->name, '$')) {
            $vn = self::varName($node->object->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                if (isset($this->narrowedTypes[$vn])) {
                    // 类型窄化：instanceof 分支内，cast 到具体类指针
                    $cn = $this->narrowedTypes[$vn];
                    $obj = "(({$cn}*)({$obj}).value._object)";
                } else {
                    $obj = "({$obj}).value._object";
                }
            }
        }
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
            $vn = self::varName($node->object->name);
            // 优先使用类型窄化（instanceof 分支内）
            if (isset($this->narrowedTypes[$vn])) {
                $objCN = $this->narrowedTypes[$vn];
            } else {
                $objType = $this->varTypes[$vn] ?? '';
                // tphp_class_Dog* → tphp_class_Dog
                $objCN = rtrim($objType, '*');
            }
        }
        // stdClass 动态属性访问：$obj->prop → tphp_fn_stdclass_get(obj, c_str("prop"))
        if ($objCN === 'tphp_class_stdClass' && !ctype_upper($prop[0] ?? '')) {
            $access = "tphp_fn_stdclass_get({$obj}, STR_LIT(\"{$prop}\"))";
            if ($node->isNullsafe) {
                return $this->wrapNullsafeAccess($obj, $access, 't_var');
            }
            return $access;
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
                $access = $obj . '->' . $prop;
                if ($node->isNullsafe) {
                    return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
                }
                return $access;
            }
            // 类常量（大写开头）→ 不经过 _parent，由下方 const 逻辑处理
            if ($prop !== '' && ctype_upper($prop[0])) {
                // 交由下方类常量访问逻辑 → TPHP_CONST_ 引用
            } else {
                $prefix = $this->resolvePropPrefix($objCN, $prop);
                $access = $obj . '->_parent.' . $prefix . $prop;
                if ($node->isNullsafe) {
                    return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
                }
                return $access;
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
            $access = "({$obj})->{$prop}";
            if ($node->isNullsafe) {
                return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
            }
            return $access;
        }
        $access = "{$obj}->{$prop}";
        if ($node->isNullsafe) {
            return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
        }
        return $access;
    }

    /**
     * 包装属性访问为 nullsafe 表达式：对象为 NULL 时返回零值，否则返回属性值
     *   生成: ({ ret_type tmp = zero; if (obj != NULL) { tmp = access; } tmp; })
     */
    private function wrapNullsafeAccess(string $obj, string $access, string $retType): string
    {
        $tmp = '_nsr_' . (++$this->tmpVarCounter);
        $zero = match ($retType) {
            't_float' => '0.0',
            't_string' => '(t_string){NULL,0}',
            't_bool' => 'false',
            default => str_ends_with($retType, '*') ? 'NULL' : '0',
        };
        return "({ {$retType} {$tmp} = {$zero}; if ((void*){$obj} != NULL) {{ {$tmp} = {$access}; }} {$tmp}; })";
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
        // UnaryExpr（如 -1、~1）按操作数字面量类型推导
        $isUnary = $node->value instanceof UnaryExpr;
        $litCType = self::$litTypeMap[$node->value::class] ?? null;
        if ($isUnary && $litCType === null) {
            $litCType = self::$litTypeMap[$node->value->expr::class] ?? null;
        }
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
        // UnaryExpr（如 -1、~1）：编译期求值为字面量
        if ($isUnary) {
            $operand = $node->value->expr;
            $op = $node->value->operator;
            if ($operand instanceof IntLiteralExpr) {
                $iv = (int)$operand->value;
                if ($op === '-') {
                    return '#define ' . $name . ' ' . (-$iv);
                }
                if ($op === '~') {
                    return '#define ' . $name . ' ' . (~$iv);
                }
            }
            if ($operand instanceof FloatLiteralExpr) {
                $fv = (float)$operand->value;
                if ($op === '-') {
                    $rv = -$fv;
                    return '#define ' . $name . ' ' .
                        (($rv == (float)(int)$rv) ? sprintf('%.1f', $rv) : rtrim(rtrim(sprintf('%.15g', $rv), '0'), '.'));
                }
            }
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
        // trait 自身不参与查找（编译期已扁平化到使用方）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
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
            // C 函数签名声明不参与注解注册
            if ($fn->isCDeclaration) continue;
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
        // trait 自身不参与查找（编译期已扁平化到使用方）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
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
        // trait 自身不参与查找（编译期已扁平化到使用方）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
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
            $pns = array_map(fn($p) => ltrim($p->name, '$'), $m->params);
            $tp = count($m->params);
            $dc = 0;
            for ($i = $tp - 1; $i >= 0; $i--) {
                if ($m->params[$i]->default !== null) { $dc++; } else { break; }
            }
            $mi = new MethodInfo($mr, $pts, false, 'public', $dc, $tp, false, false, $pns);
            $this->symbols->addEnumMethod($fqName, $m->name, $mi);
            $this->symbols->addEnumMethod($node->name, $m->name, $mi);
        }
        // 自动方法注册（静态）
        $paramCType = ($node->backingType === 'int') ? 't_int' : 't_string';
        foreach ($autoStatic as $mname => $mret) {
            $mi = new MethodInfo($mret, [$paramCType], true, 'public', 0, 1, false, false, ['value']);
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
        // 条件上下文：解包 t_var 为 bool（array<mixed> 元素在 if 中使用）
        $cond = $this->unwrapIfMixed($node->condition, $cond, 't_bool');
        $this->scopeDepth++;
        $lines = [];
        $lines[] = "if ({$cond}) {";
        // 类型窄化：if ($v instanceof X) 分支内，$v 视为 tphp_class_X* 类型
        $narrowed = $this->applyTypeNarrowing($node->condition);
        // 保存 declaredVars 快照，使兄弟分支（elseif/else）各自独立声明变量
        //   场景：if (hasFile) { foreach($k=>$v) } else { foreach($k=>$v) }
        //   无快照恢复时，$k 在 then 分支声明后，else 分支不会重复声明 → C 编译报 undeclared
        $savedDeclared = $this->declaredVars;
        foreach ($node->thenBody as $s) $lines[] = $this->ind($s->accept($this));
        $this->restoreTypeNarrowing($narrowed);
        $thenDeclared = $this->declaredVars;
        $lines[] = '}';
        foreach ($node->elseifs as $eif) {
            $econd = $eif->condition->accept($this);
            $econd = $this->unwrapIfMixed($eif->condition, $econd, 't_bool');
            $lines[] = "else if ({$econd}) {";
            $this->declaredVars = $savedDeclared;
            $narrowed = $this->applyTypeNarrowing($eif->condition);
            foreach ($eif->body as $s) $lines[] = $this->ind($s->accept($this));
            $this->restoreTypeNarrowing($narrowed);
            $thenDeclared = array_merge($thenDeclared, $this->declaredVars);
            $lines[] = '}';
        }
        if (!empty($node->elseBody)) {
            $lines[] = 'else {';
            $this->declaredVars = $savedDeclared;
            foreach ($node->elseBody as $s) $lines[] = $this->ind($s->accept($this));
            $thenDeclared = array_merge($thenDeclared, $this->declaredVars);
            $lines[] = '}';
        }
        // 合并所有分支的声明（PHP 函数级作用域，C 块作用域变量在 if 后仍可见）
        $this->declaredVars = array_merge($savedDeclared, $thenDeclared);
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    /**
     * 检测 if 条件中的 instanceof 模式，应用类型窄化
     *   场景：if ($v instanceof CURLFile) { ... $v->postname ... }
     *   效果：在分支内将 $v 的窄化类型设为 tphp_class_CURLFile
     *   仅处理简单 instanceof 表达式（非 || 联合），&& 链中各 instanceof 也生效
     * @return array 保存的旧窄化值，供 restoreTypeNarrowing 恢复
     */
    private function applyTypeNarrowing(ExprNode $cond): array
    {
        // 递归找 && 链中的 instanceof 子表达式（&& 左右侧均可窄化）
        $allSaved = [];
        $expr = $cond;
        while ($expr instanceof BinaryExpr && $expr->operator === '&&') {
            $allSaved = array_merge($allSaved, $this->applyTypeNarrowingSingle($expr->left));
            $expr = $expr->right;
        }
        $allSaved = array_merge($allSaved, $this->applyTypeNarrowingSingle($expr));
        return $allSaved;
    }

    /**
     * 单个 instanceof 表达式的类型窄化
     * @return array [varName => [old => oldVal, new => newVal]]
     */
    private function applyTypeNarrowingSingle(ExprNode $expr): array
    {
        if (!($expr instanceof BinaryExpr) || $expr->operator !== 'instanceof') {
            return [];
        }
        if (!($expr->left instanceof VariableExpr) || !str_starts_with($expr->left->name, '$')) {
            return [];
        }
        // 右操作数为类名（裸标识符，非 $variable）
        if (!($expr->right instanceof VariableExpr) || str_starts_with($expr->right->name, '$')) {
            return [];
        }
        $className = $expr->right->name;
        $cn = 'tphp_class_' . $className;
        $vn = self::varName($expr->left->name);
        $saved = [$vn => ['old' => $this->narrowedTypes[$vn] ?? null, 'new' => $cn]];
        $this->narrowedTypes[$vn] = $cn;
        return $saved;
    }

    /**
     * 恢复类型窄化到应用前的状态
     */
    private function restoreTypeNarrowing(array $saved): void
    {
        foreach ($saved as $vn => $info) {
            if ($info['old'] === null) {
                unset($this->narrowedTypes[$vn]);
            } else {
                $this->narrowedTypes[$vn] = $info['old'];
            }
        }
    }

    public function visitWhileStmt(WhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $cond = $this->unwrapIfMixed($node->condition, $cond, 't_bool');
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
        // 源是 t_var 变量（如 foreach 值）：从 .value._array 提取 t_array*
        //   场景：foreach ($users as $u) { list("id" => $uid) = $u; }
        //   $u 是 t_var，持有 TYPE_ARRAY，需提取 .value._array
        if ($node->expr instanceof VariableExpr) {
            $vn = self::varName($node->expr->name);
            $vt = $this->varTypes[$vn] ?? '';
            if ($vt === 't_var') {
                $expr = "(({$expr}).value._array)";
            }
        }
        $lines[] = "t_array* {$arrName} = {$expr};";
        // 推断源数组元素类型，用于 list 解构变量类型
        $elemType = 't_int';
        $srcLiteral = null;
        // 源变量名（用于 per-key 类型查询 arrValueTypes）
        $srcVarName = '';
        if ($node->expr instanceof VariableExpr) {
            $vn = self::varName($node->expr->name);
            $elemType = $this->arrElementTypes[$vn] ?? 't_int';
            $srcVarName = $vn;
            // 变量源数组的字面量 AST（若有）— 用于 per-key 精确类型推断
            if (isset($this->arrLiteralAST[$vn])) {
                $srcLiteral = $this->arrLiteralAST[$vn];
            }
            // 源是 t_var (array<mixed>)：元素类型为 t_var，避免错误地按 t_int 解包
            //   场景：foreach ($users as $u) { ["id" => $uid, "name" => $uname] = $u; }
            //   $u 是 t_var，$uid/$uname 应保持 t_var 由后续运算自动转换
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                $elemType = 't_var';
            }
        } elseif ($node->expr instanceof ArrayLiteralExpr) {
            $elemType = $this->inferArrayDeepElementType($node->expr);
            $srcLiteral = $node->expr;
        }
        $this->generateListAssign($lines, $arrName, 0, $node->vars, $elemType, $srcLiteral);
        // Keyed destructuring: ['key' => $var, ...] = $arr
        if (!empty($node->keyedEntries)) {
            $this->generateKeyedAssign($lines, $arrName, $node->keyedEntries, $elemType, $srcLiteral, $srcVarName);
        }
        return implode("\n", $lines);
    }

    /** Generate assignments for keyed list destructuring:
     *  ['key' => $var] = $arr  →  $var = tphp_fn_arr_get_str_int($arr, STR_LIT("key"));
     *  [0 => $var] = $arr      →  $var = tphp_fn_arr_get_int_int($arr, 0);  (整数键名)
     *  支持 per-key 类型推断：混合类型数组（如 ["age"=>30, "name"=>"Alice"]）按 key 单独推断元素类型
     *  @param ArrayLiteralExpr|null $srcLiteral  源数组字面量 AST（用于按 key 查找 entry 推断类型）
     *  @param string                $srcVarName  源变量名（用于查 arrValueTypes[var][key] per-key 类型追踪）
     */
    private function generateKeyedAssign(array &$lines, string $arrName, array $entries, string $elemType = 't_int', ?ArrayLiteralExpr $srcLiteral = null, string $srcVarName = ''): void
    {
        foreach ($entries as $e) {
            $key = $e['key'];
            $keyIsInt = $e['keyIsInt'] ?? false;
            $var = self::varName('$' . $e['var']);
            // per-key 类型推断：优先从源数组字面量或 arrValueTypes 查找精确类型
            $entryType = $elemType;
            if ($srcLiteral !== null) {
                foreach ($srcLiteral->entries as $entry) {
                    $keyMatches = $keyIsInt
                        ? ($entry->key instanceof IntegerLiteralExpr && $entry->key->value === $key)
                        : ($entry->key instanceof StringLiteralExpr && $entry->key->value === $key);
                    if ($keyMatches) {
                        $val = $entry->value ?? $entry;
                        if ($val !== null) {
                            $inferred = $this->inferType($val);
                            if ($inferred !== 'null') {
                                $entryType = $inferred;
                                if (str_contains($entryType, 'tphp_class_') && !str_ends_with($entryType, '*')) $entryType .= '*';
                            }
                        }
                        break;
                    }
                }
            } elseif ($srcVarName !== '' && !$keyIsInt && isset($this->arrValueTypes[$srcVarName][$key])) {
                $entryType = $this->arrValueTypes[$srcVarName][$key];
            }
            // 元素类型 → keyed getter 后缀
            $getterSuffix = match ($entryType) {
                't_float'    => 'float',
                't_string'   => 'str',
                't_bool'     => 'int',  // tphp 无 arr_get_str_bool，用 int
                't_array*'   => 'arr',
                't_callback' => 'callback',
                default      => 'int',
            };
            $cType = $this->typeToCType($entryType);
            $isDeclared = isset($this->declaredVars[$var]);
            // 已声明的标量/数组/回调变量保持原类型（list 解构复用已有变量时不改类型）
            //   场景：$default = 0; ["missing" => $default] = $src2; — $default 仍是 t_int
            //   elemType 为 t_var 时，用 typed getter (arr_get_str_int) 而非 *arr_get_str
            //   避免将 t_var 赋给已声明为 t_int 的变量导致类型错误
            if ($isDeclared
                && in_array($this->varTypes[$var] ?? '', ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback'], true)) {
                $existingType = $this->varTypes[$var];
                $entryType = $existingType;
                $getterSuffix = match ($existingType) {
                    't_float'    => 'float',
                    't_string'   => 'str',
                    't_bool'     => 'int',
                    't_array*'   => 'arr',
                    't_callback' => 'callback',
                    default      => 'int',
                };
                $cType = $this->typeToCType($existingType);
            }
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = $entryType;
            // 数组类型：传播源数组的 per-key 类型和字面量 AST，支持后续 $sub["k"] 访问类型推断
            //   如 ["outer" => $sub] = ["outer" => ["a"=>"first"]] → $sub["a"] 应为 string
            if ($entryType === 't_array*' && !$keyIsInt) {
                if ($srcLiteral !== null) {
                    foreach ($srcLiteral->entries as $entry) {
                        if ($entry->key instanceof StringLiteralExpr && $entry->key->value === $key) {
                            $val = $entry->value ?? $entry;
                            if ($val instanceof ArrayLiteralExpr) {
                                $this->arrLiteralAST[$var] = $val;
                                $this->arrElementTypes[$var] = $this->inferArrayDeepElementType($val);
                                // 传播 per-key 类型
                                foreach ($val->entries as $subEntry) {
                                    if ($subEntry->key instanceof StringLiteralExpr) {
                                        $subVal = $subEntry->value ?? $subEntry;
                                        if ($subVal !== null) {
                                            $subValType = $this->inferType($subVal);
                                            if ($subValType !== 'null') {
                                                $this->arrValueTypes[$var] ??= [];
                                                $this->arrValueTypes[$var][$subEntry->key->value] = $subValType;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            }
            $prefix = $isDeclared ? '' : ($cType . ' ');
            // t_var 元素：直接取 t_var* 解引用（array<mixed> 元素本身就是 t_var）
            if ($entryType === 't_var') {
                if ($keyIsInt) {
                    $lines[] = "{$prefix}{$var} = *tphp_fn_arr_get_int({$arrName}, (t_int){$key});";
                } else {
                    $klen = strlen((string)$key);
                    $lines[] = "{$prefix}{$var} = *tphp_fn_arr_get_str({$arrName}, (t_string){.data=\"{$key}\", .length={$klen}});";
                }
                continue;
            }
            // 整数键用 tphp_fn_arr_get_int_*，字符串键用 tphp_fn_arr_get_str_*
            if ($keyIsInt) {
                $lines[] = "{$prefix}{$var} = tphp_fn_arr_get_int_{$getterSuffix}({$arrName}, (t_int){$key});";
            } else {
                $klen = strlen((string)$key);
                $lines[] = "{$prefix}{$var} = tphp_fn_arr_get_str_{$getterSuffix}({$arrName}, (t_string){.data=\"{$key}\", .length={$klen}});";
            }
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
            // 普通变量（varName 转义 C 关键字，如 $default → _default）
            $var = self::varName('$' . $item);
            $isDeclared = isset($this->declaredVars[$var]);
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = $itemElemType;
            $prefix = $isDeclared ? '' : ($itemCType . ' ');
            $zeroVal = match ($itemElemType) {
                't_string'   => '(t_string){0}',
                't_float'    => '0.0',
                't_array*'   => 'NULL',
                't_callback' => 'NULL',
                't_var'      => 'VAR_NULL()',
                default      => '0',  // t_int, t_bool
            };
            // t_var 元素：直接解引用 t_var*（array<mixed> 元素本身就是 t_var）
            if ($itemElemType === 't_var') {
                $lines[] = "{$prefix}{$var} = ({$arrName} && {$arrName}->length > {$idx}) ? *tphp_fn_arr_index({$arrName}, {$idx}) : {$zeroVal};";
            } else {
                $lines[] = "{$prefix}{$var} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_item_{$getterSuffix}({$arrName}, {$idx}) : {$zeroVal};";
            }
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
            't_var'      => 't_var',
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

        // stdClass foreach: 遍历内部 t_array 的动态属性
        if (str_contains($iterType, 'tphp_class_stdClass')) {
            return $this->emitStdClassForeach($node);
        }

        // 泛型数组分支：t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*/t_arr_ptr*
        //   直接访问 entries[i].val，无需 t_var 包装和类型检查
        $genElemCType = self::genericArrayElemCType($iterType);
        if ($genElemCType !== null) {
            return $this->emitGenericArrayForeach($node, $iterType, $genElemCType);
        }

        $arr  = $this->arrayArgCode($node->array, $node->array->accept($this));
        $cnt  = '_fc_' . (++$this->tmpVarCounter);
        $idx  = '_fi_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        // 推断数组元素类型
        $elemType = 't_int';
        if ($node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            $elemType = $this->arrElementTypes[$arrVarName] ?? 't_int';
            // t_var 变量持有数组：元素统一为 t_var（array<mixed> 语义）
            //   arrElementTypes 可能记录实际值类型（如 t_array*），但 t_var 数组
            //   的元素在 C 层都是 t_var，foreach 取值需用 *_eval 直接取 t_var
            if (($this->varTypes[$arrVarName] ?? '') === 't_var') {
                $elemType = 't_var';
            }
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
            } else {
                // 属性数组未注册元素类型时，默认 t_var（mixed 语义）
                //   PHP 数组为动态类型，默认 t_int 会导致对象/字符串元素被错误解包
                //   场景：$handle->postFields 含 CURLFile/CURLStringFile/string
                $elemType = 't_var';
            }
        } elseif ($node->array instanceof CallExpr
            && $node->array->name === 'cases'
            && $node->array->callee instanceof VariableExpr) {
            // foreach (Color::cases() as $c) — 枚举 cases() 返回 array<enum>
            $enumCName = $this->symbols->resolveEnumCName($node->array->callee->name);
            if ($enumCName !== null) {
                $elemType = $enumCName . '*';
            }
        }
        // 规范化元素类型名
        if (str_contains($elemType, 'tphp_class_') && !str_ends_with($elemType, '*')) {
            $elemType .= '*';
        }
        if (str_contains($elemType, 'tphp_enum_') && !str_ends_with($elemType, '*')) {
            $elemType .= '*';
        }

        // 混合类型数组检测：[1, "foo", 2.5] → 元素为 t_var
        //   inferArrayElementType 对混合数组会锁定为某个具体类型（如 t_string），
        //   导致 int/float 元素被错误解包为空字符串。
        //   仅当数组字面量包含多种不同类型时，才覆盖为 t_var（万能数组语义）。
        if ($node->array instanceof VariableExpr) {
            $arrVarName2 = self::varName($node->array->name);
            if (isset($this->arrLiteralAST[$arrVarName2])
                && $this->isMixedArrayLiteral($this->arrLiteralAST[$arrVarName2])) {
                $elemType = 't_var';
            }
        }

        // Mark vars as declared (after declaration check)
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));
        $needValDecl = !isset($this->declaredVars[$valVar]);

        $this->declaredVars[$valVar] = true;
        // 已声明的标量/数组/回调变量保持原类型（foreach 复用已有变量时不改类型）
        //   场景：$v = 42; foreach ($arr as $v) — $v 仍是 t_int，valRead 用 t_int 解包
        $existingValT = $this->varTypes[$valVar] ?? null;
        if ($existingValT !== null
            && in_array($existingValT, ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback'], true)) {
            // 保持已有类型，不覆盖为 $elemType
        } else {
            $this->varTypes[$valVar] = $elemType;
        }
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
                // array<mixed> 不追踪 arrValueTypes，回退到字面量 AST 检测字符串键
                //   场景：$map = ["name" => "Alice"]; foreach ($map as $k => $v)
                //   $map 是 array<mixed>（t_array*），但键是字符串，需用 t_string 提取
                if (!$hasStrKey && isset($this->arrLiteralAST[$arrVarName])) {
                    foreach ($this->arrLiteralAST[$arrVarName]->entries as $entry) {
                        if ($entry->key instanceof StringLiteralExpr) {
                            $hasStrKey = true;
                            break;
                        }
                    }
                }
            }
            $keyType = $hasStrKey ? 't_string' : 't_int';
            // 已声明的 key 变量保持原类型（foreach 复用已有 key 变量时不改类型）
            if (isset($this->varTypes[$keyVar])
                && in_array($this->varTypes[$keyVar], ['t_int', 't_string'], true)) {
                $keyType = $this->varTypes[$keyVar];
            } else {
                $this->varTypes[$keyVar] = $keyType;
            }
        }

        // 根据元素类型生成值读取代码
        //   若 $valVar 已声明为具体类型（如 t_int），用该类型从 t_var 元素中提取值
        //   场景：$v = 42; foreach ($arr as $v) — $v 已是 t_int，需 VAR_AS_INT 解包
        //   否则用 $elemType（数组元素推导类型）生成 valRead
        $effectiveElemType = $elemType;
        if (isset($this->varTypes[$valVar]) && $this->varTypes[$valVar] !== 't_var') {
            $existingType = $this->varTypes[$valVar];
            // 仅当已有类型是标量/数组/回调时覆盖（避免类类型与 elemType 冲突）
            if (in_array($existingType, ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback'], true)) {
                $effectiveElemType = $existingType;
            }
        }
        $valRead = match ($effectiveElemType) {
            't_float'    => "(_eval->type == TYPE_FLOAT) ? (t_float)_eval->value._float : 0.0",
            't_string'   => "(_eval->type == TYPE_STRING) ? _eval->value._string : ((t_string){NULL, 0})",
            't_bool'     => "(_eval->type == TYPE_BOOL) ? (t_bool)_eval->value._bool : false",
            't_array*'   => "(_eval->type == TYPE_ARRAY) ? _eval->value._array : NULL",
            't_callback' => "(_eval->type == TYPE_CALLBACK) ? _eval->value._callback : ((t_callback){NULL, NULL})",
            't_var'      => "*_eval",  // array<mixed>：直接取 t_var，由 var_dump 等按 type 标签分发
            default      => (str_contains($effectiveElemType, 'tphp_class_') || str_contains($effectiveElemType, 'tphp_enum_')
                ? "(_eval->type == TYPE_OBJECT) ? (({$effectiveElemType})_eval->value._ptr) : NULL"
                : "(_eval->type == TYPE_INT) ? (t_int)_eval->value._int : 0"),
        };

        $valDecl = match ($elemType) {
            't_float'  => 't_float',
            't_string' => 't_string',
            't_bool'   => 't_bool',
            't_array*' => 't_array*',
            't_callback' => 't_callback',
            't_var'    => 't_var',  // array<mixed> 元素类型
            default    => (str_contains($elemType, 'tphp_class_') || str_contains($elemType, 'tphp_enum_') ? $elemType : 't_int'),
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

    /** 生成 stdClass foreach 代码：遍历内部 t_array 的动态属性 */
    private function emitStdClassForeach(ForeachStmtNode $node): string
    {
        $objCode = $node->array->accept($this);
        // 提取 props 指针（stdClass 结构体的动态属性表）
        $propsCode = "((tphp_class_stdClass*)({$objCode}))->props";

        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        $needValDecl = !isset($this->declaredVars[$valVar]);
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = 't_var';
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            $this->varTypes[$keyVar] = 't_string';
        }

        $idx = '_fi_' . (++$this->tmpVarCounter);
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;

        $lines = [];
        if ($needKeyDecl) {
            $lines[] = "t_string {$keyVar};";
        }
        if ($needValDecl) {
            $lines[] = "t_var {$valVar};";
        }
        $lines[] = "{$startLabel}:;";
        $lines[] = "for (int {$idx} = 0; {$idx} < tphp_fn_arr_count({$propsCode}); {$idx}++) {";
        $lines[] = $this->ind("if ({$propsCode} == NULL) break;");
        $lines[] = $this->ind("const t_arr_entry* _se = &{$propsCode}->entries[{$idx}];");
        if ($keyVar) {
            $lines[] = $this->ind("{$keyVar} = (_se->key.type == TYPE_STRING) ? _se->key.value._string : ((t_string){NULL, 0});");
        }
        $lines[] = $this->ind("{$valVar} = _se->val;");
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

    /**
     * 泛型数组 foreach：直接访问 entries[i].val，无需 t_var 包装。
     *
     * @param ForeachStmtNode $node     foreach 节点
     * @param string          $arrCType 数组 C 类型（如 t_arr_int*）
     * @param string          $elemCType 元素 C 类型（如 t_int）
     */
    private function emitGenericArrayForeach(ForeachStmtNode $node, string $arrCType, string $elemCType): string
    {
        $arr    = $node->array->accept($this);
        $idx    = '_fi_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        // 值变量类型与声明
        $valDecl = $elemCType;
        // void* 元素（array<array<T>>/array<Foo>）→ 保持原指针类型
        if ($elemCType === 'void*') {
            // 嵌套数组或对象数组：元素类型需要从上下文推断
            // 暂时回退到 t_array* 或对象指针类型
            $valDecl = 't_array*';  // 简化：嵌套数组元素当作 t_array*
        }

        $needValDecl = !isset($this->declaredVars[$valVar]);
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = $valDecl;
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            $this->varTypes[$keyVar] = 't_int';  // 泛型数组 key 统一为 int（连续下标）
        }

        $endLabel   = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel  = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;

        $lines = [];
        if ($needKeyDecl) {
            $lines[] = "t_int {$keyVar};";
        }
        if ($needValDecl) {
            $lines[] = "{$valDecl} {$valVar};";
        }
        $lines[] = "{$startLabel}:;";
        $lines[] = "for (int {$idx} = 0; {$idx} < {$arr}->length; {$idx}++) {";
        if ($keyVar) {
            $lines[] = $this->ind("{$keyVar} = (t_int){$idx};");
        }
        // 直接读取 entries[idx].val，无需类型检查（泛型数组类型安全）
        $lines[] = $this->ind("{$valVar} = {$arr}->entries[{$idx}].val;");
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

        // 多 catch 子句：每个生成 TP_CATCH_EX(var, Type) 或 TP_CATCH_EX_MULTI(var, cond)
        // 最后无类型兜底用 TP_CATCH_ANY（捕获非对象异常如 tp_throw("str")）
        $hasCatch = !empty($node->catchClauses);
        $hasObjCatch = false;
        foreach ($node->catchClauses as $clause) {
            $cv = $clause['var'];
            $ct = $clause['type'];
            $this->declaredVars[$cv] = true;
            // 多异常 catch (A | B $e)：所有类型必须为已声明的 class
            if (is_array($ct)) {
                $types = $ct;
                $conds = [];
                $allClass = true;
                foreach ($types as $t) {
                    $resolvedCn = $this->symbols->resolveClass($t);
                    $isClass = $resolvedCn !== null || $t === 'Exception' || $this->symbols->hasClass('tphp_class_' . $t);
                    if (!$isClass) { $allClass = false; break; }
                    $conds[] = 'tp_obj_is_a(_tp_ex_top->ex_obj, &_class_tphp_class_' . $t . ')';
                }
                if ($allClass && !empty($conds)) {
                    $this->varTypes[$cv] = 'tphp_class_Exception*';
                    $cond = implode(' || ', $conds);
                    $lines[] = 'TP_CATCH_EX_MULTI(' . $cv . ', (' . $cond . '))';
                    $hasObjCatch = true;
                } else {
                    // 含未知类型 → 兜底字符串消息
                    $this->varTypes[$cv] = 't_string';
                    $lines[] = 'TP_CATCH_ANY(' . $cv . ')';
                }
            } else {
                // 单异常 catch (Type $e)
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
     * or {} 错误处理块 — 直接调用时报错
     *
     * OrBlockExpr 仅在语句级上下文（AssignStmt 右式 / ExprStmt 表达式）有效，
     * 由 visitAssignStmt / visitExprStmt 检测并委托到 generateAssignWithOrBlock /
     * generateExprWithOrBlock 处理。直接调用 visitOrBlock 表示 OrBlockExpr 出现在
     * 非法位置（如嵌套表达式内），编译期捕获。
     */
    public function visitOrBlock(OrBlockExpr $node): string
    {
        throw new \RuntimeException(
            "or {} block can only appear at statement level (e.g., \$x = foo() or { ... }; or foo() or { ... };). "
            . "Cannot nest or {} block inside other expressions."
        );
    }

    /**
     * 生成 $var = (expr) or { ... }; 的 C 代码
     *
     * 等价于：
     *   TP_TRY {
     *       <var_decl> = <inner_expr>;
     *   }
     *   TP_CATCH_EX(err, Exception) {
     *       <or_body>
     *   }
     *   TP_END_TRY
     *
     * 注意：若 or 块未 return/throw，var 在 catch 路径下未赋值（C 编译器会警告）
     */
    private function generateAssignWithOrBlock(AssignStmtNode $node): string
    {
        $orBlock = $node->expr;
        $innerExpr = $orBlock->expr;
        $var = self::varName($node->varName);
        $isDeclared = isset($this->declaredVars[$var]);
        $prevType = $this->varTypes[$var] ?? '';
        $isTVar = ($prevType === 't_var');

        // 推导内部表达式类型 + 生成内部代码
        $innerType = $this->inferType($innerExpr);
        $innerCode = $innerExpr->accept($this);
        $this->declaredVars[$var] = true;

        $lines = [];

        // 变量声明必须在 TP_TRY 之前 — TP_TRY 宏展开为 do { if(setjmp()==0){...} ... } while(0)，
        // 声明在 if 块内部时作用域不延伸到 TP_END_TRY 之后，导致后续无法访问变量。
        // 首次声明：在 TP_TRY 之前零初始化声明；TP_TRY 内部仅做赋值。
        if (!$isDeclared) {
            if ($node->type !== null) {
                $cType = self::mapType($node->type);
            } else {
                $cType = $innerType;
            }
            $this->varTypes[$var] = $cType;

            // t_var 变量：值需包装为 VAR_XXX 宏
            if ($cType === 't_var') {
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = $cType;
                } else {
                    $lines[] = $this->ind("{$cType} {$var} = {0};");
                }
                $wrap = $this->wrapTvarAssign($innerExpr, $innerCode);
                $assignLine = "{$var} = {$wrap};";
            } elseif (str_starts_with($cType, 'tphp_class_') && str_ends_with($cType, '*')) {
                // 对象指针类型：注册到全局资源表（自动析构）
                if ($this->indent == 1) {
                    $this->symbols->addScopeObject($var);
                }
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = $cType;
                } else {
                    $lines[] = $this->ind("{$cType} {$var} = NULL;");
                }
                $assignLine = "{$var} = {$innerCode}; tphp_rt_register((void*){$var}, 0);";
            } else {
                // 标量 / t_string / t_array* 等
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = $cType;
                } else {
                    $zeroInit = str_ends_with($cType, '*') ? 'NULL' : '{0}';
                    $lines[] = $this->ind("{$cType} {$var} = {$zeroInit};");
                }
                $assignLine = "{$var} = {$innerCode};";
            }
        } elseif ($isTVar) {
            // 已声明的 t_var 变量：仅赋值
            $wrap = $this->wrapTvarAssign($innerExpr, $innerCode);
            $assignLine = "{$var} = {$wrap};";
        } else {
            // 已声明变量：仅赋值
            $assignLine = "{$var} = {$innerCode};";
        }

        // 注册 $err 为 Exception* 类型（供 or 块内 $err->getMessage() 等访问）
        $this->declaredVars['err'] = true;
        $this->varTypes['err'] = 'tphp_class_Exception*';

        // 生成 or 块内语句
        $this->scopeDepth++;
        $orBodyLines = [];
        foreach ($orBlock->orBody as $stmt) {
            $orBodyLines[] = $this->ind($stmt->accept($this));
        }
        $this->scopeDepth--;

        // 拼装 TP_TRY/TP_CATCH_EX/TP_END_TRY
        $lines[] = $this->ind('TP_TRY');
        $this->scopeDepth++;
        $lines[] = $this->ind($assignLine);
        $this->scopeDepth--;
        $lines[] = $this->ind('TP_CATCH_EX(err, Exception)');
        $this->scopeDepth++;
        foreach ($orBodyLines as $line) {
            $lines[] = $line;
        }
        $this->scopeDepth--;
        $lines[] = $this->ind('TP_END_TRY');
        return implode("\n", $lines);
    }

    /**
     * 生成 (expr) or { ... }; 的 C 代码（语句级，无赋值目标）
     *
     * 等价于：
     *   TP_TRY {
     *       (void)<inner_expr>;
     *   }
     *   TP_CATCH_EX(err, Exception) {
     *       <or_body>
     *   }
     *   TP_END_TRY
     */
    private function generateExprWithOrBlock(OrBlockExpr $orBlock): string
    {
        $innerExpr = $orBlock->expr;
        $innerCode = $innerExpr->accept($this);

        // 注册 $err 为 Exception* 类型
        $this->declaredVars['err'] = true;
        $this->varTypes['err'] = 'tphp_class_Exception*';

        // 生成 or 块内语句
        $this->scopeDepth++;
        $orBodyLines = [];
        foreach ($orBlock->orBody as $stmt) {
            $orBodyLines[] = $this->ind($stmt->accept($this));
        }
        $this->scopeDepth--;

        // 拼装 TP_TRY/TP_CATCH_EX/TP_END_TRY
        $lines = [];
        $lines[] = $this->ind('TP_TRY');
        $this->scopeDepth++;
        $lines[] = $this->ind("(void){$innerCode};");
        $this->scopeDepth--;
        $lines[] = $this->ind('TP_CATCH_EX(err, Exception)');
        $this->scopeDepth++;
        foreach ($orBodyLines as $line) {
            $lines[] = $line;
        }
        $this->scopeDepth--;
        $lines[] = $this->ind('TP_END_TRY');
        return implode("\n", $lines);
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
            // t_var 目标：需先解包为 t_string 参与 concat，再用 VAR_STRING 包装回去
            //   场景：$path = $urlInfo["path"]; $path .= "?"; — $path 是 t_var
            $tt = $this->inferType($node->target);
            if ($tt === 't_var') {
                return "{$t} = VAR_STRING(tphp_rt_str_concat(tphp_fn_strval({$t}), {$vs}))";
            }
            return "{$t} = tphp_rt_str_concat({$t}, {$vs})";
        }
        // ??= 是 null 合并赋值：仅在目标为 null 时才赋值（惰性求值右式）
        if ($node->operator === '??=') {
            return $this->generateCoalesceAssign($node);
        }
        // t_var 解包：array<mixed> 元素（t_var）参与算术复合赋值时，
        //   需按目标类型解包为标量，避免 struct 直接参与算术运算导致编译错误
        //   场景：$s += $b[$i]; — $b 是 array<mixed>，$b[$i] 是 t_var，$s 是 t_int
        $vt = $this->inferType($node->value);
        if ($vt === 't_var') {
            $expect = $this->inferType($node->target);
            if ($expect === 't_var' || $expect === '') {
                $expect = 't_int';  // 两侧均为 t_var 时按 int 解包（PHP 数值语义）
            }
            $v = $this->unwrapIfMixed($node->value, $v, $expect);
        }
        return "{$t} {$node->operator} {$v}";
    }

    /**
     * 生成 ??= 复合赋值代码：仅当目标为 null 时赋值
     *   - t_var:        if (t.type == TYPE_NULL) t = v;
     *   - void* (obj):  if (t == NULL) t = v;
     *   - array access: if (!array_key_exists(...)) arr_set(...);
     *   - 其他值类型:   AOT 已知非 null，no-op
     */
    private function generateCoalesceAssign(CompoundAssignExpr $node): string
    {
        $lt = $this->inferType($node->target);
        // 数组键访问：需要运行时检查键是否存在
        if ($node->target instanceof ArrayAccessExpr) {
            $aa = $node->target;
            $arrCode = $aa->array->accept($this);
            $idxCode = $aa->index->accept($this);
            $idxType = $this->inferType($aa->index);
            $v = $node->value->accept($this);
            if ($idxType === 't_string' || $aa->index instanceof StringLiteralExpr) {
                $existsCheck = "tphp_fn_array_key_exists_str({$idxCode}, {$arrCode})";
                // 键不存在时设置：转译为 if (!exists) arr_set_str(arr, idx, v)
                $valCode = $this->wrapArrVal($node->value, $v);
                return "if (!{$existsCheck}) { tphp_fn_arr_set_str({$arrCode}, {$idxCode}, {$valCode}); }";
            }
            $existsCheck = "tphp_fn_array_key_exists_int((t_int)({$idxCode}), {$arrCode})";
            $valCode = $this->wrapArrVal($node->value, $v);
            return "if (!{$existsCheck}) { tphp_fn_arr_set_int({$arrCode}, (t_int)({$idxCode}), {$valCode}); }";
        }
        // t_var: 运行时 TYPE_NULL 检查
        if ($lt === 't_var') {
            $t = $node->target->accept($this);
            $v = $this->wrapTvarAssign($node->value, $node->value->accept($this));
            return "if ({$t}.type == TYPE_NULL) { {$t} = {$v}; }";
        }
        // void* (nullable 对象指针): NULL 检查
        if ($lt === 'void*') {
            $t = $node->target->accept($this);
            $v = $node->value->accept($this);
            return "if ({$t} == NULL) { {$t} = {$v}; }";
        }
        // 其他值类型: AOT 已知非 null，no-op（返回空语句）
        return "/* ??= on non-nullable type: no-op */";
    }

    /** 将表达式包装为 t_var 联合体值（用于数组赋值） */
    private function wrapArrVal(ExprNode $expr, string $code): string
    {
        $et = $this->inferType($expr);
        return match ($et) {
            't_int'    => "VAR_INT({$code})",
            't_float'  => "VAR_FLOAT({$code})",
            't_bool'   => "VAR_BOOL({$code})",
            't_string' => "VAR_STRING({$code})",
            't_array*' => "VAR_ARRAY({$code})",
            default    => "VAR_INT({$code})",
        };
    }

    // ============================================================
    private function generateCEntry(): string
    {
        $isAndroid = ($this->targetOS === 'android');
        $entryName = $isAndroid ? 'tphp_android_main' : 'main';
        $lines = [
            "/* ── C entry: {$entryName}() ─────────────────────────── */",
            "int {$entryName}(int argc, char* argv[]) {",
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
        if ($isAndroid) {
            // Android NativeActivity 模式：sokol_main 返回后事件循环才启动，
            // 用户闭包可能通过 $this 捕获 Main 对象，不能在此释放。
            // 应用退出时进程直接终止，无需手动清理。
            // _argv 也保持存活（闭包可能通过 $argv 访问）
            $lines[] = $this->ind("/* Android: keep _main/_argv alive — closures capture \$this */");
        } else {
            $lines[] = $this->ind("tp_obj_release(_main);");
            $lines[] = $this->ind("tphp_fn_arr_free(_argv);");
        }
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

        // 兜底：根据推断类型选择转换方式
        //   t_string 是 struct，直接 (t_int) 强转会取指针地址而非解析字符串内容
        $inferredType = $this->inferType($expr);
        if ($inferredType === 't_string') {
            return "tphp_rt_parse_int({$code})";
        }
        // t_var（array<mixed> 元素等）→ VAR_AS_INT 解包
        // 仅对实际为 t_var 的表达式（varTypes/arrElementTypes 标记）应用，避免误伤 mixed 推断的标量
        if ($inferredType === 't_var' && $this->isActualTVarExpr($expr)) {
            return "VAR_AS_INT({$code})";
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
                't_var'    => "VAR_AS_FLOAT({$code})",
                default    => throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to float", $expr->line, $expr->column)
                ),
            };
        }

        // t_var（array<mixed> 元素等）→ VAR_AS_FLOAT 解包
        $inferredType = $this->inferType($expr);
        if ($inferredType === 't_var' && $this->isActualTVarExpr($expr)) {
            return "VAR_AS_FLOAT({$code})";
        }
        if ($inferredType === 't_string') {
            return "tphp_rt_parse_float({$code})";
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
                't_var'    => "VAR_AS_BOOL({$code})",
                default    => 'true', // 对象
            };
        }

        // t_var（array<mixed> 元素等）→ VAR_AS_BOOL 解包
        $inferredType = $this->inferType($expr);
        if ($inferredType === 't_var' && $this->isActualTVarExpr($expr)) {
            return "VAR_AS_BOOL({$code})";
        }
        return "((bool)({$code}))";
    }

    /** 将标量/对象转为单元素数组 */
    private function castToArray(ExprNode $expr): string
    {
        if ($expr instanceof NullLiteralExpr) return 'tphp_fn_arr_create(0)';
        // (array) $stdClass → 提取属性表为关联数组
        $srcType = $this->inferType($expr);
        if (str_contains($srcType, 'tphp_class_stdClass')) {
            $code = $expr->accept($this);
            return "tphp_fn_stdclass_to_array({$code})";
        }
        return 'tphp_fn_arr_from_val(' . $this->wrapVar($expr) . ')';
    }

    /** (object) $expr 转换：数组 → stdClass，其他 → stdClass（空或包装） */
    private function castToObject(ExprNode $expr): string
    {
        // (object) $array → stdClass from array
        $srcType = $this->inferType($expr);
        if ($srcType === 't_array*' || $srcType === 't_var') {
            $code = $expr->accept($this);
            if ($srcType === 't_var') {
                // t_var 持有数组时，先提取 .value._array
                $code = "(({$code}).value._array)";
            }
            return "tphp_fn_stdclass_from_array({$code})";
        }
        // (object) null → 空 stdClass
        if ($expr instanceof NullLiteralExpr) {
            return 'new_stdClass()';
        }
        // 其他标量转 stdClass：PHP 中 (object)42 会创建 stdClass{"scalar": 42}
        //   此场景较少见，简化处理为空 stdClass
        return 'new_stdClass()';
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

        // ── 泛型数组快速路径：t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*/t_arr_ptr* ──
        //   直接调用类型特化的 getter，避免 t_var 解包
        if ($node->array instanceof VariableExpr) {
            $vn = self::varName($node->array->name);
            $arrCType = $this->varTypes[$vn] ?? '';
            $genElemCType = self::genericArrayElemCType($arrCType);
            if ($genElemCType !== null) {
                $arr = $node->array->accept($this);
                $idx = $node->index->accept($this);
                // 从 t_arr_int* 提取 suffix: 'int'
                $suffix = substr($arrCType, 6, -1);  // t_arr_{suffix}*
                // 字符串键 → get_str，整数键 → get
                if ($node->index instanceof StringLiteralExpr) {
                    return "tphp_fn_arr_{$suffix}_get_str({$arr}, {$idx})";
                }
                $idxType = $this->inferType($node->index);
                if ($idxType === 't_string') {
                    return "tphp_fn_arr_{$suffix}_get_str({$arr}, {$idx})";
                }
                return "tphp_fn_arr_{$suffix}_get({$arr}, (t_int)({$idx}))";
            }
        }

        // 字符串字符访问 $str[$i]：变量类型为 t_string 时，用 substr 取单字符
        //   不能走数组 getter（tphp_fn_arr_get_int_str 期望 t_array*）
        if ($node->array instanceof VariableExpr
            && ($this->varTypes[self::varName($node->array->name)] ?? '') === 't_string'
            && !($node->index instanceof StringLiteralExpr)) {
            $arr = $node->array->accept($this);
            $idx = $node->index->accept($this);
            return "tphp_fn_substr({$arr}, (t_int)({$idx}), 1)";
        }

        $arr  = $node->array->accept($this);
        $idx  = $node->index->accept($this);
        $vn   = $node->array instanceof VariableExpr ? self::varName($node->array->name) : '';
        $vt   = $this->varTypes[$vn] ?? 't_int';
        // t_var 数组：元素类型为 t_var（保持 mixed 语义）
        //   - 直接变量 $sub（t_var）→ $sub[0] 元素为 t_var
        //   - 链式 $arr[0][1] 中内层为 t_var → 外层元素也为 t_var
        $isTVarArray = ($vt === 't_var');

        // t_var 变量持有数组：从 .value._array 提取指针
        //   场景：$sub = $mixed_arr[2]; $sub[0] — $sub 是 t_var，内含 TYPE_ARRAY
        //   运行时检查 type 标签；非数组时 getter 返回 0/NULL
        if ($isTVarArray) {
            $arr = "(({$arr}).value._array)";
        }

        // 链式访问：内部表达式可能返回非指针类型（如 t_int/t_var，源于混合数组的类型追踪局限），
        // 但 getter 函数期望 t_array* 参数。
        //   - t_var：从 .value._array 提取数组指针（运行时检查 type 标签），元素也是 t_var
        //   - 其他标量：添加显式 cast 满足 Clang 类型检查，运行时返回 0/NULL
        if ($node->array instanceof ArrayAccessExpr) {
            $innerType = $this->inferArrayAccessInnerType($node->array);
            // 检查内部访问的返回类型：若根数组是 array<mixed>，内层访问返回 t_var（持有数组）
            //   即使 innerType 是类指针（如 tphp_class_Item*），内部访问 $catalog[0] 仍返回 t_var
            //   需提取 .value._array 才能传给 typed getter
            [$rootArr] = $this->resolveRootArray($node->array);
            $rootElemIsTVar = ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var');
            if ($innerType === 't_var') {
                $arr = "(({$arr}).value._array)";
                $isTVarArray = true;
            } elseif ($rootElemIsTVar && str_contains($innerType, 'tphp_class_')) {
                // 嵌套对象数组的叶子层：内部访问返回 t_var，需提取 .value._array
                //   但不用设置 isTVarArray（元素是对象，用 typed object getter）
                $arr = "(({$arr}).value._array)";
            } elseif ($innerType !== 't_array*' && !str_contains($innerType, '*')) {
                $arr = "(t_array*)(intptr_t)({$arr})";
            }
        }

        // 字符串键：per-key 类型 → get_str_int/str；无记录用 get_str_str
        $idxType = $this->inferType($node->index);
        if ($idxType === 't_string' || $node->index instanceof StringLiteralExpr) {
            // per-key 类型追踪
            $keyType = $vt;
            // array<mixed>：直接走 t_var 路径，忽略 per-key 追踪
            //   适用于任意 string key（字面量或变量），保持 mixed 语义一致
            if ($node->array instanceof VariableExpr
                && ($this->arrElementTypes[self::varName($node->array->name)] ?? null) === 't_var') {
                $keyType = 't_var';
            } elseif ($node->array instanceof ArrayAccessExpr) {
                // array<mixed> 根数组：链式访问的所有层级元素统一为 t_var
                //   运行时元素存储为 t_var，typed getter 会返回标量导致 var_dump 等期望 t_var 的调用方报错
                [$rootArr, $depth] = $this->resolveRootArray($node->array);
                if ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var') {
                    $keyType = 't_var';
                } else {
                    // 链式访问 $arr[0]["key"]：优先用 AST 精确追踪，回退到嵌套类型
                    // 优先：通过数组字面量 AST 精确追踪嵌套访问的叶子值类型
                    // （处理混合类型关联数组：["id"=>42, "name"=>"foo"] 的 per-key 类型）
                    $traced = $this->traceNestedAccessType($node);
                    if ($traced !== null) {
                        $keyType = $traced;
                    } elseif ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                        $keyType = $this->arrNestedTypes[$rootArr];
                    }
                }
            }
            if ($node->index instanceof StringLiteralExpr && $node->array instanceof VariableExpr
                && $keyType !== 't_var') {
                $arrName = self::varName($node->array->name);
                $keyStr  = $node->index->value;
                // array<mixed>：直接走 t_var 路径，忽略 per-key 追踪
                //   （per-key 类型可能与 t_var 语义冲突：如 $d["vers"] = 2 后 arrValueTypes 记录 t_int，
                //    但 $d 是 array<mixed>，元素统一为 t_var，避免 wrapVar 误用 VAR_INT 包装 t_int）
                if (($this->arrElementTypes[$arrName] ?? null) === 't_var') {
                    $keyType = 't_var';
                } else {
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
            } elseif ($node->array instanceof VariableExpr
                && !($node->index instanceof StringLiteralExpr)
                && $keyType !== 't_var') {
                // 动态字符串键（如 $a["key" . $i]）：arrValueTypes 无法追踪，但 arrElementTypes
                //   由 visitAssignArrayStmt 在赋值时记录（如 $a["key".$i] = $i → arrElementTypes['a']='t_int'）
                //   优先于 varType（t_array*）以生成正确的 typed getter（get_str_int 而非 get_str_arr）
                $arrName = self::varName($node->array->name);
                if (isset($this->arrElementTypes[$arrName])) {
                    $keyType = $this->arrElementTypes[$arrName];
                    // 标准化类/枚举类型（补 * 指针后缀）
                    if ((str_contains($keyType, 'tphp_class_') || str_contains($keyType, 'tphp_enum_'))
                        && !str_ends_with($keyType, '*')) {
                        $keyType .= '*';
                    }
                }
            } elseif ($node->array instanceof PropertyAccessExpr) {
                // 属性数组字符串键访问：$this->prop["key"] 或 $obj->prop["key"]
                //   查 propArrElementTypes 获取元素类型（与整数键分支 8395 行一致）
                //   未注册时默认 t_string（而非 t_int），因为关联数组通常存字符串值
                $propKey = $this->propArrElemKey($node->array);
                if ($propKey !== null && isset($this->propArrElementTypes[$propKey])) {
                    $keyType = $this->propArrElementTypes[$propKey];
                    if ((str_contains($keyType, 'tphp_class_') || str_contains($keyType, 'tphp_enum_')) && !str_ends_with($keyType, '*')) {
                        $keyType .= '*';
                    }
                } else {
                    $keyType = 't_string';
                }
            }
            return match ($keyType) {
                't_int'   => "tphp_fn_arr_get_str_int({$arr}, {$idx})",
                't_float' => "((t_float)tphp_fn_arr_get_str_int({$arr}, {$idx}))",
                't_bool'  => "(tphp_fn_arr_get_str_int({$arr}, {$idx}) != 0)",
                't_array*' => "tphp_fn_arr_get_str_arr({$arr}, {$idx})",
                't_var'   => "(*tphp_fn_arr_get_str({$arr}, {$idx}))",  // array<mixed> 字符串键 → t_var
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
            } else {
                // 未注册元素类型的 array 属性（如 public array $nums = []）：
                //   回退到 TypeChecker 的 inferredType，若为 mixed 则元素是 t_var
                //   避免 inferType 推导 t_var 但生成 typed getter 导致类型不匹配
                $inferred = $node->inferredType !== null ? $this->inferredTypeToCType($node->inferredType) : null;
                if ($inferred === 't_var') {
                    $et = 't_var';
                } elseif ($inferred === 'void*') {
                    // TypeChecker 推导为 object（IDX_OBJECT → void*），使用 object getter
                    // 避免 defaulted 到 tphp_fn_arr_get_int_int 返回 0（NULL）导致崩溃
                    $et = 'void*';
                }
            }
        }
        // array<mixed> 通过 t_var 提取的数组：元素统一为 t_var，覆盖所有 typed getter 优化
        //   场景：$sub = $mixed_arr[0]; $sub[1]  或  $a[0][1]（$a[0] 是 t_var）
        //   原因：从 t_var.value._array 提取的是万能数组，元素类型为 t_var。
        //   若用 typed getter（返回 t_int/t_string 等），调用方（var_dump 等）期望 t_var 会报错。
        //   例外：t_var 持有对象/回调数组（arrElementTypes 记录类指针/t_callback）时，保留类型，
        //   使 visitArrayAccess 用 typed getter 提取对象/回调
        if ($isTVarArray && !str_contains($et, 'tphp_class_') && !str_contains($et, 'tphp_enum_') && $et !== 't_callback') {
            $et = 't_var';
        }
        return match ($et) {
            't_int'      => "tphp_fn_arr_get_int_int({$arr}, (t_int)({$idx}))",
            't_float'    => "tphp_fn_arr_get_int_float({$arr}, (t_int)({$idx}))",
            't_string'   => "tphp_fn_arr_get_int_str({$arr}, (t_int)({$idx}))",
            't_bool'     => "tphp_fn_arr_get_int_bool({$arr}, (t_int)({$idx}))",
            't_array*'   => "tphp_fn_arr_get_int_arr({$arr}, (t_int)({$idx}))",
            't_callback' => "tphp_fn_arr_get_int_callback({$arr}, (t_int)({$idx}))",
            't_var'      => "(*tphp_fn_arr_get_int({$arr}, (t_int)({$idx})))",  // array<mixed> 整数键 → t_var（key-based 支持稀疏键）
            'void*'      => "tphp_fn_arr_get_int_object({$arr}, (t_int)({$idx}))",  // TypeChecker 推导为 object 但未知具体类
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
            // array<mixed> 元素（t_var）→ 字符串上下文统一用 tphp_fn_strval
            //   但需检查实际生成的访问器返回类型：arrElementTypes/链式访问可能生成
            //   arr_get_int_str（返回 t_string）/ arr_get_int_int（返回 t_int）等类型化访问器，
            //   此时 tphp_fn_strval(t_string/t_int) 会导致编译错误（strval 只接受 t_var）
            if ($this->inferType($expr) === 't_var') {
                // 检查访问器函数名，确定实际返回类型
                //   类型化访问器返回 t_string/t_int/t_float → 用对应的 str 转换
                //   t_var 访问器（arr_get_str / arr_index）→ 用 tphp_fn_strval
                if (str_contains($code, '_get_str_str(') || str_contains($code, '_get_int_str(')
                    || str_contains($code, '_str_get_str(') || str_contains($code, '_str_get(')) {
                    return $code;  // t_string，无需转换
                }
                if (str_contains($code, '_get_str_int(') || str_contains($code, '_get_int_int(')
                    || str_contains($code, '_int_get_str(') || str_contains($code, '_int_get(')) {
                    return "tphp_rt_str_from_int({$code})";  // t_int → str
                }
                if (str_contains($code, '_get_str_float(') || str_contains($code, '_get_int_float(')
                    || str_contains($code, '_float_get_str(') || str_contains($code, '_float_get(')) {
                    return "tphp_rt_str_from_float({$code})";  // t_float → str
                }
                if (str_contains($code, '_get_str_arr(') || str_contains($code, '_get_int_arr(')) {
                    $fixed = str_replace(['_get_str_arr', '_get_int_arr'], '_get_str_str', $code);
                    return $fixed;  // t_array* → 改用 str 访问器
                }
                // t_var 元素：*tphp_fn_arr_get_str(...) 或 *tphp_fn_arr_index(...)
                return "tphp_fn_strval({$code})";
            }
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
                't_string'  => $code,
                't_float'   => "tphp_rt_str_from_float({$code})",
                't_array*'  => "tphp_rt_str_from_int((t_int)(intptr_t)({$code}))",
                't_var'     => "tphp_fn_strval({$code})",  // array<mixed> 元素 → t_var → str
                default     => "tphp_rt_str_from_int({$code})",
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
                't_var'      => "tphp_fn_strval({$code})",
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
            if ($pt === 't_var') return "tphp_fn_strval({$code})";
        }

        // CallExpr：查找返回类型（内置函数 + 方法调用 + 枚举方法）
        if ($expr instanceof CallExpr) {
            // 内置函数返回 t_string/t_float/t_var（如 array_sum 返回 t_var）
            if ($expr->callee === null) {
                $rt = $this->inferCallReturnType($expr);
                if ($rt === 't_string') return $code;
                if ($rt === 't_float') return "tphp_rt_str_from_float({$code})";
                // t_var (mixed): array_sum/array_product/array_pop 等 → 用 tphp_fn_strval 解包为字符串
                if ($rt === 't_var') return "tphp_fn_strval({$code})";
            }
            // 枚举方法调用（静态 Color::cases() 或实例 Color::Red->label()）
            $enumCName = null;
            if ($expr->callee instanceof EnumAccessExpr) {
                $enumCName = $this->symbols->getEnumCName($expr->callee->enumName);
            } elseif ($expr->callee instanceof VariableExpr) {
                $enumCName = $this->symbols->resolveEnumCName($expr->callee->name);
                // 变量持有枚举实例：$coalesce->label() — 查 varTypes 看是否为枚举 C 类型
                //   $coalesce = Color::tryFrom(99) ?? Color::GREEN; 之后 $coalesce->label()
                if ($enumCName === null && str_starts_with($expr->callee->name, '$')) {
                    $vn = self::varName($expr->callee->name);
                    $vt = $this->varTypes[$vn] ?? '';
                    if ($vt !== '') {
                        $enumCName = $this->symbols->resolveEnumCName(rtrim($vt, '*'));
                    }
                }
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

        // NullCoalesceExpr → 查 inferType 决定如何转字符串
        //   $x ?? "default" 的公共类型由 TypeChecker 推导，避免误用 str_from_int
        if ($expr instanceof NullCoalesceExpr) {
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

        // TPHP_CONST_ 常量引用：根据 SymbolTable 注册的类型决定如何转字符串
        //   场景：self::ITER (const int = 10000) → tphp_rt_str_from_int(TPHP_CONST_...)
        //         PHP_EOL (STR_LIT(...)) → 直接返回
        if (str_starts_with($code, 'TPHP_CONST_')) {
            $ct = $this->symbols->getConstType($code)
                ?? $this->symbols->getConstType(strtoupper($code));
            // 未注册的全局常量（PHP_EOL/PHP_OS 等）默认为 STR_LIT 宏 → t_string
            if ($ct === null) return $code;
            return match ($ct) {
                't_string' => $code,
                't_float'  => "tphp_rt_str_from_float({$code})",
                't_bool'   => "({$code} ? STR_LIT(\"1\") : STR_LIT(\"\"))",
                default    => "tphp_rt_str_from_int({$code})",
            };
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
        // PropertyAccessExpr：用 getPropType 查属性类型（含 $this->prop / $obj->prop）
        //   优先于 TypeChecker 的 inferredType（可能误标为 mixed）
        if ($expr instanceof PropertyAccessExpr) {
            $propType = $this->getPropType($expr);
            if ($propType === '') $propType = 't_int';
            if ($propType === 't_var') return $code;
            // 对象类型 → VAR_OBJ
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
                'null'       => "VAR_NULL()",
                default      => "VAR_INT({$code})",
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
        // ArrayAccessExpr 特殊处理：TypeChecker 标记 inferredType 为 mixed（因数组是 array<mixed>），
        //   但 visitArrayAccess 可能基于 arrElementTypes 生成 typed getter（返回 t_array*/t_int 等）。
        //   此时需按实际 getter 返回类型包装，否则 t_var 变量接收 typed 值会类型不匹配。
        //   优先级：实际元素类型追踪 > TypeChecker 的 mixed 标记
        if ($expr instanceof ArrayAccessExpr && $type === 't_var') {
            $actualType = $this->inferArrayAccessActualType($expr);
            if ($actualType !== null && $actualType !== 't_var') {
                $type = $actualType;
            }
        }
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

    /**
     * 推导 ArrayAccessExpr 的实际 C 返回类型（基于 arrElementTypes 追踪）。
     * 用于 wrapTvarAssign：当 TypeChecker 标记 inferredType 为 mixed（array<mixed>），
     *   但 visitArrayAccess 基于 arrElementTypes 生成了 typed getter（返回 t_array* 等），
     *   需要返回实际 getter 的返回类型以便正确包装为 t_var。
     */
    private function inferArrayAccessActualType(ArrayAccessExpr $expr): ?string
    {
        // 字符串键：查 arrValueTypes / arrElementTypes
        if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
            $arrName = self::varName($expr->array->name);
            // array<mixed>（t_var 变量持有数组）：元素统一为 t_var
            //   arrElementTypes 可能记录实际值类型（如 t_array*），但 t_var 数组
            //   的元素在 C 层都是 t_var（运行时通过 VAR_ARRAY 包装）
            if (($this->varTypes[$arrName] ?? '') === 't_var'
                || ($this->arrElementTypes[$arrName] ?? null) === 't_var') {
                return 't_var';
            }
            $keyStr  = $expr->index->value;
            if (isset($this->arrValueTypes[$arrName][$keyStr])) {
                return $this->arrValueTypes[$arrName][$keyStr];
            }
            return $this->arrElementTypes[$arrName] ?? null;
        }
        // 整数键：查 arrElementTypes
        if ($expr->array instanceof VariableExpr) {
            $arrName = self::varName($expr->array->name);
            // t_var 变量持有数组：元素统一为 t_var（与 inferArrayAccessInnerType 一致）
            if (($this->varTypes[$arrName] ?? '') === 't_var') {
                return 't_var';
            }
            return $this->arrElementTypes[$arrName] ?? null;
        }
        // 链式访问 $arr[0][1]：用 inferArrayAccessInnerType 推导（含 array<mixed> 根数组检测）
        if ($expr->array instanceof ArrayAccessExpr) {
            return $this->inferArrayAccessInnerType($expr->array);
        }
        return null;
    }

    /**
     * 推导链式访问中内层 ArrayAccessExpr 的实际 C 返回类型。
     *
     * 与 inferType 的区别：优先查 arrElementTypes/arrNestedTypes（CodeGenerator 的
     * 精确追踪），而非 AST inferredType 字段（TypeChecker 的静态推导）。
     *
     * 场景：$b = []; $b[] = [1,2,3]; $b[0][0]
     *   - TypeChecker 标记 $b[0] 的 inferredType 为 mixed（因 $b 是 array<mixed>）
     *   - 但 CodeGenerator 的 arrElementTypes['b'] = 't_array*'（push 追踪）
     *   - 链式访问 $b[0][0] 需知道 $b[0] 返回 t_array* 才能正确生成 get_int_int
     */
    private function inferArrayAccessInnerType(ArrayAccessExpr $expr): string
    {
        // 优先：t_var 变量持有数组 → 元素统一为 t_var（运行时通过 VAR_ARRAY 包装）
        //   arrElementTypes 可能记录实际值类型（如 t_array*），但 t_var 数组的
        //   元素在 C 层都是 t_var，必须返回 t_var 才能正确生成 .value._array 提取
        if ($expr->array instanceof VariableExpr) {
            $arrName = self::varName($expr->array->name);
            if (($this->varTypes[$arrName] ?? '') === 't_var') {
                return 't_var';
            }
            if (isset($this->arrElementTypes[$arrName])) {
                $et = $this->arrElementTypes[$arrName];
                // array<mixed> 根数组（arrElementTypes='t_var'）但嵌套对象数组的叶子层：
                //   $catalog = [[$i1, $i2], [$i3]]; $catalog[0][0]->title
                //   $catalog[0] 的元素是 Item 对象，返回 tphp_class_Item* 使 visitArrayAccess
                //   用 typed object getter 提取对象指针
                if ($et === 't_var'
                    && isset($this->arrNestedTypes[$arrName])
                    && str_contains($this->arrNestedTypes[$arrName], 'tphp_class_')
                    && isset($this->arrNestedDepth[$arrName])) {
                    // $expr 深度：$catalog[0] depth=1, $catalog[0][1] depth=2
                    [, $exprDepth] = $this->resolveRootArray($expr);
                    $nd = $this->arrNestedDepth[$arrName];
                    // 元素深度 = exprDepth + 1，叶子层判断：exprDepth + 1 >= nd['depth']
                    if ($exprDepth + 1 >= $nd['depth']) {
                        $leafType = $this->arrNestedTypes[$arrName];
                        if (!str_ends_with($leafType, '*')) $leafType .= '*';
                        return $leafType;
                    }
                    // 中间层：返回 t_var（运行时持有子数组）
                    return 't_var';
                }
                return $et;
            }
        }
        // 链式访问 $arr[0][1]：优先用 traceNestedAccessType 精确追踪叶子类型
        //   TypeChecker 的 inferredType 字段对 array<mixed> 元素统一标记为 mixed，
        //   但实际叶子可能是 t_array*（中间层）或 t_int（叶子层），
        //   需从数组字面量 AST 精确推导，否则会误加 .value._array
        if ($expr->array instanceof ArrayAccessExpr) {
            // array<mixed> 根数组：所有层级元素统一为 t_var（运行时持有数组/标量）
            //   中间层虽含数组，但 C 代码生成走 *tphp_fn_arr_index 路径返回 t_var，
            //   不能返回 t_array*（否则 visitArrayAccess 不会提取 .value._array）
            //   例外：若 arrNestedTypes 记录了类对象叶子类型（如 [[$obj1],[$obj2]]），
            //   返回类指针类型，使 visitArrayAccess 用 typed object getter 提取对象
            [$rootArr, $depth] = $this->resolveRootArray($expr->array);
            if ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var') {
                // 检查是否为嵌套对象数组的叶子层
                if (isset($this->arrNestedTypes[$rootArr])
                    && str_contains($this->arrNestedTypes[$rootArr], 'tphp_class_')) {
                    $nd = $this->arrNestedDepth[$rootArr] ?? null;
                    // depth 是 $expr->array 的深度（$arr[0] depth=1, $arr[0][1] depth=2）
                    // 调用方传入的 $expr 是 $arr[0][1]，$expr->array 是 $arr[0]（depth=1）
                    // 叶子层判断：depth >= nd['depth'] - 1
                    if ($nd !== null && $depth >= $nd['depth'] - 1) {
                        $leafType = $this->arrNestedTypes[$rootArr];
                        if (!str_ends_with($leafType, '*')) $leafType .= '*';
                        return $leafType;
                    }
                }
                return 't_var';
            }
            $traced = $this->traceNestedAccessType($expr);
            if ($traced !== null) return $traced;
            // 回退：arrNestedDepth 判断中间层/叶子层
            if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedDepth[$rootArr])) {
                $nd = $this->arrNestedDepth[$rootArr];
                if ($depth >= $nd['depth'] - 1) {
                    return $nd['leafType'];
                }
                return 't_array*';  // 中间层仍是数组
            }
        }
        // 回退：inferType（含 TypeChecker inferredType + 其他追踪逻辑）
        return $this->inferType($expr);
    }

    /**
     * 生成数组函数参数的 C 代码，统一输出 t_array*。
     *
     * 三种来源的处理：
     *   1. t_var 变量（array<mixed> 元素）：从 .value._array 提取 t_array*
     *   2. 显式 array<T> 变量（t_arr_int, t_arr_str, t_arr_float, t_arr_bool 指针）：
     *      调用 tphp_fn_arr_{T}_to_var() 协变转换为 t_array*（O(n) 重新包装元素为 t_var）
     *   3. t_array* 变量：直接使用，无需转换
     *
     * 设计权衡：内置数组函数（count/array_push/array_map/...）统一接收 t_array*，
     *   避免为每种 T 特化函数。转换仅在函数调用边界发生，热循环内的直接访问
     *   （$arr[$i]）仍走特化快路径，不受影响。
     *
     * @param ExprNode $expr 参数表达式
     * @param string   $code 参数表达式的 C 代码（$expr->accept($this)）
     * @return string 转换后的 C 代码（输出 t_array*）
     */
    private function arrayArgCode(ExprNode $expr, string $code): string
    {
        // t_var 变量：提取 .value._array
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? '';
            if ($vt === 't_var') {
                return "(({$code}).value._array)";
            }
            // 显式 array<T>（t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*）：
            //   自动协变转换为 array<mixed>（t_array*），调用对应 tphp_fn_arr_{suffix}_to_var()。
            //   O(n) 重新分配并包装元素为 t_var，使内置数组函数（count/array_push/...）
            //   能统一接收 t_array* 参数，无需为每种 T 特化函数。
            if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $vt, $m)) {
                return "tphp_fn_arr_{$m[1]}_to_var({$code})";
            }
            // t_arr_ptr*（array<array<T>>/array<Foo>）：元素为 void*，无法安全包装为 t_var。
            //   需用户显式声明为 array<mixed> 或手动转换。
        }
        // ArrayAccessExpr：若返回 t_var（array<mixed> 元素持有数组），提取 .value._array
        //   场景：count($b[0]) 其中 $b 是 array<mixed>，$b[0] 返回 t_var（持有 TYPE_ARRAY）
        //   优先用 inferArrayAccessActualType（CodeGenerator 精确追踪），避免 TypeChecker
        //   inferredType=mixed 误判 typed getter（返回 t_array*）的返回类型
        //   例外：per-key 类型追踪已知为标量（string/int/etc.）时按标量类型解包，
        //   避免 explode(",", $result["headers"]) 误将 string 当 array 提取
        if ($expr instanceof ArrayAccessExpr) {
            $t = $this->inferArrayAccessActualType($expr) ?? $this->inferType($expr);
            if ($t === 't_var') {
                // 检查 per-key 类型：已知标量类型时按标量解包
                if ($expr->array instanceof VariableExpr
                    && $expr->index instanceof StringLiteralExpr) {
                    $arrName = self::varName($expr->array->name);
                    $keyStr  = $expr->index->value;
                    $keyType = $this->arrValueTypes[$arrName][$keyStr] ?? null;
                    if ($keyType !== null && $keyType !== 't_array*' && $keyType !== 't_var') {
                        return match ($keyType) {
                            't_string' => "(({$code}).value._string)",
                            't_int'    => "VAR_AS_INT({$code})",
                            't_float'  => "VAR_AS_FLOAT({$code})",
                            't_bool'   => "VAR_AS_BOOL({$code})",
                            default    => $code,
                        };
                    }
                }
                return "(({$code}).value._array)";
            }
        }
        return $code;
    }

    /**
     * 检测表达式是否为显式 array<T> 变量（t_arr_int/t_arr_str/t_arr_float/t_arr_bool 指针）。
     *
     * 用于原地修改函数（array_push/pop/shift/unshift）拒绝自动转换：
     *   - to_var() 创建新数组，修改不会反映回原变量，语义错误
     *   - 应提示用户使用 $arr[] = $val 或显式声明 array<mixed>
     */
    private function isGenericArrayVar(ExprNode $expr): bool
    {
        if (!$expr instanceof VariableExpr) return false;
        $vn = self::varName($expr->name);
        $vt = $this->varTypes[$vn] ?? '';
        return (bool)preg_match('/^t_arr_(int|str|float|bool|ptr)\*$/', $vt);
    }

    /** 读取 t_var 变量的值，按预期类型提取 */
    private function readVar(string $var, string $expectType): string
    {
        return match ($expectType) {
            't_int'    => "VAR_AS_INT({$var})",
            't_float'  => "VAR_AS_FLOAT({$var})",
            't_string' => "VAR_AS_STRING({$var})",
            't_bool'   => "VAR_AS_BOOL({$var})",
            't_array*' => "(({$var}).value._array)",
            default    => $var, // fallback: raw t_var
        };
    }

    /**
     * 解包 phpc 互操作函数参数中的 t_var 值。
     *
     * phpc 函数如 php_int(v)/c_int(v) 展开为 ((t_int)(v))，对 struct t_var 编译失败。
     * 本方法检测 t_var 参数并按函数期望类型解包：
     *   - php_int/c_int/phpc_ptr_to_int → VAR_AS_INT(v)
     *   - php_str/c_str/php_str_clone/php_str_ptr/cstr_to_string → VAR_AS_STRING(v)
     *
     * @param string  $shortN 函数短名（如 'php_int'）
     * @param CallExpr $node   调用节点（用于检查参数 AST）
     * @param array   $args   已生成的参数 C 代码数组
     * @return array 解包后的参数 C 代码数组
     */
    private function unwrapPhpcArgs(string $shortN, CallExpr $node, array $args): array
    {
        $intFns = ['php_int', 'c_int', 'phpc_ptr_to_int'];
        $strFns = ['php_str', 'c_str', 'php_str_clone', 'php_str_ptr', 'cstr_to_string'];
        $expectType = '';
        if (in_array($shortN, $intFns, true)) {
            $expectType = 't_int';
        } elseif (in_array($shortN, $strFns, true)) {
            $expectType = 't_string';
        }
        if ($expectType === '') {
            return $args;
        }
        foreach ($args as $i => $code) {
            if (!isset($node->args[$i])) continue;
            $argExpr = $node->args[$i];
            // 跳过非首参数（这些函数只对第一参数解包）
            if ($i > 0) continue;
            $args[$i] = $this->unwrapIfMixed($argExpr, $code, $expectType);
        }
        return $args;
    }

    /** 如果表达式是 t_var 变量，按期望类型解包 */
    private function unwrapIfMixed(ExprNode $expr, string $code, string $expectType): string
    {
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                return $this->readVar($vn, $expectType);
            }
            return $code;
        }
        // array<mixed> 元素访问（$arr[$i]）：arrElementTypes 标记为 t_var 时才解包
        // 避免误伤 TypeChecker 推断为 mixed 但实际为 t_int/t_string 的变量
        if ($this->isActualTVarExpr($expr)) {
            return match ($expectType) {
                't_int'    => "VAR_AS_INT({$code})",
                't_float'  => "VAR_AS_FLOAT({$code})",
                't_string' => "VAR_AS_STRING({$code})",
                't_bool'   => "VAR_AS_BOOL({$code})",
                't_array*' => "(({$code}).value._array)",
                default    => $code,
            };
        }
        return $code;
    }

    /**
     * 判断表达式生成的 C 代码是否实际为 t_var 类型。
     *
     * 用于区分 TypeChecker 推断为 mixed（可能实际是 t_int/t_string）
     * 与运行时真正为 t_var 的表达式（如 array<mixed> 元素访问、t_var 变量）。
     */
    private function isActualTVarExpr(ExprNode $expr): bool
    {
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            return ($this->varTypes[$vn] ?? '') === 't_var';
        }
        if ($expr instanceof ArrayAccessExpr) {
            // array<mixed> 元素访问：arrElementTypes 标记为 t_var
            if ($expr->array instanceof VariableExpr) {
                $arrName = self::varName($expr->array->name);
                if (($this->arrElementTypes[$arrName] ?? null) === 't_var') {
                    return true;
                }
                // t_var 变量持有数组（$sub = $arr[0]; $sub[1]）：
                //   $sub 是 t_var，其 .value._array 是万能数组，元素也是 t_var
                if (($this->varTypes[$arrName] ?? '') === 't_var') {
                    return true;
                }
                // 万能数组（t_array*）且 arrElementTypes 未追踪但 inferredType 为 mixed：
                //   visitArrayAccess 对整数键生成 typed getter（tphp_fn_arr_get_int_int 返回 t_int）
                //   字符串键默认用 typed getter（tphp_fn_arr_get_str_str 返回 t_string），不是 t_var
                //   因此不视为 t_var（保持原始 t_int 默认行为）
                //   场景：$r = array_reverse($a); $r[0] — r 是 t_array*，inferredType=mixed，元素按 t_int 处理
            }
            // 链式访问 $arr[0][1]：若内层为 t_var，外层也是 t_var
            if ($expr->array instanceof ArrayAccessExpr) {
                $innerType = $this->inferArrayAccessInnerType($expr->array);
                if ($innerType === 't_var') {
                    return true;
                }
            }
            // 实例属性数组访问：$obj->prop[$key] 或 $this->prop[$key]
            //   propArrElementTypes 标记为 t_var，或未注册但 inferredType 为 mixed 时，元素为 t_var
            //   场景：public array $nums = []; $v = $w4->nums[1];
            //   visitArrayAccess 对未注册的 array 属性且 inferredType=mixed 时生成 t_var getter
            if ($expr->array instanceof PropertyAccessExpr) {
                $key = $this->propArrElemKey($expr->array);
                if ($key !== null) {
                    $et = $this->propArrElementTypes[$key] ?? null;
                    if ($et === 't_var') {
                        return true;
                    }
                    if ($et === null && $expr->inferredType !== null
                        && $this->inferredTypeToCType($expr->inferredType) === 't_var') {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** 查询方法第 $idx 个参数的 C 类型 */
    private function getMethodParamType(CallExpr $call, int $idx): string
    {
        // 独立函数调用：查 SymbolTable 的函数参数类型
        if ($call->callee === null) {
            $fnName = self::mangleCName($call->name);
            $fnInfo = $this->symbols->getFunc('tphp_fn_' . $fnName);
            if ($fnInfo === null) {
                $fnInfo = $this->symbols->getFunc('tphp_fn_' . $call->name);
            }
            if ($fnInfo !== null) {
                return $fnInfo->paramTypes[$idx] ?? '';
            }
            // 回退：内置函数参数类型映射（dirname/basename 等未注册到 SymbolTable 的内置函数）
            if (isset(self::$builtinFnParamCtypes[$call->name][$idx])) {
                return self::$builtinFnParamCtypes[$call->name][$idx];
            }
            return '';
        }
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
            // 继承方法：本类无定义时上溯父类链查找参数类型
            if ($mInfo === null) {
                $parentCN = $this->resolveMethodClass($cn, $call->name);
                if ($parentCN !== '') {
                    $mInfo = $this->symbols->getClassMethod($parentCN, $call->name);
                }
            }
            if ($mInfo !== null) {
                return $mInfo->paramTypes[$idx] ?? '';
            }
        }
        return '';
    }

    /**
     * 将 array<T> 中的元素类型字符串解析为 Type 对象。
     * 用于 mapType 中 array<T> 的 C 类型推导。
     *
     * 支持的元素类型：
     *   - 基本类型: int/string/float/bool → 对应 Type 静态实例
     *   - mixed     → Type::$mixed（array<mixed> 等价于万能数组）
     *   - 嵌套数组: array<T> → 递归解析（C 类型为 t_arr_ptr）
     *   - 其他（对象/C 类型等）→ Type::$object（C 类型为 t_arr_ptr）
     */
    private function resolveArrayElemType(string $elemStr): Type
    {
        $elemStr = trim($elemStr);
        // 基本类型
        return match ($elemStr) {
            'int'        => Type::$int,
            'string'     => Type::$string,
            'float'      => Type::$float,
            'bool'       => Type::$bool,
            'mixed', ''  => Type::$mixed,
            'null'       => Type::$null,
            // 嵌套数组 / 对象 / C 类型 / 未知类型 → 用 t_arr_ptr 承载（flags 非 0）
            //   嵌套 array<array<T>> 递归解析元素类型，但 C 层统一用 t_arr_ptr
            default      => Type::pointer(Type::$object),
        };
    }

    public function mapType(string $t): string {
        if ($t === 'self') return $this->className . '*';
        if ($t === 'mixed') return 't_var';
        if ($t === 'callable') return 't_callback';
        // 泛型数组 array<T> → 返回对应的泛型 C 类型
        //   array<int>    → t_arr_int*
        //   array<string> → t_arr_str*
        //   array<float>  → t_arr_float*
        //   array<bool>   → t_arr_bool*
        //   array<mixed>  → t_array*（等价于 t_arr_var*，兼容现有代码）
        //   array<array<T>> / array<Foo> → t_arr_ptr*
        if (str_starts_with($t, 'array<') && str_ends_with($t, '>')) {
            $elemStr = substr($t, 6, -1);
            // array<mixed> → t_array*（兼容现有代码，t_arr_var 是 t_array 的别名）
            if ($elemStr === 'mixed') {
                return 't_array*';
            }
            $elemType = $this->resolveArrayElemType($elemStr);
            return Type::arrayCType($elemType) . '*';
        }
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
    /** C 保留关键字集合 — 与 C 标识符冲突的 PHP 变量名需加 _ 前缀避免编译错误
     *  （C89 + C99 + C11 + C23 常用关键字，含 tcc 扩展） */
    private static array $cKeywords = [
        'auto','break','case','char','const','continue','default','do','double',
        'else','enum','extern','float','for','goto','if','inline','int','long',
        'register','restrict','return','short','signed','sizeof','static','struct',
        'switch','typedef','union','unsigned','void','volatile','while',
        '_Bool','_Complex','_Imaginary','_Alignas','_Alignof','_Atomic','_Generic',
        '_Noreturn','_Static_assert','_Thread_local',
        // tcc 内置/常见宏
        'asm','__asm','__attribute__','__inline','__inline__','__restrict',
    ];

    public static function varName(string $v): string {
        if ($v === '$this') return 'self';
        $n = ltrim($v, '$');
        // C 关键字转义：避免 PHP 变量名 $default / $case / $register 等生成非法 C 标识符
        if (in_array($n, self::$cKeywords, true)) {
            return '_' . $n;
        }
        return $n;
    }

    /** 解析类型到 C 类型（参数类型用；联合类型 | → t_var） */
    private static function resolveType(string $type): string {
        if (str_contains($type, '|')) return 't_var';
        if ($type === 'callable') return 't_callback';
        // Task 5.4: array<T> 泛型数组 — C 类型为 t_array*
        if (str_starts_with($type, 'array<') && str_ends_with($type, '>')) {
            return 't_array*';
        }
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
        if (isset(self::$typeMap[$type])) {
            return self::$typeMap[$type];
        }
        // 命名空间类（含 \）：用 classRefName 生成正确的 C 标识符
        //   UI\Widget → tphp_na_UI_tphp_class_Widget*（而非 tphp_class_UI\Widget*）
        if (str_contains($type, '\\')) {
            return self::classRefName($type) . '*';
        }
        return 'tphp_class_' . $type . '*';
    }

    /** 生成参数声明的 C 类型 + 变量名（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramDecl(ParamNode $p): string {
        // 可变参数：C 类型固定为 t_array*（编译期打包所有剩余实参）
        if ($p->isVariadic) {
            return 't_array* ' . self::varName($p->name);
        }
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
        // 可变参数：C 类型固定为 t_array*（与 paramDecl/visitParam 一致）
        if ($p->isVariadic) {
            return 't_array*';
        }
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
        // 泛型数组 t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*/t_arr_ptr* — 不是 byRef
        if (str_starts_with($type, 't_arr_') && str_ends_with($type, '*')) return false;
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

    /** 判断 childCName 是否是 parentCName 的子类（含多层继承） */
    private function isSubclassOf(string $childCName, string $parentCName): bool
    {
        $cur = $childCName;
        while ($cur !== '' && $this->symbols->hasClass($cur)) {
            $parent = $this->symbols->getClassParent($cur);
            if ($parent === '') break;
            if ($parent === $parentCName) return true;
            $cur = $parent;
        }
        return false;
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
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
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
            // C 函数签名声明不参与 #[Export] 导出
            if ($fn->isCDeclaration) continue;
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
