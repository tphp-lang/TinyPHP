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
//     macOS   + GCC/Clang → ASM    |  macOS   + TCC → 独立 .s 汇编 + minicoro-lite
// ============================================================

#include "object/object.h"
#include "val.h"

/* ── minicoro 平台选择 ──────────────────────────────────── */

/*
 * TCC on macOS: 内联 __asm__() 在 ARM64 上不兼容 TCC，
 * 但 TCC 的 ARM64 汇编器可以编译独立 .s 文件。
 * 因此跳过 minicoro.h 的内联汇编，使用独立的
 * include/mco_arm64_macos.s 提供 _mco_switch / _mco_wrap_main，
 * 其余 minicoro 逻辑在此自包含实现。
 */
#if defined(__APPLE__) && defined(__TINYC__)
  #define MCO_CUSTOM_ASM 1
#endif

/* TCC on Windows: kernel32.def 缺少 CreateFiberEx。
 * x86_64 Windows 上 OS 总是保存 FP 状态，FIBER_FLAG_FLOAT_SWITCH 冗余，
 * 安全地映射到 CreateFiber。仅对非 GCC 编译器（即 TCC）生效。 */
#if defined(_WIN32) && !defined(__GNUC__) && !defined(_MSC_VER)
  #define CreateFiberEx(commit, reserve, flags, fn, param) \
      CreateFiber((reserve), (fn), (param))
#endif

/* 禁用 minicoro 调试日志——会写入 stdout，干扰 #debug 输出比对 */
#define MCO_NO_DEBUG

/* ── macOS+TCC: 自包含 minicoro-lite（使用独立 .s 文件） ──── */
#ifdef MCO_CUSTOM_ASM

#include <stdlib.h>
#include <string.h>

#define MCO_MIN_STACK_SIZE 32768
#define MCO_DEFAULT_STACK_SIZE 57344
#define MCO_DEFAULT_STORAGE_SIZE 4096
#define MCO_MAGIC_NUMBER 0x7E3CB1A9

typedef enum { MCO_SUCCESS = 0, MCO_GENERIC_ERROR = -1, MCO_INVALID_POINTER = -2,
               MCO_INVALID_COROUTINE = -3, MCO_NOT_RUNNING = -4, MCO_NOT_SUSPENDED = -5,
               MCO_MAKE_CONTEXT_ERROR = -6, MCO_SWITCH_CONTEXT_ERROR = -7,
               MCO_NOT_ENOUGH_SPACE = -8, MCO_OUT_OF_MEMORY = -9,
               MCO_INVALID_ARGUMENTS = -10, MCO_INVALID_OPERATION = -11,
               MCO_STACK_OVERFLOW = -12 } mco_result;
typedef enum { MCO_DEAD = 0, MCO_RUNNING = 1, MCO_SUSPENDED = 2, MCO_NORMAL = 3 } mco_state;

typedef struct _mco_ctxbuf {
  void *x[12]; /* x19-x30 */
  void *sp;
  void *lr;
  void *d[8];  /* d8-d15 */
} _mco_ctxbuf;

typedef struct mco_coro mco_coro;
struct mco_coro {
  void* context;
  mco_state state;
  void (*func)(mco_coro* co);
  mco_coro* prev_co;
  void* user_data;
  void* allocator_data;
  void (*free_cb)(void* ptr, void* allocator_data);
  void* stack_base;
  size_t stack_size;
  unsigned char* storage;
  size_t bytes_stored;
  size_t storage_size;
  size_t magic_number;
};

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

typedef struct _mco_context {
  _mco_ctxbuf ctx;
  _mco_ctxbuf back_ctx;
} _mco_context;

/* 来自 include/mco_arm64_macos.s 的外部汇编函数 */
extern void _mco_wrap_main(void);
extern int _mco_switch(_mco_ctxbuf* from, _mco_ctxbuf* to);

static mco_coro* mco_current_co = NULL;

static size_t _mco_align_forward(size_t addr, size_t align) {
  return (addr + (align - 1)) & ~(align - 1);
}

static void* mco_malloc(size_t size, void* data) { (void)data; return malloc(size); }
static void  mco_free(void* ptr, void* data) { (void)data; free(ptr); }

static void _mco_prepare_jumpin(mco_coro* co) {
  mco_coro* prev_co = mco_current_co;
  co->prev_co = prev_co;
  if (prev_co) prev_co->state = MCO_NORMAL;
  mco_current_co = co;
}

static void _mco_prepare_jumpout(mco_coro* co) {
  mco_coro* prev_co = co->prev_co;
  co->prev_co = NULL;
  if (prev_co) prev_co->state = MCO_RUNNING;
  mco_current_co = prev_co;
}

static void _mco_jumpin(mco_coro* co) {
  _mco_context* context = (_mco_context*)co->context;
  _mco_prepare_jumpin(co);
  _mco_switch(&context->back_ctx, &context->ctx);
}

static void _mco_jumpout(mco_coro* co) {
  _mco_context* context = (_mco_context*)co->context;
  _mco_prepare_jumpout(co);
  _mco_switch(&context->ctx, &context->back_ctx);
}

static void _mco_main(mco_coro* co) {
  co->func(co);
  co->state = MCO_DEAD;
  _mco_jumpout(co);
}

static mco_result _mco_makectx(mco_coro* co, _mco_ctxbuf* ctx, void* stack_base, size_t stack_size) {
  ctx->x[0] = (void*)(co);
  ctx->x[1] = (void*)(_mco_main);
  ctx->x[2] = (void*)(0xdeaddeaddeaddead);
  ctx->sp = (void*)((size_t)stack_base + stack_size);
  ctx->lr = (void*)(_mco_wrap_main);
  return MCO_SUCCESS;
}

static mco_result _mco_create_context(mco_coro* co, mco_desc* desc) {
  size_t co_addr = (size_t)co;
  size_t context_addr = _mco_align_forward(co_addr + sizeof(mco_coro), 16);
  size_t storage_addr = _mco_align_forward(context_addr + sizeof(_mco_context), 16);
  size_t stack_addr = _mco_align_forward(storage_addr + desc->storage_size, 16);
  _mco_context* context = (_mco_context*)context_addr;
  memset(context, 0, sizeof(_mco_context));
  unsigned char* storage = (unsigned char*)storage_addr;
  memset(storage, 0, desc->storage_size);
  void *stack_base = (void*)stack_addr;
  size_t stack_size = desc->stack_size;
  mco_result res = _mco_makectx(co, &context->ctx, stack_base, stack_size);
  if (res != MCO_SUCCESS) return res;
  co->context = context;
  co->stack_base = stack_base;
  co->stack_size = stack_size;
  co->storage = storage;
  co->storage_size = desc->storage_size;
  return MCO_SUCCESS;
}

static void _mco_init_desc_sizes(mco_desc* desc, size_t stack_size) {
  desc->coro_size = _mco_align_forward(sizeof(mco_coro), 16) +
                    _mco_align_forward(sizeof(_mco_context), 16) +
                    _mco_align_forward(desc->storage_size, 16) +
                    stack_size + 16;
  desc->stack_size = stack_size;
}

static mco_desc mco_desc_init(void (*func)(mco_coro* co), size_t stack_size) {
  if (stack_size != 0) {
    if (stack_size < MCO_MIN_STACK_SIZE) stack_size = MCO_MIN_STACK_SIZE;
  } else {
    stack_size = MCO_DEFAULT_STACK_SIZE;
  }
  stack_size = _mco_align_forward(stack_size, 16);
  mco_desc desc;
  memset(&desc, 0, sizeof(mco_desc));
  desc.malloc_cb = mco_malloc;
  desc.free_cb = mco_free;
  desc.func = func;
  desc.storage_size = MCO_DEFAULT_STORAGE_SIZE;
  _mco_init_desc_sizes(&desc, stack_size);
  return desc;
}

static mco_result mco_create(mco_coro** out_co, mco_desc* desc) {
  if (!out_co) return MCO_INVALID_POINTER;
  if (!desc || !desc->malloc_cb || !desc->free_cb) { *out_co = NULL; return MCO_INVALID_ARGUMENTS; }
  mco_coro* co = (mco_coro*)desc->malloc_cb(desc->coro_size, desc->allocator_data);
  if (!co) { *out_co = NULL; return MCO_OUT_OF_MEMORY; }
  memset(co, 0, sizeof(mco_coro));
  mco_result res = _mco_create_context(co, desc);
  if (res != MCO_SUCCESS) { desc->free_cb(co, desc->allocator_data); *out_co = NULL; return res; }
  co->state = MCO_SUSPENDED;
  co->free_cb = desc->free_cb;
  co->allocator_data = desc->allocator_data;
  co->func = desc->func;
  co->user_data = desc->user_data;
  co->magic_number = MCO_MAGIC_NUMBER;
  *out_co = co;
  return MCO_SUCCESS;
}

static mco_result mco_destroy(mco_coro* co) {
  if (!co) return MCO_INVALID_COROUTINE;
  if (!(co->state == MCO_SUSPENDED || co->state == MCO_DEAD)) return MCO_INVALID_OPERATION;
  co->state = MCO_DEAD;
  if (co->free_cb) co->free_cb(co, co->allocator_data);
  return MCO_SUCCESS;
}

static mco_result mco_resume(mco_coro* co) {
  if (!co) return MCO_INVALID_COROUTINE;
  if (co->state != MCO_SUSPENDED) return MCO_NOT_SUSPENDED;
  co->state = MCO_RUNNING;
  _mco_jumpin(co);
  return MCO_SUCCESS;
}

static mco_result mco_yield(mco_coro* co) {
  if (!co) return MCO_INVALID_COROUTINE;
  volatile size_t dummy;
  size_t stack_addr = (size_t)&dummy;
  size_t stack_min = (size_t)co->stack_base;
  if (co->magic_number != MCO_MAGIC_NUMBER || stack_addr < stack_min || stack_addr > stack_min + co->stack_size)
    return MCO_STACK_OVERFLOW;
  if (co->state != MCO_RUNNING) return MCO_NOT_RUNNING;
  co->state = MCO_SUSPENDED;
  _mco_jumpout(co);
  return MCO_SUCCESS;
}

static mco_state mco_status(mco_coro* co) { return co ? co->state : MCO_DEAD; }
static void* mco_get_user_data(mco_coro* co) { return co ? co->user_data : NULL; }
static mco_coro* mco_running(void) { return mco_current_co; }

static mco_result mco_push(mco_coro* co, const void* src, size_t len) {
  if (!co) return MCO_INVALID_COROUTINE;
  if (len > 0) {
    if (co->bytes_stored + len > co->storage_size) return MCO_NOT_ENOUGH_SPACE;
    if (!src) return MCO_INVALID_POINTER;
    memcpy(&co->storage[co->bytes_stored], src, len);
    co->bytes_stored += len;
  }
  return MCO_SUCCESS;
}

static mco_result mco_pop(mco_coro* co, void* dest, size_t len) {
  if (!co) return MCO_INVALID_COROUTINE;
  if (len > 0) {
    if (len > co->bytes_stored) return MCO_NOT_ENOUGH_SPACE;
    size_t new_stored = co->bytes_stored - len;
    if (dest) memcpy(dest, &co->storage[new_stored], len);
    co->bytes_stored = new_stored;
  }
  return MCO_SUCCESS;
}

static size_t mco_get_bytes_stored(mco_coro* co) { return co ? co->bytes_stored : 0; }

#else
  /* 单 TU 编译：在此定义 MINICORO_IMPL 以包含实现 */
  #define MINICORO_IMPL
  #include "minicoro.h"
#endif

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
        mco_destroy(self->co);
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
