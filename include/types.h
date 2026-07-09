#pragma once

#include <stdint.h>
#include <stddef.h>

/* stdbool.h — TCC on modern distros may not find it, define inline */
#ifndef __cplusplus
#ifndef bool
#define bool _Bool
#define true  1
#define false 0
#endif
#endif

/* branch prediction hints — TCC/GCC/Clang 均支持 */
#ifndef likely
#if defined(__GNUC__) || defined(__clang__) || defined(__TINYC__)
#define likely(x)   __builtin_expect(!!(x), 1)
#define unlikely(x) __builtin_expect(!!(x), 0)
#else
#define likely(x)   (x)
#define unlikely(x) (x)
#endif
#endif

// 数组复用池上限（runtime.h 与 array.h 共用）
#ifndef ARR_POOL_MAX
#define ARR_POOL_MAX 128
#endif

// ============================================================
// 类型标记枚举
// ============================================================
typedef enum {
    TYPE_NULL     = 0,
    TYPE_INT      = 1,
    TYPE_FLOAT    = 2,
    TYPE_BOOL     = 3,
    TYPE_STRING   = 4,
    TYPE_ARRAY    = 5,
    TYPE_OBJECT   = 6,
    TYPE_CALLBACK = 7,
} type_t;

// ============================================================
// 1. 基础值类型
// ============================================================
typedef int64_t t_int;
typedef double  t_float;
typedef bool    t_bool;
// t_ptr: 通用 C 指针类型，用于 phpc 互操作
//   与 t_int（int64_t）区分，避免指针被当作整数参与算术运算
//   sizeof(t_ptr) == sizeof(void*) == 8（64 位平台）
typedef void* t_ptr;

#define null ((void *)0)

// ============================================================
// 2. string — SSO: ≤23 字节走内联缓冲区，零堆分配
// ============================================================
#define STR_SSO_MAX 23

typedef struct {
    union {
        char *data;              // heap/pool pointer (when !is_local && !is_lit)
        char  local[STR_SSO_MAX+1]; // SSO inline buffer (when is_local)
    };
    int   length;
    bool  is_local;
    bool  is_lit;                // true for .rodata string literals (STR_LIT) — never free()
} t_string;

// ── SSO 零开销访问器 ────────────────────────────────────
// STR_PTR(s):    从 t_string 值获取只读数据指针
// STR_PTR_P(p):  从 t_string* 获取只读数据指针
// STR_PTR_V(v):  从 t_string 值（非左值表达式）获取只读指针
// STR_MUT_P(p):  从 t_string* 获取可写数据指针
#define STR_PTR(s)   ((const char*)((s).is_local ? (s).local : (s).data))
#define STR_PTR_P(p) ((const char*)((p)->is_local ? (p)->local : (p)->data))
#define STR_PTR_V(v) ((const char*)((v).is_local ? (v).local : (v).data))
#define STR_MUT_P(p) ((char*)((p)->is_local ? (p)->local : (p)->data))

// ============================================================
// 前向声明
// ============================================================
typedef struct _t_var   t_var;
typedef struct _t_array t_array;
// ── Forward declarations for object system (in include/object/) ──
typedef struct _t_object t_object;
typedef struct _t_class  t_class;

// ============================================================
// 3. callback / closure
// ============================================================
typedef struct {
    void *func;
    void *env;
} t_callback;

// ============================================================
// 5. t_value — 值联合体
// ============================================================
typedef union {
    t_int       _int;
    t_float     _float;
    t_bool      _bool;
    t_string    _string;
    t_array    *_array;
    void       *_object;     // pointer to tphp_class_X (variable-size, supports inheritance)
    t_callback  _callback;
    void       *_ptr;
} t_value;

// ============================================================
// 6. t_var — 带标签的值
// ============================================================
struct _t_var {
    type_t  type;
    t_value value;
};

// ============================================================
// 7. PHP 万能数组 — yyjson 风格 flat memory
// ============================================================
typedef struct {
    t_var key;   // TYPE_INT = int key, TYPE_STRING = string key
    t_var val;
} t_arr_entry;

struct _t_array {
    int length;
    int capacity;
    int refcount;
    int cursor;             // internal pointer for current/next/prev/end/reset
    void *str_index;        // pointer-free hash index for O(1) string-key lookup (NULL if none)
    void *int_index;        // hash index for O(1) sparse int-key lookup (NULL if none)
    t_arr_entry entries[];  // flexible array member
};
