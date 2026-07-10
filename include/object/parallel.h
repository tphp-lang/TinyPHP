#pragma once
// ============================================================
// parallel.h — 内置 Parallel 类（数据并行 API）
//
//   Parallel::for(int $n, callable $fn, int $threads = 0): void
//     并行执行 fn(0), fn(1), ..., fn(n-1) — 纯副作用
//     callback 签名: void fn(int $i)
//
//   Parallel::map(array $data, callable $fn, int $threads = 0): array
//     并行变换 int→int，返回结果数组
//     callback 签名: int fn(int $x)
//
//   设计约束（Thread-Local 运行时策略 A）：
//     1. 回调必须为纯函数 — 只使用参数，不修改捕获的 env
//     2. map 回调只能返回值类型 (int/float/bool)，不能返回 string/array/object
//        （子线程分配的堆内存在子线程退出时被 cleanup 释放）
//     3. 回调的 env 由主线程 GC 追踪，子线程只读访问（主线程阻塞在 join）
//
//   实现要点：
//     - 按线程数均分输入范围为连续分片，各 worker 处理 [start, end)
//     - 线程创建失败时降级为内联执行（保证正确性）
//     - map 结果写入堆分配的 t_int 缓冲区，主线程 join 后收集到 t_array
// ============================================================

#include "object/object.h"
#include "compat/tinycthread.h"
#include "types.h"
#include "array.h"

/* ════════════════════════════════════════════════════════════
   Parallel::for  —  并行范围循环 (纯副作用)
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_callback cb;     /* 闭包副本 (func+env 指针，env 共享只读) */
    t_int      start;
    t_int      end;
} _parallel_for_ctx;

/* Parallel::for worker 入口：对 [start, end) 调用 fn(i) */
static int _parallel_for_worker(void *arg) {
    _parallel_for_ctx *ctx = (_parallel_for_ctx*)arg;
    void (*fn)(t_int, void*) = (void(*)(t_int, void*))ctx->cb.func;
    for (t_int i = ctx->start; i < ctx->end; i++) {
        fn(i, ctx->cb.env);
    }
    free(ctx);
    return 0;
}

/** Parallel::for(int $n, callable $fn, int $threads = 0): void */
static inline void tphp_class_Parallel_for(t_int n, t_callback fn, t_int threads) {
    if (n <= 0) return;
    int nthreads = (threads > 0) ? (int)threads : 4;
    if (nthreads > n) nthreads = (int)n;

    /* 单线程：直接内联执行，避免线程创建开销 */
    if (nthreads <= 1) {
        void (*f)(t_int, void*) = (void(*)(t_int, void*))fn.func;
        for (t_int i = 0; i < n; i++) f(i, fn.env);
        return;
    }

    thrd_t *handles = (thrd_t*)malloc(sizeof(thrd_t) * (size_t)nthreads);
    if (handles == NULL) {
        /* OOM — 降级为内联执行 */
        void (*f)(t_int, void*) = (void(*)(t_int, void*))fn.func;
        for (t_int i = 0; i < n; i++) f(i, fn.env);
        return;
    }

    t_int chunk = (n + nthreads - 1) / nthreads;
    int spawned = 0;
    for (int t = 0; t < nthreads; t++) {
        t_int start = (t_int)t * chunk;
        t_int end   = start + chunk;
        if (end > n) end = n;
        if (start >= n) break;

        _parallel_for_ctx *ctx = (_parallel_for_ctx*)malloc(sizeof(_parallel_for_ctx));
        if (ctx == NULL) break;
        ctx->cb    = fn;
        ctx->start = start;
        ctx->end   = end;

        if (thrd_create(&handles[spawned], _parallel_for_worker, ctx) == thrd_success) {
            spawned++;
        } else {
            /* 线程创建失败 — 内联执行此分片 */
            void (*f)(t_int, void*) = (void(*)(t_int, void*))fn.func;
            for (t_int i = start; i < end; i++) f(i, fn.env);
            free(ctx);
        }
    }

    /* 等待所有 worker 完成（主线程阻塞，保证 env 有效） */
    for (int t = 0; t < spawned; t++) {
        thrd_join(handles[t], NULL);
    }
    free(handles);
}

/** Parallel::for 默认参数重载 (threads=0 → 自动选择 4 线程) */
static inline void tphp_class_Parallel_for_1(t_int n, t_callback fn) {
    tphp_class_Parallel_for(n, fn, 0);
}

/* ════════════════════════════════════════════════════════════
   Parallel::map  —  并行 int→int 变换
   ════════════════════════════════════════════════════════════ */

typedef struct {
    t_callback cb;
    t_array   *input;    /* 主线程数组 (只读，主线程阻塞在 join) */
    t_int      start;
    t_int      end;
    t_int     *results;  /* 堆分配结果缓冲区 (按全局下标写入) */
} _parallel_map_ctx;

/* Parallel::map worker 入口：读 input[i]，调 fn，写 results[i] */
static int _parallel_map_worker(void *arg) {
    _parallel_map_ctx *ctx = (_parallel_map_ctx*)arg;
    t_int (*fn)(t_int, void*) = (t_int(*)(t_int, void*))ctx->cb.func;
    for (t_int i = ctx->start; i < ctx->end; i++) {
        t_int v = tphp_fn_arr_item_int(ctx->input, (int)i);
        ctx->results[i] = fn(v, ctx->cb.env);
    }
    free(ctx);
    return 0;
}

/** Parallel::map(array $data, callable $fn, int $threads = 0): array
 *  callback 签名: t_int fn(t_int $x)  —  int→int 变换
 *  限制: 回调必须为纯函数，返回值类型为 t_int (值类型，跨线程安全) */
static inline t_array* tphp_class_Parallel_map(t_array *data, t_callback fn, t_int threads) {
    if (data == NULL || data->length == 0) return tphp_fn_arr_create(0);
    int n = data->length;
    int nthreads = (threads > 0) ? (int)threads : 4;
    if (nthreads > n) nthreads = n;

    /* 堆分配结果缓冲区（不经过 arr_freelist，避免跨线程池污染） */
    t_int *results = (t_int*)malloc(sizeof(t_int) * (size_t)n);
    if (results == NULL) return NULL;

    /* 单线程：内联执行 */
    if (nthreads <= 1) {
        t_int (*f)(t_int, void*) = (t_int(*)(t_int, void*))fn.func;
        for (int i = 0; i < n; i++) {
            t_int v = tphp_fn_arr_item_int(data, i);
            results[i] = f(v, fn.env);
        }
    } else {
        thrd_t *handles = (thrd_t*)malloc(sizeof(thrd_t) * (size_t)nthreads);
        if (handles == NULL) {
            /* OOM — 降级为内联执行 */
            t_int (*f)(t_int, void*) = (t_int(*)(t_int, void*))fn.func;
            for (int i = 0; i < n; i++) {
                t_int v = tphp_fn_arr_item_int(data, i);
                results[i] = f(v, fn.env);
            }
        } else {
            t_int chunk = (n + nthreads - 1) / nthreads;
            int spawned = 0;
            for (int t = 0; t < nthreads; t++) {
                t_int start = (t_int)t * chunk;
                t_int end   = start + chunk;
                if (end > n) end = n;
                if (start >= n) break;

                _parallel_map_ctx *ctx = (_parallel_map_ctx*)malloc(sizeof(_parallel_map_ctx));
                if (ctx == NULL) break;
                ctx->cb      = fn;
                ctx->input   = data;
                ctx->start   = start;
                ctx->end     = end;
                ctx->results = results;

                if (thrd_create(&handles[spawned], _parallel_map_worker, ctx) == thrd_success) {
                    spawned++;
                } else {
                    /* 线程创建失败 — 内联执行此分片 */
                    t_int (*f)(t_int, void*) = (t_int(*)(t_int, void*))fn.func;
                    for (t_int i = start; i < end; i++) {
                        t_int v = tphp_fn_arr_item_int(data, (int)i);
                        results[i] = f(v, fn.env);
                    }
                    free(ctx);
                }
            }

            for (int t = 0; t < spawned; t++) {
                thrd_join(handles[t], NULL);
            }
            free(handles);
        }
    }

    /* 主线程收集结果到输出数组（使用主线程的 arr_freelist） */
    t_array *out = tphp_fn_arr_create(n);
    for (int i = 0; i < n; i++) {
        out = tphp_fn_arr_push(out, VAR_INT(results[i]));
    }
    free(results);
    return out;
}

/** Parallel::map 默认参数重载 (threads=0 → 自动选择 4 线程) */
static inline t_array* tphp_class_Parallel_map_1(t_array *data, t_callback fn) {
    return tphp_class_Parallel_map(data, fn, 0);
}
