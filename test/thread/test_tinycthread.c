// test_tinycthread.c — 独立编译测试优化的 tinycthread.h
// 验证 Thread / Mutex / CondVar / SpinLock / WaitGroup 功能正确性

#include "compat/tinycthread.h"
#include <stdio.h>
#include <string.h>
#include <assert.h>

/* ── 测试 1: Thread create + join ── */
static int thread_fn_basic(void *arg) {
    int *val = (int *)arg;
    *val = 42;
    return 0;
}

static void test_thread_basic(void) {
    thrd_t t;
    int val = 0;
    int ret = thrd_create(&t, thread_fn_basic, &val);
    assert(ret == thrd_success);
    int res;
    thrd_join(t, &res);
    assert(val == 42);
    printf("test_thread_basic: PASS\n");
}

/* ── 测试 2: Thread 返回值 ── */
static int thread_fn_retval(void *arg) {
    (void)arg;
    return 99;
}

static void test_thread_retval(void) {
    thrd_t t;
    thrd_create(&t, thread_fn_retval, NULL);
    int res = -1;
    thrd_join(t, &res);
    assert(res == 99);
    printf("test_thread_retval: PASS\n");
}

/* ── 测试 3: Mutex (non-recursive) ── */
static mtx_t g_mtx;
static int g_counter;

static int thread_fn_mutex(void *arg) {
    int iterations = *(int *)arg;
    for (int i = 0; i < iterations; i++) {
        mtx_lock(&g_mtx);
        g_counter++;
        mtx_unlock(&g_mtx);
    }
    return 0;
}

static void test_mutex(void) {
    mtx_init(&g_mtx, mtx_plain);
    g_counter = 0;
    int iterations = 10000;
    thrd_t threads[4];
    for (int i = 0; i < 4; i++) {
        thrd_create(&threads[i], thread_fn_mutex, &iterations);
    }
    for (int i = 0; i < 4; i++) {
        thrd_join(threads[i], NULL);
    }
    mtx_destroy(&g_mtx);
    assert(g_counter == 4 * 10000);
    printf("test_mutex: PASS (counter=%d)\n", g_counter);
}

/* ── 测试 4: Recursive Mutex ── */
static mtx_t g_rec_mtx;

static int recursive_helper(int depth) {
    if (depth <= 0) return 0;
    mtx_lock(&g_rec_mtx);
    int r = recursive_helper(depth - 1) + 1;
    mtx_unlock(&g_rec_mtx);
    return r;
}

static int thread_fn_rec_mutex(void *arg) {
    int depth = *(int *)arg;
    int r = recursive_helper(depth);
    return r;
}

static void test_recursive_mutex(void) {
    mtx_init(&g_rec_mtx, mtx_recursive);
    thrd_t t;
    int depth = 10;
    thrd_create(&t, thread_fn_rec_mutex, &depth);
    int res;
    thrd_join(t, &res);
    mtx_destroy(&g_rec_mtx);
    assert(res == 10);
    printf("test_recursive_mutex: PASS (depth=%d)\n", res);
}

/* ── 测试 5: CondVar (signal + wait) ── */
static mtx_t g_cnd_mtx;
static cnd_t g_cnd;
static int g_ready;

static int thread_fn_cnd_waiter(void *arg) {
    (void)arg;
    mtx_lock(&g_cnd_mtx);
    while (!g_ready) {
        cnd_wait(&g_cnd, &g_cnd_mtx);
    }
    g_ready = 0;
    mtx_unlock(&g_cnd_mtx);
    return 0;
}

static void test_condvar(void) {
    mtx_init(&g_cnd_mtx, mtx_plain);
    cnd_init(&g_cnd);
    g_ready = 0;

    thrd_t waiter;
    thrd_create(&waiter, thread_fn_cnd_waiter, NULL);

    /* 给 waiter 一点时间进入 wait */
    thrd_yield();
    thrd_sleep(&(struct timespec){0, 10000000}, NULL); /* 10ms */

    mtx_lock(&g_cnd_mtx);
    g_ready = 1;
    cnd_signal(&g_cnd);
    mtx_unlock(&g_cnd_mtx);

    thrd_join(waiter, NULL);

    cnd_destroy(&g_cnd);
    mtx_destroy(&g_cnd_mtx);
    printf("test_condvar: PASS\n");
}

/* ── 测试 6: CondVar broadcast (多 waiter) ── */
static cnd_t g_bcast_cnd;
static mtx_t g_bcast_mtx;
static int g_bcast_ready;
static int g_bcast_done_count;

static int thread_fn_bcast_waiter(void *arg) {
    (void)arg;
    mtx_lock(&g_bcast_mtx);
    while (!g_bcast_ready) {
        cnd_wait(&g_bcast_cnd, &g_bcast_mtx);
    }
    g_bcast_done_count++;
    mtx_unlock(&g_bcast_mtx);
    return 0;
}

static void test_condvar_broadcast(void) {
    mtx_init(&g_bcast_mtx, mtx_plain);
    cnd_init(&g_bcast_cnd);
    g_bcast_ready = 0;
    g_bcast_done_count = 0;

    thrd_t waiters[4];
    for (int i = 0; i < 4; i++) {
        thrd_create(&waiters[i], thread_fn_bcast_waiter, NULL);
    }
    thrd_sleep(&(struct timespec){0, 50000000}, NULL); /* 50ms */

    mtx_lock(&g_bcast_mtx);
    g_bcast_ready = 1;
    cnd_broadcast(&g_bcast_cnd);
    mtx_unlock(&g_bcast_mtx);

    for (int i = 0; i < 4; i++) {
        thrd_join(waiters[i], NULL);
    }
    assert(g_bcast_done_count == 4);
    cnd_destroy(&g_bcast_cnd);
    mtx_destroy(&g_bcast_mtx);
    printf("test_condvar_broadcast: PASS (woke=%d)\n", g_bcast_done_count);
}

/* ── 测试 7: SpinLock ── */
static tphp_spinlock_t g_spin;
static int g_spin_counter;

static int thread_fn_spinlock(void *arg) {
    int iterations = *(int *)arg;
    for (int i = 0; i < iterations; i++) {
        tphp_spin_lock(&g_spin);
        g_spin_counter++;
        tphp_spin_unlock(&g_spin);
    }
    return 0;
}

static void test_spinlock(void) {
    tphp_spin_init(&g_spin);
    g_spin_counter = 0;
    int iterations = 10000;
    thrd_t threads[4];
    for (int i = 0; i < 4; i++) {
        thrd_create(&threads[i], thread_fn_spinlock, &iterations);
    }
    for (int i = 0; i < 4; i++) {
        thrd_join(threads[i], NULL);
    }
    tphp_spin_destroy(&g_spin);
    assert(g_spin_counter == 4 * 10000);
    printf("test_spinlock: PASS (counter=%d)\n", g_spin_counter);
}

/* ── 测试 8: WaitGroup ── */
static tphp_wg_t g_wg;
static int g_wg_results[4];

static int thread_fn_wg(void *arg) {
    int idx = *(int *)arg;
    g_wg_results[idx] = idx * idx;
    thrd_sleep(&(struct timespec){0, 10000000}, NULL); /* 10ms */
    tphp_wg_done(&g_wg);
    return 0;
}

static void test_waitgroup(void) {
    tphp_wg_init(&g_wg);
    memset(g_wg_results, 0, sizeof(g_wg_results));

    int indices[4] = {0, 1, 2, 3};
    tphp_wg_add(&g_wg, 4);

    thrd_t threads[4];
    for (int i = 0; i < 4; i++) {
        thrd_create(&threads[i], thread_fn_wg, &indices[i]);
    }

    tphp_wg_wait(&g_wg);

    for (int i = 0; i < 4; i++) {
        thrd_join(threads[i], NULL);
        assert(g_wg_results[i] == i * i);
    }

    tphp_wg_destroy(&g_wg);
    printf("test_waitgroup: PASS (results=%d,%d,%d,%d)\n",
           g_wg_results[0], g_wg_results[1], g_wg_results[2], g_wg_results[3]);
}

/* ── 测试 9: mtx_trylock ── */
static void test_mtx_trylock(void) {
    mtx_t m;
    mtx_init(&m, mtx_plain);
    assert(mtx_trylock(&m) == thrd_success);
    assert(mtx_trylock(&m) == thrd_busy);
    mtx_unlock(&m);
    assert(mtx_trylock(&m) == thrd_success);
    mtx_unlock(&m);
    mtx_destroy(&m);
    printf("test_mtx_trylock: PASS\n");
}

/* ── 测试 10: thrd_detach ── */
static int thread_fn_detach(void *arg) {
    (void)arg;
    thrd_sleep(&(struct timespec){0, 50000000}, NULL); /* 50ms */
    return 0;
}

static void test_thrd_detach(void) {
    thrd_t t;
    thrd_create(&t, thread_fn_detach, NULL);
    int ret = thrd_detach(t);
    assert(ret == thrd_success);
    /* 不 join，等待线程自然结束 */
    thrd_sleep(&(struct timespec){0, 100000000}, NULL); /* 100ms */
    printf("test_thrd_detach: PASS\n");
}

/* ── main ── */
int main(void) {
    printf("=== TinyCThread Optimized Test Suite ===\n\n");

    test_thread_basic();
    test_thread_retval();
    test_mutex();
    test_recursive_mutex();
    test_condvar();
    test_condvar_broadcast();
    test_spinlock();
    test_waitgroup();
    test_mtx_trylock();
    test_thrd_detach();

    printf("\n=== ALL TESTS PASSED ===\n");
    return 0;
}
