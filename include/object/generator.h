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
//     macOS   + GCC/Clang → ASM    |  macOS   + TCC → STUB (ucontext 损坏)
// ============================================================

#include "object/object.h"
#include "val.h"

/* ── minicoro 平台选择 ──────────────────────────────────── */

#if defined(__APPLE__) && !defined(__GNUC__)
/*
 * Stub: TCC on macOS — ucontext 布局不匹配 Apple Silicon，运行时 segfault。
 * GCC/Clang on macOS 定义 __GNUC__，走 ASM 路径，正常工作。
 * 此 stub 让编译通过，运行时 Generator 方法返回默认值。
 */
typedef struct mco_coro_t_stub { int _unused; } mco_coro;
typedef struct { void (*func)(mco_coro*); void* user_data; } mco_desc;
typedef int mco_result;
typedef int mco_state;
#define MCO_SUCCESS   0
#define MCO_DEAD      0
#define MCO_SUSPENDED 3
static inline mco_desc mco_desc_init(void (*f)(mco_coro*), size_t s) { mco_desc d = {f, NULL}; (void)s; return d; }
static inline mco_result mco_create(mco_coro** out, mco_desc* d) { *out = NULL; (void)d; return MCO_SUCCESS; }
static inline mco_result mco_destroy(mco_coro* co) { (void)co; return MCO_SUCCESS; }
static inline mco_result mco_resume(mco_coro* co) { (void)co; return MCO_SUCCESS; }
static inline mco_result mco_yield(mco_coro* co) { (void)co; return MCO_SUCCESS; }
static inline mco_state mco_status(mco_coro* co) { (void)co; return MCO_DEAD; }
static inline void* mco_get_user_data(mco_coro* co) { (void)co; return NULL; }
static inline mco_result mco_push(mco_coro* co, const void* s, size_t l) { (void)co; (void)s; (void)l; return MCO_SUCCESS; }
static inline mco_result mco_pop(mco_coro* co, void* d, size_t l) { (void)co; (void)d; (void)l; return MCO_SUCCESS; }
static inline size_t mco_get_bytes_stored(mco_coro* co) { (void)co; return 0; }
static inline mco_coro* mco_running(void) { return NULL; }

#else
/* 真实 minicoro 实现 */

/* TCC on Windows: kernel32.def 缺少 CreateFiberEx。
 * x86_64 Windows 上 OS 总是保存 FP 状态，FIBER_FLAG_FLOAT_SWITCH 冗余，
 * 安全地映射到 CreateFiber。仅对非 GCC 编译器（即 TCC）生效。 */
#if defined(_WIN32) && !defined(__GNUC__) && !defined(_MSC_VER)
  #define CreateFiberEx(commit, reserve, flags, fn, param) \
      CreateFiber((reserve), (fn), (param))
#endif

/* 禁用 minicoro 调试日志——会写入 stdout，干扰 #debug 输出比对 */
#define MCO_NO_DEBUG

/* 单 TU 编译：在此定义 MINICORO_IMPL 以包含实现 */
#define MINICORO_IMPL
#include "minicoro.h"

#endif /* __APPLE__ && !__GNUC__ */

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
    if (self) {
        self->co = co;
        self->cur_key = VAR_NULL();
        self->cur_val = VAR_NULL();
        self->ret_val = VAR_NULL();
        self->started = false;
        self->done = false;
        tphp_rt_register((void*)self, 0);
    }
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
        #if !defined(__APPLE__) || defined(__GNUC__)
        mco_destroy(self->co);
        #endif
        self->co = NULL;
    }
}

/* current() — 返回当前缓存的 yield 值；若未启动则先 rewind */
t_var tphp_class_Generator_current(tphp_class_Generator* self) {
    if (self == NULL) return VAR_NULL();
    if (!self->started) {
        tphp_class_Generator_rewind(self);
    }
    return self->cur_val;
}

/* key() — 返回当前缓存的 key */
t_var tphp_class_Generator_key(tphp_class_Generator* self) {
    if (self == NULL) return VAR_NULL();
    if (!self->started) {
        tphp_class_Generator_rewind(self);
    }
    return self->cur_key;
}

/* valid() — 返回是否仍有可迭代的值 */
t_int tphp_class_Generator_valid(tphp_class_Generator* self) {
    if (self == NULL) return 0;
    if (!self->started) {
        tphp_class_Generator_rewind(self);
    }
    return self->done ? 0 : 1;
}

/* next() — 推进到下一个 yield，返回新的 yield 值 */
t_var tphp_class_Generator_next(tphp_class_Generator* self) {
    if (self == NULL) return VAR_NULL();
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
    if (self == NULL) return VAR_NULL();
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
    if (self == NULL) return VAR_NULL();
    return self->ret_val;
}

/* rewind() — 首次 resume（推进到第一个 yield） */
void tphp_class_Generator_rewind(tphp_class_Generator* self) {
    if (self == NULL || self->started || self->done) return;
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
