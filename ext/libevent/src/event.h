#pragma once
// ext/libevent/src/event.h — libevent C 封装函数声明
//
// 所有 C 函数按项目约定加  前缀（CodeGenerator 自动映射 PHP 调用）。
// 指针通过 t_int (int64_t) 传递；字符串通过 t_string 包装。
// 函数名加前缀避免与 libevent 系统库的同名函数冲突（redefinition 错误）。

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
t_int libevent_config_new(void);
void  libevent_config_free(t_int cfg);
t_int libevent_config_avoid_method(t_int cfg, t_string method);
t_int libevent_config_require_features(t_int cfg, t_int feature);
t_int libevent_config_set_flag(t_int cfg, t_int flag);

// ── EventBase ────────────────────────────────────────────
t_int libevent_base_new(t_int cfg);
void  libevent_base_free(t_int base);
t_int libevent_base_loop(t_int base, t_int flags);
t_int libevent_base_dispatch(t_int base);
void  libevent_base_loopbreak(t_int base);
t_int libevent_base_loopexit(t_int base, double timeout);
t_string libevent_base_get_method(t_int base);
t_int libevent_base_get_features(t_int base);
t_int libevent_base_priority_init(t_int base, t_int n);

// ── Event (Phase 2 — 回调) ───────────────────────────────
t_int libevent_new(t_int base, t_int fd, t_int what, t_int cb_fn, t_int cb_arg);
void  libevent_free(t_int ev);
t_int libevent_add(t_int ev, double timeout);
t_int libevent_del(t_int ev);

// ── EventBuffer ──────────────────────────────────────────
t_int libevbuffer_new(void);
void  libevbuffer_free(t_int buf);
t_int libevbuffer_add(t_int buf, t_string data);
t_string libevbuffer_read(t_int buf, t_int maxlen);
t_int libevbuffer_drain(t_int buf, t_int len);
t_int libevbuffer_prepend(t_int buf, t_string data);
t_int libevbuffer_expand(t_int buf, t_int len);
t_int libevbuffer_get_length(t_int buf);
t_string libevbuffer_readln(t_int buf, t_int eol);
