#include "event.h"
#include <string.h>
#include <stdlib.h>
#include <runtime.h>

// ── Helpers ──────────────────────────────────────────────

// 将 C 字符串转为 t_string（SSO ≤23B 或池分配）
static t_string _mk_str(const char *s) {
    int len = s ? (int)strlen(s) : 0;
    if (!s || !len) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (len <= STR_SSO_MAX) {
        t_string r = {.length = len, .is_local = true};
        memcpy(r.local, s, len);
        r.local[len] = 0;
        return r;
    }
    char *d = str_pool_alloc(len);
    if (!d) return (t_string){.data = NULL, .length = 0, .is_local = false};
    memcpy(d, s, len);
    return (t_string){.data = d, .length = len, .is_local = false};
}

// 将秒数转为 struct timeval
static struct timeval _timeval_from_double(double timeout) {
    struct timeval tv;
    if (timeout < 0) { tv.tv_sec = 0; tv.tv_usec = 0; return tv; }
    tv.tv_sec  = (long)timeout;
    tv.tv_usec = (long)((timeout - (double)tv.tv_sec) * 1000000.0);
    return tv;
}

// 指针转换宏
#define TO_PTR(v, t)  ((t)(uintptr_t)(v))
#define FROM_PTR(p)   ((t_int)(intptr_t)(p))

// ── EventConfig ──────────────────────────────────────────

t_int libevent_config_new(void) {
    struct event_config *cfg = event_config_new();
    return FROM_PTR(cfg);
}

void libevent_config_free(t_int cfg) {
    if (!cfg) return;
    event_config_free(TO_PTR(cfg, struct event_config *));
}

t_int libevent_config_avoid_method(t_int cfg, t_string method) {
    if (!cfg) return -1;
    return (t_int)event_config_avoid_method(TO_PTR(cfg, struct event_config *), STR_PTR(method));
}

t_int libevent_config_require_features(t_int cfg, t_int feature) {
    if (!cfg) return -1;
    return (t_int)event_config_require_features(TO_PTR(cfg, struct event_config *), (int)feature);
}

t_int libevent_config_set_flag(t_int cfg, t_int flag) {
    if (!cfg) return -1;
    return (t_int)event_config_set_flag(TO_PTR(cfg, struct event_config *), (int)flag);
}

// ── EventBase ────────────────────────────────────────────

#ifdef _WIN32
// Windows: libevent 内部 evsig_init_ 需要 WSAStartup，否则会输出
// "[warn] evsig_init_: socketpair: WSAStartup 失败" 到 stderr。
// 在首次创建 event_base 时初始化 Winsock，整个进程只需一次。
static int _winsock_init(void) {
    static int initialized = 0;
    if (!initialized) {
        WSADATA wsa;
        WSAStartup(MAKEWORD(2, 2), &wsa);
        initialized = 1;
    }
    return 0;
}
#endif

t_int libevent_base_new(t_int cfg) {
#ifdef _WIN32
    _winsock_init();
#endif
    struct event_base *base;
    if (cfg) {
        base = event_base_new_with_config(TO_PTR(cfg, struct event_config *));
    } else {
        base = event_base_new();
    }
    return FROM_PTR(base);
}

void libevent_base_free(t_int base) {
    if (!base) return;
    event_base_free(TO_PTR(base, struct event_base *));
}

t_int libevent_base_loop(t_int base, t_int flags) {
    if (!base) return -1;
    return (t_int)event_base_loop(TO_PTR(base, struct event_base *), (int)flags);
}

t_int libevent_base_dispatch(t_int base) {
    if (!base) return -1;
    return (t_int)event_base_dispatch(TO_PTR(base, struct event_base *));
}

void libevent_base_loopbreak(t_int base) {
    if (!base) return;
    event_base_loopbreak(TO_PTR(base, struct event_base *));
}

t_int libevent_base_loopexit(t_int base, double timeout) {
    if (!base) return -1;
    if (timeout <= 0) {
        event_base_loopbreak(TO_PTR(base, struct event_base *));
        return 0;
    }
    struct timeval tv = _timeval_from_double(timeout);
    return (t_int)event_base_loopexit(TO_PTR(base, struct event_base *), &tv);
}

t_string libevent_base_get_method(t_int base) {
    if (!base) return (t_string){.data = NULL, .length = 0, .is_local = false};
    return _mk_str(event_base_get_method(TO_PTR(base, struct event_base *)));
}

t_int libevent_base_get_features(t_int base) {
    if (!base) return -1;
    return (t_int)event_base_get_features(TO_PTR(base, struct event_base *));
}

t_int libevent_base_priority_init(t_int base, t_int n) {
    if (!base) return -1;
    return (t_int)event_base_priority_init(TO_PTR(base, struct event_base *), (int)n);
}

// ── Event (Phase 2 — 回调) ───────────────────────────────

t_int libevent_new(t_int base, t_int fd, t_int what, t_int cb_fn, t_int cb_arg) {
    if (!base || !cb_fn) return 0;
    struct event *ev = event_new(
        TO_PTR(base, struct event_base *),
        (evutil_socket_t)fd,
        (short)what,
        (event_callback_fn)(uintptr_t)cb_fn,
        (void*)(uintptr_t)cb_arg
    );
    return FROM_PTR(ev);
}

void libevent_free(t_int ev) {
    if (!ev) return;
    event_free(TO_PTR(ev, struct event *));
}

t_int libevent_add(t_int ev, double timeout) {
    if (!ev) return -1;
    if (timeout < 0) {
        return (t_int)event_add(TO_PTR(ev, struct event *), NULL);
    }
    struct timeval tv = _timeval_from_double(timeout);
    return (t_int)event_add(TO_PTR(ev, struct event *), &tv);
}

t_int libevent_del(t_int ev) {
    if (!ev) return -1;
    return (t_int)event_del(TO_PTR(ev, struct event *));
}

// ── EventBuffer ──────────────────────────────────────────

t_int libevbuffer_new(void) {
    struct evbuffer *buf = evbuffer_new();
    return FROM_PTR(buf);
}

void libevbuffer_free(t_int buf) {
    if (!buf) return;
    evbuffer_free(TO_PTR(buf, struct evbuffer *));
}

t_int libevbuffer_add(t_int buf, t_string data) {
    if (!buf) return -1;
    const char *p = STR_PTR(data);
    int len = data.length;
    if (!p || len <= 0) return 0;
    return (t_int)evbuffer_add(TO_PTR(buf, struct evbuffer *), p, (size_t)len);
}

t_string libevbuffer_read(t_int buf, t_int maxlen) {
    if (!buf || maxlen <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = (int)maxlen;
    char *tmp = (char*)malloc(len + 1);
    if (!tmp) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int n = (int)evbuffer_remove(TO_PTR(buf, struct evbuffer *), tmp, (size_t)len);
    if (n <= 0) { free(tmp); return (t_string){.data = NULL, .length = 0, .is_local = false}; }
    tmp[n] = 0;
    t_string r = _mk_str(tmp);
    free(tmp);
    return r;
}

t_int libevbuffer_drain(t_int buf, t_int len) {
    if (!buf) return -1;
    return (t_int)evbuffer_drain(TO_PTR(buf, struct evbuffer *), (size_t)len);
}

t_int libevbuffer_prepend(t_int buf, t_string data) {
    if (!buf) return -1;
    const char *p = STR_PTR(data);
    int len = data.length;
    if (!p || len <= 0) return 0;
    return (t_int)evbuffer_prepend(TO_PTR(buf, struct evbuffer *), p, (size_t)len);
}

t_int libevbuffer_expand(t_int buf, t_int len) {
    if (!buf) return -1;
    return (t_int)evbuffer_expand(TO_PTR(buf, struct evbuffer *), (size_t)len);
}

t_int libevbuffer_get_length(t_int buf) {
    if (!buf) return 0;
    return (t_int)evbuffer_get_length(TO_PTR(buf, struct evbuffer *));
}

t_string libevbuffer_readln(t_int buf, t_int eol) {
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    size_t n_read = 0;
    char *line = evbuffer_readln(TO_PTR(buf, struct evbuffer *), &n_read, (enum evbuffer_eol_style)eol);
    if (!line || n_read == 0) {
        if (line) free(line);
        return (t_string){.data = NULL, .length = 0, .is_local = false};
    }
    t_string r = _mk_str(line);
    free(line);
    return r;
}
