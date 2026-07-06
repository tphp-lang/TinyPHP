#pragma once
// ext/libevent — libevent object wrapper for TinyPHP
//
// tphp_class_ object pattern, matching PHP libevent extension API.

#include "types.h"
#include <event2/event.h>
#include <event2/bufferevent.h>

// ── Constants ──
#define EV_TIMEOUT   0x01
#define EV_READ      0x02
#define EV_WRITE     0x04
#define EV_SIGNAL    0x08
#define EV_PERSIST   0x10
#define EV_ET        0x20
#define EVLOOP_ONCE      0x01
#define EVLOOP_NONBLOCK  0x02
#define EVLOOP_NONBLOCK  0x02

// ── Callback type ──
typedef void (*event_callback_fn)(int fd, short events, void* arg);

// ============================================================
// EventBase
// ============================================================
typedef struct {
    t_object _obj;
    struct event_base* base;
} tphp_class_EventBase;

void  tphp_class_EventBase___construct(tphp_class_EventBase* self);
void  tphp_class_EventBase___destruct(tphp_class_EventBase* self);
t_int tphp_class_EventBase_dispatch(tphp_class_EventBase* self);
t_int tphp_class_EventBase_loop(tphp_class_EventBase* self, t_int flags);
t_int tphp_class_EventBase_loopBreak(tphp_class_EventBase* self);
t_int tphp_class_EventBase_loopContinue(tphp_class_EventBase* self);
t_int tphp_class_EventBase_stop(tphp_class_EventBase* self);
t_int tphp_class_EventBase_free(tphp_class_EventBase* self);
t_string tphp_class_EventBase_getMethod(tphp_class_EventBase* self);

// ============================================================
// Event
// ============================================================
typedef struct {
    t_object _obj;
    struct event* ev;
} tphp_class_Event;

void  tphp_class_Event___construct(tphp_class_Event* self, tphp_class_EventBase* base,
                                    t_int fd, t_int events, t_callback callback);
void  tphp_class_Event___destruct(tphp_class_Event* self);
t_int tphp_class_Event_add(tphp_class_Event* self, t_int timeout_ms);
t_int tphp_class_Event_del(tphp_class_Event* self);
void  tphp_class_Event_free(tphp_class_Event* self);
t_int tphp_class_Event_pending(tphp_class_Event* self, t_int events);
void  tphp_class_Event_set(tphp_class_Event* self, tphp_class_EventBase* base,
                           t_int fd, t_int events, t_callback callback);
t_int tphp_class_Event_setPriority(tphp_class_Event* self, t_int priority);
t_int tphp_class_Event_getPendingEvents(tphp_class_Event* self);

// ============================================================
// EventTimer
// ============================================================
typedef struct {
    t_object _obj;
    struct event* ev;
} tphp_class_EventTimer;

void  tphp_class_EventTimer___construct(tphp_class_EventTimer* self,
                                         tphp_class_EventBase* base,
                                         t_callback callback);
void  tphp_class_EventTimer___destruct(tphp_class_EventTimer* self);
t_int tphp_class_EventTimer_add(tphp_class_EventTimer* self, t_int timeout_ms);
t_int tphp_class_EventTimer_del(tphp_class_EventTimer* self);

// ============================================================
// EventSignal
// ============================================================
typedef struct {
    t_object _obj;
    struct event* ev;
} tphp_class_EventSignal;

void  tphp_class_EventSignal___construct(tphp_class_EventSignal* self,
                                          tphp_class_EventBase* base,
                                          t_int signum, t_callback callback);
void  tphp_class_EventSignal___destruct(tphp_class_EventSignal* self);
t_int tphp_class_EventSignal_add(tphp_class_EventSignal* self);
t_int tphp_class_EventSignal_del(tphp_class_EventSignal* self);

// ============================================================
// EventBuffer
// ============================================================
typedef struct {
    t_object _obj;
    struct evbuffer* buf;
} tphp_class_EventBuffer;

void     tphp_class_EventBuffer___construct(tphp_class_EventBuffer* self);
void     tphp_class_EventBuffer___destruct(tphp_class_EventBuffer* self);
t_int    tphp_class_EventBuffer_add(tphp_class_EventBuffer* self, t_string data);
t_int    tphp_class_EventBuffer_addBuffer(tphp_class_EventBuffer* self, tphp_class_EventBuffer* src);
t_int    tphp_class_EventBuffer_drain(tphp_class_EventBuffer* self, t_int len);
t_int    tphp_class_EventBuffer_remove(tphp_class_EventBuffer* self, t_string buf, t_int len);
t_int    tphp_class_EventBuffer_length(tphp_class_EventBuffer* self);
t_int    tphp_class_EventBuffer_prepend(tphp_class_EventBuffer* self, t_string data);
t_string tphp_class_EventBuffer_readLine(tphp_class_EventBuffer* self, t_int eolStyle);
t_string tphp_class_EventBuffer_pullup(tphp_class_EventBuffer* self, t_int len);

// ============================================================
// EventBufferEvent
// ============================================================
typedef struct {
    t_object _obj;
    struct bufferevent* bev;
} tphp_class_EventBufferEvent;

void  tphp_class_EventBufferEvent___construct(tphp_class_EventBufferEvent* self,
                                               tphp_class_EventBase* base, t_int fd,
                                               t_int events);
void  tphp_class_EventBufferEvent___destruct(tphp_class_EventBufferEvent* self);
t_int tphp_class_EventBufferEvent_enable(tphp_class_EventBufferEvent* self, t_int events);
t_int tphp_class_EventBufferEvent_disable(tphp_class_EventBufferEvent* self, t_int events);
void  tphp_class_EventBufferEvent_free(tphp_class_EventBufferEvent* self);
void  tphp_class_EventBufferEvent_setCallbacks(tphp_class_EventBufferEvent* self,
                                                t_callback readCb, t_callback writeCb,
                                                t_callback eventCb);
t_int tphp_class_EventBufferEvent_write(tphp_class_EventBufferEvent* self, t_string data);
t_int tphp_class_EventBufferEvent_writeBuffer(tphp_class_EventBufferEvent* self,
                                               tphp_class_EventBuffer* buf);
void* tphp_class_EventBufferEvent_getInput(tphp_class_EventBufferEvent* self);
void* tphp_class_EventBufferEvent_getOutput(tphp_class_EventBufferEvent* self);
t_int tphp_class_EventBufferEvent_setTimeouts(tphp_class_EventBufferEvent* self,
                                               t_int readMs, t_int writeMs);
