#pragma once
// ============================================================
// os/posix.h — POSIX 系统函数 (select subset, AOT-friendly)
// Windows: crash via tphp_fn_error()
// ============================================================

#ifdef _WIN32

#include "types.h"
void tphp_fn_error(t_string msg, const char *php_file, int php_line);
#define POSIX_WIN_ERR(name) \
    tphp_fn_error((t_string){"posix_" name "(): not available on Windows", 40}, "<php>", 0)

static inline t_int tphp_fn_posix_getpid(void)      { POSIX_WIN_ERR("getpid"); return -1; }
static inline t_int tphp_fn_posix_getppid(void)     { POSIX_WIN_ERR("getppid"); return -1; }
static inline t_int tphp_fn_posix_getuid(void)      { POSIX_WIN_ERR("getuid"); return -1; }
static inline t_int tphp_fn_posix_geteuid(void)     { POSIX_WIN_ERR("geteuid"); return -1; }
static inline t_int tphp_fn_posix_getgid(void)      { POSIX_WIN_ERR("getgid"); return -1; }
static inline t_int tphp_fn_posix_getegid(void)     { POSIX_WIN_ERR("getegid"); return -1; }
static inline t_string tphp_fn_posix_getcwd(void)   { POSIX_WIN_ERR("getcwd"); return (t_string){NULL,0}; }
static inline t_int tphp_fn_posix_isatty(t_int fd)  { (void)fd; return 0; }
static inline t_int tphp_fn_posix_kill(t_int pid, t_int sig) { (void)pid;(void)sig; POSIX_WIN_ERR("kill"); return -1; }
static inline t_string tphp_fn_posix_strerror(t_int no) {
    static char _b[64]; int n = snprintf(_b, 64, "errno=%lld", (long long)no);
    return (t_string){_b, n > 0 ? n : 0};
}
static inline t_int tphp_fn_posix_get_last_error(void)  { return 0; }
static inline t_int tphp_fn_posix_ttyname(t_int fd)     { (void)fd; POSIX_WIN_ERR("ttyname"); return -1; }

// uname → returns array, stub with empty
static inline t_array* tphp_fn_posix_uname(void) {
    t_array* a = tphp_fn_arr_create(5);
    if (a) tphp_rt_register((void*)a, 1);
    return a;
}

// times → returns array with dummy values
static inline t_array* tphp_fn_posix_times(void) {
    t_array* a = tphp_fn_arr_create(5);
    if (a) tphp_rt_register((void*)a, 1);
    return a;
}

#else /* POSIX */

#include <unistd.h>
#include <sys/types.h>
#include <sys/times.h>
#include <sys/utsname.h>
#include <errno.h>
#include <string.h>
#include <signal.h>
#include "types.h"

// ── Process identity (1-line wrappers) ────────────────────
static inline t_int tphp_fn_posix_getpid(void)   { return (t_int)getpid(); }
static inline t_int tphp_fn_posix_getppid(void)  { return (t_int)getppid(); }
static inline t_int tphp_fn_posix_getuid(void)   { return (t_int)getuid(); }
static inline t_int tphp_fn_posix_geteuid(void)  { return (t_int)geteuid(); }
static inline t_int tphp_fn_posix_getgid(void)   { return (t_int)getgid(); }
static inline t_int tphp_fn_posix_getegid(void)  { return (t_int)getegid(); }

// ── getcwd ────────────────────────────────────────────────
static inline t_string tphp_fn_posix_getcwd(void) {
    static char _buf[4096];
    if (getcwd(_buf, sizeof(_buf)) == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = (int)strlen(_buf);
    return (t_string){_buf, len};
}

// ── isatty ────────────────────────────────────────────────
static inline t_int tphp_fn_posix_isatty(t_int fd) { return isatty((int)fd) ? 1 : 0; }

// ── kill ──────────────────────────────────────────────────
static inline t_int tphp_fn_posix_kill(t_int pid, t_int sig) { return (t_int)kill((pid_t)pid, (int)sig); }

// ── strerror / errno ──────────────────────────────────────
static inline t_string tphp_fn_posix_strerror(t_int no) {
    char *msg = strerror((int)no);
    int len = (int)strlen(msg);
    return (t_string){msg, len};
}
static inline t_int tphp_fn_posix_get_last_error(void) { return (t_int)errno; }

// ── ttyname ───────────────────────────────────────────────
static inline t_string tphp_fn_posix_ttyname(t_int fd) {
    char *t = ttyname((int)fd);
    if (t == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = (int)strlen(t);
    return (t_string){t, len};
}

// ── uname → array[sysname,nodename,release,version,machine] ──
static inline t_array* tphp_fn_posix_uname(void) {
    t_array* a = tphp_fn_arr_create(5);
    if (a == NULL) return NULL;
    tphp_rt_register((void*)a, 1);
    struct utsname u;
    if (uname(&u) < 0) return a;
    t_string _sys  = {u.sysname,  (int)strlen(u.sysname)};
    t_string _node = {u.nodename, (int)strlen(u.nodename)};
    t_string _rel  = {u.release,  (int)strlen(u.release)};
    t_string _ver  = {u.version,  (int)strlen(u.version)};
    t_string _mach = {u.machine,  (int)strlen(u.machine)};
    a = tphp_fn_arr_set_str(a, (t_string){"sysname",7},  VAR_STRING(_sys));
    a = tphp_fn_arr_set_str(a, (t_string){"nodename",8}, VAR_STRING(_node));
    a = tphp_fn_arr_set_str(a, (t_string){"release",7},  VAR_STRING(_rel));
    a = tphp_fn_arr_set_str(a, (t_string){"version",7},  VAR_STRING(_ver));
    a = tphp_fn_arr_set_str(a, (t_string){"machine",7},  VAR_STRING(_mach));
    return a;
}

// ── times → array[ticks,utime,stime,cutime,cstime] ────────
static inline t_array* tphp_fn_posix_times(void) {
    t_array* a = tphp_fn_arr_create(5);
    if (a == NULL) return NULL;
    tphp_rt_register((void*)a, 1);
    struct tms t;
    clock_t ct = times(&t);
    a = tphp_fn_arr_set_str(a, (t_string){"ticks",5},  VAR_INT((t_int)ct));
    a = tphp_fn_arr_set_str(a, (t_string){"utime",5},  VAR_INT((t_int)t.tms_utime));
    a = tphp_fn_arr_set_str(a, (t_string){"stime",5},  VAR_INT((t_int)t.tms_stime));
    a = tphp_fn_arr_set_str(a, (t_string){"cutime",6}, VAR_INT((t_int)t.tms_cutime));
    a = tphp_fn_arr_set_str(a, (t_string){"cstime",6}, VAR_INT((t_int)t.tms_cstime));
    return a;
}

#endif /* POSIX */
