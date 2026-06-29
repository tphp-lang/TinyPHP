#include "posix.h"
#include <string.h>
#include <runtime.h>

// Helper: make t_string from C string (SSO ≤23B or pool-allocated)
static t_string _mk_str(const char *s) {
    int len = s ? (int)strlen(s) : 0;
    if (!s || !len) return (t_string){0};
    if (len <= STR_SSO_MAX) {
        t_string r = {.length = len, .is_local = true};
        memcpy(r.local, s, len); r.local[len] = 0;
        return r;
    }
    char *d = str_pool_alloc(len);
    if (!d) return (t_string){0};
    memcpy(d, s, len);
    return (t_string){.data = d, .length = len, .is_local = false};
}

#ifdef _WIN32
#define POSIX_ERR(msg) do { \
    fputs("\nFatal error: Call to undefined function " msg " (posix extension not available on Windows)\n\n", stderr); \
    exit(1); \
} while(0)

t_int tphp_fn_posix_getpid(void)   { POSIX_ERR("posix_getpid()"); return -1; }
t_int tphp_fn_posix_getppid(void)  { POSIX_ERR("posix_getppid()"); return -1; }
t_int tphp_fn_posix_getuid(void)   { POSIX_ERR("posix_getuid()"); return -1; }
t_int tphp_fn_posix_geteuid(void)  { POSIX_ERR("posix_geteuid()"); return -1; }
t_int tphp_fn_posix_getgid(void)   { POSIX_ERR("posix_getgid()"); return -1; }
t_int tphp_fn_posix_getegid(void)  { POSIX_ERR("posix_getegid()"); return -1; }
t_int tphp_fn_posix_isatty(t_int fd) { (void)fd; POSIX_ERR("posix_isatty()"); return -1; }
t_int tphp_fn_posix_kill(t_int pid, t_int sig) { (void)pid;(void)sig; POSIX_ERR("posix_kill()"); return -1; }
t_int tphp_fn_posix_get_last_error(void)   { POSIX_ERR("posix_get_last_error()"); return -1; }
t_string tphp_fn_posix_getcwd(void)   { POSIX_ERR("posix_getcwd()"); return (t_string){0}; }
t_string tphp_fn_posix_strerror(t_int n) { (void)n; POSIX_ERR("posix_strerror()"); return (t_string){0}; }
t_string tphp_fn_posix_ttyname(t_int fd) { (void)fd; POSIX_ERR("posix_ttyname()"); return (t_string){0}; }
#undef POSIX_ERR
#else
t_int tphp_fn_posix_getpid(void)   { return (t_int)getpid(); }
t_int tphp_fn_posix_getppid(void)  { return (t_int)getppid(); }
t_int tphp_fn_posix_getuid(void)   { return (t_int)getuid(); }
t_int tphp_fn_posix_geteuid(void)  { return (t_int)geteuid(); }
t_int tphp_fn_posix_getgid(void)   { return (t_int)getgid(); }
t_int tphp_fn_posix_getegid(void)  { return (t_int)getegid(); }
t_int tphp_fn_posix_isatty(t_int fd) { return isatty((int)fd) ? 1 : 0; }
t_int tphp_fn_posix_kill(t_int pid, t_int sig) { return (t_int)kill((pid_t)pid, (int)sig); }
t_int tphp_fn_posix_get_last_error(void)   { return (t_int)errno; }

t_string tphp_fn_posix_getcwd(void) {
    static char buf[4096];
    if (!getcwd(buf, sizeof(buf))) return (t_string){0};
    return _mk_str(buf);
}
t_string tphp_fn_posix_strerror(t_int no) { return _mk_str(strerror((int)no)); }
t_string tphp_fn_posix_ttyname(t_int fd) {
    char *t = ttyname((int)fd);
    return t ? _mk_str(t) : (t_string){0};
}
#endif
