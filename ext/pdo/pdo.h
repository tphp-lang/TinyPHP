#pragma once
// ============================================================
// pdo.h — PDO 扩展（SQLite 驱动）
//
// 设计说明（对齐 openssl/exif 扩展模式）：
//   - 所有 PHP 可调用的 C 函数用 tphp_fn_pdo_* 前缀
//   - CodeGenerator 自动加 tphp_fn_ 前缀，PHP 层直接调用 pdo_*()
//   - 返回类型注册在 CodeGenerator.php 的 $builtinRetTypes
//   - 指针在 PHP 层以 int 存储（phpc_ptr_to_int / phpc_int_to_ptr 转换）
//   - C 包装函数内部完成 t_int ↔ sqlite3*/sqlite3_stmt* 转换
//   - 错误统一 tp_throw_ex（可被 catch(Exception $e) 捕获）
//   - 常量在 .h 中以 TPHP_CONST_TPHP_CLASS_PDO_* 定义
//
// 与 PHP 原生 PDO 的兼容性：
//   - 类名、方法名、常量名保持一致
//   - 行为语义保持一致（fetch 模式、事务、绑定等）
//   - 底层使用 SQLite amalgamation 静态编译，零运行时依赖
//   - 仅支持 SQLite 驱动（DSN 前缀 "sqlite:"）
//
// 砍掉的功能（AOT 不兼容或极少使用）：
//   - FETCH_LAZY / FETCH_FUNC / FETCH_SERIALIZE（依赖 zend 运行时）
//   - ATTR_PERSISTENT / ATTR_STATEMENT_CLASS（依赖 persistent_list / zend_class_entry）
//   - PARAM_INPUT_OUTPUT / PARAM_STR_NATL / PARAM_STR_CHAR（标志位）
//   - UDF：createFunction / createAggregate / createCollation（依赖 zend_fcall_info）
//   - loadExtension / openBlob（AOT 不适用）
//
// SQLite 源码：include/os/sqlite_src/sqlite3.c (amalgamation 3.46.0)
//   通过 pdo.php 的 #flag __INC__ . "os/sqlite_src/sqlite3.c" 加入编译
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
#include <ctype.h>
#include <stdint.h>

// ── SQLite amalgamation 头文件 ──
// 用路径相对包含避免被 tcc/include/sqlite3.h 顶替（macOS TCC bundle 自带 sqlite3.h）
// 搜索路径: -I"include" → include/os/sqlite_src/sqlite3.h（本仓库修补版）
#include "os/sqlite_src/sqlite3.h"

// ── 指针 ↔ t_int 转换宏（C 内部使用，不暴露给 PHP）──
#define _PDO_INT_TO_DB(v)   ((sqlite3*)(intptr_t)(v))
#define _PDO_INT_TO_STMT(v) ((sqlite3_stmt*)(intptr_t)(v))
#define _PDO_DB_TO_INT(p)   ((t_int)(intptr_t)(p))
#define _PDO_STMT_TO_INT(p) ((t_int)(intptr_t)(p))

// ============================================================
// 常量定义（CodeGenerator 强制大写，TPHP_CONST_<类名大写>_<常量名大写>）
// ============================================================

// ── PDO::PARAM_* 参数类型 ──
#define TPHP_CONST_TPHP_CLASS_PDO_PARAM_NULL  0
#define TPHP_CONST_TPHP_CLASS_PDO_PARAM_INT   1
#define TPHP_CONST_TPHP_CLASS_PDO_PARAM_STR   2
#define TPHP_CONST_TPHP_CLASS_PDO_PARAM_LOB   3
#define TPHP_CONST_TPHP_CLASS_PDO_PARAM_BOOL  5

// ── PDO::FETCH_* fetch 模式 ──
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_DEFAULT    0
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_ASSOC      2
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_NUM        3
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_BOTH       4
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_OBJ        5
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_BOUND      6
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_COLUMN     7
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_CLASS      8
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_INTO       9
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_NAMED     11
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_KEY_PAIR  12
// FETCH_LAZY(1)/FETCH_FUNC(10)/FETCH_SERIALIZE(512) 不支持，已砍掉

// ── PDO::ATTR_* 属性 ──
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_AUTOCOMMIT          0
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_TIMEOUT             2
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_ERRMODE             3
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_SERVER_VERSION      4
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_CLIENT_VERSION      5
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_SERVER_INFO         6
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_CONNECTION_STATUS   7
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_CASE               8
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_CURSOR             10
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_ORACLE_NULLS       11
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_DRIVER_NAME        16
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_STRINGIFY_FETCHES  17
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_DEFAULT_FETCH_MODE 19

// ── PDO::ERRMODE_* 错误模式 ──
#define TPHP_CONST_TPHP_CLASS_PDO_ERRMODE_SILENT    0
#define TPHP_CONST_TPHP_CLASS_PDO_ERRMODE_WARNING   1
#define TPHP_CONST_TPHP_CLASS_PDO_ERRMODE_EXCEPTION 2

// ── PDO::CASE_* 列名大小写 ──
#define TPHP_CONST_TPHP_CLASS_PDO_CASE_NATURAL 0
#define TPHP_CONST_TPHP_CLASS_PDO_CASE_LOWER   1
#define TPHP_CONST_TPHP_CLASS_PDO_CASE_UPPER   2

// ── PDO::CURSOR_* 游标类型（SQLite 仅支持前向）──
#define TPHP_CONST_TPHP_CLASS_PDO_CURSOR_FWDONLY 0
#define TPHP_CONST_TPHP_CLASS_PDO_CURSOR_SCROLL  1

// ── PDO::NULL_* Oracle 兼容空值处理 ──
#define TPHP_CONST_TPHP_CLASS_PDO_NULL_NATURAL      0
#define TPHP_CONST_TPHP_CLASS_PDO_NULL_EMPTY_STRING 1
#define TPHP_CONST_TPHP_CLASS_PDO_NULL_TO_STRING    2

// ── PDO::FETCH_ORI_* 游标方向（SQLite 仅支持 NEXT）──
#define TPHP_CONST_TPHP_CLASS_PDO_FETCH_ORI_NEXT 0

// ── PDO::ERR_NONE 无错误 SQLSTATE ──
#define TPHP_CONST_TPHP_CLASS_PDO_ERR_NONE  STR_LIT("00000")

// ── SQLite 驱动特有常量（PDO::ATTR_* 1000+）──
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_OPEN_FLAGS            1000
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_READONLY_STATEMENT    1001
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_EXTENDED_RESULT_CODES 1002
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_BUSY_STATEMENT        1003
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_TRANSACTION_MODE      1005

// ── PDO::OPEN_* sqlite3_open_v2 flags ──
#define TPHP_CONST_TPHP_CLASS_PDO_OPEN_READONLY  1
#define TPHP_CONST_TPHP_CLASS_PDO_OPEN_READWRITE 2
#define TPHP_CONST_TPHP_CLASS_PDO_OPEN_CREATE    4

// ── PDO::TRANSACTION_MODE_* 事务模式 ──
#define TPHP_CONST_TPHP_CLASS_PDO_TRANSACTION_MODE_DEFERRED  0
#define TPHP_CONST_TPHP_CLASS_PDO_TRANSACTION_MODE_IMMEDIATE 1
#define TPHP_CONST_TPHP_CLASS_PDO_TRANSACTION_MODE_EXCLUSIVE 2

// ============================================================
// 内部辅助函数（static inline，仅本 TU 内使用，不暴露给 PHP）
// ============================================================

// ── 构造错误异常并抛出（dbh 上下文）──
//   tp_throw_ex 接收 Exception 对象指针（非 t_string）
static inline void _pdo_throw_db_error(const char *msg_prefix, sqlite3 *db) {
    const char *emsg = db ? sqlite3_errmsg(db) : "unknown error";
    char buf[512];
    int n = snprintf(buf, sizeof(buf), "%s: %s", msg_prefix, emsg);
    t_string s = {(char*)buf, n > 0 ? n : 0, false, false};
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ── 构造错误异常并抛出（stmt 上下文）──
static inline void _pdo_throw_stmt_error(const char *msg_prefix, sqlite3_stmt *stmt) {
    const char *emsg = stmt ? sqlite3_errmsg(sqlite3_db_handle(stmt)) : "unknown error";
    char buf[512];
    int n = snprintf(buf, sizeof(buf), "%s: %s", msg_prefix, emsg);
    t_string s = {(char*)buf, n > 0 ? n : 0, false, false};
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ── 构造错误异常并抛出（简单消息，无 sqlite 上下文）──
static inline void _pdo_throw_msg(const char *msg) {
    t_string s = {(char*)msg, (int)strlen(msg), false, false};
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ============================================================
// PHP 可调用的 C 函数（tphp_fn_pdo_* 前缀）
//   CodeGenerator 自动加 tphp_fn_ 前缀，PHP 层调用 pdo_*()
//   返回类型注册在 CodeGenerator.php 的 $builtinRetTypes
//
// 指针转换约定：
//   - PHP 层以 int 存储指针（t_int = int64_t，足够容纳 64 位指针）
//   - C 包装函数接收 t_int，内部用 _PDO_INT_TO_DB/_PDO_INT_TO_STMT 转换
//   - 返回指针的函数用 _PDO_DB_TO_INT/_PDO_STMT_TO_INT 转为 t_int
// ============================================================

// ── pdo_throw_msg: 抛出异常（PHP 层调用，用于错误处理）──
//   msg: t_string 消息（PHP 字符串字面量编译为 STR_LIT → t_string）
//   注册返回类型: void
static inline void tphp_fn_pdo_throw_msg(t_string msg) {
    _pdo_throw_msg(STR_PTR_V(msg));
}

// ── pdo_throw_db_error: 抛出 db 错误异常 ──
//   msg_prefix: t_string 消息前缀
//   db_int: 数据库句柄（int 形式）
//   注册返回类型: void
static inline void tphp_fn_pdo_throw_db_error(t_string msg_prefix, t_int db_int) {
    _pdo_throw_db_error(STR_PTR_V(msg_prefix), _PDO_INT_TO_DB(db_int));
}

// ── pdo_throw_stmt_error: 抛出 stmt 错误异常 ──
//   msg_prefix: t_string 消息前缀
//   stmt_int: 语句句柄（int 形式）
//   注册返回类型: void
static inline void tphp_fn_pdo_throw_stmt_error(t_string msg_prefix, t_int stmt_int) {
    _pdo_throw_stmt_error(STR_PTR_V(msg_prefix), _PDO_INT_TO_STMT(stmt_int));
}

// ── pdo_open_db: 打开 SQLite 数据库（包装 sqlite3_open_v2 + DSN 解析）──
//   dsn: 完整 DSN 字符串（如 "sqlite::memory:" 或 "sqlite:/path/to/db"）
//   flags: PDO::OPEN_* 标志组合
//   成功返回数据库句柄（int 形式），失败返回 0 并抛异常
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_open_db(const char *dsn, int flags) {
    if (dsn == NULL) {
        _pdo_throw_msg("PDO::__construct: DSN is NULL");
        return 0;
    }
    // 解析 "sqlite:" 前缀
    if (strncasecmp(dsn, "sqlite:", 7) != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "PDO: only 'sqlite' driver is supported, got: %.64s", dsn);
        _pdo_throw_msg(buf);
        return 0;
    }
    const char *path = dsn + 7;
    if (*path == '\0') {
        _pdo_throw_msg("PDO::__construct: empty path in DSN");
        return 0;
    }
    // 打开数据库（自动加 SQLITE_OPEN_URI 以支持 :memory: 和 file: 协议）
    sqlite3 *db = NULL;
    int rc = sqlite3_open_v2(path, &db, flags | SQLITE_OPEN_URI, NULL);
    if (rc != SQLITE_OK) {
        if (db != NULL) {
            _pdo_throw_db_error("PDO::__construct: failed to open database", db);
            sqlite3_close_v2(db);
        } else {
            _pdo_throw_msg("PDO::__construct: failed to open database (out of memory)");
        }
        return 0;
    }
    return _PDO_DB_TO_INT(db);
}

// ── pdo_prepare: 预处理 SQL（包装 sqlite3_prepare_v2）──
//   db_int: 数据库句柄（int 形式）
//   成功返回语句句柄（int 形式），失败返回 0 并抛异常
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_prepare(t_int db_int, const char *sql) {
    sqlite3 *db = _PDO_INT_TO_DB(db_int);
    if (db == NULL || sql == NULL) {
        _pdo_throw_msg("PDO::prepare: db or sql is NULL");
        return 0;
    }
    sqlite3_stmt *stmt = NULL;
    int rc = sqlite3_prepare_v2(db, sql, -1, &stmt, NULL);
    if (rc != SQLITE_OK) {
        _pdo_throw_db_error("PDO::prepare: failed to prepare statement", db);
        return 0;
    }
    return _PDO_STMT_TO_INT(stmt);
}

// ── pdo_exec: 执行无结果集的 SQL（包装 sqlite3_exec）──
//   db_int: 数据库句柄（int 形式）
//   成功返回受影响行数，失败返回 -1 并抛异常
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_exec(t_int db_int, const char *sql) {
    sqlite3 *db = _PDO_INT_TO_DB(db_int);
    if (db == NULL || sql == NULL) {
        _pdo_throw_msg("PDO::exec: db or sql is NULL");
        return -1;
    }
    char *err = NULL;
    int rc = sqlite3_exec(db, sql, NULL, NULL, &err);
    if (rc != SQLITE_OK) {
        if (err != NULL) {
            char buf[512];
            snprintf(buf, sizeof(buf), "PDO::exec: %s", err);
            sqlite3_free(err);
            t_string s = {(char*)buf, (int)strlen(buf), false, false};
            tp_throw_ex(new_tphp_class_Exception(s));
        } else {
            _pdo_throw_db_error("PDO::exec: failed", db);
        }
        return -1;
    }
    return (t_int)sqlite3_changes(db);
}

// ── pdo_str_from_ptr: 从 const char* + len 构造 t_string ──
//   深拷贝到 str_pool（sqlite 返回的指针在下一次 step/reset 后失效）
//   注册返回类型: t_string
static inline t_string tphp_fn_pdo_str_from_ptr(const char *ptr, int len) {
    if (ptr == NULL || len <= 0) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    char *buf = str_pool_alloc(len);
    if (buf == NULL) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    memcpy(buf, ptr, (size_t)len);
    buf[len] = '\0';
    t_string s = {buf, len, false, false};
    return s;
}

// ── pdo_str_len: 字符串长度（包装 strlen）──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_str_len(const char *s) {
    return s ? (t_int)strlen(s) : 0;
}

// ── pdo_bind_text: 包装 sqlite3_bind_text ──
//   stmt_int: 语句句柄（int 形式）
//   SQLITE_TRANSIENT 让 sqlite 在 bind 时拷贝数据
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_bind_text(t_int stmt_int, int idx, const char *text, int len) {
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    return (t_int)sqlite3_bind_text(stmt, idx, text, len, SQLITE_TRANSIENT);
}

// ── pdo_bind_params: 批量绑定参数（从 t_array* 提取 t_var 并按类型绑定）──
//   内部处理 t_var 类型分发，PHP 层只需传入 array（避免 mixed/t_var 暴露到 PHP API）
//   支持位置参数（int 键，1-based）和命名参数（string 键 ":name"）
//   注册返回类型: void
static inline void tphp_fn_pdo_bind_params(t_int stmt_int, t_array* params) {
    if (stmt_int == 0 || params == NULL || params->length == 0) return;
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    int len = params->length;
    // 检查第一个键是否是字符串（命名参数模式）
    int has_str_key = 0;
    if (len > 0 && params->entries[0].key.type == TYPE_STRING) {
        has_str_key = 1;
    }
    for (int i = 0; i < len; i++) {
        t_var key = params->entries[i].key;
        t_var val = params->entries[i].val;
        int idx;
        if (has_str_key) {
            // 命名参数：用 sqlite3_bind_parameter_index 查找
            idx = (int)sqlite3_bind_parameter_index(stmt, STR_PTR(key.value._string));
        } else {
            // 位置参数：1-based
            idx = i + 1;
        }
        if (idx == 0) continue;
        // 按 t_var 类型标签分发绑定
        switch (val.type) {
            case TYPE_NULL:
                sqlite3_bind_null(stmt, idx);
                break;
            case TYPE_INT:
                sqlite3_bind_int64(stmt, idx, val.value._int);
                break;
            case TYPE_FLOAT:
                sqlite3_bind_double(stmt, idx, val.value._float);
                break;
            case TYPE_BOOL:
                sqlite3_bind_int64(stmt, idx, val.value._bool ? 1 : 0);
                break;
            case TYPE_STRING:
                sqlite3_bind_text(stmt, idx, STR_PTR(val.value._string),
                                  val.value._string.length, SQLITE_TRANSIENT);
                break;
            default:
                break;
        }
    }
}

// ── pdo_bind_blob: 包装 sqlite3_bind_blob ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_bind_blob(t_int stmt_int, int idx, const char *data, int len) {
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    return (t_int)sqlite3_bind_blob(stmt, idx, data, len, SQLITE_TRANSIENT);
}

// ── pdo_column_text: 包装 sqlite3_column_text，返回 const char* ──
//   sqlite3_column_text 返回 const unsigned char*，这里 cast 为 const char*
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_column_text(t_int stmt_int, int col) {
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    return (const char*)sqlite3_column_text(stmt, col);
}

// ── pdo_column_name: 包装 sqlite3_column_name ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_column_name(t_int stmt_int, int col) {
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    return sqlite3_column_name(stmt, col);
}

// ── pdo_column_decltype: 包装 sqlite3_column_decltype ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_column_decltype(t_int stmt_int, int col) {
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    return sqlite3_column_decltype(stmt, col);
}

// ── pdo_errmsg: 包装 sqlite3_errmsg ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_errmsg(t_int db_int) {
    sqlite3 *db = _PDO_INT_TO_DB(db_int);
    return db ? sqlite3_errmsg(db) : "no database connection";
}

// ── pdo_libversion: 包装 sqlite3_libversion ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_libversion(void) {
    return sqlite3_libversion();
}

// ── pdo_column_double: 包装 sqlite3_column_double ──
//   注册返回类型: t_float
static inline t_float tphp_fn_pdo_column_double(t_int stmt_int, int col) {
    sqlite3_stmt *stmt = _PDO_INT_TO_STMT(stmt_int);
    return sqlite3_column_double(stmt, col);
}

// ── pdo_sqlite_errstate: sqlite3 错误码 → SQLSTATE 5 字符串 ──
//   映射逻辑参考 PHP pdo_sqlite 驱动 _pdo_sqlite_error
//   注册返回类型: t_string
static inline t_string tphp_fn_pdo_sqlite_errstate(int rc) {
    const char *s = "HY000";  // 默认 General error
    switch (rc) {
        case SQLITE_OK:        s = "00000"; break;
        case SQLITE_NOTFOUND:  s = "42S02"; break;
        case SQLITE_CONSTRAINT:s = "23000"; break;
        case SQLITE_TOOBIG:    s = "22001"; break;
        case SQLITE_INTERRUPT: s = "01002"; break;
        case SQLITE_NOLFS:     s = "HYC00"; break;
        default: break;
    }
    // 注意：s 是 const char* 变量，不能用 STR_LIT（sizeof 指针=8 而非串长）
    return tphp_rt_str_dup((t_string){(char*)s, (int)strlen(s)});
}

// ── pdo_quote: 转义字符串（用 sqlite3_mprintf 的 %Q 格式）──
//   返回带单引号的转义字符串
//   注册返回类型: t_string
static inline t_string tphp_fn_pdo_quote(const char *s) {
    if (s == NULL) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    char *quoted = sqlite3_mprintf("%Q", s);
    if (quoted == NULL) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    t_string result = tphp_rt_str_dup((t_string){quoted, (int)strlen(quoted), false, false});
    sqlite3_free(quoted);
    return result;
}
