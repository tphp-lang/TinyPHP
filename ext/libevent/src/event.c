// ext/libevent/src/event.c — libevent object implementation

#include "event.h"
#include <event2/event.h>
#include <event2/bufferevent.h>
#include <stdlib.h>
#include <string.h>

// ============================================================
// EventBase
// ============================================================

void tphp_class_EventBase___construct(tphp_class_EventBase* self) {
    if (!self) return;
    self->base = event_base_new();
}

void tphp_class_EventBase___destruct(tphp_class_EventBase* self) {
    if (self && self->base) {
        event_base_free(self->base);
        self->base = NULL;
    }
}

t_int tphp_class_EventBase_dispatch(tphp_class_EventBase* self) {
    if (!self || !self->base) return -1;
    return event_base_dispatch(self->base);
}

t_int tphp_class_EventBase_loop(tphp_class_EventBase* self, t_int flags) {
    if (!self || !self->base) return -1;
    return event_base_loop(self->base, (int)flags);
}

t_int tphp_class_EventBase_loopBreak(tphp_class_EventBase* self) {
    if (!self || !self->base) return -1;
    return event_base_loopbreak(self->base);
}

t_int tphp_class_EventBase_loopContinue(tphp_class_EventBase* self) {
    if (!self || !self->base) return -1;
    return event_base_loopcontinue(self->base);
}

t_int tphp_class_EventBase_stop(tphp_class_EventBase* self) {
    if (!self || !self->base) return -1;
    return event_base_loopbreak(self->base);
}

t_int tphp_class_EventBase_free(tphp_class_EventBase* self) {
    if (!self || !self->base) return -1;
    event_base_free(self->base);
    self->base = NULL;
    return 0;
}

t_string tphp_class_EventBase_getMethod(tphp_class_EventBase* self) {
    if (!self || !self->base) return (t_string){0};
    const char* method = event_base_get_method(self->base);
    if (!method) return (t_string){0};
    int len = (int)strlen(method);
    if (len <= 23) {
        t_string r = {.is_local = true, .length = len};
        memcpy(r.local, method, len);
        r.local[len] = 0;
        return r;
    }
    char* d = str_pool_alloc(len);
    if (!d) return (t_string){0};
    memcpy(d, method, len);
    return (t_string){.data = d, .length = len, .is_local = false};
}

// ============================================================
// Event
// ============================================================

void tphp_class_Event___construct(tphp_class_Event* self, tphp_class_EventBase* base,
                                   t_int fd, t_int events, t_callback callback) {
    if (!self || !base || !base->base) return;
    self->ev = event_new(base->base, (int)fd, (short)(int)events,
                         (event_callback_fn)callback.func, callback.env);
}

void tphp_class_Event___destruct(tphp_class_Event* self) {
    if (self && self->ev) {
        event_free(self->ev);
        self->ev = NULL;
    }
}

t_int tphp_class_Event_add(tphp_class_Event* self, t_int timeout_ms) {
    if (!self || !self->ev) return -1;
    if (timeout_ms > 0) {
        struct timeval tv = { (long)(timeout_ms / 1000), (long)((timeout_ms % 1000) * 1000) };
        return event_add(self->ev, &tv);
    }
    return event_add(self->ev, NULL);
}

t_int tphp_class_Event_del(tphp_class_Event* self) {
    if (!self || !self->ev) return -1;
    return event_del(self->ev);
}

void tphp_class_Event_free(tphp_class_Event* self) {
    if (self && self->ev) {
        event_free(self->ev);
        self->ev = NULL;
    }
}

t_int tphp_class_Event_pending(tphp_class_Event* self, t_int events) {
    if (!self || !self->ev) return 0;
    return event_pending(self->ev, (short)(int)events, NULL);
}

void tphp_class_Event_set(tphp_class_Event* self, tphp_class_EventBase* base,
                           t_int fd, t_int events, t_callback callback) {
    if (!self || !base || !base->base) return;
    if (self->ev) event_free(self->ev);
    self->ev = event_new(base->base, (int)fd, (short)(int)events,
                         (event_callback_fn)callback.func, callback.env);
}

t_int tphp_class_Event_setPriority(tphp_class_Event* self, t_int priority) {
    if (!self || !self->ev) return -1;
    return event_priority_set(self->ev, (int)priority);
}

t_int tphp_class_Event_getPendingEvents(tphp_class_Event* self) {
    if (!self || !self->ev) return 0;
    return event_pending(self->ev, EV_READ|EV_WRITE|EV_SIGNAL|EV_TIMEOUT, NULL);
}

// ============================================================
// EventTimer
// ============================================================

static void _timer_callback(evutil_socket_t fd, short events, void* arg) {
    (void)fd; (void)events;
    t_callback* cb = (t_callback*)arg;
    if (cb && cb->func) {
        ((void(*)(void*))cb->func)(cb->env);
    }
}

void tphp_class_EventTimer___construct(tphp_class_EventTimer* self,
                                       tphp_class_EventBase* base,
                                       t_callback callback) {
    if (!self || !base || !base->base) return;
    t_callback* cb = (t_callback*)malloc(sizeof(t_callback));
    if (cb) { cb->func = callback.func; cb->env = callback.env; }
    self->ev = event_new(base->base, -1, EV_TIMEOUT, _timer_callback, cb);
}

void tphp_class_EventTimer___destruct(tphp_class_EventTimer* self) {
    if (self && self->ev) {
        void* arg = event_get_arg(self->ev);
        if (arg) free(arg);
        event_free(self->ev);
        self->ev = NULL;
    }
}

t_int tphp_class_EventTimer_add(tphp_class_EventTimer* self, t_int timeout_ms) {
    if (!self || !self->ev) return -1;
    struct timeval tv = { (long)(timeout_ms / 1000), (long)((timeout_ms % 1000) * 1000) };
    return event_add(self->ev, &tv);
}

t_int tphp_class_EventTimer_del(tphp_class_EventTimer* self) {
    if (!self || !self->ev) return -1;
    return event_del(self->ev);
}

// ============================================================
// EventSignal
// ============================================================

static void _signal_callback(evutil_socket_t fd, short events, void* arg) {
    (void)fd; (void)events;
    t_callback* cb = (t_callback*)arg;
    if (cb && cb->func) {
        ((void(*)(void*))cb->func)(cb->env);
    }
}

void tphp_class_EventSignal___construct(tphp_class_EventSignal* self,
                                        tphp_class_EventBase* base,
                                        t_int signum, t_callback callback) {
    if (!self || !base || !base->base) return;
    t_callback* cb = (t_callback*)malloc(sizeof(t_callback));
    if (cb) { cb->func = callback.func; cb->env = callback.env; }
    self->ev = event_new(base->base, (int)signum, EV_SIGNAL|EV_PERSIST,
                         _signal_callback, cb);
}

void tphp_class_EventSignal___destruct(tphp_class_EventSignal* self) {
    if (self && self->ev) {
        void* arg = event_get_arg(self->ev);
        if (arg) free(arg);
        event_free(self->ev);
        self->ev = NULL;
    }
}

t_int tphp_class_EventSignal_add(tphp_class_EventSignal* self) {
    if (!self || !self->ev) return -1;
    return event_add(self->ev, NULL);
}

t_int tphp_class_EventSignal_del(tphp_class_EventSignal* self) {
    if (!self || !self->ev) return -1;
    return event_del(self->ev);
}

// ============================================================
// EventBuffer
// ============================================================

void tphp_class_EventBuffer___construct(tphp_class_EventBuffer* self) {
    if (!self) return;
    self->buf = evbuffer_new();
}

void tphp_class_EventBuffer___destruct(tphp_class_EventBuffer* self) {
    if (self && self->buf) {
        evbuffer_free(self->buf);
        self->buf = NULL;
    }
}

t_int tphp_class_EventBuffer_add(tphp_class_EventBuffer* self, t_string data) {
    if (!self || !self->buf) return -1;
    return evbuffer_add(self->buf, STR_PTR(data), (size_t)data.length);
}

t_int tphp_class_EventBuffer_addBuffer(tphp_class_EventBuffer* self, tphp_class_EventBuffer* src) {
    if (!self || !self->buf || !src || !src->buf) return -1;
    return evbuffer_add_buffer(self->buf, src->buf);
}

t_int tphp_class_EventBuffer_drain(tphp_class_EventBuffer* self, t_int len) {
    if (!self || !self->buf || len <= 0) return -1;
    return evbuffer_drain(self->buf, (size_t)len);
}

t_int tphp_class_EventBuffer_remove(tphp_class_EventBuffer* self, t_string buf, t_int len) {
    if (!self || !self->buf || len <= 0) return -1;
    return evbuffer_remove(self->buf, (void*)STR_PTR(buf), (size_t)len);
}

t_int tphp_class_EventBuffer_length(tphp_class_EventBuffer* self) {
    if (!self || !self->buf) return 0;
    return (t_int)evbuffer_get_length(self->buf);
}

t_int tphp_class_EventBuffer_prepend(tphp_class_EventBuffer* self, t_string data) {
    if (!self || !self->buf) return -1;
    return evbuffer_prepend(self->buf, STR_PTR(data), (size_t)data.length);
}

t_string tphp_class_EventBuffer_readLine(tphp_class_EventBuffer* self, t_int eolStyle) {
    if (!self || !self->buf) return (t_string){0};
    struct evbuffer_iovec vec;
    int eol = (eolStyle == 0) ? EV_EOL_LF : EV_EOL_CRLF;
    int n = evbuffer_readline_to_iovec(self->buf, &vec, eol);
    if (n <= 0) return (t_string){0};
    if (n <= 23) {
        t_string r = {.is_local = true, .length = n};
        memcpy(r.local, vec.iov_base, n);
        r.local[n] = 0;
        return r;
    }
    char* d = str_pool_alloc(n);
    if (!d) return (t_string){0};
    memcpy(d, vec.iov_base, n);
    return (t_string){.data = d, .length = n, .is_local = false};
}

t_string tphp_class_EventBuffer_pullup(tphp_class_EventBuffer* self, t_int len) {
    if (!self || !self->buf) return (t_string){0};
    char* data = evbuffer_pullup(self->buf, len);
    size_t total = evbuffer_get_length(self->buf);
    if (!data || total == 0) return (t_string){0};
    int n = (len > 0 && (size_t)len < total) ? (int)len : (int)total;
    if (n <= 23) {
        t_string r = {.is_local = true, .length = n};
        memcpy(r.local, data, n);
        r.local[n] = 0;
        return r;
    }
    char* d = str_pool_alloc(n);
    if (!d) return (t_string){0};
    memcpy(d, data, n);
    return (t_string){.data = d, .length = n, .is_local = false};
}

// ============================================================
// EventBufferEvent
// ============================================================

void tphp_class_EventBufferEvent___construct(tphp_class_EventBufferEvent* self,
                                             tphp_class_EventBase* base, t_int fd,
                                             t_int events) {
    if (!self || !base || !base->base) return;
    self->bev = bufferevent_socket_new(base->base, (int)fd,
                                       (unsigned)events, NULL);
}

void tphp_class_EventBufferEvent___destruct(tphp_class_EventBufferEvent* self) {
    if (self && self->bev) {
        bufferevent_free(self->bev);
        self->bev = NULL;
    }
}

t_int tphp_class_EventBufferEvent_enable(tphp_class_EventBufferEvent* self, t_int events) {
    if (!self || !self->bev) return -1;
    return bufferevent_enable(self->bev, (unsigned)events);
}

t_int tphp_class_EventBufferEvent_disable(tphp_class_EventBufferEvent* self, t_int events) {
    if (!self || !self->bev) return -1;
    return bufferevent_disable(self->bev, (unsigned)events);
}

void tphp_class_EventBufferEvent_free(tphp_class_EventBufferEvent* self) {
    if (self && self->bev) {
        bufferevent_free(self->bev);
        self->bev = NULL;
    }
}

void tphp_class_EventBufferEvent_setCallbacks(tphp_class_EventBufferEvent* self,
                                              t_callback readCb, t_callback writeCb,
                                              t_callback eventCb) {
    if (!self || !self->bev) return;
    bufferevent_setcb(self->bev,
                      (bufferevent_data_cb)readCb.func,
                      (bufferevent_data_cb)writeCb.func,
                      (bufferevent_event_cb)eventCb.func,
                      readCb.env);
}

t_int tphp_class_EventBufferEvent_write(tphp_class_EventBufferEvent* self, t_string data) {
    if (!self || !self->bev) return -1;
    return bufferevent_write(self->bev, STR_PTR(data), (size_t)data.length);
}

t_int tphp_class_EventBufferEvent_writeBuffer(tphp_class_EventBufferEvent* self,
                                               tphp_class_EventBuffer* buf) {
    if (!self || !self->bev || !buf || !buf->buf) return -1;
    return bufferevent_write_buffer(self->bev, buf->buf);
}

void* tphp_class_EventBufferEvent_getInput(tphp_class_EventBufferEvent* self) {
    if (!self || !self->bev) return NULL;
    return bufferevent_get_input(self->bev);
}

void* tphp_class_EventBufferEvent_getOutput(tphp_class_EventBufferEvent* self) {
    if (!self || !self->bev) return NULL;
    return bufferevent_get_output(self->bev);
}

t_int tphp_class_EventBufferEvent_setTimeouts(tphp_class_EventBufferEvent* self,
                                               t_int readMs, t_int writeMs) {
    if (!self || !self->bev) return -1;
    struct timeval tv_read = {0}, tv_write = {0};
    if (readMs > 0) {
        tv_read.tv_sec = readMs / 1000;
        tv_read.tv_usec = (readMs % 1000) * 1000;
    }
    if (writeMs > 0) {
        tv_write.tv_sec = writeMs / 1000;
        tv_write.tv_usec = (writeMs % 1000) * 1000;
    }
    return bufferevent_set_timeouts(self->bev,
                                     readMs > 0 ? &tv_read : NULL,
                                     writeMs > 0 ? &tv_write : NULL);
}
