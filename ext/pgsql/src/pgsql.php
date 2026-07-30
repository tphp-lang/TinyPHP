<?php
// ext/pgsql/src/pgsql.php — PostgreSQL 扩展（纯 C 协议实现）
//
// 设计说明：
//   - 纯 C 实现 PostgreSQL v3 协议（不依赖 libpq）
//   - 认证：trust / MD5 / SCRAM-SHA-256（内置 SHA-256/HMAC/PBKDF2）
//   - 协议：Extended Query（Parse/Bind/Execute）+ Simple Query
//   - Large Object 支持（lo_create/lo_open/lo_read/lo_write/lo_lseek/lo_close/lo_unlink）
//   - 持久连接池（pg_pconnect）
//   - 不支持 SSL/TLS、Unix socket（本期）
//
// 依赖：ext/stream（socket 跨平台抽象）
//   - 本文件通过 #include 直接引入 stream.h，无需用户额外 #import stream
//   - Windows 链接 ws2_32 由本文件 #flag windows -lws2_32 提供
//
// 包含顺序（CodeGenerator 保证 ext/ 路径的头文件放在 common.h 之后）：
//   1. stream/src/stream.h   — socket 跨平台抽象
//   2. pgsql/pgsql.h         — 协议常量 + 结构体 + 前向声明
//   3. pgsql/pg_crypto.h     — SHA-256/HMAC/PBKDF2/Base64/MD5 密码学原语
//   4. pgsql/pgsql_protocol.h — DSN 解析、消息收发、认证、连接函数实现
//   5. pgsql/pgsql_query.h   — 简单查询协议 + 扩展查询协议实现

// Windows 需要 winsock2 库（socket/connect/recv/send 等符号）
#flag windows -lws2_32

// 引入 stream.h（提供 STREAM_CLOSE/STREAM_ERRNO 等宏 + tphp_fn_stream_init + tphp_fn_stream_socket_client）
#include __EXT__ . "stream/src/stream.h"

// 引入 pgsql.h（协议常量 + 结构体定义 + 前向声明）
#include __EXT__ . "pgsql/pgsql.h"

// 引入 pg_crypto.h（SHA-256/HMAC-SHA-256/PBKDF2/Base64/MD5 密码学原语）
#include __EXT__ . "pgsql/pg_crypto.h"

// 引入 pgsql_protocol.h（DSN 解析、消息收发、认证流程、连接函数实现）
#include __EXT__ . "pgsql/pgsql_protocol.h"

// 引入 pgsql_query.h（简单查询协议 + 扩展查询协议实现）
#include __EXT__ . "pgsql/pgsql_query.h"

// 引入 pgsql_result.h（Task 6：结果集访问函数）
//   依赖 pgsql_query.h 的 _pg_result_free，必须在其后包含
#include __EXT__ . "pgsql/pgsql_result.h"

// 引入 pgsql_misc.h（Task 7：连接信息函数 + 转义函数）
//   依赖 pgsql_query.h 的 _pg_exec_query / _pg_result_free，必须在其后包含
#include __EXT__ . "pgsql/pgsql_misc.h"

// 引入 pgsql_copy.h（Task 8：COPY 协议函数）
//   依赖 pgsql_query.h 的 _pg_exec_query / _pg_result_free，必须在其后包含
#include __EXT__ . "pgsql/pgsql_copy.h"

// 引入 pgsql_dml.h（Task 9：高层 DML 函数 — 元数据/转换/insert/update/delete/select）
//   依赖 pgsql_query.h 的 _pg_exec_query / _pg_result_free 和 pgsql_misc.h 的 _pg_quote_ident/_pg_quote_literal
#include __EXT__ . "pgsql/pgsql_dml.h"

// 引入 pgsql_lo.h（Task 9.5：Large Object 协议 — lo_create/lo_open/lo_read/lo_write/lo_seek/lo_tell/lo_truncate/lo_close/lo_unlink/lo_import/lo_export/lo_read_all）
//   依赖 pgsql_query.h 的 _pg_exec_query / _pg_result_free，pgsql_result.h 的 _pg_mk_str/_pg_mk_str_n，
//   pgsql_misc.h 的 _pg_unescape_bytea，pgsql_copy.h 的 _pg_quote_literal，include/object/resource.h 的资源 API
#include __EXT__ . "pgsql/pgsql_lo.h"

// 引入 pgsql_pconnect.h（Task 9.6：持久连接池 — pg_pconnect/pg_close 实现）
//   依赖 pgsql_protocol.h 的 _pg_connect / _pg_send_message / _pg_free_conn
//   _pg_pconnect / _pg_close 委托调用 _pg_pconnect_real / _pg_close_real
#include __EXT__ . "pgsql/pgsql_pconnect.h"

// 引入 pgsql_notice.h（Task 9.7：通知回调 — pg_set_notice_callback / _pg_invoke_notice_cb）
//   依赖 pgsql_result.h 的 _pg_mk_str_n，pgsql_protocol.h / pgsql_query.h 的 NoticeResponse 处理调用 _pg_invoke_notice_cb
#include __EXT__ . "pgsql/pgsql_notice.h"

// ════════════════════════════════════════════════════════════
// Task 10: PHP 层函数声明
//
// 设计原则：
//   1. 所有参数/返回值用 tphp 具体类型（int/string/array/bool）
//   2. C 层函数签名使用 t_int/t_string/t_array*，与 PHP 层类型一一对应
//   3. 连接句柄/结果句柄以 t_int 存储，直接传递（无需 c_int/php_int 转换）
//   4. 字符串参数/返回值使用 t_string，直接传递（无需 c_str/php_str 转换）
//   5. 错误由 C 层 tp_throw 抛出，PHP 层不重复检查
//   6. 多态返回用拆分函数（如 pg_insert_result 返回 int，pg_insert_sql 返回 string）
//   7. pg_lo_open 返回 int（Large Object 句柄以 int 存储）
//
// PgSql\Connection 和 PgSql\Result 不透明类：
//   PHP 8.5 中这两个类用于类型提示。由于 tphp 的 AOT 模型，
//   用 int 存储指针，不声明这两个类（声明了也无法用于类型约束，因为实际值是 int）。
// ════════════════════════════════════════════════════════════

// ── 连接管理 ──────────────────────────────────────────────

function pg_connect(string $dsn): int
{
    return _pg_connect($dsn);
}

function pg_pconnect(string $dsn, int $flags = 0): int
{
    return _pg_pconnect($dsn, $flags);
}

// close_flags 传递给 C 层 _pg_close_real（PGSQL_CLOSE_FORCE=1 强制关闭持久连接）
function pg_close(int $conn, int $close_flags = 0): bool
{
    return _pg_close($conn, $close_flags);
}

function pg_connection_status(int $conn): int
{
    return _pg_connection_status($conn);
}

function pg_connection_reset(int $conn): bool
{
    return _pg_connection_reset($conn);
}

function pg_ping(int $conn): bool
{
    return _pg_ping($conn);
}

// ── 查询 ──────────────────────────────────────────────────

function pg_query(int $conn, string $sql): int
{
    return _pg_query($conn, $sql);
}

function pg_query_params(int $conn, string $sql, array $params): int
{
    return _pg_query_params($conn, $sql, $params);
}

function pg_prepare(int $conn, string $stmt_name, string $sql): int
{
    return _pg_prepare($conn, $stmt_name, $sql);
}

function pg_execute(int $conn, string $stmt_name, array $params): int
{
    return _pg_execute($conn, $stmt_name, $params);
}

function pg_free_result(int $result): void
{
    _pg_free_result($result);
}

// ── 结果集 ────────────────────────────────────────────────

function pg_num_rows(int $result): int
{
    return _pg_num_rows($result);
}

function pg_num_fields(int $result): int
{
    return _pg_num_fields($result);
}

function pg_affected_rows(int $result): int
{
    return _pg_affected_rows($result);
}

function pg_last_oid(int $result): int
{
    return _pg_last_oid($result);
}

function pg_field_name(int $result, int $field_num): string
{
    return _pg_field_name($result, $field_num);
}

function pg_field_num(int $result, string $field_name): int
{
    return _pg_field_num($result, $field_name);
}

function pg_field_type(int $result, int $field_num): string
{
    return _pg_field_type($result, $field_num);
}

function pg_field_type_oid(int $result, int $field_num): int
{
    return _pg_field_type_oid($result, $field_num);
}

function pg_field_size(int $result, int $field_num): int
{
    return _pg_field_size($result, $field_num);
}

function pg_field_prtlen(int $result, int $row_num, int $field_num): int
{
    return _pg_field_prtlen($result, $row_num, $field_num);
}

function pg_field_is_null(int $result, int $row_num, int $field_num): bool
{
    return _pg_field_is_null($result, $row_num, $field_num);
}

function pg_field_table(int $result, int $field_num): int
{
    return _pg_field_table($result, $field_num);
}

// 返回 array 的函数：C 层返回 t_array*，NULL 时返回空数组
function pg_fetch_row(int $result): array
{
    $arr = _pg_fetch_row($result);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_fetch_assoc(int $result): array
{
    $arr = _pg_fetch_assoc($result);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_fetch_array(int $result, int $result_type = 3): array
{
    $arr = _pg_fetch_array($result, $result_type);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_fetch_all(int $result, int $result_type = 3): array
{
    $arr = _pg_fetch_all($result, $result_type);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_fetch_all_columns(int $result, int $col = 0): array
{
    $arr = _pg_fetch_all_columns($result, $col);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

// pg_fetch_result_str 对应 C 层 _pg_fetch_result（多态返回按类型拆分）
function pg_fetch_result_str(int $result, int $row, string $field): string
{
    return _pg_fetch_result($result, $row, $field);
}

function pg_result_status(int $result, int $mode = 1): int
{
    return _pg_result_status($result, $mode);
}

function pg_result_status_str(int $result): string
{
    return _pg_result_status_str($result);
}

function pg_result_seek(int $result, int $offset): bool
{
    return _pg_result_seek($result, $offset);
}

function pg_result_error(int $result): string
{
    return _pg_result_error($result);
}

function pg_result_error_field(int $result, int $field_code): string
{
    return _pg_result_error_field($result, $field_code);
}

function pg_last_error(int $conn): string
{
    return _pg_last_error($conn);
}

function pg_last_notice(int $conn): string
{
    return _pg_last_notice($conn);
}

// ── 连接信息 ──────────────────────────────────────────────

function pg_dbname(int $conn): string
{
    return _pg_dbname($conn);
}

function pg_host(int $conn): string
{
    return _pg_host($conn);
}

function pg_port(int $conn): int
{
    return _pg_port($conn);
}

function pg_options(int $conn): string
{
    return _pg_options($conn);
}

function pg_tty(int $conn): string
{
    return _pg_tty($conn);
}

function pg_version(int $conn): array
{
    $arr = _pg_version($conn);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_parameter_status(int $conn, string $param_name): string
{
    return _pg_parameter_status($conn, $param_name);
}

function pg_transaction_status(int $conn): int
{
    return _pg_transaction_status($conn);
}

function pg_client_encoding(int $conn): string
{
    return _pg_client_encoding($conn);
}

function pg_set_client_encoding(int $conn, string $encoding): int
{
    return _pg_set_client_encoding($conn, $encoding);
}

// ── 转义 ──────────────────────────────────────────────────

function pg_escape_string(int $conn, string $data): string
{
    return _pg_escape_string($conn, $data);
}

function pg_escape_literal(int $conn, string $data): string
{
    return _pg_escape_literal($conn, $data);
}

function pg_escape_identifier(int $conn, string $data): string
{
    return _pg_escape_identifier($conn, $data);
}

function pg_escape_bytea(int $conn, string $data): string
{
    return _pg_escape_bytea($conn, $data);
}

// pg_unescape_bytea 不需要连接句柄（C 层 _pg_unescape_bytea 仅接受 t_string data）
function pg_unescape_bytea(string $data): string
{
    return _pg_unescape_bytea($data);
}

// ── COPY ──────────────────────────────────────────────────

function pg_copy_to(int $conn, string $table_name, string $separator = "\t", string $null_as = "\\\\N"): array
{
    $arr = _pg_copy_to($conn, $table_name, $separator, $null_as);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_copy_from(int $conn, string $table_name, array $rows, string $separator = "\t", string $null_as = "\\\\N"): bool
{
    return _pg_copy_from($conn, $table_name, $rows, $separator, $null_as);
}

function pg_put_copy_data(int $conn, string $data): bool
{
    return _pg_put_copy_data($conn, $data);
}

function pg_put_copy_end(int $conn, string $error_msg = ""): bool
{
    return _pg_put_copy_end($conn, $error_msg);
}

function pg_end_copy(int $conn): bool
{
    return _pg_end_copy($conn);
}

// ── DML ───────────────────────────────────────────────────
//   多态返回按类型拆分：pg_insert_result（int）/ pg_insert_sql（string）等

function pg_meta_data(int $conn, string $table_name): array
{
    $arr = _pg_meta_data($conn, $table_name);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_convert(int $conn, string $table_name, array $assoc_array, int $flags = 0): array
{
    $arr = _pg_convert($conn, $table_name, $assoc_array, $flags);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

function pg_insert_result(int $conn, string $table_name, array $assoc, int $flags = 1): int
{
    return _pg_insert_result($conn, $table_name, $assoc, $flags);
}

function pg_insert_sql(int $conn, string $table_name, array $assoc, int $flags = 1): string
{
    return _pg_insert_sql($conn, $table_name, $assoc, $flags);
}

function pg_update_result(int $conn, string $table_name, array $assoc, array $condition, int $flags = 1): int
{
    return _pg_update_result($conn, $table_name, $assoc, $condition, $flags);
}

function pg_update_sql(int $conn, string $table_name, array $assoc, array $condition, int $flags = 1): string
{
    return _pg_update_sql($conn, $table_name, $assoc, $condition, $flags);
}

function pg_delete_result(int $conn, string $table_name, array $condition, int $flags = 1): int
{
    return _pg_delete_result($conn, $table_name, $condition, $flags);
}

function pg_delete_sql(int $conn, string $table_name, array $condition, int $flags = 1): string
{
    return _pg_delete_sql($conn, $table_name, $condition, $flags);
}

function pg_select(int $conn, string $table_name, array $assoc, int $conditions = 0, int $flags = 1): array
{
    $arr = _pg_select($conn, $table_name, $assoc, $conditions, $flags);
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

// ── Large Object ─────────────────────────────────────────
//   lo 句柄以 int 存储（C 层返回 t_int），Resource 类型用 int 表达

function pg_lo_create(int $conn): int
{
    return _pg_lo_create($conn);
}

function pg_lo_open(int $conn, int $oid, string $mode): int
{
    return _pg_lo_open($conn, $oid, $mode);
}

function pg_lo_read(int $conn, int $lob, int $len): string
{
    return _pg_lo_read($conn, $lob, $len);
}

function pg_lo_write(int $conn, int $lob, string $data): int
{
    return _pg_lo_write($conn, $lob, $data);
}

function pg_lo_seek(int $conn, int $lob, int $offset, int $whence = 0): int
{
    return _pg_lo_seek($conn, $lob, $offset, $whence);
}

function pg_lo_tell(int $conn, int $lob): int
{
    return _pg_lo_tell($conn, $lob);
}

function pg_lo_truncate(int $conn, int $lob, int $len): bool
{
    return _pg_lo_truncate($conn, $lob, $len);
}

function pg_lo_close(int $conn, int $lob): void
{
    _pg_lo_close($conn, $lob);
}

function pg_lo_unlink(int $conn, int $oid): bool
{
    return _pg_lo_unlink($conn, $oid);
}

function pg_lo_import(int $conn, string $filename): int
{
    return _pg_lo_import($conn, $filename);
}

function pg_lo_export(int $conn, int $oid, string $filename): bool
{
    return _pg_lo_export($conn, $oid, $filename);
}

function pg_lo_read_all(int $conn, int $lob): string
{
    return _pg_lo_read_all($conn, $lob);
}

// ── 通知回调 ──────────────────────────────────────────────
//   callable 参数在 tphp 中映射为 t_callback 类型，直接透传给 C 层

function pg_set_notice_callback(int $conn, callable $callback): void
{
    _pg_set_notice_callback($conn, $callback);
}
