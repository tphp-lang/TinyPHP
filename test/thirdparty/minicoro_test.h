#pragma once

/*
 * minicoro integration test wrapper for TinyPHP AOT.
 *
 * This header compiles minicoro's implementation (via MINICORO_IMPL) into
 * TinyPHP's single translation unit, and exposes two simple test functions
 * that can be called from PHP via the C-> raw call mechanism.
 *
 * Platform selection (auto-detected by minicoro.h):
 *   Windows + GCC/Clang (x86_64) → ASM context switch
 *   Windows + TCC/MSVC           → Win32 Fiber (fallback)
 *   Linux   + GCC/Clang (x86_64/aarch64) → ASM
 *   Linux   + TCC                 → ucontext (fallback)
 *   macOS   + Clang (x86_64/arm64) → ASM
 */

/*
 * TCC compatibility: its bundled kernel32.def lacks CreateFiberEx.
 * On x86_64 Windows, the OS always preserves FP state across fiber
 * switches, so FIBER_FLAG_FLOAT_SWITCH is redundant — we can safely
 * remap CreateFiberEx to the older CreateFiber.
 * Only applies to non-GCC compilers on Windows (i.e., TCC);
 * GCC/Clang keep the original CreateFiberEx.
 */
#if defined(_WIN32) && !defined(__GNUC__) && !defined(_MSC_VER)
  #define CreateFiberEx(commit, reserve, flags, fn, param) \
      CreateFiber((reserve), (fn), (param))
#endif

/* Suppress minicoro debug logging — it writes to stdout and would
 * interfere with TinyPHP's #debug output comparison. */
#define MCO_NO_DEBUG

#define MINICORO_IMPL
#include "minicoro.h"

#include <stdint.h>

/* ── Test 1: yield 1,2,3,4,5 and return their sum (15) ──────────────── */

static void mco_test_basic_entry(mco_coro* co) {
    for (int i = 1; i <= 5; i++) {
        mco_push(co, &i, sizeof(int));
        mco_yield(co);
    }
}

int64_t mco_test_basic(void) {
    mco_desc desc = mco_desc_init(mco_test_basic_entry, 0);
    mco_coro* co = NULL;
    mco_result res = mco_create(&co, &desc);
    if (res != MCO_SUCCESS) return -1;

    int64_t sum = 0;
    while (mco_status(co) == MCO_SUSPENDED) {
        res = mco_resume(co);
        if (res != MCO_SUCCESS) break;
        int val = 0;
        if (mco_get_bytes_stored(co) >= sizeof(int)) {
            mco_pop(co, &val, sizeof(int));
            sum += val;
        }
    }
    mco_destroy(co);
    return sum;
}

/* ── Test 2: bidirectional — send 5 numbers, receive doubled values ─── */
/* Inputs: 1,2,3,4,5 → Outputs: 2,4,6,8,10 → Sum = 30                   */

static void mco_test_double_entry(mco_coro* co) {
    for (;;) {
        int input = 0;
        if (mco_pop(co, &input, sizeof(int)) != MCO_SUCCESS) break;
        int output = input * 2;
        mco_push(co, &output, sizeof(int));
        mco_yield(co);
    }
}

int64_t mco_test_bidirectional(void) {
    mco_desc desc = mco_desc_init(mco_test_double_entry, 0);
    mco_coro* co = NULL;
    mco_result res = mco_create(&co, &desc);
    if (res != MCO_SUCCESS) return -1;

    int64_t sum = 0;
    int inputs[5] = {1, 2, 3, 4, 5};
    for (int i = 0; i < 5; i++) {
        mco_push(co, &inputs[i], sizeof(int));
        mco_resume(co);
        int output = 0;
        if (mco_pop(co, &output, sizeof(int)) == MCO_SUCCESS) {
            sum += output;
        }
    }
    /* Coroutine is suspended after the last yield — mco_destroy
     * accepts suspended coroutines, no need for a final resume. */
    mco_destroy(co);
    return sum;
}

/* ── Test 3: coroutine state transitions ─────────────────────────────── */
/* Returns: 100*create_status + 10*first_yield_status + final_status      */
/* MCO_SUSPENDED=3, MCO_DEAD=0 → 3*100 + 3*10 + 0 = 330                   */

int64_t mco_test_states(void) {
    mco_desc desc = mco_desc_init(mco_test_basic_entry, 0);
    mco_coro* co = NULL;
    mco_result res = mco_create(&co, &desc);
    if (res != MCO_SUCCESS) return -1;

    int64_t state_after_create = (int64_t)mco_status(co);  /* SUSPENDED = 3 */
    mco_resume(co);  /* runs to first yield */
    int64_t state_after_yield = (int64_t)mco_status(co);   /* SUSPENDED = 3 */

    /* drain remaining yields */
    while (mco_status(co) == MCO_SUSPENDED) {
        mco_resume(co);
    }
    int64_t state_final = (int64_t)mco_status(co);         /* DEAD = 0 */

    mco_destroy(co);
    return state_after_create * 100 + state_after_yield * 10 + state_final;
}
