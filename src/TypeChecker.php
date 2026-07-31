<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// TypeChecker — 独立类型检查器
//   参考 vlang 的 Checker 阶段设计：
//   - 在 Parser 之后、CodeGenerator 之前运行
//   - 遍历 AST 填充所有 ExprNode 的 inferredType 字段
//   - 持有 expected_type 状态，驱动空数组/字面量推导
//   - 持有作用域栈（变量名 → Type）
//   - 持有 namespace 上下文和 use 导入表
// ═══════════════════════════════════════════════════════════════

class TypeChecker implements ASTVisitor
{
    /** @var SymbolTable 符号表（与 CodeGenerator 共享） */
    private SymbolTable $symbols;

    /** @var Type|null 期望类型（自顶向下推导，如赋值右式、函数参数、return 表达式） */
    private ?Type $expectedType = null;

    /** @var array<string,Type> 当前作用域变量名（不含 $）→ Type */
    private array $scope = [];

    /** @var array<int,array<string,Type>> 作用域栈（用于嵌套块作用域） */
    private array $scopeStack = [];

    /**
     * @var array<string,string> 变量名（不含 $）→ 类 C 名（如 tphp_class_File）
     *
     * 用于实例方法调用类型推导：TypeChecker 将所有 new X() 表达式标记为
     * Type::$object（避免用户 Type idx 冲突），但 $obj->method() 需要知道
     * 具体类才能查方法返回类型。此表在 checkAssignStmt 中由 new X() 右式填充，
     * 在 inferMethodCallReturnType 中查询。
     */
    private array $varClassCNames = [];

    /**
     * @var array<string,ClosureExpr> 变量名（不含 $）→ ClosureExpr 节点
     *
     * 用于闭包调用返回类型推导：$cb = function(): int {...}; 之后 $cb() 调用
     * 需要知道闭包的返回类型。CodeGenerator 在 visitAssignStmt 中注册
     * addVarClosure（闭包名 → 签名），但那发生在 TypeChecker 之后。
     * 此表在 checkAssignStmt 中由 ClosureExpr 右式填充，在
     * inferMethodCallReturnType 中查询，直接从 AST returnType 字段取值。
     */
    private array $varClosureExprs = [];

    /** @var string 当前命名空间 */
    private string $currentNamespace = '';

    /** @var array<string,string> use 导入表：短名 => FQCN */
    private array $useImports = [];

    /** @var array<string,ClassNode> trait 定义：trait 名（短名/FQ 名）=> ClassNode */
    private array $traitDefs = [];

    /** @var string|null 当前类的 C 名（如 tphp_class_Foo），null=不在类内 */
    private ?string $currentClassCName = null;

    /** @var bool 当前是否在静态上下文（static 方法内） */
    private bool $inStaticContext = false;

    /** @var array<string,string> 内置函数返回类型表（函数名 => C 类型字符串/PHP 类型简写） */
    private static array $builtinRetTypes = [
        'echo' => 'void', 'print' => 'int', 'strlen' => 'int', 'count' => 'int',
        'sizeof' => 'int', 'array_push' => 'int', 'array_pop' => 'mixed',
        'array_shift' => 'mixed', 'array_keys' => 'array', 'array_values' => 'array',
        'array_merge' => 'array', 'array_map' => 'array', 'array_filter' => 'array',
        'array_reduce' => 'mixed', 'array_slice' => 'array', 'array_reverse' => 'array',
        'array_flip' => 'array', 'array_unique' => 'array', 'array_combine' => 'array',
        'array_fill' => 'array', 'array_column' => 'array', 'in_array' => 'bool',
        'array_key_exists' => 'bool', 'array_search' => 'mixed', 'is_int' => 'bool',
        'is_string' => 'bool', 'is_array' => 'bool', 'is_bool' => 'bool',
        'is_float' => 'bool', 'is_null' => 'bool', 'is_object' => 'bool',
        'is_callable' => 'bool', 'is_resource' => 'bool', 'is_numeric' => 'bool',
        'is_scalar' => 'bool', 'is_iterable' => 'bool', 'is_countable' => 'bool',
        'isset' => 'bool', 'empty' => 'bool', 'gettype' => 'string',
        'get_class' => 'string', 'get_parent_class' => 'string', 'intval' => 'int',
        'floatval' => 'float', 'doubleval' => 'float', 'strval' => 'string',
        'boolval' => 'bool', 'settype' => 'bool', 'sprintf' => 'string',
        'printf' => 'int', 'vsprintf' => 'string', 'vprintf' => 'int',
        'fprintf' => 'int', 'number_format' => 'string', 'str_repeat' => 'string',
        'str_replace' => 'string', 'strtolower' => 'string', 'strtoupper' => 'string',
        'ucfirst' => 'string', 'lcfirst' => 'string', 'ucwords' => 'string',
        'trim' => 'string', 'ltrim' => 'string', 'rtrim' => 'string',
        'substr' => 'string', 'strpos' => 'int', 'stripos' => 'int',
        'strrpos' => 'int', 'str_split' => 'array', 'explode' => 'array',
        'implode' => 'string', 'join' => 'string', 'preg_match' => 'array',
        'preg_match_all' => 'array', 'preg_replace' => 'string', 'preg_split' => 'array',
        'date' => 'string', 'time' => 'int', 'microtime' => 'float',
        'mktime' => 'int', 'strtotime' => 'int', 'abs' => 'int',
        'max' => 'mixed', 'min' => 'mixed', 'round' => 'float',
        'floor' => 'float', 'ceil' => 'float', 'pow' => 'mixed',
        'sqrt' => 'float', 'log' => 'float', 'exp' => 'float',
        'sin' => 'float', 'cos' => 'float', 'tan' => 'float',
        'pi' => 'float', 'mt_rand' => 'int', 'rand' => 'int',
        'random_int' => 'int', 'getenv' => 'string', 'putenv' => 'bool',
        'define' => 'bool', 'defined' => 'bool', 'constant' => 'mixed',
        'function_exists' => 'bool', 'class_exists' => 'bool',
        'interface_exists' => 'bool', 'method_exists' => 'bool',
        'property_exists' => 'bool', 'func_get_args' => 'array',
        'func_num_args' => 'int', 'func_get_arg' => 'mixed',
        'var_dump' => 'void', 'print_r' => 'mixed',
        'serialize' => 'string', 'unserialize' => 'mixed',
        'json_encode' => 'string', 'json_decode' => 'mixed',
        'utf8_encode' => 'string', 'utf8_decode' => 'string',
        'mb_strlen' => 'int', 'mb_substr' => 'string', 'mb_strtolower' => 'string',
        'mb_strtoupper' => 'string', 'mb_convert_encoding' => 'string',
        'mb_internal_encoding' => 'bool', 'chr' => 'string', 'ord' => 'int',
        'hex2bin' => 'string', 'bin2hex' => 'string', 'base64_encode' => 'string',
        'base64_decode' => 'string', 'dechex' => 'string', 'hexdec' => 'int',
        'decoct' => 'string', 'octdec' => 'int', 'decbin' => 'string',
        'bindec' => 'int', 'ip2long' => 'int', 'long2ip' => 'string',
        'uniqid' => 'string', 'md5' => 'string', 'sha1' => 'string',
        'hash' => 'string', 'hash_hmac' => 'string', 'crc32' => 'int',
        'unlink' => 'bool', 'file_exists' => 'bool', 'is_file' => 'bool',
        'is_dir' => 'bool', 'is_readable' => 'bool', 'is_writable' => 'bool',
        'filesize' => 'int', 'filemtime' => 'int', 'file_get_contents' => 'string',
        'file_put_contents' => 'int', 'fopen' => 'resource', 'fclose' => 'bool',
        'fread' => 'string', 'fwrite' => 'int', 'fputs' => 'int',
        'fgets' => 'string', 'fgetc' => 'string', 'feof' => 'bool',
        'ftell' => 'int', 'fseek' => 'int', 'rewind' => 'bool',
        'ftruncate' => 'bool', 'fflush' => 'bool', 'fpassthru' => 'int',
        'readfile' => 'int', 'tempnam' => 'string', 'tmpfile' => 'resource',
        'mkdir' => 'bool', 'rmdir' => 'bool', 'rename' => 'bool',
        'copy' => 'bool', 'chmod' => 'bool', 'chown' => 'bool',
        'chgrp' => 'bool', 'touch' => 'bool', 'realpath' => 'string',
        'dirname' => 'string', 'basename' => 'string', 'pathinfo' => 'array',
        'glob' => 'array', 'scandir' => 'array', 'opendir' => 'resource',
        'readdir' => 'string', 'closedir' => 'void', 'rewinddir' => 'void',
        'sort' => 'bool', 'rsort' => 'bool', 'asort' => 'bool',
        'arsort' => 'bool', 'ksort' => 'bool', 'krsort' => 'bool',
        'usort' => 'bool', 'uasort' => 'bool', 'uksort' => 'bool',
        'natsort' => 'bool', 'natcasesort' => 'bool', 'shuffle' => 'bool',
        'array_sum' => 'mixed', 'array_product' => 'mixed', 'array_pad' => 'array',
        'array_chunk' => 'array', 'array_diff' => 'array', 'array_intersect' => 'array',
        'array_count_values' => 'array', 'array_fill_keys' => 'array',
        'compact' => 'array', 'extract' => 'int', 'range' => 'array',
        'list' => 'array', 'key' => 'mixed', 'current' => 'mixed',
        'next' => 'mixed', 'prev' => 'mixed', 'reset' => 'mixed',
        'end' => 'mixed', 'each' => 'array', 'call_user_func' => 'mixed',
        'call_user_func_array' => 'mixed', 'forward_static_call' => 'mixed',
        'forward_static_call_array' => 'mixed', 'spl_autoload_register' => 'bool',
        'spl_autoload_unregister' => 'bool', 'spl_autoload_functions' => 'array',
        'iterator_to_array' => 'array', 'iterator_count' => 'int',
        'iterator_apply' => 'int',
        // ── ext 扩展函数返回类型（与 CodeGenerator::$builtinRetTypes 保持同步）──
        //   避免扩展函数返回具体类型（如 Resource*）被推导为 mixed（t_var），
        //   导致变量声明类型不匹配（如 t_var zip = tphp_fn_zip_open() → 编译错误）
        // ── zip (Resource*/bool/int/string/array) ──
        'zip_open' => 'tphp_class_Resource*', 'zip_close' => 'bool',
        'zip_read' => 'array', 'zip_entry_open' => 'bool',
        'zip_entry_read' => 'string', 'zip_entry_close' => 'bool',
        'zip_add_file' => 'bool', 'zip_add_dir' => 'bool',
        'zip_delete' => 'bool', 'zip_rename' => 'bool',
        'zip_stat' => 'array', 'zip_num_files' => 'int',
        'zip_get_error_string' => 'string', 'zip_entry_name' => 'string',
        'zip_entry_filesize' => 'int', 'zip_entry_compressedsize' => 'int',
        'zip_entry_compressionmethod' => 'string', 'zip_locate' => 'int',
        // ── zlib gz/增量 (Resource*/bool/int/string/array) ──
        'gzopen' => 'tphp_class_Resource*', 'gzclose' => 'bool',
        'gzeof' => 'bool', 'gzrewind' => 'bool', 'gzflush' => 'bool',
        'gzread' => 'string', 'gzgets' => 'string', 'gzgetc' => 'string',
        'gzwrite' => 'int', 'gzputs' => 'int', 'gzseek' => 'int',
        'gztell' => 'int', 'gzpassthru' => 'int', 'readgzfile' => 'int',
        'gzfile' => 'array',
        'gzcompress' => 'string', 'gzuncompress' => 'string',
        'gzencode' => 'string', 'gzdecode' => 'string',
        'gzdeflate' => 'string', 'gzinflate' => 'string',
        'zlib_encode' => 'string', 'zlib_decode' => 'string',
        'deflate_init' => 'tphp_class_Resource*', 'inflate_init' => 'tphp_class_Resource*',
        'deflate_add' => 'string', 'inflate_add' => 'string',
        'inflate_get_status' => 'int', 'inflate_get_read_len' => 'int',
        // ── fileinfo (Resource*/string/bool) ──
        'finfo_open' => 'tphp_class_Resource*', 'finfo_close' => 'void',
        'finfo_file' => 'string', 'finfo_buffer' => 'string',
        'finfo_set_flags' => 'bool', 'mime_content_type' => 'string',
        // ── sqlite3 函数式 API (int/bool/string/array) ──
        'sqlite_open' => 'int', 'sqlite_close' => 'void',
        'sqlite_exec' => 'bool', 'sqlite_query' => 'array',
        'sqlite_query_single' => 'array', 'sqlite_escape_string' => 'string',
        'sqlite_changes' => 'int', 'sqlite_last_insert_rowid' => 'int',
        'sqlite_last_error_msg' => 'string', 'sqlite_last_error_code' => 'int',
        'sqlite_version' => 'string',
        // ── stream (int/bool/string/array) ──
        'stream_last_error' => 'int', 'stream_set_read_buffer' => 'int',
        'stream_set_write_buffer' => 'int', 'stream_select' => 'int',
        'stream_socket_server' => 'int', 'stream_socket_accept' => 'int',
        'stream_socket_client' => 'int', 'stream_socket_sendto' => 'int',
        'stream_socket_enable_crypto' => 'int', 'stream_context_create' => 'int',
        'stream_strerror' => 'string', 'stream_socket_recvfrom' => 'string',
        'stream_socket_get_name' => 'string', 'stream_get_contents' => 'string',
        'stream_get_line' => 'string', 'stream_set_blocking' => 'bool',
        'stream_isatty' => 'bool', 'stream_set_timeout' => 'bool',
        'stream_socket_shutdown' => 'bool', 'stream_get_meta_data' => 'array',
        'stream_socket_pair' => 'array', 'stream_close' => 'void',
        // ── openssl (int/bool/string) ──
        'openssl_ctx_new' => 'int', 'openssl_ctx_set_options' => 'int',
        'openssl_ssl_new' => 'int', 'openssl_ssl_connect' => 'int',
        'openssl_ssl_accept' => 'int', 'openssl_ssl_write' => 'int',
        'openssl_error_string' => 'string', 'openssl_ssl_get_cipher_name' => 'string',
        'openssl_ssl_get_version' => 'string', 'openssl_encrypt' => 'string',
        'openssl_decrypt' => 'string', 'openssl_random_pseudo_bytes' => 'string',
        'openssl_digest' => 'string', 'openssl_ssl_read' => 'string',
        'openssl_ctx_use_certificate_file' => 'bool',
        'openssl_ctx_use_private_key_file' => 'bool',
        'openssl_ssl_set_fd' => 'bool', 'openssl_ssl_shutdown' => 'bool',
        'openssl_ctx_free' => 'void', 'openssl_ssl_free' => 'void',
        'openssl_ctx_set_verify' => 'void',
        // ── pdo 底层 (int/string/float/void) ──
        'pdo_open_db' => 'int', 'pdo_prepare' => 'int',
        'pdo_exec' => 'int', 'pdo_str_len' => 'int',
        'pdo_bind_text' => 'int', 'pdo_bind_blob' => 'int',
        'pdo_bind_params' => 'void', 'pdo_str_from_ptr' => 'string',
        'pdo_sqlite_errstate' => 'string', 'pdo_quote' => 'string',
        'pdo_column_double' => 'float', 'pdo_column_text' => 'string',
        'pdo_column_name' => 'string', 'pdo_column_decltype' => 'string',
        'pdo_errmsg' => 'string', 'pdo_libversion' => 'string',
        'pdo_throw_msg' => 'void', 'pdo_throw_db_error' => 'void',
        'pdo_throw_stmt_error' => 'void',
        'pdo_driver_find' => 'int', 'pdo_driver_open' => 'int',
        'pdo_driver_exec' => 'int', 'pdo_driver_prepare' => 'int',
        'pdo_driver_bind_int' => 'int', 'pdo_driver_bind_text' => 'int',
        'pdo_driver_bind_blob' => 'int', 'pdo_driver_bind_null' => 'int',
        'pdo_driver_bind_param_index' => 'int', 'pdo_driver_step' => 'int',
        'pdo_driver_column_count' => 'int', 'pdo_driver_column_type' => 'int',
        'pdo_driver_column_int64' => 'int', 'pdo_driver_column_bytes' => 'int',
        'pdo_driver_data_count' => 'int', 'pdo_driver_changes' => 'int',
        'pdo_driver_last_insert_rowid' => 'int', 'pdo_driver_errcode' => 'int',
        'pdo_driver_column_double' => 'float', 'pdo_driver_column_text' => 'string',
        'pdo_driver_column_name' => 'string', 'pdo_driver_column_decltype' => 'string',
        'pdo_driver_errmsg' => 'string', 'pdo_driver_last_open_error' => 'string',
        'pdo_driver_name' => 'string', 'pdo_driver_server_version' => 'string',
        'pdo_driver_quote' => 'string',
        // ── posix (int/string) ──
        'posix_getpid' => 'int', 'posix_getppid' => 'int',
        'posix_getuid' => 'int', 'posix_geteuid' => 'int',
        'posix_getgid' => 'int', 'posix_getegid' => 'int',
        'posix_isatty' => 'int', 'posix_kill' => 'int',
        'posix_get_last_error' => 'int', 'posix_getcwd' => 'string',
        'posix_strerror' => 'string', 'posix_ttyname' => 'string',
        // ── pcntl (int/string/void) ──
        'pcntl_fork' => 'int', 'pcntl_waitpid' => 'int',
        'pcntl_wait' => 'int', 'pcntl_alarm' => 'int',
        'pcntl_get_last_error' => 'int', 'pcntl_strerror' => 'string',
        'pcntl_exec' => 'void',
        // ── 其他补充 ──
        'str_contains' => 'bool', 'str_starts_with' => 'bool', 'str_ends_with' => 'bool',
        'array_is_list' => 'bool', 'str_split' => 'array',
        'parse_url' => 'array', 'parse_str' => 'array',
        'preg_grep' => 'array', 'preg_last_error' => 'int',
        'preg_last_error_msg' => 'string', 'preg_quote' => 'string',
        'iconv' => 'string', 'iconv_strlen' => 'int', 'iconv_strpos' => 'int',
        'iconv_substr' => 'string', 'iconv_mime_encode' => 'string',
        'iconv_mime_decode' => 'string', 'iconv_set_encoding' => 'bool',
        'iconv_get_encoding' => 'array',
        'http_build_query' => 'string', 'htmlspecialchars' => 'string',
        'nl2br' => 'string', 'password_hash' => 'string', 'password_verify' => 'bool',
        'json_validate' => 'bool', 'sha256' => 'string', 'sha512' => 'string',
        'base_convert' => 'string', 'str_pad' => 'string', 'str_shuffle' => 'string',
        'strrev' => 'string', 'strtr' => 'string', 'urlencode' => 'string',
        'urldecode' => 'string', 'addslashes' => 'string', 'stripslashes' => 'string',
        'intdiv' => 'int', 'array_key_first' => 'int', 'array_key_last' => 'int',
        'substr_count' => 'int', 'hrtime' => 'int', 'array_unshift' => 'int',
        'array_rand' => 'mixed', 'filter_var' => 'mixed', 'filter_id' => 'int',
        'filter_list' => 'array', 'deg2rad' => 'float', 'rad2deg' => 'float',
        'log10' => 'float', 'fmod' => 'float', 'asin' => 'float', 'acos' => 'float',
        'atan' => 'float', 'php_str' => 'string', 'php_str_clone' => 'string',
        // ── pgsql C 包装函数（_pg_*，与 CodeGenerator::$builtinRetTypes 保持同步）──
        //   ext/pgsql/src/pgsql.php 的 PHP 包装函数体内调用 _pg_*，
        //   注册返回类型避免被推导为 mixed（t_var），导致 return 类型不匹配
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
        // ── pdo_pgsql C 包装函数（_pgpdo_*）──
        '_pgpdo_get_pid' => 't_int', '_pgpdo_pgconn' => 't_int',
        '_pgpdo_get_notify' => 't_array*',
    ];

    public function __construct(SymbolTable $symbols)
    {
        $this->symbols = $symbols;
        // 确保 Type 静态实例已初始化（Type::$int 等）
        Type::init();
    }

    // ──────────────────────────────────────────────────────────
    // 主入口
    // ──────────────────────────────────────────────────────────

    /**
     * 检查整个程序，填充所有 ExprNode 的 inferredType 字段
     */
    public function check(ProgramNode $program): void
    {
        $this->prescan($program);
        $this->checkProgram($program);
    }

    // ──────────────────────────────────────────────────────────
    // 预扫描（prescan）
    //   在 check() 之前调用，填充 TypeChecker 自己的 SymbolTable
    //   注册所有类、函数、方法、枚举、常量
    // ──────────────────────────────────────────────────────────

    /**
     * 预扫描 AST，填充 SymbolTable（在 check() 之前调用）
     * 注册所有类、函数、方法、枚举、常量到 TypeChecker 的 SymbolTable
     */
    public function prescan(ProgramNode $program): void
    {
        // 0. 读取 use 导入表（Parser 收集，用于解析短类名引用）
        $this->useImports = $program->useImports['classes'] ?? [];

        // 1. 注册内置类（Exception、Generator 等基础类）
        $this->registerBuiltinClasses();

        // 2. 注册内置常量（PHP_EOL 等）
        $this->registerBuiltinConstants();

        // 3. 预扫描类名映射（addClassName）
        $allClasses = array_merge(
            $program->mainClass !== null ? [$program->mainClass] : [],
            $program->extraClasses
        );
        foreach ($allClasses as $class) {
            // trait 自身不注册到符号表（编译期扁平化到使用方，不生成 C 结构体）
            if ($class->isTrait) {
                continue;
            }
            $cn = $this->classCName($class);
            $this->symbols->addClassName($class->name, $cn);
            if ($class->namespace !== '') {
                $this->symbols->addClassName($class->namespace . '\\' . $class->name, $cn);
            }
        }

        // 3b. 收集所有 trait 定义（供后续扁平化使用）
        //     键为 trait 短名 + FQ 名，值为 ClassNode
        $this->traitDefs = [];
        foreach ($allClasses as $class) {
            if ($class->isTrait) {
                $this->traitDefs[$class->name] = $class;
                if ($class->namespace !== '') {
                    $this->traitDefs[$class->namespace . '\\' . $class->name] = $class;
                }
            }
        }

        // 3c. 对使用 trait 的类编译期扁平化：将 trait 的 methods/properties/classConsts
        //     复制到类（应用 insteadof 排除规则和 as 别名规则）
        //     这一步在 registerClass 之前完成，确保 SymbolTable 注册的是扁平化后的成员
        foreach ($allClasses as $class) {
            if ($class->isTrait || empty($class->traits)) {
                continue;
            }
            $this->flattenTraits($class);
        }

        // 4. 注册类的完整信息（属性、方法、常量）
        foreach ($allClasses as $class) {
            if ($class->isTrait) {
                continue; // trait 不注册到 SymbolTable
            }
            $this->registerClass($class);
        }

        // 5. 注册独立函数和 C 函数签名声明
        //    C 函数签名声明（isCDeclaration=true）使用单独的注册路径，
        //    避免污染普通函数符号表（C.foo 不是合法的 C 函数名）
        foreach ($program->functions as $fn) {
            if ($fn->isCDeclaration) {
                $this->registerCFunction($fn);
            } else {
                $this->registerFunction($fn);
            }
        }

        // 5b. 注册 C 结构体（#cstruct 指令 + struct C.Foo{} 声明）
        //     TypeChecker 据此推导 C 结构体字段访问 $p->x 的类型
        foreach ($program->cstructs as $cs) {
            $this->symbols->addCStruct($cs['name'], $cs['fields']);
            // 同时注册到 TypeTable，便于 resolveTypeFromString 识别 C.X / C.X* 类型注解
            //   cType 设为结构体名（如 'Point'），后续 checkPropertyAccess 据此查 cStructs
            $this->symbols->registerType('C.' . $cs['name'], $cs['name'], true);
        }

        // 6. 注册枚举
        foreach ($program->enums as $enum) {
            $this->registerEnum($enum);
        }

        // 7. 注册全局常量
        foreach ($program->constants as $const) {
            $this->registerConst($const);
        }

        // 8. 注册 use const 导入（Task 2.12）
        //    将 use const NS\FOO; 导入的常量按短名注册，类型从原 FQCN 查找
        foreach (($program->useImports['consts'] ?? []) as $shortName => $fqName) {
            $info = $this->symbols->getConst($fqName);
            if ($info !== null) {
                $this->symbols->addConst($shortName, $info['type'], $info['vis']);
            }
        }
    }

    /** 注册内置类（Exception/Generator/Resource 等） */
    private function registerBuiltinClasses(): void
    {
        // Exception
        $this->symbols->addClass('tphp_class_Exception');
        $this->symbols->addClassName('Exception', 'tphp_class_Exception');
        $this->symbols->addClassProp('tphp_class_Exception', 'message', 't_string');
        $this->symbols->addClassProp('tphp_class_Exception', 'code', 't_int');
        $this->symbols->addClassMethod('tphp_class_Exception', '__construct', new MethodInfo('void', ['t_string'], false, 'public', 1, 1));
        $this->symbols->addClassMethod('tphp_class_Exception', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_Exception', 'getMessage',  new MethodInfo('t_string'));
        $this->symbols->addClassMethod('tphp_class_Exception', 'getCode',     new MethodInfo('t_int'));
        $this->symbols->addClassMethod('tphp_class_Exception', 'getPrevious', new MethodInfo('tphp_class_Exception*'));

        // Generator（基于 minicoro 协程）
        $this->symbols->addClass('tphp_class_Generator');
        $this->symbols->addClassName('Generator', 'tphp_class_Generator');
        $this->symbols->addClassMethod('tphp_class_Generator', 'current',   new MethodInfo('t_var'));
        $this->symbols->addClassMethod('tphp_class_Generator', 'key',       new MethodInfo('t_var'));
        $this->symbols->addClassMethod('tphp_class_Generator', 'next',      new MethodInfo('t_var'));
        $this->symbols->addClassMethod('tphp_class_Generator', 'send',      new MethodInfo('t_var', ['t_var']));
        $this->symbols->addClassMethod('tphp_class_Generator', 'valid',     new MethodInfo('t_int'));
        $this->symbols->addClassMethod('tphp_class_Generator', 'getReturn', new MethodInfo('t_var'));
        $this->symbols->addClassMethod('tphp_class_Generator', 'rewind',    new MethodInfo('void'));

        // 注册 Exception 子类（常见异常）— 列表与 CodeGenerator::BUILTIN_EXCEPTION_SUBCLASSES 一致
        foreach (CodeGenerator::BUILTIN_EXCEPTION_SUBCLASSES as $name) {
            $cn = 'tphp_class_' . $name;
            $this->symbols->addClass($cn, 'tphp_class_Exception');
            $this->symbols->addClassName($name, $cn);
            $this->symbols->addClassMethod($cn, '__construct', new MethodInfo('void', ['t_string'], false, 'public', 1, 1));
            $this->symbols->addClassMethod($cn, '__destruct',  new MethodInfo('void'));
            $this->symbols->addClassMethod($cn, 'getMessage',  new MethodInfo('t_string'));
        }

        // 内置 Resource 类（资源对象化根，用户可 extends Resource）
        //   注册与方法签名与 CodeGenerator 中保持一致
        $this->symbols->addClass('tphp_class_Resource');
        $this->symbols->addClassName('Resource', 'tphp_class_Resource');
        $this->symbols->addClassMethod('tphp_class_Resource', '__construct', new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_Resource', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_Resource', 'getType',     new MethodInfo('t_int'));

        // 内置 File 类（Resource 子类，替代 fopen resource）
        $this->symbols->addClass('tphp_class_File', 'tphp_class_Resource');
        $this->symbols->addClassName('File', 'tphp_class_File');
        $this->symbols->addClassMethod('tphp_class_File', '__construct', new MethodInfo('void', ['t_string', 't_string']));
        $this->symbols->addClassMethod('tphp_class_File', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_File', 'getType',     new MethodInfo('t_int'));
        $this->symbols->addClassMethod('tphp_class_File', 'read',        new MethodInfo('t_string', ['t_int']));
        $this->symbols->addClassMethod('tphp_class_File', 'write',       new MethodInfo('t_int', ['t_string']));
        $this->symbols->addClassMethod('tphp_class_File', 'eof',         new MethodInfo('t_bool'));
        $this->symbols->addClassMethod('tphp_class_File', 'close',       new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_File', 'isOpen',      new MethodInfo('t_bool'));

        // 内置 Thread 类（基于 tinycthread 的线程封装）
        $this->symbols->addClass('tphp_class_Thread');
        $this->symbols->addClassName('Thread', 'tphp_class_Thread');
        $this->symbols->addClassMethod('tphp_class_Thread', '__construct', new MethodInfo('void', ['t_callback']));
        $this->symbols->addClassMethod('tphp_class_Thread', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_Thread', 'start',       new MethodInfo('t_bool'));
        $this->symbols->addClassMethod('tphp_class_Thread', 'join',        new MethodInfo('t_int'));
        $this->symbols->addClassMethod('tphp_class_Thread', 'detach',      new MethodInfo('t_bool'));
        $this->symbols->addClassMethod('tphp_class_Thread', 'yield',       new MethodInfo('void', [], true));
        $this->symbols->addClassMethod('tphp_class_Thread', 'sleep',       new MethodInfo('void', ['t_float'], true));
        $this->symbols->addClassMethod('tphp_class_Thread', 'id',          new MethodInfo('t_int', [], true));

        // 内置 Mutex 类
        $this->symbols->addClass('tphp_class_Mutex');
        $this->symbols->addClassName('Mutex', 'tphp_class_Mutex');
        $this->symbols->addClassMethod('tphp_class_Mutex', '__construct', new MethodInfo('void', ['t_bool']));
        $this->symbols->addClassMethod('tphp_class_Mutex', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_Mutex', 'lock',        new MethodInfo('t_bool'));
        $this->symbols->addClassMethod('tphp_class_Mutex', 'tryLock',     new MethodInfo('t_bool'));
        $this->symbols->addClassMethod('tphp_class_Mutex', 'unlock',      new MethodInfo('t_bool'));

        // 内置 CondVar 类
        $this->symbols->addClass('tphp_class_CondVar');
        $this->symbols->addClassName('CondVar', 'tphp_class_CondVar');
        $this->symbols->addClassMethod('tphp_class_CondVar', '__construct', new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_CondVar', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_CondVar', 'wait',        new MethodInfo('t_bool', ['tphp_class_Mutex*']));
        $this->symbols->addClassMethod('tphp_class_CondVar', 'signal',      new MethodInfo('t_bool'));
        $this->symbols->addClassMethod('tphp_class_CondVar', 'broadcast',   new MethodInfo('t_bool'));

        // 内置 WaitGroup 类
        $this->symbols->addClass('tphp_class_WaitGroup');
        $this->symbols->addClassName('WaitGroup', 'tphp_class_WaitGroup');
        $this->symbols->addClassMethod('tphp_class_WaitGroup', '__construct', new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_WaitGroup', '__destruct',  new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_WaitGroup', 'add',         new MethodInfo('void', ['t_int']));
        $this->symbols->addClassMethod('tphp_class_WaitGroup', 'done',        new MethodInfo('void'));
        $this->symbols->addClassMethod('tphp_class_WaitGroup', 'wait',        new MethodInfo('void'));

        // 内置 Parallel 类（数据并行 API — 纯函数并行）
        $this->symbols->addClass('tphp_class_Parallel');
        $this->symbols->addClassName('Parallel', 'tphp_class_Parallel');
        // for(int $n, callable $fn, int $threads = 0): void — 3 params, 1 default
        $this->symbols->addClassMethod('tphp_class_Parallel', 'for', new MethodInfo('void', ['t_int', 't_callback', 't_int'], true, 'public', 1, 3));
        // map(array $data, callable $fn, int $threads = 0): array — 3 params, 1 default
        $this->symbols->addClassMethod('tphp_class_Parallel', 'map', new MethodInfo('t_array*', ['t_array*', 't_callback', 't_int'], true, 'public', 1, 3));

        // 内置 AnnotationEntry 类（注解系统）
        $this->symbols->addClass('tphp_class_AnnotationEntry');
        $this->symbols->addClassName('AnnotationEntry', 'tphp_class_AnnotationEntry');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'data', 't_array*');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'type', 't_string');
        $this->symbols->addClassProp('tphp_class_AnnotationEntry', 'name', 't_string');
        $this->symbols->addClassMethod('tphp_class_AnnotationEntry', '__construct', new MethodInfo('void', ['t_array*', 't_string', 't_string']));
        $this->symbols->addClassMethod('tphp_class_AnnotationEntry', '__destruct',  new MethodInfo('void'));
    }

    /** 注册内置常量 */
    private function registerBuiltinConstants(): void
    {
        $strConsts = ['PHP_EOL', 'PHP_OS', 'PHP_OS_FAMILY', 'PHP_SAPI', 'PHP_VERSION', 'PHP_EXTRA_VERSION'];
        foreach ($strConsts as $c) {
            $this->symbols->addConst($c, 't_string');
        }

        $intConsts = ['PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_INT_SIZE', 'PHP_MAJOR_VERSION',
                      'E_ERROR', 'E_WARNING', 'E_PARSE', 'E_NOTICE', 'E_ALL'];
        foreach ($intConsts as $c) {
            $this->symbols->addConst($c, 't_int');
        }

        $floatConsts = ['PHP_FLOAT_MAX', 'PHP_FLOAT_MIN', 'PHP_FLOAT_EPSILON'];
        foreach ($floatConsts as $c) {
            $this->symbols->addConst($c, 't_float');
        }
    }

    /** 类名 → C 名（与 CodeGenerator::classCName 一致） */
    private function classCName(ClassNode $class): string
    {
        if ($class->namespace === '') {
            return 'tphp_class_' . $class->name;
        }
        return 'tphp_na_' . str_replace('\\', '_', $class->namespace) . '_tphp_class_' . $class->name;
    }

    /**
     * 编译期扁平化 trait：将 trait 的 methods/properties/classConsts 复制到类。
     *
     * PHP trait 语义（AOT 实现，零运行时开销）：
     *   - 类自身方法优先于 trait 方法（覆盖）
     *   - 多 trait 同名方法必须用 insteadof 解决冲突，否则报错
     *   - insteadof: `B::foo insteadof A` 表示 foo 排除 A 的版本，使用 B 的版本
     *   - as 别名: `A::bar as baz` 创建 A::bar 的克隆，重命名为 baz
     *   - as 可见性: `A::bar as private` 改变可见性（TinyPHP 仅 public/private，记录但不强制）
     *
     * @param ClassNode $class 使用 trait 的类（非 trait 自身）
     */
    private function flattenTraits(ClassNode $class): void
    {
        // 收集类自身已定义的方法名/属性名/常量名（用于去重，类定义优先）
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

        // insteadof 规则：[methodName => [excludedTraitName, ...]]
        $insteadof = $class->traitAdaptations['insteadof'] ?? [];
        // as 规则：['TraitName::methodName' => ['alias' => newName, 'visibility' => vis]]
        $asRules = $class->traitAdaptations['as'] ?? [];

        // 按声明顺序遍历 trait（先声明的 trait 方法先加入，后声明的同名方法需 insteadof 解决）
        $traitMethodOwners = []; // methodName => [traitName, ...] 记录每个方法来自哪些 trait
        $newMethods = [];
        $newProps = [];
        $newConsts = [];

        foreach ($class->traits as $traitName) {
            $trait = $this->resolveTrait($traitName, $class->namespace);
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
                $asKey = "{$traitName}::{$mName}";
                if (isset($asRules[$asKey]['alias'])) {
                    $alias = $asRules[$asKey]['alias'];
                    if (!isset($selfMethods[$alias])) {
                        // 克隆方法并重命名（PHP 语义：as 创建别名副本，原方法仍可用除非同时用 insteadof）
                        $aliasMethod = clone $m;
                        // 反射修改 readonly 的 name 字段
                        (new \ReflectionProperty($aliasMethod, 'name'))->setValue($aliasMethod, $alias);
                        $newMethods[] = $aliasMethod;
                        $selfMethods[$alias] = true;
                    }
                }

                // 类自身已定义 → 跳过（类方法覆盖 trait 方法）
                if (isset($selfMethods[$mName])) {
                    continue;
                }
                // insteadof 排除：当前 trait 在该方法的排除列表中 → 跳过主方法
                if ($excluded) {
                    continue;
                }

                // 记录方法来源，用于冲突检测
                $traitMethodOwners[$mName][] = $traitName;

                // 检查是否已存在同名方法（来自前一个 trait）
                $exists = false;
                foreach ($newMethods as $existing) {
                    if ($existing->name === $mName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $newMethods[] = $m;
                }
            }

            // 复制属性（trait 属性与类属性冲突时报错，PHP 语义严格）
            foreach ($trait->properties as $p) {
                if (isset($selfProps[$p->name])) {
                    throw new \RuntimeException("Trait '{$traitName}' property '{$p->name}' conflicts with class {$class->name} property (PHP traits: properties must be unique)");
                }
                // 检查与之前 trait 的属性冲突
                foreach ($newProps as $existing) {
                    if ($existing->name === $p->name) {
                        throw new \RuntimeException("Trait '{$traitName}' property '{$p->name}' conflicts with previous trait property (PHP traits: properties must be unique)");
                    }
                }
                $newProps[] = $p;
                $selfProps[$p->name] = true;
            }

            // 复制类常量（trait 常量与类常量冲突时报错）
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

        // 合并到类（trait 方法追加在类自身方法之后，C 代码生成顺序无关）
        $class->methods = array_merge($class->methods, $newMethods);
        $class->properties = array_merge($class->properties, $newProps);
        $class->classConsts = array_merge($class->classConsts, $newConsts);
    }

    /** 解析 trait 名（支持短名、FQ 名、命名空间内引用） */
    private function resolveTrait(string $name, string $currentNamespace): ?ClassNode
    {
        // 1. 短名直接查
        if (isset($this->traitDefs[$name])) {
            return $this->traitDefs[$name];
        }
        // 2. 当前命名空间下的 trait
        if ($currentNamespace !== '' && isset($this->traitDefs[$currentNamespace . '\\' . $name])) {
            return $this->traitDefs[$currentNamespace . '\\' . $name];
        }
        // 3. 去掉前导 \ 的 FQ 名
        $trimmed = ltrim($name, '\\');
        if (isset($this->traitDefs[$trimmed])) {
            return $this->traitDefs[$trimmed];
        }
        // 4. use 导入表解析短名
        $fqcn = $this->useImports[$name] ?? null;
        if ($fqcn !== null && isset($this->traitDefs[$fqcn])) {
            return $this->traitDefs[$fqcn];
        }
        return null;
    }

    /** 注册类的完整信息 */
    private function registerClass(ClassNode $class): void
    {
        $cn = $this->classCName($class);
        $parentCN = '';
        if ($class->parentName !== null) {
            $parentCN = $this->symbols->resolveClass($class->parentName) ?? ('tphp_class_' . ltrim($class->parentName, '\\'));
        }

        $this->symbols->addClass(
            $cn, $parentCN, $class->isAbstract,
            $class->implements, $class->isReadonly, $class->isFinal, $class->isInterface
        );

        // 注册属性
        foreach ($class->properties as $prop) {
            $ptype = $this->phpTypeToCType($prop->type);
            $this->symbols->addClassProp($cn, $prop->name, $ptype, true, $prop->isStatic);
            if ($prop->isReadonly || $class->isReadonly) {
                $this->symbols->addClassReadonlyProp($cn, $prop->name);
            }
            if ($prop->setVisibility === 'private' && !$prop->isStatic) {
                $this->symbols->addClassPrivateSetProp($cn, $prop->name);
            }
        }

        // 注册类常量（Task 2.12: 同时覆盖普通类和接口的常量；
        //   接口被解析为 isInterface=true 的 ClassNode，classConsts 字段同样有效）
        foreach ($class->classConsts as $const) {
            $ctype = $this->phpTypeToCType($const->type);
            $this->symbols->addConst($cn . '::' . $const->name, $ctype, $const->visibility ?? 'public');
        }

        // 注册方法
        // Task 5.5: final class 的方法标记 is_static_dispatch = true
        //   final class 不可继承，所有方法调用可在编译期确定目标，走静态分发
        $isStaticDispatch = $class->isFinal;
        foreach ($class->methods as $method) {
            $retType = $this->phpTypeToCType($method->returnType);
            $paramTypes = [];
            $isVariadic = false;
            foreach ($method->params as $param) {
                if ($param->isVariadic) {
                    // 可变参数：C 类型固定为 t_array*，元素类型由 type 字段追踪
                    $paramTypes[] = 't_array*';
                    $isVariadic = true;
                } else {
                    $paramTypes[] = $this->phpTypeToCType($param->type);
                }
            }
            $defaultCount = 0;
            $totalParams = count($method->params);
            for ($i = $totalParams - 1; $i >= 0; $i--) {
                if ($method->params[$i]->default !== null) {
                    $defaultCount++;
                } else {
                    break;
                }
            }
            // 同步标记 MethodNode（AST 元数据，供 CodeGenerator 查询）
            $method->isStaticDispatch = $isStaticDispatch;
            $this->symbols->addClassMethod($cn, $method->name, new MethodInfo(
                $retType, $paramTypes, $method->isStatic, $method->visibility,
                $defaultCount, $totalParams, $method->isFinal, $method->body === null,
                [], $isVariadic, $isStaticDispatch
            ));
        }
    }

    /** 注册独立函数 */
    private function registerFunction(FunctionNode $fn): void
    {
        $fnCName = $this->funcCName($fn);
        $retType = $this->phpTypeToCType($fn->returnType);
        $paramTypes = [];
        $isVariadic = false;
        foreach ($fn->params as $param) {
            if ($param->isVariadic) {
                // 可变参数：C 类型固定为 t_array*，元素类型由 type 字段追踪
                $paramTypes[] = 't_array*';
                $isVariadic = true;
            } else {
                $paramTypes[] = $this->phpTypeToCType($param->type);
            }
        }
        $defaultCount = 0;
        $totalParams = count($fn->params);
        for ($i = $totalParams - 1; $i >= 0; $i--) {
            if ($fn->params[$i]->default !== null) {
                $defaultCount++;
            } else {
                break;
            }
        }
        $this->symbols->addFunc($fnCName, new FunctionInfo(
            $retType, $paramTypes, $defaultCount, $totalParams, $fn->isGenerator,
            [], $isVariadic
        ));
    }

    /** 函数名 → C 名（与 CodeGenerator::funcCName 一致） */
    private function funcCName(FunctionNode $fn): string
    {
        if ($fn->namespace === '') {
            return 'tphp_fn_' . $fn->name;
        }
        return 'tphp_na_' . str_replace('\\', '_', $fn->namespace) . '_tphp_fn_' . $fn->name;
    }

    /** 注册 C 函数签名声明（vlang 风格 function C.foo(...): C.ret;）
     *  将 C 类型（C.int / C.char* 等）转换为 PHP 等价类型字符串后注册到 SymbolTable。
     *  TypeChecker 使用 PHP 等价类型（t_int / t_string / mixed 等），
     *  CodeGenerator 使用原始 C 类型（int / char* 等），两者 SymbolTable 实例独立，互不冲突。 */
    private function registerCFunction(FunctionNode $fn): void
    {
        // 名称格式: 'C.' + 函数名 → 剥前缀得到原始 C 函数名
        $rawName = substr($fn->name, 2);
        $retType = self::cTypeToPHPEquiv($fn->returnType);
        $paramTypes = array_map(fn($p) => self::cTypeToPHPEquiv($p->type), $fn->params);
        $this->symbols->addCFunction($rawName, $retType, $paramTypes);
    }

    /** C 类型字符串 → PHP 等价类型字符串（用于 TypeChecker 注册 C 函数签名）
     *  C.int → t_int, C.double → t_float, C.char* → t_string,
     *  C.void → void, 其他指针 → mixed（不透明指针）, 其他标量 → t_int
     *  TinyPHP 内部类型（t_int/t_string/t_callback/t_float/t_bool/t_array*）原样透传，
     *  用于 phpc 扩展中 C 函数直接接收 TinyPHP 值类型参数（如 t_callback 回调）。
     *  变参 '...' 原样返回（由消费方识别为变参标记） */
    private static function cTypeToPHPEquiv(string $cType): string
    {
        $cType = trim($cType);
        if ($cType === '' || $cType === '...') return $cType;

        // 剥去 C. 前缀
        if (str_starts_with($cType, 'C.')) {
            $cType = substr($cType, 2);
        }

        // TinyPHP 内部类型原样透传（t_int/t_string/t_callback/t_float/t_bool/t_array*）
        // 用于 phpc 扩展中 C 函数直接接收 TinyPHP 值类型参数
        if (in_array($cType, ['t_int', 't_string', 't_callback', 't_float', 't_bool', 't_array*'], true)) {
            return $cType;
        }

        // void → void
        if ($cType === 'void') return 'void';

        // 指针类型：
        //   char* → t_string（C 字符串语义，便于类型推导）
        //   其他指针 → mixed（不透明指针，与旧有 Type::$mixed 行为一致）
        if (str_ends_with($cType, '*')) {
            $base = rtrim($cType, '* ');
            if ($base === 'char') return 't_string';
            return 'mixed';
        }

        // voidptr / FILE → mixed（不透明指针）
        if ($cType === 'voidptr' || $cType === 'FILE') return 'mixed';

        // 标量类型映射
        return match ($cType) {
            'float', 'double' => 't_float',
            'bool' => 't_bool',
            default => 't_int',  // int/char/short/long/size_t/int8_t/...
        };
    }

    /**
     * C 类型字符串 → Type 对象（用于 C 结构体字段类型推导）
     *   C.int → Type::$int, C.double → Type::$float, C.char* → Type::$string
     *   C.char → Type::$int (char 作为 int), C.bool → Type::$bool
     *   C.void* / C.FILE / 其他不透明指针 → Type::pointer(Type::$void)
     *   未识别类型 → Type::pointer(Type::$void)（不透明指针，安全回退）
     */
    private function cTypeToType(string $cType): Type
    {
        $cType = trim($cType);
        // 剥去 C. 前缀
        $base = str_starts_with($cType, 'C.') ? substr($cType, 2) : $cType;

        // 指针类型
        if (str_ends_with($base, '*')) {
            $ptrBase = rtrim($base, '* ');
            return match ($ptrBase) {
                'char'      => Type::$string,
                'void'      => Type::pointer(Type::$void),
                'FILE'      => Type::pointer(Type::$void),
                'voidptr'   => Type::pointer(Type::$void),
                default     => Type::pointer(Type::$void),  // 其他指针 → 不透明指针
            };
        }

        // 标量类型
        return match ($base) {
            'int', 'int8_t', 'int16_t', 'int32_t', 'int64_t',
            'uint8_t', 'uint16_t', 'uint32_t', 'uint64_t',
            'size_t', 'ssize_t', 'short', 'long', 'char' => Type::$int,
            'float', 'double' => Type::$float,
            'bool' => Type::$bool,
            'void' => Type::$void,
            'voidptr', 'FILE' => Type::pointer(Type::$void),
            default => Type::pointer(Type::$void),  // 其他 C 类型 → 不透明指针（安全回退）
        };
    }

    /** 注册枚举 */
    private function registerEnum(EnumNode $enum): void
    {
        $fqName = $enum->namespace !== '' ? $enum->namespace . '\\' . $enum->name : $enum->name;
        $cName = 'tphp_enum_' . str_replace('\\', '_', $fqName);
        $backing = $enum->backingType ?: 'int';
        $cType = ($backing === 'string' ? 't_string' : 't_int') . '/* enum */';

        $this->symbols->addEnum($enum->name, $backing, $cName . '*');
        $this->symbols->addEnum($fqName, $backing, $cName . '*');

        foreach ($enum->cases as $case) {
            $this->symbols->addEnumCase($enum->name, $case->name);
            $this->symbols->addEnumCase($fqName, $case->name);
        }

        foreach ($enum->classConsts as $const) {
            $ctype = $this->phpTypeToCType($const->type);
            $this->symbols->addEnumConst($enum->name, $const->name, $ctype);
            $this->symbols->addEnumConst($fqName, $const->name, $ctype);
        }

        foreach ($enum->methods as $method) {
            $retType = $this->phpTypeToCType($method->returnType);
            $paramTypes = array_map(fn($p) => $this->phpTypeToCType($p->type), $method->params);
            $paramNames = array_map(fn($p) => ltrim($p->name, '$'), $method->params);
            $totalParams = count($method->params);
            $defaultCount = 0;
            for ($i = $totalParams - 1; $i >= 0; $i--) {
                if ($method->params[$i]->default !== null) { $defaultCount++; } else { break; }
            }
            $mi = new MethodInfo($retType, $paramTypes, $method->isStatic, $method->visibility,
                $defaultCount, $totalParams, false, false, $paramNames);
            $this->symbols->addEnumMethod($enum->name, $method->name, $mi);
            $this->symbols->addEnumMethod($fqName, $method->name, $mi);
        }

        // 自动静态方法注册：cases()/from()/tryFrom() — 与 CodeGenerator::visitEnum 保持一致
        // 使 TypeChecker 阶段也能推导出枚举类型（而非 mixed）
        $paramCType = ($enum->backingType === 'int') ? 't_int' : 't_string';
        $autoStatic = [
            'cases'    => 't_array*',
            'from'     => $cName . '*',
            'tryFrom'  => $cName . '*',
        ];
        foreach ($autoStatic as $mname => $mret) {
            $mi = new MethodInfo($mret, [$paramCType], true, 'public', 0, 1, false, false, ['value']);
            if ($mname === 'cases') {
                $mi = new MethodInfo($mret, [], true, 'public', 0, 0);
            }
            $this->symbols->addEnumMethod($enum->name, $mname, $mi);
            $this->symbols->addEnumMethod($fqName, $mname, $mi);
        }
    }

    /** 注册全局常量 */
    private function registerConst(ConstNode $const): void
    {
        // 全局/命名空间常量类型声明可选（与 CodeGenerator::visitConstStmt 一致）：
        //   有声明用声明，无则从字面量值推导
        if ($const->type !== null) {
            $ctype = $this->phpTypeToCType($const->type);
        } else {
            $ctype = match ($const->value::class) {
                IntLiteralExpr::class    => 't_int',
                FloatLiteralExpr::class  => 't_float',
                StringLiteralExpr::class => 't_string',
                BoolLiteralExpr::class   => 't_bool',
                MagicConstExpr::class    => 't_string',
                default                  => 't_int',
            };
        }
        $this->symbols->addConst($const->name, $ctype, $const->visibility ?? 'public');
    }

    /** PHP 类型字符串 → C 类型字符串（简化版，与 CodeGenerator::mapType 对齐） */
    private function phpTypeToCType(string $phpType): string
    {
        $phpType = trim($phpType);
        // Task 2.13: 无类型标注（''）或 mixed 类型均映射为 t_var（万能动态类型容器）
        if ($phpType === '' || $phpType === 'mixed') {
            return 't_var';
        }

        // 去掉 ? 前缀（nullable 在 C 层面不加指针）
        if (str_starts_with($phpType, '?')) {
            $phpType = substr($phpType, 1);
        }
        // 去掉 |Exception 后缀
        $phpType = preg_replace('/\|Exception$/', '', $phpType);

        return match ($phpType) {
            'void' => 'void',
            'int', 'integer' => 't_int',
            'float', 'double' => 't_float',
            'string' => 't_string',
            'bool', 'boolean' => 't_bool',
            'array' => 't_array*',
            'object' => 'void*',
            'callable', 'callback' => 't_callback*',
            'null' => 'null',
            'resource' => 'void*',
            'never' => 'void',
            'self', 'static' => $this->currentClassCName !== null ? $this->currentClassCName . '*' : 'void*',
            default => $this->resolveClassNameToCType($phpType),
        };
    }

    /** 类名 → C 类型字符串 */
    private function resolveClassNameToCType(string $name): string
    {
        $fqcn = $this->resolveTypeRef($name);
        $cName = $this->symbols->resolveClass($fqcn);
        if ($cName !== null) {
            return $cName . '*';
        }
        // 默认规则
        $clean = ltrim(str_replace('\\', '_', $fqcn), '\\');
        return 'tphp_class_' . $clean . '*';
    }

    // ──────────────────────────────────────────────────────────
    // 顶层 check* 分发方法
    // ──────────────────────────────────────────────────────────

    private function checkProgram(ProgramNode $node): void
    {
        // 骨架：遍历所有顶层节点
        // trait 自身不参与类型检查（编译期已扁平化到使用方）
        if ($node->mainClass !== null && !$node->mainClass->isTrait) {
            $this->checkClass($node->mainClass);
        }
        foreach ($node->extraClasses as $class) {
            if ($class->isTrait) {
                continue;
            }
            $this->checkClass($class);
        }
        foreach ($node->functions as $fn) {
            $this->checkFunction($fn);
        }
        foreach ($node->constants as $const) {
            $this->checkConst($const);
        }
        foreach ($node->enums as $enum) {
            $this->checkEnum($enum);
        }
        // includes/ccFlags/callbacks/debugs/cstructs 为纯数据，无 AST 节点需检查
    }

    private function checkClass(ClassNode $node): void
    {
        $savedNamespace  = $this->currentNamespace;
        $savedClassCName = $this->currentClassCName;
        $savedStatic     = $this->inStaticContext;

        $this->currentNamespace = $node->namespace;
        $this->currentClassCName = $this->resolveClassCName($node->name);
        $this->inStaticContext = false;

        // 属性默认值
        foreach ($node->properties as $prop) {
            $this->checkPropertyDecl($prop);
        }

        // 类常量
        foreach ($node->classConsts as $const) {
            $this->checkConst($const);
        }

        // 方法
        foreach ($node->methods as $method) {
            $this->checkMethod($method);
        }

        // 类型约束检查（Task 2.9/2.10/2.11）
        $this->checkClassTypeConstraints($node);

        $this->currentNamespace  = $savedNamespace;
        $this->currentClassCName = $savedClassCName;
        $this->inStaticContext   = $savedStatic;
    }

    /**
     * 类类型约束检查（接口实现、抽象方法实现、final 继承/覆盖）
     * 使用 trigger_error 发出警告，不中断编译
     */
    private function checkClassTypeConstraints(ClassNode $node): void
    {
        $cn = $this->currentClassCName;
        if ($cn === null) return;

        // 接口本身不需要检查实现
        if ($node->isInterface) return;

        // 1. final 类不可被继承（Task 2.11.6）
        if ($node->parentName !== null) {
            $parentCN = $this->symbols->resolveClass($node->parentName);
            if ($parentCN !== null && $this->symbols->isClassFinal($parentCN)) {
                trigger_error(
                    "Class {$node->name} cannot extend final class {$node->parentName}",
                    E_USER_WARNING
                );
            }
        }

        // 2. final 方法不可被覆盖（Task 2.11.7）
        if ($node->parentName !== null) {
            $parentCN = $this->symbols->resolveClass($node->parentName);
            if ($parentCN !== null) {
                foreach ($node->methods as $method) {
                    $cur = $parentCN;
                    $visited = [];
                    while ($cur !== '' && !isset($visited[$cur])) {
                        $visited[$cur] = true;
                        $parentMethod = $this->symbols->getClassMethod($cur, $method->name);
                        if ($parentMethod !== null && $parentMethod->isFinal) {
                            trigger_error(
                                "Class {$node->name} cannot override final method {$method->name}()",
                                E_USER_WARNING
                            );
                            break;
                        }
                        $cur = $this->symbols->getClassParent($cur);
                    }
                }
            }
        }

        // 以下检查仅对具体类（非 abstract）生效
        if ($node->isAbstract) return;

        // 收集本类及父类链中所有已实现（非 abstract）方法名
        $implementedMethods = $this->collectImplementedMethodNames($cn);

        // 3. 接口方法实现检查（Task 2.9.6）
        foreach ($node->implements as $ifaceName) {
            $ifaceCN = $this->symbols->resolveClass($ifaceName);
            if ($ifaceCN === null) continue;
            $ifaceMethods = $this->collectInterfaceMethods($ifaceCN);
            foreach ($ifaceMethods as $mName => $ifaceMethod) {
                if (!isset($implementedMethods[$mName])) {
                    trigger_error(
                        "Class {$node->name} must implement interface method {$ifaceName}::{$mName}()",
                        E_USER_WARNING
                    );
                } else {
                    // 查找实现方法的 MethodInfo（本类或父类链）
                    $implMethod = $this->symbols->getClassMethod($cn, $mName);
                    if ($implMethod === null) {
                        $cur = $cn;
                        while ($cur !== '' && $implMethod === null) {
                            $cur = $this->symbols->getClassParent($cur);
                            $implMethod = $this->symbols->getClassMethod($cur, $mName);
                        }
                    }
                    if ($implMethod !== null && !$this->checkMethodSignatureMatch($ifaceMethod, $implMethod)) {
                        trigger_error(
                            "Class {$node->name} method {$mName}() signature mismatch with interface {$ifaceName}",
                            E_USER_WARNING
                        );
                    }
                }
            }
        }

        // 4. 抽象方法实现检查（Task 2.10.2）
        if ($node->parentName !== null) {
            $parentCN = $this->symbols->resolveClass($node->parentName);
            if ($parentCN !== null) {
                $abstractMethods = $this->collectAbstractMethods($parentCN);
                foreach ($abstractMethods as $mName => $_) {
                    if (!isset($implementedMethods[$mName])) {
                        trigger_error(
                            "Class {$node->name} must implement abstract method {$mName}()",
                            E_USER_WARNING
                        );
                    }
                }
            }
        }
    }

    private function checkMethod(MethodNode $node): void
    {
        $savedStatic = $this->inStaticContext;
        $this->inStaticContext = $node->isStatic;

        $this->pushScope();

        // 非静态方法声明 $this（统一为 object 类型，不创建用户 Type idx）
        if (!$node->isStatic && $this->currentClassCName !== null) {
            $this->declareVar('this', Type::$object);
        }

        // 声明参数到作用域
        foreach ($node->params as $param) {
            $this->checkParam($param);
        }

        // 遍历方法体（abstract 方法的 body 为 null）
        if ($node->body !== null) {
            foreach ($node->body as $stmt) {
                $this->checkStmt($stmt);
            }
        }

        $this->popScope();
        $this->inStaticContext = $savedStatic;
    }

    private function checkFunction(FunctionNode $node): void
    {
        // C 函数签名声明无函数体，无需检查（类型信息已在 prescan 中注册）
        if ($node->isCDeclaration) return;

        $savedNamespace = $this->currentNamespace;
        $this->currentNamespace = $node->namespace;

        $this->pushScope();

        // 声明参数到作用域（独立函数无 $this）
        foreach ($node->params as $param) {
            $this->checkParam($param);
        }

        // 遍历函数体
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }

        $this->popScope();
        $this->currentNamespace = $savedNamespace;
    }

    private function checkParam(ParamNode $node): void
    {
        $name = ltrim($node->name, '$');
        // 可变参数：在函数体内作为 t_array* 访问（元素类型由 type 字段追踪）
        //   `...$args`（type='array'）或 `int ...$nums`（type='int'）均映射为 array<mixed>
        //   （与通用 array 参数一致，保持 PHP 动态语义）
        if ($node->isVariadic) {
            $this->declareVar($name, Type::array(Type::$mixed));
            return;
        }
        // Task 2.13: 无类型标注的参数（type=''）经 resolveTypeFromString 返回 Type::$mixed，
        //   即未类型化参数默认为 mixed（动态类型语义）
        $type = $this->resolveTypeFromString($node->type);
        $this->declareVar($name, $type);

        if ($node->default !== null) {
            $savedExpected = $this->expectedType;
            $this->expectedType = $type;
            $this->checkExpr($node->default);
            $this->expectedType = $savedExpected;
        }
    }

    private function checkPropertyDecl(PropertyDeclNode $node): void
    {
        // 骨架：检查默认值
        if ($node->default !== null) {
            $this->checkExpr($node->default);
        }
        // TODO Task 2.2: 属性类型注册到符号表
    }

    private function checkConst(ConstNode $node): void
    {
        // 骨架：检查常量值表达式
        if ($node->value !== null) {
            $this->checkExpr($node->value);
        }
    }

    private function checkEnum(EnumNode $node): void
    {
        // 验证 case 值：类型匹配 backing type，且 backed value 唯一
        $backing = $node->backingType;
        $seenValues = [];
        foreach ($node->cases as $case) {
            $v = $case->value;
            // 类型匹配检查
            if ($backing === 'int' && !($v instanceof IntLiteralExpr)) {
                trigger_error(sprintf(
                    "Enum %s has int backing type but case %s has non-integer value",
                    $node->name, $case->name
                ), E_ERROR);
            } elseif ($backing === 'string' && !($v instanceof StringLiteralExpr)) {
                trigger_error(sprintf(
                    "Enum %s has string backing type but case %s has non-string value",
                    $node->name, $case->name
                ), E_ERROR);
            }
            // backed value 唯一性检查
            $key = match (true) {
                $v instanceof IntLiteralExpr => "i:{$v->value}",
                $v instanceof StringLiteralExpr => "s:{$v->value}",
                default => null,
            };
            if ($key !== null) {
                if (isset($seenValues[$key])) {
                    trigger_error(sprintf(
                        "Enum %s case %s has duplicate backed value with case %s",
                        $node->name, $case->name, $seenValues[$key]
                    ), E_ERROR);
                } else {
                    $seenValues[$key] = $case->name;
                }
            }
        }

        // 遍历枚举方法和常量
        foreach ($node->methods as $method) {
            $this->checkMethod($method);
        }
        foreach ($node->classConsts as $const) {
            $this->checkConst($const);
        }
    }

    // ──────────────────────────────────────────────────────────
    // checkStmt 分发
    // ──────────────────────────────────────────────────────────

    private function checkStmt(StmtNode $node): void
    {
        // 骨架：通过 instanceof 分发到具体 check*Stmt
        // 实际只递归遍历子表达式，不做实际类型推导
        match (true) {
            $node instanceof EchoStmtNode            => $this->checkEchoStmt($node),
            $node instanceof ReturnStmtNode          => $this->checkReturnStmt($node),
            $node instanceof AssignStmtNode           => $this->checkAssignStmt($node),
            $node instanceof AssignPropStmtNode      => $this->checkAssignPropStmt($node),
            $node instanceof AssignArrayStmtNode      => $this->checkAssignArrayStmt($node),
            $node instanceof AssignArrayPushStmtNode  => $this->checkAssignArrayPushStmt($node),
            $node instanceof ExprStmtNode             => $this->checkExprStmt($node),
            $node instanceof NopStmtNode             => $this->checkNopStmt($node),
            $node instanceof StaticStmtNode           => $this->checkStaticStmt($node),
            $node instanceof ConstStmtNode            => $this->checkConstStmt($node),
            $node instanceof BlockStmtNode            => $this->checkBlockStmt($node),
            $node instanceof IfStmtNode               => $this->checkIfStmt($node),
            $node instanceof WhileStmtNode            => $this->checkWhileStmt($node),
            $node instanceof DoWhileStmtNode          => $this->checkDoWhileStmt($node),
            $node instanceof ListStmtNode            => $this->checkListStmt($node),
            $node instanceof ForStmtNode              => $this->checkForStmt($node),
            $node instanceof ForeachStmtNode          => $this->checkForeachStmt($node),
            $node instanceof SwitchStmtNode           => $this->checkSwitchStmt($node),
            $node instanceof BreakStmtNode            => $this->checkBreakStmt($node),
            $node instanceof GotoStmtNode            => $this->checkGotoStmt($node),
            $node instanceof TryStmtNode             => $this->checkTryStmt($node),
            $node instanceof ThrowStmtNode            => $this->checkThrowStmt($node),
            $node instanceof LabelStmtNode            => $this->checkLabelStmt($node),
            $node instanceof ContinueStmtNode         => $this->checkContinueStmt($node),
            $node instanceof DeferStmtNode           => $this->checkDeferStmt($node),
            default                                  => null, // 未识别的语句：骨架阶段静默跳过
        };
    }

    // ──────────────────────────────────────────────────────────
    // checkExpr 分发
    // ──────────────────────────────────────────────────────────

    private function checkExpr(ExprNode $node): void
    {
        // 骨架：通过 instanceof 分发到具体 check*Expr
        // 实际只递归遍历子表达式，不做实际类型推导
        match (true) {
            $node instanceof StringLiteralExpr    => $this->checkStringLiteral($node),
            $node instanceof IntLiteralExpr       => $this->checkIntLiteral($node),
            $node instanceof FloatLiteralExpr    => $this->checkFloatLiteral($node),
            $node instanceof BoolLiteralExpr      => $this->checkBoolLiteral($node),
            $node instanceof NullLiteralExpr      => $this->checkNullLiteral($node),
            $node instanceof MagicConstExpr      => $this->checkMagicConst($node),
            $node instanceof ArrayLiteralExpr    => $this->checkArrayLiteral($node),
            $node instanceof VariableExpr         => $this->checkVariable($node),
            $node instanceof BinaryExpr           => $this->checkBinary($node),
            $node instanceof TernaryExpr          => $this->checkTernary($node),
            $node instanceof NullCoalesceExpr     => $this->checkNullCoalesce($node),
            $node instanceof MatchExpr            => $this->checkMatchExpr($node),
            $node instanceof CallExpr             => $this->checkCall($node),
            $node instanceof CastExpr             => $this->checkCast($node),
            $node instanceof NewExpr              => $this->checkNew($node),
            $node instanceof ClosureExpr          => $this->checkClosure($node),
            $node instanceof YieldExpr            => $this->checkYieldExpr($node),
            $node instanceof YieldFromExpr       => $this->checkYieldFromExpr($node),
            $node instanceof UnaryExpr           => $this->checkUnary($node),
            $node instanceof PostfixExpr          => $this->checkPostfix($node),
            $node instanceof CompoundAssignExpr   => $this->checkCompoundAssign($node),
            $node instanceof ArrayAccessExpr      => $this->checkArrayAccess($node),
            $node instanceof ArrayAppendExpr      => $this->checkArrayAppend($node),
            $node instanceof PropertyAccessExpr   => $this->checkPropertyAccess($node),
            $node instanceof EnumAccessExpr       => $this->checkEnumAccess($node),
            $node instanceof ThrowExprNode        => $this->checkThrowExpr($node),
            $node instanceof PipeExpr             => $this->checkPipeExpr($node),
            $node instanceof PlaceholderExpr      => $this->checkPlaceholderExpr($node),
            $node instanceof OrBlockExpr          => $this->checkOrBlock($node),
            default                               => null, // 未识别的表达式：骨架阶段静默跳过
        };
    }

    // ──────────────────────────────────────────────────────────
    // 作用域管理
    // ──────────────────────────────────────────────────────────

    private function pushScope(): void
    {
        $this->scopeStack[] = $this->scope;
    }

    private function popScope(): void
    {
        $this->scope = array_pop($this->scopeStack) ?? [];
    }

    private function declareVar(string $name, Type $type): void
    {
        // $name 不含 $ 前缀
        $this->scope[$name] = $type;
    }

    private function lookupVar(string $name): ?Type
    {
        // $name 不含 $ 前缀
        return $this->scope[$name] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // 类型解析辅助
    // ──────────────────────────────────────────────────────────

    /**
     * 根据类型名字符串解析为 Type 对象
     * 优先从符号表查询，未知类型回退到 mixed
     * 支持：?T（可选）、array<T>（泛型数组）、T|Exception（结果类型）、T*（指针）
     */
    private function resolveTypeFromString(string $name): Type
    {
        $name = trim($name);
        // Task 2.13: 无类型标注（''）或 mixed 类型均返回 Type::$mixed
        //   无类型标注的参数/返回值默认为 mixed（动态类型语义）
        if ($name === '' || $name === 'mixed') {
            return Type::$mixed;
        }

        // 处理 T|Exception 结果类型（|Exception 后缀为文档提示，C 代码仅生成 T）
        $pipePos = strpos($name, '|Exception');
        if ($pipePos !== false) {
            $base = $this->resolveTypeFromString(substr($name, 0, $pipePos));
            return Type::result($base);
        }

        // 处理 ?T 可选类型
        if (str_starts_with($name, '?')) {
            $base = $this->resolveTypeFromString(substr($name, 1));
            return Type::option($base);
        }

        // 处理 array<T> 泛型数组语法
        // Task 2.13: array<mixed> 生成 Type::array(Type::$mixed)，元素类型为 mixed，
        //   数组访问时返回 mixed（万能数组语义，不应用元素类型优化）
        if (str_starts_with($name, 'array<') && str_ends_with($name, '>')) {
            $elemTypeName = substr($name, 6, -1);
            $elemType = $this->resolveTypeFromString($elemTypeName);
            return Type::array($elemType);
        }

        // 通用 array（无泛型参数）→ array<mixed>
        // 保持 PHP 友好性：未注解的 array 参数/变量默认为 array<mixed>（万能数组）
        if ($name === 'array') {
            return Type::array(Type::$mixed);
        }

        // 处理 C.X* / C.X** 指针类型：剥离尾部 * 递归解析基础类型，再加指针标志
        //   例如 C.Point* → 解析 C.Point 得到基础 Type，再 Type::pointer() 包装
        //   仅对已注册的 C 类型生效，避免误将未注册的 X* 当作指针
        if (str_ends_with($name, '*') && str_starts_with($name, 'C.')) {
            $baseName = rtrim($name, '*');
            $baseType = $this->symbols->lookupType($baseName);
            if ($baseType !== null) {
                return Type::pointer($baseType);
            }
        }

        // 内置/用户类型查询
        $t = $this->symbols->lookupType($name);
        if ($t !== null) {
            return $t;
        }

        // 尝试作为类名查询（短名可能已在符号表注册）
        $cName = $this->symbols->resolveClass($name);
        if ($cName !== null) {
            // 类类型统一用 Type::$object，不创建用户 Type idx
            return Type::$object;
        }

        // 未知类型回退到 mixed
        return Type::$mixed;
    }

    /**
     * 计算两个类型的公共类型
     * 用于三元/null 合并/match 等需要统一多分支类型的场景
     * 两侧均为 null 时回退到 mixed；两侧相等时返回该类型；否则回退到 mixed
     */
    private function commonType(?Type $a, ?Type $b): Type
    {
        if ($a === null && $b === null) {
            return Type::$mixed;
        }
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        if ($a->equals($b)) {
            return $a;
        }
        return Type::$mixed;
    }

    /**
     * 计算多个类型的公共类型
     * 空列表回退到 mixed；全部相等时返回该类型；否则回退到 mixed
     */
    private function commonTypeAll(array $types): Type
    {
        if (empty($types)) {
            return Type::$mixed;
        }
        $first = $types[0];
        foreach ($types as $t) {
            if (!$t->equals($first)) {
                return Type::$mixed;
            }
        }
        return $first;
    }

    /**
     * 根据类的 PHP 名构造 C 名
     * 优先从符号表查询，回退到默认命名规则
     */
    private function resolveClassCName(string $name): string
    {
        $fqcn = $this->resolveTypeRef($name);
        $resolved = $this->symbols->resolveClass($fqcn);
        if ($resolved !== null) {
            return $resolved;
        }
        // 默认规则：与 CodeGenerator 保持一致
        $clean = ltrim(str_replace('\\', '_', $fqcn), '\\');
        return 'tphp_class_' . $clean;
    }

    /**
     * 解析类型引用为 FQCN（不含前导反斜杠）
     *   - 已限定名（含 \）：直接去前导 \ 返回
     *   - 短名：先查 use 导入表；找不到则按当前命名空间限定
     *
     * 用于 TypeChecker 内部需要把短类名解析为 FQCN 的场景。
     * Parser 在 parse 阶段已对大部分类引用调用 resolveClassName 解析；
     * TypeChecker 通过此方法处理仍未限定的引用（如保留为短名的类型注解）。
     */
    private function resolveTypeRef(string $name): string
    {
        // 已限定（含命名空间分隔符）→ 去前导 \ 返回
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }
        // use 导入表命中
        if (isset($this->useImports[$name])) {
            return $this->useImports[$name];
        }
        // 当前命名空间限定
        return ($this->currentNamespace !== '')
            ? $this->currentNamespace . '\\' . $name
            : $name;
    }

    /**
     * 将 C 类型字符串转换为 Type 对象
     * 同时支持 C 类型名（t_int, t_string）和 PHP 类型简写（int, string）
     */
    private function resolveCTypeToType(string $cType): Type
    {
        $cType = trim($cType);
        if ($cType === '') {
            return Type::$mixed;
        }

        // 去掉指针符号
        $isPointer = str_ends_with($cType, '*');
        $base = rtrim($cType, '* ');
        $base = trim($base);

        if ($base === '') {
            return Type::$mixed;
        }

        // 内置类型映射（同时支持 C 类型名和 PHP 简写）
        $type = match (true) {
            $base === 'void' => Type::$void,
            in_array($base, [
                'int', 't_int', 'int8_t', 'int16_t', 'int32_t', 'int64_t',
                'uint8_t', 'uint16_t', 'uint32_t', 'uint64_t',
                'size_t', 'ssize_t', 'long', 'short', 'char',
            ], true) => Type::$int,
            in_array($base, ['float', 't_float', 'double'], true) => Type::$float,
            in_array($base, ['bool', 't_bool'], true) => Type::$bool,
            in_array($base, ['string', 't_string'], true) => Type::$string,
            in_array($base, ['array', 't_array'], true) => Type::$array,
            in_array($base, ['mixed', 't_var'], true) => Type::$mixed,
            in_array($base, ['callback', 't_callback'], true) => Type::$callback,
            $base === 'null' => Type::$null,
            in_array($base, ['object', 't_object'], true) => Type::$object,
            $base === 'resource' => Type::$mixed,
            $base === 'never' => Type::$never,
            default => null,
        };

        if ($type === null) {
            // tphp_class_* / tphp_enum_* 类型的 C 字符串 → Type::$object
            // （不创建用户 Type idx，避免 idx 冲突；不加指针包装）
            if (str_starts_with($base, 'tphp_class_') || str_starts_with($base, 'tphp_enum_')) {
                return Type::$object;
            }
            // 尝试从类型表查询（类类型如 tphp_class_Foo）
            $t = $this->symbols->lookupType($base);
            if ($t !== null) {
                $type = $t;
            } else {
                // 尝试作为类名查询
                $cName = $this->symbols->resolveClass($base);
                if ($cName !== null) {
                    // 类类型统一用 Type::$object，不创建用户 Type idx
                    return Type::$object;
                }
            }
            // 仍未识别，回退到 object（C 结构体/类指针等）
            if ($type === null) {
                $type = Type::$object;
            }
        }

        // 处理指针标志（数组类型不加指针，数组本身就是指针语义）
        if ($isPointer && !$type->isPointer() && !$type->isArray()) {
            $type = Type::pointer($type);
        }

        return $type;
    }

    /**
     * 从 Type 对象获取对应的 C 类名（如 tphp_class_Foo / tphp_enum_Color）
     * 用于方法/属性查找，返回 null 表示无法确定
     */
    private function typeToCName(Type $type): ?string
    {
        $sym = $this->symbols->typeTable()->getByIdx($type->idx());
        if ($sym !== null) {
            $cName = rtrim($sym->cType, '* ');
            if (str_starts_with($cName, 'tphp_class_') || str_starts_with($cName, 'tphp_enum_')) {
                return $cName;
            }
        }
        return null;
    }

    /**
     * 从 CallExpr 推导 C 函数名（与 CodeGenerator.funcCNameFromCall 格式一致）
     * 全局函数: tphp_fn_functionName
     * 命名空间函数: tphp_na_Namespace_tphp_fn_functionName
     */
    private function funcCNameFromCall(CallExpr $node): string
    {
        if ($node->callee !== null) {
            return ''; // 方法调用不在此
        }
        $pos = strrpos($node->name, '\\');
        if ($pos !== false) {
            $ns = substr($node->name, 0, $pos);
            $fn = substr($node->name, $pos + 1);
            return 'tphp_na_' . str_replace('\\', '_', $ns) . '_tphp_fn_' . $fn;
        }
        return 'tphp_fn_' . $node->name;
    }

    /**
     * 沿父类链查找声明方法的类 C 名
     * 返回 '' 表示未找到
     */
    private function resolveMethodClass(string $cn, string $method): string
    {
        $cur = $cn;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            if ($this->symbols->getClassMethod($cur, $method) !== null) {
                return $cur;
            }
        }
        return '';
    }

    /**
     * 沿父类链查找属性类型（C 类型字符串）
     * 返回 null 表示未找到
     */
    private function resolveClassPropTypeAlongParent(string $cn, string $prop): ?string
    {
        $pt = $this->symbols->getClassPropType($cn, $prop);
        if ($pt !== null) {
            return $pt;
        }
        $cur = $cn;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            $pt = $this->symbols->getClassPropType($cur, $prop);
            if ($pt !== null) {
                return $pt;
            }
        }
        return null;
    }

    /**
     * 收集类及其父类链中所有 abstract 方法
     * 返回 array<string,MethodInfo> 方法名 => MethodInfo
     */
    private function collectAbstractMethods(string $cName): array
    {
        $result = [];
        $cur = $cName;
        $visited = [];
        while ($cur !== '' && !isset($visited[$cur])) {
            $visited[$cur] = true;
            $c = $this->symbols->getClass($cur);
            if ($c === null) break;
            foreach ($c->methods as $name => $m) {
                if ($m->isAbstract && !isset($result[$name])) {
                    $result[$name] = $m;
                }
            }
            $cur = $c->parent;
        }
        return $result;
    }

    /**
     * 收集接口及其父接口链中所有方法
     * 返回 array<string,MethodInfo> 方法名 => MethodInfo
     */
    private function collectInterfaceMethods(string $cName): array
    {
        $result = [];
        $visited = [];
        $queue = [$cName];
        while (!empty($queue)) {
            $cur = array_shift($queue);
            if ($cur === '' || isset($visited[$cur])) continue;
            $visited[$cur] = true;
            $c = $this->symbols->getClass($cur);
            if ($c === null) continue;
            foreach ($c->methods as $name => $m) {
                if (!isset($result[$name])) {
                    $result[$name] = $m;
                }
            }
            // 接口 extends（存储在 implements 字段）
            foreach ($c->implements as $parentIface) {
                $parentCN = $this->symbols->resolveClass($parentIface);
                if ($parentCN !== null) {
                    $queue[] = $parentCN;
                }
            }
        }
        return $result;
    }

    /**
     * 检查两个方法签名是否兼容（参数数量 + 返回类型）
     */
    private function checkMethodSignatureMatch(MethodInfo $a, MethodInfo $b): bool
    {
        if ($a->totalParams !== $b->totalParams) {
            return false;
        }
        if ($a->retType !== $b->retType) {
            return false;
        }
        return true;
    }

    /**
     * 收集类及其父类链中所有已实现（非 abstract）方法名
     * 返回 array<string,true> 方法名 => true
     */
    private function collectImplementedMethodNames(string $cName): array
    {
        $result = [];
        $cur = $cName;
        $visited = [];
        while ($cur !== '' && !isset($visited[$cur])) {
            $visited[$cur] = true;
            $c = $this->symbols->getClass($cur);
            if ($c === null) break;
            foreach ($c->methods as $name => $m) {
                if (!$m->isAbstract) {
                    $result[$name] = true;
                }
            }
            $cur = $c->parent;
        }
        return $result;
    }

    /**
     * 推导方法调用的返回类型
     * 处理实例调用、静态调用、闭包调用、枚举方法调用
     */
    private function inferMethodCallReturnType(CallExpr $node): Type
    {
        $callee = $node->callee;
        $methodName = $node->name;

        // 1. 闭包调用：$closure() — callee 是 VariableExpr($var)
        if ($callee instanceof VariableExpr && str_starts_with($callee->name, '$')) {
            $varName = ltrim($callee->name, '$');
            // 检查是否为闭包变量
            $closureName = $this->symbols->getVarClosure($varName);
            if ($closureName !== null) {
                $sig = $this->symbols->getClosureSig($closureName);
                if ($sig !== null && isset($sig['ret'])) {
                    return $this->resolveCTypeToType($sig['ret']);
                }
            }
            // 回退：查 varClosureExprs 表（TypeChecker 阶段 CodeGenerator 尚未注册闭包签名）
            if (isset($this->varClosureExprs[$varName])) {
                $closureExpr = $this->varClosureExprs[$varName];
                if ($closureExpr->returnType !== '') {
                    return $this->resolveTypeFromString($closureExpr->returnType);
                }
            }
        }

        // 2. 静态方法调用：ClassName::method() — callee 是 VariableExpr(ClassName)（无 $ 前缀）
        if ($callee instanceof VariableExpr && !str_starts_with($callee->name, '$')) {
            $rawName = $callee->name;
            // self/static → 当前类
            if ($rawName === 'self' || $rawName === 'static') {
                if ($this->currentClassCName !== null) {
                    $m = $this->symbols->getClassMethod($this->currentClassCName, $methodName);
                    if ($m !== null) {
                        return $this->resolveCTypeToType($m->retType);
                    }
                    $found = $this->resolveMethodClass($this->currentClassCName, $methodName);
                    if ($found !== '') {
                        $m = $this->symbols->getClassMethod($found, $methodName);
                        if ($m !== null) {
                            return $this->resolveCTypeToType($m->retType);
                        }
                    }
                }
                return Type::$mixed;
            }
            // parent → 父类
            if ($rawName === 'parent') {
                if ($this->currentClassCName !== null) {
                    $parent = $this->symbols->getClassParent($this->currentClassCName);
                    if ($parent !== '') {
                        $m = $this->symbols->getClassMethod($parent, $methodName);
                        if ($m !== null) {
                            return $this->resolveCTypeToType($m->retType);
                        }
                    }
                }
                return Type::$mixed;
            }
            // 枚举静态调用：EnumName::method()
            $enumCName = $this->symbols->resolveEnumCName($rawName);
            if ($enumCName !== null) {
                $m = $this->symbols->getEnumMethodByCName($enumCName, $methodName);
                if ($m !== null) {
                    return $this->enumMethodReturnType($methodName, $m, $enumCName);
                }
                return Type::$mixed;
            }
            // 类名静态调用：ClassName::method()
            $cName = $this->symbols->resolveClass($rawName);
            if ($cName !== null) {
                $m = $this->symbols->getClassMethod($cName, $methodName);
                if ($m !== null) {
                    return $this->resolveCTypeToType($m->retType);
                }
                $found = $this->resolveMethodClass($cName, $methodName);
                if ($found !== '') {
                    $m = $this->symbols->getClassMethod($found, $methodName);
                    if ($m !== null) {
                        return $this->resolveCTypeToType($m->retType);
                    }
                }
                return Type::$mixed;
            }
            return Type::$mixed;
        }

        // 3. 枚举实例方法调用：Color::RED->method() — callee 是 EnumAccessExpr
        if ($callee instanceof EnumAccessExpr) {
            $enumCName = $this->symbols->getEnumCName($callee->enumName);
            if ($enumCName !== null) {
                $m = $this->symbols->getEnumMethodByCName($enumCName, $methodName);
                if ($m !== null) {
                    return $this->enumMethodReturnType($methodName, $m, $enumCName);
                }
            }
            return Type::$mixed;
        }

        // 4. $this->method() — 特殊处理，使用当前类
        if ($callee instanceof VariableExpr && $callee->name === '$this') {
            if ($this->currentClassCName !== null) {
                $m = $this->symbols->getClassMethod($this->currentClassCName, $methodName);
                if ($m !== null) {
                    return $this->resolveCTypeToType($m->retType);
                }
                $found = $this->resolveMethodClass($this->currentClassCName, $methodName);
                if ($found !== '') {
                    $m = $this->symbols->getClassMethod($found, $methodName);
                    if ($m !== null) {
                        return $this->resolveCTypeToType($m->retType);
                    }
                }
            }
            return Type::$mixed;
        }

        // 5. 其他实例方法调用：$obj->method() 或链式调用 func()->method()
        //    使用 callee 的 inferredType 获取对象类型
        $objType = $callee->inferredType;
        if ($objType === null) {
            return Type::$mixed;
        }

        // 优先使用 varClassCNames 表（$var = new X() 时记录的具体类 C 名）
        //   checkNew 将所有 new X() 标记为 Type::$object，typeToCName 无法解析，
        //   通过 varClassCNames 表可恢复具体类信息，正确查找方法返回类型
        $cName = null;
        if ($callee instanceof VariableExpr) {
            $varName = ltrim($callee->name, '$');
            if (isset($this->varClassCNames[$varName])) {
                $cName = $this->varClassCNames[$varName];
            }
        }
        // 回退：通过 inferredType 解析类 C 名
        if ($cName === null) {
            $cName = $this->typeToCName($objType);
        }

        if ($cName !== null) {
            // 枚举方法
            $enumCName = $this->symbols->resolveEnumCName($cName);
            if ($enumCName !== null) {
                $m = $this->symbols->getEnumMethodByCName($enumCName, $methodName);
                if ($m !== null) {
                    return $this->enumMethodReturnType($methodName, $m, $enumCName);
                }
            }
            // 类方法
            $m = $this->symbols->getClassMethod($cName, $methodName);
            if ($m !== null) {
                return $this->resolveCTypeToType($m->retType);
            }
            // 沿父类链查找
            $found = $this->resolveMethodClass($cName, $methodName);
            if ($found !== '') {
                $m = $this->symbols->getClassMethod($found, $methodName);
                if ($m !== null) {
                    return $this->resolveCTypeToType($m->retType);
                }
            }
        }

        return Type::$mixed;
    }

    /**
     * 推导枚举方法调用的返回类型。
     * 自动静态方法 cases()/from()/tryFrom() 需特殊处理：
     *   - from($v)    → enum 实例类型
     *   - tryFrom($v) → ?enum（nullable enum 实例）
     *   - cases()     → array<enum>（带元素类型的数组）
     * 用户方法直接从 cType 转换。
     */
    private function enumMethodReturnType(string $methodName, MethodInfo $m, ?string $enumCName = null): Type
    {
        // 自动静态方法（cases/from/tryFrom）：处理 nullable 和数组元素类型
        if ($m->isStatic && in_array($methodName, ['cases', 'from', 'tryFrom'], true)) {
            // enum 实例类型：from/tryFrom 的 retType 已是 'tphp_enum_*'，
            //   但 cases 的 retType 是 't_array*'，需用 enumCName 构造 enum 类型
            if ($methodName === 'cases' && $enumCName !== null) {
                $enumType = $this->resolveCTypeToType($enumCName . '*');
                return Type::array($enumType);
            }
            $enumType = $this->resolveCTypeToType($m->retType);
            return match ($methodName) {
                'from' => $enumType,
                'tryFrom' => Type::option($enumType),
                'cases' => Type::array($enumType),
            };
        }
        // 用户方法（实例/静态）：直接从 cType 转换
        return $this->resolveCTypeToType($m->retType);
    }

    /**
     * 推导全局函数调用的返回类型
     * 查询顺序：内置函数表 → SymbolTable → 命名空间 fallback → mixed
     */
    private function inferGlobalCallReturnType(CallExpr $node): Type
    {
        $name = $node->name;

        // 前缀规则：is_* / ctype_* → bool
        if (str_starts_with($name, 'is_') || str_starts_with($name, 'ctype_')) {
            return Type::$bool;
        }

        // 命名空间函数：先查 NS 下的 C 函数名
        $fnCName = $this->funcCNameFromCall($node);
        if ($fnCName !== '' && $this->symbols->getFuncRet($fnCName) !== null) {
            return $this->resolveCTypeToType($this->symbols->getFuncRet($fnCName));
        }

        // 内置函数返回类型表
        if (isset(self::$builtinRetTypes[$name])) {
            return $this->resolveCTypeToType(self::$builtinRetTypes[$name]);
        }

        // 命名空间 fallback：NS\func() 若 NS 下未定义，剥掉前缀查全局
        if (($pos = strrpos($name, '\\')) !== false) {
            $baseName = substr($name, $pos + 1);
            // 前缀规则 fallback
            if (str_starts_with($baseName, 'is_') || str_starts_with($baseName, 'ctype_')) {
                return Type::$bool;
            }
            // 查全局函数 C 名
            $globalCName = 'tphp_fn_' . $baseName;
            if ($this->symbols->getFuncRet($globalCName) !== null) {
                return $this->resolveCTypeToType($this->symbols->getFuncRet($globalCName));
            }
            // 查内置函数表
            if (isset(self::$builtinRetTypes[$baseName])) {
                return $this->resolveCTypeToType(self::$builtinRetTypes[$baseName]);
            }
        }

        // Fallback
        return Type::$mixed;
    }

    // ──────────────────────────────────────────────────────────
    // 语句检查方法（check*Stmt）
    // 骨架阶段：仅递归遍历子表达式
    // ──────────────────────────────────────────────────────────

    private function checkEchoStmt(EchoStmtNode $node): void
    {
        foreach ($node->exprs as $expr) {
            $this->checkExpr($expr);
        }
    }

    private function checkReturnStmt(ReturnStmtNode $node): void
    {
        if ($node->expr !== null) {
            $this->checkExpr($node->expr);
        }
    }

    private function checkAssignStmt(AssignStmtNode $node): void
    {
        // 检查右式，如有显式类型标注则设为 expectedType
        $savedExpected = $this->expectedType;
        $declType = null;
        if ($node->type !== null) {
            $declType = $this->resolveTypeFromString($node->type);
            $this->expectedType = $declType;
        }
        $this->checkExpr($node->expr);
        $this->expectedType = $savedExpected;

        // 确定变量类型：有标注用标注，否则用右式 inferredType，再否则 mixed
        if ($declType !== null) {
            $varType = $declType;
        } else {
            $varType = $node->expr->inferredType ?? Type::$mixed;
        }

        // Task 2.13: mixed 动态类型语义 — 变量重赋值时直接用 declareVar 覆盖类型，
        //   不报类型错误。即使原类型为 int 重赋值为 string 也允许（PHP 动态语义）
        // 声明变量到作用域（变量名 ltrim '$'）
        $varName = ltrim($node->varName, '$');
        $this->declareVar($varName, $varType);

        // 跟踪 new X() 赋值的变量 → 类 C 名映射，供实例方法调用类型推导使用
        //   checkNew 将所有 new X() 标记为 Type::$object，丢失了具体类信息；
        //   此处补充记录类 C 名，inferMethodCallReturnType 通过此表查找方法
        if ($node->expr instanceof NewExpr) {
            $cName = $this->resolveClassCName($node->expr->className);
            if ($cName !== '' && $this->symbols->hasClass($cName)) {
                $this->varClassCNames[$varName] = $cName;
            } else {
                // 变量被重赋值为非 new 表达式，清除映射
                unset($this->varClassCNames[$varName]);
            }
        } else {
            // 变量被重赋值为非 new 表达式，清除映射
            unset($this->varClassCNames[$varName]);
        }

        // 跟踪闭包赋值：$cb = function(): T {...} → 记录 ClosureExpr 节点，
        //   供 $cb() 调用时直接从 AST returnType 字段推导返回类型
        //   （CodeGenerator 的 addVarClosure 发生在 TypeChecker 之后，此表填补空窗）
        if ($node->expr instanceof ClosureExpr) {
            $this->varClosureExprs[$varName] = $node->expr;
        } else {
            unset($this->varClosureExprs[$varName]);
        }
    }

    private function checkAssignPropStmt(AssignPropStmtNode $node): void
    {
        $this->checkPropertyAccess($node->target);
        $this->checkExpr($node->value);
    }

    private function checkAssignArrayStmt(AssignArrayStmtNode $node): void
    {
        $this->checkArrayAccess($node->target);
        $this->checkExpr($node->value);
    }

    private function checkAssignArrayPushStmt(AssignArrayPushStmtNode $node): void
    {
        $this->checkExpr($node->target);
        $this->checkExpr($node->value);
        // 简化实现：只检查不强制更新数组元素类型（避免复杂的状态管理）
        // 如果数组变量未声明，则声明为万能数组
        if ($node->target instanceof VariableExpr) {
            $varName = ltrim($node->target->name, '$');
            if ($this->lookupVar($varName) === null) {
                $this->declareVar($varName, Type::$array);
            }
        }
    }

    private function checkExprStmt(ExprStmtNode $node): void
    {
        $this->checkExpr($node->expr);
    }

    private function checkNopStmt(NopStmtNode $node): void
    {
        // 空语句，无操作
    }

    private function checkStaticStmt(StaticStmtNode $node): void
    {
        $varType = null;
        if ($node->type !== null) {
            $varType = $this->resolveTypeFromString($node->type);
        }
        if ($node->init !== null) {
            $this->checkExpr($node->init);
            if ($varType === null) {
                $varType = $node->init->inferredType ?? Type::$mixed;
            }
        }
        // 声明静态变量到作用域
        $varName = ltrim($node->varName, '$');
        $this->declareVar($varName, $varType ?? Type::$mixed);
    }

    private function checkConstStmt(ConstStmtNode $node): void
    {
        $this->checkExpr($node->value);
    }

    private function checkBlockStmt(BlockStmtNode $node): void
    {
        $this->pushScope();
        foreach ($node->stmts as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
    }

    private function checkIfStmt(IfStmtNode $node): void
    {
        $this->checkExpr($node->condition);
        $this->pushScope();
        foreach ($node->thenBody as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
        foreach ($node->elseifs as $elseif) {
            $this->checkExpr($elseif->condition);
            $this->pushScope();
            foreach ($elseif->body as $stmt) {
                $this->checkStmt($stmt);
            }
            $this->popScope();
        }
        if (!empty($node->elseBody)) {
            $this->pushScope();
            foreach ($node->elseBody as $stmt) {
                $this->checkStmt($stmt);
            }
            $this->popScope();
        }
    }

    private function checkWhileStmt(WhileStmtNode $node): void
    {
        $this->checkExpr($node->condition);
        $this->pushScope();
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
    }

    private function checkDoWhileStmt(DoWhileStmtNode $node): void
    {
        $this->pushScope();
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
        $this->checkExpr($node->condition);
    }

    private function checkListStmt(ListStmtNode $node): void
    {
        $this->checkExpr($node->expr);

        // 从右式数组类型推导元素类型
        $arrType = $node->expr->inferredType;
        $elemType = null;
        if ($arrType !== null && $arrType->isArray() && $arrType->elemType() !== null) {
            $elemType = $arrType->elemType();
        }
        // 元素类型未知时回退到 mixed
        $varType = $elemType ?? Type::$mixed;

        // 位置解构：vars 中 null=跳过, string=变量名, ListStmtNode=嵌套解构
        foreach ($node->vars as $var) {
            if ($var === null) {
                continue;
            }
            if (is_string($var)) {
                $this->declareVar(ltrim($var, '$'), $varType);
            } elseif ($var instanceof ListStmtNode) {
                // 嵌套解构：递归处理（以当前元素类型作为右式类型）
                $var->expr->inferredType = $varType;
                $this->checkListStmt($var);
            }
        }

        // 键名解构：keyedEntries = [[key=>string, var=>string], ...]
        foreach ($node->keyedEntries as $entry) {
            if (isset($entry['var'])) {
                $this->declareVar(ltrim((string)$entry['var'], '$'), $varType);
            }
        }
    }

    private function checkForStmt(ForStmtNode $node): void
    {
        if ($node->init !== null) {
            $this->checkExpr($node->init);
        }
        if ($node->condition !== null) {
            $this->checkExpr($node->condition);
        }
        if ($node->step !== null) {
            $this->checkExpr($node->step);
        }
        $this->pushScope();
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
    }

    private function checkForeachStmt(ForeachStmtNode $node): void
    {
        $this->checkExpr($node->array);

        // 从数组类型推导元素类型
        $arrType = $node->array->inferredType;
        if ($arrType !== null && $arrType->isArray() && $arrType->elemType() !== null) {
            $valueVarType = $arrType->elemType();
        } else {
            // 万能数组或未知类型 → mixed
            $valueVarType = Type::$mixed;
        }

        // key 默认 mixed（可能是 int 或 string）
        $keyVarType = Type::$mixed;

        $this->pushScope();
        $valueName = ltrim($node->valueVar, '$');
        $this->declareVar($valueName, $valueVarType);
        if ($node->keyVar !== null) {
            $keyName = ltrim($node->keyVar, '$');
            $this->declareVar($keyName, $keyVarType);
        }
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
    }

    private function checkSwitchStmt(SwitchStmtNode $node): void
    {
        $this->checkExpr($node->condition);
        foreach ($node->cases as $case) {
            if ($case->value !== null) {
                $this->checkExpr($case->value);
            }
            $this->pushScope();
            foreach ($case->body as $stmt) {
                $this->checkStmt($stmt);
            }
            $this->popScope();
        }
    }

    private function checkBreakStmt(BreakStmtNode $node): void
    {
        // 无子表达式
    }

    private function checkGotoStmt(GotoStmtNode $node): void
    {
        // 无子表达式
    }

    private function checkTryStmt(TryStmtNode $node): void
    {
        $this->pushScope();
        foreach ($node->tryBody as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();

        foreach ($node->catchClauses as $catch) {
            $this->pushScope();
            // catch (Type $var) { body } — 多异常 catch (A | B $var) 时 $var 类型为公共基类 Exception
            $varName = ltrim($catch['var'], '$');
            $ct = $catch['type'];
            if (is_array($ct)) {
                // catch (A | B $e)：AOT 简化为 Exception 基类（与 CodeGenerator 一致）
                $type = $this->resolveTypeFromString('Exception');
            } else {
                $type = $this->resolveTypeFromString($ct);
            }
            $this->declareVar($varName, $type);
            foreach ($catch['body'] as $stmt) {
                $this->checkStmt($stmt);
            }
            $this->popScope();
        }

        if (!empty($node->finallyBody)) {
            $this->pushScope();
            foreach ($node->finallyBody as $stmt) {
                $this->checkStmt($stmt);
            }
            $this->popScope();
        }
    }

    private function checkThrowStmt(ThrowStmtNode $node): void
    {
        $this->checkExpr($node->expr);
    }

    private function checkLabelStmt(LabelStmtNode $node): void
    {
        // 无子表达式
    }

    private function checkContinueStmt(ContinueStmtNode $node): void
    {
        // 无子表达式
    }

    private function checkDeferStmt(DeferStmtNode $node): void
    {
        $this->pushScope();
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();
    }

    // ──────────────────────────────────────────────────────────
    // 表达式检查方法（check*Expr）
    // 骨架阶段：仅递归遍历子表达式，字面量直接赋 inferredType
    // ──────────────────────────────────────────────────────────

    private function checkStringLiteral(StringLiteralExpr $node): void
    {
        $node->inferredType = Type::$string;
    }

    private function checkIntLiteral(IntLiteralExpr $node): void
    {
        $node->inferredType = Type::$int;
    }

    private function checkFloatLiteral(FloatLiteralExpr $node): void
    {
        $node->inferredType = Type::$float;
    }

    private function checkBoolLiteral(BoolLiteralExpr $node): void
    {
        $node->inferredType = Type::$bool;
    }

    private function checkNullLiteral(NullLiteralExpr $node): void
    {
        $node->inferredType = Type::$null;
    }

    private function checkMagicConst(MagicConstExpr $node): void
    {
        // __LINE__ => int，其余魔法常量 => string
        if ($node->name === '__LINE__') {
            $node->inferredType = Type::$int;
        } else {
            // __FILE__/__DIR__/__FUNCTION__/__CLASS__/__METHOD__/__NAMESPACE__/DIRECTORY_SEPARATOR
            $node->inferredType = Type::$string;
        }
    }

    private function checkArrayLiteral(ArrayLiteralExpr $node): void
    {
        $elemTypes = [];
        foreach ($node->entries as $entry) {
            if ($entry->key !== null) {
                $this->checkExpr($entry->key);
            }
            $this->checkExpr($entry->value);
            if ($entry->value->inferredType !== null) {
                $elemTypes[] = $entry->value->inferredType;
            }
        }

        // 泛型数组推导策略：默认 array<mixed>，显式声明才优化
        // - 无 expectedType → array<mixed>（保持 PHP 友好性，现有代码无需修改）
        // - expectedType 为 array<T>（T 非 mixed）→ 用 expectedType，验证元素类型一致
        // - expectedType 为 array<mixed> → 用 array<mixed>
        $expected = $this->expectedType;
        if ($expected !== null && $expected->isArray() && $expected->elemType() !== null) {
            $expectedElem = $expected->elemType();
            // 类型严格性：显式声明 array<T>（T 非 mixed）时，元素类型必须匹配
            if (!$expectedElem->isMixed()) {
                foreach ($elemTypes as $et) {
                    if (!$et->equals($expectedElem)) {
                        $this->error("Cannot assign {$et} to array<{$expectedElem}> element");
                        break;
                    }
                }
            }
            $node->inferredType = $expected;
            return;
        }

        // 默认 array<mixed>（包括无 expectedType 或 expectedType 是通用 array 的情况）
        $node->inferredType = Type::array(Type::$mixed);
    }

    private function checkVariable(VariableExpr $node): void
    {
        $name = ltrim($node->name, '$');
        if ($name === 'this') {
            // $this 统一为 object 类型（不创建用户 Type idx，避免 idx 冲突）
            $node->inferredType = Type::$object;
            return;
        }
        $t = $this->lookupVar($name);
        if ($t !== null) {
            $node->inferredType = $t;
        } else {
            // 未声明变量：不报错，默认 mixed（PHP 动态语义）
            $node->inferredType = Type::$mixed;
        }
    }

    private function checkBinary(BinaryExpr $node): void
    {
        $this->checkExpr($node->left);
        $this->checkExpr($node->right);

        $op = $node->operator;
        if ($op === '=') {
            // for-init 赋值: $i = expr → 声明循环变量类型为右式类型
            //   （Parser 将 for ($i = 0; ...) 的 init 解析为 BinaryExpr($i, '=', 0)）
            if ($node->left instanceof VariableExpr) {
                $varName = ltrim($node->left->name, '$');
                $varType = $node->right->inferredType ?? Type::$mixed;
                $this->declareVar($varName, $varType);
            }
            $node->inferredType = $node->right->inferredType ?? Type::$mixed;
            return;
        }
        if ($op === '.') {
            // 字符串拼接 → string
            $node->inferredType = Type::$string;
        } elseif ($op === '<=>') {
            // 飞船运算符 → int
            $node->inferredType = Type::$int;
        } elseif ($op === '**') {
            // 幂运算 → 左操作数类型
            $node->inferredType = $node->left->inferredType ?? Type::$int;
        } elseif (in_array($op, ['<', '>', '<=', '>=', '==', '!=', '===', '!==', '&&', '||', 'instanceof'], true)) {
            // 比较/逻辑运算符 → bool
            $node->inferredType = Type::$bool;
        } else {
            // 算术/位运算：取左操作数类型，左为 null 时取右，都 null 时默认 int
            //   注意：t_var（mixed）操作数在 CodeGenerator.visitBinary 中按 unwrapIfMixed
            //   解包为标量后运算，结果类型应为解包后的标量类型，而非 t_var。
            //   两侧 t_var 按 t_int 解包；单侧 t_var 按另一侧类型解包。
            $lt = $node->left->inferredType;
            $rt = $node->right->inferredType;
            $lMixed = $lt !== null && $lt->isMixed();
            $rMixed = $rt !== null && $rt->isMixed();
            if ($lMixed && $rMixed) {
                $node->inferredType = Type::$int;
            } elseif ($lMixed) {
                $node->inferredType = $rt ?? Type::$int;
            } elseif ($rMixed) {
                $node->inferredType = $lt ?? Type::$int;
            } else {
                $node->inferredType = $lt ?? $rt ?? Type::$int;
            }
        }
    }

    private function checkTernary(TernaryExpr $node): void
    {
        $this->checkExpr($node->condition);
        $this->checkExpr($node->thenExpr);
        $this->checkExpr($node->elseExpr);

        // 结果类型为 thenExpr 和 elseExpr 的公共类型
        $thenType = $node->thenExpr->inferredType;
        $elseType = $node->elseExpr->inferredType;
        $node->inferredType = $this->commonType($thenType, $elseType);
    }

    private function checkNullCoalesce(NullCoalesceExpr $node): void
    {
        $this->checkExpr($node->left);
        $this->checkExpr($node->right);

        // $a ?? $b：左式非 null 时返回左式类型，否则返回右式类型
        // 简化：取两者公共类型，无公共类型时回退到右式类型（保证非 null 语义）
        $leftType = $node->left->inferredType;
        $rightType = $node->right->inferredType;
        $common = $this->commonType($leftType, $rightType);
        $node->inferredType = $common->isMixed() && $rightType !== null
            ? $rightType
            : $common;
    }

    private function checkMatchExpr(MatchExpr $node): void
    {
        $this->checkExpr($node->condition);
        $armTypes = [];
        foreach ($node->arms as $arm) {
            foreach ($arm->values as $v) {
                $this->checkExpr($v);
            }
            $this->checkExpr($arm->body);
            if ($arm->body->inferredType !== null) {
                $armTypes[] = $arm->body->inferredType;
            }
        }

        // 结果类型为所有分支 body 的公共类型
        $node->inferredType = $this->commonTypeAll($armTypes);
    }

    private function checkCall(CallExpr $node): void
    {
        // 先检查 callee 和参数
        if ($node->callee !== null) {
            $this->checkExpr($node->callee);
        }
        foreach ($node->args as $arg) {
            $this->checkExpr($arg);
        }

        // C 函数调用：优先使用声明的签名推导返回类型
        //   function C.foo(...): C.ret; 声明的返回类型经 cTypeToPHPEquiv 转换为
        //   PHP 等价类型字符串，再由 resolveCTypeToType 映射为 Type 对象。
        //   未声明签名时回退到 mixed（与旧有行为一致，向后兼容）
        if ($node->isRawC) {
            $cFuncInfo = $this->symbols->getCFunction($node->name);
            if ($cFuncInfo !== null) {
                $node->inferredType = $this->resolveCTypeToType($cFuncInfo->retType);
            } else {
                $node->inferredType = Type::$mixed;
            }
            return;
        }

        // 方法调用（callee !== null）
        if ($node->callee !== null) {
            $node->inferredType = $this->inferMethodCallReturnType($node);
            return;
        }

        // 全局函数调用
        $node->inferredType = $this->inferGlobalCallReturnType($node);
    }

    private function checkCast(CastExpr $node): void
    {
        $this->checkExpr($node->expr);

        // 根据 castType 映射到 Type
        $ct = $node->castType;
        $node->inferredType = match (true) {
            in_array($ct, ['int', 'integer'], true) => Type::$int,
            in_array($ct, ['float', 'double'], true) => Type::$float,
            in_array($ct, ['string', 'binary'], true) => Type::$string,
            in_array($ct, ['bool', 'boolean'], true) => Type::$bool,
            $ct === 'array' => Type::$array,
            $ct === 'object' => Type::$object,
            $ct === 'unset' => Type::$null,
            default => Type::$mixed,
        };
    }

    private function checkNew(NewExpr $node): void
    {
        foreach ($node->args as $arg) {
            $this->checkExpr($arg);
        }

        // 接口/抽象类不可实例化（Task 2.9.7 / 2.10.1）
        $cName = $this->resolveClassCName($node->className);
        if ($this->symbols->hasClass($cName)) {
            if ($this->symbols->isClassInterface($cName)) {
                trigger_error(
                    "Cannot instantiate interface {$node->className}",
                    E_USER_WARNING
                );
            } elseif ($this->symbols->isClassAbstract($cName)) {
                trigger_error(
                    "Cannot instantiate abstract class {$node->className}",
                    E_USER_WARNING
                );
            }
        }

        // 类类型统一用 Type::$object（不创建用户 Type idx，避免 idx 冲突）
        $node->inferredType = Type::$object;
    }

    private function checkClosure(ClosureExpr $node): void
    {
        $this->pushScope();
        foreach ($node->params as $param) {
            $this->checkParam($param);
        }
        // 闭包 use 变量：[varName, type] 二元组数组
        //   Parser 留空 type（''），TypeChecker 从外层作用域查变量类型；
        //   若未找到（未声明），回退到 mixed
        foreach ($node->useVars as $useVar) {
            if (is_array($useVar) && isset($useVar[0], $useVar[1])) {
                $varName = ltrim((string)$useVar[0], '$');
                $typeStr = (string)$useVar[1];
                if ($typeStr !== '') {
                    $type = $this->resolveTypeFromString($typeStr);
                } else {
                    // 从外层作用域查找变量类型
                    $outerType = $this->lookupVar($varName);
                    $type = $outerType ?? Type::$mixed;
                }
                $this->declareVar($varName, $type);
            }
        }
        foreach ($node->body as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();

        // 闭包表达式本身类型为 callback
        $node->inferredType = Type::$callback;
    }

    private function checkYieldExpr(YieldExpr $node): void
    {
        if ($node->key !== null) {
            $this->checkExpr($node->key);
        }
        if ($node->value !== null) {
            $this->checkExpr($node->value);
        }
    }

    private function checkYieldFromExpr(YieldFromExpr $node): void
    {
        $this->checkExpr($node->expr);
    }

    private function checkUnary(UnaryExpr $node): void
    {
        $this->checkExpr($node->expr);

        // '!' → bool，'-'/'+'/'~' → 操作数类型
        if ($node->operator === '!') {
            $node->inferredType = Type::$bool;
        } else {
            // '-'/'+': 操作数类型；'~': 按位取反，操作数类型
            $node->inferredType = $node->expr->inferredType ?? Type::$int;
        }
    }

    private function checkPostfix(PostfixExpr $node): void
    {
        $this->checkExpr($node->expr);

        // '++'/'--' → 操作数类型
        $node->inferredType = $node->expr->inferredType ?? Type::$int;
    }

    private function checkCompoundAssign(CompoundAssignExpr $node): void
    {
        $this->checkExpr($node->target);
        $this->checkExpr($node->value);

        // '.=' → string，其他复合赋值 → 目标类型
        if ($node->operator === '.=') {
            $node->inferredType = Type::$string;
        } else {
            $node->inferredType = $node->target->inferredType ?? Type::$int;
        }
    }

    private function checkArrayAccess(ArrayAccessExpr $node): void
    {
        $this->checkExpr($node->array);
        $this->checkExpr($node->index);

        $arrType = $node->array->inferredType;
        if ($arrType === null) {
            $node->inferredType = Type::$mixed;
            return;
        }

        // 数组类型且有元素类型
        if ($arrType->isArray()) {
            $elemType = $arrType->elemType();
            if ($elemType !== null) {
                $node->inferredType = $elemType;
            } else {
                // 万能数组（无元素类型信息）
                $node->inferredType = Type::$mixed;
            }
            return;
        }

        // 字符串访问：string[index] → string
        if ($arrType->equals(Type::$string)) {
            $node->inferredType = Type::$string;
            return;
        }

        // Fallback
        $node->inferredType = Type::$mixed;
    }

    private function checkArrayAppend(ArrayAppendExpr $node): void
    {
        $this->checkExpr($node->target);
        // 数组追加表达式类型 = 目标数组类型
        $node->inferredType = $node->target->inferredType ?? Type::$array;
    }

    private function checkPropertyAccess(PropertyAccessExpr $node): void
    {
        $this->checkExpr($node->object);

        // 静态属性访问: ClassName::$prop 或 self::$prop
        //   object 名无 $ 前缀标识类名/self，property 名以 $ 开头标识静态属性
        if ($node->object instanceof VariableExpr
            && !str_starts_with($node->object->name, '$')
            && str_starts_with($node->property, '$')) {
            $rawName = $node->object->name;
            $cn = ($rawName === 'self' || $rawName === 'static')
                ? ($this->currentClassCName ?? '')
                : ($this->symbols->resolveClass($rawName) ?? $rawName);
            $propName = ltrim($node->property, '$');
            if ($cn !== '') {
                $staticType = $this->symbols->getStaticPropType($cn, $propName);
                if ($staticType !== null) {
                    $node->inferredType = $this->resolveCTypeToType($staticType);
                    return;
                }
            }
            $node->inferredType = Type::$mixed;
            return;
        }

        // 类常量访问: ClassName::CONST / self::CONST / static::CONST / parent::CONST（Task 2.12）
        //   object 名无 $ 前缀标识类名/self，property 名不以 $ 开头标识常量名
        //   注意：AST 不区分 -> 和 ::，此处按"非 $ 开头的属性名 + 非变量 object"判定为常量访问
        if ($node->object instanceof VariableExpr
            && !str_starts_with($node->object->name, '$')
            && $node->object->name !== 'C'
            && !str_starts_with($node->property, '$')) {
            $rawName = $node->object->name;
            if ($rawName === 'self' || $rawName === 'static') {
                $cn = $this->currentClassCName ?? '';
            } elseif ($rawName === 'parent') {
                $cn = ($this->currentClassCName !== null)
                    ? ($this->symbols->getClassParent($this->currentClassCName) ?: '')
                    : '';
            } else {
                $cn = $this->symbols->resolveClass($rawName) ?? '';
            }
            if ($cn !== '') {
                $constType = $this->symbols->getConstType($cn . '::' . $node->property);
                if ($constType !== null) {
                    $node->inferredType = $this->resolveCTypeToType($constType);
                    return;
                }
            }
            $node->inferredType = Type::$mixed;
            return;
        }

        // C->CONST — C constant/enum/macro, 默认 int
        if ($node->object instanceof VariableExpr && $node->object->name === 'C') {
            $node->inferredType = Type::$int;
            return;
        }

        // $this->prop → 使用当前类名查询属性类型
        if ($node->object instanceof VariableExpr && $node->object->name === '$this') {
            if ($this->currentClassCName !== null) {
                $pt = $this->resolveClassPropTypeAlongParent($this->currentClassCName, $node->property);
                if ($pt !== null) {
                    $node->inferredType = $this->resolveCTypeToType($pt);
                    return;
                }
            }
            $node->inferredType = Type::$mixed;
            return;
        }

        // 枚举实例属性访问: EnumAccessExpr->name / EnumAccessExpr->value
        if ($node->object instanceof EnumAccessExpr) {
            $enumCName = $this->symbols->getEnumCName($node->object->enumName);
            if ($enumCName !== null) {
                if ($node->property === 'name') {
                    $node->inferredType = Type::$string;
                    return;
                }
                if ($node->property === 'value') {
                    $backing = $this->symbols->getEnumBacking($node->object->enumName);
                    $node->inferredType = $backing === 'string' ? Type::$string : Type::$int;
                    return;
                }
            }
            $node->inferredType = Type::$mixed;
            return;
        }

        // 普通属性访问：从对象 inferredType 查 cName，再查属性类型
        $objType = $node->object->inferredType;
        if ($objType !== null) {
            // Task 3.5: C 结构体指针字段访问类型推导
            //   当对象类型为已注册的 C 结构体（如 C.Point*）时，
            //   查询 cStructs 表获取字段类型，并映射到 Type 对象
            $sym = $this->symbols->typeTable()->getByIdx($objType->idx());
            if ($sym !== null && $sym->isCLang) {
                $structName = rtrim($sym->cType, '* ');
                if ($this->symbols->hasCStruct($structName)) {
                    $fieldType = $this->symbols->getCStructField($structName, $node->property);
                    if ($fieldType !== null) {
                        $node->inferredType = $this->cTypeToType($fieldType);
                        return;
                    }
                    // 结构体已注册但字段不存在 — 不报错（保持向后兼容），回退 mixed
                    $node->inferredType = Type::$mixed;
                    return;
                }
            }

            $cName = $this->typeToCName($objType);
            if ($cName !== null) {
                // 枚举属性访问：name → string, value → backing type
                $enumCName = $this->symbols->resolveEnumCName($cName);
                if ($enumCName !== null) {
                    if ($node->property === 'name') {
                        $node->inferredType = Type::$string;
                        return;
                    }
                    if ($node->property === 'value') {
                        // 查找枚举名以获取 backing type
                        foreach ($this->symbols->allEnums() as $enumName => $ct) {
                            if (rtrim($ct, '*') === $enumCName) {
                                $backing = $this->symbols->getEnumBacking($enumName);
                                $node->inferredType = $backing === 'string' ? Type::$string : Type::$int;
                                return;
                            }
                        }
                        $node->inferredType = Type::$int;
                        return;
                    }
                }
                // 类属性访问：沿父类链查找
                $pt = $this->resolveClassPropTypeAlongParent($cName, $node->property);
                if ($pt !== null) {
                    $node->inferredType = $this->resolveCTypeToType($pt);
                    return;
                }
            }
        }

        // Fallback
        $node->inferredType = Type::$mixed;
    }

    private function checkEnumAccess(EnumAccessExpr $node): void
    {
        // 无子表达式
        // Task 2.12: 枚举 case 访问类型推导
        //   Suit::Hearts → 枚举实例类型（tphp_enum_Suit*，resolveCTypeToType 映射为 Type::$object）
        //   枚举 case 在 registerEnum() 中通过 addEnumCase() 注册（仅存在性），
        //   此处通过 getEnumCType() 获取枚举 C 类型字符串并转换为 Type
        if ($this->symbols->hasEnumCase($node->enumName, $node->caseName)) {
            $cType = $this->symbols->getEnumCType($node->enumName);
            if ($cType !== null) {
                $node->inferredType = $this->resolveCTypeToType($cType);
                return;
            }
        }
        // 枚举常量访问 → 常量声明类型
        $ct = $this->symbols->getEnumConstType($node->enumName, $node->caseName);
        if ($ct !== null) {
            $node->inferredType = $this->resolveCTypeToType($ct);
            return;
        }
        // Fallback：当作枚举实例
        $cType = $this->symbols->getEnumCType($node->enumName);
        if ($cType !== null) {
            $node->inferredType = $this->resolveCTypeToType($cType);
            return;
        }
        $node->inferredType = Type::$mixed;
    }

    private function checkThrowExpr(ThrowExprNode $node): void
    {
        $this->checkExpr($node->expr);
        // throw 表达式类型为 never（永不正常返回）
        $node->inferredType = Type::$never;
    }

    /**
     * or {} 错误处理块类型推导
     *
     *   $data = readFile('x.txt') or { echo "failed"; return; };
     *
     * 语义：
     *   - 被检查表达式（左式）若抛出异常，执行 or {} 块
     *   - or 块内通过 $err 访问异常对象（类型为 Exception）
     *   - or 块内可 return/throw 传播错误
     *   - OrBlockExpr 类型 = 左式的"成功类型"（去掉 |Exception 后缀）
     */
    private function checkOrBlock(OrBlockExpr $node): void
    {
        // 检查左式（被检查表达式）
        $this->checkExpr($node->expr);
        $exprType = $node->expr->inferredType;

        // or 块内 $err 类型为 Exception
        $this->pushScope();
        $errType = $this->lookupType('Exception') ?? Type::$object;
        $this->scope['err'] = $errType;

        // 检查 or 块内语句
        foreach ($node->orBody as $stmt) {
            $this->checkStmt($stmt);
        }
        $this->popScope();

        // OrBlockExpr 类型 = 左式的类型（成功路径的返回值类型）
        // T|Exception 函数的"成功类型"为 T（去掉 Exception flag）
        $node->inferredType = $exprType;
    }

    private function checkPipeExpr(PipeExpr $node): void
    {
        $this->checkExpr($node->left);
        $this->checkExpr($node->right);

        // $x |> f($$) 生成 f(x)，结果类型为右侧 callable 的返回类型
        $right = $node->right;
        if ($right instanceof CallExpr) {
            // 函数调用：checkCall 已填充 inferredType（返回类型）
            $node->inferredType = $right->inferredType ?? Type::$mixed;
        } elseif ($right instanceof ClosureExpr) {
            // 闭包：从 returnType 注解推导
            $node->inferredType = $this->resolveTypeFromString($right->returnType);
        } else {
            // 变量 callable 等其他情况：无法推导返回类型
            $node->inferredType = Type::$mixed;
        }
    }

    private function checkPlaceholderExpr(PlaceholderExpr $node): void
    {
        // 无字段
    }

    // ──────────────────────────────────────────────────────────
    // 注解节点检查
    // ──────────────────────────────────────────────────────────

    private function checkAttributeDecl(AttributeDeclNode $node): void
    {
        // 注解声明参数为描述数组，检查其中的默认值表达式
        foreach ($node->params as $param) {
            if (is_array($param) && isset($param['default']) && $param['default'] instanceof ExprNode) {
                $this->checkExpr($param['default']);
            }
        }
    }

    private function checkAttributeUse(AttributeUseNode $node): void
    {
        foreach ($node->args as $arg) {
            $this->checkExpr($arg);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ASTVisitor 接口实现
    //   所有 visit* 方法：委托给对应 check* 方法，返回 ''
    // ═══════════════════════════════════════════════════════════════

    // ── 顶层节点 ──

    public function visitProgram(ProgramNode $node): string
    {
        $this->checkProgram($node);
        return '';
    }

    public function visitClass(ClassNode $node): string
    {
        $this->checkClass($node);
        return '';
    }

    public function visitFunction(FunctionNode $node): string
    {
        $this->checkFunction($node);
        return '';
    }

    public function visitMethod(MethodNode $node): string
    {
        $this->checkMethod($node);
        return '';
    }

    public function visitParam(ParamNode $node): string
    {
        $this->checkParam($node);
        return '';
    }

    public function visitPropertyDecl(PropertyDeclNode $node): string
    {
        $this->checkPropertyDecl($node);
        return '';
    }

    // ── 语句节点 ──

    public function visitEchoStmt(EchoStmtNode $node): string
    {
        $this->checkEchoStmt($node);
        return '';
    }

    public function visitReturnStmt(ReturnStmtNode $node): string
    {
        $this->checkReturnStmt($node);
        return '';
    }

    public function visitAssignStmt(AssignStmtNode $node): string
    {
        $this->checkAssignStmt($node);
        return '';
    }

    public function visitAssignPropStmt(AssignPropStmtNode $node): string
    {
        $this->checkAssignPropStmt($node);
        return '';
    }

    public function visitAssignArrayStmt(AssignArrayStmtNode $node): string
    {
        $this->checkAssignArrayStmt($node);
        return '';
    }

    public function visitAssignArrayPushStmt(AssignArrayPushStmtNode $node): string
    {
        $this->checkAssignArrayPushStmt($node);
        return '';
    }

    public function visitExprStmt(ExprStmtNode $node): string
    {
        $this->checkExprStmt($node);
        return '';
    }

    public function visitNopStmt(NopStmtNode $node): string
    {
        $this->checkNopStmt($node);
        return '';
    }

    public function visitStaticStmt(StaticStmtNode $node): string
    {
        $this->checkStaticStmt($node);
        return '';
    }

    public function visitConstStmt(ConstStmtNode $node): string
    {
        $this->checkConstStmt($node);
        return '';
    }

    public function visitBlockStmt(BlockStmtNode $node): string
    {
        $this->checkBlockStmt($node);
        return '';
    }

    public function visitIfStmt(IfStmtNode $node): string
    {
        $this->checkIfStmt($node);
        return '';
    }

    public function visitWhileStmt(WhileStmtNode $node): string
    {
        $this->checkWhileStmt($node);
        return '';
    }

    public function visitDoWhileStmt(DoWhileStmtNode $node): string
    {
        $this->checkDoWhileStmt($node);
        return '';
    }

    public function visitListStmt(ListStmtNode $node): string
    {
        $this->checkListStmt($node);
        return '';
    }

    public function visitForStmt(ForStmtNode $node): string
    {
        $this->checkForStmt($node);
        return '';
    }

    public function visitForeachStmt(ForeachStmtNode $node): string
    {
        $this->checkForeachStmt($node);
        return '';
    }

    public function visitSwitchStmt(SwitchStmtNode $node): string
    {
        $this->checkSwitchStmt($node);
        return '';
    }

    public function visitBreakStmt(BreakStmtNode $node): string
    {
        $this->checkBreakStmt($node);
        return '';
    }

    public function visitGotoStmt(GotoStmtNode $node): string
    {
        $this->checkGotoStmt($node);
        return '';
    }

    public function visitTryStmt(TryStmtNode $node): string
    {
        $this->checkTryStmt($node);
        return '';
    }

    public function visitThrowStmt(ThrowStmtNode $node): string
    {
        $this->checkThrowStmt($node);
        return '';
    }

    public function visitLabelStmt(LabelStmtNode $node): string
    {
        $this->checkLabelStmt($node);
        return '';
    }

    public function visitContinueStmt(ContinueStmtNode $node): string
    {
        $this->checkContinueStmt($node);
        return '';
    }

    public function visitDeferStmt(DeferStmtNode $node): string
    {
        $this->checkDeferStmt($node);
        return '';
    }

    // ── 表达式节点 ──

    public function visitStringLiteral(StringLiteralExpr $node): string
    {
        $this->checkStringLiteral($node);
        return '';
    }

    public function visitIntLiteral(IntLiteralExpr $node): string
    {
        $this->checkIntLiteral($node);
        return '';
    }

    public function visitFloatLiteral(FloatLiteralExpr $node): string
    {
        $this->checkFloatLiteral($node);
        return '';
    }

    public function visitBoolLiteral(BoolLiteralExpr $node): string
    {
        $this->checkBoolLiteral($node);
        return '';
    }

    public function visitNullLiteral(NullLiteralExpr $node): string
    {
        $this->checkNullLiteral($node);
        return '';
    }

    public function visitMagicConst(MagicConstExpr $node): string
    {
        $this->checkMagicConst($node);
        return '';
    }

    public function visitArrayLiteral(ArrayLiteralExpr $node): string
    {
        $this->checkArrayLiteral($node);
        return '';
    }

    public function visitVariable(VariableExpr $node): string
    {
        $this->checkVariable($node);
        return '';
    }

    public function visitBinary(BinaryExpr $node): string
    {
        $this->checkBinary($node);
        return '';
    }

    public function visitTernary(TernaryExpr $node): string
    {
        $this->checkTernary($node);
        return '';
    }

    public function visitNullCoalesce(NullCoalesceExpr $node): string
    {
        $this->checkNullCoalesce($node);
        return '';
    }

    public function visitMatchExpr(MatchExpr $node): string
    {
        $this->checkMatchExpr($node);
        return '';
    }

    public function visitCall(CallExpr $node): string
    {
        $this->checkCall($node);
        return '';
    }

    public function visitCast(CastExpr $node): string
    {
        $this->checkCast($node);
        return '';
    }

    public function visitNew(NewExpr $node): string
    {
        $this->checkNew($node);
        return '';
    }

    public function visitClosure(ClosureExpr $node): string
    {
        $this->checkClosure($node);
        return '';
    }

    public function visitYieldExpr(YieldExpr $node): string
    {
        $this->checkYieldExpr($node);
        return '';
    }

    public function visitYieldFromExpr(YieldFromExpr $node): string
    {
        $this->checkYieldFromExpr($node);
        return '';
    }

    public function visitUnary(UnaryExpr $node): string
    {
        $this->checkUnary($node);
        return '';
    }

    public function visitPostfix(PostfixExpr $node): string
    {
        $this->checkPostfix($node);
        return '';
    }

    public function visitCompoundAssign(CompoundAssignExpr $node): string
    {
        $this->checkCompoundAssign($node);
        return '';
    }

    public function visitArrayAccess(ArrayAccessExpr $node): string
    {
        $this->checkArrayAccess($node);
        return '';
    }

    public function visitArrayAppend(ArrayAppendExpr $node): string
    {
        $this->checkArrayAppend($node);
        return '';
    }

    public function visitPropertyAccess(PropertyAccessExpr $node): string
    {
        $this->checkPropertyAccess($node);
        return '';
    }

    public function visitEnumAccess(EnumAccessExpr $node): string
    {
        $this->checkEnumAccess($node);
        return '';
    }

    public function visitThrowExpr(ThrowExprNode $node): string
    {
        $this->checkThrowExpr($node);
        return '';
    }

    public function visitOrBlock(OrBlockExpr $node): string
    {
        $this->checkOrBlock($node);
        return '';
    }

    public function visitPipeExpr(PipeExpr $node): string
    {
        $this->checkPipeExpr($node);
        return '';
    }

    public function visitPlaceholderExpr(PlaceholderExpr $node): string
    {
        $this->checkPlaceholderExpr($node);
        return '';
    }

    public function visitCallableConvert(CallableConvertExpr $node): string
    {
        // First-class callable 表达式类型恒为 callback
        $node->inferredType = Type::$callback;
        return '';
    }

    // ── 其他节点 ──

    public function visitConst(ConstNode $node): string
    {
        $this->checkConst($node);
        return '';
    }

    public function visitEnum(EnumNode $node): string
    {
        $this->checkEnum($node);
        return '';
    }

    public function visitAttributeDecl(AttributeDeclNode $node): string
    {
        $this->checkAttributeDecl($node);
        return '';
    }

    public function visitAttributeUse(AttributeUseNode $node): string
    {
        $this->checkAttributeUse($node);
        return '';
    }
}
