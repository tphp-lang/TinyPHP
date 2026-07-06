// ext/libevent/src/event.c — libevent wrapper for TinyPHP

#include "event.h"
#include <event2/event.h>
#include <event2/bufferevent.h>
#include <stdlib.h>

// ── EventBase ──

void* tphp_fn_event_base_new(void) {
    return event_base_new();
}

int tphp_fn_event_base_dispatch(void* base) {
    if (!base) return -1;
    return event_base_dispatch((struct event_base*)base);
}

int tphp_fn_event_base_loop(void* base, int flags) {
    if (!base) return -1;
    return event_base_loop((struct event_base*)base, flags);
}

int tphp_fn_event_base_loopbreak(void* base) {
    if (!base) return -1;
    return event_base_loopbreak((struct event_base*)base);
}

void tphp_fn_event_base_free(void* base) {
    if (base) event_base_free((struct event_base*)base);
}

// ── Event ──

void* tphp_fn_event_new(void* base, int fd, int events,
                         event_callback_fn callback, void* arg) {
    if (!base) return NULL;
    return event_new((struct event_base*)base, fd, (short)events,
                     callback, arg);
}

int tphp_fn_event_add(void* ev, int timeout_ms) {
    if (!ev) return -1;
    if (timeout_ms > 0) {
        struct timeval tv = { timeout_ms / 1000, (timeout_ms % 1000) * 1000 };
        return event_add((struct event*)ev, &tv);
    }
    return event_add((struct event*)ev, NULL);
}

int tphp_fn_event_del(void* ev) {
    if (!ev) return -1;
    return event_del((struct event*)ev);
}

void tphp_fn_event_free(void* ev) {
    if (ev) event_free((struct event*)ev);
}

int tphp_fn_event_pending(void* ev, int events) {
    if (!ev) return 0;
    return event_pending((struct event*)ev, (short)events, NULL);
}

// ── EventBuffer ──

void* tphp_fn_event_buffer_new(void) {
    return evbuffer_new();
}

int tphp_fn_event_buffer_add(void* buf, const char* data, int len) {
    if (!buf || !data || len <= 0) return -1;
    return evbuffer_add((struct evbuffer*)buf, data, (size_t)len);
}

int tphp_fn_event_buffer_drain(void* buf, int len) {
    if (!buf || len <= 0) return -1;
    return evbuffer_drain((struct evbuffer*)buf, (size_t)len);
}

int tphp_fn_event_buffer_remove(void* buf, char* data, int len) {
    if (!buf || !data || len <= 0) return -1;
    return evbuffer_remove((struct evbuffer*)buf, data, (size_t)len);
}

int tphp_fn_event_buffer_length(void* buf) {
    if (!buf) return 0;
    return (int)evbuffer_get_length((struct evbuffer*)buf);
}

void tphp_fn_event_buffer_free(void* buf) {
    if (buf) evbuffer_free((struct evbuffer*)buf);
}
