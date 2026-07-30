#pragma once
// ============================================================
// pgsql_result.h — PostgreSQL 结果集访问函数（Task 6）
//
// 实现：
//   6.1 行/列数（pg_num_rows / pg_num_fields / pg_affected_rows / pg_last_oid）
//   6.2 字段信息（pg_field_name / pg_field_num / pg_field_type / pg_field_type_oid /
//                  pg_field_size / pg_field_prtlen / pg_field_is_null / pg_field_table）
//   6.3 fetch 函数（pg_fetch_row / pg_fetch_assoc / pg_fetch_array /
//                    pg_fetch_all / pg_fetch_all_columns / pg_fetch_result）
//   6.4 结果状态（pg_result_status / pg_result_status_str / pg_result_seek /
//                  pg_result_error / pg_result_error_field）
//   6.5 错误/通知（pg_last_error / pg_last_notice）
//   6.6 释放（pg_free_result — 从 pgsql_query.h 移入）
//
// 依赖：pgsql.h（结构体 + 常量）+ pgsql_protocol.h（消息收发）+ pgsql_query.h（PGresult 操作）
//
// t_string 返回值：用 str_pool 分配（_pg_mk_str / _pg_mk_str_n 辅助函数）
// t_array  返回值：用 tphp_fn_arr_create 创建，tphp_rt_register 注册
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
// 内部辅助函数
// ============================================================

#ifndef _PG_MK_STR_DEFINED
#define _PG_MK_STR_DEFINED

// 从 C 字符串（NUL-terminated）构造 t_string，深拷贝到 SSO/str_pool
static inline t_string _pg_mk_str(const char *s) {
    if (s == NULL) return (t_string){0};
    int len = (int)strlen(s);
    if (len == 0) return (t_string){0};
    if (len <= STR_SSO_MAX) {
        t_string r;
        memset(&r, 0, sizeof(r));
        r.is_local = true;
        r.length = len;
        memcpy(r.local, s, (size_t)len);
        r.local[len] = '\0';
        return r;
    }
    char *d = str_pool_alloc(len);
    if (d == NULL) return (t_string){0};
    memcpy(d, s, (size_t)len);
    d[len] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = d;
    r.length = len;
    return r;
}

// 从指针+长度构造 t_string，深拷贝（支持嵌入 NUL 的二进制数据）
static inline t_string _pg_mk_str_n(const char *s, int len) {
    if (s == NULL || len <= 0) return (t_string){0};
    if (len <= STR_SSO_MAX) {
        t_string r;
        memset(&r, 0, sizeof(r));
        r.is_local = true;
        r.length = len;
        memcpy(r.local, s, (size_t)len);
        r.local[len] = '\0';
        return r;
    }
    char *d = str_pool_alloc(len);
    if (d == NULL) return (t_string){0};
    memcpy(d, s, (size_t)len);
    d[len] = '\0';
    t_string r;
    memset(&r, 0, sizeof(r));
    r.data = d;
    r.length = len;
    return r;
}
#endif // _PG_MK_STR_DEFINED

// OID → PostgreSQL 类型名
static const char* _pg_oid_to_type_name(uint32_t oid) {
    switch (oid) {
        case OID_BOOL:      return "bool";
        case OID_BYTEA:     return "bytea";
        case OID_INT8:      return "int8";
        case OID_INT2:      return "int2";
        case OID_INT4:      return "int4";
        case OID_TEXT:      return "text";
        case OID_OID:       return "oid";
        case OID_FLOAT4:    return "float4";
        case OID_FLOAT8:    return "float8";
        case OID_VARCHAR:   return "varchar";
        case OID_DATE:      return "date";
        case OID_TIMESTAMP: return "timestamp";
        case OID_NUMERIC:   return "numeric";
        default:            return "unknown";
    }
}

// 按字段名查找字段序号，未找到返回 -1
static int _pg_find_field_by_name(PGresult *res, const char *name) {
    if (res == NULL || name == NULL || res->fields == NULL) return -1;
    for (int i = 0; i < res->num_fields; i++) {
        if (strcmp(res->fields[i].name, name) == 0) return i;
    }
    return -1;
}

// PGSQL_DIAG_* 常量 → ErrorResponse 字段类型字符
static char _pg_diag_code_to_char(t_int code) {
    switch ((int)code) {
        case 0:  return 'S';   // PGSQL_DIAG_SEVERITY
        case 1:  return 'C';   // PGSQL_DIAG_SQLSTATE
        case 2:  return 'M';   // PGSQL_DIAG_MESSAGE / MESSAGE_PRIMARY
        case 3:  return 'D';   // PGSQL_DIAG_DETAIL / MESSAGE_DETAIL
        case 4:  return 'H';   // PGSQL_DIAG_HINT / MESSAGE_HINT
        case 5:  return 'P';   // PGSQL_DIAG_POSITION / STATEMENT_POSITION
        case 6:  return 'p';   // PGSQL_DIAG_INTERNAL_POSITION
        case 7:  return 'q';   // PGSQL_DIAG_INTERNAL_QUERY
        case 8:  return 'W';   // PGSQL_DIAG_CONTEXT
        case 9:  return 'F';   // PGSQL_DIAG_SOURCE_FILE
        case 10: return 'L';   // PGSQL_DIAG_SOURCE_LINE
        case 11: return 's';   // PGSQL_DIAG_SCHEMA_NAME / NON_HIGHLIGHTED
        case 12: return 't';   // PGSQL_DIAG_TABLE_NAME
        case 13: return 'c';   // PGSQL_DIAG_COLUMN_NAME
        case 14: return 'd';   // PGSQL_DIAG_DATATYPE_NAME
        case 15: return 'n';   // PGSQL_DIAG_CONSTRAINT_NAME
        case 18: return 'R';   // PGSQL_DIAG_SOURCE_FUNCTION
        default: return '\0';
    }
}

// 内部辅助：从指定行构造 t_array（按 result_type 决定键类型）
//   result_type: PGSQL_ASSOC=1, PGSQL_NUM=2, PGSQL_BOTH=3
//   返回新建的 t_array*（已注册到 tphp_rt_register），失败返回 NULL
static t_array* _pg_build_row(PGresult *res, int row, int result_type) {
    if (res == NULL || row < 0 || row >= res->num_rows || res->num_fields <= 0) {
        return NULL;
    }
    t_array *arr = tphp_fn_arr_create(res->num_fields);
    if (arr == NULL) return NULL;

    int base = row * res->num_fields;
    for (int i = 0; i < res->num_fields; i++) {
        int val_len = res->row_lens[base + i];
        t_var val;
        if (val_len < 0) {
            val = VAR_NULL();
        } else {
            t_string s = _pg_mk_str_n(res->rows[base + i], val_len);
            val = VAR_STRING(s);
        }

        if (result_type == 2) {
            // PGSQL_NUM：仅数字索引
            arr = tphp_fn_arr_push(arr, val);
        } else if (result_type == 1) {
            // PGSQL_ASSOC：仅关联键
            t_string key = _pg_mk_str(res->fields[i].name);
            arr = tphp_fn_arr_set_str(arr, key, val);
        } else {
            // PGSQL_BOTH (3)：关联 + 数字索引
            t_string key = _pg_mk_str(res->fields[i].name);
            arr = tphp_fn_arr_set_str(arr, key, val);
            arr = tphp_fn_arr_set_int(arr, (t_int)i, val);
        }
    }
    tphp_rt_register((void*)arr, 1);
    return arr;
}

// ============================================================
// 6.1 行/列数
// ============================================================

t_int tphp_fn_pg_num_rows(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_num_rows: invalid result handle");
        return 0;
    }
    return (t_int)res->num_rows;
}

t_int tphp_fn_pg_num_fields(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_num_fields: invalid result handle");
        return 0;
    }
    return (t_int)res->num_fields;
}

t_int tphp_fn_pg_affected_rows(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_affected_rows: invalid result handle");
        return 0;
    }
    return (t_int)res->affected;
}

t_int tphp_fn_pg_last_oid(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_last_oid: invalid result handle");
        return 0;
    }
    return (t_int)res->last_oid;
}

// ============================================================
// 6.2 字段信息
// ============================================================

t_string tphp_fn_pg_field_name(t_int res_handle, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_name: invalid result handle");
        return (t_string){0};
    }
    int fn = (int)field_num;
    if (fn < 0 || fn >= res->num_fields || res->fields == NULL) {
        tp_throw("pg_field_name: field number out of range");
        return (t_string){0};
    }
    return _pg_mk_str(res->fields[fn].name);
}

t_int tphp_fn_pg_field_num(t_int res_handle, t_string field_name) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_num: invalid result handle");
        return -1;
    }
    const char *name = STR_PTR(field_name);
    if (name == NULL || field_name.length == 0) {
        tp_throw("pg_field_num: empty field name");
        return -1;
    }
    int idx = _pg_find_field_by_name(res, name);
    if (idx < 0) return -1;
    return (t_int)idx;
}

t_string tphp_fn_pg_field_type(t_int res_handle, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_type: invalid result handle");
        return (t_string){0};
    }
    int fn = (int)field_num;
    if (fn < 0 || fn >= res->num_fields || res->fields == NULL) {
        tp_throw("pg_field_type: field number out of range");
        return (t_string){0};
    }
    return _pg_mk_str(_pg_oid_to_type_name(res->fields[fn].type_oid));
}

t_int tphp_fn_pg_field_type_oid(t_int res_handle, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_type_oid: invalid result handle");
        return 0;
    }
    int fn = (int)field_num;
    if (fn < 0 || fn >= res->num_fields || res->fields == NULL) {
        tp_throw("pg_field_type_oid: field number out of range");
        return 0;
    }
    return (t_int)res->fields[fn].type_oid;
}

t_int tphp_fn_pg_field_size(t_int res_handle, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_size: invalid result handle");
        return 0;
    }
    int fn = (int)field_num;
    if (fn < 0 || fn >= res->num_fields || res->fields == NULL) {
        tp_throw("pg_field_size: field number out of range");
        return 0;
    }
    return (t_int)res->fields[fn].type_size;
}

t_int tphp_fn_pg_field_prtlen(t_int res_handle, t_int row_num, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_prtlen: invalid result handle");
        return 0;
    }
    int rn = (int)row_num;
    int fn = (int)field_num;
    if (rn < 0 || rn >= res->num_rows) {
        tp_throw("pg_field_prtlen: row number out of range");
        return 0;
    }
    if (fn < 0 || fn >= res->num_fields) {
        tp_throw("pg_field_prtlen: field number out of range");
        return 0;
    }
    int base = rn * res->num_fields;
    int len = res->row_lens[base + fn];
    // NULL 值返回 0（与 libpq PQgetlength 一致）
    return (len < 0) ? 0 : (t_int)len;
}

t_bool tphp_fn_pg_field_is_null(t_int res_handle, t_int row_num, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_is_null: invalid result handle");
        return true;
    }
    int rn = (int)row_num;
    int fn = (int)field_num;
    if (rn < 0 || rn >= res->num_rows) {
        tp_throw("pg_field_is_null: row number out of range");
        return true;
    }
    if (fn < 0 || fn >= res->num_fields) {
        tp_throw("pg_field_is_null: field number out of range");
        return true;
    }
    int base = rn * res->num_fields;
    return (res->row_lens[base + fn] < 0) ? true : false;
}

t_int tphp_fn_pg_field_table(t_int res_handle, t_int field_num) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_field_table: invalid result handle");
        return 0;
    }
    int fn = (int)field_num;
    if (fn < 0 || fn >= res->num_fields || res->fields == NULL) {
        tp_throw("pg_field_table: field number out of range");
        return 0;
    }
    return (t_int)res->fields[fn].table_oid;
}

// ============================================================
// 6.3 fetch 函数
// ============================================================

// pg_fetch_row — 取下一行为数字索引数组（PGSQL_NUM），无更多行返回 NULL
t_array* tphp_fn_pg_fetch_row(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_fetch_row: invalid result handle");
        return NULL;
    }
    if (res->cur_row >= res->num_rows) return NULL;
    t_array *arr = _pg_build_row(res, res->cur_row, 2);  // PGSQL_NUM
    if (arr != NULL) res->cur_row++;
    return arr;
}

// pg_fetch_assoc — 取下一行为关联数组（PGSQL_ASSOC），无更多行返回 NULL
t_array* tphp_fn_pg_fetch_assoc(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_fetch_assoc: invalid result handle");
        return NULL;
    }
    if (res->cur_row >= res->num_rows) return NULL;
    t_array *arr = _pg_build_row(res, res->cur_row, 1);  // PGSQL_ASSOC
    if (arr != NULL) res->cur_row++;
    return arr;
}

// pg_fetch_array — 取下一行，result_type 决定键类型（默认 PGSQL_BOTH=3）
t_array* tphp_fn_pg_fetch_array(t_int res_handle, t_int result_type) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_fetch_array: invalid result handle");
        return NULL;
    }
    if (res->cur_row >= res->num_rows) return NULL;
    int rt = (int)result_type;
    // 0 或无效值 → 默认 PGSQL_BOTH (3)
    if (rt != 1 && rt != 2) rt = 3;
    t_array *arr = _pg_build_row(res, res->cur_row, rt);
    if (arr != NULL) res->cur_row++;
    return arr;
}

// pg_fetch_all — 取所有剩余行，无行返回 NULL
t_array* tphp_fn_pg_fetch_all(t_int res_handle, t_int result_type) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_fetch_all: invalid result handle");
        return NULL;
    }
    if (res->cur_row >= res->num_rows) return NULL;
    int rt = (int)result_type;
    if (rt != 1 && rt != 2) rt = 3;  // 默认 BOTH

    int remaining = res->num_rows - res->cur_row;
    t_array *result = tphp_fn_arr_create(remaining);
    if (result == NULL) {
        tp_throw("pg_fetch_all: out of memory");
        return NULL;
    }
    for (int i = res->cur_row; i < res->num_rows; i++) {
        t_array *row = _pg_build_row(res, i, rt);
        if (row == NULL) {
            tp_throw("pg_fetch_all: failed to build row");
            break;
        }
        result = tphp_fn_arr_push(result, VAR_ARRAY(row));
    }
    res->cur_row = res->num_rows;
    tphp_rt_register((void*)result, 1);
    return result;
}

// pg_fetch_all_columns — 取指定列的所有值，无行返回 NULL
t_array* tphp_fn_pg_fetch_all_columns(t_int res_handle, t_int col) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_fetch_all_columns: invalid result handle");
        return NULL;
    }
    int c = (int)col;
    if (c < 0 || c >= res->num_fields) {
        tp_throw("pg_fetch_all_columns: column out of range");
        return NULL;
    }
    if (res->num_rows <= 0) return NULL;

    t_array *result = tphp_fn_arr_create(res->num_rows);
    if (result == NULL) {
        tp_throw("pg_fetch_all_columns: out of memory");
        return NULL;
    }
    for (int i = 0; i < res->num_rows; i++) {
        int base = i * res->num_fields;
        int val_len = res->row_lens[base + c];
        t_var val;
        if (val_len < 0) {
            val = VAR_NULL();
        } else {
            t_string s = _pg_mk_str_n(res->rows[base + c], val_len);
            val = VAR_STRING(s);
        }
        result = tphp_fn_arr_push(result, val);
    }
    tphp_rt_register((void*)result, 1);
    return result;
}

// pg_fetch_result — 按行号+字段名取单个值
//   field 可为字段名或数字字符串（如 "0"）
t_string tphp_fn_pg_fetch_result(t_int res_handle, t_int row, t_string field) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_fetch_result: invalid result handle");
        return (t_string){0};
    }
    int rn = (int)row;
    if (rn < 0 || rn >= res->num_rows) {
        tp_throw("pg_fetch_result: row out of range");
        return (t_string){0};
    }
    const char *fname = STR_PTR(field);
    if (fname == NULL || field.length == 0) {
        tp_throw("pg_fetch_result: empty field name");
        return (t_string){0};
    }

    // 先按字段名查找
    int field_num = _pg_find_field_by_name(res, fname);
    if (field_num < 0) {
        // 尝试解析为整数字段序号
        char *endp = NULL;
        long n = strtol(fname, &endp, 10);
        if (endp != NULL && *endp == '\0' && n >= 0 && n < res->num_fields) {
            field_num = (int)n;
        } else {
            tp_throw("pg_fetch_result: field not found");
            return (t_string){0};
        }
    }

    int base = rn * res->num_fields;
    int val_len = res->row_lens[base + field_num];
    if (val_len < 0) {
        // SQL NULL → 空字符串
        return (t_string){0};
    }
    return _pg_mk_str_n(res->rows[base + field_num], val_len);
}

// ============================================================
// 6.4 结果状态
// ============================================================

// pg_result_status — 返回状态码（ExecStatusType）
//   mode: PGSQL_STATUS_LONG=1 / PGSQL_STATUS_STRING=2
//   注意：mode=STRING 时应使用 pg_result_status_str 获取字符串
t_int tphp_fn_pg_result_status(t_int res_handle, t_int mode) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_result_status: invalid result handle");
        return 0;
    }
    (void)mode;  // LONG/STRING 均返回状态码；字符串模式用 pg_result_status_str
    return (t_int)res->status;
}

// pg_result_status_str — 返回状态字符串（命令 tag 或错误消息）
t_string tphp_fn_pg_result_status_str(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_result_status_str: invalid result handle");
        return (t_string){0};
    }
    if (res->status == PGRES_FATAL_ERROR || res->status == PGRES_NONFATAL_ERROR) {
        if (res->err_msg != NULL) return _pg_mk_str(res->err_msg);
        return STR_LIT("ERROR");
    }
    if (res->cmd_tag[0] != '\0') return _pg_mk_str(res->cmd_tag);
    // 回退到状态名
    switch (res->status) {
        case PGRES_EMPTY_QUERY:  return STR_LIT("EMPTY_QUERY");
        case PGRES_COMMAND_OK:   return STR_LIT("COMMAND_OK");
        case PGRES_TUPLES_OK:    return STR_LIT("TUPLES_OK");
        case PGRES_BAD_RESPONSE: return STR_LIT("BAD_RESPONSE");
        default:                 return STR_LIT("UNKNOWN");
    }
}

// pg_result_seek — 移动 cur_row 指针
t_bool tphp_fn_pg_result_seek(t_int res_handle, t_int offset) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_result_seek: invalid result handle");
        return false;
    }
    int off = (int)offset;
    if (off < 0 || off >= res->num_rows) {
        return false;
    }
    res->cur_row = off;
    return true;
}

// pg_result_error — 返回结果集的错误消息
t_string tphp_fn_pg_result_error(t_int res_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_result_error: invalid result handle");
        return (t_string){0};
    }
    if (res->err_msg != NULL) return _pg_mk_str(res->err_msg);
    return (t_string){0};
}

// pg_result_error_field — 按 PGSQL_DIAG_* 字段码取错误字段值
t_string tphp_fn_pg_result_error_field(t_int res_handle, t_int field_code) {
    PGresult *res = _PG_RESULT_FROM_INT(res_handle);
    if (res == NULL) {
        tp_throw("pg_result_error_field: invalid result handle");
        return (t_string){0};
    }
    if (res->err_data == NULL || res->err_data_len <= 0) {
        return (t_string){0};
    }
    char field_char = _pg_diag_code_to_char(field_code);
    if (field_char == '\0') return (t_string){0};

    // 解析 err_data：sequence of (field_char + NUL-terminated string), ending with NUL
    int pos = 0;
    while (pos < res->err_data_len) {
        char fc = res->err_data[pos];
        if (fc == '\0') break;  // 结束标志
        pos++;
        if (pos >= res->err_data_len) break;
        const char *val = res->err_data + pos;
        int vlen = 0;
        while (pos + vlen < res->err_data_len && val[vlen] != '\0') vlen++;
        // PGSQL_DIAG_SEVERITY (0) 同时匹配 'S' 和 'V'（PG9+ 非本地化严重程度）
        if (fc == field_char || (field_code == 0 && fc == 'V')) {
            return _pg_mk_str_n(val, vlen);
        }
        pos += vlen + 1;  // 跳过值 + NUL
    }
    return (t_string){0};  // 字段未找到
}

// ============================================================
// 6.5 错误/通知
// ============================================================

t_string tphp_fn_pg_last_error(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_last_error: invalid connection handle");
        return (t_string){0};
    }
    if (conn->last_error != NULL) return _pg_mk_str(conn->last_error);
    return (t_string){0};
}

t_string tphp_fn_pg_last_notice(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_last_notice: invalid connection handle");
        return (t_string){0};
    }
    if (conn->last_notice != NULL) return _pg_mk_str(conn->last_notice);
    return (t_string){0};
}

// ============================================================
// 6.6 释放（从 pgsql_query.h 移入）
// ============================================================

// pg_free_result — 释放 PGresult
void tphp_fn_pg_free_result(t_int result_handle) {
    PGresult *res = _PG_RESULT_FROM_INT(result_handle);
    if (res == NULL) return;
    _pg_result_free(res);
}
