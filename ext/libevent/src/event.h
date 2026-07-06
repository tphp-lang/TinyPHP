#pragma once
// ext/libevent/src/event.h — libevent C 封装函数声明
//
// 所有 C 函数使用 tphp_fn_ 前缀，PHP 侧直接调用（codegen 自动加前缀）。
// 指针通过 t_int (int64_t) 传递；字符串通过 const char* + php_str() 包装。

#include "types.h"

// Windows 头文件防护 — 必须在 event2/event.h 之前定义
#ifdef _WIN32
#ifndef WIN32_LEAN_AND_MEAN
#define WIN32_LEAN_AND_MEAN
#endif
#include <winsock2.h>
#include <windows.h>
#endif

#include <event2/event.h>
#include <event2/buffer.h>
#include <event2/util.h>

// ── EventConfig ──────────────────────────────────────────
t_int tphp_fn_event_config_new(void);
void  tphp_fn_event_config_free(t_int cfg);
t_int tphp_fn_event_config_avoid_method(t_int cfg, t_string method);
t_int tphp_fn_event_config_require_features(t_int cfg, t_int feature);
t_int tphp_fn_event_config_set_flag(t_int cfg, t_int flag);

// ── EventBase ────────────────────────────────────────────
t_int tphp_fn_event_base_new(t_int cfg);
void  tphp_fn_event_base_free(t_int base);
t_int tphp_fn_event_base_loop(t_int base, t_int flags);
t_int tphp_fn_event_base_dispatch(t_int base);
void  tphp_fn_event_base_loopbreak(t_int base);
t_int tphp_fn_event_base_loopexit(t_int base, double timeout);
t_string tphp_fn_event_base_get_method(t_int base);
t_int tphp_fn_event_base_get_features(t_int base);
t_int tphp_fn_event_base_priority_init(t_int base, t_int n);

// ── Event (Phase 2 — 回调) ───────────────────────────────
t_int tphp_fn_event_new(t_int base, t_int fd, t_int what, t_int cb_fn, t_int cb_arg);
void  tphp_fn_event_free(t_int ev);
t_int tphp_fn_event_add(t_int ev, double timeout);
t_int tphp_fn_event_del(t_int ev);

// ── EventBuffer ──────────────────────────────────────────
t_int tphp_fn_evbuffer_new(void);
void  tphp_fn_evbuffer_free(t_int buf);
t_int tphp_fn_evbuffer_add(t_int buf, t_string data);
t_string tphp_fn_evbuffer_read(t_int buf, t_int maxlen);
t_int tphp_fn_evbuffer_drain(t_int buf, t_int len);
t_int tphp_fn_evbuffer_prepend(t_int buf, t_string data);
t_int tphp_fn_evbuffer_expand(t_int buf, t_int len);
t_int tphp_fn_evbuffer_get_length(t_int buf);
t_string tphp_fn_evbuffer_readln(t_int buf, t_int eol);
