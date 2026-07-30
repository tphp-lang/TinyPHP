#pragma once
// ============================================================
// pgsql_dml.h — PostgreSQL 高层 DML 函数（Task 9）
//
// 实现：
//   9.1 pg_meta_data  — 查询 information_schema.columns，返回字段元数据数组
//   9.2 pg_convert    — PHP 值数组转 SQL 字面量数组
//   9.3 pg_insert     — 生成并执行 INSERT（_result / _sql 两个变体）
//   9.4 pg_update     — 生成并执行 UPDATE（_result / _sql 两个变体）
//   9.5 pg_delete     — 生成并执行 DELETE（_result / _sql 两个变体）
//   9.6 pg_select     — 生成 SELECT 并返回结果数组
//
// 内部辅助：
//   _pg_strbuf_*          — 动态字符串构建器
//   _pg_var_to_sql        — t_var → SQL 字面量字符串（NULL/bool/int/float/string）
//   _pg_build_set_clause  — 生成 "col1" = val1, "col2" = val2
//   _pg_build_where_clause — 生成 "col1" = val1 AND "col2" = val2
//   _pg_build_insert_parts — 生成 "col1", "col2" 和 val1, val2
//   _pg_build_insert      — 生成 INSERT SQL
//   _pg_build_update      — 生成 UPDATE SQL
//   _pg_build_delete      — 生成 DELETE SQL
//   _pg_build_select      — 生成 SELECT SQL
//
// 依赖：
//   - pgsql.h（结构体 + 常量）
//   - pgsql_protocol.h（消息收发 + 错误处理）
//   - pgsql_query.h（_pg_exec_query / _pg_result_free / _pg_result_new）
//   - pgsql_result.h（_pg_mk_str / _pg_mk_str_n / _pg_oid_to_type_name）
//   - pgsql_misc.h（tphp_fn_pg_escape_* 等）
//   - pgsql_copy.h（_pg_quote_literal / _pg_quote_ident）
//
//   必须 在 pgsql_query.h 之后被 #include（依赖 _pg_exec_query）
//
// flags 常量（定义于 pgsql_constants.php）：
//   PGSQL_DML_EXEC   = 1   执行 SQL
//   PGSQL_DML_STRING = 3   返回 SQL 字符串
//   PGSQL_DML_ESCAPE = 4   使用 pg_escape 转义
//   PGSQL_CONV_IGNORE_DEFAULT = 2
//   PGSQL_CONV_FORCE_NULL    = 4
//   PGSQL_CONV_IGNORE_NOT_NULL = 8
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
// 动态字符串构建器（用于 SQL 生成）
// ============================================================

#ifndef _PG_STRBUF_DEFINED
#define _PG_STRBUF_DEFINED

typedef struct {
    char *buf;
    int   len;
    int   cap;
} _pg_strbuf;

static int _pg_strbuf_init(_pg_strbuf *sb, int initial_cap) {
    if (initial_cap < 64) initial_cap = 64;
    sb->buf = (char*)malloc((size_t)initial_cap);
    if (sb->buf == NULL) return -1;
    sb->buf[0] = '\0';
    sb->len = 0;
    sb->cap = initial_cap;
    return 0;
}

static int _pg_strbuf_append(_pg_strbuf *sb, const char *s, int len) {
    if (sb == NULL || s == NULL) return -1;
    if (len <= 0) len = (int)strlen(s);
    if (sb->len + len + 1 > sb->cap) {
        int new_cap = sb->cap;
        while (new_cap < sb->len + len + 1) new_cap *= 2;
        char *new_buf = (char*)realloc(sb->buf, (size_t)new_cap);
        if (new_buf == NULL) return -1;
        sb->buf = new_buf;
        sb->cap = new_cap;
    }
    memcpy(sb->buf + sb->len, s, (size_t)len);
    sb->len += len;
    sb->buf[sb->len] = '\0';
    return 0;
}

static int _pg_strbuf_append_str(_pg_strbuf *sb, const char *s) {
    if (s == NULL) return -1;
    return _pg_strbuf_append(sb, s, (int)strlen(s));
}

static int _pg_strbuf_append_char(_pg_strbuf *sb, char c) {
    return _pg_strbuf_append(sb, &c, 1);
}

// 释放 sb 内部 buffer 并返回所有权（调用者负责 free 结果）
static char* _pg_strbuf_detach(_pg_strbuf *sb) {
    if (sb == NULL || sb->buf == NULL) return NULL;
    char *result = sb->buf;
    sb->buf = NULL;
    sb->len = 0;
    sb->cap = 0;
    return result;
}

static void _pg_strbuf_free(_pg_strbuf *sb) {
    if (sb == NULL) return;
    if (sb->buf != NULL) free(sb->buf);
    sb->buf = NULL;
    sb->len = 0;
    sb->cap = 0;
}

#endif // _PG_STRBUF_DEFINED

// ============================================================
// t_var → SQL 字面量转换
//   NULL    → "NULL"
//   bool    → "TRUE" / "FALSE"
//   int     → 十进制字符串
//   float   → %g 格式
//   string  → 'escaped'（始终转义 ' → ''）
//   其他    → "NULL"（兜底）
//   成功返回 malloc'd 字符串（调用者 free），失败返回 NULL
// ============================================================

static char* _pg_var_to_sql(PGconn *conn, t_var val, int flags) {
    (void)conn;
    (void)flags;

    switch (val.type) {
        case TYPE_NULL: {
            char *out = (char*)malloc(5);
            if (out == NULL) return NULL;
            strcpy(out, "NULL");
            return out;
        }
        case TYPE_BOOL: {
            char *out = (char*)malloc(6);
            if (out == NULL) return NULL;
            strcpy(out, val.value._bool ? "TRUE" : "FALSE");
            return out;
        }
        case TYPE_INT: {
            char buf[32];
            int n = snprintf(buf, sizeof(buf), "%lld", (long long)val.value._int);
            char *out = (char*)malloc((size_t)n + 1);
            if (out == NULL) return NULL;
            memcpy(out, buf, (size_t)n + 1);
            return out;
        }
        case TYPE_FLOAT: {
            char buf[64];
            int n = snprintf(buf, sizeof(buf), "%g", val.value._float);
            char *out = (char*)malloc((size_t)n + 1);
            if (out == NULL) return NULL;
            memcpy(out, buf, (size_t)n + 1);
            return out;
        }
        case TYPE_STRING: {
            const char *s = STR_PTR(val.value._string);
            int slen = val.value._string.length;
            if (s == NULL || slen <= 0) {
                char *out = (char*)malloc(3);
                if (out == NULL) return NULL;
                out[0] = '\''; out[1] = '\''; out[2] = '\0';
                return out;
            }
            return _pg_quote_literal(s, slen);
        }
        default: {
            char *out = (char*)malloc(5);
            if (out == NULL) return NULL;
            strcpy(out, "NULL");
            return out;
        }
    }
}

// ============================================================
// 9.1 pg_meta_data — 查询 information_schema.columns
//   返回 t_array*，key=字段名，value=关联数组：
//     num:           字段序号（ordinal_position）
//     type:          数据类型名（data_type）
//     len:           字符最大长度（character_maximum_length，可能为 NULL→-1）
//     not_null:      是否 NOT NULL（is_nullable = 'NO' → true）
//     has_default:   是否有默认值（column_default != NULL → true）
//     default_value: 默认值字符串（无默认值时为空字符串）
// ============================================================

t_array* tphp_fn_pg_meta_data(t_int conn_handle, t_string table_name) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_meta_data: invalid connection handle");
        return NULL;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_meta_data: empty table name");
        return NULL;
    }

    // 转义表名为字面量
    char *table_lit = _pg_quote_literal(table, table_name.length);
    if (table_lit == NULL) {
        tp_throw("pg_meta_data: out of memory (table escape)");
        return NULL;
    }

    // 构建 SQL：查询 information_schema.columns
    //   列顺序: column_name(0), ordinal_position(1), data_type(2),
    //           character_maximum_length(3), column_default(4), is_nullable(5)
    const char *sql_template =
        "SELECT column_name, ordinal_position, data_type, "
        "character_maximum_length, column_default, is_nullable "
        "FROM information_schema.columns "
        "WHERE table_name = %s "
        "ORDER BY ordinal_position";
    int sql_cap = (int)strlen(sql_template) + (int)strlen(table_lit) + 16;
    char *sql = (char*)malloc((size_t)sql_cap);
    if (sql == NULL) {
        free(table_lit);
        tp_throw("pg_meta_data: out of memory (sql)");
        return NULL;
    }
    snprintf(sql, (size_t)sql_cap, sql_template, table_lit);
    free(table_lit);

    // 执行查询
    PGresult *res = _pg_exec_query(conn, sql);
    free(sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_meta_data: query failed");
        return NULL;
    }
    if (res->status == PGRES_FATAL_ERROR || res->num_fields < 6) {
        const char *emsg = res->err_msg ? res->err_msg
                            : (conn->last_error ? conn->last_error : "pg_meta_data: query failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return NULL;
    }

    // 构建结果数组
    t_array *meta = tphp_fn_arr_create(res->num_rows > 0 ? res->num_rows : 4);
    if (meta == NULL) {
        _pg_result_free(res);
        tp_throw("pg_meta_data: out of memory (meta array)");
        return NULL;
    }

    for (int i = 0; i < res->num_rows; i++) {
        int base = i * res->num_fields;

        // column_name (列 0)
        const char *col_name = (res->row_lens[base + 0] >= 0) ? res->rows[base + 0] : NULL;
        if (col_name == NULL) continue;  // 跳过无名字段

        // ordinal_position (列 1) → num
        t_int num = 0;
        if (res->row_lens[base + 1] >= 0 && res->rows[base + 1] != NULL) {
            num = (t_int)atoll(res->rows[base + 1]);
        }

        // data_type (列 2) → type
        t_string type_str = (res->row_lens[base + 2] >= 0 && res->rows[base + 2] != NULL)
                            ? _pg_mk_str(res->rows[base + 2])
                            : (t_string){0};

        // character_maximum_length (列 3) → len（NULL → -1）
        t_int len_val = -1;
        if (res->row_lens[base + 3] >= 0 && res->rows[base + 3] != NULL) {
            len_val = (t_int)atoll(res->rows[base + 3]);
        }

        // column_default (列 4) → default_value + has_default
        t_bool has_default = false;
        t_string default_str = (t_string){0};
        if (res->row_lens[base + 4] >= 0 && res->rows[base + 4] != NULL) {
            has_default = true;
            default_str = _pg_mk_str(res->rows[base + 4]);
        }

        // is_nullable (列 5) → not_null（'NO' → true）
        t_bool not_null = false;
        if (res->row_lens[base + 5] >= 0 && res->rows[base + 5] != NULL) {
            not_null = (strcmp(res->rows[base + 5], "NO") == 0) ? true : false;
        }

        // 构建字段信息关联数组
        t_array *field_info = tphp_fn_arr_create(6);
        if (field_info == NULL) {
            _pg_result_free(res);
            tphp_fn_arr_free(meta);
            tp_throw("pg_meta_data: out of memory (field_info)");
            return NULL;
        }
        field_info = tphp_fn_arr_set_str(field_info, STR_LIT("num"), VAR_INT(num));
        field_info = tphp_fn_arr_set_str(field_info, STR_LIT("type"), VAR_STRING(type_str));
        field_info = tphp_fn_arr_set_str(field_info, STR_LIT("len"), VAR_INT(len_val));
        field_info = tphp_fn_arr_set_str(field_info, STR_LIT("not_null"), VAR_BOOL(not_null));
        field_info = tphp_fn_arr_set_str(field_info, STR_LIT("has_default"), VAR_BOOL(has_default));
        field_info = tphp_fn_arr_set_str(field_info, STR_LIT("default_value"), VAR_STRING(default_str));

        // 添加到 meta 数组（key=字段名）
        t_string key = _pg_mk_str(col_name);
        meta = tphp_fn_arr_set_str(meta, key, VAR_ARRAY(field_info));
    }

    _pg_result_free(res);
    tphp_rt_register((void*)meta, 1);
    return meta;
}

// ============================================================
// 9.2 pg_convert — PHP 值数组转 SQL 字面量数组
//   flags:
//     PGSQL_CONV_IGNORE_DEFAULT (2): 跳过有默认值且值为空字符串的字段
//     PGSQL_CONV_FORCE_NULL    (4): 空字符串转 NULL
//     PGSQL_CONV_IGNORE_NOT_NULL (8): 跳过 NOT NULL 但值为 NULL 的字段
//   返回 t_array*，key=字段名，value=SQL 字面量字符串
// ============================================================

t_array* tphp_fn_pg_convert(t_int conn_handle, t_string table_name, t_array *assoc_array, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_convert: invalid connection handle");
        return NULL;
    }
    if (assoc_array == NULL) {
        tp_throw("pg_convert: null assoc_array");
        return NULL;
    }

    // 获取元数据
    t_array *meta = tphp_fn_pg_meta_data(conn_handle, table_name);
    if (meta == NULL) {
        return NULL;  // tp_throw 已在 pg_meta_data 中调用
    }

    t_array *result = tphp_fn_arr_create(assoc_array->length > 0 ? assoc_array->length : 4);
    if (result == NULL) {
        tp_throw("pg_convert: out of memory (result)");
        return NULL;
    }

    int f = (int)flags;

    for (int i = 0; i < assoc_array->length; i++) {
        if (assoc_array->entries[i].key.type != TYPE_STRING) {
            // 非 string 键 — 跳过
            continue;
        }
        t_string key = assoc_array->entries[i].key.value._string;
        t_var val = assoc_array->entries[i].val;

        // 查找字段元数据
        t_var *meta_var = tphp_fn_arr_get_str(meta, key);
        t_bool not_null = false;
        t_bool has_default = false;
        if (meta_var != NULL && meta_var->type == TYPE_ARRAY) {
            t_array *field_info = meta_var->value._array;
            t_var *nn = tphp_fn_arr_get_str(field_info, STR_LIT("not_null"));
            if (nn != NULL && nn->type == TYPE_BOOL) not_null = nn->value._bool;
            t_var *hd = tphp_fn_arr_get_str(field_info, STR_LIT("has_default"));
            if (hd != NULL && hd->type == TYPE_BOOL) has_default = hd->value._bool;
        }

        // PGSQL_CONV_IGNORE_NOT_NULL: NOT NULL 字段值为 NULL → 跳过
        if ((f & 8) && not_null && val.type == TYPE_NULL) {
            continue;
        }

        // PGSQL_CONV_IGNORE_DEFAULT: 有默认值且字符串为空 → 跳过
        if ((f & 2) && has_default && val.type == TYPE_STRING && val.value._string.length == 0) {
            continue;
        }

        // PGSQL_CONV_FORCE_NULL: 空字符串 → NULL
        if ((f & 4) && val.type == TYPE_STRING && val.value._string.length == 0) {
            val = VAR_NULL();
        }

        // 转换为 SQL 字面量
        char *literal = _pg_var_to_sql(conn, val, f);
        if (literal == NULL) {
            tp_throw("pg_convert: out of memory (literal)");
            return NULL;
        }
        t_string lit_str = _pg_mk_str(literal);
        result = tphp_fn_arr_set_str(result, key, VAR_STRING(lit_str));
        free(literal);
    }

    tphp_rt_register((void*)result, 1);
    return result;
}

// ============================================================
// 内部辅助：SQL 子句生成
// ============================================================

// _pg_build_set_clause — 生成 "col1" = val1, "col2" = val2（用于 UPDATE SET）
//   成功返回 malloc'd 字符串，失败返回 NULL
static char* _pg_build_set_clause(PGconn *conn, t_array *assoc, int flags) {
    if (assoc == NULL || assoc->length <= 0) return NULL;

    _pg_strbuf sb;
    if (_pg_strbuf_init(&sb, 128) != 0) return NULL;

    int first = 1;
    for (int i = 0; i < assoc->length; i++) {
        if (assoc->entries[i].key.type != TYPE_STRING) continue;

        const char *col_name = STR_PTR(assoc->entries[i].key.value._string);
        int col_len = assoc->entries[i].key.value._string.length;
        if (col_name == NULL || col_len <= 0) continue;

        char *col_q = _pg_quote_ident(col_name, col_len);
        if (col_q == NULL) {
            _pg_strbuf_free(&sb);
            return NULL;
        }
        char *val_lit = _pg_var_to_sql(conn, assoc->entries[i].val, flags);
        if (val_lit == NULL) {
            free(col_q);
            _pg_strbuf_free(&sb);
            return NULL;
        }

        if (!first) {
            _pg_strbuf_append_str(&sb, ", ");
        }
        _pg_strbuf_append_str(&sb, col_q);
        _pg_strbuf_append_str(&sb, " = ");
        _pg_strbuf_append_str(&sb, val_lit);

        free(col_q);
        free(val_lit);
        first = 0;
    }

    if (first) {
        // 没有有效的列
        _pg_strbuf_free(&sb);
        return NULL;
    }
    return _pg_strbuf_detach(&sb);
}

// _pg_build_where_clause — 生成 "col1" = val1 AND "col2" = val2（用于 WHERE）
//   成功返回 malloc'd 字符串，失败返回 NULL
static char* _pg_build_where_clause(PGconn *conn, t_array *assoc, int flags) {
    if (assoc == NULL || assoc->length <= 0) return NULL;

    _pg_strbuf sb;
    if (_pg_strbuf_init(&sb, 128) != 0) return NULL;

    int first = 1;
    for (int i = 0; i < assoc->length; i++) {
        if (assoc->entries[i].key.type != TYPE_STRING) continue;

        const char *col_name = STR_PTR(assoc->entries[i].key.value._string);
        int col_len = assoc->entries[i].key.value._string.length;
        if (col_name == NULL || col_len <= 0) continue;

        char *col_q = _pg_quote_ident(col_name, col_len);
        if (col_q == NULL) {
            _pg_strbuf_free(&sb);
            return NULL;
        }
        char *val_lit = _pg_var_to_sql(conn, assoc->entries[i].val, flags);
        if (val_lit == NULL) {
            free(col_q);
            _pg_strbuf_free(&sb);
            return NULL;
        }

        if (!first) {
            _pg_strbuf_append_str(&sb, " AND ");
        }
        _pg_strbuf_append_str(&sb, col_q);
        _pg_strbuf_append_str(&sb, " = ");
        _pg_strbuf_append_str(&sb, val_lit);

        free(col_q);
        free(val_lit);
        first = 0;
    }

    if (first) {
        _pg_strbuf_free(&sb);
        return NULL;
    }
    return _pg_strbuf_detach(&sb);
}

// _pg_build_insert_parts — 生成 "col1", "col2" 和 val1, val2
//   cols 和 vals 输出参数，调用者负责 free
//   成功返回 0，失败返回 -1
static void _pg_build_insert_parts(PGconn *conn, t_array *assoc, int flags, char **cols, char **vals) {
    *cols = NULL;
    *vals = NULL;
    if (assoc == NULL || assoc->length <= 0) return;

    _pg_strbuf cols_sb, vals_sb;
    if (_pg_strbuf_init(&cols_sb, 128) != 0) return;
    if (_pg_strbuf_init(&vals_sb, 128) != 0) {
        _pg_strbuf_free(&cols_sb);
        return;
    }

    int first = 1;
    for (int i = 0; i < assoc->length; i++) {
        if (assoc->entries[i].key.type != TYPE_STRING) continue;

        const char *col_name = STR_PTR(assoc->entries[i].key.value._string);
        int col_len = assoc->entries[i].key.value._string.length;
        if (col_name == NULL || col_len <= 0) continue;

        char *col_q = _pg_quote_ident(col_name, col_len);
        if (col_q == NULL) {
            _pg_strbuf_free(&cols_sb);
            _pg_strbuf_free(&vals_sb);
            return;
        }
        char *val_lit = _pg_var_to_sql(conn, assoc->entries[i].val, flags);
        if (val_lit == NULL) {
            free(col_q);
            _pg_strbuf_free(&cols_sb);
            _pg_strbuf_free(&vals_sb);
            return;
        }

        if (!first) {
            _pg_strbuf_append_str(&cols_sb, ", ");
            _pg_strbuf_append_str(&vals_sb, ", ");
        }
        _pg_strbuf_append_str(&cols_sb, col_q);
        _pg_strbuf_append_str(&vals_sb, val_lit);

        free(col_q);
        free(val_lit);
        first = 0;
    }

    if (first) {
        _pg_strbuf_free(&cols_sb);
        _pg_strbuf_free(&vals_sb);
        return;
    }
    *cols = _pg_strbuf_detach(&cols_sb);
    *vals = _pg_strbuf_detach(&vals_sb);
}

// _pg_build_insert — 生成 INSERT INTO "table" ("col1", "col2") VALUES (val1, val2)
//   成功返回 malloc'd SQL 字符串，失败返回 NULL
static char* _pg_build_insert(PGconn *conn, const char *table, t_array *assoc, int flags) {
    if (table == NULL || assoc == NULL) return NULL;

    char *table_q = _pg_quote_ident(table, (int)strlen(table));
    if (table_q == NULL) return NULL;

    char *cols = NULL;
    char *vals = NULL;
    _pg_build_insert_parts(conn, assoc, flags, &cols, &vals);
    if (cols == NULL || vals == NULL) {
        free(table_q);
        if (cols != NULL) free(cols);
        if (vals != NULL) free(vals);
        return NULL;
    }

    // "INSERT INTO " + table_q + " (" + cols + ") VALUES (" + vals + ")"
    int total = 20 + (int)strlen(table_q) + 2 + (int)strlen(cols) + 11 + (int)strlen(vals) + 1;
    char *sql = (char*)malloc((size_t)total);
    if (sql == NULL) {
        free(table_q); free(cols); free(vals);
        return NULL;
    }
    snprintf(sql, (size_t)total, "INSERT INTO %s (%s) VALUES (%s)", table_q, cols, vals);
    free(table_q); free(cols); free(vals);
    return sql;
}

// _pg_build_update — 生成 UPDATE "table" SET ... WHERE ...
//   cond 为 NULL 或空时省略 WHERE 子句
//   成功返回 malloc'd SQL 字符串，失败返回 NULL
static char* _pg_build_update(PGconn *conn, const char *table, t_array *assoc, t_array *cond, int flags) {
    if (table == NULL || assoc == NULL) return NULL;

    char *table_q = _pg_quote_ident(table, (int)strlen(table));
    if (table_q == NULL) return NULL;

    char *set_clause = _pg_build_set_clause(conn, assoc, flags);
    if (set_clause == NULL) {
        free(table_q);
        return NULL;
    }

    char *where_clause = NULL;
    if (cond != NULL && cond->length > 0) {
        where_clause = _pg_build_where_clause(conn, cond, flags);
    }

    _pg_strbuf sb;
    if (_pg_strbuf_init(&sb, 256) != 0) {
        free(table_q); free(set_clause);
        if (where_clause != NULL) free(where_clause);
        return NULL;
    }
    _pg_strbuf_append_str(&sb, "UPDATE ");
    _pg_strbuf_append_str(&sb, table_q);
    _pg_strbuf_append_str(&sb, " SET ");
    _pg_strbuf_append_str(&sb, set_clause);
    if (where_clause != NULL) {
        _pg_strbuf_append_str(&sb, " WHERE ");
        _pg_strbuf_append_str(&sb, where_clause);
    }

    free(table_q); free(set_clause);
    if (where_clause != NULL) free(where_clause);
    return _pg_strbuf_detach(&sb);
}

// _pg_build_delete — 生成 DELETE FROM "table" WHERE ...
//   cond 为 NULL 或空时省略 WHERE 子句（删除所有行）
//   成功返回 malloc'd SQL 字符串，失败返回 NULL
static char* _pg_build_delete(PGconn *conn, const char *table, t_array *cond, int flags) {
    (void)conn;
    (void)flags;
    if (table == NULL) return NULL;

    char *table_q = _pg_quote_ident(table, (int)strlen(table));
    if (table_q == NULL) return NULL;

    char *where_clause = NULL;
    if (cond != NULL && cond->length > 0) {
        where_clause = _pg_build_where_clause(conn, cond, flags);
    }

    _pg_strbuf sb;
    if (_pg_strbuf_init(&sb, 128) != 0) {
        free(table_q);
        if (where_clause != NULL) free(where_clause);
        return NULL;
    }
    _pg_strbuf_append_str(&sb, "DELETE FROM ");
    _pg_strbuf_append_str(&sb, table_q);
    if (where_clause != NULL) {
        _pg_strbuf_append_str(&sb, " WHERE ");
        _pg_strbuf_append_str(&sb, where_clause);
    }

    free(table_q);
    if (where_clause != NULL) free(where_clause);
    return _pg_strbuf_detach(&sb);
}

// _pg_build_select — 生成 SELECT * FROM "table" WHERE ...
//   assoc 为 NULL 或空时省略 WHERE 子句（选择所有行）
//   成功返回 malloc'd SQL 字符串，失败返回 NULL
static char* _pg_build_select(PGconn *conn, const char *table, t_array *assoc, int flags) {
    if (table == NULL) return NULL;

    char *table_q = _pg_quote_ident(table, (int)strlen(table));
    if (table_q == NULL) return NULL;

    char *where_clause = NULL;
    if (assoc != NULL && assoc->length > 0) {
        where_clause = _pg_build_where_clause(conn, assoc, flags);
    }

    _pg_strbuf sb;
    if (_pg_strbuf_init(&sb, 128) != 0) {
        free(table_q);
        if (where_clause != NULL) free(where_clause);
        return NULL;
    }
    _pg_strbuf_append_str(&sb, "SELECT * FROM ");
    _pg_strbuf_append_str(&sb, table_q);
    if (where_clause != NULL) {
        _pg_strbuf_append_str(&sb, " WHERE ");
        _pg_strbuf_append_str(&sb, where_clause);
    }

    free(table_q);
    if (where_clause != NULL) free(where_clause);
    return _pg_strbuf_detach(&sb);
}

// ============================================================
// 9.3 pg_insert — 生成并执行 INSERT
//   _result 变体：执行 SQL，返回 PGresult 句柄（t_int）
//   _sql 变体：返回 SQL 字符串（t_string）
// ============================================================

// pg_insert_result — 执行 INSERT，返回 PGresult 句柄
t_int tphp_fn_pg_insert_result(t_int conn_handle, t_string table_name, t_array *assoc, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_insert: invalid connection handle");
        return 0;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_insert: empty table name");
        return 0;
    }
    if (assoc == NULL || assoc->length <= 0) {
        tp_throw("pg_insert: empty assoc array");
        return 0;
    }

    char *sql = _pg_build_insert(conn, table, assoc, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_insert: failed to build SQL");
        return 0;
    }

    PGresult *res = _pg_exec_query(conn, sql);
    free(sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_insert: query failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                            : (conn->last_error ? conn->last_error : "pg_insert: failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_insert_sql — 返回 INSERT SQL 字符串（不执行）
t_string tphp_fn_pg_insert_sql(t_int conn_handle, t_string table_name, t_array *assoc, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_insert: invalid connection handle");
        return (t_string){0};
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_insert: empty table name");
        return (t_string){0};
    }
    if (assoc == NULL || assoc->length <= 0) {
        tp_throw("pg_insert: empty assoc array");
        return (t_string){0};
    }

    char *sql = _pg_build_insert(conn, table, assoc, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_insert: failed to build SQL");
        return (t_string){0};
    }
    t_string result = _pg_mk_str(sql);
    free(sql);
    return result;
}

// ============================================================
// 9.4 pg_update — 生成并执行 UPDATE
// ============================================================

// pg_update_result — 执行 UPDATE，返回 PGresult 句柄
t_int tphp_fn_pg_update_result(t_int conn_handle, t_string table_name, t_array *assoc, t_array *condition, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_update: invalid connection handle");
        return 0;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_update: empty table name");
        return 0;
    }
    if (assoc == NULL || assoc->length <= 0) {
        tp_throw("pg_update: empty assoc array");
        return 0;
    }

    char *sql = _pg_build_update(conn, table, assoc, condition, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_update: failed to build SQL");
        return 0;
    }

    PGresult *res = _pg_exec_query(conn, sql);
    free(sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_update: query failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                            : (conn->last_error ? conn->last_error : "pg_update: failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_update_sql — 返回 UPDATE SQL 字符串（不执行）
t_string tphp_fn_pg_update_sql(t_int conn_handle, t_string table_name, t_array *assoc, t_array *condition, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_update: invalid connection handle");
        return (t_string){0};
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_update: empty table name");
        return (t_string){0};
    }
    if (assoc == NULL || assoc->length <= 0) {
        tp_throw("pg_update: empty assoc array");
        return (t_string){0};
    }

    char *sql = _pg_build_update(conn, table, assoc, condition, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_update: failed to build SQL");
        return (t_string){0};
    }
    t_string result = _pg_mk_str(sql);
    free(sql);
    return result;
}

// ============================================================
// 9.5 pg_delete — 生成并执行 DELETE
// ============================================================

// pg_delete_result — 执行 DELETE，返回 PGresult 句柄
t_int tphp_fn_pg_delete_result(t_int conn_handle, t_string table_name, t_array *condition, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_delete: invalid connection handle");
        return 0;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_delete: empty table name");
        return 0;
    }

    char *sql = _pg_build_delete(conn, table, condition, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_delete: failed to build SQL");
        return 0;
    }

    PGresult *res = _pg_exec_query(conn, sql);
    free(sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_delete: query failed");
        return 0;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                            : (conn->last_error ? conn->last_error : "pg_delete: failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return 0;
    }
    return _PG_RESULT_TO_INT(res);
}

// pg_delete_sql — 返回 DELETE SQL 字符串（不执行）
t_string tphp_fn_pg_delete_sql(t_int conn_handle, t_string table_name, t_array *condition, t_int flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_delete: invalid connection handle");
        return (t_string){0};
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_delete: empty table name");
        return (t_string){0};
    }

    char *sql = _pg_build_delete(conn, table, condition, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_delete: failed to build SQL");
        return (t_string){0};
    }
    t_string result = _pg_mk_str(sql);
    free(sql);
    return result;
}

// ============================================================
// 9.6 pg_select — 生成 SELECT 并返回结果数组
//   执行 SELECT 查询，返回 t_array*（每元素为一行关联数组）
//   flags: PGSQL_DML_EXEC（执行）/ PGSQL_DML_ESCAPE（转义）
// ============================================================

t_array* tphp_fn_pg_select(t_int conn_handle, t_string table_name, t_array *assoc, t_int conditions, t_int flags) {
    (void)conditions;  // 当前实现固定使用 AND 连接条件
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_select: invalid connection handle");
        return NULL;
    }
    const char *table = STR_PTR(table_name);
    if (table == NULL || table_name.length == 0) {
        tp_throw("pg_select: empty table name");
        return NULL;
    }

    char *sql = _pg_build_select(conn, table, assoc, (int)flags);
    if (sql == NULL) {
        tp_throw("pg_select: failed to build SQL");
        return NULL;
    }

    PGresult *res = _pg_exec_query(conn, sql);
    free(sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_select: query failed");
        return NULL;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                            : (conn->last_error ? conn->last_error : "pg_select: query failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return NULL;
    }

    // 构建结果数组：每元素为一行关联数组（PGSQL_ASSOC）
    t_array *result = tphp_fn_arr_create(res->num_rows > 0 ? res->num_rows : 4);
    if (result == NULL) {
        _pg_result_free(res);
        tp_throw("pg_select: out of memory (result)");
        return NULL;
    }

    for (int i = 0; i < res->num_rows; i++) {
        t_array *row = tphp_fn_arr_create(res->num_fields > 0 ? res->num_fields : 4);
        if (row == NULL) {
            _pg_result_free(res);
            tphp_fn_arr_free(result);
            tp_throw("pg_select: out of memory (row)");
            return NULL;
        }
        int base = i * res->num_fields;
        for (int j = 0; j < res->num_fields; j++) {
            int val_len = res->row_lens[base + j];
            t_var val;
            if (val_len < 0) {
                val = VAR_NULL();
            } else {
                t_string s = _pg_mk_str_n(res->rows[base + j], val_len);
                val = VAR_STRING(s);
            }
            t_string key = _pg_mk_str(res->fields[j].name);
            row = tphp_fn_arr_set_str(row, key, val);
        }
        result = tphp_fn_arr_push(result, VAR_ARRAY(row));
    }

    _pg_result_free(res);
    tphp_rt_register((void*)result, 1);
    return result;
}
