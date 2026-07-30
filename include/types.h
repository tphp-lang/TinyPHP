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

/* _Thread_local 回退（pre-C11 编译器）— 用于线程独立内存池 */
#if !defined(__STDC_VERSION__) || (__STDC_VERSION__ < 201102L)
  #if !defined(_Thread_local)
    #if defined(_WIN32)
      #define _Thread_local __declspec(thread)
    #elif defined(__GNUC__) || defined(__clang__)
      #define _Thread_local __thread
    #endif
  #endif
#endif

/* TCC on macOS aarch64: _Thread_local 生成错误的 TLS 访问代码，
 * 程序启动时即 segfault（即使不使用多线程）。
 * 退化为普通 static — 变量不真正线程隔离。
 * TCC+Windows 和 TCC+macOS 均通过 tls.h 用平台原生 TLS API 实现真正隔离
 * （Windows: TlsAlloc, macOS: pthread_key_create）。
 * GCC/Clang 保持原生 _Thread_local。 */
#if defined(__TINYC__) && defined(__APPLE__)
  #define _Thread_local
#endif

// 数组复用池上限（runtime.h 与 array.h 共用）
#ifndef ARR_POOL_MAX
#define ARR_POOL_MAX 128
#endif

// 字符串池大小（runtime.h 用，提前定义供 tls.h 使用）
#ifndef STR_POOL_SIZE
#define STR_POOL_SIZE 131072
#endif

// 对象复用池上限（object.h 用，提前定义供 tls.h 使用）
#ifndef OBJ_FREELIST_MAX
#define OBJ_FREELIST_MAX 128
#endif

// 对象池槽位类型（object.h 用，提前定义供 tls.h 使用）
typedef struct { void *ptr; uint32_t size; } _obj_pool_slot;

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
    int is_shared;          // 0=thread-local, 1=cross-thread shared (atomic refcount, heap strings)
    void *str_index;        // pointer-free hash index for O(1) string-key lookup (NULL if none)
    void *int_index;        // hash index for O(1) sparse int-key lookup (NULL if none)
    t_arr_entry entries[];  // flexible array member
};

// ============================================================
// 8. 泛型数组 — array<T> 的 C 结构
//
//   每种 array<T> 在编译期单态化为独立 C 类型，元素紧凑存储：
//     array<int>      → t_arr_int   (entry: {t_var key; t_int val;})
//     array<string>   → t_arr_str   (entry: {t_var key; t_string val;})
//     array<float>    → t_arr_float (entry: {t_var key; t_float val;})
//     array<bool>     → t_arr_bool  (entry: {t_var key; t_bool val;})
//     array<mixed>    → t_arr_var   (= t_array, 万能数组, entry: {t_var key; t_var val;})
//     array<array<T>> → t_arr_ptr   (entry: {t_var key; void* val;}, 访问时转型)
//     array<Foo>      → t_arr_ptr   (对象数组, 元素为 void* 指向对象)
//
//   key 统一为 t_var（支持 int/string 混存，保留 PHP 有序 map 语义）
//   value 按 T 紧凑存储，无 t_var 包装（除 array<mixed>）
// ============================================================

// array<int> entry — value 为 8 字节 t_int（比 t_var 的 24 字节节省 67%）
typedef struct {
    t_var  key;
    t_int  val;
} t_arr_entry_int;

// array<string> entry — value 为 t_string（SSO 内联，无 type tag）
typedef struct {
    t_var     key;
    t_string  val;
} t_arr_entry_str;

// array<float> entry — value 为 8 字节 t_float
typedef struct {
    t_var    key;
    t_float  val;
} t_arr_entry_float;

// array<bool> entry — value 为 1 字节 t_bool（ padded to 8 due to struct alignment)
typedef struct {
    t_var   key;
    t_bool  val;
} t_arr_entry_bool;

// array<mixed> entry = 当前 t_arr_entry（别名，保持兼容）
typedef t_arr_entry t_arr_entry_var;

// array<array<T>> / array<Foo> entry — value 为 void* 指针
// CodeGenerator 根据内层类型生成转型代码
typedef struct {
    t_var  key;
    void  *val;
} t_arr_entry_ptr;

// ── 数组头结构（每种元素类型独立，布局与 t_array 一致） ──

typedef struct {
    int    length;
    int    capacity;
    int    refcount;
    int    cursor;
    int    is_shared;
    void  *str_index;
    void  *int_index;
    t_arr_entry_int entries[];
} t_arr_int;

typedef struct {
    int    length;
    int    capacity;
    int    refcount;
    int    cursor;
    int    is_shared;
    void  *str_index;
    void  *int_index;
    t_arr_entry_str entries[];
} t_arr_str;

typedef struct {
    int    length;
    int    capacity;
    int    refcount;
    int    cursor;
    int    is_shared;
    void  *str_index;
    void  *int_index;
    t_arr_entry_float entries[];
} t_arr_float;

typedef struct {
    int    length;
    int    capacity;
    int    refcount;
    int    cursor;
    int    is_shared;
    void  *str_index;
    void  *int_index;
    t_arr_entry_bool entries[];
} t_arr_bool;

// array<mixed> = t_array（别名，共享同一结构）
typedef struct _t_array t_arr_var;

// array<array<T>> / array<Foo> — 元素为指针的数组
typedef struct {
    int    length;
    int    capacity;
    int    refcount;
    int    cursor;
    int    is_shared;
    void  *str_index;
    void  *int_index;
    t_arr_entry_ptr entries[];
} t_arr_ptr;
