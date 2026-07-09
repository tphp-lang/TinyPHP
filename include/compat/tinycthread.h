#pragma once
// ============================================================
// tinycthread.h — 优化的跨平台线程库（TinyPHP 定制版）
//
// 基于 tinycthread v1.1 (Marcus Geelnard, zlib license)
// 优化内容：
//   1. Windows 非递归 mutex: CRITICAL_SECTION → SRWLOCK（指针大小，更轻量）
//   2. Windows condvar: 2 Event + CS 模拟 → CONDITION_VARIABLE（Vista+ 原生）
//   3. 移除 Sleep(1000) 死锁检测（SRWLOCK 原生非递归）
//   4. 修复 cnd_broadcast POSIX bug（pthread_cond_signal → pthread_cond_broadcast）
//   5. 实现 thrd_detach（原为 FIXME）
//   6. 新增 SpinLock（TCC 兼容 CAS + 指数退避）
//   7. 新增 WaitGroup（mutex 保护，无线程安全问题）
//
// License: zlib (见下方原始声明)
// ============================================================

/* -*- mode: c; tab-width: 2; indent-tabs-mode: nil; -*- */
/* Copyright (c) 2012 Marcus Geelnard                                   */
/*                                                                      */
/* This software is provided 'as-is', without any express or implied    */
/* warranty. In no event will the authors be held liable for any damages*/
/* arising from the use of this software.                               */
/*                                                                      */
/* Permission is granted to anyone to use this software for any purpose,*/
/* including commercial applications, and to alter it and redistribute  */
/* it freely, subject to the following restrictions:                    */
/*                                                                      */
/*  1. The origin of this software must not be misrepresented; you must */
/*     not claim that you wrote the original software. If you use this  */
/*     software in a product, an acknowledgment in the product          */
/*     documentation would be appreciated but is not required.          */
/*                                                                      */
/*  2. Altered source versions must be plainly marked as such, and must*/
/*     not be misrepresented as being the original software.            */
/*                                                                      */
/*  3. This notice may not be removed or altered from any source        */
/*     distribution.                                                    */
/* ====================================================================*/

#ifndef _TINYCTHREAD_OPTIMIZED_H_
#define _TINYCTHREAD_OPTIMIZED_H_

/* ── 平台检测（统一用 _WIN32，与 TinyPHP 现有规范一致） ── */
#if !defined(_TTHREAD_PLATFORM_DEFINED_)
  #if defined(_WIN32)
    #define _TTHREAD_WIN32_
  #else
    #define _TTHREAD_POSIX_
  #endif
  #define _TTHREAD_PLATFORM_DEFINED_
#endif

/* ── POSIX 功能激活 ── */
#if defined(_TTHREAD_POSIX_)
  #if !defined(_GNU_SOURCE)
    #define _GNU_SOURCE
  #endif
  #if !defined(_POSIX_C_SOURCE) || ((_POSIX_C_SOURCE - 0) < 199309L)
    #undef _POSIX_C_SOURCE
    #define _POSIX_C_SOURCE 199309L
  #endif
#endif

/* ── 通用 includes ── */
#include <time.h>
#include <stdlib.h>   /* malloc / free */

/* ── 线程退出清理钩子 ──
 * 由 runtime 在 #include 前定义，用于释放子线程的 thread-local 内存池。
 * 独立使用 tinycthread（如 test_tinycthread.c）时为空操作。 */
#ifndef TPHP_THREAD_CLEANUP
  #define TPHP_THREAD_CLEANUP() ((void)0)
#endif

#if defined(_TTHREAD_POSIX_)
  #include <pthread.h>
  #include <signal.h>
  #include <sched.h>
  #include <unistd.h>
  /* TCC 在 Linux/macOS 自带的 errno.h 引用链不完整
   * (bits/errno.h → linux/errno.h 找不到)。
   * tinycthread 仅需 ETIMEDOUT（pthread_cond_timedwait 超时返回值），
   * 手动定义以避免引入 TCC 损坏的 errno.h。 */
  #if defined(__TINYC__)
    #ifndef ETIMEDOUT
      #ifdef __linux__
        #define ETIMEDOUT 110
      #else
        #define ETIMEDOUT 60
      #endif
    #endif
  #else
    #include <errno.h>
  #endif
#elif defined(_TTHREAD_WIN32_)
  #ifndef WIN32_LEAN_AND_MEAN
    #define WIN32_LEAN_AND_MEAN
  #endif
  #include <windows.h>
  #include <process.h>   /* _beginthreadex */
  /* TCC 的 windows.h 缺少 SRWLOCK / CONDITION_VARIABLE — 手动补充。
   * GCC/Clang (MSYS2) 的 windows.h 已包含完整定义，跳过以避免冲突。 */
  #if defined(__TINYC__) && !defined(_TTHREAD_HAVE_SRWLOCK)
    typedef struct _TTHREAD_SRWLOCK {
      PVOID Ptr;
    } SRWLOCK, *PSRWLOCK;
    #define SRWLOCK_INIT {0}
    WINBASEAPI void    WINAPI InitializeSRWLock(PSRWLOCK Lock);
    WINBASEAPI void    WINAPI AcquireSRWLockExclusive(PSRWLOCK Lock);
    WINBASEAPI void    WINAPI ReleaseSRWLockExclusive(PSRWLOCK Lock);
    WINBASEAPI BOOLEAN WINAPI TryAcquireSRWLockExclusive(PSRWLOCK Lock);
  #endif
  #if defined(__TINYC__) && !defined(_TTHREAD_HAVE_CONDVAR)
    typedef struct _TTHREAD_CONDITION_VARIABLE {
      PVOID Ptr;
    } CONDITION_VARIABLE, *PCONDITION_VARIABLE;
    WINBASEAPI void WINAPI InitializeConditionVariable(PCONDITION_VARIABLE Cond);
    WINBASEAPI void WINAPI WakeConditionVariable(PCONDITION_VARIABLE Cond);
    WINBASEAPI void WINAPI WakeAllConditionVariable(PCONDITION_VARIABLE Cond);
    WINBASEAPI BOOL  WINAPI SleepConditionVariableSRW(PCONDITION_VARIABLE Cond, PSRWLOCK Lock, DWORD ms, ULONG flags);
    WINBASEAPI BOOL  WINAPI SleepConditionVariableCS(PCONDITION_VARIABLE Cond, LPCRITICAL_SECTION Lock, DWORD ms);
  #endif
#endif

/* ── timespec / clock_gettime 模拟（Windows） ── */
#if defined(_TTHREAD_WIN32_) && !defined(_TTHREAD_HAVE_TIMESPEC)
  /* TCC/GCC on Windows: struct timespec 可能不可用 */
  #if !defined(_TIMESPEC_DEFINED) && !defined(__struct_timespec_defined)
    struct _tthread_timespec {
      time_t tv_sec;
      long   tv_nsec;
    };
    #define timespec _tthread_timespec
  #endif
  #define _TTHREAD_EMULATE_CLOCK_GETTIME_
  typedef int _tthread_clockid_t;
  #define clockid_t _tthread_clockid_t
  static inline int _tthread_clock_gettime(clockid_t clk_id, struct timespec *ts) {
    (void)clk_id;
    FILETIME ft;
    ULARGE_INTEGER li;
    GetSystemTimeAsFileTime(&ft);
    li.LowPart  = ft.dwLowDateTime;
    li.HighPart = ft.dwHighDateTime;
    /* FILETIME: 100ns intervals since 1601-01-01 → Unix epoch */
    li.QuadPart -= 116444736000000000ULL;
    ts->tv_sec  = (time_t)(li.QuadPart / 10000000ULL);
    ts->tv_nsec = (long)((li.QuadPart % 10000000ULL) * 100);
    return 0;
  }
  #define clock_gettime _tthread_clock_gettime
#endif

#ifndef TIME_UTC
  #define TIME_UTC 0
#endif

/* ── _Thread_local 兼容 ── */
#if !(defined(__STDC_VERSION__) && (__STDC_VERSION__ >= 201102L)) && !defined(_Thread_local)
  #if defined(__GNUC__) || defined(__INTEL_COMPILER) || defined(__clang__)
    #define _Thread_local __thread
  #elif defined(_MSC_VER)
    #define _Thread_local __declspec(thread)
  #elif defined(_WIN32)
    #define _Thread_local __declspec(thread)
  #endif
#endif

/* ════════════════════════════════════════════════════════════
   常量定义
   ════════════════════════════════════════════════════════════ */

#define TSS_DTOR_ITERATIONS 0

/* 函数返回值 */
#define thrd_error    0
#define thrd_success  1
#define thrd_timeout  2
#define thrd_busy     3
#define thrd_nomem    4

/* mutex 类型 */
#define mtx_plain     1
#define mtx_timed     2
#define mtx_try       4
#define mtx_recursive 8

/* ════════════════════════════════════════════════════════════
   类型定义
   ════════════════════════════════════════════════════════════ */

/* ── Mutex ── */
#if defined(_TTHREAD_WIN32_)
  /* 优化：非递归用 SRWLOCK（指针大小），递归用 CRITICAL_SECTION */
  typedef struct {
    int               mRecursive;  /* 1=递归(CRITICAL_SECTION), 0=非递归(SRWLOCK) */
    SRWLOCK           mLock;       /* 非递归锁 */
    CRITICAL_SECTION  mRecLock;    /* 递归锁 */
  } mtx_t;
#else
  typedef pthread_mutex_t mtx_t;
#endif

/* ── Condition Variable ── */
#if defined(_TTHREAD_WIN32_)
  /* 优化：用原生 CONDITION_VARIABLE 替代 2 Event + CS 模拟 */
  typedef struct {
    CONDITION_VARIABLE mCond;
  } cnd_t;
#else
  typedef pthread_cond_t cnd_t;
#endif

/* ── Thread ── */
#if defined(_TTHREAD_WIN32_)
  typedef HANDLE thrd_t;
#else
  typedef pthread_t thrd_t;
#endif

/* ── Thread-Specific Storage ── */
#if defined(_TTHREAD_WIN32_)
  typedef DWORD tss_t;
#else
  typedef pthread_key_t tss_t;
#endif

typedef int (*thrd_start_t)(void *arg);
typedef void (*tss_dtor_t)(void *val);

/* ════════════════════════════════════════════════════════════
   Thread API
   ════════════════════════════════════════════════════════════ */

/** 创建新线程 */
static inline int thrd_create(thrd_t *thr, thrd_start_t func, void *arg);

/** 分离线程（资源自动回收） */
static inline int thrd_detach(thrd_t thr);

/** 等待线程结束 */
static inline int thrd_join(thrd_t thr, int *res);

/** 当前线程 ID */
static inline thrd_t thrd_current(void);

/** 比较两个线程 ID */
static inline int thrd_equal(thrd_t thr0, thrd_t thr1);

/** 退出当前线程 */
static inline void thrd_exit(int res);

/** 让出 CPU */
static inline void thrd_yield(void);

/** 睡眠指定时间 */
static inline int thrd_sleep(const struct timespec *time_point, struct timespec *remaining);

/* ════════════════════════════════════════════════════════════
   Mutex API
   ════════════════════════════════════════════════════════════ */

static inline int mtx_init(mtx_t *mtx, int type);
static inline void mtx_destroy(mtx_t *mtx);
static inline int mtx_lock(mtx_t *mtx);
static inline int mtx_trylock(mtx_t *mtx);
static inline int mtx_unlock(mtx_t *mtx);
static inline int mtx_timedlock(mtx_t *mtx, const struct timespec *ts);

/* ════════════════════════════════════════════════════════════
   Condition Variable API
   ════════════════════════════════════════════════════════════ */

static inline int cnd_init(cnd_t *cond);
static inline void cnd_destroy(cnd_t *cond);
static inline int cnd_signal(cnd_t *cond);
static inline int cnd_broadcast(cnd_t *cond);
static inline int cnd_wait(cnd_t *cond, mtx_t *mtx);
static inline int cnd_timedwait(cnd_t *cond, mtx_t *mtx, const struct timespec *ts);

/* ════════════════════════════════════════════════════════════
   Thread-Specific Storage API
   ════════════════════════════════════════════════════════════ */

static inline int tss_create(tss_t *key, tss_dtor_t dtor);
static inline void tss_delete(tss_t key);
static inline void *tss_get(tss_t key);
static inline int tss_set(tss_t key, void *val);

/* ════════════════════════════════════════════════════════════
   SpinLock API（新增 — TCC 兼容）
   ════════════════════════════════════════════════════════════ */

/*
 * SpinLock 适用于极短临界区（<几十条指令），避免 mutex 的内核态切换开销。
 *
 * 原子操作策略（TCC 兼容）：
 *   Windows        → InterlockedCompareExchange（win32 API，TCC 原生支持）
 *   GCC/Clang      → __atomic_compare_exchange_n（内建，无需 libatomic）
 *   TCC x86_64     → 内联汇编 lock cmpxchgl
 *   TCC aarch64    → mutex 降级（TCC ARM 无内联汇编能力）
 */

/* 检测是否可以用真实 SpinLock（CAS） */
#if defined(_WIN32)
  #define _TPHP_SPINLOCK_REAL 1      /* Windows: InterlockedCompareExchange */
#elif defined(__GNUC__) || defined(__clang__)
  #define _TPHP_SPINLOCK_REAL 1      /* GCC/Clang: __atomic_* builtins */
#elif defined(__TINYC__) && (defined(__x86_64__) || defined(__i386__))
  #define _TPHP_SPINLOCK_REAL 1      /* TCC x86: inline asm */
#else
  #define _TPHP_SPINLOCK_REAL 0      /* TCC ARM: mutex 降级 */
#endif

#if _TPHP_SPINLOCK_REAL
  typedef struct {
    volatile int _locked;  /* 0=unlocked, 1=locked */
  } tphp_spinlock_t;
#else
  /* TCC ARM: 用 mutex 降级为 "spinlock" */
  typedef struct {
    mtx_t _mtx;
  } tphp_spinlock_t;
#endif

static inline void tphp_spin_init(tphp_spinlock_t *s);
static inline void tphp_spin_lock(tphp_spinlock_t *s);
static inline int  tphp_spin_trylock(tphp_spinlock_t *s);
static inline void tphp_spin_unlock(tphp_spinlock_t *s);
static inline void tphp_spin_destroy(tphp_spinlock_t *s);

/* ════════════════════════════════════════════════════════════
   WaitGroup API（新增）
   ════════════════════════════════════════════════════════════ */

/*
 * WaitGroup 用于等待 N 个线程完成。
 * 用法：
 *   tphp_wg_t wg;  tphp_wg_init(&wg);
 *   tphp_wg_add(&wg, 3);       // 3 个任务
 *   // ... 启动 3 个线程，每个完成后 tphp_wg_done(&wg)
 *   tphp_wg_wait(&wg);        // 等待全部完成
 *   tphp_wg_destroy(&wg);
 *
 * 实现：mutex + condvar（无需原子操作，TCC 全兼容）
 */

typedef struct {
  mtx_t mLock;
  cnd_t mCond;
  int   task_count;
  int   wait_count;
} tphp_wg_t;

static inline void tphp_wg_init(tphp_wg_t *wg);
static inline void tphp_wg_add(tphp_wg_t *wg, int delta);
static inline void tphp_wg_done(tphp_wg_t *wg);
static inline void tphp_wg_wait(tphp_wg_t *wg);
static inline void tphp_wg_destroy(tphp_wg_t *wg);

/* ════════════════════════════════════════════════════════════
   ║                       实现                               ║
   ════════════════════════════════════════════════════════════ */

/* ── 内部：线程启动包装 ── */
typedef struct {
  thrd_start_t mFunction;
  void        *mArg;
} _tthread_start_info;

#if defined(_TTHREAD_WIN32_)
static unsigned __stdcall _tthread_wrapper_fn(void *aArg)
#else
static void *_tthread_wrapper_fn(void *aArg)
#endif
{
  _tthread_start_info *ti = (_tthread_start_info *)aArg;
  thrd_start_t fun = ti->mFunction;
  void *arg = ti->mArg;
  free((void *)ti);
  int res = fun(arg);
  TPHP_THREAD_CLEANUP();   /* 释放子线程 thread-local 内存池 */
#if defined(_TTHREAD_WIN32_)
  return (unsigned)res;
#else
  /* POSIX: pthread_join 期望 void* 返回，用 malloc 传递 int */
  int *pres = (int *)malloc(sizeof(int));
  if (pres) *pres = res;
  return pres;
#endif
}

/* ── Thread 实现 ── */

static inline int thrd_create(thrd_t *thr, thrd_start_t func, void *arg) {
  _tthread_start_info *ti = (_tthread_start_info *)malloc(sizeof(_tthread_start_info));
  if (ti == NULL) return thrd_nomem;
  ti->mFunction = func;
  ti->mArg = arg;
#if defined(_TTHREAD_WIN32_)
  *thr = (HANDLE)_beginthreadex(NULL, 0, _tthread_wrapper_fn, (void *)ti, 0, NULL);
  if (*thr == 0) { free(ti); return thrd_error; }
#else
  if (pthread_create(thr, NULL, _tthread_wrapper_fn, (void *)ti) != 0) {
    *thr = 0;
    free(ti);
    return thrd_error;
  }
#endif
  return thrd_success;
}

static inline int thrd_detach(thrd_t thr) {
#if defined(_TTHREAD_WIN32_)
  /* CloseHandle 释放句柄；线程退出后资源自动回收（detach 语义） */
  if (CloseHandle(thr)) return thrd_success;
  return thrd_error;
#else
  return (pthread_detach(thr) == 0) ? thrd_success : thrd_error;
#endif
}

static inline int thrd_join(thrd_t thr, int *res) {
#if defined(_TTHREAD_WIN32_)
  if (WaitForSingleObject(thr, INFINITE) == WAIT_FAILED) return thrd_error;
  if (res) {
    DWORD dwRes;
    GetExitCodeThread(thr, &dwRes);
    *res = (int)dwRes;
  }
  CloseHandle(thr);
#else
  void *pres;
  if (pthread_join(thr, &pres) != 0) return thrd_error;
  if (pres) {
    if (res) *res = *(int *)pres;
    free(pres);
  }
#endif
  return thrd_success;
}

static inline thrd_t thrd_current(void) {
#if defined(_TTHREAD_WIN32_)
  /* GetCurrentThread() 返回伪句柄，需 DuplicateHandle 获取真实句柄。
   * 但对于 thrd_equal 比较，伪句柄即可。 */
  return GetCurrentThread();
#else
  return pthread_self();
#endif
}

static inline int thrd_equal(thrd_t thr0, thrd_t thr1) {
#if defined(_TTHREAD_WIN32_)
  return thr0 == thr1;
#else
  return pthread_equal(thr0, thr1);
#endif
}

static inline void thrd_exit(int res) {
#if defined(_TTHREAD_WIN32_)
  ExitThread((DWORD)res);
#else
  int *pres = (int *)malloc(sizeof(int));
  if (pres) *pres = res;
  pthread_exit(pres);
#endif
}

static inline void thrd_yield(void) {
#if defined(_TTHREAD_WIN32_)
  Sleep(0);
#else
  sched_yield();
#endif
}

static inline int thrd_sleep(const struct timespec *duration, struct timespec *remaining) {
  /* C11: thrd_sleep 接受相对时长（非绝对时间戳），直接 Sleep/nanosleep */
#if defined(_TTHREAD_WIN32_)
  DWORD delta = (DWORD)(duration->tv_sec * 1000 +
                        (duration->tv_nsec + 999999) / 1000000);
  if (delta > 0) Sleep(delta);
  if (remaining) { remaining->tv_sec = 0; remaining->tv_nsec = 0; }
  return 0;
#else
  return nanosleep(duration, remaining);
#endif
}

/* ── Mutex 实现 ── */

static inline int mtx_init(mtx_t *mtx, int type) {
#if defined(_TTHREAD_WIN32_)
  mtx->mRecursive = (type & mtx_recursive) ? 1 : 0;
  if (mtx->mRecursive) {
    InitializeCriticalSection(&mtx->mRecLock);
  } else {
    InitializeSRWLock(&mtx->mLock);
  }
  (void)type;
  return thrd_success;
#else
  int ret;
  pthread_mutexattr_t attr;
  pthread_mutexattr_init(&attr);
  if (type & mtx_recursive)
    pthread_mutexattr_settype(&attr, PTHREAD_MUTEX_RECURSIVE);
  ret = pthread_mutex_init(mtx, &attr);
  pthread_mutexattr_destroy(&attr);
  return ret == 0 ? thrd_success : thrd_error;
#endif
}

static inline void mtx_destroy(mtx_t *mtx) {
#if defined(_TTHREAD_WIN32_)
  if (mtx->mRecursive) DeleteCriticalSection(&mtx->mRecLock);
  /* SRWLOCK 无需销毁 */
#else
  pthread_mutex_destroy(mtx);
#endif
}

static inline int mtx_lock(mtx_t *mtx) {
#if defined(_TTHREAD_WIN32_)
  if (mtx->mRecursive) {
    EnterCriticalSection(&mtx->mRecLock);
  } else {
    AcquireSRWLockExclusive(&mtx->mLock);
  }
  return thrd_success;
#else
  return pthread_mutex_lock(mtx) == 0 ? thrd_success : thrd_error;
#endif
}

static inline int mtx_trylock(mtx_t *mtx) {
#if defined(_TTHREAD_WIN32_)
  if (mtx->mRecursive) {
    return TryEnterCriticalSection(&mtx->mRecLock) ? thrd_success : thrd_busy;
  }
  /* TryAcquireSRWLockExclusive: Windows 7+ */
  return TryAcquireSRWLockExclusive(&mtx->mLock) ? thrd_success : thrd_busy;
#else
  return (pthread_mutex_trylock(mtx) == 0) ? thrd_success : thrd_busy;
#endif
}

static inline int mtx_unlock(mtx_t *mtx) {
#if defined(_TTHREAD_WIN32_)
  if (mtx->mRecursive) {
    LeaveCriticalSection(&mtx->mRecLock);
  } else {
    ReleaseSRWLockExclusive(&mtx->mLock);
  }
  return thrd_success;
#else
  return pthread_mutex_unlock(mtx) == 0 ? thrd_success : thrd_error;
#endif
}

static inline int mtx_timedlock(mtx_t *mtx, const struct timespec *ts) {
  /* 简单实现：轮询 trylock + 短睡眠 */
  struct timespec now;
  if (clock_gettime(TIME_UTC, &now) != 0) return thrd_error;
  while (now.tv_sec < ts->tv_sec || (now.tv_sec == ts->tv_sec && now.tv_nsec < ts->tv_nsec)) {
    if (mtx_trylock(mtx) == thrd_success) return thrd_success;
    thrd_yield();
    clock_gettime(TIME_UTC, &now);
  }
  return thrd_timeout;
}

/* ── Condition Variable 实现 ── */

static inline int cnd_init(cnd_t *cond) {
#if defined(_TTHREAD_WIN32_)
  InitializeConditionVariable(&cond->mCond);
  return thrd_success;
#else
  return pthread_cond_init(cond, NULL) == 0 ? thrd_success : thrd_error;
#endif
}

static inline void cnd_destroy(cnd_t *cond) {
#if defined(_TTHREAD_WIN32_)
  /* CONDITION_VARIABLE 无需销毁 */
  (void)cond;
#else
  pthread_cond_destroy(cond);
#endif
}

static inline int cnd_signal(cnd_t *cond) {
#if defined(_TTHREAD_WIN32_)
  WakeConditionVariable(&cond->mCond);
  return thrd_success;
#else
  return pthread_cond_signal(cond) == 0 ? thrd_success : thrd_error;
#endif
}

static inline int cnd_broadcast(cnd_t *cond) {
#if defined(_TTHREAD_WIN32_)
  WakeAllConditionVariable(&cond->mCond);
  return thrd_success;
#else
  /* 修复原 tinycthread bug: pthread_cond_signal → pthread_cond_broadcast */
  return pthread_cond_broadcast(cond) == 0 ? thrd_success : thrd_error;
#endif
}

static inline int cnd_wait(cnd_t *cond, mtx_t *mtx) {
#if defined(_TTHREAD_WIN32_)
  BOOL ok;
  if (mtx->mRecursive) {
    ok = SleepConditionVariableCS(&cond->mCond, &mtx->mRecLock, INFINITE);
  } else {
    ok = SleepConditionVariableSRW(&cond->mCond, &mtx->mLock, INFINITE, 0);
  }
  return ok ? thrd_success : thrd_error;
#else
  return pthread_cond_wait(cond, mtx) == 0 ? thrd_success : thrd_error;
#endif
}

static inline int cnd_timedwait(cnd_t *cond, mtx_t *mtx, const struct timespec *ts) {
#if defined(_TTHREAD_WIN32_)
  struct timespec now;
  if (clock_gettime(TIME_UTC, &now) != 0) return thrd_error;
  DWORD delta = (DWORD)((ts->tv_sec - now.tv_sec) * 1000 +
                        (ts->tv_nsec - now.tv_nsec + 500000) / 1000000);
  BOOL ok;
  if (mtx->mRecursive) {
    ok = SleepConditionVariableCS(&cond->mCond, &mtx->mRecLock, delta);
  } else {
    ok = SleepConditionVariableSRW(&cond->mCond, &mtx->mLock, delta, 0);
  }
  if (!ok) {
    return (GetLastError() == ERROR_TIMEOUT) ? thrd_timeout : thrd_error;
  }
  return thrd_success;
#else
  int ret = pthread_cond_timedwait(cond, mtx, ts);
  if (ret == ETIMEDOUT) return thrd_timeout;
  return ret == 0 ? thrd_success : thrd_error;
#endif
}

/* ── Thread-Specific Storage 实现 ── */

static inline int tss_create(tss_t *key, tss_dtor_t dtor) {
#if defined(_TTHREAD_WIN32_)
  /* 用 FLS（Fiber-Local Storage）替代 TLS，支持析构函数 */
  if (dtor != NULL) {
    *key = FlsAlloc((PFLS_CALLBACK_FUNCTION)dtor);
  } else {
    *key = FlsAlloc(NULL);
  }
  if (*key == FLS_OUT_OF_INDEXES) return thrd_error;
  return thrd_success;
#else
  return (pthread_key_create(key, dtor) == 0) ? thrd_success : thrd_error;
#endif
}

static inline void tss_delete(tss_t key) {
#if defined(_TTHREAD_WIN32_)
  FlsFree(key);
#else
  pthread_key_delete(key);
#endif
}

static inline void *tss_get(tss_t key) {
#if defined(_TTHREAD_WIN32_)
  return FlsGetValue(key);
#else
  return pthread_getspecific(key);
#endif
}

static inline int tss_set(tss_t key, void *val) {
#if defined(_TTHREAD_WIN32_)
  return FlsSetValue(key, val) ? thrd_success : thrd_error;
#else
  return (pthread_setspecific(key, val) == 0) ? thrd_success : thrd_error;
#endif
}

/* ════════════════════════════════════════════════════════════
   SpinLock 实现
   ════════════════════════════════════════════════════════════ */

/* ── 内部：CAS 原语（TCC 兼容） ── */

static inline int _tphp_cas32(volatile int *ptr, int expected, int desired) {
#if defined(_WIN32)
  /* Windows: InterlockedCompareExchange 返回旧值，等于 expected 表示成功 */
  return InterlockedCompareExchange((volatile LONG *)ptr, (LONG)desired, (LONG)expected) == (LONG)expected;
#elif defined(__GNUC__) || defined(__clang__)
  /* GCC/Clang: __atomic 原生内建，无需 libatomic（32 位类型 x86_64/aarch64 原生支持） */
  return __atomic_compare_exchange_n(ptr, &expected, desired, 0,
                                     __ATOMIC_ACQ_REL, __ATOMIC_ACQUIRE);
#elif defined(__TINYC__) && (defined(__x86_64__) || defined(__i386__))
  /* TCC x86: 内联汇编 lock cmpxchgl
   *   %0 = result (sete), %1 = *ptr (+m), %2 = desired (r), %3 = expected (a=eax)
   *   cmpxchgl 比较 eax 与 *ptr，相等则 *ptr=desired，否则 eax=*ptr */
  char result;
  __asm__ __volatile__(
      "lock; cmpxchgl %2, %1\n\t"
      "sete %0"
      : "=q"(result), "+m"(*ptr)
      : "r"(desired), "a"(expected)
      : "memory", "cc"
  );
  return (int)result;
#else
  #error "Unsupported platform for CAS — define _TPHP_SPINLOCK_REAL 0 or add platform support"
#endif
}

static inline void _tphp_atomic_store_release(volatile int *ptr, int val) {
#if defined(_WIN32)
  InterlockedExchange((volatile LONG *)ptr, (LONG)val);
#elif defined(__GNUC__) || defined(__clang__)
  __atomic_store_n(ptr, val, __ATOMIC_RELEASE);
#elif defined(__TINYC__) && (defined(__x86_64__) || defined(__i386__))
  /* x86_64: store + compiler barrier = release 语义（x86 强内存序） */
  __asm__ __volatile__("" ::: "memory");
  *ptr = val;
#else
  *ptr = val;
#endif
}

static inline int _tphp_atomic_load_acquire(volatile int *ptr) {
#if defined(_WIN32)
  return InterlockedCompareExchange((volatile LONG *)ptr, 0, 0);
#elif defined(__GNUC__) || defined(__clang__)
  return __atomic_load_n(ptr, __ATOMIC_ACQUIRE);
#elif defined(__TINYC__) && (defined(__x86_64__) || defined(__i386__))
  int v = *ptr;
  __asm__ __volatile__("" ::: "memory");
  return v;
#else
  return *ptr;
#endif
}

/* ── cpu_relax：自旋等待优化 ── */
static inline void _tphp_cpu_relax(void) {
#if defined(__x86_64__) || defined(_M_X64) || defined(__i386__) || defined(_M_IX86)
  __asm__ __volatile__("pause" ::: "memory");
#elif (defined(__aarch64__) || defined(__arm__)) && (defined(__GNUC__) || defined(__clang__))
  __asm__ __volatile__("yield" ::: "memory");
#else
  /* TCC ARM 或未知平台：空 volatile（编译器屏障） */
  __asm__ __volatile__("" ::: "memory");
#endif
}

/* ── SpinLock API 实现 ── */

static inline void tphp_spin_init(tphp_spinlock_t *s) {
#if _TPHP_SPINLOCK_REAL
  s->_locked = 0;
#else
  mtx_init(&s->_mtx, mtx_plain);
#endif
}

static inline void tphp_spin_lock(tphp_spinlock_t *s) {
#if _TPHP_SPINLOCK_REAL
  int spin_count = 0;
  const int max_spins = 100;
  for (;;) {
    if (_tphp_cas32(&s->_locked, 0, 1)) return;
    spin_count++;
    if (spin_count > max_spins) {
      /* 超过 max_spins 次后让出 CPU（避免 100% 自旋浪费） */
      thrd_yield();
      spin_count = 0;
    } else {
      _tphp_cpu_relax();
    }
  }
#else
  mtx_lock(&s->_mtx);
#endif
}

static inline int tphp_spin_trylock(tphp_spinlock_t *s) {
#if _TPHP_SPINLOCK_REAL
  return _tphp_cas32(&s->_locked, 0, 1) ? thrd_success : thrd_busy;
#else
  return mtx_trylock(&s->_mtx);
#endif
}

static inline void tphp_spin_unlock(tphp_spinlock_t *s) {
#if _TPHP_SPINLOCK_REAL
  _tphp_atomic_store_release(&s->_locked, 0);
#else
  mtx_unlock(&s->_mtx);
#endif
}

static inline void tphp_spin_destroy(tphp_spinlock_t *s) {
#if _TPHP_SPINLOCK_REAL
  (void)s;
#else
  mtx_destroy(&s->_mtx);
#endif
}

/* ════════════════════════════════════════════════════════════
   WaitGroup 实现
   ════════════════════════════════════════════════════════════ */

static inline void tphp_wg_init(tphp_wg_t *wg) {
  mtx_init(&wg->mLock, mtx_plain);
  cnd_init(&wg->mCond);
  wg->task_count = 0;
  wg->wait_count = 0;
}

static inline void tphp_wg_add(tphp_wg_t *wg, int delta) {
  mtx_lock(&wg->mLock);
  wg->task_count += delta;
  if (wg->task_count < 0) wg->task_count = 0;
  if (wg->task_count == 0 && wg->wait_count > 0) {
    cnd_broadcast(&wg->mCond);
  }
  mtx_unlock(&wg->mLock);
}

static inline void tphp_wg_done(tphp_wg_t *wg) {
  tphp_wg_add(wg, -1);
}

static inline void tphp_wg_wait(tphp_wg_t *wg) {
  mtx_lock(&wg->mLock);
  if (wg->task_count > 0) {
    wg->wait_count++;
    while (wg->task_count > 0) {
      cnd_wait(&wg->mCond, &wg->mLock);
    }
    wg->wait_count--;
  }
  mtx_unlock(&wg->mLock);
}

static inline void tphp_wg_destroy(tphp_wg_t *wg) {
  mtx_destroy(&wg->mLock);
  cnd_destroy(&wg->mCond);
}

#endif /* _TINYCTHREAD_OPTIMIZED_H_ */
