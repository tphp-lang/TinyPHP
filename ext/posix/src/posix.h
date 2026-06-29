#pragma once
// ext/posix — POSIX system functions
#include "types.h"
#ifndef _WIN32
#include <unistd.h>
#include <sys/types.h>
#include <sys/times.h>
#include <sys/utsname.h>
#include <signal.h>
#endif

t_int tphp_fn_posix_getpid(void);
t_int tphp_fn_posix_getppid(void);
t_int tphp_fn_posix_getuid(void);
t_int tphp_fn_posix_geteuid(void);
t_int tphp_fn_posix_getgid(void);
t_int tphp_fn_posix_getegid(void);
t_int tphp_fn_posix_isatty(t_int fd);
t_int tphp_fn_posix_kill(t_int pid, t_int sig);
t_int tphp_fn_posix_get_last_error(void);

t_string tphp_fn_posix_getcwd(void);
t_string tphp_fn_posix_strerror(t_int no);
t_string tphp_fn_posix_ttyname(t_int fd);
