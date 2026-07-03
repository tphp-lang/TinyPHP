#pragma once
// ============================================================
// phpc.h — PHP ↔ C 互操作（原名 p2c，已统一命名为 phpc）
//
//   基础类型：tphp_fn_c_int / tphp_fn_c_float / tphp_fn_c_str / tphp_fn_php_int / tphp_fn_php_float / tphp_fn_php_str
//   数组：    tphp_fn_phpc_arr_*   — 严格 C 风格，类型不匹配即 error() 退出
//   对象：    tphp_fn_phpc_obj / tphp_fn_phpc_new_obj   — PHP 对象 ↔ C 结构体指针
//   回调：    tphp_fn_phpc_fn / tphp_fn_phpc_new_fn     — PHP 闭包 ↔ C 函数指针
// ============================================================

#include <stdint.h>
#include <stdlib.h>

// ── 1. 基础类型：PHP → C ──────────────────────────────────

static inline int32_t  tphp_fn_c_int(t_int v)     { return (int32_t)v; }
static inline double   tphp_fn_c_float(t_float v) { return (double)v; }
static inline const char* tphp_fn_c_str(t_string v) { return STR_PTR(v); }

// ── 2. 基础类型：C → PHP ──────────────────────────────────

static inline t_int   tphp_fn_php_int(int32_t v)   { return (t_int)v; }
static inline t_float tphp_fn_php_float(double v)  { return (t_float)v; }
static inline t_string tphp_fn_php_str(const char* s) {
    return s ? tphp_rt_str_dup((t_string){(char*)s, (int)strlen(s)}) : (t_string){.data = NULL, .length = 0, .is_local = false};
}

// ── 3. 数组：PHP → C（严格类型检查，不匹配即 error()）────
//   返回 malloc 的 C 数组指针，长度通过 count($arr) 获取
//   调用方负责 free()

// 提取为 int32_t 数组 — 所有元素必须为 TYPE_INT
static inline int32_t* tphp_fn_phpc_arr_int(t_array* a) {
    if (!a || a->length == 0) return NULL;
    int32_t* out = (int32_t*)malloc((size_t)a->length * sizeof(int32_t));
    if (!out) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].val.type != TYPE_INT) {
            free(out);
            tphp_rt_free_all();
            fprintf(stderr, "\nFatal error: phpc_arr_int(): element %d is not int (type=%d)\n\n", i, a->entries[i].val.type);
            exit(1);
        }
        out[i] = (int32_t)a->entries[i].val.value._int;
    }
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
            tphp_rt_free_all();
            fprintf(stderr, "\nFatal error: phpc_arr_dbl(): element %d is not numeric (type=%d)\n\n", i, a->entries[i].val.type);
            exit(1);
        }
    }
    return out;
}

// 提取为 C 字符串数组 — 所有元素必须为 TYPE_STRING
static inline char** tphp_fn_phpc_arr_str(t_array* a) {
    if (!a || a->length == 0) return NULL;
    char** out = (char**)malloc((size_t)a->length * sizeof(char*));
    if (!out) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].val.type != TYPE_STRING) {
            for (int j = 0; j < i; j++) free(out[j]);
            free(out);
            tphp_rt_free_all();
            fprintf(stderr, "\nFatal error: phpc_arr_str(): element %d is not string (type=%d)\n\n", i, a->entries[i].val.type);
            exit(1);
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

// PHP 对象 → 底层 C 结构体指针（类型安全由调用方保证）
static inline void* tphp_fn_phpc_obj(void* obj) {
    return obj;
}

// C 结构体指针 → PHP 对象（class descriptor 控制析构生命周期）
static inline void* tphp_fn_phpc_new_obj(void* ptr, const t_class* cls) {
    if (!ptr || !cls) return NULL;
    t_object* obj = (t_object*)ptr;
    obj->cls      = cls;
    obj->refcount = 1;
    tphp_rt_register(ptr, 0);  // register for error() cleanup
    return obj;
}

// ── 6. 回调：PHP ↔ C 函数指针 ────────────────────────────

static inline void* tphp_fn_phpc_fn(t_callback cb)   { return cb.func; }
static inline void* tphp_fn_phpc_env(t_callback cb)  { return cb.env; }

static inline t_callback tphp_fn_phpc_new_fn(void* func) {
    return (t_callback){ .func = func, .env = NULL };
}
static inline t_callback tphp_fn_phpc_new_fn_env(void* func, void* env) {
    return (t_callback){ .func = func, .env = env };
}

// ── 6b. 回调类型转换：TinyPHP 闭包 → C 回调指针 ──────────
//   闭包编译为 t_int fn(t_int, void*)，C 库期望 int32_t fn(int32_t, void*)
//   以下内联函数完成指针 cast（ABI 兼容，x86-64/ARM64 同寄存器）

typedef int32_t (*phpc_fn_i32_t)(int32_t, void*);
typedef int64_t (*phpc_fn_i64_t)(int64_t, void*);
typedef double  (*phpc_fn_f64_t)(double,  void*);

static inline phpc_fn_i32_t tphp_fn_phpc_fn_i32(t_callback cb) { return (phpc_fn_i32_t)cb.func; }
static inline phpc_fn_i64_t tphp_fn_phpc_fn_i64(t_callback cb) { return (phpc_fn_i64_t)cb.func; }
static inline phpc_fn_f64_t tphp_fn_phpc_fn_f64(t_callback cb) { return (phpc_fn_f64_t)cb.func; }

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
//   phpc_arr_* 返回的 malloc 指针必须通过以下函数释放

static inline void tphp_fn_phpc_free(void* ptr) {
    if (ptr) free(ptr);
}

// 释放 phpc_arr_str 返回的字符串数组（先释放每个字符串，再释放指针数组）
static inline void tphp_fn_phpc_free_str_arr(char** strs, int len) {
    if (!strs) return;
    for (int i = 0; i < len; i++) free(strs[i]);
    free(strs);
}
