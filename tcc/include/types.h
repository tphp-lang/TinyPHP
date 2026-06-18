#pragma once

#include <stdint.h>
#include <stdbool.h>
#include <stddef.h>

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
// 3. PHP 万能数组（前向声明解决循环依赖）
//    有序映射，键: int | string，值: 任意类型，支持无限嵌套
// ============================================================

typedef struct _t_var   t_var;
typedef struct _t_array t_array;

// 数组条目：键值对（t_var 用前向声明的指针）
typedef struct {
    t_var     *key;      // TYPE_INT 或 TYPE_STRING（堆分配）
    t_var     *value;    // 任意类型（堆分配，嵌套数组用 t_array* 指针）
    int        hash;     // key 的 hash 缓存
} t_entry;

// 数组本体（堆分配，引用计数）
struct _t_array {
    t_entry *entries;
    int      length;
    int      capacity;
    int      refcount;
};

// ============================================================
// 4. callback / closure
// ============================================================
typedef struct {
    void *func;
    void *env;
} t_callback;

// ============================================================
// 5. object — 对象系统
// ============================================================
typedef struct _ClassVTable ClassVTable;

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
// 6. t_value — 值联合体
// ============================================================
typedef union {
    t_int       _int;
    t_float     _float;
    t_bool      _bool;
    t_string    _string;
    t_array    *_array;    // 指向堆分配的数组
    t_object    _object;
    t_callback  _callback;
} t_value;

// ============================================================
// 7. t_var — 带标签的值（堆分配 / 栈分配均可）
// ============================================================
struct _t_var {
    type_t  type;
    t_value value;
};
