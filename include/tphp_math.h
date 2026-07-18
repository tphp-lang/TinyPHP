#pragma once
// ============================================================
// math.h — 数学扩展函数 (pi, deg2rad, rad2deg, intdiv, pow, …)
// 全零堆分配，直接封装 libc math
// ============================================================

#ifndef _USE_MATH_DEFINES
#define _USE_MATH_DEFINES
#endif
#include <math.h>
#include "types.h"

#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif

// ── pi() — 返回圆周率常量 ──────────────────────────────────
static inline t_float tphp_fn_pi(void) {
    return M_PI;
}

// ── deg2rad($deg) / rad2deg($rad) — 角度 ↔ 弧度 ────────────
static inline t_float tphp_fn_deg2rad(t_float deg) {
    return deg * (M_PI / 180.0);
}

static inline t_float tphp_fn_rad2deg(t_float rad) {
    return rad * (180.0 / M_PI);
}

// ── intdiv($a, $b) — 整数除法（零除 → tp_throw） ───────────
// NOTE: tp_throw defined in object/try.h (included before math.h in common.h)

static inline t_int tphp_fn_intdiv(t_int a, t_int b) {
    if (unlikely(b == 0)) {
        tp_throw("Division by zero");
        return 0;
    }
    return a / b;
}

// ── pow($base, $exp) — 幂函数（独立调用版） ─────────────────
// 整数指数走自研 O(log n)，浮点走 libc pow
static inline t_int  tphp_rt_pow_int(t_int base, t_int exp);
static inline t_float tphp_rt_pow_float(t_float base, t_float exp);

static inline t_var tphp_fn_pow(t_var base, t_var exp) {
    // 整数底+非负整数指数 → 整数结果；负指数必须走 float 路径（PHP 语义）
    if (base.type == TYPE_INT && exp.type == TYPE_INT && exp.value._int >= 0) {
        return (t_var){.type = TYPE_INT, .value._int = tphp_rt_pow_int(base.value._int, exp.value._int)};
    }
    t_float b = (base.type == TYPE_INT)  ? (t_float)base.value._int  : base.value._float;
    t_float e = (exp.type  == TYPE_INT)  ? (t_float)exp.value._int   : exp.value._float;
    return (t_var){.type = TYPE_FLOAT, .value._float = tphp_rt_pow_float(b, e)};
}

// ── abs($x) — 绝对值（int → int, float → float） ───────────
static inline t_int   tphp_fn_abs_int(t_int x)   { return x < 0 ? -x : x; }
static inline t_float tphp_fn_abs_float(t_float x) { return x < 0 ? -x : x; }
