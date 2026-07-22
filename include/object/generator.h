#pragma once
// ============================================================
// generator.h — 内置 Generator 类（基于 minicoro 协程）
//
//   生成器函数编译为协程入口，包装函数返回 tphp_class_Generator*。
//   yield 通过 mco_push/mco_pop 双向传值：
//     yield  → push {t_var key, t_var value}, mco_yield
//     return → push t_var (返回值)
//   调用方（Generator 方法）在 mco_resume 后：
//     MCO_DEAD     → pop 返回值
//     MCO_SUSPENDED → pop yield 对
//
//   平台支持：
//     Windows + GCC/Clang → ASM    |  Windows + TCC → Win32 Fiber
//     Linux   + GCC/Clang → ASM    |  Linux   + TCC → ucontext
//     macOS   + GCC/Clang → ASM    |  macOS   + TCC → pthread（线程模拟）
// ============================================================

#include "object/object.h"
#include "val.h"

/* ── minicoro 平台选择 ──────────────────────────────────── */

/* TCC on Windows: kernel32.def 缺少 CreateFiberEx。
 * x86_64 Windows 上 OS 总是保存 FP 状态，FIBER_FLAG_FLOAT_SWITCH 冗余，
 * 安全地映射到 CreateFiber。仅对非 GCC 编译器（即 TCC）生效。 */
#if defined(_WIN32) && !defined(__GNUC__) && !defined(_MSC_VER)
  #define CreateFiberEx(commit, reserve, flags, fn, param) \
      CreateFiber((reserve), (fn), (param))
#endif

/* 禁用 minicoro 调试日志——会写入 stdout，干扰 #debug 输出比对 */
#define MCO_NO_DEBUG

/* ────────────────────────────────────────────────────────────
 * macOS + TCC: 用 pthread 线程模拟协程
 *
 * 根因：TCC 在 macOS 定义 __GNUC__=2（<3），minicoro 走 ucontext 路径，
 *       但 TCC 的 ucontext_t 布局与 Apple Silicon ABI 不匹配 → 段错误。
 *       强制 ASM 路径也有问题（TCC 的 ARM64 内联汇编器/独立 .s 编译均不稳定）。
 *
 * 解法：完全绕过 minicoro 的 ASM/ucontext，用 pthread 线程模拟协程语义。
 *       每个 generator 对应一个线程，主线程与 generator 线程通过
 *       mutex + condition variables 来回切换。保持 mco_* API 完全兼容。
 *
 * 零开销：仅在 __APPLE__ + __TINYC__ 时启用，其他平台走原 minicoro 逻辑。
 * ──────────────────────────────────────────────────────────── */
#if defined(__APPLE__) && defined(__TINYC__)
  #define MCO_USE_PTHREAD
#endif

#ifdef MCO_USE_PTHREAD

#include <pthread.h>
#include <string.h>
#include <stdlib.h>

#define MCO_MAGIC_NUMBER 0x7E3CB1A9
#define MCO_DEFAULT_STORAGE_SIZE 4096

typedef enum {
    MCO_SUCCESS = 0, MCO_GENERIC_ERROR = -1, MCO_INVALID_POINTER = -2,
    MCO_INVALID_COROUTINE = -3, MCO_NOT_RUNNING = -4, MCO_NOT_SUSPENDED = -5,
    MCO_MAKE_CONTEXT_ERROR = -6, MCO_SWITCH_CONTEXT_ERROR = -7,
    MCO_NOT_ENOUGH_SPACE = -8, MCO_OUT_OF_MEMORY = -9,
    MCO_INVALID_ARGUMENTS = -10, MCO_INVALID_OPERATION = -11,
    MCO_STACK_OVERFLOW = -12
} mco_result;

typedef enum { MCO_DEAD = 0, MCO_RUNNING = 1, MCO_SUSPENDED = 2, MCO_NORMAL = 3 } mco_state;

typedef struct mco_coro mco_coro;

typedef struct mco_desc {
    void (*func)(mco_coro* co);
    void* user_data;
    void* (*malloc_cb)(size_t size, void* allocator_data);
    void  (*free_cb)(void* ptr, void* allocator_data);
    void* allocator_data;
    size_t storage_size;
    size_t coro_size;
    size_t stack_size;
} mco_desc;

struct mco_coro {
    mco_state state;
    void (*func)(mco_coro* co);
    void* user_data;
    void* allocator_data;
    void (*free_cb)(void* ptr, void* allocator_data);
    /* pthread 同步 */
    pthread_t thread;
    pthread_mutex_t mutex;
    pthread_cond_t main_cv;   /* 主线程等待此 CV */
    pthread_cond_t gen_cv;    /* generator 线程等待此 CV */
    bool gen_resume;          /* 主线程 → generator：可以继续 */
    bool main_resume;         /* generator → 主线程：可以继续 */
    bool thread_started;
    /* 存储（push/pop） */
    unsigned char* storage;
    size_t bytes_stored;
    size_t storage_size;
    size_t magic_number;
};

/* TLS：当前运行的协程（generator 线程内通过 pthread_setspecific 设置） */
static pthread_key_t _mco_current_key;
static pthread_once_t _mco_once = PTHREAD_ONCE_INIT;

static void _mco_init_tls(void) {
    pthread_key_create(&_mco_current_key, NULL);
}

mco_coro* mco_running(void) {
    pthread_once(&_mco_once, _mco_init_tls);
    return (mco_coro*)pthread_getspecific(_mco_current_key);
}

/* generator 线程入口 */
static void* _mco_thread_entry(void* arg) {
    mco_coro* co = (mco_coro*)arg;
    pthread_once(&_mco_once, _mco_init_tls);
    pthread_setspecific(_mco_current_key, co);

    /* 等待首次 resume */
    pthread_mutex_lock(&co->mutex);
    while (!co->gen_resume) {
        pthread_cond_wait(&co->gen_cv, &co->mutex);
    }
    co->gen_resume = false;
    pthread_mutex_unlock(&co->mutex);

    /* 执行 generator 函数（其中会 mco_yield 来回切换） */
    co->func(co);

    /* 函数结束 → 设置 DEAD 并通知主线程 */
    pthread_mutex_lock(&co->mutex);
    co->state = MCO_DEAD;
    co->main_resume = true;
    pthread_cond_signal(&co->main_cv);
    pthread_mutex_unlock(&co->mutex);

    return NULL;
}

mco_desc mco_desc_init(void (*func)(mco_coro* co), size_t stack_size) {
    (void)stack_size; /* pthread 线程栈由 OS 管理，忽略 */
    mco_desc desc;
    memset(&desc, 0, sizeof(desc));
    desc.func = func;
    desc.storage_size = MCO_DEFAULT_STORAGE_SIZE;
    desc.coro_size = sizeof(mco_coro);
    desc.stack_size = 0;
    desc.malloc_cb = (void*(*)(size_t, void*))malloc;
    desc.free_cb = (void(*)(void*, void*))free;
    return desc;
}

mco_result mco_create(mco_coro** out_co, mco_desc* desc) {
    if (!out_co) return MCO_INVALID_POINTER;
    if (!desc || !desc->func) { *out_co = NULL; return MCO_INVALID_ARGUMENTS; }

    mco_coro* co = (mco_coro*)calloc(1, sizeof(mco_coro));
    if (!co) { *out_co = NULL; return MCO_OUT_OF_MEMORY; }

    co->state = MCO_SUSPENDED;
    co->func = desc->func;
    co->user_data = desc->user_data;
    co->allocator_data = desc->allocator_data;
    co->free_cb = desc->free_cb;
    co->storage_size = desc->storage_size ? desc->storage_size : MCO_DEFAULT_STORAGE_SIZE;
    co->storage = (unsigned char*)calloc(1, co->storage_size);
    if (co->storage == NULL) {
        free(co);
        *out_co = NULL;
        return MCO_OUT_OF_MEMORY;
    }
    co->magic_number = MCO_MAGIC_NUMBER;

    pthread_mutex_init(&co->mutex, NULL);
    pthread_cond_init(&co->main_cv, NULL);
    pthread_cond_init(&co->gen_cv, NULL);

    if (pthread_create(&co->thread, NULL, _mco_thread_entry, co) != 0) {
        pthread_mutex_destroy(&co->mutex);
        pthread_cond_destroy(&co->main_cv);
        pthread_cond_destroy(&co->gen_cv);
        free(co->storage);
        free(co);
        *out_co = NULL;
        return MCO_MAKE_CONTEXT_ERROR;
    }
    co->thread_started = true;

    *out_co = co;
    return MCO_SUCCESS;
}

mco_result mco_destroy(mco_coro* co) {
    if (!co) return MCO_INVALID_COROUTINE;

    /* 如果仍处于 SUSPENDED，先唤醒 generator 线程让它退出 */
    if (co->state == MCO_SUSPENDED && co->thread_started) {
        pthread_mutex_lock(&co->mutex);
        co->state = MCO_DEAD;
        co->gen_resume = true;
        pthread_cond_signal(&co->gen_cv);
        pthread_mutex_unlock(&co->mutex);
        pthread_join(co->thread, NULL);
        co->thread_started = false;
    } else if (co->thread_started) {
        pthread_join(co->thread, NULL);
        co->thread_started = false;
    }

    pthread_mutex_destroy(&co->mutex);
    pthread_cond_destroy(&co->main_cv);
    pthread_cond_destroy(&co->gen_cv);

    free(co->storage);
    if (co->free_cb) {
        co->free_cb(co, co->allocator_data);
    } else {
        free(co);
    }
    return MCO_SUCCESS;
}

mco_result mco_resume(mco_coro* co) {
    if (!co) return MCO_INVALID_COROUTINE;
    if (co->state != MCO_SUSPENDED) return MCO_NOT_SUSPENDED;

    pthread_mutex_lock(&co->mutex);
    co->state = MCO_RUNNING;
    co->gen_resume = true;
    pthread_cond_signal(&co->gen_cv);
    /* 等待 generator yield 或结束 */
    while (!co->main_resume) {
        pthread_cond_wait(&co->main_cv, &co->mutex);
    }
    co->main_resume = false;
    /* state 已由 generator 线程设置（SUSPENDED 或 DEAD） */
    pthread_mutex_unlock(&co->mutex);
    return MCO_SUCCESS;
}

mco_result mco_yield(mco_coro* co) {
    if (!co) return MCO_INVALID_COROUTINE;
    if (co->state != MCO_RUNNING) return MCO_NOT_RUNNING;

    pthread_mutex_lock(&co->mutex);
    co->state = MCO_SUSPENDED;
    co->main_resume = true;
    pthread_cond_signal(&co->main_cv);
    /* 等待主线程再次 resume */
    while (!co->gen_resume) {
        pthread_cond_wait(&co->gen_cv, &co->mutex);
    }
    co->gen_resume = false;
    co->state = MCO_RUNNING;
    pthread_mutex_unlock(&co->mutex);
    return MCO_SUCCESS;
}

mco_state mco_status(mco_coro* co) {
    return co ? co->state : MCO_DEAD;
}

void* mco_get_user_data(mco_coro* co) {
    return co ? co->user_data : NULL;
}

mco_result mco_push(mco_coro* co, const void* src, size_t len) {
    if (!co) return MCO_INVALID_COROUTINE;
    if (len == 0) return MCO_SUCCESS;
    if (co->bytes_stored + len > co->storage_size) return MCO_NOT_ENOUGH_SPACE;
    if (!src) return MCO_INVALID_POINTER;
    memcpy(&co->storage[co->bytes_stored], src, len);
    co->bytes_stored += len;
    return MCO_SUCCESS;
}

mco_result mco_pop(mco_coro* co, void* dest, size_t len) {
    if (!co) return MCO_INVALID_COROUTINE;
    if (len == 0) return MCO_SUCCESS;
    if (len > co->bytes_stored) return MCO_NOT_ENOUGH_SPACE;
    co->bytes_stored -= len;
    if (dest) memcpy(dest, &co->storage[co->bytes_stored], len);
    return MCO_SUCCESS;
}

size_t mco_get_bytes_stored(mco_coro* co) {
    return co ? co->bytes_stored : 0;
}

#else /* !MCO_USE_PTHREAD — 正常 minicoro 路径 */

/* 单 TU 编译：在此定义 MINICORO_IMPL 以包含实现 */
#define MINICORO_IMPL
#include "minicoro.h"

#endif /* MCO_USE_PTHREAD */

/* ── yield 协议结构体 ──────────────────────────────────── */
typedef struct {
    t_var key;
    t_var value;
} _gen_yield_pair;

/* ── Generator 类结构体 ────────────────────────────────── */
typedef struct {
    t_object _obj;
    mco_coro* co;
    t_var cur_key;       /* 缓存当前 key */
    t_var cur_val;       /* 缓存当前 value */
    t_var ret_val;       /* 缓存 return 值 */
    bool started;        /* 是否已首次 resume */
    bool done;           /* 是否已完成 */
} tphp_class_Generator;

/* 前向声明 */
void tphp_class_Generator___destruct(tphp_class_Generator* self);
t_var tphp_class_Generator_current(tphp_class_Generator* self);
t_var tphp_class_Generator_key(tphp_class_Generator* self);
t_int tphp_class_Generator_valid(tphp_class_Generator* self);
t_var tphp_class_Generator_next(tphp_class_Generator* self);
t_var tphp_class_Generator_send(tphp_class_Generator* self, t_var v);
t_var tphp_class_Generator_getReturn(tphp_class_Generator* self);
void tphp_class_Generator_rewind(tphp_class_Generator* self);

/* 类描述符 */
static void* _vtable_tphp_class_Generator[1] = { NULL };
static const t_class _class_tphp_class_Generator = {
    .name          = "Generator",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Generator),
    .dtor          = (void*)tphp_class_Generator___destruct,
    .vtable        = _vtable_tphp_class_Generator,
    .vtable_len    = 0,
};

/* 构造函数：包装 mco_coro* 为 Generator 对象 */
static inline tphp_class_Generator* new_tphp_class_Generator(mco_coro* co) {
    tphp_class_Generator* self = (tphp_class_Generator*)tp_obj_alloc(&_class_tphp_class_Generator);
    if (self == NULL) { tp_throw("new Generator(): out of memory"); return NULL; }
    self->co = co;
    self->cur_key = VAR_NULL();
    self->cur_val = VAR_NULL();
    self->ret_val = VAR_NULL();
    self->started = false;
    self->done = false;
    tphp_rt_register((void*)self, 0);
    return self;
}

/* 内部辅助：resume 协程并更新缓存 */
static inline void _gen_resume_and_cache(tphp_class_Generator* self, t_var sent_val) {
    if (self == NULL || self->co == NULL || self->done) {
        self->done = true;
        return;
    }
    /* 推送 sent_val（next() 传 NULL，send() 传实际值） */
    mco_push(self->co, &sent_val, sizeof(t_var));
    mco_resume(self->co);
    mco_state st = mco_status(self->co);
    if (st == MCO_DEAD) {
        self->done = true;
        /* 弹出返回值（若有） */
        if (mco_get_bytes_stored(self->co) >= sizeof(t_var)) {
            mco_pop(self->co, &self->ret_val, sizeof(t_var));
        } else {
            self->ret_val = VAR_NULL();
        }
    } else {
        /* 弹出 yield 对 */
        _gen_yield_pair yp;
        if (mco_get_bytes_stored(self->co) >= sizeof(_gen_yield_pair)) {
            mco_pop(self->co, &yp, sizeof(_gen_yield_pair));
            self->cur_key = yp.key;
            self->cur_val = yp.value;
        } else {
            self->cur_key = VAR_NULL();
            self->cur_val = VAR_NULL();
        }
    }
}

/* ── 方法实现 ──────────────────────────────────────────── */

void tphp_class_Generator___destruct(tphp_class_Generator* self) {
    if (self && self->co) {
        mco_destroy(self->co);
        self->co = NULL;
    }
}

/* current() — 返回当前缓存的 yield 值；若未启动则先 rewind */
t_var tphp_class_Generator_current(tphp_class_Generator* self) {
    if (self == NULL) { tp_throw("Generator::current(): null generator"); return VAR_NULL(); }
    if (!self->started) {
        tphp_class_Generator_rewind(self);
    }
    return self->cur_val;
}

/* key() — 返回当前缓存的 key */
t_var tphp_class_Generator_key(tphp_class_Generator* self) {
    if (self == NULL) { tp_throw("Generator::key(): null generator"); return VAR_NULL(); }
    if (!self->started) {
        tphp_class_Generator_rewind(self);
    }
    return self->cur_key;
}

/* valid() — 返回是否仍有可迭代的值 */
t_int tphp_class_Generator_valid(tphp_class_Generator* self) {
    if (self == NULL) { tp_throw("Generator::valid(): null generator"); return 0; }
    if (!self->started) {
        tphp_class_Generator_rewind(self);
    }
    return self->done ? 0 : 1;
}

/* next() — 推进到下一个 yield，返回新的 yield 值 */
t_var tphp_class_Generator_next(tphp_class_Generator* self) {
    if (self == NULL) { tp_throw("Generator::next(): null generator"); return VAR_NULL(); }
    if (!self->started) {
        tphp_class_Generator_rewind(self);
        return self->cur_val;
    }
    if (self->done) return VAR_NULL();
    _gen_resume_and_cache(self, VAR_NULL());
    return self->cur_val;
}

/* send($v) — 发送值到 yield 表达式，返回下一个 yield 值 */
t_var tphp_class_Generator_send(tphp_class_Generator* self, t_var v) {
    if (self == NULL) { tp_throw("Generator::send(): null generator"); return VAR_NULL(); }
    if (!self->started) {
        /* 首次：先 rewind 到第一个 yield，再发送 */
        tphp_class_Generator_rewind(self);
        if (self->done) return VAR_NULL();
    }
    if (self->done) return VAR_NULL();
    _gen_resume_and_cache(self, v);
    return self->cur_val;
}

/* getReturn() — 返回生成器的 return 值 */
t_var tphp_class_Generator_getReturn(tphp_class_Generator* self) {
    if (self == NULL) { tp_throw("Generator::getReturn(): null generator"); return VAR_NULL(); }
    return self->ret_val;
}

/* rewind() — 首次 resume（推进到第一个 yield） */
void tphp_class_Generator_rewind(tphp_class_Generator* self) {
    if (self == NULL) { tp_throw("Generator::rewind(): null generator"); return; }
    if (self->started || self->done) return;
    self->started = true;
    if (self->co == NULL) {
        self->done = true;
        return;
    }
    /* 首次 resume 不推送 sent_val（协程入口不弹出） */
    mco_resume(self->co);
    mco_state st = mco_status(self->co);
    if (st == MCO_DEAD) {
        self->done = true;
        if (mco_get_bytes_stored(self->co) >= sizeof(t_var)) {
            mco_pop(self->co, &self->ret_val, sizeof(t_var));
        }
    } else {
        _gen_yield_pair yp;
        if (mco_get_bytes_stored(self->co) >= sizeof(_gen_yield_pair)) {
            mco_pop(self->co, &yp, sizeof(_gen_yield_pair));
            self->cur_key = yp.key;
            self->cur_val = yp.value;
        }
    }
}
