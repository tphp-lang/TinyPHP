#include "pcntl.h"
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
#define PCNTL_ERR(msg) do { \
    fputs("\nFatal error: Call to undefined function " msg " (pcntl extension not available on Windows)\n\n", stderr); \
    exit(1); \
} while(0)

t_int tphp_fn_pcntl_fork(void)       { PCNTL_ERR("pcntl_fork()"); return -1; }
t_int tphp_fn_pcntl_waitpid(t_int pid, t_int *status, t_int options) { (void)pid;(void)status;(void)options; PCNTL_ERR("pcntl_waitpid()"); return -1; }
t_int tphp_fn_pcntl_wait(t_int *status) { (void)status; PCNTL_ERR("pcntl_wait()"); return -1; }
void  tphp_fn_pcntl_exec(t_string path) { (void)path; PCNTL_ERR("pcntl_exec()"); }
t_int tphp_fn_pcntl_alarm(t_int sec)    { (void)sec; PCNTL_ERR("pcntl_alarm()"); return -1; }
t_int tphp_fn_pcntl_get_last_error(void) { PCNTL_ERR("pcntl_get_last_error()"); return -1; }
t_string tphp_fn_pcntl_strerror(t_int no) { (void)no; PCNTL_ERR("pcntl_strerror()"); return (t_string){0}; }
#undef PCNTL_ERR
#else
t_int tphp_fn_pcntl_fork(void)       { pid_t p = fork(); return (t_int)p; }
t_int tphp_fn_pcntl_waitpid(t_int pid, t_int *status, t_int options) { return (t_int)waitpid((pid_t)pid, (int*)status, (int)options); }
t_int tphp_fn_pcntl_wait(t_int *status) { return (t_int)wait((int*)status); }
void  tphp_fn_pcntl_exec(t_string path) { const char *p = STR_PTR(path); if (!p || !*p) return; char *argv[] = {(char*)p, NULL}; execv(p, argv); }
t_int tphp_fn_pcntl_alarm(t_int sec)    { return (t_int)alarm((unsigned)(sec > 0 ? sec : 0)); }
t_int tphp_fn_pcntl_get_last_error(void) { return (t_int)errno; }
t_string tphp_fn_pcntl_strerror(t_int no) { return _mk_str(strerror((int)no)); }
#endif
