#pragma once
// ext/libevent — libevent object wrapper for TinyPHP
//
// Uses tphp_class_ object pattern (same as Generator).
// C structs embed t_object + libevent pointer.
// PHP classes call tphp_class_* methods via object dispatch.

#include "types.h"
#include <event2/event.h>

// ── Constants ──
#define EV_TIMEOUT   0x01
#define EV_READ      0x02
#define EV_WRITE     0x04
#define EV_SIGNAL    0x08
#define EV_PERSIST   0x10
#define EV_ET        0x20
#define EVLOOP_ONCE      0x01
#define EVLOOP_NONBLOCK  0x02

// ── Callback type ──
typedef void (*event_callback_fn)(int fd, short events, void* arg);

// ── EventBase ──
typedef struct {
    t_object _obj;
    struct event_base* base;
} tphp_class_EventBase;

void tphp_class_EventBase___construct(tphp_class_EventBase* self);
void tphp_class_EventBase___destruct(tphp_class_EventBase* self);
t_int tphp_class_EventBase_dispatch(tphp_class_EventBase* self);
t_int tphp_class_EventBase_loop(tphp_class_EventBase* self, t_int flags);
t_int tphp_class_EventBase_loopBreak(tphp_class_EventBase* self);

// ── Event ──
typedef struct {
    t_object _obj;
    struct event* ev;
} tphp_class_Event;

void tphp_class_Event___construct(tphp_class_Event* self, tphp_class_EventBase* base,
                                   t_int fd, t_int events, t_callback callback);
void tphp_class_Event___destruct(tphp_class_Event* self);
t_int tphp_class_Event_add(tphp_class_Event* self, t_int timeout_ms);
t_int tphp_class_Event_del(tphp_class_Event* self);
t_int tphp_class_Event_pending(tphp_class_Event* self, t_int events);

// ── EventBuffer ──
typedef struct {
    t_object _obj;
    struct evbuffer* buf;
} tphp_class_EventBuffer;

void tphp_class_EventBuffer___construct(tphp_class_EventBuffer* self);
void tphp_class_EventBuffer___destruct(tphp_class_EventBuffer* self);
t_int tphp_class_EventBuffer_add(tphp_class_EventBuffer* self, t_string data);
t_int tphp_class_EventBuffer_drain(tphp_class_EventBuffer* self, t_int len);
t_string tphp_class_EventBuffer_remove(tphp_class_EventBuffer* self, t_int len);
t_int tphp_class_EventBuffer_length(tphp_class_EventBuffer* self);
