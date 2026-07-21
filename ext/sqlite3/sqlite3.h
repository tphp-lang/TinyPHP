#pragma once
// ============================================================
// sqlite3.h — SQLite3 扩展（函数式 API，基于内置 SQLite amalgamation）
//
// 设计说明（对齐 ext/pdo 模式，函数式而非 OO）：
//   - 所有 PHP 可调用的 C 函数用 tphp_fn_sqlite_* 前缀
//   - CodeGenerator 自动加 tphp_fn_ 前缀，PHP 层直接调用 sqlite_*()
//   - 返回类型注册在 CodeGenerator.php 的 $builtinRetTypes
//   - 数据库句柄以 t_int 存储（sqlite3* 指针 ↔ t_int 转换）
//   - C 包装函数内部完成 t_int ↔ sqlite3* 转换
//   - 错误统一 tp_throw_ex（可被 catch(Exception $e) 捕获）
//   - 常量在 sqlite3.php 中以 const 声明（CodeGenerator 生成 TPHP_CONST_* #define）
//
// 与 PHP 原生 ext/sqlite3 的兼容性：
//   - 函数名保持一致（sqlite_open / sqlite_exec / sqlite_query 等）
//   - 行为语义保持一致（mode 参数、影响行数、最后插入 rowid 等）
//   - 底层使用 SQLite amalgamation 静态编译，零运行时依赖
//   - 仅支持函数式 API（不提供 SQLite3 / SQLite3Stmt / SQLite3Result 类）
//
// AOT 类型安全：
//   - 所有函数参数/返回值类型固定（t_int / t_string / t_bool / t_array*）
//   - 不使用 mixed / t_var / resource
//   - 查询结果统一返回 array<array<string>>（列值统一转字符串）
//
// SQLite 源码：include/os/sqlite_src/sqlite3.c (amalgamation 3.46.0)
//   通过 sqlite3.php 的 #flag __INC__ . "os/sqlite_src/sqlite3.c" 加入编译
//   与 ext/pdo 共享同一份源码（避免重复编译）
// ============================================================

#include "types.h"
#include "object/object.h"    // t_object 完整定义
#include "object/exception.h"
#include "object/try.h"
#include "val.h"
#include "array.h"
#include "runtime.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>

// ── SQLite amalgamation 头文件 ──
// 用路径相对包含避免被 tcc/include/sqlite3.h 顶替（macOS TCC bundle 自带 sqlite3.h）
// 搜索路径: -I"include" → include/os/sqlite_src/sqlite3.h（本仓库修补版）
#include "os/sqlite_src/sqlite3.h"

// ── 指针 ↔ t_int 转换宏（C 内部使用，不暴露给 PHP）──
#define _SQLITE3_INT_TO_DB(v)   ((sqlite3*)(intptr_t)(v))
#define _SQLITE3_DB_TO_INT(p)   ((t_int)(intptr_t)(p))

// ============================================================
// 内部辅助函数（static inline，仅本 TU 内使用，不暴露给 PHP）
// ============================================================

// ── 构造错误异常并抛出（简单消息，无 sqlite 上下文）──
static inline void _sqlite_throw_msg(const char *msg) {
    t_string s = {(char*)msg, (int)strlen(msg), false, false};
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ── 构造错误异常并抛出（dbh 上下文，附带 sqlite3_errmsg）──
static inline void _sqlite_throw_db_error(const char *msg_prefix, sqlite3 *db) {
    const char *emsg = db ? sqlite3_errmsg(db) : "unknown error";
    char buf[512];
    int n = snprintf(buf, sizeof(buf), "%s: %s", msg_prefix, emsg);
    t_string s = {(char*)buf, n > 0 ? n : 0, false, false};
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ── 读取当前行指定列的值，统一转为 t_string ──
//   int/float/null 转字符串，text/blob 深拷贝
//   sqlite 返回的指针在下次 step/reset 后失效，必须深拷贝到 str_pool
static inline t_string _sqlite_column_to_string(sqlite3_stmt *stmt, int col) {
    int type = sqlite3_column_type(stmt, col);
    if (type == SQLITE_NULL) {
        // null → 空字符串（AOT 类型安全：无法区分 NULL 和空串）
        return STR_LIT("");
    } else if (type == SQLITE_INTEGER) {
        char buf[32];
        int n = snprintf(buf, sizeof(buf), "%lld", (long long)sqlite3_column_int64(stmt, col));
        if (n <= 0) return STR_LIT("");
        char *p = str_pool_alloc(n);
        if (p) { memcpy(p, buf, (size_t)n); p[n] = 0; }
        return (t_string){p, n, false, false};
    } else if (type == SQLITE_FLOAT) {
        char buf[64];
        int n = snprintf(buf, sizeof(buf), "%g", sqlite3_column_double(stmt, col));
        if (n <= 0) return STR_LIT("");
        char *p = str_pool_alloc(n);
        if (p) { memcpy(p, buf, (size_t)n); p[n] = 0; }
        return (t_string){p, n, false, false};
    } else {
        // SQLITE_TEXT / SQLITE_BLOB — 统一按字节拷贝
        const void *ptr = sqlite3_column_text(stmt, col);
        int len = sqlite3_column_bytes(stmt, col);
        if (ptr == NULL || len <= 0) return STR_LIT("");
        char *p = str_pool_alloc(len);
        if (p) { memcpy(p, ptr, (size_t)len); p[len] = 0; }
        return (t_string){p, len, false, false};
    }
}

// ============================================================
// PHP 可调用的 C 函数（tphp_fn_sqlite_* 前缀）
//   CodeGenerator 自动加 tphp_fn_ 前缀，PHP 层调用 sqlite_*()
//   返回类型注册在 CodeGenerator.php 的 $builtinRetTypes
//
// 指针转换约定：
//   - PHP 层以 t_int 存储指针（t_int = int64_t，足够容纳 64 位指针）
//   - C 包装函数接收 t_int，内部用 _SQLITE3_INT_TO_DB 转换
//   - 返回指针的函数用 _SQLITE3_DB_TO_INT 转为 t_int
// ============================================================

// ── sqlite_open: 打开 SQLite 数据库（包装 sqlite3_open_v2）──
//   filename: 数据库文件路径（":memory:" 创建内存数据库）
//   flags: SQLITE3_OPEN_READONLY(1) / READWRITE(2) / CREATE(4) 组合
//   enc_key: 加密密钥（当前实现忽略，预留 SQLCipher 接口）
//   成功返回数据库句柄（int 形式），失败返回 0 并抛异常
//   注册返回类型: t_int
static inline t_int tphp_fn_sqlite_open(t_string filename, t_int flags, t_string enc_key) {
    (void)enc_key;  // 预留加密密钥接口，当前未使用
    const char *path = STR_PTR_V(filename);
    if (path == NULL || *path == '\0') {
        _sqlite_throw_msg("sqlite_open: filename is empty");
        return 0;
    }
    sqlite3 *db = NULL;
    // 自动加 SQLITE_OPEN_URI 以支持 :memory: 和 file: 协议
    int rc = sqlite3_open_v2(path, &db, (int)flags | SQLITE_OPEN_URI, NULL);
    if (rc != SQLITE_OK) {
        if (db != NULL) {
            _sqlite_throw_db_error("sqlite_open: failed to open database", db);
            sqlite3_close_v2(db);
        } else {
            _sqlite_throw_msg("sqlite_open: failed to open database (out of memory)");
        }
        return 0;
    }
    // 启用扩展结果码（更详细的错误信息）
    sqlite3_extended_result_codes(db, 1);
    return _SQLITE3_DB_TO_INT(db);
}

// ── sqlite_close: 关闭数据库（包装 sqlite3_close_v2）──
//   db_int: 数据库句柄（int 形式）
//   注册返回类型: void
static inline void tphp_fn_sqlite_close(t_int db_int) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db != NULL) {
        sqlite3_close_v2(db);
    }
}

// ── sqlite_exec: 执行无结果集 SQL（包装 sqlite3_exec）──
//   db_int: 数据库句柄（int 形式）
//   sql: SQL 语句
//   成功返回 t_bool(1)，失败抛异常返回 t_bool(0)
//   注册返回类型: t_bool
static inline t_bool tphp_fn_sqlite_exec(t_int db_int, t_string sql) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) {
        _sqlite_throw_msg("sqlite_exec: database handle is NULL");
        return false;
    }
    const char *sql_cstr = STR_PTR_V(sql);
    if (sql_cstr == NULL) {
        _sqlite_throw_msg("sqlite_exec: SQL is NULL");
        return false;
    }
    char *err = NULL;
    int rc = sqlite3_exec(db, sql_cstr, NULL, NULL, &err);
    if (rc != SQLITE_OK) {
        if (err != NULL) {
            char buf[512];
            int n = snprintf(buf, sizeof(buf), "sqlite_exec: %s", err);
            sqlite3_free(err);
            t_string s = {(char*)buf, n > 0 ? n : 0, false, false};
            tp_throw_ex(new_tphp_class_Exception(s));
        } else {
            _sqlite_throw_db_error("sqlite_exec: failed", db);
        }
        return false;
    }
    return true;
}

// ── sqlite_query: 查询返回所有行（包装 sqlite3_prepare_v2 + step 循环）──
//   db_int: 数据库句柄（int 形式）
//   sql: SQL 语句
//   mode: SQLITE3_ASSOC(1) / SQLITE3_NUM(2) / SQLITE3_BOTH(3)
//   返回 array<array<string>>（外层每项一行，内层每项一列值字符串）
//   失败抛异常返回空数组
//   注册返回类型: t_array*（外层元素是 t_array*，内层元素是 t_string）
static inline t_array* tphp_fn_sqlite_query(t_int db_int, t_string sql, t_int mode) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) {
        _sqlite_throw_msg("sqlite_query: database handle is NULL");
        return tphp_fn_arr_create(0);
    }
    const char *sql_cstr = STR_PTR_V(sql);
    if (sql_cstr == NULL) {
        _sqlite_throw_msg("sqlite_query: SQL is NULL");
        return tphp_fn_arr_create(0);
    }
    sqlite3_stmt *stmt = NULL;
    int rc = sqlite3_prepare_v2(db, sql_cstr, -1, &stmt, NULL);
    if (rc != SQLITE_OK) {
        _sqlite_throw_db_error("sqlite_query: prepare failed", db);
        return tphp_fn_arr_create(0);
    }
    int col_count = sqlite3_column_count(stmt);
    // 外层结果数组（行集合）
    t_array *result = tphp_fn_arr_create(8);
    if (result == NULL) {
        sqlite3_finalize(stmt);
        _sqlite_throw_msg("sqlite_query: out of memory");
        return tphp_fn_arr_create(0);
    }
    int m = (int)mode;
    while (1) {
        rc = sqlite3_step(stmt);
        if (rc == SQLITE_DONE) break;
        if (rc != SQLITE_ROW) {
            // 错误：先 finalize 再抛异常
            sqlite3_finalize(stmt);
            _sqlite_throw_db_error("sqlite_query: step failed", db);
            return result;
        }
        // 构造行数组
        t_array *row = tphp_fn_arr_create(col_count > 0 ? col_count : 4);
        if (row == NULL) {
            sqlite3_finalize(stmt);
            _sqlite_throw_msg("sqlite_query: out of memory");
            return result;
        }
        for (int i = 0; i < col_count; i++) {
            t_string val = _sqlite_column_to_string(stmt, i);
            if (m == 2) {
                // SQLITE3_NUM: 仅索引键
                row = tphp_fn_arr_push(row, VAR_STRING(val));
            } else if (m == 1) {
                // SQLITE3_ASSOC: 仅关联键
                const char *cname = (const char*)sqlite3_column_name(stmt, i);
                t_string key = cname ? tphp_rt_str_dup((t_string){(char*)cname, (int)strlen(cname), false, false})
                                      : STR_LIT("");
                row = tphp_fn_arr_set_str(row, key, VAR_STRING(val));
            } else {
                // SQLITE3_BOTH (默认): 关联 + 索引
                const char *cname = (const char*)sqlite3_column_name(stmt, i);
                t_string key = cname ? tphp_rt_str_dup((t_string){(char*)cname, (int)strlen(cname), false, false})
                                      : STR_LIT("");
                row = tphp_fn_arr_set_str(row, key, VAR_STRING(val));
                row = tphp_fn_arr_set_int(row, (t_int)i, VAR_STRING(val));
            }
        }
        result = tphp_fn_arr_push(result, VAR_ARRAY(row));
    }
    sqlite3_finalize(stmt);
    // 注册结果数组到运行时清理列表（type=1 表示 t_array*）
    tphp_rt_register((void*)result, 1);
    return result;
}

// ── sqlite_query_single: 查询返回第一行 ──
//   无结果返回空数组（不抛异常），失败抛异常返回空数组
//   注册返回类型: t_array*（元素是 t_string）
static inline t_array* tphp_fn_sqlite_query_single(t_int db_int, t_string sql, t_int mode) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) {
        _sqlite_throw_msg("sqlite_query_single: database handle is NULL");
        return tphp_fn_arr_create(0);
    }
    const char *sql_cstr = STR_PTR_V(sql);
    if (sql_cstr == NULL) {
        _sqlite_throw_msg("sqlite_query_single: SQL is NULL");
        return tphp_fn_arr_create(0);
    }
    sqlite3_stmt *stmt = NULL;
    int rc = sqlite3_prepare_v2(db, sql_cstr, -1, &stmt, NULL);
    if (rc != SQLITE_OK) {
        _sqlite_throw_db_error("sqlite_query_single: prepare failed", db);
        return tphp_fn_arr_create(0);
    }
    int col_count = sqlite3_column_count(stmt);
    rc = sqlite3_step(stmt);
    if (rc == SQLITE_DONE) {
        // 无结果 → 空数组
        sqlite3_finalize(stmt);
        t_array *empty = tphp_fn_arr_create(0);
        if (empty) tphp_rt_register((void*)empty, 1);
        return empty;
    }
    if (rc != SQLITE_ROW) {
        sqlite3_finalize(stmt);
        _sqlite_throw_db_error("sqlite_query_single: step failed", db);
        return tphp_fn_arr_create(0);
    }
    // 构造单行数组
    t_array *row = tphp_fn_arr_create(col_count > 0 ? col_count : 4);
    if (row == NULL) {
        sqlite3_finalize(stmt);
        _sqlite_throw_msg("sqlite_query_single: out of memory");
        return tphp_fn_arr_create(0);
    }
    int m = (int)mode;
    for (int i = 0; i < col_count; i++) {
        t_string val = _sqlite_column_to_string(stmt, i);
        if (m == 2) {
            row = tphp_fn_arr_push(row, VAR_STRING(val));
        } else if (m == 1) {
            const char *cname = (const char*)sqlite3_column_name(stmt, i);
            t_string key = cname ? tphp_rt_str_dup((t_string){(char*)cname, (int)strlen(cname), false, false})
                                  : STR_LIT("");
            row = tphp_fn_arr_set_str(row, key, VAR_STRING(val));
        } else {
            const char *cname = (const char*)sqlite3_column_name(stmt, i);
            t_string key = cname ? tphp_rt_str_dup((t_string){(char*)cname, (int)strlen(cname), false, false})
                                  : STR_LIT("");
            row = tphp_fn_arr_set_str(row, key, VAR_STRING(val));
            row = tphp_fn_arr_set_int(row, (t_int)i, VAR_STRING(val));
        }
    }
    sqlite3_finalize(stmt);
    tphp_rt_register((void*)row, 1);
    return row;
}

// ── sqlite_escape_string: 转义字符串（使用 sqlite3_mprintf %q）──
//   返回不带引号的转义字符串（单引号翻倍为 ''）
//   注意：sqlite3_mprintf 返回的内存需要 sqlite3_free 释放
//   拷贝到 str_pool 后立即 sqlite3_free
//   注册返回类型: t_string
static inline t_string tphp_fn_sqlite_escape_string(t_string str) {
    const char *s = STR_PTR_V(str);
    if (s == NULL) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    // %q 转义：单引号翻倍为 ''，其他字符原样（不含外层引号）
    char *escaped = sqlite3_mprintf("%q", s);
    if (escaped == NULL) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    int len = (int)strlen(escaped);
    char *p = str_pool_alloc(len);
    t_string result;
    if (p) {
        memcpy(p, escaped, (size_t)len);
        p[len] = 0;
        result = (t_string){p, len, false, false};
    } else {
        result = (t_string){NULL, 0, false, false};
    }
    sqlite3_free(escaped);
    return result;
}

// ── sqlite_changes: 最近一次 INSERT/UPDATE/DELETE 影响行数 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_sqlite_changes(t_int db_int) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) return 0;
    return (t_int)sqlite3_changes(db);
}

// ── sqlite_last_insert_rowid: 最近一次 INSERT 的 rowid ──
//   注册返回类型: t_int
static inline t_int tphp_fn_sqlite_last_insert_rowid(t_int db_int) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) return 0;
    return (t_int)sqlite3_last_insert_rowid(db);
}

// ── sqlite_last_error_msg: 最近一次错误消息 ──
//   深拷贝到 str_pool 返回 t_string
//   注册返回类型: t_string
static inline t_string tphp_fn_sqlite_last_error_msg(t_int db_int) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) return STR_LIT("no database connection");
    const char *emsg = sqlite3_errmsg(db);
    if (emsg == NULL) return STR_LIT("");
    int len = (int)strlen(emsg);
    char *p = str_pool_alloc(len);
    if (p) {
        memcpy(p, emsg, (size_t)len);
        p[len] = 0;
        return (t_string){p, len, false, false};
    }
    return STR_LIT("");
}

// ── sqlite_last_error_code: 最近一次错误码 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_sqlite_last_error_code(t_int db_int) {
    sqlite3 *db = _SQLITE3_INT_TO_DB(db_int);
    if (db == NULL) return 0;
    return (t_int)sqlite3_errcode(db);
}

// ── sqlite_version: SQLite 库版本 ──
//   深拷贝返回 t_string
//   注册返回类型: t_string
static inline t_string tphp_fn_sqlite_version(void) {
    const char *v = sqlite3_libversion();
    if (v == NULL) return STR_LIT("");
    int len = (int)strlen(v);
    char *p = str_pool_alloc(len);
    if (p) {
        memcpy(p, v, (size_t)len);
        p[len] = 0;
        return (t_string){p, len, false, false};
    }
    return STR_LIT("");
}
