#pragma once
// ============================================================
// channel.h — 异步与协程通信库（Channel + Future + chan_select）
//
//   设计参考 vlang 的 Channel + select 模式，用 tphp OOP 风格封装。
//   复用 tinycthread 的 Mutex/CondVar，不引入新 C 依赖。
//
//   • Channel  — 有界环形缓冲区，CSP 风格线程/协程通信
//   • Future   — 一次性异步结果，await/then/catch/all/race
//   • chan_select — 多通道多路复用
//
//   性能优化：
//     • push/pop/await 阻塞前自旋 750 次（与 vlang 一致），减少 syscall
//     • Channel 使用固定容量环形缓冲区，push/pop 零 malloc
//     • isReady/isClosed/length 无锁原子读
//
//   内存安全：
//     • t_var 值在 push 时 _arr_val_retain，pop 时不额外 retain
//     • close 时遍历释放剩余元素
//     • dtor 即使忘记 close 也释放所有剩余元素
//     • Future resolve/reject 时 retain，dtor 释放
// ============================================================

#include "object/object.h"
#include "object/exception.h"
#include "object/try.h"
#include "compat/tinycthread.h"
#include "types.h"
#include "val.h"
#include "array.h"

/* 自旋次数：阻塞前先自旋，减少高并发场景的 syscall。
   750→64：原值过大，每次"自旋"实为 lock/unlock，高竞争下放大锁开销。
   降低到 64 + 每轮 thrd_yield() 让出 CPU，减少无效锁竞争。 */
#define TPHP_CHAN_SPIN  64

/* ════════════════════════════════════════════════════════════
   Channel 类
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_object    _obj;
    mtx_t       mtx;        /* 保护 ringbuf 和索引 */
    cnd_t       not_full;   /* 缓冲区非满（push 等待） */
    cnd_t       not_empty;  /* 缓冲区非空（pop 等待） */
    t_var      *ringbuf;    /* 固定容量环形缓冲区 */
    int         capacity;   /* 缓冲区容量 */
    int         head;       /* pop 位置 */
    int         tail;       /* push 位置 */
    int         count;      /* 当前元素数 */
    int         is_closed;  /* 0=开放, 1=已关闭 */
} tphp_class_Channel;

/* 前向声明 */
static void tphp_class_Channel___destruct(tphp_class_Channel *self);

static void* _vtable_tphp_class_Channel[1] = { NULL };
static const t_class _class_tphp_class_Channel = {
    .name          = "Channel",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Channel),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_Channel___destruct,
    .vtable        = _vtable_tphp_class_Channel,
    .vtable_len    = 0,
};

static inline tphp_class_Channel* new_tphp_class_Channel(t_int capacity) {
    if (capacity < 1) capacity = 1;
    tphp_class_Channel *self = (tphp_class_Channel*)tp_obj_alloc(&_class_tphp_class_Channel);
    if (self == NULL) { tp_throw("new Channel(): out of memory"); return NULL; }
    mtx_init(&self->mtx, mtx_plain);
    cnd_init(&self->not_full);
    cnd_init(&self->not_empty);
    self->capacity  = (int)capacity;
    self->head      = 0;
    self->tail      = 0;
    self->count     = 0;
    self->is_closed = 0;
    self->ringbuf   = (t_var*)calloc((size_t)self->capacity, sizeof(t_var));
    if (self->ringbuf == NULL) {
        mtx_destroy(&self->mtx);
        cnd_destroy(&self->not_full);
        cnd_destroy(&self->not_empty);
        tp_throw("new Channel(): out of memory");
        return NULL;
    }
    return self;
}

static void tphp_class_Channel___destruct(tphp_class_Channel *self) {
    if (self == NULL) return;
    /* 释放剩余元素（即使忘记 close 也安全） */
    if (self->ringbuf != NULL) {
        for (int i = 0; i < self->count; i++) {
            int idx = (self->head + i) % self->capacity;
            _arr_val_release(self->ringbuf[idx]);
        }
        free(self->ringbuf);
        self->ringbuf = NULL;
    }
    mtx_destroy(&self->mtx);
    cnd_destroy(&self->not_full);
    cnd_destroy(&self->not_empty);
}

/** push(mixed $v): void — 阻塞发送 */
static inline void tphp_class_Channel_push(tphp_class_Channel *self, t_var v) {
    if (self == NULL) { tp_throw("Channel::push(): null channel"); return; }
    /* spin-then-wait */
    for (int i = 0; i < TPHP_CHAN_SPIN; i++) {
        mtx_lock(&self->mtx);
        if (self->is_closed) {
            mtx_unlock(&self->mtx);
            tp_throw_ex(new_tphp_class_ChannelClosedException(STR_LIT("Channel::push(): channel closed")));
            return;
        }
        if (self->count < self->capacity) {
            self->ringbuf[self->tail] = _arr_val_retain(v);
            self->tail = (self->tail + 1) % self->capacity;
            self->count++;
            cnd_signal(&self->not_empty);
            mtx_unlock(&self->mtx);
            return;
        }
        mtx_unlock(&self->mtx);
        thrd_yield();
    }
    /* 阻塞等待 */
    mtx_lock(&self->mtx);
    while (self->count >= self->capacity && !self->is_closed) {
        cnd_wait(&self->not_full, &self->mtx);
    }
    if (self->is_closed) {
        mtx_unlock(&self->mtx);
        tp_throw_ex(new_tphp_class_ChannelClosedException(STR_LIT("Channel::push(): channel closed")));
        return;
    }
    self->ringbuf[self->tail] = _arr_val_retain(v);
    self->tail = (self->tail + 1) % self->capacity;
    self->count++;
    cnd_signal(&self->not_empty);
    mtx_unlock(&self->mtx);
}

/** pop(): mixed — 阻塞接收，空且关闭返回 null */
static inline t_var tphp_class_Channel_pop(tphp_class_Channel *self) {
    if (self == NULL) { tp_throw("Channel::pop(): null channel"); return VAR_NULL(); }
    /* spin-then-wait */
    for (int i = 0; i < TPHP_CHAN_SPIN; i++) {
        mtx_lock(&self->mtx);
        if (self->count > 0) {
            t_var v = self->ringbuf[self->head];
            self->head = (self->head + 1) % self->capacity;
            self->count--;
            cnd_signal(&self->not_full);
            mtx_unlock(&self->mtx);
            return v;  /* 调用方按值获取，不额外 retain */
        }
        if (self->is_closed) {
            mtx_unlock(&self->mtx);
            return VAR_NULL();
        }
        mtx_unlock(&self->mtx);
        thrd_yield();
    }
    /* 阻塞等待 */
    mtx_lock(&self->mtx);
    while (self->count == 0 && !self->is_closed) {
        cnd_wait(&self->not_empty, &self->mtx);
    }
    if (self->count > 0) {
        t_var v = self->ringbuf[self->head];
        self->head = (self->head + 1) % self->capacity;
        self->count--;
        cnd_signal(&self->not_full);
        mtx_unlock(&self->mtx);
        return v;
    }
    mtx_unlock(&self->mtx);
    return VAR_NULL();  /* closed 且空 */
}

/** tryPush(mixed $v): bool — 非阻塞发送 */
static inline t_bool tphp_class_Channel_tryPush(tphp_class_Channel *self, t_var v) {
    if (self == NULL) { tp_throw("Channel::tryPush(): null channel"); return false; }
    mtx_lock(&self->mtx);
    if (self->is_closed) {
        mtx_unlock(&self->mtx);
        tp_throw_ex(new_tphp_class_ChannelClosedException(STR_LIT("Channel::tryPush(): channel closed")));
        return false;
    }
    if (self->count >= self->capacity) {
        mtx_unlock(&self->mtx);
        return false;
    }
    self->ringbuf[self->tail] = _arr_val_retain(v);
    self->tail = (self->tail + 1) % self->capacity;
    self->count++;
    cnd_signal(&self->not_empty);
    mtx_unlock(&self->mtx);
    return true;
}

/** tryPop(): mixed — 非阻塞接收，空返回 null */
static inline t_var tphp_class_Channel_tryPop(tphp_class_Channel *self) {
    if (self == NULL) { tp_throw("Channel::tryPop(): null channel"); return VAR_NULL(); }
    mtx_lock(&self->mtx);
    if (self->count == 0) {
        mtx_unlock(&self->mtx);
        return VAR_NULL();
    }
    t_var v = self->ringbuf[self->head];
    self->head = (self->head + 1) % self->capacity;
    self->count--;
    cnd_signal(&self->not_full);
    mtx_unlock(&self->mtx);
    return v;
}

/** close(): void — 关闭通道，唤醒所有等待者 */
static inline void tphp_class_Channel_close(tphp_class_Channel *self) {
    if (self == NULL) { tp_throw("Channel::close(): null channel"); return; }
    mtx_lock(&self->mtx);
    self->is_closed = 1;
    cnd_broadcast(&self->not_full);
    cnd_broadcast(&self->not_empty);
    mtx_unlock(&self->mtx);
}

/** isClosed(): bool — 无锁读（is_closed 是 int，原子读足够） */
static inline t_bool tphp_class_Channel_isClosed(tphp_class_Channel *self) {
    if (self == NULL) return true;
    return (t_bool)self->is_closed;
}

/** length(): int — 当前元素数（加锁保证一致） */
static inline t_int tphp_class_Channel_length(tphp_class_Channel *self) {
    if (self == NULL) return 0;
    mtx_lock(&self->mtx);
    int c = self->count;
    mtx_unlock(&self->mtx);
    return (t_int)c;
}

/** capacity(): int — 缓冲区容量 */
static inline t_int tphp_class_Channel_capacity(tphp_class_Channel *self) {
    return self ? (t_int)self->capacity : 0;
}


/* ════════════════════════════════════════════════════════════
   Future 类
   ════════════════════════════════════════════════════════════ */

/* Future 状态 */
enum {
    _TP_FUTURE_PENDING  = 0,
    _TP_FUTURE_RESOLVED = 1,
    _TP_FUTURE_REJECTED = 2,
};

typedef struct {
    t_object    _obj;
    mtx_t       mtx;
    cnd_t       done;
    t_var       result;       /* resolve 后的值 */
    t_var       error;        /* reject 后的异常对象（tphp_class_Exception*） */
    int         state;        /* _TP_FUTURE_* */
} tphp_class_Future;

/* 前向声明 */
static void tphp_class_Future___destruct(tphp_class_Future *self);

static void* _vtable_tphp_class_Future[1] = { NULL };
static const t_class _class_tphp_class_Future = {
    .name          = "Future",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Future),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_Future___destruct,
    .vtable        = _vtable_tphp_class_Future,
    .vtable_len    = 0,
};

/** Future::create(): Future — 创建未完成的 Future */
static inline tphp_class_Future* tphp_class_Future_create(void) {
    tphp_class_Future *self = (tphp_class_Future*)tp_obj_alloc(&_class_tphp_class_Future);
    if (self == NULL) { tp_throw("Future::create(): out of memory"); return NULL; }
    mtx_init(&self->mtx, mtx_plain);
    cnd_init(&self->done);
    self->state  = _TP_FUTURE_PENDING;
    self->result = VAR_NULL();
    self->error  = VAR_NULL();
    return self;
}

static void tphp_class_Future___destruct(tphp_class_Future *self) {
    if (self == NULL) return;
    _arr_val_release(self->result);
    _arr_val_release(self->error);
    mtx_destroy(&self->mtx);
    cnd_destroy(&self->done);
}

/** resolve(mixed $v): void — 完成 Future（只能一次） */
static inline void tphp_class_Future_resolve(tphp_class_Future *self, t_var v) {
    if (self == NULL) { tp_throw("Future::resolve(): null future"); return; }
    mtx_lock(&self->mtx);
    if (self->state != _TP_FUTURE_PENDING) {
        mtx_unlock(&self->mtx);
        tp_throw("Future::resolve(): already settled");
        return;
    }
    self->result = _arr_val_retain(v);
    self->state  = _TP_FUTURE_RESOLVED;
    cnd_broadcast(&self->done);
    mtx_unlock(&self->mtx);
}

/** reject(Exception $e): void — 拒绝 Future（只能一次） */
static inline void tphp_class_Future_reject(tphp_class_Future *self, t_var err_obj) {
    if (self == NULL) { tp_throw("Future::reject(): null future"); return; }
    mtx_lock(&self->mtx);
    if (self->state != _TP_FUTURE_PENDING) {
        mtx_unlock(&self->mtx);
        tp_throw("Future::reject(): already settled");
        return;
    }
    self->error = _arr_val_retain(err_obj);
    self->state = _TP_FUTURE_REJECTED;
    cnd_broadcast(&self->done);
    mtx_unlock(&self->mtx);
}

/** await(): mixed — 阻塞等待，reject 则抛异常 */
static inline t_var tphp_class_Future_await(tphp_class_Future *self) {
    if (self == NULL) { tp_throw("Future::await(): null future"); return VAR_NULL(); }
    /* spin-then-wait */
    for (int i = 0; i < TPHP_CHAN_SPIN; i++) {
        if (self->state != _TP_FUTURE_PENDING) break;
    }
    if (self->state == _TP_FUTURE_PENDING) {
        mtx_lock(&self->mtx);
        while (self->state == _TP_FUTURE_PENDING) {
            cnd_wait(&self->done, &self->mtx);
        }
        mtx_unlock(&self->mtx);
        thrd_yield();
    }
    if (self->state == _TP_FUTURE_REJECTED) {
        /* 抛出原始异常对象 */
        void *ex_obj = self->error.value._object;
        tp_throw_ex(ex_obj);
        return VAR_NULL();
    }
    return self->result;
}

/** isReady(): bool — 无锁原子读 */
static inline t_bool tphp_class_Future_isReady(tphp_class_Future *self) {
    return self ? (t_bool)(self->state != _TP_FUTURE_PENDING) : false;
}

/** isRejected(): bool — 无锁原子读 */
static inline t_bool tphp_class_Future_isRejected(tphp_class_Future *self) {
    return self ? (t_bool)(self->state == _TP_FUTURE_REJECTED) : false;
}

/* ── then/catch 实现 ──
 * then(cb): 创建新 Future，原 Future resolve 时调 cb(v) → resolve 新 Future
 * catch(cb): 创建新 Future，原 Future reject 时调 cb(e) → resolve 新 Future
 *
 * 实现策略：spin 轮询原 Future 状态（简单可靠，避免回调注册的内存管理复杂性）
 *   注意：then/catch 返回新 Future，需要在新 Future 上 await 才能获取结果
 *   回调在 await 时惰性执行（而非 resolve 时主动触发），简化线程安全 */

/** then(callable $cb): Future — 链式回调 */
static inline tphp_class_Future* tphp_class_Future_then(tphp_class_Future *self, t_callback cb) {
    if (self == NULL) { tp_throw("Future::then(): null future"); return NULL; }
    tphp_class_Future *next = tphp_class_Future_create();
    if (next == NULL) return NULL;
    /* 等待原 Future 完成（不抛异常，以便能透传 reject） */
    for (int i = 0; i < TPHP_CHAN_SPIN; i++) {
        if (self->state != _TP_FUTURE_PENDING) break;
    }
    if (self->state == _TP_FUTURE_PENDING) {
        mtx_lock(&self->mtx);
        while (self->state == _TP_FUTURE_PENDING) {
            cnd_wait(&self->done, &self->mtx);
        }
        mtx_unlock(&self->mtx);
        thrd_yield();
    }
    if (self->state == _TP_FUTURE_REJECTED) {
        /* 原 Future 被 reject — 透传错误到 next */
        tphp_class_Future_reject(next, self->error);
    } else {
        /* resolve — 调用回调 */
        t_var (*fn)(t_var, void*) = (t_var(*)(t_var, void*))cb.func;
        t_var result = fn(self->result, cb.env);
        tphp_class_Future_resolve(next, result);
        _arr_val_release(result);
    }
    return next;
}

/** catch(callable $cb): Future — 错误恢复回调 */
static inline tphp_class_Future* tphp_class_Future_catch(tphp_class_Future *self, t_callback cb) {
    if (self == NULL) { tp_throw("Future::catch(): null future"); return NULL; }
    tphp_class_Future *next = tphp_class_Future_create();
    if (next == NULL) return NULL;
    /* 等待原 Future 完成（不抛异常，以便能恢复 reject） */
    for (int i = 0; i < TPHP_CHAN_SPIN; i++) {
        if (self->state != _TP_FUTURE_PENDING) break;
    }
    if (self->state == _TP_FUTURE_PENDING) {
        mtx_lock(&self->mtx);
        while (self->state == _TP_FUTURE_PENDING) {
            cnd_wait(&self->done, &self->mtx);
        }
        mtx_unlock(&self->mtx);
        thrd_yield();
    }
    if (self->state == _TP_FUTURE_REJECTED) {
        /* reject — 调用恢复回调 */
        t_var (*fn)(t_var, void*) = (t_var(*)(t_var, void*))cb.func;
        t_var result = fn(self->error, cb.env);
        tphp_class_Future_resolve(next, result);
        _arr_val_release(result);
    } else {
        /* resolve — 透传结果 */
        tphp_class_Future_resolve(next, self->result);
    }
    return next;
}

/* ── Future::all / race ── */

/** Future::all(array<Future> $futures): Future<array> — 等待全部完成 */
static inline tphp_class_Future* tphp_class_Future_all(t_array *futures) {
    if (futures == NULL) { tp_throw("Future::all(): null array"); return NULL; }
    tphp_class_Future *result = tphp_class_Future_create();
    if (result == NULL) return NULL;
    int n = futures->length;
    if (n == 0) {
        tphp_class_Future_resolve(result, VAR_ARRAY(tphp_fn_arr_create(0)));
        return result;
    }
    /* 顺序等待所有子 Future 完成（不抛异常），任一 reject 则整体 reject */
    t_array *out = tphp_fn_arr_create(n);
    for (int i = 0; i < n; i++) {
        tphp_class_Future *f = (tphp_class_Future*)futures->entries[i].val.value._object;
        /* 非抛出式等待 */
        for (int s = 0; s < TPHP_CHAN_SPIN; s++) {
            if (f->state != _TP_FUTURE_PENDING) break;
        }
        if (f->state == _TP_FUTURE_PENDING) {
            mtx_lock(&f->mtx);
            while (f->state == _TP_FUTURE_PENDING) {
                cnd_wait(&f->done, &f->mtx);
            }
            mtx_unlock(&f->mtx);
        }
        if (f->state == _TP_FUTURE_REJECTED) {
            tphp_class_Future_reject(result, f->error);
            tphp_fn_arr_free(out);
            return result;
        }
        out = tphp_fn_arr_push(out, f->result);
    }
    tphp_class_Future_resolve(result, VAR_ARRAY(out));
    tphp_fn_arr_free(out);
    return result;
}

/** Future::race(array<Future> $futures): Future — 等待第一个完成 */
static inline tphp_class_Future* tphp_class_Future_race(t_array *futures) {
    if (futures == NULL) { tp_throw("Future::race(): null array"); return NULL; }
    tphp_class_Future *result = tphp_class_Future_create();
    if (result == NULL) return NULL;
    int n = futures->length;
    /* spin 检查是否有已完成的 */
    for (int spin = 0; spin < TPHP_CHAN_SPIN; spin++) {
        for (int i = 0; i < n; i++) {
            tphp_class_Future *f = (tphp_class_Future*)futures->entries[i].val.value._object;
            if (f->state != _TP_FUTURE_PENDING) {
                if (f->state == _TP_FUTURE_REJECTED) {
                    tphp_class_Future_reject(result, f->error);
                } else {
                    tphp_class_Future_resolve(result, f->result);
                }
                return result;
            }
        }
    }
    /* 全部 pending — 顺序 await 第一个完成的 */
    for (int i = 0; i < n; i++) {
        tphp_class_Future *f = (tphp_class_Future*)futures->entries[i].val.value._object;
        if (f->state != _TP_FUTURE_PENDING) {
            if (f->state == _TP_FUTURE_REJECTED) {
                tphp_class_Future_reject(result, f->error);
            } else {
                tphp_class_Future_resolve(result, f->result);
            }
            return result;
        }
    }
    /* 没有提前完成的 — 轮询所有 Future，等待任意一个完成 */
    while (n > 0) {
        for (int i = 0; i < n; i++) {
            tphp_class_Future *f = (tphp_class_Future*)futures->entries[i].val.value._object;
            if (f->state != _TP_FUTURE_PENDING) {
                if (f->state == _TP_FUTURE_REJECTED) {
                    tphp_class_Future_reject(result, f->error);
                } else {
                    tphp_class_Future_resolve(result, f->result);
                }
                return result;
            }
        }
        /* 所有 Future 仍 pending — 让出 CPU 避免忙等浪费 */
        thrd_yield();
    }
    return result;
}


/* ════════════════════════════════════════════════════════════
   chan_select — 多通道多路复用
   ════════════════════════════════════════════════════════════ */

/** chan_select(array<Channel> $channels, int $timeout_ms = -1): int
 *  返回第一个可读 Channel 的索引（0-based）
 *  超时返回 -1，全部关闭返回 -2 */
static inline t_int tphp_fn_chan_select(t_array *channels, t_int timeout_ms) {
    if (channels == NULL || channels->length == 0) return -1;
    int n = channels->length;

    /* 计算超时 deadline */
    int has_deadline = (timeout_ms >= 0);
    struct timespec deadline = {0, 0};
    if (has_deadline) {
#if defined(_TTHREAD_WIN32_)
        struct timespec now;
        clock_gettime(0, &now);
#else
        struct timespec now;
        clock_gettime(CLOCK_REALTIME, &now);
#endif
        deadline.tv_sec  = now.tv_sec + (time_t)(timeout_ms / 1000);
        deadline.tv_nsec = now.tv_nsec + (long)((timeout_ms % 1000) * 1000000L);
        if (deadline.tv_nsec >= 1000000000L) {
            deadline.tv_sec++;
            deadline.tv_nsec -= 1000000000L;
        }
    }

    for (;;) {
        int all_closed = 1;
        for (int i = 0; i < n; i++) {
            tphp_class_Channel *ch = (tphp_class_Channel*)channels->entries[i].val.value._object;
            if (ch == NULL) continue;
            if (!ch->is_closed) all_closed = 0;
            if (ch->count > 0) return (t_int)i;  /* 有数据可读 */
        }
        if (all_closed) return -2;  /* 全部关闭 */

        /* 检查超时 */
        if (has_deadline) {
#if defined(_TTHREAD_WIN32_)
            struct timespec now;
            clock_gettime(0, &now);
#else
            struct timespec now;
            clock_gettime(CLOCK_REALTIME, &now);
#endif
            if (now.tv_sec > deadline.tv_sec ||
                (now.tv_sec == deadline.tv_sec && now.tv_nsec >= deadline.tv_nsec)) {
                return -1;  /* 超时 */
            }
        }

        thrd_yield();  /* 避免空转 */
    }
}
