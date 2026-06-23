#pragma once
// ============================================================
// p2c.h — PHP ↔ C 互操作辅助函数
// ============================================================

#include <stdint.h>

// PHP → C 类型转换
static inline int32_t  c_int(t_int v)     { return (int32_t)v; }
static inline double   c_float(t_float v) { return (double)v; }
static inline const char* c_str(t_string v) { return v.data; }

// C → PHP 类型转换
static inline t_int   php_int(int32_t v)   { return (t_int)v; }
static inline t_float php_float(double v)  { return (t_float)v; }
static inline t_string php_str(const char* s) {
    return s ? tphp_rt_str_dup((t_string){(char*)s, (int)strlen(s)}) : (t_string){NULL, 0};
}
