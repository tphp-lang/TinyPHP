#pragma once
// ============================================================
// pgsql_misc.h — PostgreSQL 连接信息函数 + 转义函数（Task 7）
//
// 实现：
//   7.1 连接信息
//       pg_dbname / pg_host / pg_port / pg_options / pg_tty
//       pg_version / pg_parameter_status / pg_transaction_status
//       pg_client_encoding / pg_set_client_encoding
//
//   7.2 转义函数
//       pg_escape_string     — 按 standard_conforming_strings 设置转义
//       pg_escape_literal    — 字面量转义（'it''s' 格式）
//       pg_escape_identifier — 标识符转义（"my column" 格式）
//       pg_escape_bytea      — bytea hex 转义（"\x..." 格式）
//       pg_unescape_bytea    — bytea 反转义（支持 hex 和 escape 两种格式）
//
// 依赖：pgsql.h（结构体 + 常量）+ pgsql_protocol.h（连接操作）+ pgsql_query.h（_pg_exec_query）
//
// 转义规则：
//   pg_escape_string:
//     std_conforming_strings=on:  单引号→两个单引号，反斜杠原样
//     std_conforming_strings=off: 单引号→\'，反斜杠→双反斜杠
//   pg_escape_literal:
//     单引号→两个单引号，包裹在单引号中（'it''s'）
//   pg_escape_identifier:
//     双引号→两个双引号，包裹在双引号中（"my column"）
//   pg_escape_bytea:
//     "\x" + hex(data)（PG 9+ hex 格式）
//   pg_unescape_bytea:
//     hex 格式（"\x..."）→ hex 解码
//     escape 格式（"\xxx" 八进制 / "\\" / "\'"）→ 对应解码
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include "val.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

// ============================================================
// 内部辅助（hex 编解码）
// ============================================================

static const char _pg_hex_chars[] = "0123456789abcdef";

// hex 字符 → 数值（0-15），非 hex 字符返回 -1
static inline int _pg_hex_val(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

// ============================================================
// 7.1 连接信息
// ============================================================

t_string tphp_fn_pg_dbname(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_dbname: invalid connection handle");
        return (t_string){0};
    }
    if (conn->dbname != NULL) return _pg_mk_str(conn->dbname);
    return (t_string){0};
}

t_string tphp_fn_pg_host(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_host: invalid connection handle");
        return (t_string){0};
    }
    if (conn->host != NULL) return _pg_mk_str(conn->host);
    return (t_string){0};
}

t_int tphp_fn_pg_port(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_port: invalid connection handle");
        return 0;
    }
    return (t_int)conn->port;
}

t_string tphp_fn_pg_options(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_options: invalid connection handle");
        return (t_string){0};
    }
    if (conn->options != NULL) return _pg_mk_str(conn->options);
    return (t_string){0};
}

t_string tphp_fn_pg_tty(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_tty: invalid connection handle");
        return (t_string){0};
    }
    if (conn->tty != NULL) return _pg_mk_str(conn->tty);
    return (t_string){0};
}

// pg_version — 返回 [client, server, protocol] 关联数组
t_array* tphp_fn_pg_version(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_version: invalid connection handle");
        return NULL;
    }
    t_array *a = tphp_fn_arr_create(3);
    if (a == NULL) {
        tp_throw("pg_version: out of memory");
        return NULL;
    }
    // client: 纯 C 实现的客户端版本
    a = tphp_fn_arr_set_str(a, STR_LIT("client"), VAR_STRING(STR_LIT("TinyPHP pgsql 1.0")));
    // server: 从 ParameterStatus 获取的服务器版本
    if (conn->server_version != NULL) {
        t_string sv = _pg_mk_str(conn->server_version);
        a = tphp_fn_arr_set_str(a, STR_LIT("server"), VAR_STRING(sv));
    } else {
        a = tphp_fn_arr_set_str(a, STR_LIT("server"), VAR_STRING(STR_LIT("unknown")));
    }
    // protocol: PostgreSQL v3 协议
    a = tphp_fn_arr_set_str(a, STR_LIT("protocol"), VAR_STRING(STR_LIT("3.0")));
    tphp_rt_register((void*)a, 1);
    return a;
}

// pg_parameter_status — 返回已知的服务器参数值
//   仅返回连接启动时缓存的参数（server_version / client_encoding / standard_conforming_strings）
t_string tphp_fn_pg_parameter_status(t_int conn_handle, t_string param_name) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_parameter_status: invalid connection handle");
        return (t_string){0};
    }
    const char *name = STR_PTR(param_name);
    if (name == NULL || param_name.length == 0) {
        tp_throw("pg_parameter_status: empty parameter name");
        return (t_string){0};
    }
    if (strcmp(name, "server_version") == 0) {
        if (conn->server_version != NULL) return _pg_mk_str(conn->server_version);
        return (t_string){0};
    }
    if (strcmp(name, "client_encoding") == 0) {
        if (conn->client_encoding != NULL) return _pg_mk_str(conn->client_encoding);
        return (t_string){0};
    }
    if (strcmp(name, "standard_conforming_strings") == 0) {
        return STR_LIT(conn->std_conforming_strings ? "on" : "off");
    }
    // 未知参数 — 未缓存
    return (t_string){0};
}

// pg_transaction_status — 返回当前事务状态
//   返回 PGSQL_TRANSACTION_* 常量（0=IDLE, 1=ACTIVE, 2=INTRANS, 3=INERROR, 4=UNKNOWN）
t_int tphp_fn_pg_transaction_status(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_transaction_status: invalid connection handle");
        return 4;  // PGSQL_TRANSACTION_UNKNOWN
    }
    if (conn->status != CONN_OK) return 4;  // 连接异常
    switch (conn->trans_status) {
        case TRANS_IDLE:    return 0;  // PGSQL_TRANSACTION_IDLE
        case TRANS_INTRANS: return 2;  // PGSQL_TRANSACTION_INTRANS
        case TRANS_INERROR: return 3;  // PGSQL_TRANSACTION_INERROR
        default:            return 4;  // PGSQL_TRANSACTION_UNKNOWN
    }
    // 注意：PGSQL_TRANSACTION_ACTIVE (1) 在同步模式下无法检测
}

// pg_client_encoding — 返回客户端编码
t_string tphp_fn_pg_client_encoding(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_client_encoding: invalid connection handle");
        return (t_string){0};
    }
    if (conn->client_encoding != NULL) return _pg_mk_str(conn->client_encoding);
    // 默认编码
    return STR_LIT("UTF8");
}

// pg_set_client_encoding — 设置客户端编码
//   发送 "SET CLIENT_ENCODING TO '<encoding>'" 查询
//   成功返回 0，失败返回 -1
t_int tphp_fn_pg_set_client_encoding(t_int conn_handle, t_string encoding) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_set_client_encoding: invalid connection handle");
        return -1;
    }
    const char *enc = STR_PTR(encoding);
    if (enc == NULL || encoding.length == 0) {
        tp_throw("pg_set_client_encoding: empty encoding");
        return -1;
    }
    // 构建 SQL: SET CLIENT_ENCODING TO '<encoding>'
    char sql[256];
    int n = snprintf(sql, sizeof(sql), "SET CLIENT_ENCODING TO '%s'", enc);
    if (n < 0 || n >= (int)sizeof(sql)) {
        tp_throw("pg_set_client_encoding: encoding name too long");
        return -1;
    }
    PGresult *res = _pg_exec_query(conn, sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_set_client_encoding: query failed");
        return -1;
    }
    int rc = 0;
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                                        : (conn->last_error ? conn->last_error : "pg_set_client_encoding: failed");
        tp_throw(emsg);
        rc = -1;
    } else {
        // 更新 conn->client_encoding
        if (conn->client_encoding != NULL) free(conn->client_encoding);
        conn->client_encoding = (char*)malloc(strlen(enc) + 1);
        if (conn->client_encoding != NULL) {
            strcpy(conn->client_encoding, enc);
        }
    }
    _pg_result_free(res);
    return rc;
}

// ============================================================
// 7.2 转义函数
// ============================================================

// pg_escape_string — 字符串转义（用于 SQL 字面量）
//   standard_conforming_strings=on:  单引号→''，反斜杠原样
//   standard_conforming_strings=off: 单引号→\'，反斜杠→双反斜杠
t_string tphp_fn_pg_escape_string(t_int conn_handle, t_string data) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    // conn 可为 NULL（PHP 允许无连接转义，默认 std=on）
    int std_conf = (conn != NULL) ? conn->std_conforming_strings : 1;
    const char *src = STR_PTR(data);
    int len = data.length;
    if (src == NULL || len <= 0) return (t_string){0};

    // 估算最大输出长度
    int max_out = len * 2 + 1;
    char *buf = str_pool_alloc(max_out);
    if (buf == NULL) {
        tp_throw("pg_escape_string: out of memory");
        return (t_string){0};
    }
    int pos = 0;
    for (int i = 0; i < len; i++) {
        char c = src[i];
        if (c == '\'') {
            if (std_conf) {
                buf[pos++] = '\''; buf[pos++] = '\'';  // ' → ''
            } else {
                buf[pos++] = '\\'; buf[pos++] = '\'';  // ' → \'
            }
        } else if (c == '\\' && !std_conf) {
            buf[pos++] = '\\'; buf[pos++] = '\\';  // \ → \\ (仅 std=off)
        } else {
            buf[pos++] = c;
        }
    }
    buf[pos] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = buf;
    r.length = pos;
    return r;
}

// pg_escape_literal — 字面量转义（返回带引号的字面量）
//   ' → ''，包裹在单引号中（'it''s'）
//   始终使用标准 SQL 转义（不受 standard_conforming_strings 影响）
t_string tphp_fn_pg_escape_literal(t_int conn_handle, t_string data) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    (void)conn;  // 不依赖连接设置
    const char *src = STR_PTR(data);
    int len = data.length;
    if (src == NULL || len < 0) len = 0;

    // 估算最大输出：2*len + 2（引号） + 1（NUL）
    int max_out = 2 * len + 3;
    char *buf = str_pool_alloc(max_out);
    if (buf == NULL) {
        tp_throw("pg_escape_literal: out of memory");
        return (t_string){0};
    }
    int pos = 0;
    buf[pos++] = '\'';  // 开头单引号
    for (int i = 0; i < len; i++) {
        char c = src[i];
        if (c == '\'') {
            buf[pos++] = '\''; buf[pos++] = '\'';  // ' → ''
        } else {
            buf[pos++] = c;
        }
    }
    buf[pos++] = '\'';  // 结尾单引号
    buf[pos] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = buf;
    r.length = pos;
    return r;
}

// pg_escape_identifier — 标识符转义（返回带双引号的标识符）
//   " → ""，包裹在双引号中（"my column"）
t_string tphp_fn_pg_escape_identifier(t_int conn_handle, t_string data) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    (void)conn;
    const char *src = STR_PTR(data);
    int len = data.length;
    if (src == NULL || len < 0) len = 0;

    // 估算最大输出：2*len + 2（引号） + 1（NUL）
    int max_out = 2 * len + 3;
    char *buf = str_pool_alloc(max_out);
    if (buf == NULL) {
        tp_throw("pg_escape_identifier: out of memory");
        return (t_string){0};
    }
    int pos = 0;
    buf[pos++] = '"';  // 开头双引号
    for (int i = 0; i < len; i++) {
        char c = src[i];
        if (c == '"') {
            buf[pos++] = '"'; buf[pos++] = '"';  // " → ""
        } else {
            buf[pos++] = c;
        }
    }
    buf[pos++] = '"';  // 结尾双引号
    buf[pos] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = buf;
    r.length = pos;
    return r;
}

// pg_escape_bytea — bytea hex 转义："\x" + hex(data)
t_string tphp_fn_pg_escape_bytea(t_int conn_handle, t_string data) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    (void)conn;
    const char *src = STR_PTR(data);
    int len = data.length;
    if (src == NULL || len <= 0) {
        // 空输入 → "\x"
        return STR_LIT("\\x");
    }
    // 输出："\x" (2) + 2*len hex chars
    int out_len = 2 + 2 * len;
    char *buf = str_pool_alloc(out_len);
    if (buf == NULL) {
        tp_throw("pg_escape_bytea: out of memory");
        return (t_string){0};
    }
    buf[0] = '\\';
    buf[1] = 'x';
    for (int i = 0; i < len; i++) {
        unsigned char b = (unsigned char)src[i];
        buf[2 + 2*i]     = _pg_hex_chars[b >> 4];
        buf[2 + 2*i + 1] = _pg_hex_chars[b & 0x0F];
    }
    buf[out_len] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = buf;
    r.length = out_len;
    return r;
}

// pg_unescape_bytea — bytea 反转义
//   支持 hex 格式（"\x..."）和 escape 格式（"\xxx" 八进制 / "\\" / "\'" 等）
t_string tphp_fn_pg_unescape_bytea(t_string data) {
    const char *src = STR_PTR(data);
    int len = data.length;
    if (src == NULL || len <= 0) return (t_string){0};

    // 检测 hex 格式：以 "\x" 或 "\X" 开头
    if (len >= 2 && src[0] == '\\' && (src[1] == 'x' || src[1] == 'X')) {
        // hex 格式：跳过 "\x"，每 2 个 hex 字符解码为 1 字节
        int max_out = (len - 2) / 2;
        if (max_out <= 0) return (t_string){0};
        char *buf = str_pool_alloc(max_out);
        if (buf == NULL) {
            tp_throw("pg_unescape_bytea: out of memory");
            return (t_string){0};
        }
        int pos = 2;
        int out_pos = 0;
        while (pos + 1 < len) {
            int hi = _pg_hex_val(src[pos]);
            int lo = _pg_hex_val(src[pos + 1]);
            if (hi < 0 || lo < 0) {
                // 跳过非 hex 字符（如空白）
                pos++;
                continue;
            }
            buf[out_pos++] = (char)((hi << 4) | lo);
            pos += 2;
        }
        buf[out_pos] = '\0';
        t_string r;
        memset(&r, 0, sizeof(r));
        r.data = buf;
        r.length = out_pos;
        return r;
    }

    // escape 格式：
    //   \xxx (3 位八进制，首位 0-3) → 1 字节
    //   \\ → \
    //   \' → '
    //   其他 \x → 保持原样（字面量）
    int max_out = len;
    char *buf = str_pool_alloc(max_out);
    if (buf == NULL) {
        tp_throw("pg_unescape_bytea: out of memory");
        return (t_string){0};
    }
    int pos = 0;
    int out_pos = 0;
    while (pos < len) {
        if (src[pos] == '\\' && pos + 1 < len) {
            char next = src[pos + 1];
            if (next >= '0' && next <= '3' && pos + 3 < len &&
                src[pos+2] >= '0' && src[pos+2] <= '7' &&
                src[pos+3] >= '0' && src[pos+3] <= '7') {
                // \xxx 八进制（3 位，首位 0-3）
                int b = (next - '0') * 64 + (src[pos+2] - '0') * 8 + (src[pos+3] - '0');
                buf[out_pos++] = (char)b;
                pos += 4;
            } else if (next == '\\') {
                // \\ → \
                buf[out_pos++] = '\\';
                pos += 2;
            } else if (next == '\'') {
                // \' → '
                buf[out_pos++] = '\'';
                pos += 2;
            } else if (next == 'b') {
                buf[out_pos++] = '\b';
                pos += 2;
            } else if (next == 'n') {
                buf[out_pos++] = '\n';
                pos += 2;
            } else if (next == 'r') {
                buf[out_pos++] = '\r';
                pos += 2;
            } else if (next == 't') {
                buf[out_pos++] = '\t';
                pos += 2;
            } else if (next == 'v') {
                buf[out_pos++] = '\v';
                pos += 2;
            } else if (next == 'f') {
                buf[out_pos++] = '\f';
                pos += 2;
            } else {
                // 未知转义 — 保持反斜杠 + 字符
                buf[out_pos++] = src[pos++];
            }
        } else {
            buf[out_pos++] = src[pos++];
        }
    }
    buf[out_pos] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = buf;
    r.length = out_pos;
    return r;
}
