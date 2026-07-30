#pragma once
// ============================================================
// pgsql_query.h — PostgreSQL 简单查询协议 + 扩展查询协议实现
//
// Task 4: 简单查询协议（Simple Query Protocol）
//   4.1 _pg_send_query              — 发送 Query 消息
//   4.2 _pg_parse_row_description   — 解析 RowDescription ('T')
//   4.3 _pg_parse_data_row          — 解析 DataRow ('D')
//   4.4 _pg_parse_command_complete  — 解析 CommandComplete ('C')
//   4.5 EmptyQueryResponse ('I')    — 在主循环中处理
//   4.6 _pg_handle_ready_for_query  — 处理 ReadyForQuery ('Z')
//   4.7 _pg_result_new / free / append_row — PGresult 生命周期
//   4.8 _pg_exec_query              — 主查询循环
//   4.9 tphp_fn_pg_query            — PHP 层包装
//
// Task 5: 扩展查询协议（Extended Query Protocol）
//   5.1 _pg_send_parse              — Parse 消息 ('P')
//   5.2 _pg_send_bind               — Bind 消息 ('B')
//   5.3 _pg_send_describe           — Describe 消息 ('D')
//   5.4 _pg_send_execute            — Execute 消息 ('E')
//   5.5 _pg_send_sync / _pg_send_flush — Sync ('S') / Flush ('H')
//   5.7 _pg_exec_query_params       — 参数化查询执行
//   5.8 tphp_fn_pg_query_params / pg_prepare / pg_execute — PHP 层包装
//   5.9 _pg_array_to_params         — t_array* → const char** 转换
//
// 依赖：pgsql.h（结构体 + 常量）+ pgsql_protocol.h（消息收发 + 错误解析）
//
// 大端序：PostgreSQL 协议所有多字节整数使用大端序（network byte order）
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
// 辅助：写入 16 位大端序（pgsql_protocol.h 仅提供 _pg_write_be32）
// ============================================================
#ifndef _PG_WRITE_BE16_DEFINED
#define _PG_WRITE_BE16_DEFINED
static inline void _pg_write_be16(uint8_t *p, uint16_t v) {
    p[0] = (uint8_t)((v >> 8) & 0xFF);
    p[1] = (uint8_t)(v & 0xFF);
}
#endif

// ============================================================
// 4.7 PGresult 创建和销毁
// ============================================================

// 创建新 PGresult（空，status 默认 PGRES_TUPLES_OK，由调用者覆盖）
static PGresult* _pg_result_new(void) {
    PGresult *res = (PGresult*)calloc(1, sizeof(PGresult));
    if (res == NULL) return NULL;
    res->status = PGRES_TUPLES_OK;
    res->fields = NULL;
    res->num_fields = 0;
    res->rows = NULL;
    res->row_lens = NULL;
    res->num_rows = 0;
    res->cap_rows = 0;
    res->cur_row = 0;
    res->affected = 0;
    res->last_oid = 0;
    res->err_msg = NULL;
    return res;
}

// 释放 PGresult 及其所有内部分配
//   rows/row_lens 为扁平布局：长度 = num_rows * num_fields
static void _pg_result_free(PGresult *res) {
    if (res == NULL) return;
    if (res->fields != NULL) free(res->fields);
    if (res->rows != NULL) {
        int total = res->num_rows * res->num_fields;
        for (int i = 0; i < total; i++) {
            if (res->rows[i] != NULL) free(res->rows[i]);
        }
        free(res->rows);
    }
    if (res->row_lens != NULL) free(res->row_lens);
    if (res->err_msg != NULL) free(res->err_msg);
    if (res->err_data != NULL) free(res->err_data);
    free(res);
}

// 追加一行到 PGresult（自动扩容 cap_rows）
//   row:   char* 数组（长度 = num_fields），元素可为 NULL（表示 SQL NULL）
//   lens:  int 数组（长度 = num_fields），-1 表示 NULL
//   注意：调用后 row 中的指针所有权转移给 res（row 数组本身由调用者释放）
//   成功返回 0，失败返回 -1（调用者需释放 row 中的各个值）
static int _pg_result_append_row(PGresult *res, char **row, int *lens) {
    if (res == NULL || row == NULL || lens == NULL) return -1;
    if (res->num_fields <= 0) return -1;

    if (res->num_rows >= res->cap_rows) {
        int new_cap = res->cap_rows > 0 ? res->cap_rows * 2 : 8;
        size_t alloc_rows = (size_t)new_cap * (size_t)res->num_fields * sizeof(char*);
        char **new_rows = (char**)realloc(res->rows, alloc_rows);
        if (new_rows == NULL) return -1;
        res->rows = new_rows;

        size_t alloc_lens = (size_t)new_cap * (size_t)res->num_fields * sizeof(int);
        int *new_lens = (int*)realloc(res->row_lens, alloc_lens);
        if (new_lens == NULL) return -1;
        res->row_lens = new_lens;
        res->cap_rows = new_cap;
    }

    int nf = res->num_fields;
    int base = res->num_rows * nf;
    for (int i = 0; i < nf; i++) {
        res->rows[base + i] = row[i];
        res->row_lens[base + i] = lens[i];
    }
    res->num_rows++;
    return 0;
}

// ============================================================
// 4.2 RowDescription 解析（'T' 消息）
//   格式：2字节字段数 + 每字段（字段名\0 + 4表OID + 2列号 + 4类型OID + 2类型大小 + 4类型修饰符 + 2格式）
// ============================================================
static int _pg_parse_row_description(PGresult *res, const char *data, int len) {
    if (res == NULL || data == NULL || len < 2) return -1;

    int pos = 0;
    int num_fields = (int)_pg_read_be16((const uint8_t*)data);
    pos += 2;

    if (res->fields != NULL) free(res->fields);
    res->fields = (pg_field_meta*)calloc((size_t)num_fields, sizeof(pg_field_meta));
    if (res->fields == NULL) return -1;
    res->num_fields = num_fields;

    for (int i = 0; i < num_fields; i++) {
        if (pos >= len) return -1;

        // 字段名（NUL-terminated）
        const char *name = data + pos;
        int name_len = 0;
        while (pos + name_len < len && name[name_len] != '\0') name_len++;
        if (name_len >= (int)sizeof(res->fields[i].name)) {
            name_len = (int)sizeof(res->fields[i].name) - 1;
        }
        memcpy(res->fields[i].name, name, (size_t)name_len);
        res->fields[i].name[name_len] = '\0';
        pos += name_len + 1;  // 跳过名字 + NUL

        // 后续 4+2+4+2+4+2 = 18 字节
        if (pos + 18 > len) return -1;

        res->fields[i].table_oid  = _pg_read_be32((const uint8_t*)(data + pos)); pos += 4;
        res->fields[i].column_num = _pg_read_be16((const uint8_t*)(data + pos)); pos += 2;
        res->fields[i].type_oid   = _pg_read_be32((const uint8_t*)(data + pos)); pos += 4;
        res->fields[i].type_size  = (int16_t)_pg_read_be16((const uint8_t*)(data + pos)); pos += 2;
        res->fields[i].type_mod   = (int32_t)_pg_read_be32((const uint8_t*)(data + pos)); pos += 4;
        res->fields[i].format     = (int16_t)_pg_read_be16((const uint8_t*)(data + pos)); pos += 2;
    }
    return 0;
}

// ============================================================
// 4.3 DataRow 解析（'D' 消息）
//   格式：2字节列数 + 每列（4字节长度be + 数据，长度=-1 表示 NULL）
//   深拷贝：从消息 buffer 复制到 PGresult（buffer 会被下次接收覆盖）
// ============================================================
static int _pg_parse_data_row(PGresult *res, const char *data, int len) {
    if (res == NULL || data == NULL || len < 2) return -1;

    int pos = 0;
    int num_cols = (int)_pg_read_be16((const uint8_t*)data);
    pos += 2;

    // 若 PGresult 还没有字段数（理论上应先收到 'T'），用 DataRow 的列数兜底
    if (res->num_fields <= 0) {
        res->num_fields = num_cols;
    }
    int nf = res->num_fields;

    char **row = (char**)malloc(sizeof(char*) * (size_t)nf);
    int *lens = (int*)malloc(sizeof(int) * (size_t)nf);
    if (row == NULL || lens == NULL) {
        if (row != NULL) free(row);
        if (lens != NULL) free(lens);
        return -1;
    }
    for (int i = 0; i < nf; i++) {
        row[i] = NULL;
        lens[i] = -1;
    }

    for (int i = 0; i < nf; i++) {
        if (pos + 4 > len) break;  // 数据不完整
        int32_t col_len = (int32_t)_pg_read_be32((const uint8_t*)(data + pos));
        pos += 4;

        if (col_len < 0) {
            // SQL NULL
            row[i] = NULL;
            lens[i] = -1;
        } else {
            if (pos + col_len > len) {
                row[i] = NULL;
                lens[i] = -1;
                continue;
            }
            // 深拷贝（消息 buffer 会被下次接收覆盖）
            char *val = (char*)malloc((size_t)col_len + 1);
            if (val == NULL) {
                row[i] = NULL;
                lens[i] = -1;
                continue;
            }
            memcpy(val, data + pos, (size_t)col_len);
            val[col_len] = '\0';
            row[i] = val;
            lens[i] = col_len;
            pos += col_len;
        }
    }

    int rc = _pg_result_append_row(res, row, lens);
    if (rc != 0) {
        // 追加失败 — 释放已分配的各个值
        for (int i = 0; i < nf; i++) {
            if (row[i] != NULL) free(row[i]);
        }
    }
    free(row);
    free(lens);
    return rc;
}

// ============================================================
// 4.4 CommandComplete 解析（'C' 消息）
//   格式：命令 tag 字符串 + '\0'
//   tag 形式：
//     INSERT 0 1234   → affected=1234, last_oid=0（PG 8.0+ OID 可选）
//     UPDATE 5        → affected=5
//     DELETE 3        → affected=3
//     SELECT 10       → affected=10（行数，与 DataRow 计数一致）
//     COPY 100        → affected=100
//     MOVE 5 / FETCH 10 → affected=N
// ============================================================
static int _pg_parse_command_complete(PGresult *res, const char *data, int len) {
    if (res == NULL || data == NULL || len <= 0) return -1;

    // 复制 tag 到 cmd_tag（NUL-terminated）
    int tlen = 0;
    while (tlen < len && tlen < (int)sizeof(res->cmd_tag) - 1 && data[tlen] != '\0') {
        res->cmd_tag[tlen] = data[tlen];
        tlen++;
    }
    res->cmd_tag[tlen] = '\0';

    // 解析命令名（首词）
    char cmd[16];
    int cmd_len = 0;
    int pos = 0;
    while (pos < tlen && data[pos] != ' ' && cmd_len < (int)sizeof(cmd) - 1) {
        cmd[cmd_len++] = data[pos++];
    }
    cmd[cmd_len] = '\0';

    if (strcmp(cmd, "INSERT") == 0) {
        // "INSERT OID COUNT" — 跳过空格，读 OID，再读 count
        while (pos < tlen && data[pos] == ' ') pos++;
        long oid = 0;
        while (pos < tlen && data[pos] >= '0' && data[pos] <= '9') {
            oid = oid * 10 + (data[pos] - '0');
            pos++;
        }
        while (pos < tlen && data[pos] == ' ') pos++;
        long count = 0;
        while (pos < tlen && data[pos] >= '0' && data[pos] <= '9') {
            count = count * 10 + (data[pos] - '0');
            pos++;
        }
        res->affected = (int)count;
        res->last_oid = (uint32_t)oid;
    } else {
        // 其他命令："CMD COUNT" — 读最后的数字
        while (pos < tlen && data[pos] == ' ') pos++;
        long count = 0;
        while (pos < tlen && data[pos] >= '0' && data[pos] <= '9') {
            count = count * 10 + (data[pos] - '0');
            pos++;
        }
        res->affected = (int)count;
        res->last_oid = 0;
    }
    return 0;
}

// ============================================================
// 4.6 ReadyForQuery 处理（'Z' 消息）
//   1 字节事务状态：I(空闲) / T(事务块中) / E(中止的事务块中)
// ============================================================
static int _pg_handle_ready_for_query(PGconn *conn, const char *data, int len) {
    if (conn == NULL) return -1;
    if (data != NULL && len >= 1) {
        conn->trans_status = data[0];
    }
    return 0;
}

// ============================================================
// 通用结果接收循环（简单查询和扩展查询共用）
//   处理所有后端→前端消息类型：T/D/C/I/E/N/Z/S/G/H/1/2/n/t
//   阻塞直到 ReadyForQuery ('Z')
//   返回 PGresult*（最后一个结果集）；错误时返回 NULL 并设置 conn->last_error
//   注意：简单查询可能返回多个结果集（多语句），此实现取最后一个
// ============================================================
static PGresult* _pg_receive_query_results(PGconn *conn) {
    if (conn == NULL) return NULL;
    PGresult *res = NULL;
    int got_error = 0;

    while (1) {
        char *data = NULL;
        int len = 0;
        char type = _pg_recv_message(conn, &data, &len);

        if (type == 0) {
            if (data != NULL) free(data);
            if (res != NULL) _pg_result_free(res);
            if (conn->last_error == NULL) {
                _pg_set_error(conn, "pg: connection lost during query");
            }
            return NULL;
        }

        int should_break = 0;

        switch (type) {
            case PG_MSG_PARSE_COMPLETE:       // '1' ParseComplete
            case PG_MSG_BIND_COMPLETE:        // '2' BindComplete
            case PG_MSG_PARAMETER_DESCRIPTION: // 't' ParameterDescription
            case PG_MSG_CLOSE_COMPLETE:       // '3' CloseComplete
            case PG_MSG_PORTAL_SUSPENDED:     // 's' PortalSuspended
                // 无 payload 需处理（No-op）
                break;

            case PG_MSG_ROW_DESCRIPTION:      // 'T' RowDescription
                // 新结果集开始 — 释放旧结果，创建新的
                if (res != NULL) _pg_result_free(res);
                res = _pg_result_new();
                if (res == NULL) {
                    free(data);
                    _pg_set_error(conn, "pg: out of memory (PGresult)");
                    return NULL;
                }
                _pg_parse_row_description(res, data, len);
                res->status = PGRES_TUPLES_OK;
                break;

            case PG_MSG_DATA_ROW:             // 'D' DataRow
                if (res == NULL) {
                    res = _pg_result_new();
                    if (res == NULL) {
                        free(data);
                        _pg_set_error(conn, "pg: out of memory (PGresult)");
                        return NULL;
                    }
                }
                _pg_parse_data_row(res, data, len);
                break;

            case PG_MSG_COMMAND_COMPLETE:     // 'C' CommandComplete
                if (res == NULL) {
                    // 无结果集（INSERT/UPDATE/DELETE）
                    res = _pg_result_new();
                    if (res == NULL) {
                        free(data);
                        _pg_set_error(conn, "pg: out of memory (PGresult)");
                        return NULL;
                    }
                    res->status = PGRES_COMMAND_OK;
                } else if (res->status != PGRES_FATAL_ERROR) {
                    res->status = (res->num_fields > 0) ? PGRES_TUPLES_OK : PGRES_COMMAND_OK;
                }
                _pg_parse_command_complete(res, data, len);
                break;

            case PG_MSG_NO_DATA:              // 'n' NoData
                // 无结果集（如 INSERT/UPDATE 经 Describe 返回）
                if (res == NULL) {
                    res = _pg_result_new();
                    if (res == NULL) {
                        free(data);
                        _pg_set_error(conn, "pg: out of memory (PGresult)");
                        return NULL;
                    }
                    res->status = PGRES_COMMAND_OK;
                }
                break;

            case PG_MSG_EMPTY_QUERY_RESPONSE: // 'I' EmptyQueryResponse
                if (res != NULL) _pg_result_free(res);
                res = _pg_result_new();
                if (res == NULL) {
                    free(data);
                    _pg_set_error(conn, "pg: out of memory (PGresult)");
                    return NULL;
                }
                res->status = PGRES_EMPTY_QUERY;
                break;

            case PG_MSG_ERROR_RESPONSE:       // 'E' ErrorResponse
                if (res != NULL) _pg_result_free(res);
                res = _pg_result_new();
                if (res == NULL) {
                    free(data);
                    _pg_set_error(conn, "pg: out of memory (PGresult)");
                    return NULL;
                }
                res->status = PGRES_FATAL_ERROR;
                {
                    char *msg = NULL;
                    _pg_parse_error(conn, data, len, NULL, NULL, &msg, NULL, NULL);
                    if (msg != NULL) {
                        if (res->err_msg != NULL) free(res->err_msg);
                        res->err_msg = msg;
                    }
                    // 存储原始 ErrorResponse payload 供 pg_result_error_field 使用
                    if (data != NULL && len > 0) {
                        res->err_data = (char*)malloc((size_t)len);
                        if (res->err_data != NULL) {
                            memcpy(res->err_data, data, (size_t)len);
                            res->err_data_len = len;
                        }
                    }
                }
                got_error = 1;
                break;

            case PG_MSG_NOTICE_RESPONSE:      // 'N' NoticeResponse
                {
                    char *msg = NULL;
                    _pg_parse_error(conn, data, len, NULL, NULL, &msg, NULL, NULL);
                    if (msg != NULL) {
                        int msg_len = (int)strlen(msg);
                        _pg_invoke_notice_cb(conn, msg, msg_len);
                        if (conn->last_notice != NULL) free(conn->last_notice);
                        conn->last_notice = msg;
                    }
                }
                break;

            case PG_MSG_PARAMETER_STATUS:     // 'S' ParameterStatus
                // 格式：key\0value\0
                if (data != NULL && len > 0) {
                    const char *key = data;
                    int klen = (int)strlen(key);
                    if (klen + 1 < len) {
                        const char *val = data + klen + 1;
                        if (strcmp(key, "server_version") == 0) {
                            if (conn->server_version != NULL) free(conn->server_version);
                            conn->server_version = (char*)malloc(strlen(val) + 1);
                            if (conn->server_version != NULL) strcpy(conn->server_version, val);
                        } else if (strcmp(key, "client_encoding") == 0) {
                            if (conn->client_encoding != NULL) free(conn->client_encoding);
                            conn->client_encoding = (char*)malloc(strlen(val) + 1);
                            if (conn->client_encoding != NULL) strcpy(conn->client_encoding, val);
                        } else if (strcmp(key, "standard_conforming_strings") == 0) {
                            conn->std_conforming_strings = (strcmp(val, "on") == 0) ? 1 : 0;
                        }
                    }
                }
                break;

            case PG_MSG_READY_FOR_QUERY:      // 'Z' ReadyForQuery
                _pg_handle_ready_for_query(conn, data, len);
                // 收到 ReadyForQuery 表示连接已就绪，可接受下一个查询
                // （_pg_parse_error 可能把 status 设为 CONN_BAD，此处重置）
                conn->status = CONN_OK;
                should_break = 1;
                break;

            case PG_MSG_COPY_IN_RESPONSE:     // 'G' CopyInResponse
                // COPY FROM STDIN — 返回 PGRES_COPY_IN，连接进入 COPY IN 状态
                // 调用者用 pg_put_copy_data / pg_put_copy_end / pg_end_copy 完成 COPY
                if (res != NULL) _pg_result_free(res);
                res = _pg_result_new();
                if (res != NULL) {
                    res->status = PGRES_COPY_IN;
                }
                should_break = 1;  // 立即返回，不等待 ReadyForQuery
                break;

            case PG_MSG_COPY_OUT_RESPONSE:    // 'H' CopyOutResponse
                // COPY TO STDOUT — 返回 PGRES_COPY_OUT，连接进入 COPY OUT 状态
                if (res != NULL) _pg_result_free(res);
                res = _pg_result_new();
                if (res != NULL) {
                    res->status = PGRES_COPY_OUT;
                }
                should_break = 1;  // 立即返回，不等待 ReadyForQuery
                break;

            default:
                // 未知消息类型 — 跳过
                break;
        }

        free(data);
        if (should_break) break;
    }

    if (res == NULL) {
        // 未收到任何结果消息 — 创建默认结果
        res = _pg_result_new();
        if (res == NULL) {
            _pg_set_error(conn, "pg: out of memory (PGresult)");
            return NULL;
        }
        res->status = got_error ? PGRES_FATAL_ERROR : PGRES_COMMAND_OK;
    }
    return res;
}

// ============================================================
// 4.1 Query 消息发送
//   'Q' + 4字节长度be + sql + '\0'
// ============================================================
static int _pg_send_query(PGconn *conn, const char *sql) {
    if (conn == NULL || sql == NULL) return -1;
    int sql_len = (int)strlen(sql);
    // _pg_send_message 发送 type + 4字节长度 + payload
    // payload = sql + NUL（C 字符串自带 NUL，发送 sql_len + 1 字节）
    return _pg_send_message(conn, PG_MSG_QUERY, sql, sql_len + 1);
}

// ============================================================
// 4.8 主查询循环 — 执行查询并接收所有结果
//   返回 PGresult*；错误时返回 NULL，conn->last_error 已设置
// ============================================================
static PGresult* _pg_exec_query(PGconn *conn, const char *sql) {
    if (conn == NULL || sql == NULL) return NULL;
    if (conn->sock < 0) {
        _pg_set_error(conn, "pg: not connected");
        return NULL;
    }

    // 清除上次错误
    if (conn->last_error != NULL) {
        free(conn->last_error);
        conn->last_error = NULL;
    }

    if (_pg_send_query(conn, sql) != 0) return NULL;

    return _pg_receive_query_results(conn);
}

// ============================================================
// 5.1 Parse 消息发送
//   'P' + 4字节长度be + statement_name\0 + query\0 + 2字节参数数be + 参数类型OID数组
// ============================================================
static int _pg_send_parse(PGconn *conn, const char *stmt_name, const char *query,
                          const uint32_t *param_types, int n_params) {
    if (conn == NULL || query == NULL) return -1;
    if (n_params < 0) n_params = 0;

    int name_len = (stmt_name != NULL) ? (int)strlen(stmt_name) : 0;
    int query_len = (int)strlen(query);

    // payload 大小：name + NUL + query + NUL + 2字节参数数 + n_params*4
    int payload_size = (name_len + 1) + (query_len + 1) + 2 + (n_params * 4);
    char *buf = (char*)malloc((size_t)payload_size);
    if (buf == NULL) {
        _pg_set_error(conn, "pg: out of memory (parse)");
        return -1;
    }

    int pos = 0;
    if (stmt_name != NULL && name_len > 0) {
        memcpy(buf + pos, stmt_name, (size_t)name_len);
        pos += name_len;
    }
    buf[pos++] = '\0';

    memcpy(buf + pos, query, (size_t)query_len);
    pos += query_len;
    buf[pos++] = '\0';

    _pg_write_be16((uint8_t*)(buf + pos), (uint16_t)n_params);
    pos += 2;

    for (int i = 0; i < n_params; i++) {
        uint32_t oid = (param_types != NULL) ? param_types[i] : 0;
        _pg_write_be32((uint8_t*)(buf + pos), oid);
        pos += 4;
    }

    int rc = _pg_send_message(conn, PG_MSG_PARSE, buf, pos);
    free(buf);
    return rc;
}

// ============================================================
// 5.2 Bind 消息发送
//   'B' + 4字节长度be + portal_name\0 + statement_name\0
//        + 2字节参数格式数be + 参数格式数组
//        + 2字节参数数be + 每参数（4字节长度be + 数据，-1=NULL）
//        + 2字节结果格式数be + 结果格式数组
//
//   本实现：参数格式数=0（全部使用 text 文本格式），结果格式数=1（统一使用 result_format）
// ============================================================
static int _pg_send_bind(PGconn *conn, const char *portal, const char *stmt_name,
                         const char *const *param_values, const int *param_lens,
                         int n_params, int result_format) {
    if (conn == NULL || stmt_name == NULL) return -1;
    if (n_params < 0) n_params = 0;
    if (n_params > 0 && (param_values == NULL || param_lens == NULL)) return -1;

    int portal_len = (portal != NULL) ? (int)strlen(portal) : 0;
    int stmt_len = (int)strlen(stmt_name);

    // 估算 payload 大小
    int payload_size = (portal_len + 1) + (stmt_len + 1)
                     + 2    // 参数格式数（=0）
                     + 2    // 参数数
                     + 2;   // 结果格式数（=1）
    for (int i = 0; i < n_params; i++) {
        payload_size += 4;  // 长度前缀
        if (param_values[i] != NULL && param_lens[i] > 0) {
            payload_size += param_lens[i];
        }
    }
    payload_size += 2;  // 结果格式代码

    char *buf = (char*)malloc((size_t)payload_size);
    if (buf == NULL) {
        _pg_set_error(conn, "pg: out of memory (bind)");
        return -1;
    }

    int pos = 0;
    // Portal name
    if (portal != NULL && portal_len > 0) {
        memcpy(buf + pos, portal, (size_t)portal_len);
        pos += portal_len;
    }
    buf[pos++] = '\0';
    // Statement name
    memcpy(buf + pos, stmt_name, (size_t)stmt_len);
    pos += stmt_len;
    buf[pos++] = '\0';

    // 参数格式数 = 0（全部使用默认 text 格式）
    _pg_write_be16((uint8_t*)(buf + pos), 0);
    pos += 2;

    // 参数数
    _pg_write_be16((uint8_t*)(buf + pos), (uint16_t)n_params);
    pos += 2;

    // 各参数值
    for (int i = 0; i < n_params; i++) {
        if (param_values[i] == NULL) {
            // NULL 参数：长度 = -1（0xFFFFFFFF）
            _pg_write_be32((uint8_t*)(buf + pos), 0xFFFFFFFF);
            pos += 4;
        } else {
            int plen = param_lens[i];
            if (plen < 0) plen = 0;
            _pg_write_be32((uint8_t*)(buf + pos), (uint32_t)plen);
            pos += 4;
            if (plen > 0) {
                memcpy(buf + pos, param_values[i], (size_t)plen);
                pos += plen;
            }
        }
    }

    // 结果格式数 = 1，格式 = result_format（0=text, 1=binary）
    _pg_write_be16((uint8_t*)(buf + pos), 1);
    pos += 2;
    _pg_write_be16((uint8_t*)(buf + pos), (uint16_t)result_format);
    pos += 2;

    int rc = _pg_send_message(conn, PG_MSG_BIND, buf, pos);
    free(buf);
    return rc;
}

// ============================================================
// 5.3 Describe 消息发送
//   'D' + 4字节长度be + 'S'(语句)/'P'(portal) + name\0
// ============================================================
static int _pg_send_describe(PGconn *conn, char describe_type, const char *name) {
    if (conn == NULL) return -1;
    if (describe_type != 'S' && describe_type != 'P') return -1;
    int name_len = (name != NULL) ? (int)strlen(name) : 0;

    // payload: 1 字节类型 + name + NUL
    char *buf = (char*)malloc((size_t)(1 + name_len + 1));
    if (buf == NULL) {
        _pg_set_error(conn, "pg: out of memory (describe)");
        return -1;
    }
    buf[0] = describe_type;
    if (name != NULL && name_len > 0) {
        memcpy(buf + 1, name, (size_t)name_len);
    }
    buf[1 + name_len] = '\0';

    int rc = _pg_send_message(conn, PG_MSG_DESCRIBE, buf, 1 + name_len + 1);
    free(buf);
    return rc;
}

// ============================================================
// 5.4 Execute 消息发送
//   'E' + 4字节长度be + portal_name\0 + 4字节max_rows（0=无限）
// ============================================================
static int _pg_send_execute(PGconn *conn, const char *portal, int max_rows) {
    if (conn == NULL) return -1;
    int portal_len = (portal != NULL) ? (int)strlen(portal) : 0;

    // payload: portal + NUL + 4 字节 max_rows
    char *buf = (char*)malloc((size_t)(portal_len + 1 + 4));
    if (buf == NULL) {
        _pg_set_error(conn, "pg: out of memory (execute)");
        return -1;
    }
    int pos = 0;
    if (portal != NULL && portal_len > 0) {
        memcpy(buf + pos, portal, (size_t)portal_len);
        pos += portal_len;
    }
    buf[pos++] = '\0';
    _pg_write_be32((uint8_t*)(buf + pos), (uint32_t)max_rows);
    pos += 4;

    int rc = _pg_send_message(conn, PG_MSG_EXECUTE, buf, pos);
    free(buf);
    return rc;
}

// ============================================================
// 5.5 Sync 消息发送
//   'S' + 4字节长度be(=4)，无 payload
// ============================================================
static int _pg_send_sync(PGconn *conn) {
    if (conn == NULL) return -1;
    return _pg_send_message(conn, PG_MSG_SYNC, "", 0);
}

// Flush 消息发送
//   'H' + 4字节长度be(=4)，无 payload
static int _pg_send_flush(PGconn *conn) {
    if (conn == NULL) return -1;
    return _pg_send_message(conn, PG_MSG_FLUSH, "", 0);
}

// ============================================================
// 5.7 参数化查询执行
//   Parse + Bind + Describe + Execute + Sync，然后接收结果
//   接收循环处理：'1' ParseComplete → '2' BindComplete → 't' ParameterDescription
//                 → 'T'/'n' RowDescription/NoData → 'D'* DataRow → 'C' CommandComplete → 'Z' ReadyForQuery
// ============================================================
static PGresult* _pg_exec_query_params(PGconn *conn, const char *sql,
                                        const char *const *params, const int *param_lens, int n_params) {
    if (conn == NULL || sql == NULL) return NULL;
    if (conn->sock < 0) {
        _pg_set_error(conn, "pg: not connected");
        return NULL;
    }

    if (conn->last_error != NULL) {
        free(conn->last_error);
        conn->last_error = NULL;
    }

    // 发送扩展查询序列：Parse + Bind + Describe + Execute + Sync
    if (_pg_send_parse(conn, "", sql, NULL, n_params) != 0) return NULL;
    if (_pg_send_bind(conn, "", "", params, param_lens, n_params, 0) != 0) return NULL;
    if (_pg_send_describe(conn, 'P', "") != 0) return NULL;
    if (_pg_send_execute(conn, "", 0) != 0) return NULL;
    if (_pg_send_sync(conn) != 0) return NULL;

    return _pg_receive_query_results(conn);
}

// ============================================================
// 5.9 t_array* → const char** / int* 参数转换
//   遍历 t_array->entries，按 val.type 分发：
//     TYPE_NULL    → NULL, lens=-1
//     TYPE_INT     → snprintf 转十进制字符串
//     TYPE_FLOAT   → snprintf 转 %g 字符串
//     TYPE_BOOL    → "t"/"f"（PostgreSQL 布尔文本表示）
//     TYPE_STRING  → 直接借用 STR_PTR 指针（不分配）
//   转换后的字符串（int/float/bool）由 allocated[] 持有，调用方负责释放
// ============================================================
typedef struct {
    const char **values;     // 参数值数组（NULL 表示 SQL NULL）
    int         *lens;       // 参数长度数组（-1 表示 NULL）
    int          count;      // 参数数量
    char       **allocated;  // 需要释放的字符串数组
    int          n_allocated;
} _pg_param_array;

static int _pg_array_to_params(t_array *params, _pg_param_array *out) {
    out->values = NULL;
    out->lens = NULL;
    out->count = 0;
    out->allocated = NULL;
    out->n_allocated = 0;

    if (params == NULL || params->length <= 0) return 0;

    int n = params->length;
    out->values = (const char**)malloc(sizeof(char*) * (size_t)n);
    out->lens = (int*)malloc(sizeof(int) * (size_t)n);
    out->allocated = (char**)malloc(sizeof(char*) * (size_t)n);
    if (out->values == NULL || out->lens == NULL || out->allocated == NULL) {
        if (out->values != NULL) free(out->values);
        if (out->lens != NULL) free(out->lens);
        if (out->allocated != NULL) free(out->allocated);
        out->values = NULL; out->lens = NULL; out->allocated = NULL;
        return -1;
    }

    for (int i = 0; i < n; i++) {
        out->values[i] = NULL;
        out->lens[i] = -1;
        out->allocated[i] = NULL;
    }
    out->count = n;

    for (int i = 0; i < n; i++) {
        t_var val = params->entries[i].val;
        switch (val.type) {
            case TYPE_NULL:
                out->values[i] = NULL;
                out->lens[i] = -1;
                break;
            case TYPE_INT: {
                char buf[32];
                int blen = snprintf(buf, sizeof(buf), "%lld", (long long)val.value._int);
                char *dup = (char*)malloc((size_t)blen + 1);
                if (dup == NULL) return -1;
                memcpy(dup, buf, (size_t)blen + 1);
                out->allocated[out->n_allocated++] = dup;
                out->values[i] = dup;
                out->lens[i] = blen;
                break;
            }
            case TYPE_FLOAT: {
                char buf[64];
                int blen = snprintf(buf, sizeof(buf), "%g", val.value._float);
                char *dup = (char*)malloc((size_t)blen + 1);
                if (dup == NULL) return -1;
                memcpy(dup, buf, (size_t)blen + 1);
                out->allocated[out->n_allocated++] = dup;
                out->values[i] = dup;
                out->lens[i] = blen;
                break;
            }
            case TYPE_BOOL: {
                // PostgreSQL 文本协议布尔表示：t/f
                char *dup = (char*)malloc(2);
                if (dup == NULL) return -1;
                dup[0] = val.value._bool ? 't' : 'f';
                dup[1] = '\0';
                out->allocated[out->n_allocated++] = dup;
                out->values[i] = dup;
                out->lens[i] = 1;
                break;
            }
            case TYPE_STRING: {
                // 借用 t_string 内部指针（无需释放）
                out->values[i] = STR_PTR(val.value._string);
                out->lens[i] = val.value._string.length;
                break;
            }
            default:
                // 未知类型当作 NULL
                out->values[i] = NULL;
                out->lens[i] = -1;
                break;
        }
    }
    return 0;
}

static void _pg_param_array_free(_pg_param_array *pa) {
    if (pa == NULL) return;
    for (int i = 0; i < pa->n_allocated; i++) {
        if (pa->allocated[i] != NULL) free(pa->allocated[i]);
    }
    if (pa->values != NULL) free(pa->values);
    if (pa->lens != NULL) free(pa->lens);
    if (pa->allocated != NULL) free(pa->allocated);
    pa->values = NULL;
    pa->lens = NULL;
    pa->allocated = NULL;
    pa->count = 0;
    pa->n_allocated = 0;
}

// ============================================================
// 4.9 / 5.8 PHP 层 C 包装函数
// ============================================================

// pg_query — 执行简单查询
//   返回 PGresult 指针（t_int）；错误 tp_throw 并返回 0
t_int tphp_fn_pg_query(t_int conn_handle, t_string sql) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_query: invalid connection handle");
        return 0;
    }
    const char *sql_str = STR_PTR(sql);
    if (sql_str == NULL || sql.length == 0) {
        tp_throw("pg_query: empty SQL");
        return 0;
    }

    PGresult *res = _pg_exec_query(conn, sql_str);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_query: query failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                                        : (conn->last_error ? conn->last_error : "pg_query: query failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_query_params — 执行参数化查询
//   params: t_array*，元素可为 int/string/bool/float/null
t_int tphp_fn_pg_query_params(t_int conn_handle, t_string sql, t_array *params) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_query_params: invalid connection handle");
        return 0;
    }
    const char *sql_str = STR_PTR(sql);
    if (sql_str == NULL || sql.length == 0) {
        tp_throw("pg_query_params: empty SQL");
        return 0;
    }

    _pg_param_array pa;
    if (_pg_array_to_params(params, &pa) != 0) {
        _pg_param_array_free(&pa);
        tp_throw("pg_query_params: out of memory (params)");
        return 0;
    }

    PGresult *res = _pg_exec_query_params(conn, sql_str, pa.values, pa.lens, pa.count);
    _pg_param_array_free(&pa);

    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_query_params: query failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                                        : (conn->last_error ? conn->last_error : "pg_query_params: query failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_prepare — 预处理语句
//   只发送 Parse + Sync，接收 ParseComplete + ReadyForQuery
//   返回 PGresult*（status=PGRES_COMMAND_OK）
t_int tphp_fn_pg_prepare(t_int conn_handle, t_string stmt_name, t_string sql) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_prepare: invalid connection handle");
        return 0;
    }
    const char *name_str = STR_PTR(stmt_name);
    const char *sql_str = STR_PTR(sql);
    if (sql_str == NULL || sql.length == 0) {
        tp_throw("pg_prepare: empty SQL");
        return 0;
    }

    if (conn->last_error != NULL) {
        free(conn->last_error);
        conn->last_error = NULL;
    }

    // 发送 Parse + Sync
    if (_pg_send_parse(conn, name_str ? name_str : "", sql_str, NULL, 0) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_prepare: send parse failed");
        return 0;
    }
    if (_pg_send_sync(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_prepare: send sync failed");
        return 0;
    }

    // 接收 ParseComplete + ReadyForQuery（共用通用接收循环）
    PGresult *res = _pg_receive_query_results(conn);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_prepare: failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                                        : (conn->last_error ? conn->last_error : "pg_prepare: failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    // pg_prepare 成功 — 默认 status=PGRES_COMMAND_OK
    if (res->status == PGRES_TUPLES_OK) {
        res->status = PGRES_COMMAND_OK;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_execute — 执行预处理语句
//   发送 Bind + Execute + Sync，接收结果
t_int tphp_fn_pg_execute(t_int conn_handle, t_string stmt_name, t_array *params) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_execute: invalid connection handle");
        return 0;
    }
    const char *name_str = STR_PTR(stmt_name);

    _pg_param_array pa;
    if (_pg_array_to_params(params, &pa) != 0) {
        _pg_param_array_free(&pa);
        tp_throw("pg_execute: out of memory (params)");
        return 0;
    }

    if (conn->last_error != NULL) {
        free(conn->last_error);
        conn->last_error = NULL;
    }

    // 发送 Bind + Describe + Execute + Sync
    //   Describe('P' portal) 是必需的 — 否则服务端不会发送 RowDescription，
    //   导致 pg_fetch_assoc 等无法按字段名取值
    if (_pg_send_bind(conn, "", name_str ? name_str : "", pa.values, pa.lens, pa.count, 0) != 0) {
        _pg_param_array_free(&pa);
        tp_throw(conn->last_error ? conn->last_error : "pg_execute: send bind failed");
        return 0;
    }
    if (_pg_send_describe(conn, 'P', "") != 0) {
        _pg_param_array_free(&pa);
        tp_throw(conn->last_error ? conn->last_error : "pg_execute: send describe failed");
        return 0;
    }
    if (_pg_send_execute(conn, "", 0) != 0) {
        _pg_param_array_free(&pa);
        tp_throw(conn->last_error ? conn->last_error : "pg_execute: send execute failed");
        return 0;
    }
    if (_pg_send_sync(conn) != 0) {
        _pg_param_array_free(&pa);
        tp_throw(conn->last_error ? conn->last_error : "pg_execute: send sync failed");
        return 0;
    }

    _pg_param_array_free(&pa);

    // 接收结果
    PGresult *res = _pg_receive_query_results(conn);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_execute: failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                                        : (conn->last_error ? conn->last_error : "pg_execute: failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_free_result 实现移至 pgsql_result.h（Task 6）
