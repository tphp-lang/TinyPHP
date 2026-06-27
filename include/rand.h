#pragma once
// ============================================================
// rand.h — TinyPHP 随机数函数
// ============================================================

#include <stdlib.h>

static inline t_int tphp_fn_rand(t_int min, t_int max) {
    if (min > max) {
        tphp_rt_free_all();
        fputs("\nFatal error: rand(): min must be <= max\n\n", stderr);
        exit(1);
    }
    return min + (t_int)(rand() % (max - min + 1));
}

// 别名（array_rand/uniqid 等内部使用）
static inline t_int tphp_fn_rand_int(t_int min, t_int max) {
    return tphp_fn_rand(min, max);
}

/* ─── Mersenne Twister MT19937 ──────────────────────────── */

#define MT_N 624
#define MT_M 397

static uint32_t mt_state[MT_N];
static int      mt_index = MT_N + 1;
static bool     mt_seeded = false;

static inline void tphp_fn_mt_srand(uint32_t seed) {
    mt_state[0] = seed;
    for (int i = 1; i < MT_N; i++)
        mt_state[i] = (1812433253UL * (mt_state[i - 1] ^ (mt_state[i - 1] >> 30)) + (uint32_t)i);
    mt_index = MT_N;
    mt_seeded = true;
}

static inline uint32_t tphp_fn_mt_rand32(void) {
    if (!mt_seeded) tphp_fn_mt_srand((uint32_t)((uintptr_t)&mt_state ^ (uintptr_t)time(NULL)));
    if (mt_index >= MT_N) {
        for (int i = 0; i < MT_N; i++) {
            uint32_t y = (mt_state[i] & 0x80000000UL) | (mt_state[(i + 1) % MT_N] & 0x7FFFFFFFUL);
            mt_state[i] = mt_state[(i + MT_M) % MT_N] ^ (y >> 1) ^ ((y & 1) ? 0x9908B0DFUL : 0);
        }
        mt_index = 0;
    }
    uint32_t y = mt_state[mt_index++];
    y ^= (y >> 11);
    y ^= (y << 7) & 0x9D2C5680UL;
    y ^= (y << 15) & 0xEFC60000UL;
    y ^= (y >> 18);
    return y;
}

static inline t_int tphp_fn_mt_rand(t_int min, t_int max) {
    if (min > max) {
        tphp_rt_free_all();
        fputs("\nFatal error: mt_rand(): min must be <= max\n\n", stderr);
        exit(1);
    }
    return min + (t_int)(tphp_fn_mt_rand32() % ((uint32_t)(max - min + 1)));
}
