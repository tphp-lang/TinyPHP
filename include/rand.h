#pragma once
// rand.h — TinyPHP 随机数 (CSPRNG)

#include <stdlib.h>
#include <stdint.h>
#include <string.h>
#include <time.h>
#include "klib/krng.h"

static krng_t _tphp_rng;
static int    _tphp_rng_seeded = 0;

static inline void _tphp_rng_init(void) {
    if (_tphp_rng_seeded) return;
    unsigned char seed_buf[8] = {0};
    int ok = 0;
#if defined(_WIN32) && !defined(__clang__)
    unsigned int v = 0;
    if (rand_s(&v) == 0) { memcpy(seed_buf, &v, 4); ok = 1; }
#elif !defined(_WIN32)
    FILE *f = fopen("/dev/urandom", "rb");
    if (f) { fread(seed_buf, 1, 8, f); fclose(f); ok = 1; }
#endif
    uint64_t seed = ok ? *(uint64_t*)seed_buf : (uint64_t)time(NULL);
    kr_srand_r(&_tphp_rng, seed);
    _tphp_rng_seeded = 1;
}

// ── 快速范围随机 (krng) — rand / mt_rand / shuffle ──
static inline t_int tphp_fn_rand_int(t_int min, t_int max) {
    if (min > max) {
        tphp_rt_free_all();
        fputs("\nFatal error: rand(): min must be <= max\n\n", stderr);
        exit(1);
    }
    _tphp_rng_init();
    uint64_t range = (uint64_t)(max - min) + 1;
    return min + (t_int)(kr_rand_r(&_tphp_rng) % range);
}
static inline t_int tphp_fn_rand(t_int min, t_int max)    { return tphp_fn_rand_int(min, max); }
static inline t_int tphp_fn_mt_rand(t_int min, t_int max) { return tphp_fn_rand_int(min, max); }

// ── CSPRNG (安全) — random_int / random_bytes ──
static inline int _tphp_random_bytes(unsigned char* buf, size_t n) {
#if defined(_WIN32) && !defined(__clang__)
    size_t i = 0;
    while (i + 4 <= n) {
        unsigned int v = 0;
        if (rand_s(&v) != 0) return -1;
        buf[i]=(unsigned char)(v); buf[i+1]=(unsigned char)(v>>8);
        buf[i+2]=(unsigned char)(v>>16); buf[i+3]=(unsigned char)(v>>24);
        i+=4;
    }
    if (i<n) { unsigned int v=0; if(rand_s(&v)!=0) return -1; for(;i<n;i++){buf[i]=(unsigned char)(v);v>>=8;} }
    return 0;
#elif defined(_WIN32) && defined(__clang__)
    for (size_t i=0; i<n; i++) buf[i]=(unsigned char)(kr_rand_r(&_tphp_rng)&0xFF);
    return 0;
#else
    FILE* f=fopen("/dev/urandom","rb"); if(!f) return -1;
    size_t r=fread(buf,1,n,f); fclose(f); return (r==n)?0:-1;
#endif
}

static inline t_int tphp_fn_random_int(t_int min, t_int max) {
    if (min>max) { tphp_fn_error(STR_LIT("random_int(): min must be <= max"),"<php>",0); return 0; }
    uint64_t range=(uint64_t)(max-min)+1; unsigned char buf[8];
    if(_tphp_random_bytes(buf,8)!=0){tphp_rt_free_all();fputs("\nFatal error: CSPRNG failure\n\n",stderr);exit(1);}
    uint64_t val=((uint64_t)buf[0])|((uint64_t)buf[1]<<8)|((uint64_t)buf[2]<<16)|((uint64_t)buf[3]<<24)
               |((uint64_t)buf[4]<<32)|((uint64_t)buf[5]<<40)|((uint64_t)buf[6]<<48)|((uint64_t)buf[7]<<56);
    return min+(t_int)(val%range);
}
