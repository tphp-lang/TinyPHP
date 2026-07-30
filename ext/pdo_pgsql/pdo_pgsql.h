#pragma once
// ============================================================
// pdo_pgsql.h — PostgreSQL PDO 驱动（复用 ext/pgsql 纯 C 协议实现）
//
// 设计目标：
//   - 复用 ext/pgsql 的 PGconn/PGresult 结构体与协议实现（不重复实现协议）
//   - 通过 pdo_driver_t 接口暴露给 PHP
//   - 用户通过 new PDO("pgsql:host=...;port=...;dbname=...") 使用
//   - DSN 前缀 "pgsql:" / "postgresql:" 都匹配
//   - 协议：Extended Query（Parse/Bind/Execute）+ Simple Query
//   - 参数化查询：将 PDO 的 ? 占位符转换为 PostgreSQL 的 $N 占位符
//   - 结果集：PGresult 缓存所有行，step() 顺序读取
//
// 依赖（由 .php 文件控制包含顺序）：
//   1. stream/src/stream.h  — socket 跨平台抽象（STREAM_CLOSE/STREAM_ERRNO 等宏）
//   2. pgsql/pgsql.h        — PGconn/PGresult 结构体 + 协议常量
//   3. pgsql/pg_crypto.h    — SHA-256/HMAC/PBKDF2/Base64/MD5 密码学原语
//   4. pgsql/pgsql_protocol.h — DSN 解析、消息收发、认证、连接函数实现
//   5. pgsql/pgsql_query.h   — 简单查询 + 扩展查询协议实现（_pg_exec_query 等）
//   6. pgsql/pgsql_result.h  — 结果集访问函数（_pg_mk_str 等）
//   7. pgsql/pgsql_misc.h    — 转义函数（_pg_quote_literal 等）
//   8. pgsql/pgsql_copy.h    — COPY 协议函数
//   9. pgsql/pgsql_lo.h      — Large Object 函数
//  10. pgsql/pgsql_pconnect.h — _pg_close_real（tphp_fn_pg_close 依赖）
//  11. pgsql/pgsql_notice.h   — 通知回调
//  12. pdo/pdo_driver.h      — pdo_driver_t 接口定义 + pdo_register_driver
//  13. pdo_pgsql/pdo_pgsql.h — 本文件，驱动实现
//
// 内存安全：
//   - 所有 malloc 配对 free
//   - PGresult 由 _pg_result_free 释放
//   - PGconn 由 _pg_free_conn 释放
//   - 参数值（text/blob）由 free 释放
// ============================================================

#include "types.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

// ============================================================
// pgpdo_db_t — PDO PgSQL 连接包装
//   在 PGconn* 基础上保存 PDO 层需要的瞬时状态：
//   - last_insert_oid：最近一次 INSERT 返回的 OID（PG 8.0+ 通常为 0）
//   - affected_rows：最近一次语句影响的行数
// ============================================================
typedef struct {
    PGconn*   conn;              // 底层 PG 连接（所有权归本结构）
    int64_t   last_insert_oid;   // 最近 INSERT 的 OID（PGresult.last_oid）
    int64_t   affected_rows;     // 最近语句影响行数（PGresult.affected）
    int       last_status;       // 最近 PGresult.status（PGRES_*）
} pgpdo_db_t;

// ============================================================
// pgpdo_stmt_t — PDO PgSQL 语句
//   prepare 阶段存储 SQL 模板（? 已转换为 $N），step 阶段执行并缓存 PGresult
// ============================================================
typedef struct {
    pgpdo_db_t* db;              // 所属连接（不持有所有权）
    char*       sql_template;    // SQL 模板（已转换为 $N 占位符）
    int         sql_template_len;
    int         num_params;      // 参数数量（占位符个数）
    // 参数值存储
    int*        param_types;     // 0=null, 1=int, 2=text, 3=blob
    int64_t*    param_ints;      // int 值
    char**      param_texts;     // text/blob 值指针
    int*        param_text_lens; // text/blob 长度
    // 结果集状态
    PGresult*   result;          // 当前结果集（NULL 表示未执行或已 finalize）
    int         cur_row;          // 当前行索引（PGresult 中）
    int         eof_reached;      // 1=结果集已读完
    int         executed;         // 1=已执行
    int         num_columns;      // 列数（execute 后缓存）
} pgpdo_stmt_t;

// ============================================================
// 内部辅助：错误设置
// ============================================================
static void _pgpdo_set_error(pgpdo_db_t* db, const char* msg) {
    if (db == NULL || db->conn == NULL) return;
    _pg_set_error(db->conn, msg);
}

// 从 PGresult 提取错误信息到 PGconn（PGconn->last_error 已由 _pg_receive_query_results 设置）
// 此处仅同步 db->last_status
static void _pgpdo_sync_status(pgpdo_db_t* db, PGresult* res) {
    if (db == NULL || res == NULL) return;
    db->last_status = res->status;
    db->last_insert_oid = (int64_t)res->last_oid;
    db->affected_rows = (int64_t)res->affected;
}

// ============================================================
// DSN 预处理：pgsql:host=...;port=...;dbname=... → host=... port=... dbname=...
//   1. 剥离 "pgsql:" / "postgresql:" 前缀
//   2. 将 ';' 转换为空格（libpq 风格 key=value 分隔符）
//   返回值：malloc'd 字符串（调用方 free），失败返回 NULL
// ============================================================
static char* _pgpdo_normalize_dsn(const char* dsn) {
    if (dsn == NULL) return NULL;
    const char* p = dsn;
    // 剥离前缀
    if (strncasecmp(p, "pgsql:", 6) == 0) {
        p += 6;
    } else if (strncasecmp(p, "postgresql:", 11) == 0) {
        p += 11;
    } else {
        return NULL;  // 不匹配的前缀
    }
    int len = (int)strlen(p);
    char* out = (char*)malloc((size_t)len + 1);
    if (out == NULL) return NULL;
    // ';' → ' '
    for (int i = 0; i < len; i++) {
        char c = p[i];
        out[i] = (c == ';') ? ' ' : c;
    }
    out[len] = '\0';
    return out;
}

// ============================================================
// Pdo\Pgsql 驱动常量（PDO::ATTR_* 2000+，避免与 SQLite 1000+ 冲突）
//   TinyPHP 中 PDO 基类位于全局命名空间，驱动常量统一以
//   TPHP_CONST_TPHP_CLASS_PDO_ATTR_* 命名（与 pdo.h 中的常量一致）
//
//   ATTR_DISABLE_PREPARES: 禁用服务端预处理（始终用 Simple Query 协议）
//     PostgreSQL 的 Extended Query 预处理在 pgbouncer 等连接池模式下
//     可能产生 "prepared statement already exists" 错误，此属性可禁用
//   ATTR_RESULT_MEMORY_SIZE: 结果集内存占用上限（字节，0=无限制）
//     超过此值时自动释放已缓存的行，降低内存峰值（本期仅定义常量）
// ============================================================
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_DISABLE_PREPARES    2000
#define TPHP_CONST_TPHP_CLASS_PDO_ATTR_RESULT_MEMORY_SIZE  2001

// ============================================================
// 驱动函数前向声明
// ============================================================
static int  _pdo_pgsql_open(const char* dsn, int flags, const char* user, const char* pass, void** dbh);
static void _pdo_pgsql_close(void* dbh);
static int  _pdo_pgsql_exec(void* dbh, const char* sql);
static int  _pdo_pgsql_prepare(void* dbh, const char* sql, void** stmt);
static int  _pdo_pgsql_bind_int(void* stmt, int idx, int64_t val);
static int  _pdo_pgsql_bind_text(void* stmt, int idx, const char* val, int len);
static int  _pdo_pgsql_bind_blob(void* stmt, int idx, const char* data, int len);
static int  _pdo_pgsql_bind_null(void* stmt, int idx);
static int  _pdo_pgsql_bind_param_index(void* stmt, const char* name);
static int  _pdo_pgsql_step(void* stmt);
static int  _pdo_pgsql_reset(void* stmt);
static int  _pdo_pgsql_clear_bindings(void* stmt);
static int  _pdo_pgsql_finalize(void* stmt);
static int  _pdo_pgsql_column_count(void* stmt);
static int  _pdo_pgsql_column_type(void* stmt, int col);
static int64_t _pdo_pgsql_column_int64(void* stmt, int col);
static double  _pdo_pgsql_column_double(void* stmt, int col);
static const char* _pdo_pgsql_column_text(void* stmt, int col);
static int  _pdo_pgsql_column_bytes(void* stmt, int col);
static const char* _pdo_pgsql_column_name(void* stmt, int col);
static const char* _pdo_pgsql_column_decltype(void* stmt, int col);
static int  _pdo_pgsql_data_count(void* stmt);
static int64_t _pdo_pgsql_changes(void* dbh);
static int64_t _pdo_pgsql_last_insert_rowid(void* dbh);
static int  _pdo_pgsql_errcode(void* dbh);
static const char* _pdo_pgsql_errmsg(void* dbh);
static int  _pdo_pgsql_busy_timeout(void* dbh, int ms);
static void _pdo_pgsql_extended_result_codes(void* dbh, int on);
static char* _pdo_pgsql_quote(const char* s);
static void  _pdo_pgsql_free_quote(char* s);
static const char* _pdo_pgsql_driver_name(void);
static const char* _pdo_pgsql_server_version(void* dbh);

// ============================================================
// _pdo_pgsql_open: 解析 DSN + 连接 + 认证
//
// 失败处理契约（与 _pdo_mysql_open 一致）：
//   - 失败时 *dbh = db（不 free、不 close conn），返回 -1
//   - 错误信息已写入 conn->last_error
//   - pdo_driver_open 读取 errmsg 后调用 close 释放
// ============================================================
static int _pdo_pgsql_open(const char* dsn, int flags, const char* user, const char* pass, void** dbh) {
    (void)flags;
    if (dsn == NULL || dbh == NULL) return -1;
    *dbh = NULL;

    // 1. 验证 DSN 前缀并归一化
    char* norm_dsn = _pgpdo_normalize_dsn(dsn);
    if (norm_dsn == NULL) return -1;

    // 2. 分配 PGconn
    PGconn* conn = (PGconn*)malloc(sizeof(PGconn));
    if (conn == NULL) {
        free(norm_dsn);
        tp_throw("_pdo_pgsql_open: out of memory");
        return -1;
    }
    memset(conn, 0, sizeof(*conn));
    conn->sock = -1;
    conn->status = CONN_BAD;
    conn->trans_status = TRANS_IDLE;

    // 3. 分配 pgpdo_db_t 包装
    pgpdo_db_t* db = (pgpdo_db_t*)malloc(sizeof(pgpdo_db_t));
    if (db == NULL) {
        free(norm_dsn);
        free(conn);
        tp_throw("_pdo_pgsql_open: out of memory");
        return -1;
    }
    memset(db, 0, sizeof(*db));
    db->conn = conn;
    db->last_insert_oid = 0;
    db->affected_rows = 0;
    db->last_status = PGRES_EMPTY_QUERY;

    // 4. 解析 DSN（_pg_parse_dsn 填充 conn 的 host/port/dbname/user/password 等字段）
    if (_pg_parse_dsn(norm_dsn, conn) != 0) {
        _pg_set_error(conn, "_pdo_pgsql_open: failed to parse DSN");
        free(norm_dsn);
        *dbh = db;  // 保留 db 供 driver 读取错误
        return -1;
    }
    free(norm_dsn);

    // 5. PDO 显式传入的 user/pass 覆盖 DSN 中的值
    if (user != NULL && *user != '\0') {
        if (conn->user != NULL) free(conn->user);
        conn->user = (char*)malloc(strlen(user) + 1);
        if (conn->user != NULL) strcpy(conn->user, user);
    }
    if (pass != NULL && *pass != '\0') {
        if (conn->password != NULL) free(conn->password);
        conn->password = (char*)malloc(strlen(pass) + 1);
        if (conn->password != NULL) strcpy(conn->password, pass);
    }
    // 默认 user 为当前 OS 用户名（PostgreSQL 默认行为）
    if (conn->user == NULL) {
        const char* def_user = "postgres";
        conn->user = (char*)malloc(strlen(def_user) + 1);
        if (conn->user != NULL) strcpy(conn->user, def_user);
    }
    // 默认 host
    if (conn->host == NULL) {
        conn->host = (char*)malloc(strlen("127.0.0.1") + 1);
        if (conn->host != NULL) strcpy(conn->host, "127.0.0.1");
    }

    // 6. 建立 TCP 连接
    if (_pg_connect_socket(conn) != 0) {
        *dbh = db;
        return -1;
    }

    // 7. 发送 StartupMessage
    if (_pg_send_startup(conn) != 0) {
        *dbh = db;
        return -1;
    }

    // 8. 认证
    if (_pg_do_auth(conn) != 0) {
        *dbh = db;
        return -1;
    }

    // 9. 消费启动消息（ParameterStatus / BackendKeyData / ReadyForQuery）
    if (_pg_consume_startup_messages(conn) != 0) {
        *dbh = db;
        return -1;
    }

    *dbh = db;
    return 0;
}

// ── _pdo_pgsql_close: 关闭连接 ──
static void _pdo_pgsql_close(void* dbh) {
    if (dbh == NULL) return;
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    if (db->conn != NULL) {
        // 发送 Terminate ('X') 消息并关闭 socket
        if (db->conn->sock >= 0) {
            _pg_send_message(db->conn, PG_MSG_TERMINATE, "", 0);
        }
        _pg_free_conn(db->conn);
        db->conn = NULL;
    }
    free(db);
}

// ── _pdo_pgsql_exec: 执行无结果集 SQL ──
//   返回受影响行数（>=0），<0=err
static int _pdo_pgsql_exec(void* dbh, const char* sql) {
    if (dbh == NULL || sql == NULL) return -1;
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    if (db->conn == NULL) return -1;

    PGresult* res = _pg_exec_query(db->conn, sql);
    if (res == NULL) return -1;
    if (res->status == PGRES_FATAL_ERROR) {
        _pgpdo_sync_status(db, res);
        _pg_result_free(res);
        return -1;
    }
    // 对于 SELECT，affected 字段即行数；对于 INSERT/UPDATE/DELETE，是受影响行数
    int affected = res->affected;
    _pgpdo_sync_status(db, res);
    _pg_result_free(res);
    return affected;
}

// ── _pdo_pgsql_prepare: 预处理 SQL ──
//   仅存储 SQL 模板，将 ? 转换为 $N，统计参数数量
static int _pdo_pgsql_prepare(void* dbh, const char* sql, void** stmt) {
    if (dbh == NULL || sql == NULL || stmt == NULL) return -1;
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    int sql_len = (int)strlen(sql);

    // 转换 ? 为 $N：扫描时跳过字符串字面量内的 ?
    // 输出长度估算：每个 ? 替换为 $N，N 最多 10 位（够用）
    int max_out_len = sql_len + 64;
    char* converted = (char*)malloc((size_t)max_out_len + 1);
    if (converted == NULL) {
        _pgpdo_set_error(db, "_pdo_pgsql_prepare: out of memory");
        return -1;
    }
    int num_params = 0;
    int in_single = 0, in_double = 0;
    int out_pos = 0;
    for (int i = 0; i < sql_len; i++) {
        char c = sql[i];
        if (in_single) {
            if (c == '\'') in_single = 0;
            converted[out_pos++] = c;
        } else if (in_double) {
            if (c == '"') in_double = 0;
            converted[out_pos++] = c;
        } else {
            if (c == '\'') {
                in_single = 1;
                converted[out_pos++] = c;
            } else if (c == '"') {
                in_double = 1;
                converted[out_pos++] = c;
            } else if (c == '?') {
                num_params++;
                // 生成 "$N"
                int n = snprintf(converted + out_pos, (size_t)(max_out_len - out_pos), "$%d", num_params);
                out_pos += n;
            } else {
                converted[out_pos++] = c;
            }
        }
    }
    converted[out_pos] = '\0';

    pgpdo_stmt_t* s = (pgpdo_stmt_t*)malloc(sizeof(pgpdo_stmt_t));
    if (s == NULL) {
        free(converted);
        _pgpdo_set_error(db, "_pdo_pgsql_prepare: out of memory");
        return -1;
    }
    memset(s, 0, sizeof(*s));
    s->db = db;
    s->sql_template = converted;
    s->sql_template_len = out_pos;
    s->num_params = num_params;
    s->result = NULL;
    s->cur_row = 0;
    s->eof_reached = 0;
    s->executed = 0;
    s->num_columns = 0;

    // 分配参数数组
    if (num_params > 0) {
        s->param_types = (int*)malloc(sizeof(int) * (size_t)num_params);
        s->param_ints = (int64_t*)malloc(sizeof(int64_t) * (size_t)num_params);
        s->param_texts = (char**)malloc(sizeof(char*) * (size_t)num_params);
        s->param_text_lens = (int*)malloc(sizeof(int) * (size_t)num_params);
        if (s->param_types == NULL || s->param_ints == NULL
            || s->param_texts == NULL || s->param_text_lens == NULL) {
            if (s->param_types) free(s->param_types);
            if (s->param_ints) free(s->param_ints);
            if (s->param_texts) free(s->param_texts);
            if (s->param_text_lens) free(s->param_text_lens);
            free(s->sql_template);
            free(s);
            _pgpdo_set_error(db, "_pdo_pgsql_prepare: out of memory");
            return -1;
        }
        memset(s->param_types, 0, sizeof(int) * (size_t)num_params);
        for (int i = 0; i < num_params; i++) {
            s->param_texts[i] = NULL;
            s->param_text_lens[i] = 0;
        }
    }

    *stmt = s;
    return 0;
}

// ── 绑定函数 ──
static int _pdo_pgsql_bind_int(void* stmt, int idx, int64_t val) {
    if (stmt == NULL || idx < 1) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts != NULL && s->param_texts[i] != NULL) {
        free(s->param_texts[i]);
        s->param_texts[i] = NULL;
    }
    s->param_ints[i] = val;
    s->param_types[i] = 1;
    return 0;
}

static int _pdo_pgsql_bind_text(void* stmt, int idx, const char* val, int len) {
    if (stmt == NULL || idx < 1 || val == NULL || len < 0) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts[i] != NULL) free(s->param_texts[i]);
    s->param_texts[i] = (char*)malloc((size_t)len + 1);
    if (s->param_texts[i] == NULL) {
        tp_throw("_pdo_pgsql_bind_text: out of memory");
        return -1;
    }
    memcpy(s->param_texts[i], val, (size_t)len);
    s->param_texts[i][len] = '\0';
    s->param_text_lens[i] = len;
    s->param_types[i] = 2;
    return 0;
}

static int _pdo_pgsql_bind_blob(void* stmt, int idx, const char* data, int len) {
    // PostgreSQL bytea 通过 text 协议传输时需 hex 转义，
    // 但 bind_text 直接传字节也兼容（服务器按 text 格式接收）
    // 此处与 bind_text 一致，类型标记为 blob
    if (stmt == NULL || idx < 1 || data == NULL || len < 0) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts[i] != NULL) free(s->param_texts[i]);
    s->param_texts[i] = (char*)malloc((size_t)len + 1);
    if (s->param_texts[i] == NULL) {
        tp_throw("_pdo_pgsql_bind_blob: out of memory");
        return -1;
    }
    memcpy(s->param_texts[i], data, (size_t)len);
    s->param_texts[i][len] = '\0';
    s->param_text_lens[i] = len;
    s->param_types[i] = 3;
    return 0;
}

static int _pdo_pgsql_bind_null(void* stmt, int idx) {
    if (stmt == NULL || idx < 1) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts[i] != NULL) {
        free(s->param_texts[i]);
        s->param_texts[i] = NULL;
    }
    s->param_types[i] = 0;
    return 0;
}

static int _pdo_pgsql_bind_param_index(void* stmt, const char* name) {
    // PostgreSQL PDO 驱动仅支持 ? 位置参数（已转换为 $N）
    // 命名参数 :name 不支持（与 _pdo_mysql_bind_param_index 一致）
    (void)stmt; (void)name;
    return 0;
}

// ── _pdo_pgsql_step: 执行查询并顺序读取结果集 ──
//   首次 step：构造参数数组，调用 _pg_exec_query_params，缓存 PGresult
//   后续 step：递增 cur_row
static int _pdo_pgsql_step(void* stmt) {
    if (stmt == NULL) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (s->db == NULL || s->db->conn == NULL) return -1;

    if (!s->executed) {
        s->executed = 1;
        s->eof_reached = 0;
        s->cur_row = 0;

        // 准备参数值数组（const char** + int*，符合 _pg_exec_query_params 签名）
        const char** values = NULL;
        int* lens = NULL;
        char** tmp_strs = NULL;  // int 参数转换的字符串（必须在 query 之后释放）
        int n_params = s->num_params;
        if (n_params > 0) {
            values = (const char**)malloc(sizeof(char*) * (size_t)n_params);
            lens = (int*)malloc(sizeof(int) * (size_t)n_params);
            if (values == NULL || lens == NULL) {
                if (values) free(values);
                if (lens) free(lens);
                _pgpdo_set_error(s->db, "_pdo_pgsql_step: out of memory");
                return -1;
            }
            // 为 int 参数生成字符串值（PostgreSQL text 格式接收）
            // 已转换的字符串由临时分配数组管理
            tmp_strs = (char**)calloc((size_t)n_params, sizeof(char*));
            if (tmp_strs == NULL) {
                free(values);
                free(lens);
                _pgpdo_set_error(s->db, "_pdo_pgsql_step: out of memory");
                return -1;
            }
            for (int i = 0; i < n_params; i++) {
                switch (s->param_types[i]) {
                    case 0:  // NULL
                        values[i] = NULL;
                        lens[i] = -1;
                        break;
                    case 1: {  // INT → 字符串
                        char ibuf[24];
                        int blen = snprintf(ibuf, sizeof(ibuf), "%lld", (long long)s->param_ints[i]);
                        char* dup = (char*)malloc((size_t)blen + 1);
                        if (dup == NULL) {
                            for (int j = 0; j < i; j++) if (tmp_strs[j]) free(tmp_strs[j]);
                            free(tmp_strs); free(values); free(lens);
                            _pgpdo_set_error(s->db, "_pdo_pgsql_step: out of memory");
                            return -1;
                        }
                        memcpy(dup, ibuf, (size_t)blen + 1);
                        tmp_strs[i] = dup;
                        values[i] = dup;
                        lens[i] = blen;
                        break;
                    }
                    case 2:
                    case 3:  // TEXT / BLOB
                        values[i] = s->param_texts[i];
                        lens[i] = s->param_text_lens[i];
                        break;
                }
            }
            // 注意：tmp_strs 的释放必须在 _pg_exec_query_params 之后，
            //   因为 values[i] 指向 tmp_strs[i] 的内存（int 参数转换的字符串）
        }

        // 执行扩展查询（Parse + Bind + Describe + Execute + Sync）
        PGresult* res = _pg_exec_query_params(s->db->conn, s->sql_template,
                                              values, lens, n_params);

        // 释放临时 int 字符串（query 已完成，values 中的指针不再被使用）
        if (tmp_strs != NULL) {
            for (int i = 0; i < n_params; i++) {
                if (tmp_strs[i] != NULL) free(tmp_strs[i]);
            }
            free(tmp_strs);
        }
        if (values) free(values);
        if (lens) free(lens);

        if (res == NULL) {
            return -1;
        }
        if (res->status == PGRES_FATAL_ERROR) {
            _pgpdo_sync_status(s->db, res);
            _pg_result_free(res);
            return -1;
        }
        // 同步 affected/oid
        _pgpdo_sync_status(s->db, res);
        s->result = res;
        s->num_columns = res->num_fields;

        // 判断是否有结果集
        if (res->num_fields > 0 && res->num_rows > 0) {
            // 有数据，定位到第 0 行
            s->cur_row = 0;
            return PDO_STEP_ROW;
        }
        // 无结果集（INSERT/UPDATE/DELETE 或空结果集）
        s->eof_reached = 1;
        return PDO_STEP_DONE;
    }

    // 后续 step：递增行号
    if (s->eof_reached || s->result == NULL) return PDO_STEP_DONE;
    s->cur_row++;
    if (s->cur_row >= s->result->num_rows) {
        s->eof_reached = 1;
        return PDO_STEP_DONE;
    }
    return PDO_STEP_ROW;
}

// ── _pdo_pgsql_reset: 重置语句 ──
static int _pdo_pgsql_reset(void* stmt) {
    if (stmt == NULL) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    // 释放当前结果集
    if (s->result != NULL) {
        _pg_result_free(s->result);
        s->result = NULL;
    }
    s->cur_row = 0;
    s->eof_reached = 0;
    s->executed = 0;
    s->num_columns = 0;
    return 0;
}

// ── _pdo_pgsql_clear_bindings: 清除绑定 ──
static int _pdo_pgsql_clear_bindings(void* stmt) {
    if (stmt == NULL) return -1;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    for (int i = 0; i < s->num_params; i++) {
        if (s->param_texts != NULL && s->param_texts[i] != NULL) {
            free(s->param_texts[i]);
            s->param_texts[i] = NULL;
        }
        if (s->param_text_lens != NULL) s->param_text_lens[i] = 0;
        if (s->param_types != NULL) s->param_types[i] = 0;
    }
    return 0;
}

// ── _pdo_pgsql_finalize: 释放语句 ──
static int _pdo_pgsql_finalize(void* stmt) {
    if (stmt == NULL) return 0;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    // 释放当前结果集
    if (s->result != NULL) {
        _pg_result_free(s->result);
        s->result = NULL;
    }
    // 释放 SQL 模板
    if (s->sql_template != NULL) free(s->sql_template);
    // 释放参数数组
    if (s->param_types != NULL) free(s->param_types);
    if (s->param_ints != NULL) free(s->param_ints);
    if (s->param_texts != NULL) {
        for (int i = 0; i < s->num_params; i++) {
            if (s->param_texts[i] != NULL) free(s->param_texts[i]);
        }
        free(s->param_texts);
    }
    if (s->param_text_lens != NULL) free(s->param_text_lens);
    free(s);
    return 0;
}

// ── 列信息函数 ──
static int _pdo_pgsql_column_count(void* stmt) {
    if (stmt == NULL) return 0;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    return s->num_columns;
}

// 根据 PGresult 字段类型 OID 返回 PDO 列类型
static int _pgpdo_oid_to_pdo_type(uint32_t type_oid) {
    switch (type_oid) {
        case OID_INT2:
        case OID_INT4:
        case OID_INT8:
        case OID_OID:
            return PDO_COL_INT;
        case OID_FLOAT4:
        case OID_FLOAT8:
            return PDO_COL_FLOAT;
        case OID_BYTEA:
            return PDO_COL_BLOB;
        case OID_BOOL:
            // PostgreSQL bool 在 text 协议下是 't'/'f' 字符串，归类为 TEXT
            return PDO_COL_TEXT;
        case OID_TEXT:
        case OID_VARCHAR:
        case OID_DATE:
        case OID_TIMESTAMP:
        case OID_NUMERIC:
        default:
            return PDO_COL_TEXT;
    }
}

static int _pdo_pgsql_column_type(void* stmt, int col) {
    if (stmt == NULL) return PDO_COL_NULL;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (s->result == NULL || col < 0 || col >= s->num_columns) return PDO_COL_NULL;
    // 检查当前行该列是否为 NULL
    if (s->cur_row < s->result->num_rows) {
        int idx = s->cur_row * s->result->num_fields + col;
        if (s->result->row_lens[idx] < 0) return PDO_COL_NULL;
    }
    return _pgpdo_oid_to_pdo_type(s->result->fields[col].type_oid);
}

static int64_t _pdo_pgsql_column_int64(void* stmt, int col) {
    const char* s = _pdo_pgsql_column_text(stmt, col);
    if (s == NULL) return 0;
    return (int64_t)strtoll(s, NULL, 10);
}

static double _pdo_pgsql_column_double(void* stmt, int col) {
    const char* s = _pdo_pgsql_column_text(stmt, col);
    if (s == NULL) return 0.0;
    return strtod(s, NULL);
}

static const char* _pdo_pgsql_column_text(void* stmt, int col) {
    if (stmt == NULL) return NULL;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (s->result == NULL || col < 0 || col >= s->num_columns) return NULL;
    if (s->cur_row < 0 || s->cur_row >= s->result->num_rows) return NULL;
    int idx = s->cur_row * s->result->num_fields + col;
    return s->result->rows[idx];  // NULL 表示 SQL NULL
}

static int _pdo_pgsql_column_bytes(void* stmt, int col) {
    if (stmt == NULL) return 0;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (s->result == NULL || col < 0 || col >= s->num_columns) return 0;
    if (s->cur_row < 0 || s->cur_row >= s->result->num_rows) return 0;
    int idx = s->cur_row * s->result->num_fields + col;
    int len = s->result->row_lens[idx];
    return len < 0 ? 0 : len;
}

static const char* _pdo_pgsql_column_name(void* stmt, int col) {
    if (stmt == NULL) return NULL;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (s->result == NULL || col < 0 || col >= s->num_columns) return NULL;
    return s->result->fields[col].name;
}

static const char* _pdo_pgsql_column_decltype(void* stmt, int col) {
    // PostgreSQL 协议在 RowDescription 中只提供 type_oid，不提供声明类型字符串
    // 返回 NULL（与 MySQL 驱动一致）
    (void)stmt; (void)col;
    return NULL;
}

static int _pdo_pgsql_data_count(void* stmt) {
    if (stmt == NULL) return 0;
    pgpdo_stmt_t* s = (pgpdo_stmt_t*)stmt;
    if (s->result == NULL || s->cur_row < 0 || s->cur_row >= s->result->num_rows) return 0;
    return s->result->num_fields;
}

// ── 连接信息函数 ──
static int64_t _pdo_pgsql_changes(void* dbh) {
    if (dbh == NULL) return 0;
    return ((pgpdo_db_t*)dbh)->affected_rows;
}

static int64_t _pdo_pgsql_last_insert_rowid(void* dbh) {
    if (dbh == NULL) return 0;
    // PostgreSQL 8.0+ 不再使用 OID，应通过 INSERT ... RETURNING 或 lastval() 获取
    // 此处返回 PGresult 中缓存的 last_oid（通常为 0）
    return ((pgpdo_db_t*)dbh)->last_insert_oid;
}

static int _pdo_pgsql_errcode(void* dbh) {
    if (dbh == NULL) return 0;
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    // 返回最近 PGresult.status（PGRES_FATAL_ERROR=7 表示错误）
    return db->last_status;
}

static const char* _pdo_pgsql_errmsg(void* dbh) {
    if (dbh == NULL) return "no database connection";
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    if (db->conn == NULL) return "no pg connection";
    if (db->conn->last_error != NULL) return db->conn->last_error;
    return "";
}

static int _pdo_pgsql_busy_timeout(void* dbh, int ms) {
    if (dbh == NULL) return -1;
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    if (db->conn == NULL || db->conn->sock < 0) return -1;
#ifdef _WIN32
    DWORD to_ms = (DWORD)ms;
    setsockopt((SOCKET)db->conn->sock, SOL_SOCKET, SO_RCVTIMEO, (const char*)&to_ms, sizeof(to_ms));
    setsockopt((SOCKET)db->conn->sock, SOL_SOCKET, SO_SNDTIMEO, (const char*)&to_ms, sizeof(to_ms));
#else
    struct timeval tv;
    tv.tv_sec = ms / 1000;
    tv.tv_usec = (ms % 1000) * 1000;
    setsockopt(db->conn->sock, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof(tv));
    setsockopt(db->conn->sock, SOL_SOCKET, SO_SNDTIMEO, (const char*)&tv, sizeof(tv));
#endif
    return 0;
}

static void _pdo_pgsql_extended_result_codes(void* dbh, int on) {
    // PostgreSQL 没有 SQLite 风格的扩展结果码，空操作
    (void)dbh; (void)on;
}

// ── 转义 ──
// PostgreSQL 标准 SQL 字面量转义：'value'，嵌入 ' → ''
static char* _pdo_pgsql_quote(const char* s) {
    if (s == NULL) return NULL;
    int len = (int)strlen(s);
    return _pg_quote_literal(s, len);
}

static void _pdo_pgsql_free_quote(char* s) {
    if (s != NULL) free(s);
}

// ── 驱动元信息 ──
static const char* _pdo_pgsql_driver_name(void) {
    return "pgsql";
}

static const char* _pdo_pgsql_server_version(void* dbh) {
    if (dbh == NULL) return "unknown";
    pgpdo_db_t* db = (pgpdo_db_t*)dbh;
    if (db->conn == NULL || db->conn->server_version == NULL) return "unknown";
    return db->conn->server_version;
}

// ── PostgreSQL PgSQL 驱动实例（函数指针表）──
static const pdo_driver_t pdo_pgsql_driver = {
    .name                 = "pgsql",
    .open                 = _pdo_pgsql_open,
    .close                = _pdo_pgsql_close,
    .exec                 = _pdo_pgsql_exec,
    .prepare              = _pdo_pgsql_prepare,
    .bind_int             = _pdo_pgsql_bind_int,
    .bind_text            = _pdo_pgsql_bind_text,
    .bind_blob            = _pdo_pgsql_bind_blob,
    .bind_null            = _pdo_pgsql_bind_null,
    .bind_param_index     = _pdo_pgsql_bind_param_index,
    .step                 = _pdo_pgsql_step,
    .reset                = _pdo_pgsql_reset,
    .clear_bindings       = _pdo_pgsql_clear_bindings,
    .finalize             = _pdo_pgsql_finalize,
    .column_count         = _pdo_pgsql_column_count,
    .column_type          = _pdo_pgsql_column_type,
    .column_int64         = _pdo_pgsql_column_int64,
    .column_double        = _pdo_pgsql_column_double,
    .column_text          = _pdo_pgsql_column_text,
    .column_bytes         = _pdo_pgsql_column_bytes,
    .column_name          = _pdo_pgsql_column_name,
    .column_decltype      = _pdo_pgsql_column_decltype,
    .data_count           = _pdo_pgsql_data_count,
    .changes              = _pdo_pgsql_changes,
    .last_insert_rowid    = _pdo_pgsql_last_insert_rowid,
    .errcode              = _pdo_pgsql_errcode,
    .errmsg               = _pdo_pgsql_errmsg,
    .busy_timeout         = _pdo_pgsql_busy_timeout,
    .extended_result_codes = _pdo_pgsql_extended_result_codes,
    .quote                = _pdo_pgsql_quote,
    .free_quote           = _pdo_pgsql_free_quote,
    .driver_name          = _pdo_pgsql_driver_name,
    .server_version       = _pdo_pgsql_server_version,
};

// ============================================================
// DSN 前缀匹配辅助：检查 DSN 是否以 "pgsql:" 或 "postgresql:" 开头
//   用于在 PDO::__construct 中通过 pdo_find_driver 查找驱动
//   注意：pdo_find_driver 用 strcasecmp 比对 drv->name 与 DSN 前缀
//   驱动 name="pgsql"，所以 PDO 解析 "pgsql:..." 时会找到本驱动
//   "postgresql:" 前缀需要额外处理：在 PDO::__construct 中 DSN 前缀
//   为 "postgresql" 时不会匹配 drv->name="pgsql"，因此用户必须用 "pgsql:"
//   本驱动不修改 PDO 基类，"postgresql:" 别名通过在 _pdo_pgsql_open 中
//   额外检查实现（_pgpdo_normalize_dsn 同时识别两个前缀）
// ============================================================

// ── 注册 PgSQL 驱动 ──
//   constructor + static 在部分 TCC 版本下会被死代码消除，
//   因此同时提供 constructor 和显式注册函数（PHP 层调用 tphp_fn_pdo_pgsql_init）
__attribute__((constructor))
static void _pdo_pgsql_register(void) {
    pdo_register_driver(&pdo_pgsql_driver);
}

// 显式注册（供 CodeGenerator 在 main() 入口自动调用，确保跨编译器一致）
//   返回 1 表示注册成功（或已注册），0 表示失败
static inline int tphp_fn_pdo_pgsql_init(void) {
    pdo_register_driver(&pdo_pgsql_driver);
    return 1;
}

// ============================================================
// Pdo\Pgsql 类专用的 C 包装函数
//   PHP 层通过 $this->db（dbh 指针）调用这些函数，
//   内部提取 PGconn* 后委托给 ext/pgsql 的对应函数
// ============================================================

// ── pdo_pgsql_pgconn: 从 PDO dbh 提取 PGconn 指针（int 形式）──
//   PHP 层调用 pg_* 系列函数需要 PGconn 句柄，本函数完成转换
//   返回 PGconn 指针的 int 表示（0 表示无效）
static inline t_int tphp_fn_pdo_pgsql_pgconn(t_int dbh_int) {
    if (dbh_int == 0) return 0;
    pgpdo_db_t* db = (pgpdo_db_t*)(intptr_t)dbh_int;
    if (db == NULL || db->conn == NULL) return 0;
    return (t_int)(intptr_t)db->conn;
}

// ── pdo_pgsql_get_pid: 获取后端 PID ──
//   直接读取 PGconn->backend_pid（由 BackendKeyData 消息填充）
static inline t_int tphp_fn_pdo_pgsql_get_pid(t_int dbh_int) {
    if (dbh_int == 0) return 0;
    pgpdo_db_t* db = (pgpdo_db_t*)(intptr_t)dbh_int;
    if (db == NULL || db->conn == NULL) return 0;
    return (t_int)db->conn->backend_pid;
}

// ── pdo_pgsql_get_notify: 获取异步通知 ──
//   返回 t_array*，包含 [pid, channel, message] 三元素；无通知返回空数组
//   注意：当前 ext/pgsql 的 _pg_receive_query_results 未缓存 NotificationResponse('A') 消息
//   因此本实现返回空数组。完整 LISTEN/NOTIFY 支持需要修改协议接收循环
//   后续可扩展：在 pgpdo_db_t 中添加 notify 缓存，hook 到消息接收循环
static inline t_array* tphp_fn_pdo_pgsql_get_notify(t_int dbh_int, t_int result_type, t_int ms_timeout) {
    (void)dbh_int; (void)result_type; (void)ms_timeout;
    // 当前实现：返回空数组（LISTEN/NOTIFY 支持需要协议层修改，超出本任务范围）
    t_array* arr = tphp_fn_arr_create(0);
    if (arr != NULL) {
        tphp_rt_register((void*)arr, 1);
    }
    return arr;
}
