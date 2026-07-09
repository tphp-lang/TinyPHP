#pragma once
// ext/pcntl — Process Control (POSIX only)
#include "types.h"
#ifndef _WIN32
#include <unistd.h>
#include <sys/wait.h>
#include <signal.h>
#endif

t_int tphp_fn_pcntl_fork(void);
t_int tphp_fn_pcntl_waitpid(t_int pid, t_int *status, t_int options);
t_int tphp_fn_pcntl_wait(t_int *status);
void  tphp_fn_pcntl_exec(t_string path);
t_int tphp_fn_pcntl_alarm(t_int sec);
t_int tphp_fn_pcntl_get_last_error(void);
t_string tphp_fn_pcntl_strerror(t_int no);
