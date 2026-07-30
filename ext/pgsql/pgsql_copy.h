#pragma once
// ============================================================
// pgsql_copy.h — PostgreSQL COPY 协议实现（Task 8）
//
// 实现：
//   8.1 内部消息收发
//       _pg_send_copy_data  — 发送 CopyData ('d') 消息
//       _pg_send_copy_done  — 发送 CopyDone ('c') 消息
//       _pg_send_copy_fail  — 发送 CopyFail ('f') 消息
//       _pg_recv_copy_out   — 接收并处理 COPY OUT 流（返回行数组）
//       _pg_recv_copy_done  — 接收 COPY IN 完成后的响应
//
//   8.2 PHP 层 API
//       pg_copy_to          — COPY table TO STDOUT，返回行数组
//       pg_copy_from        — COPY table FROM STDIN，从数组推送数据
//       pg_put_copy_data    — 发送 CopyData 消息
//       pg_put_copy_end     — 发送 CopyDone / CopyFail
//       pg_end_copy         — 同步等待 COPY 完成
//
// COPY 协议消息流：
//   COPY OUT (pg_copy_to):
//     客户端发送 Q(COPY ... TO STDOUT)
//     服务端响应: H(CopyOutResponse) + d(CopyData)* + c(CopyDone) + C(CommandComplete) + Z(ReadyForQuery)
//     每个 CopyData 可能含多行（按 \n 分割）
//
//   COPY IN (pg_copy_from):
//     客户端发送 Q(COPY ... FROM STDIN)
//     服务端响应: G(CopyInResponse)
//     客户端发送: d(CopyData)* + c(CopyDone)
//     服务端响应: C(CommandComplete) + Z(ReadyForQuery)
//     若出错: 客户端发送 f(CopyFail + error_msg)
//
// 依赖：pgsql.h（结构体 + 常量）+ pgsql_protocol.h（消息收发）+ pgsql_query.h（_pg_send_query）
//
// 内存管理：
//   - 返回 t_array* 用 tphp_fn_arr_create 分配，tphp_rt_register 注册
//   - 返回 t_string 用 str_pool_alloc 分配（通过 _pg_mk_str_n）
//   - 内部 char* 用 malloc/realloc，配对 free
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
// 通用 SQL 引号辅助（供 pgsql_copy.h 和 pgsql_dml.h 共用）
// ============================================================

#ifndef _PG_QUOTE_HELPERS_DEFINED
#define _PG_QUOTE_HELPERS_DEFINED

// 将字符串包装为 SQL 字面量：'value'，嵌入的 ' → ''
//   成功返回 malloc'd 字符串（调用者负责 free），失败返回 NULL
static char* _pg_quote_literal(const char *s, int len) {
    if (s == NULL || len <= 0) {
        char *out = (char*)malloc(3);
        if (out != NULL) { out[0] = '\''; out[1] = '\''; out[2] = '\0'; }
        return out;
    }
    int n_quotes = 0;
    for (int i = 0; i < len; i++) {
        if (s[i] == '\'') n_quotes++;
    }
    int out_len = 2 + len + n_quotes;
    char *out = (char*)malloc((size_t)out_len + 1);
    if (out == NULL) return NULL;
    int pos = 0;
    out[pos++] = '\'';
    for (int i = 0; i < len; i++) {
        if (s[i] == '\'') {
            out[pos++] = '\'';
            out[pos++] = '\'';
        } else {
            out[pos++] = s[i];
        }
    }
    out[pos++] = '\'';
    out[pos] = '\0';
    return out;
}

// 将字符串包装为 SQL 标识符："name"，嵌入的 " → ""
//   成功返回 malloc'd 字符串（调用者负责 free），失败返回 NULL
static char* _pg_quote_ident(const char *s, int len) {
    if (s == NULL || len <= 0) {
        char *out = (char*)malloc(3);
        if (out != NULL) { out[0] = '"'; out[1] = '"'; out[2] = '\0'; }
        return out;
    }
    int n_quotes = 0;
    for (int i = 0; i < len; i++) {
        if (s[i] == '"') n_quotes++;
    }
    int out_len = 2 + len + n_quotes;
    char *out = (char*)malloc((size_t)out_len + 1);
    if (out == NULL) return NULL;
    int pos = 0;
    out[pos++] = '"';
    for (int i = 0; i < len; i++) {
        if (s[i] == '"') {
            out[pos++] = '"';
            out[pos++] = '"';
        } else {
            out[pos++] = s[i];
        }
    }
    out[pos++] = '"';
    out[pos] = '\0';
    return out;
}

#endif // _PG_QUOTE_HELPERS_DEFINED

// ============================================================
// 8.1 内部消息收发
// ============================================================

// _pg_send_copy_data — 发送 CopyData ('d') 消息
//   payload = 原始数据（不含 NUL 终止符）
//   成功返回 0，失败返回 -1
static int _pg_send_copy_data(PGconn *conn, const char *data, int len) {
    if (conn == NULL || conn->sock < 0) return -1;
    if (data == NULL || len <= 0) {
        // 空数据 — 仍发送 CopyData 消息（payload 为空）
        return _pg_send_message(conn, PG_MSG_COPY_DATA_FE, "", 0);
    }
    return _pg_send_message(conn, PG_MSG_COPY_DATA_FE, data, len);
}

// _pg_send_copy_done — 发送 CopyDone ('c') 消息
//   无 payload
//   成功返回 0，失败返回 -1
static int _pg_send_copy_done(PGconn *conn) {
    if (conn == NULL || conn->sock < 0) return -1;
    return _pg_send_message(conn, PG_MSG_COPY_DONE_FE, "", 0);
}

// _pg_send_copy_fail — 发送 CopyFail ('f') 消息
//   payload = error_msg + NUL
//   成功返回 0，失败返回 -1
static int _pg_send_copy_fail(PGconn *conn, const char *err_msg) {
    if (conn == NULL || conn->sock < 0) return -1;
    const char *msg = (err_msg != NULL) ? err_msg : "";
    int msg_len = (int)strlen(msg);
    // payload = msg + NUL（NUL 终止符是消息的一部分）
    char *payload = (char*)malloc((size_t)msg_len + 1);
    if (payload == NULL) {
        _pg_set_error(conn, "pg: out of memory (CopyFail)");
        return -1;
    }
    memcpy(payload, msg, (size_t)msg_len);
    payload[msg_len] = '\0';
    int rc = _pg_send_message(conn, PG_MSG_COPY_FAIL, payload, msg_len + 1);
    free(payload);
    return rc;
}

// _pg_recv_copy_out — 接收并处理 COPY OUT 流
//   预期消息序列: H(CopyOutResponse) + d(CopyData)* + c(CopyDone) + C(CommandComplete) + Z(ReadyForQuery)
//   每个 CopyData 可能含多行（按 \n 分割），也可能是不完整的行（需跨 CopyData 拼接）
//   错误时返回 NULL（conn->last_error 已设置），调用者负责检查
//   成功返回 t_array*（已注册到 tphp_rt_register），每元素为一行字符串
static t_array* _pg_recv_copy_out(PGconn *conn) {
    if (conn == NULL) return NULL;

    t_array *rows = tphp_fn_arr_create(8);
    if (rows == NULL) {
        _pg_set_error(conn, "pg: COPY OUT: out of memory (rows)");
        return NULL;
    }

    // pending buffer：累积跨 CopyData 的不完整行
    char *pending = NULL;
    int pending_len = 0;
    int pending_cap = 0;
    int got_done = 0;
    int got_error = 0;

    while (1) {
        char *data = NULL;
        int len = 0;
        char type = _pg_recv_message(conn, &data, &len);

        if (type == 0) {
            if (data != NULL) free(data);
            if (pending != NULL) free(pending);
            tphp_fn_arr_free(rows);
            if (conn->last_error == NULL) {
                _pg_set_error(conn, "pg: COPY OUT: connection lost");
            }
            return NULL;
        }

        if (type == PG_MSG_ERROR_RESPONSE) {
            // ErrorResponse — 记录错误，继续 drain 直到 ReadyForQuery
            _pg_parse_error(conn, data, len, NULL, NULL, NULL, NULL, NULL);
            free(data);
            got_error = 1;
            continue;
        }

        if (type == PG_MSG_NOTICE_RESPONSE) {
            // NoticeResponse — 记录通知，继续
            char *msg = NULL;
            _pg_parse_error(conn, data, len, NULL, NULL, &msg, NULL, NULL);
            if (msg != NULL) {
                if (conn->last_notice != NULL) free(conn->last_notice);
                conn->last_notice = msg;
            }
            free(data);
            continue;
        }

        if (type == PG_MSG_COPY_OUT_RESPONSE) {
            // CopyOutResponse: 1 byte overall format + 2 bytes num_cols + 2*num_cols bytes per-col format
            // 我们使用 text 格式，无需解析 payload
            free(data);
            continue;
        }

        if (type == PG_MSG_COPY_DATA && !got_error && !got_done) {
            // CopyData — 追加到 pending buffer，然后按 '\n' 分行
            int need = pending_len + len;
            if (need > pending_cap) {
                int new_cap = (pending_cap > 0) ? pending_cap * 2 : 256;
                while (new_cap < need) new_cap *= 2;
                char *new_buf = (char*)realloc(pending, (size_t)new_cap);
                if (new_buf == NULL) {
                    free(data);
                    if (pending != NULL) free(pending);
                    tphp_fn_arr_free(rows);
                    _pg_set_error(conn, "pg: COPY OUT: out of memory (pending)");
                    return NULL;
                }
                pending = new_buf;
                pending_cap = new_cap;
            }
            memcpy(pending + pending_len, data, (size_t)len);
            pending_len += len;
            free(data);

            // 按 '\n' 分行：每个完整的行（含 '\n' 之前的内容）作为一行加入结果
            int start = 0;
            for (int i = 0; i < pending_len; i++) {
                if (pending[i] == '\n') {
                    int line_len = i - start;
                    // 即使 line_len == 0（空行）也加入，保持行数一致
                    t_string line = _pg_mk_str_n(pending + start, line_len);
                    rows = tphp_fn_arr_push(rows, VAR_STRING(line));
                    start = i + 1;
                }
            }
            // 把剩余的不完整行移到 pending 开头
            if (start > 0) {
                int remaining = pending_len - start;
                if (remaining > 0) {
                    memmove(pending, pending + start, (size_t)remaining);
                }
                pending_len = remaining;
            }
            continue;
        }

        if (type == PG_MSG_COPY_DONE) {
            // CopyDone — 服务端不再发送数据
            got_done = 1;
            // 若 pending 中还有未以 '\n' 结尾的数据，作为最后一行
            if (pending != NULL && pending_len > 0) {
                t_string line = _pg_mk_str_n(pending, pending_len);
                rows = tphp_fn_arr_push(rows, VAR_STRING(line));
            }
            if (pending != NULL) { free(pending); pending = NULL; }
            pending_len = 0;
            free(data);
            continue;
        }

        if (type == PG_MSG_COMMAND_COMPLETE) {
            // CommandComplete: "COPY N" — 跳过
            free(data);
            continue;
        }

        if (type == PG_MSG_READY_FOR_QUERY) {
            // ReadyForQuery — COPY 流结束
            _pg_handle_ready_for_query(conn, data, len);
            free(data);
            conn->status = CONN_OK;
            break;
        }

        // 未知消息类型 — 跳过
        free(data);
    }

    if (pending != NULL) free(pending);

    if (got_error) {
        tphp_fn_arr_free(rows);
        return NULL;
    }

    tphp_rt_register((void*)rows, 1);
    return rows;
}

// _pg_recv_copy_done — 接收 COPY IN 完成后的响应
//   预期消息序列: C(CommandComplete) + Z(ReadyForQuery)
//   也可能收到 ErrorResponse（若 CopyFail 或服务端错误）
//   成功返回 0，失败返回 -1（conn->last_error 已设置）
static int _pg_recv_copy_done(PGconn *conn) {
    if (conn == NULL) return -1;

    while (1) {
        char *data = NULL;
        int len = 0;
        char type = _pg_recv_message(conn, &data, &len);

        if (type == 0) {
            if (data != NULL) free(data);
            if (conn->last_error == NULL) {
                _pg_set_error(conn, "pg: COPY IN: connection lost");
            }
            return -1;
        }

        if (type == PG_MSG_ERROR_RESPONSE) {
            _pg_parse_error(conn, data, len, NULL, NULL, NULL, NULL, NULL);
            free(data);
            // 继续 drain 直到 ReadyForQuery
            continue;
        }

        if (type == PG_MSG_NOTICE_RESPONSE) {
            char *msg = NULL;
            _pg_parse_error(conn, data, len, NULL, NULL, &msg, NULL, NULL);
            if (msg != NULL) {
                if (conn->last_notice != NULL) free(conn->last_notice);
                conn->last_notice = msg;
            }
            free(data);
            continue;
        }

        if (type == PG_MSG_COMMAND_COMPLETE) {
            // "COPY N" — 跳过
            free(data);
            continue;
        }

        if (type == PG_MSG_READY_FOR_QUERY) {
            _pg_handle_ready_for_query(conn, data, len);
            free(data);
            conn->status = CONN_OK;
            return 0;
        }

        // 未知消息类型 — 跳过
        free(data);
    }
}

// ============================================================
// 8.2 PHP 层 API
// ============================================================

// pg_copy_to — COPY table TO STDOUT，返回行数组
//   执行 "COPY "table" TO STDOUT WITH (DELIMITER 'sep', NULL 'null_as')" 查询
//   接收 CopyOutResponse('H') + CopyData('d')* + CopyDone('c') + CommandComplete('C') + ReadyForQuery('Z')
//   每个 CopyData 可能含多行（按 \n 分割）
//   返回 t_array*（每元素一行字符串），错误 tp_throw 并返回 NULL
t_array* tphp_fn_pg_copy_to(t_int conn_handle, t_string table_name, t_string separator, t_string null_as) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_copy_to: invalid connection handle");
        return NULL;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_copy_to: empty table name");
        return NULL;
    }

    // 默认值：分隔符 '\t'，NULL 标记 '\N'
    const char *sep = (separator.length > 0) ? STR_PTR(separator) : "\t";
    const char *null_str = (null_as.length > 0) ? STR_PTR(null_as) : "\\N";

    // 转义标识符和字面量
    char *table_q = _pg_quote_ident(table, table_name.length);
    if (table_q == NULL) {
        tp_throw("pg_copy_to: out of memory (table escape)");
        return NULL;
    }
    char *sep_q = _pg_quote_literal(sep, (int)strlen(sep));
    char *null_q = _pg_quote_literal(null_str, (int)strlen(null_str));
    if (sep_q == NULL || null_q == NULL) {
        free(table_q);
        if (sep_q != NULL) free(sep_q);
        if (null_q != NULL) free(null_q);
        tp_throw("pg_copy_to: out of memory (option escape)");
        return NULL;
    }

    // 构建 SQL: COPY "table" TO STDOUT WITH (DELIMITER 'sep', NULL 'null_as')
    //   估算长度：固定部分约 60 字节 + table_q + sep_q + null_q
    int sql_cap = 80 + (int)strlen(table_q) + (int)strlen(sep_q) + (int)strlen(null_q);
    char *sql = (char*)malloc((size_t)sql_cap);
    if (sql == NULL) {
        free(table_q); free(sep_q); free(null_q);
        tp_throw("pg_copy_to: out of memory (sql)");
        return NULL;
    }
    int n = snprintf(sql, (size_t)sql_cap, "COPY %s TO STDOUT WITH (DELIMITER %s, NULL %s)",
                     table_q, sep_q, null_q);
    free(table_q); free(sep_q); free(null_q);
    if (n < 0 || n >= sql_cap) {
        free(sql);
        tp_throw("pg_copy_to: SQL too long");
        return NULL;
    }

    // 清除上次错误
    if (conn->last_error != NULL) {
        free(conn->last_error);
        conn->last_error = NULL;
    }

    // 发送 Query 消息
    if (_pg_send_query(conn, sql) != 0) {
        free(sql);
        tp_throw(conn->last_error ? conn->last_error : "pg_copy_to: send query failed");
        return NULL;
    }
    free(sql);

    // 接收 COPY OUT 流
    t_array *rows = _pg_recv_copy_out(conn);
    if (rows == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_copy_to: COPY OUT failed");
        return NULL;
    }
    return rows;
}

// pg_copy_from — COPY table FROM STDIN，从数组推送数据
//   执行 "COPY "table" FROM STDIN WITH (DELIMITER 'sep', NULL 'null_as')" 查询
//   接收 CopyInResponse('G')
//   发送每个数组元素为 CopyData('d') 消息（每行以 '\n' 结尾）
//   发送 CopyDone('c')
//   接收 CommandComplete('C') + ReadyForQuery('Z')
//   返回 true 成功，false 失败
t_bool tphp_fn_pg_copy_from(t_int conn_handle, t_string table_name, t_array *rows, t_string separator, t_string null_as) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_copy_from: invalid connection handle");
        return false;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_copy_from: empty table name");
        return false;
    }
    if (rows == NULL) {
        tp_throw("pg_copy_from: null rows array");
        return false;
    }

    const char *sep = (separator.length > 0) ? STR_PTR(separator) : "\t";
    const char *null_str = (null_as.length > 0) ? STR_PTR(null_as) : "\\N";

    // 转义标识符和字面量
    char *table_q = _pg_quote_ident(table, table_name.length);
    if (table_q == NULL) {
        tp_throw("pg_copy_from: out of memory (table escape)");
        return false;
    }
    char *sep_q = _pg_quote_literal(sep, (int)strlen(sep));
    char *null_q = _pg_quote_literal(null_str, (int)strlen(null_str));
    if (sep_q == NULL || null_q == NULL) {
        free(table_q);
        if (sep_q != NULL) free(sep_q);
        if (null_q != NULL) free(null_q);
        tp_throw("pg_copy_from: out of memory (option escape)");
        return false;
    }

    // 构建 SQL
    int sql_cap = 80 + (int)strlen(table_q) + (int)strlen(sep_q) + (int)strlen(null_q);
    char *sql = (char*)malloc((size_t)sql_cap);
    if (sql == NULL) {
        free(table_q); free(sep_q); free(null_q);
        tp_throw("pg_copy_from: out of memory (sql)");
        return false;
    }
    int n = snprintf(sql, (size_t)sql_cap, "COPY %s FROM STDIN WITH (DELIMITER %s, NULL %s)",
                     table_q, sep_q, null_q);
    free(table_q); free(sep_q); free(null_q);
    if (n < 0 || n >= sql_cap) {
        free(sql);
        tp_throw("pg_copy_from: SQL too long");
        return false;
    }

    // 清除上次错误
    if (conn->last_error != NULL) {
        free(conn->last_error);
        conn->last_error = NULL;
    }

    // 发送 Query 消息
    if (_pg_send_query(conn, sql) != 0) {
        free(sql);
        tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: send query failed");
        return false;
    }
    free(sql);

    // 接收 CopyInResponse ('G') 或 ErrorResponse
    //   可能在 CopyInResponse 之前收到 NoticeResponse
    int got_copy_in = 0;
    while (!got_copy_in) {
        char *data = NULL;
        int len = 0;
        char type = _pg_recv_message(conn, &data, &len);
        if (type == 0) {
            if (data != NULL) free(data);
            tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: connection lost");
            return false;
        }
        if (type == PG_MSG_ERROR_RESPONSE) {
            _pg_parse_error(conn, data, len, NULL, NULL, NULL, NULL, NULL);
            free(data);
            // drain 直到 ReadyForQuery
            _pg_recv_copy_done(conn);
            tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: COPY command failed");
            return false;
        }
        if (type == PG_MSG_NOTICE_RESPONSE) {
            char *msg = NULL;
            _pg_parse_error(conn, data, len, NULL, NULL, &msg, NULL, NULL);
            if (msg != NULL) {
                if (conn->last_notice != NULL) free(conn->last_notice);
                conn->last_notice = msg;
            }
            free(data);
            continue;  // 继续等待 CopyInResponse
        }
        if (type != PG_MSG_COPY_IN_RESPONSE) {
            char errbuf[128];
            snprintf(errbuf, sizeof(errbuf), "pg_copy_from: expected CopyInResponse('G'), got '%c'", type);
            _pg_set_error(conn, errbuf);
            free(data);
            tp_throw(errbuf);
            return false;
        }
        // CopyInResponse — payload 跳过
        //   格式: 1 byte overall format + 2 bytes num_cols + 2*num_cols bytes per-col format
        free(data);
        got_copy_in = 1;
    }

    // 发送每个数组元素为 CopyData 消息
    //   每行末尾确保有 '\n'（COPY 协议要求行以 '\n' 分隔）
    for (int i = 0; i < rows->length; i++) {
        t_var *row_var = &rows->entries[i].val;
        if (row_var->type != TYPE_STRING) {
            // 非 string 元素 — 跳过（调用者应传入 string 数组）
            continue;
        }
        const char *row_str = STR_PTR(row_var->value._string);
        int row_len = row_var->value._string.length;
        if (row_str == NULL || row_len <= 0) {
            // 空行 — 发送仅含 '\n' 的 CopyData
            if (_pg_send_copy_data(conn, "\n", 1) != 0) {
                tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: send CopyData failed");
                return false;
            }
            continue;
        }

        // 检查行末是否已有 '\n'
        if (row_str[row_len - 1] == '\n') {
            // 已有 '\n' — 直接发送
            if (_pg_send_copy_data(conn, row_str, row_len) != 0) {
                tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: send CopyData failed");
                return false;
            }
        } else {
            // 无 '\n' — 追加后发送
            char *buf = (char*)malloc((size_t)row_len + 2);
            if (buf == NULL) {
                _pg_set_error(conn, "pg_copy_from: out of memory (row buffer)");
                tp_throw("pg_copy_from: out of memory (row buffer)");
                return false;
            }
            memcpy(buf, row_str, (size_t)row_len);
            buf[row_len] = '\n';
            buf[row_len + 1] = '\0';
            int rc = _pg_send_copy_data(conn, buf, row_len + 1);
            free(buf);
            if (rc != 0) {
                tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: send CopyData failed");
                return false;
            }
        }
    }

    // 发送 CopyDone
    if (_pg_send_copy_done(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: send CopyDone failed");
        return false;
    }

    // 接收 CommandComplete + ReadyForQuery
    if (_pg_recv_copy_done(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_copy_from: COPY completion failed");
        return false;
    }

    return true;
}

// pg_put_copy_data — 发送 CopyData 消息
//   假设当前连接已处于 COPY IN 状态（由 pg_query("COPY ... FROM STDIN") 发起）
//   data: 要发送的数据（通常为一行文本，以 '\n' 结尾）
//   返回 true 成功，false 失败
t_bool tphp_fn_pg_put_copy_data(t_int conn_handle, t_string data) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL || conn->sock < 0) {
        tp_throw("pg_put_copy_data: invalid connection handle");
        return false;
    }
    const char *data_str = STR_PTR(data);
    int data_len = data.length;
    if (data_str == NULL || data_len <= 0) {
        tp_throw("pg_put_copy_data: empty data");
        return false;
    }

    if (_pg_send_copy_data(conn, data_str, data_len) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_put_copy_data: send failed");
        return false;
    }
    return true;
}

// pg_put_copy_end — 发送 CopyDone（正常）或 CopyFail（错误）
//   error_msg 为空时发 CopyDone('c')，非空发 CopyFail('f') + error_msg
//   发送后同步等待 CommandComplete/ErrorResponse + ReadyForQuery，使连接回到就绪状态
//   返回 true 成功，false 失败
t_bool tphp_fn_pg_put_copy_end(t_int conn_handle, t_string error_msg) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL || conn->sock < 0) {
        tp_throw("pg_put_copy_end: invalid connection handle");
        return false;
    }

    const char *err_str = STR_PTR(error_msg);
    if (error_msg.length > 0 && err_str != NULL) {
        // 非空 error_msg — 发送 CopyFail
        if (_pg_send_copy_fail(conn, err_str) != 0) {
            tp_throw(conn->last_error ? conn->last_error : "pg_put_copy_end: send CopyFail failed");
            return false;
        }
    } else {
        // 空 error_msg — 发送 CopyDone
        if (_pg_send_copy_done(conn) != 0) {
            tp_throw(conn->last_error ? conn->last_error : "pg_put_copy_end: send CopyDone failed");
            return false;
        }
    }

    // 同步等待 COPY 完成（CommandComplete/ErrorResponse + ReadyForQuery）
    if (_pg_recv_copy_done(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_put_copy_end: COPY completion failed");
        return false;
    }
    return true;
}

// pg_end_copy — 同步等待 COPY 完成
//   pg_put_copy_end 已内置同步（_pg_recv_copy_done），pg_copy_to/pg_copy_from 也各自完成同步，
//   因此本函数在无进行中 COPY 时直接返回 true（兼容 PHP 调用约定）
//   返回 true 成功，false 失败
t_bool tphp_fn_pg_end_copy(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL || conn->sock < 0) {
        tp_throw("pg_end_copy: invalid connection handle");
        return false;
    }
    // pg_put_copy_end / pg_copy_from / pg_copy_to 已完成同步，此处直接返回 true
    return true;
}
