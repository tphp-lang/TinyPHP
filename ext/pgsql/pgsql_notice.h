#pragma once
// ============================================================
// pgsql_notice.h — PostgreSQL 通知回调实现（Task 9.7）
//
// PGconn 已有 notice_cb 字段（t_callback 类型，定义于 pgsql.h）。
// 在 NoticeResponse 消息处理中调用 _pg_invoke_notice_cb。
//
// 实现函数：
//   9.7.1  _pg_set_notice_callback — 注册通知回调
//   9.7.2  _pg_invoke_notice_cb           — 内部调用（NoticeResponse 处理中）
//
// 回调签名约定：
//   void (*)(void *msg_str, void *env)
//     msg_str: t_string* 指针（包含通知消息文本，栈分配，回调返回后失效）
//     env:     t_callback.env（闭包捕获环境）
//   参考 include/object/thread.h 的 Thread 入口函数签名 t_int (*)(void*)
//   通知回调采用双参数变体：msg_str + env，由 CodeGenerator 生成对应闭包代码
//
// 依赖：
//   - pgsql.h（结构体 + 前向声明）
//   - pgsql_result.h（_pg_mk_str_n）
//   - include/types.h（t_callback / t_string / t_int）
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
// 9.7.1 _pg_set_notice_callback — 注册通知回调
//   将 t_callback 保存到 conn->notice_cb
//   回调签名：void (*)(t_string* msg, void* env)
// ============================================================
void _pg_set_notice_callback(t_int conn_handle, t_callback callback) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_set_notice_callback: invalid connection handle");
        return;
    }
    conn->notice_cb = callback;
}

// ============================================================
// 9.7.2 _pg_invoke_notice_cb — 内部调用
//   在 NoticeResponse 处理中调用
//   如果 conn->notice_cb.func != NULL，构造 t_string 消息，调用回调
//   msg/len: 通知消息文本（从 NoticeResponse 'M' 字段提取）
//
// 闭包调用约定（与 CodeGenerator::visitClosure 一致）：
//   function(string $msg) use ($notices): void
//   => void _closure_N(t_string msg, void* _env)
//   业务参数在前（t_string 按值传递），env 在末尾。
//   参考 include/object/thread.h 的 _tphp_thread_entry（零参特化）。
// ============================================================
static void _pg_invoke_notice_cb(PGconn *conn, const char *msg, int len) {
    if (conn == NULL || conn->notice_cb.func == NULL) return;
    if (msg == NULL || len <= 0) return;

    // 构造 t_string 消息（栈分配 t_string 结构，数据走 str_pool 或 SSO）
    t_string msg_str = _pg_mk_str_n(msg, len);

    // 调用回调：void (*)(t_string msg, void* env)
    //   msg 按值传递 t_string 结构体（与闭包签名一致）
    //   env 传递闭包捕获环境
    ((void (*)(t_string, void*))conn->notice_cb.func)(msg_str,
                                                        conn->notice_cb.env);
}
