#pragma once
// compat.h — 编译器兼容层

#ifndef _USE_MATH_DEFINES
#define _USE_MATH_DEFINES
#endif
#ifndef _CRT_RAND_S
#define _CRT_RAND_S  // 启用 rand_s() (Windows CRT)
#endif
#if defined(__linux__) && !defined(_GNU_SOURCE)
#define _GNU_SOURCE  // 启用 sincos() 等 GNU 扩展（GCC -O2 会将 sin+cos 合并为 sincos）
#endif

#include <stdlib.h>
#include <math.h>

#include "types.h"

/* ── 显式声明 math 函数（各编译器/平台的 <math.h> 可能不完整）── */
double ceil(double);
double floor(double);
double sqrt(double);
double pow(double, double);
double fabs(double);
double round(double);

/* ── isnan / isinf — MinGW/GCC 16 可能不声明 ── */
#ifndef isnan
#ifdef _WIN32
#define isnan(x) _isnan(x)
#else
int isnan(double);
#endif
#endif

#ifndef isinf
#ifdef _WIN32
#define isinf(x) (!_finite(x) && !_isnan(x))
#else
int isinf(double);
#endif
#endif

#ifdef __TINYC__
/* ── round — TCC 库中无此函数，自行实现 ── */
static inline double _tphp_round(double x) {
    double r;
    if (x >= 0.0) { r = (double)((long long)(x + 0.5)); }
    else          { r = (double)((long long)(x - 0.5)); }
    return r;
}
#undef round
#define round(x) _tphp_round(x)
#endif /* __TINYC__ */
