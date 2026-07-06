// ext/libevent/src/event.c — libevent wrapper implementation
//
// Thin wrappers around libevent C API, using opaque pointers.
// Callbacks are stored as function pointers with void* arg.

#include "event.h"
#include <event2/event.h>
#include <event2/bufferevent.h>
#include <event2/listener.h>
#include <stdlib.h>
#include <string.h>

// ── EventBase ──

t_event_base tphp_fn_event_base_new(void) {
    return (t_event_base)event_base_new();
}

t_int tphp_fn_event_base_dispatch(t_event_base base) {
    if (!base) return -1;
    return event_base_dispatch((struct event_base*)base);
}

t_int tphp_fn_event_base_loop(t_event_base base, t_int flags) {
    if (!base) return -1;
    return event_base_loop((struct event_base*)base, flags);
}

t_int tphp_fn_event_base_loopbreak(t_event_base base) {
    if (!base) return -1;
    return event_base_loopbreak((struct event_base*)base);
}

void tphp_fn_event_base_free(t_event_base base) {
    if (base) event_base_free((struct event_base*)base);
}

// ── Event ──

t_event tphp_fn_event_new(t_event_base base, t_int fd, t_int events,
                           event_callback_fn callback, void* arg) {
    if (!base) return NULL;
    struct event* ev = event_new(
        (struct event_base*)base,
        (evutil_socket_t)fd,
        (short)events,
        callback,
        arg
    );
    return (t_event)ev;
}

t_int tphp_fn_event_add(t_event ev, t_int timeout_ms) {
    if (!ev) return -1;
    if (timeout_ms > 0) {
        struct timeval tv;
        tv.tv_sec = timeout_ms / 1000;
        tv.tv_usec = (timeout_ms % 1000) * 1000;
        return event_add((struct event*)ev, &tv);
    }
    return event_add((struct event*)ev, NULL);
}

t_int tphp_fn_event_del(t_event ev) {
    if (!ev) return -1;
    return event_del((struct event*)ev);
}

void tphp_fn_event_free(t_event ev) {
    if (ev) event_free((struct event*)ev);
}

t_int tphp_fn_event_pending(t_event ev, t_int events) {
    if (!ev) return 0;
    return event_pending((struct event*)ev, (short)events, NULL);
}

t_int tphp_fn_event_base_once(t_event_base base, t_int fd, t_int events,
                               event_callback_fn callback, void* arg,
                               t_int timeout_ms) {
    if (!base) return -1;
    if (timeout_ms > 0) {
        struct timeval tv;
        tv.tv_sec = timeout_ms / 1000;
        tv.tv_usec = (timeout_ms % 1000) * 1000;
        return event_base_once((struct event_base*)base,
                                (evutil_socket_t)fd,
                                (short)events,
                                callback, arg, &tv);
    }
    return event_base_once((struct event_base*)base,
                            (evutil_socket_t)fd,
                            (short)events,
                            callback, arg, NULL);
}

// ── EventBuffer ──

t_event_buffer tphp_fn_event_buffer_new(void) {
    return (t_event_buffer)evbuffer_new();
}

t_int tphp_fn_event_buffer_add(t_event_buffer buf, const char* data, t_int len) {
    if (!buf || !data || len <= 0) return -1;
    return evbuffer_add((struct evbuffer*)buf, data, (size_t)len);
}

t_int tphp_fn_event_buffer_drain(t_event_buffer buf, t_int len) {
    if (!buf || len <= 0) return -1;
    return evbuffer_drain((struct evbuffer*)buf, (size_t)len);
}

t_int tphp_fn_event_buffer_remove(t_event_buffer buf, char* data, t_int len) {
    if (!buf || !data || len <= 0) return -1;
    return evbuffer_remove((struct evbuffer*)buf, data, (size_t)len);
}

t_int tphp_fn_event_buffer_length(t_event_buffer buf) {
    if (!buf) return 0;
    return (t_int)evbuffer_get_length((struct evbuffer*)buf);
}

void tphp_fn_event_buffer_free(t_event_buffer buf) {
    if (buf) evbuffer_free((struct evbuffer*)buf);
}
