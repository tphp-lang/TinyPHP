#pragma once
// ============================================================
// conv.h — 进制转换 + 数字格式化
// 纯栈计算，零堆分配（除 number_format 输出字符串）
// ============================================================

#ifndef _USE_MATH_DEFINES
#define _USE_MATH_DEFINES
#endif
#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <math.h>

/* TCC pow fallback for small integer exponents */
#ifdef __TINYC__
static inline double _tphp_pow10(t_int exp) {
    double r = 1.0;
    for (t_int i = 0; i < exp && i < 20; i++) r *= 10.0;
    return r;
}
#else
static inline double _tphp_pow10(t_int exp) { return pow(10.0, (double)exp); }
#endif
#include "types.h"

// 前向声明
static inline char* str_pool_alloc(int len);

// ── 进制转换：PHP 字符串 → int ──────────────────
// bindec/octdec 复用 strtol，hexdec 用 strtoull 防溢出

static inline t_int tphp_fn_bindec(t_string s) {
    if (s.data == NULL || s.length == 0) return 0;
    return (t_int)strtoll(STR_PTR(s), NULL, 2);
}

static inline t_int tphp_fn_hexdec(t_string s) {
    if (s.data == NULL || s.length == 0) return 0;
    return (t_int)strtoll(STR_PTR(s), NULL, 16);
}

static inline t_int tphp_fn_octdec(t_string s) {
    if (s.data == NULL || s.length == 0) return 0;
    return (t_int)strtoll(STR_PTR(s), NULL, 8);
}

// ── 进制转换：int → PHP 字符串 ──────────────────
// decbin < 64 位 → 栈缓冲写二进制

static inline t_string tphp_fn_decbin(t_int n) {
    char *buf = str_pool_alloc(72);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int pos = 0;
    uint64_t v = (uint64_t)n;
    if (v == 0) { buf[0] = '0'; buf[1] = '\0'; return (t_string){buf, 1}; }
    while (v > 0) { buf[pos++] = (char)('0' + (v & 1)); v >>= 1; }
    buf[pos] = '\0';
    // 反转
    for (int i = 0, j = pos - 1; i < j; i++, j--) {
        char t = buf[i]; buf[i] = buf[j]; buf[j] = t;
    }
    return (t_string){buf, pos};
}

static inline t_string tphp_fn_decoct(t_int n) {
    char *buf = str_pool_alloc(32);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = snprintf(buf, 32, "%llo", (unsigned long long)(uint64_t)n);
    return (t_string){buf, len > 0 ? len : 0};
}

static inline t_string tphp_fn_dechex(t_int n) {
    char *buf = str_pool_alloc(32);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = snprintf(buf, 32, "%llx", (unsigned long long)(uint64_t)n);
    return (t_string){buf, len > 0 ? len : 0};
}

// ── number_format($num, $decimals) ─────────────────
// 与 PHP 行为一致：. ± 千分位逗号
static inline t_string tphp_fn_number_format2(t_float num, t_int decimals) {
    char *buf = str_pool_alloc(128);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (decimals < 0) decimals = 0;
    if (decimals > 50) decimals = 50;

    // 处理负数
    bool neg = (num < 0);
    if (neg) num = -num;

    // 舍入（正负号统一处理）
    t_float scale = 1.0;
    if (decimals > 0) { scale = _tphp_pow10(decimals); }
    t_float round = num * scale + (neg ? -0.5 : 0.5);
    int64_t ival = (int64_t)round;
    int64_t dpart = ival;
    int64_t div = 1;
    for (t_int i = 0; i < decimals; i++) { dpart /= 10; div *= 10; }
    int64_t remained = ival % div;

    // 写整数部分（带千分位）
    int pos = 0;
    if (neg) buf[pos++] = '-';
    // 转为字符串（反向）
    char intstr[48]; int ip = 0;
    if (dpart == 0) { intstr[ip++] = '0'; }
    while (dpart > 0) { intstr[ip++] = (char)('0' + (dpart % 10)); dpart /= 10; }
    // 第一组大小 = 总长度 % 3（0 则用 3）
    int first = ip % 3;
    if (first == 0) first = 3;
    for (int i = ip - 1, cnt = 0; i >= 0; i--, cnt++) {
        if (cnt == first) { buf[pos++] = ','; first = 3; cnt = 0; }  // 后续组均为 3
        buf[pos++] = intstr[i];
    }
    // 小数部分
    if (decimals > 0) {
        buf[pos++] = '.';
        for (t_int i = 0; i < decimals; i++) {
            int64_t d = 1;
            for (t_int j = 0; j < decimals - 1 - i; j++) d *= 10;
            buf[pos++] = (char)('0' + ((remained / d) % 10));
        }
    }
    buf[pos] = '\0';
    return (t_string){buf, pos};
}

// ── number_format($num) — 默认 0 位小数 ───────────
static inline t_string tphp_fn_number_format(t_float num) {
    return tphp_fn_number_format2(num, 0);
}
