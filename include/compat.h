#pragma once
// ============================================================
// compat.h — 编译器兼容层
// TCC 的 <math.h> 可能不声明部分 C89/C99 函数（链接时能找到）
// 此头文件提供声明 + 缺失函数的 fallback 实现
// 必须在所有使用 math.h 的头文件之前被 include
// ============================================================

#include <stdlib.h>

#ifdef __TINYC__

/* ── 声明：TCC 可能未声明的 math 函数 ── */
double ceil(double);
double floor(double);
double sqrt(double);
double pow(double, double);
double fabs(double);
double round(double);

/* ── round — TCC 库中无此函数，自行实现 ── */
static inline double _tphp_round(double x) {
    double r;
    if (x >= 0.0) { r = (double)((long long)(x + 0.5)); }
    else          { r = (double)((long long)(x - 0.5)); }
    return r;
}
#define round(x) _tphp_round(x)

#endif /* __TINYC__ */
