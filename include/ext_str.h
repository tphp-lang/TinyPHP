#ifndef TPHP_EXT_STR_H
#define TPHP_EXT_STR_H

/*
 * Common string helpers for extensions.
 * Included via #include "ext_str.h" after runtime.h/types.h are available.
 * All functions are static inline to allow use in separate TUs without link conflicts.
 */

#include <string.h>

/* Create t_string from C string (SSO ≤23B inline, otherwise pool-allocated) */
static inline t_string ext_mk_str(const char *s) {
    int len = s ? (int)strlen(s) : 0;
    if (!s || !len) return (t_string){0};
    if (len <= STR_SSO_MAX) {
        t_string r = {.is_local = true, .length = len};
        memcpy(r.local, s, len);
        r.local[len] = 0;
        return r;
    }
    char *d = str_pool_alloc(len);
    if (!d) return (t_string){0};
    memcpy(d, s, len);
    return (t_string){.data = d, .length = len, .is_local = false};
}

/* Create t_string from substring [start, end) (SSO ≤23B inline, otherwise pool-allocated) */
static inline t_string ext_mk_substr(const char *src, int start, int end) {
    int len = end - start;
    if (len <= 0 || !src) return (t_string){0};
    if (len <= STR_SSO_MAX) {
        t_string r = {.is_local = true, .length = len};
        memcpy(r.local, src + start, len);
        r.local[len] = 0;
        return r;
    }
    char *d = str_pool_alloc(len);
    if (!d) return (t_string){0};
    memcpy(d, src + start, len);
    return (t_string){.data = d, .length = len, .is_local = false};
}

#endif /* TPHP_EXT_STR_H */
