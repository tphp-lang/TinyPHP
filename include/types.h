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

#define null ((void *)0)

// ============================================================
// 2. string
// ============================================================
typedef struct {
    char *data;
    int   length;
} t_string;

// ============================================================
// 前向声明
// ============================================================
typedef struct _t_var   t_var;
typedef struct _t_array t_array;
typedef struct _ClassVTable ClassVTable;

// ============================================================
// 3. callback / closure
// ============================================================
typedef struct {
    void *func;
    void *env;
} t_callback;

// ============================================================
// 4. object — 对象系统
// ============================================================
typedef struct {
    const ClassVTable *vtable;
    int   refcount;
} t_object;

struct _ClassVTable {
    const char *name;
    int   type_id;
    void (*dtor)(void *self);
};

// ============================================================
// 5. t_value — 值联合体
// ============================================================
typedef union {
    t_int       _int;
    t_float     _float;
    t_bool      _bool;
    t_string    _string;
    t_array    *_array;
    t_object    _object;
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
    t_arr_entry entries[];  // flexible array member
};
