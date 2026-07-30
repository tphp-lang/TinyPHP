#pragma once
// ============================================================
// pgsql_pconnect.h — PostgreSQL 持久连接池实现（Task 9.6）
//
// 参考 vlang Pool+IdleSlot 模式：
//   - 进程内全局连接池（最多 PG_PCONN_POOL_SIZE=32 个条目）
//   - pg_pconnect 按 DSN 哈希查找空闲连接复用
//   - pg_close 对持久连接仅归还池（in_use=0），不真正关闭
//   - PGSQL_CLOSE_FORCE 标志强制关闭并从池移除
//
// 实现函数：
//   9.6.1  _pg_dsn_hash         — FNV-1a 64-bit 哈希
//   9.6.2  _pg_pconnect_real    — pg_pconnect 实现（查找池 / 新建连接）
//   9.6.3  _pg_close_real       — pg_close 实现（持久连接归还池 / 非持久直接关闭）
//   9.6.4  _pg_pconn_pool_cleanup — 连接池清理（runtime shutdown 调用）
//
// 依赖：
//   - pgsql.h（结构体 + 常量 + 前向声明）
//   - pgsql_protocol.h（_pg_connect / _pg_send_message / _pg_free_conn）
//   - include/types.h（t_int / t_string / t_bool）
//
// 注意：
//   _pg_pconnect / _pg_close（在 pgsql_protocol.h 中）
//   委托调用本文件的 _pg_pconnect_real / _pg_close_real
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

// ============================================================
// 持久连接池（进程内全局）
// ============================================================

// 连接池条目（使用 pgsql.h 中定义的 pg_pconn_slot 结构体）
static pg_pconn_slot _pg_pconn_pool[PG_PCONN_POOL_SIZE];
static int _pg_pconn_count = 0;

// ============================================================
// 9.6.1 FNV-1a 64-bit 哈希
//   用于快速比较 DSN 是否相同（避免每次 strcmp）
// ============================================================
static uint64_t _pg_dsn_hash(const char *dsn) {
    if (dsn == NULL) return 0;
    uint64_t hash = 14695981039346656037ULL;  // FNV-1a 64-bit offset basis
    while (*dsn) {
        hash ^= (uint64_t)(unsigned char)*dsn;
        hash *= 1099511628211ULL;  // FNV-1a 64-bit prime
        dsn++;
    }
    return hash;
}

// ============================================================
// 9.6.2 _pg_pconnect_real — 持久连接实现
//   1. 计算 DSN 哈希
//   2. 查池（in_use=0 且 dsn_hash 匹配），命中则复用
//   3. 未命中：调 _pg_connect 建立新连接，加入池
//   flags: PGSQL_CONNECT_FORCE_NEW=2 强制新建连接
// ============================================================
static t_int _pg_pconnect_real(t_string dsn, t_int flags) {
    const char *dsn_str = STR_PTR(dsn);
    if (dsn_str == NULL || dsn.length == 0) {
        tp_throw("pg_pconnect: empty connection string");
        return 0;
    }

    uint64_t hash = _pg_dsn_hash(dsn_str);
    int force_new = ((flags & 2) != 0);  // PGSQL_CONNECT_FORCE_NEW = 2

    // 查池：查找 dsn_hash 匹配的连接（单进程模型中，同一 DSN 的 pconnect 返回同一连接）
    if (!force_new) {
        for (int i = 0; i < _pg_pconn_count; i++) {
            if (_pg_pconn_pool[i].conn != NULL &&
                _pg_pconn_pool[i].dsn_hash == hash) {
                // 复用此连接（与 PHP 行为一致：同 DSN 的 pconnect 返回同一连接）
                _pg_pconn_pool[i].in_use = 1;
                _pg_pconn_pool[i].conn->is_persistent = 1;
                return _PG_CONN_TO_INT(_pg_pconn_pool[i].conn);
            }
        }
    }

    // 未命中 — 建立新连接
    t_int conn_handle = _pg_connect(dsn);
    if (conn_handle == 0) return 0;  // pg_connect 已 tp_throw

    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    conn->is_persistent = 1;

    // 加入连接池（如果池未满）
    if (_pg_pconn_count < PG_PCONN_POOL_SIZE) {
        int idx = _pg_pconn_count++;
        _pg_pconn_pool[idx].conn = conn;
        _pg_pconn_pool[idx].dsn = (char*)malloc((size_t)dsn.length + 1);
        if (_pg_pconn_pool[idx].dsn != NULL) {
            memcpy(_pg_pconn_pool[idx].dsn, dsn_str, (size_t)dsn.length);
            _pg_pconn_pool[idx].dsn[dsn.length] = '\0';
        }
        _pg_pconn_pool[idx].dsn_hash = hash;
        _pg_pconn_pool[idx].in_use = 1;
    }
    // 池满时不加入池，但连接仍可用（只是不会被复用）

    return conn_handle;
}

// ============================================================
// 9.6.3 _pg_close_real — 关闭连接实现
//   如果 conn->is_persistent 且非 PGSQL_CLOSE_FORCE：
//     仅设 in_use=0（归还池），不关闭
//   否则：真正关闭（发送 Terminate + _pg_free_conn），从池移除
//   close_flags: PGSQL_CLOSE_FORCE=1 强制关闭
// ============================================================
static void _pg_close_real(PGconn *conn, t_int close_flags) {
    if (conn == NULL) return;

    int force_close = ((close_flags & 1) != 0);  // PGSQL_CLOSE_FORCE = 1

    // 持久连接且非强制关闭 — 归还连接池
    if (conn->is_persistent && !force_close) {
        for (int i = 0; i < _pg_pconn_count; i++) {
            if (_pg_pconn_pool[i].conn == conn) {
                _pg_pconn_pool[i].in_use = 0;
                return;  // 不关闭 socket，不释放 conn
            }
        }
        // 未在池中找到（池满时创建的连接）— 走真正关闭路径
    }

    // 非持久连接或强制关闭 — 从池中移除（如果在池中）
    for (int i = 0; i < _pg_pconn_count; i++) {
        if (_pg_pconn_pool[i].conn == conn) {
            if (_pg_pconn_pool[i].dsn != NULL) {
                free(_pg_pconn_pool[i].dsn);
                _pg_pconn_pool[i].dsn = NULL;
            }
            _pg_pconn_pool[i].conn = NULL;
            _pg_pconn_pool[i].dsn_hash = 0;
            _pg_pconn_pool[i].in_use = 0;
            break;
        }
    }

    // 发送 Terminate 消息并释放连接
    if (conn->sock >= 0) {
        _pg_send_message(conn, PG_MSG_TERMINATE, "", 0);
    }
    _pg_free_conn(conn);
}

// ============================================================
// 9.6.4 _pg_pconn_pool_cleanup — 连接池清理
//   在 runtime shutdown 时调用，关闭所有池中连接
// ============================================================
static void _pg_pconn_pool_cleanup(void) {
    for (int i = 0; i < _pg_pconn_count; i++) {
        if (_pg_pconn_pool[i].conn != NULL) {
            PGconn *conn = _pg_pconn_pool[i].conn;
            if (conn->sock >= 0) {
                _pg_send_message(conn, PG_MSG_TERMINATE, "", 0);
            }
            _pg_free_conn(conn);
            _pg_pconn_pool[i].conn = NULL;
        }
        if (_pg_pconn_pool[i].dsn != NULL) {
            free(_pg_pconn_pool[i].dsn);
            _pg_pconn_pool[i].dsn = NULL;
        }
        _pg_pconn_pool[i].dsn_hash = 0;
        _pg_pconn_pool[i].in_use = 0;
    }
    _pg_pconn_count = 0;
}
