#pragma once
// ============================================================
// pdo_driver.h — PDO 驱动抽象接口
//
// 设计目标：
//   - 统一接口支持多种数据库（sqlite/mysql/pgsql...）
//   - PHP 层 API 完全不变（class PDO / class PDOStatement）
//   - 编译期类型固定（AOT 兼容），运行时通过函数指针表分发
//   - 零额外开销（C 编译器优化间接调用）
//
// 架构：
//   - pdo_driver_t：驱动接口（函数指针表 + 驱动元信息）
//   - pdo_find_driver(name)：按 DSN 前缀查找已注册驱动
//   - 每个驱动独立扩展（ext/pdo_sqlite, ext/pdo_mysql, ...）
//   - 驱动用 constructor 自动注册
//
// PHP 层使用：
//   - PDO 类持有 driver 指针（int 形式）+ dbh 指针（int 形式）
//   - PDOStatement 类持有 driver 指针 + stmt 指针
//   - 所有 C 调用通过 pdo_driver_* 包装函数，内部调用 driver->func
//
// 错误处理：
//   - driver 函数返回 <0 表示错误，errmsg 提供错误描述
//   - PHP 层包装函数检测返回值，失败时抛 Exception
//   - 错误消息由 driver 提供（sqlite3_errmsg / mysql_error / PQerrorMessage）
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include <stdint.h>

// ── Windows 兼容：strcasecmp/strncasecmp ──
// Windows CRT 无 strcasecmp/strncasecmp，使用 _stricmp/_strnicmp 替代
#ifdef _WIN32
#ifndef _TPHP_STRCASE_COMPAT
#define _TPHP_STRCASE_COMPAT
#define strcasecmp _stricmp
#define strncasecmp _strnicmp
#endif
#endif

// ── 前向声明 ──
typedef struct pdo_driver_t pdo_driver_t;

// ── 列类型常量（所有 driver 统一）──
#define PDO_COL_INT    1
#define PDO_COL_FLOAT  2
#define PDO_COL_TEXT   3
#define PDO_COL_BLOB   4
#define PDO_COL_NULL   5

// ── step 返回值常量（所有 driver 统一）──
#define PDO_STEP_ROW   100
#define PDO_STEP_DONE  101

// ============================================================
// pdo_driver_t — 驱动接口（函数指针表）
//
// 每个驱动必须实现所有函数指针，无默认实现（强制完整契约）
// ============================================================
struct pdo_driver_t {
    const char* name;             // 驱动名（DSN 前缀，如 "sqlite" / "mysql"）

    // ── 连接管理 ──
    // open: 解析完整 DSN（含前缀），返回 dbh 指针
    //   返回 0=ok, <0=err
    int  (*open)(const char* dsn, int flags, const char* user, const char* pass, void** dbh);
    void (*close)(void* dbh);

    // ── SQL 执行 ──
    // exec: 执行无结果集 SQL，返回受影响行数（>=0），<0=err
    int  (*exec)(void* dbh, const char* sql);
    // prepare: 预处理 SQL，返回 stmt 指针
    //   返回 0=ok, <0=err
    int  (*prepare)(void* dbh, const char* sql, void** stmt);

    // ── 参数绑定（位置参数 1-based）──
    int  (*bind_int)(void* stmt, int idx, int64_t val);
    int  (*bind_text)(void* stmt, int idx, const char* val, int len);
    int  (*bind_blob)(void* stmt, int idx, const char* data, int len);
    int  (*bind_null)(void* stmt, int idx);
    // 命名参数：返回 1-based idx，0=not found
    int  (*bind_param_index)(void* stmt, const char* name);

    // ── 执行 & 游标控制 ──
    // step: PDO_STEP_ROW=有数据 / PDO_STEP_DONE=结束 / <0=err
    int  (*step)(void* stmt);
    int  (*reset)(void* stmt);
    int  (*clear_bindings)(void* stmt);
    int  (*finalize)(void* stmt);

    // ── 列信息 ──
    int  (*column_count)(void* stmt);
    int  (*column_type)(void* stmt, int col);     // PDO_COL_*
    int64_t (*column_int64)(void* stmt, int col);
    double  (*column_double)(void* stmt, int col);
    const char* (*column_text)(void* stmt, int col);
    int  (*column_bytes)(void* stmt, int col);
    const char* (*column_name)(void* stmt, int col);
    const char* (*column_decltype)(void* stmt, int col);
    int  (*data_count)(void* stmt);

    // ── 连接信息 ──
    int64_t (*changes)(void* dbh);
    int64_t (*last_insert_rowid)(void* dbh);
    int  (*errcode)(void* dbh);
    const char* (*errmsg)(void* dbh);
    int  (*busy_timeout)(void* dbh, int ms);
    void (*extended_result_codes)(void* dbh, int on);

    // ── 转义（driver 特定语法）──
    // quote: 返回 malloc'd 字符串（带引号），调用方用 free_quote 释放
    char* (*quote)(const char* s);
    void  (*free_quote)(char* s);

    // ── 驱动元信息 ──
    const char* (*driver_name)(void);            // 如 "sqlite"
    const char* (*server_version)(void* dbh);    // 如 "3.46.0"（dbh 可能为 NULL，driver 自行处理）
};

// ============================================================
// 驱动注册/查找（驱动实现方调用）
//
// 注意：TinyPHP 单 TU 编译模式，static 变量在整个程序中唯一
// ============================================================

#include <string.h>

#define PDO_MAX_DRIVERS 8
static const pdo_driver_t* _pdo_drivers[PDO_MAX_DRIVERS];
static int _pdo_driver_count = 0;

// pdo_register_driver: 注册驱动到全局表
//   通常在 driver .h 文件的 constructor 中调用
static inline void pdo_register_driver(const pdo_driver_t* drv) {
    if (drv == NULL || drv->name == NULL || _pdo_driver_count >= PDO_MAX_DRIVERS) return;
    // 避免重复注册
    for (int i = 0; i < _pdo_driver_count; i++) {
        if (_pdo_drivers[i] == drv) return;
    }
    _pdo_drivers[_pdo_driver_count++] = drv;
}

// pdo_find_driver: 按名称查找驱动（名称匹配 drv->name）
//   返回驱动指针，未找到返回 NULL
static inline const pdo_driver_t* pdo_find_driver(const char* name) {
    if (name == NULL) return NULL;
    for (int i = 0; i < _pdo_driver_count; i++) {
        if (_pdo_drivers[i] && strcasecmp(_pdo_drivers[i]->name, name) == 0) {
            return _pdo_drivers[i];
        }
    }
    return NULL;
}

// ============================================================
// 内部：抛异常辅助 ──────────────────────────────────────────
static inline void _pdo_driver_throw(const char* msg) {
    t_string s;
    s.data = (char*)msg;
    s.length = (int)strlen(msg);
    s.is_local = false;
    s.is_lit = false;
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ============================================================
// PHP 层包装函数（tphp_fn_pdo_driver_*）
//   接收 driver 指针（t_int）+ 参数，内部调用 driver->func
//   CodeGenerator 自动加 tphp_fn_ 前缀
// ============================================================

// ── 指针转换宏 ──
#define _PDO_DRV_FROM_INT(v)  ((const pdo_driver_t*)(intptr_t)(v))
#define _PDO_DRV_TO_INT(p)    ((t_int)(intptr_t)(p))
#define _PDO_DBH_FROM_INT(v)  ((void*)(intptr_t)(v))
#define _PDO_STMT_FROM_INT(v) ((void*)(intptr_t)(v))

// ── pdo_driver_find: 按名称查找驱动 ──
//   返回 driver 指针（int 形式），未找到返回 0
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_find(t_string name) {
    const pdo_driver_t* drv = pdo_find_driver(STR_PTR_V(name));
    return _PDO_DRV_TO_INT(drv);
}

// ── pdo_driver_name: 返回驱动名 ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_name(t_int drv_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    return drv ? drv->name : "unknown";
}

// ── pdo_driver_open: 打开数据库 ──
//   返回 dbh 指针（int 形式），失败返回 0
//   失败时错误信息保存到 _pdo_last_open_error
//   注册返回类型: t_int
static char _pdo_last_open_error[512] = "";

static inline t_int tphp_fn_pdo_driver_open(t_int drv_int, t_string dsn, t_int flags, t_string user, t_string pass) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    _pdo_last_open_error[0] = '\0';
    if (drv == NULL || drv->open == NULL) {
        strncpy(_pdo_last_open_error, "driver or open function is NULL", sizeof(_pdo_last_open_error) - 1);
        return 0;
    }
    void* dbh = NULL;
    int rc = drv->open(STR_PTR_V(dsn), (int)flags, STR_PTR_V(user), STR_PTR_V(pass), &dbh);
    if (rc < 0) {
        // 失败：如果 dbh 非空（driver 在失败时保留了 conn 用于错误查询），获取 errmsg 然后 close
        if (dbh != NULL) {
            if (drv->errmsg) {
                const char* msg = drv->errmsg(dbh);
                if (msg) strncpy(_pdo_last_open_error, msg, sizeof(_pdo_last_open_error) - 1);
            }
            if (drv->close) drv->close(dbh);
        }
        return 0;
    }
    return (t_int)(intptr_t)dbh;
}

// ── pdo_driver_last_open_error: 获取最近一次 open 失败的错误信息 ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_last_open_error(void) {
    return _pdo_last_open_error;
}

// ── pdo_driver_close: 关闭数据库 ──
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_close(t_int drv_int, t_int dbh_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->close == NULL) return;
    drv->close(_PDO_DBH_FROM_INT(dbh_int));
}

// ── pdo_driver_exec: 执行无结果集 SQL ──
//   返回受影响行数（>=0），<0=err
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_exec(t_int drv_int, t_int dbh_int, const char* sql) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->exec == NULL) return -1;
    return (t_int)drv->exec(_PDO_DBH_FROM_INT(dbh_int), sql);
}

// ── pdo_driver_prepare: 预处理 SQL ──
//   返回 stmt 指针（int 形式），失败返回 0
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_prepare(t_int drv_int, t_int dbh_int, const char* sql) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->prepare == NULL) return 0;
    void* stmt = NULL;
    int rc = drv->prepare(_PDO_DBH_FROM_INT(dbh_int), sql, &stmt);
    if (rc < 0) return 0;
    return (t_int)(intptr_t)stmt;
}

// ── pdo_driver_bind_int: 绑定整数 ──
//   返回 0=ok, <0=err
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_bind_int(t_int drv_int, t_int stmt_int, t_int idx, t_int val) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->bind_int == NULL) return -1;
    return (t_int)drv->bind_int(_PDO_STMT_FROM_INT(stmt_int), (int)idx, (int64_t)val);
}

// ── pdo_driver_bind_text: 绑定字符串 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_bind_text(t_int drv_int, t_int stmt_int, t_int idx, const char* val, t_int len) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->bind_text == NULL) return -1;
    return (t_int)drv->bind_text(_PDO_STMT_FROM_INT(stmt_int), (int)idx, val, (int)len);
}

// ── pdo_driver_bind_blob: 绑定 BLOB ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_bind_blob(t_int drv_int, t_int stmt_int, t_int idx, const char* data, t_int len) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->bind_blob == NULL) return -1;
    return (t_int)drv->bind_blob(_PDO_STMT_FROM_INT(stmt_int), (int)idx, data, (int)len);
}

// ── pdo_driver_bind_null: 绑定 NULL ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_bind_null(t_int drv_int, t_int stmt_int, t_int idx) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->bind_null == NULL) return -1;
    return (t_int)drv->bind_null(_PDO_STMT_FROM_INT(stmt_int), (int)idx);
}

// ── pdo_driver_bind_param_index: 查找命名参数索引 ──
//   返回 1-based idx，0=not found
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_bind_param_index(t_int drv_int, t_int stmt_int, const char* name) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->bind_param_index == NULL) return 0;
    return (t_int)drv->bind_param_index(_PDO_STMT_FROM_INT(stmt_int), name);
}

// ── pdo_driver_step: 执行一步 ──
//   返回 PDO_STEP_ROW / PDO_STEP_DONE / <0=err
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_step(t_int drv_int, t_int stmt_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->step == NULL) return -1;
    return (t_int)drv->step(_PDO_STMT_FROM_INT(stmt_int));
}

// ── pdo_driver_reset: 重置语句 ──
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_reset(t_int drv_int, t_int stmt_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->reset == NULL) return;
    drv->reset(_PDO_STMT_FROM_INT(stmt_int));
}

// ── pdo_driver_clear_bindings: 清除绑定 ──
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_clear_bindings(t_int drv_int, t_int stmt_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->clear_bindings == NULL) return;
    drv->clear_bindings(_PDO_STMT_FROM_INT(stmt_int));
}

// ── pdo_driver_finalize: 释放语句 ──
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_finalize(t_int drv_int, t_int stmt_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->finalize == NULL) return;
    drv->finalize(_PDO_STMT_FROM_INT(stmt_int));
}

// ── pdo_driver_column_count: 列数 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_column_count(t_int drv_int, t_int stmt_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_count == NULL) return 0;
    return (t_int)drv->column_count(_PDO_STMT_FROM_INT(stmt_int));
}

// ── pdo_driver_column_type: 列类型 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_column_type(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_type == NULL) return PDO_COL_NULL;
    return (t_int)drv->column_type(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_column_int64: 列整数值 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_column_int64(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_int64 == NULL) return 0;
    return (t_int)drv->column_int64(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_column_double: 列浮点值 ──
//   注册返回类型: t_float
static inline t_float tphp_fn_pdo_driver_column_double(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_double == NULL) return 0.0;
    return drv->column_double(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_column_text: 列文本值（借用指针，下次 step 后失效）──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_column_text(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_text == NULL) return NULL;
    return drv->column_text(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_column_bytes: 列字节数 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_column_bytes(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_bytes == NULL) return 0;
    return (t_int)drv->column_bytes(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_column_name: 列名 ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_column_name(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_name == NULL) return NULL;
    return drv->column_name(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_column_decltype: 列声明类型 ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_column_decltype(t_int drv_int, t_int stmt_int, t_int col) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->column_decltype == NULL) return NULL;
    return drv->column_decltype(_PDO_STMT_FROM_INT(stmt_int), (int)col);
}

// ── pdo_driver_data_count: 当前行列数 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_data_count(t_int drv_int, t_int stmt_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->data_count == NULL) return 0;
    return (t_int)drv->data_count(_PDO_STMT_FROM_INT(stmt_int));
}

// ── pdo_driver_changes: 受影响行数 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_changes(t_int drv_int, t_int dbh_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->changes == NULL) return 0;
    return (t_int)drv->changes(_PDO_DBH_FROM_INT(dbh_int));
}

// ── pdo_driver_last_insert_rowid: 最后插入 rowid ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_last_insert_rowid(t_int drv_int, t_int dbh_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->last_insert_rowid == NULL) return 0;
    return (t_int)drv->last_insert_rowid(_PDO_DBH_FROM_INT(dbh_int));
}

// ── pdo_driver_errcode: 错误码 ──
//   注册返回类型: t_int
static inline t_int tphp_fn_pdo_driver_errcode(t_int drv_int, t_int dbh_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->errcode == NULL) return 0;
    return (t_int)drv->errcode(_PDO_DBH_FROM_INT(dbh_int));
}

// ── pdo_driver_errmsg: 错误消息 ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_errmsg(t_int drv_int, t_int dbh_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->errmsg == NULL) return "no driver";
    return drv->errmsg(_PDO_DBH_FROM_INT(dbh_int));
}

// ── pdo_driver_busy_timeout: 忙等待超时 ──
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_busy_timeout(t_int drv_int, t_int dbh_int, t_int ms) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->busy_timeout == NULL) return;
    drv->busy_timeout(_PDO_DBH_FROM_INT(dbh_int), (int)ms);
}

// ── pdo_driver_extended_result_codes: 扩展结果码 ──
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_extended_result_codes(t_int drv_int, t_int dbh_int, t_int on) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->extended_result_codes == NULL) return;
    drv->extended_result_codes(_PDO_DBH_FROM_INT(dbh_int), (int)on);
}

// ── pdo_driver_quote: 转义字符串 ──
//   返回带引号的转义字符串（深拷贝到 str_pool，driver 内部 free 原始内存）
//   注册返回类型: t_string
static inline t_string tphp_fn_pdo_driver_quote(t_int drv_int, const char* s) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->quote == NULL || drv->free_quote == NULL || s == NULL) {
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    char* quoted = drv->quote(s);
    if (quoted == NULL) {
        _pdo_driver_throw("pdo_quote: out of memory");
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    int len = (int)strlen(quoted);
    char* buf = str_pool_alloc(len);
    if (buf == NULL) {
        drv->free_quote(quoted);
        _pdo_driver_throw("pdo_quote: out of memory");
        t_string empty = {NULL, 0, false, false};
        return empty;
    }
    memcpy(buf, quoted, len);
    buf[len] = '\0';
    drv->free_quote(quoted);
    return (t_string){buf, len, false, false};
}

// ── pdo_driver_server_version: 服务器版本 ──
//   注册返回类型: const char*
static inline const char* tphp_fn_pdo_driver_server_version(t_int drv_int, t_int dbh_int) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || drv->server_version == NULL) return "unknown";
    return drv->server_version(_PDO_DBH_FROM_INT(dbh_int));
}

// ── pdo_driver_bind_params: 批量绑定参数（从 t_array* 提取 t_var 并按类型绑定）──
//   内部处理 t_var 类型分发，PHP 层只需传入 array（避免 mixed/t_var 暴露到 PHP API）
//   支持位置参数（int 键，1-based）和命名参数（string 键 ":name"）
//   注册返回类型: void
static inline void tphp_fn_pdo_driver_bind_params(t_int drv_int, t_int stmt_int, t_array* params) {
    const pdo_driver_t* drv = _PDO_DRV_FROM_INT(drv_int);
    if (drv == NULL || stmt_int == 0 || params == NULL || params->length == 0) return;
    void* stmt = _PDO_STMT_FROM_INT(stmt_int);
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
            // 命名参数：用 bind_param_index 查找
            idx = drv->bind_param_index(stmt, STR_PTR(key.value._string));
        } else {
            // 位置参数：1-based
            idx = i + 1;
        }
        if (idx == 0) continue;
        // 按 t_var 类型标签分发绑定
        switch (val.type) {
            case TYPE_NULL:
                drv->bind_null(stmt, idx);
                break;
            case TYPE_INT:
                drv->bind_int(stmt, idx, val.value._int);
                break;
            case TYPE_FLOAT:
                drv->bind_int(stmt, idx, (int64_t)val.value._float);
                break;
            case TYPE_BOOL:
                drv->bind_int(stmt, idx, val.value._bool ? 1 : 0);
                break;
            case TYPE_STRING:
                drv->bind_text(stmt, idx, STR_PTR(val.value._string),
                               val.value._string.length);
                break;
            default:
                break;
        }
    }
}
