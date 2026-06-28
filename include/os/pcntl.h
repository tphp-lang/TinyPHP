#pragma once
// ============================================================
// os/pcntl.h — Process Control (POSIX only)
// Windows: stub with error message
// ============================================================

#ifdef _WIN32

#include "types.h"
void tphp_fn_error(t_string msg, const char *php_file, int php_line);
#define PCNTL_WIN_ERR(name) \
    tphp_fn_error((t_string){"pcntl_" name "(): not available on Windows", 40}, "<php>", 0)

static inline t_int tphp_fn_pcntl_fork(void)            { PCNTL_WIN_ERR("fork"); return -1; }
static inline t_int tphp_fn_pcntl_waitpid(t_int pid, t_int *st, t_int opt) { (void)pid;(void)st;(void)opt; PCNTL_WIN_ERR("waitpid"); return -1; }
static inline t_int tphp_fn_pcntl_wait(t_int *st)       { (void)st; PCNTL_WIN_ERR("wait"); return -1; }
static inline void  tphp_fn_pcntl_exec(t_string path)   { (void)path; PCNTL_WIN_ERR("exec"); }
static inline t_int tphp_fn_pcntl_alarm(t_int sec)      { (void)sec; PCNTL_WIN_ERR("alarm"); return 0; }
static inline t_int tphp_fn_pcntl_get_last_error(void)  { return 0; }
static inline t_string tphp_fn_pcntl_strerror(t_int no) {
    static char _buf[64]; int n = snprintf(_buf, 64, "errno=%lld", (long long)no);
    return (t_string){_buf, n > 0 ? n : 0};
}

#else /* POSIX */

#include <unistd.h>
#include <sys/wait.h>
#include <signal.h>
#include <errno.h>
#include <string.h>
#include "types.h"

static inline t_int tphp_fn_pcntl_fork(void) {
    pid_t pid = fork();
    return (t_int)pid;
}

static inline t_int tphp_fn_pcntl_waitpid(t_int pid, t_int *status, t_int options) {
    return (t_int)waitpid((pid_t)pid, status, (int)options);
}

static inline t_int tphp_fn_pcntl_wait(t_int *status) {
    return (t_int)wait(status);
}

static inline void tphp_fn_pcntl_exec(t_string path) {
    if (path.data == NULL || path.length == 0) return;
    char _buf[1024]; int len = path.length < 1023 ? path.length : 1023;
    memcpy(_buf, STR_PTR(path), (size_t)len); _buf[len] = '\0';
    char *argv[] = {_buf, NULL};
    execv(_buf, argv);
    // exec only returns on error
}

static inline t_int tphp_fn_pcntl_alarm(t_int sec) {
    return (t_int)alarm((unsigned int)(sec > 0 ? sec : 0));
}

static inline t_int tphp_fn_pcntl_get_last_error(void) {
    return (t_int)errno;
}

static inline t_string tphp_fn_pcntl_strerror(t_int no) {
    char *msg = strerror((int)no);
    int len = (int)strlen(msg);
    return (t_string){msg, len};
}

#endif /* POSIX */
