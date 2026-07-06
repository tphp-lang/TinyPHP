#pragma once
// ext/libevent — libevent wrapper for TinyPHP
//
// Wraps libevent C API as tphp_fn_ prefixed functions.
// PHP side calls these directly via C->call() or class methods.

#include "types.h"

// ── Constants ──
#define EV_TIMEOUT   0x01
#define EV_READ      0x02
#define EV_WRITE     0x04
#define EV_SIGNAL    0x08
#define EV_PERSIST   0x10
#define EV_ET        0x20

#define EVLOOP_ONCE      0x01
#define EVLOOP_NONBLOCK  0x02

// ── Opaque pointer types ──
typedef void* t_event_base;
typedef void* t_event;
typedef void* t_event_buffer;

// ── EventBase functions ──
t_event_base tphp_fn_event_base_new(void);
t_int        tphp_fn_event_base_dispatch(t_event_base base);
t_int        tphp_fn_event_base_loop(t_event_base base, t_int flags);
t_int        tphp_fn_event_base_loopbreak(t_event_base base);
void         tphp_fn_event_base_free(t_event_base base);

// ── Event functions ──
// callback signature: void callback(t_int fd, t_int events, void* arg)
typedef void (*event_callback_fn)(t_int fd, t_int events, void* arg);

t_event tphp_fn_event_new(t_event_base base, t_int fd, t_int events,
                           event_callback_fn callback, void* arg);
t_int   tphp_fn_event_add(t_event ev, t_int timeout_ms);
t_int   tphp_fn_event_del(t_event ev);
void    tphp_fn_event_free(t_event ev);
t_int   tphp_fn_event_pending(t_event ev, t_int events);
t_int   tphp_fn_event_base_once(t_event_base base, t_int fd, t_int events,
                                 event_callback_fn callback, void* arg,
                                 t_int timeout_ms);

// ── EventBuffer functions ──
t_event_buffer tphp_fn_event_buffer_new(void);
t_int          tphp_fn_event_buffer_add(t_event_buffer buf, const char* data, t_int len);
t_int          tphp_fn_event_buffer_drain(t_event_buffer buf, t_int len);
t_int          tphp_fn_event_buffer_remove(t_event_buffer buf, char* data, t_int len);
t_int          tphp_fn_event_buffer_length(t_event_buffer buf);
void           tphp_fn_event_buffer_free(t_event_buffer buf);
