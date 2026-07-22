#pragma once
// ============================================================
// thread.h — 内置线程 COS 类（Thread / Mutex / CondVar / WaitGroup）
//
//   基于 tinycthread.h（优化版 C11 线程库），提供 PHP 层线程 API。
//   遵循 tphp_class_File 封装模式：
//     1. struct 以 t_object _obj 开头
//     2. 类描述符 _class_tphp_class_X（dtor 指向 __destruct）
//     3. new_tphp_class_X 分配器
//     4. 方法命名 tphp_class_X_method
//
//   策略 A（Thread-Local 运行时）：
//     每个线程有独立的 str_pool/arr_pool/obj_pool。
//     线程间只能传递值类型（int/float/bool）或堆分配数据。
//     闭包 env 由创建线程的 GC 追踪，子线程通过 cb->env 访问。
// ============================================================

#include "object/object.h"
#include "compat/tinycthread.h"
#include "types.h"       // t_callback

/* ── 线程入口适配器 ──
 * 将 t_callback（闭包）适配为 thrd_start_t（int(*)(void*)）。
 * 闭包签名约定：t_int (*)(void* _env)
 * 适配器负责释放堆分配的 t_callback 副本。 */
static int _tphp_thread_entry(void *arg) {
    t_callback *cb = (t_callback *)arg;
    /* 调用闭包函数（返回 t_int，截断为 int 作为线程退出码） */
    t_int ret = ((t_int (*)(void *))cb->func)(cb->env);
    free(cb);
    return (int)ret;
}

/* ════════════════════════════════════════════════════════════
   Thread 类
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_object    _obj;
    thrd_t      handle;    /* 线程句柄 */
    int         state;     /* 0=未启动, 1=运行中, 2=已结束 */
    t_callback *cb;        /* 闭包回调（堆分配，start 后由子线程释放） */
    int         ret;       /* join 后的返回值 */
} tphp_class_Thread;

/* 前置声明 */
static void tphp_class_Thread___destruct(tphp_class_Thread *self);

static void* _vtable_tphp_class_Thread[1] = { NULL };
static const t_class _class_tphp_class_Thread = {
    .name          = "Thread",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Thread),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_Thread___destruct,
    .vtable        = _vtable_tphp_class_Thread,
    .vtable_len    = 0,
};

static inline void tphp_class_Thread___construct(tphp_class_Thread *self, t_callback fn) {
    self->state  = 0;
    self->ret    = 0;
    self->handle = 0;
    /* 将 t_callback 复制到堆（原始值在栈上） */
    self->cb = (t_callback *)malloc(sizeof(t_callback));
    if (self->cb) {
        self->cb->func = fn.func;
        self->cb->env  = fn.env;
    }
}

static void tphp_class_Thread___destruct(tphp_class_Thread *self) {
    if (self->state == 1) {
        /* 线程仍在运行 — detach 让其自动回收 */
        thrd_detach(self->handle);
        self->state = 2;
    }
    /* 如果 start() 从未调用，释放回调副本 */
    if (self->cb) {
        free(self->cb);
        self->cb = NULL;
    }
}

static inline t_bool tphp_class_Thread_start(tphp_class_Thread *self) {
    if (self->state != 0 || self->cb == NULL) return false;
    void *arg = self->cb;      /* 转交所有权给子线程 */
    self->cb  = NULL;
    int r = thrd_create(&self->handle, _tphp_thread_entry, arg);
    if (r == thrd_success) {
        self->state = 1;
        return true;
    }
    /* 创建失败 — 回收回调 */
    free(arg);
    return false;
}

static inline t_int tphp_class_Thread_join(tphp_class_Thread *self) {
    if (self->state != 1) return (t_int)self->ret;
    int res = 0;
    thrd_join(self->handle, &res);
    self->ret   = res;
    self->state = 2;
    return (t_int)res;
}

static inline t_bool tphp_class_Thread_detach(tphp_class_Thread *self) {
    if (self->state != 1) return false;
    if (thrd_detach(self->handle) == thrd_success) {
        self->state = 2;
        return true;
    }
    return false;
}

static inline void tphp_class_Thread_yield(void) {
    thrd_yield();
}

static inline void tphp_class_Thread_sleep(t_float seconds) {
    struct timespec ts;
    ts.tv_sec  = (time_t)seconds;
    ts.tv_nsec = (long)((seconds - (t_float)ts.tv_sec) * 1e9);
    if (ts.tv_nsec < 0) ts.tv_nsec = 0;
    thrd_sleep(&ts, NULL);
}

static inline t_int tphp_class_Thread_id(void) {
#if defined(_TTHREAD_WIN32_)
    return (t_int)GetCurrentThreadId();
#else
    return (t_int)(intptr_t)pthread_self();
#endif
}

static inline tphp_class_Thread* new_tphp_class_Thread(t_callback fn) {
    tphp_class_Thread *self = (tphp_class_Thread *)tp_obj_alloc(&_class_tphp_class_Thread);
    if (unlikely(self == NULL)) return NULL;
    tphp_class_Thread___construct(self, fn);
    return self;
}

/* ════════════════════════════════════════════════════════════
   Mutex 类
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_object _obj;
    mtx_t    mtx;       /* 非递归(SRWLOCK) 或 递归(CRITICAL_SECTION) */
} tphp_class_Mutex;

static void tphp_class_Mutex___destruct(tphp_class_Mutex *self);

static void* _vtable_tphp_class_Mutex[1] = { NULL };
static const t_class _class_tphp_class_Mutex = {
    .name          = "Mutex",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Mutex),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_Mutex___destruct,
    .vtable        = _vtable_tphp_class_Mutex,
    .vtable_len    = 0,
};

static inline void tphp_class_Mutex___construct(tphp_class_Mutex *self, t_bool recursive) {
    mtx_init(&self->mtx, recursive ? mtx_recursive : mtx_plain);
}

static void tphp_class_Mutex___destruct(tphp_class_Mutex *self) {
    mtx_destroy(&self->mtx);
}

static inline t_bool tphp_class_Mutex_lock(tphp_class_Mutex *self) {
    return mtx_lock(&self->mtx) == thrd_success;
}

static inline t_bool tphp_class_Mutex_tryLock(tphp_class_Mutex *self) {
    return mtx_trylock(&self->mtx) == thrd_success;
}

static inline t_bool tphp_class_Mutex_unlock(tphp_class_Mutex *self) {
    return mtx_unlock(&self->mtx) == thrd_success;
}

static inline tphp_class_Mutex* new_tphp_class_Mutex(t_bool recursive) {
    tphp_class_Mutex *self = (tphp_class_Mutex *)tp_obj_alloc(&_class_tphp_class_Mutex);
    if (unlikely(self == NULL)) return NULL;
    tphp_class_Mutex___construct(self, recursive);
    return self;
}

/* ════════════════════════════════════════════════════════════
   CondVar 类
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_object _obj;
    cnd_t    cnd;
} tphp_class_CondVar;

static void tphp_class_CondVar___destruct(tphp_class_CondVar *self);

static void* _vtable_tphp_class_CondVar[1] = { NULL };
static const t_class _class_tphp_class_CondVar = {
    .name          = "CondVar",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_CondVar),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_CondVar___destruct,
    .vtable        = _vtable_tphp_class_CondVar,
    .vtable_len    = 0,
};

static inline void tphp_class_CondVar___construct(tphp_class_CondVar *self) {
    cnd_init(&self->cnd);
}

static void tphp_class_CondVar___destruct(tphp_class_CondVar *self) {
    cnd_destroy(&self->cnd);
}

static inline t_bool tphp_class_CondVar_wait(tphp_class_CondVar *self, tphp_class_Mutex *mutex) {
    return cnd_wait(&self->cnd, &mutex->mtx) == thrd_success;
}

static inline t_bool tphp_class_CondVar_signal(tphp_class_CondVar *self) {
    return cnd_signal(&self->cnd) == thrd_success;
}

static inline t_bool tphp_class_CondVar_broadcast(tphp_class_CondVar *self) {
    return cnd_broadcast(&self->cnd) == thrd_success;
}

static inline tphp_class_CondVar* new_tphp_class_CondVar(void) {
    tphp_class_CondVar *self = (tphp_class_CondVar *)tp_obj_alloc(&_class_tphp_class_CondVar);
    if (unlikely(self == NULL)) return NULL;
    tphp_class_CondVar___construct(self);
    return self;
}

/* ════════════════════════════════════════════════════════════
   WaitGroup 类
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_object  _obj;
    tphp_wg_t wg;
} tphp_class_WaitGroup;

static void tphp_class_WaitGroup___destruct(tphp_class_WaitGroup *self);

static void* _vtable_tphp_class_WaitGroup[1] = { NULL };
static const t_class _class_tphp_class_WaitGroup = {
    .name          = "WaitGroup",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_WaitGroup),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_WaitGroup___destruct,
    .vtable        = _vtable_tphp_class_WaitGroup,
    .vtable_len    = 0,
};

static inline void tphp_class_WaitGroup___construct(tphp_class_WaitGroup *self) {
    tphp_wg_init(&self->wg);
}

static void tphp_class_WaitGroup___destruct(tphp_class_WaitGroup *self) {
    tphp_wg_destroy(&self->wg);
}

static inline void tphp_class_WaitGroup_add(tphp_class_WaitGroup *self, t_int delta) {
    tphp_wg_add(&self->wg, (int)delta);
}

static inline void tphp_class_WaitGroup_done(tphp_class_WaitGroup *self) {
    tphp_wg_done(&self->wg);
}

static inline void tphp_class_WaitGroup_wait(tphp_class_WaitGroup *self) {
    tphp_wg_wait(&self->wg);
}

static inline tphp_class_WaitGroup* new_tphp_class_WaitGroup(void) {
    tphp_class_WaitGroup *self = (tphp_class_WaitGroup *)tp_obj_alloc(&_class_tphp_class_WaitGroup);
    if (unlikely(self == NULL)) return NULL;
    tphp_class_WaitGroup___construct(self);
    return self;
}
