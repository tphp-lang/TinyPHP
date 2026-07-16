#pragma once
// ============================================================
// phpc.h — PHP ↔ C 互操作（原名 p2c，已统一命名为 phpc）
//
//   基础类型：tphp_fn_c_int / tphp_fn_c_str
//             tphp_fn_php_int / tphp_fn_php_str / tphp_fn_php_str_clone
//   数组：    tphp_fn_phpc_arr_*   — 严格 C 风格，类型不匹配抛 tp_throw 异常
//   对象：    tphp_fn_phpc_obj / tphp_fn_phpc_new_obj / tphp_fn_phpc_unregister_obj
//   回调：    tphp_fn_phpc_fn / tphp_fn_phpc_new_fn
//   释放：    tphp_fn_phpc_free / tphp_fn_phpc_free_str_arr
//
// 安全设计（借鉴 vlang）：
//   - tphp_fn_phpc_arr_* 失败抛 tp_throw 异常，可被 try-catch 捕获，不再 exit(1)
//   - tphp_fn_phpc_obj 返回 NULL 时安全（无段错误）
//   - tphp_fn_phpc_new_obj 可通过 phpc_unregister_obj 解除注册，防止 double-free
//   - tphp_fn_php_str_clone 提供字符串深拷贝（C→PHP 拥有所有权），区别于 php_str 复用
// ============================================================

#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include "object/try.h"

// ── 1. 基础类型：PHP → C ──────────────────────────────────
//   纯透传函数用宏实现（参考 vlang:简单表达式用 #define,零开销 + 常量折叠）
//   c_str 涉及 STR_PTR(多次求值),保持 static inline 确保单次求值
//   注：c_float/php_float 已移除（t_float 就是 double，转换无意义）

#define tphp_fn_c_int(v)      ((int32_t)(v))
static inline const char* tphp_fn_c_str(t_string v) { return STR_PTR(v); }

// ── 2. 基础类型：C → PHP ──────────────────────────────────

#define tphp_fn_php_int(v)    ((t_int)(v))

// php_str: 复用 C 内存（深拷贝到 arena，C 端原指针仍由 C 管理）
//   适用：C 函数返回的栈字符串/静态字符串/调用方不持有所有权的字符串
//   参数为 const char*，类型安全
//   有 strlen+dup 逻辑,保持 static inline（宏会多次求值 s）
static inline t_string tphp_fn_php_str(const char* s) {
    return s ? tphp_rt_str_dup((t_string){(char*)s, (int)strlen(s)}) : (t_string){.data = NULL, .length = 0, .is_local = false};
}

// php_str_ptr / php_str_clone: 宏展开为对 php_str 的单次调用,参数仅求值一次
#define tphp_fn_php_str_ptr(ptr)    tphp_fn_php_str((const char*)(ptr))
#define tphp_fn_php_str_clone(s)    tphp_fn_php_str(s)

// c_void_ptr: 透传 void*,让 void* 在 PHP 层显式流转（类型标记用途）
#define tphp_fn_c_void_ptr(p) (p)

// ── 3. 数组：PHP → C（严格类型检查，不匹配抛 tp_throw 异常）────
//   返回 malloc 的 C 数组指针，长度通过 count($arr) 获取
//   自动注册到运行时（type 3），程序结束/异常时由 tphp_rt_free_all 自动释放
//   可提前调用 phpc_free() 手动释放（会先注销注册，防 double-free）

// 提取为 int32_t 数组 — 所有元素必须为 TYPE_INT
static inline int32_t* tphp_fn_phpc_arr_int(t_array* a) {
    if (!a || a->length == 0) return NULL;
    int32_t* out = (int32_t*)malloc((size_t)a->length * sizeof(int32_t));
    if (!out) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].val.type != TYPE_INT) {
            free(out);
            char _buf[128];
            snprintf(_buf, sizeof(_buf), "phpc_arr_int(): element %d is not int (type=%d)", i, a->entries[i].val.type);
            tp_throw(_buf);
        }
        out[i] = (int32_t)a->entries[i].val.value._int;
    }
    tphp_rt_register(out, 3);
    return out;
}

// 提取为 double 数组 — 元素必须为 TYPE_INT 或 TYPE_FLOAT
static inline double* tphp_fn_phpc_arr_dbl(t_array* a) {
    if (!a || a->length == 0) return NULL;
    double* out = (double*)malloc((size_t)a->length * sizeof(double));
    if (!out) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].val.type == TYPE_INT)
            out[i] = (double)a->entries[i].val.value._int;
        else if (a->entries[i].val.type == TYPE_FLOAT)
            out[i] = a->entries[i].val.value._float;
        else {
            free(out);
            char _buf[128];
            snprintf(_buf, sizeof(_buf), "phpc_arr_dbl(): element %d is not numeric (type=%d)", i, a->entries[i].val.type);
            tp_throw(_buf);
        }
    }
    tphp_rt_register(out, 3);
    return out;
}

// 提取为 C 字符串数组 — 所有元素必须为 TYPE_STRING
//   注意:字符串数组需逐元素释放,不自动注册,调用方需用 phpc_free_str_arr(arr, len) 手动释放
static inline char** tphp_fn_phpc_arr_str(t_array* a) {
    if (!a || a->length == 0) return NULL;
    char** out = (char**)malloc((size_t)a->length * sizeof(char*));
    if (!out) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].val.type != TYPE_STRING) {
            for (int j = 0; j < i; j++) free(out[j]);
            free(out);
            char _buf[128];
            snprintf(_buf, sizeof(_buf), "phpc_arr_str(): element %d is not string (type=%d)", i, a->entries[i].val.type);
            tp_throw(_buf);
        }
        t_string s = a->entries[i].val.value._string;
        out[i] = (char*)malloc((size_t)(s.length + 1));
        if (out[i]) {
            if (STR_PTR(s) && s.length > 0) memcpy(out[i], STR_PTR(s), (size_t)s.length);
            out[i][s.length] = '\0';
        }
    }
    return out;
}

// ── 4. 数组：C → PHP（深拷贝数据）────────────────────────

static inline t_array* tphp_fn_phpc_new_arr_int(const int32_t* src, int len) {
    t_array* a = tphp_fn_arr_create(len > 0 ? len : 4);
    if (!a || !src) return a;
    for (int i = 0; i < len; i++) {
        a = tphp_fn_arr_push(a, VAR_INT((t_int)src[i]));
    }
    return a;
}

static inline t_array* tphp_fn_phpc_new_arr_dbl(const double* src, int len) {
    t_array* a = tphp_fn_arr_create(len > 0 ? len : 4);
    if (!a || !src) return a;
    for (int i = 0; i < len; i++) {
        a = tphp_fn_arr_push(a, VAR_FLOAT((t_float)src[i]));
    }
    return a;
}

static inline t_array* tphp_fn_phpc_new_arr_str(const char* const* src, int len) {
    t_array* a = tphp_fn_arr_create(len > 0 ? len : 4);
    if (!a || !src) return a;
    for (int i = 0; i < len; i++) {
        t_string s = src[i] ? tphp_rt_str_dup((t_string){(char*)src[i], (int)strlen(src[i])}) : (t_string){.data = NULL, .length = 0, .is_local = false};
        a = tphp_fn_arr_push(a, VAR_STRING(s));
    }
    return a;
}

static inline t_array* tphp_fn_phpc_new_arr(void) {
    return tphp_fn_arr_create(4);
}

// ── 5. 对象：PHP ↔ C 结构体指针 ──────────────────────────
//   TinyPHP 对象 = t_object 头部 + 用户字段，结构体指针即对象首地址

// PHP 对象 → 底层 C 结构体指针（借用语义，不转移所有权）
//   返回 NULL 时安全（C 端应自行检查）
#define tphp_fn_phpc_obj(obj) (obj)

// C 结构体指针 → PHP 对象（接管语义，class descriptor 控制析构生命周期）
//   注意：ptr 由 TinyPHP 注册管理，C 端不应再自行 free
//   如需 C 端自行管理生命周期，请先调 phpc_unregister_obj 解除注册
static inline void* tphp_fn_phpc_new_obj(void* ptr, const t_class* cls) {
    if (!ptr || !cls) return NULL;
    t_object* obj = (t_object*)ptr;
    obj->cls      = cls;
    obj->refcount = 1;
    tphp_rt_register(ptr, 0);  // register for error() cleanup
    return obj;
}

// 解除对象注册 — 当 C 库自行释放对象内存时调用，防止 tphp_rt_free_all double-free
//   典型用法：C->point_free(phpc_obj($p)); phpc_unregister_obj(phpc_obj($p));
static inline void tphp_fn_phpc_unregister_obj(void* ptr) {
    if (ptr) tphp_rt_unregister(ptr);
}

// ── 6. 回调：PHP ↔ C 函数指针 ────────────────────────────
//   纯字段访问,用宏实现零开销

#define tphp_fn_phpc_fn(cb)       ((cb).func)
#define tphp_fn_phpc_env(cb)      ((cb).env)
#define tphp_fn_phpc_new_fn(f)    ((t_callback){ .func = (f), .env = NULL })
#define tphp_fn_phpc_new_fn_env(f, e) ((t_callback){ .func = (f), .env = (e) })

// ── 6b. 回调类型转换：TinyPHP 闭包 → C 回调指针 ──────────
//   闭包编译为 t_int fn(t_int, void*)，C 库期望 int32_t fn(int32_t, void*)
//   以下内联函数完成指针 cast（ABI 兼容，x86-64/ARM64 同寄存器）

typedef int32_t (*phpc_fn_i32_t)(int32_t, void*);
typedef int64_t (*phpc_fn_i64_t)(int64_t, void*);
typedef double  (*phpc_fn_f64_t)(double,  void*);

//   纯 cast,宏实现零开销
#define tphp_fn_phpc_fn_i32(cb) ((phpc_fn_i32_t)(cb).func)
#define tphp_fn_phpc_fn_i64(cb) ((phpc_fn_i64_t)(cb).func)
#define tphp_fn_phpc_fn_f64(cb) ((phpc_fn_f64_t)(cb).func)

// ── 6c. 无 env 回调 thunk ───────────────────────────────
//   用法：phpc_thunk('cb_name', $fn)  按 #callback 签名生成 thunk
//   闭包 env 嵌入 thunk 函数体（编译期绑定），类似 libffi 机制
//   CodeGen 生成：
//     static double _thunk_N(int32_t idx, double val) {
//         double (*_raw)(int32_t, double, void*) = ...;
//         return (double)_raw((t_int)idx, (t_float)val, _cb.env);
//     }
//   支持任意类型任意数量参数（由 #callback 声明决定）

// ── 7. 内存释放 ───────────────────────────────────────────
//   phpc_arr_int/dbl 已自动注册,程序结束/异常时由 tphp_rt_free_all 自动释放
//   如需提前释放(如循环内避免内存堆积),调用 phpc_free() 手动释放
//   CodeGenerator 会在调用后自动置零变量，防止 use-after-free

// phpc_free: 释放 C 内存并注销注册（防 double-free）
//   CodeGenerator 会在调用后自动置零变量
static inline void tphp_fn_phpc_free(void* ptr) {
    if (ptr) {
        tphp_rt_unregister(ptr);  // 先注销注册,防止 tphp_rt_free_all double-free
        free(ptr);
    }
}

// 释放 phpc_arr_str 返回的字符串数组（先释放每个字符串，再释放指针数组）
static inline void tphp_fn_phpc_free_str_arr(char** strs, int len) {
    if (!strs) return;
    for (int i = 0; i < len; i++) free(strs[i]);
    free(strs);
}

// ── 7b. 通用 C 指针自动注册 ─────────────────────────────
//   phpc_auto: 将任意 C 指针注册到运行时,程序结束/异常时自动 free
//   用于 C->func() 返回的 malloc 指针,免去手动 free 的负担
//   用法: $buf = phpc_auto(C->malloc(100));
//   返回 ptr 本身(透传,方便链式调用)
static inline void* tphp_fn_phpc_auto(void* ptr) {
    if (ptr) tphp_rt_register(ptr, 3);
    return ptr;
}

// ── 7c. 指针 ↔ 整数桥接 ─────────────────────────────────
//   让 C 指针以 t_int (int64_t) 在 PHP 层流转,函数签名可用 int 类型
//   内部用 intptr_t 保证可移植性(32/64 位平台均安全)
//   用法:
//     $fp = phpc_ptr_to_int(C->fopen(...));      // void* → t_int
//     function helper(int $fp): int {
//         $f = phpc_int_to_ptr($fp);              // t_int → void*
//         C->fseek($f, ...);
//     }
static inline t_int tphp_fn_phpc_ptr_to_int(void* ptr) {
    return (t_int)(intptr_t)ptr;
}
static inline void* tphp_fn_phpc_int_to_ptr(t_int v) {
    return (void*)(intptr_t)v;
}

// ── 8. 安全辅助 API ──────────────────────────────────────

// phpc_assert_ptr: 断言指针非 NULL，NULL 时抛 tp_throw 异常
//   用法：phpc_assert_ptr($ptr, "ptr_name"); C->func($ptr);
//   防止 C->func(NULL) 导致段错误
//   参数: ptr 为 t_int（指针值），name 为 t_string（变量名字符串）
static inline t_int tphp_fn_phpc_assert_ptr(t_int ptr, t_string name) {
    if (!ptr) {
        char _buf[128];
        snprintf(_buf, sizeof(_buf), "phpc_assert_ptr(): '%s' is NULL", STR_PTR(name));
        tp_throw(_buf);
    }
    return ptr;
}

// phpc_obj_steal: 标记对象为"已分离"（refcount = -1），防止 tp_obj_release double-free
//   用法：phpc_obj_steal(phpc_obj($p)); C->point_free(phpc_obj($p));
//   调用后 C 库可安全释放对象内存，PHP 侧 $p 不再被 GC 释放
//   注意：steal 后请勿再访问 $p 的任何字段（内存可能已被 C 库释放）
static inline void tphp_fn_phpc_obj_steal(void* obj) {
    if (obj) {
        t_object* o = (t_object*)obj;
        o->refcount = -1;  // immortal: tp_obj_release 检测 refcount <= 0 时跳过释放
    }
}

// ── 9. 闭包 env 生命周期管理（异步回调安全） ─────────────
//   问题：C 库持有回调 env 期间，PHP 侧闭包可能超出作用域被释放
//   方案：pin 将 env 注册到全局表防止释放；unpin 恢复正常生命周期

#define PHPC_ENV_PIN_MAX 64
typedef struct { void* env; } phpc_env_slot;
static phpc_env_slot _phpc_pinned_envs[PHPC_ENV_PIN_MAX];

// phpc_env_pin: 固定闭包 env，防止 PHP 侧释放（C 库持有回调期间使用）
//   返回 env 指针，C 库可安全持有
//   用法：$env = phpc_env_pin($fn); C->async_call(..., $env);
static inline void* tphp_fn_phpc_env_pin(t_callback cb) {
    if (!cb.env) return NULL;
    for (int i = 0; i < PHPC_ENV_PIN_MAX; i++) {
        if (_phpc_pinned_envs[i].env == NULL) {
            _phpc_pinned_envs[i].env = cb.env;
            return cb.env;
        }
    }
    return cb.env;  // 表满时仍返回 env（降级，不阻塞）
}

// phpc_env_unpin: 解除固定（C 库不再需要回调时调用）
//   用法：C->async_cancel(handle); phpc_env_unpin($env);
static inline void tphp_fn_phpc_env_unpin(void* env) {
    if (!env) return;
    for (int i = 0; i < PHPC_ENV_PIN_MAX; i++) {
        if (_phpc_pinned_envs[i].env == env) {
            _phpc_pinned_envs[i].env = NULL;
            return;
        }
    }
}
