#pragma once

#include <assert.h>
#include "types.h"

// ============================================================
// 便捷宏 — 快速构造 t_var
// ============================================================

// STR_LIT: C 字符串字面量 → t_string（.rodata 段, 零堆分配, 永不 free）
#define STR_LIT(s) \
    ((t_string){.data = (char *)(s), .length = (int)(sizeof(s) - 1), .is_local = false, .is_lit = true})

// 编译期检查：确保 STR_LIT 的参数不是指针
//   (void)sizeof(s) 在编译时求值，如果 s 是 char* 指针则 sizeof 为 8，非预期行为
//   这里用 _Static_assert 检测数组类型（GCC/Clang 支持）
#if defined(__GNUC__) || defined(__clang__)
#define STR_LIT_CHECK(s) \
    _Static_assert(!__builtin_types_compatible_p(typeof(s), char*), \
                   "STR_LIT: 禁止传入 char* 指针，请使用字符串字面量")
#else
#define STR_LIT_CHECK(s) ((void)0)
#endif

// 各类型 t_var 构造
#define VAR_INT(v)    ((t_var){.type = TYPE_INT,    .value._int    = (v)})
#define VAR_FLOAT(v)  ((t_var){.type = TYPE_FLOAT,  .value._float  = (v)})
#define VAR_BOOL(v)   ((t_var){.type = TYPE_BOOL,   .value._bool   = (v)})
#define VAR_STRING(s) ((t_var){.type = TYPE_STRING, .value._string = (s)})
#define VAR_ARRAY(a)  ((t_var){.type = TYPE_ARRAY,  .value._array  = (a)})
#define VAR_CALLBACK(c) ((t_var){.type = TYPE_CALLBACK, .value._callback = (c)})
#define VAR_OBJ(p)   ((t_var){.type = TYPE_OBJECT, .value = {._object = (p)}})
#define VAR_NULL()    ((t_var){.type = TYPE_NULL})

// ============================================================
// t_var → 具体类型取值宏（含默认值，内存安全）
//   mixed / union 类型变量的读取用
// ============================================================
#define VAR_AS_INT(v)    ((v).type == TYPE_INT    ? (v).value._int    : (t_int)0)
#define VAR_AS_FLOAT(v)  ((v).type == TYPE_FLOAT  ? (v).value._float  : (t_float)0.0)
#define VAR_AS_STRING(v) ((v).type == TYPE_STRING ? (v).value._string : ((t_string){.data = NULL, .length = 0, .is_local = false}))
#define VAR_AS_BOOL(v)   ((v).type == TYPE_BOOL   ? (v).value._bool   : false)
