<?php

declare(strict_types=1);

// ============================================================
// CodeGenDataTrait — CodeGenerator 静态数据注册表
// 从 CodeGenerator.php 提取，减少主文件维护负担
// ============================================================
trait CodeGenDataTrait {

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
        if ($rawType === '' || $rawType === self::CT_VOID) return self::CT_VOID;
        // TinyPHP 内部类型原样透传（phpc 扩展 C 函数返回 TinyPHP 值类型）
        if (self::isTphpType($rawType)) return $rawType;
        if (str_ends_with($rawType, '*')) return self::CT_NULL_PTR;
        if ($rawType === 'float' || $rawType === 'double') return self::CT_FLOAT;
        return self::CT_INT;
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
        $this->inferTypeCache = [];
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
        if ($isDeclared && self::isSimpleType($prevType)) {
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
        if (self::isTphpType($type)) return true;
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
        return self::idxToCT($type->idx());
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

    /** 从 AST 表达式推导 C 类型（带记忆化缓存） */
    private function inferType(ExprNode $expr): string
    {
        $key = spl_object_id($expr);
        if (isset($this->inferTypeCache[$key])) {
            return $this->inferTypeCache[$key];
        }
        $result = $this->inferTypeUncached($expr);
        $this->inferTypeCache[$key] = $result;
        return $result;
    }

    /** 从 AST 表达式推导 C 类型（实际实现） */
    private function inferTypeUncached(ExprNode $expr): string
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
        return self::arrElemCType($arrCType) ?? match (true) {
            str_contains($arrCType, '_ptr*') => self::CT_VOID_PTR,
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
        $n = strtolower(preg_replace('/\s+/', '', $cType));
        return match ($n) {
            'int32_t','int64_t','int','long','longlong','uint32_t','uint64_t','unsignedint','unsignedlong' => self::CT_INT,
            'double','float' => self::CT_FLOAT,
            'constchar*','char*','constchar','char' => self::CT_STRING,
            'bool','_bool' => self::CT_BOOL,
            'void' => self::CT_VOID,
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
        if ($lt === self::CT_STRING && in_array($rt, [self::CT_INT, self::CT_FLOAT, self::CT_BOOL], true)) {
            $lCode = 'tphp_rt_parse_int(' . $lCode . ')';
        }
        if ($rt === self::CT_STRING && in_array($lt, [self::CT_INT, self::CT_FLOAT, self::CT_BOOL], true)) {
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


}
