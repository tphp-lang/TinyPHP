#pragma once
// ext/libevent — libevent wrapper for TinyPHP
//
// All C functions use tphp_fn_ prefix, PHP calls them directly.
// Opaque pointers hide libevent internals.

#include "types.h"

#define EV_TIMEOUT   0x01
#define EV_READ      0x02
#define EV_WRITE     0x04
#define EV_SIGNAL    0x08
#define EV_PERSIST   0x10
#define EV_ET        0x20
#define EVLOOP_ONCE      0x01
#define EVLOOP_NONBLOCK  0x02

// Callback: void fn(int fd, int events, void* arg)
typedef void (*event_callback_fn)(int fd, int events, void* arg);

// EventBase
void*  tphp_fn_event_base_new(void);
int    tphp_fn_event_base_dispatch(void* base);
int    tphp_fn_event_base_loop(void* base, int flags);
int    tphp_fn_event_base_loopbreak(void* base);
void   tphp_fn_event_base_free(void* base);

// Event
void*  tphp_fn_event_new(void* base, int fd, int events,
                          event_callback_fn callback, void* arg);
int    tphp_fn_event_add(void* ev, int timeout_ms);
int    tphp_fn_event_del(void* ev);
void   tphp_fn_event_free(void* ev);
int    tphp_fn_event_pending(void* ev, int events);

// EventBuffer
void*  tphp_fn_event_buffer_new(void);
int    tphp_fn_event_buffer_add(void* buf, const char* data, int len);
int    tphp_fn_event_buffer_drain(void* buf, int len);
int    tphp_fn_event_buffer_remove(void* buf, char* data, int len);
int    tphp_fn_event_buffer_length(void* buf);
void   tphp_fn_event_buffer_free(void* buf);
