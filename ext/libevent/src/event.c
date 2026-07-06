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

t_int tphp_class_Event_pending(tphp_class_Event* self, t_int events) {
    if (!self || !self->ev) return 0;
    return event_pending(self->ev, (short)(int)events, NULL);
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

t_int tphp_class_EventBuffer_drain(tphp_class_EventBuffer* self, t_int len) {
    if (!self || !self->buf || len <= 0) return -1;
    return evbuffer_drain(self->buf, (size_t)len);
}

t_string tphp_class_EventBuffer_remove(tphp_class_EventBuffer* self, t_int len) {
    if (!self || !self->buf || len <= 0) return (t_string){0};
    char* buf = (char*)malloc((size_t)len);
    if (!buf) return (t_string){0};
    int n = evbuffer_remove(self->buf, buf, (size_t)len);
    if (n <= 0) { free(buf); return (t_string){0}; }
    return (t_string){.data = buf, .length = n, .is_local = false};
}

t_int tphp_class_EventBuffer_length(tphp_class_EventBuffer* self) {
    if (!self || !self->buf) return 0;
    return (t_int)evbuffer_get_length(self->buf);
}
