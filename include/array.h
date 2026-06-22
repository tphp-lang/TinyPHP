#pragma once
// ============================================================
// PHP 万能数组 API — yyjson 风格 flat memory + 对象池复用
//
//   • 所有 entry 在一块 malloc 中（单次分配/释放）
//   • push 1.5x 扩容 realloc 无需逐 entry malloc
//   • 键: int | string，值: 任意 t_var
//   • 引用计数 + 嵌套数组自动 retain/free
//   • 数组对象池：空闲数组回收复用，减少 malloc/free 抖动
// ============================================================

#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include "types.h"

/* 前向声明 runtime.h 中 array.h 需要的函数（避免循环 include，统一用 static inline） */
static inline void tphp_rt_str_free(t_string* s);
static inline t_string tphp_rt_str_dup(t_string s);
static inline t_bool tphp_rt_str_eq(t_string a, t_string b);
static inline void tphp_rt_register(void *ptr, int type);

static int tphp_fn_str_hash(t_string s);

// === Array Freelist Pool (LIFO, up to 128 cached arrays) ===

#define ARR_POOL_MAX  128
static t_array*  arr_freelist[ARR_POOL_MAX];
static int       arr_freelist_count = 0;

/** 将数组归还到复用池（仅当引用计数为 0 且无外部持有者时调用） */
static inline void arr_pool_put(t_array *a) {
    if (unlikely(a == NULL)) return;
    if (arr_freelist_count >= ARR_POOL_MAX) { free(a); return; }
    // 重置状态
    a->length   = 0;
    a->refcount = 1;
    // 清零 entry 区域防止残留数据
    memset(a->entries, 0, (size_t)a->capacity * sizeof(t_arr_entry));
    arr_freelist[arr_freelist_count++] = a;
}

/** 从复用池取合适容量的数组（没有则返回 NULL） */
static inline t_array* arr_pool_get(int cap) {
    if (cap < 4) cap = 4;
    // 从尾部取最近归还的（LIFO 缓存友好）
    for (int i = arr_freelist_count - 1; i >= 0; i--) {
        t_array *a = arr_freelist[i];
        if (a->capacity >= cap) {
            // 找到合适大小的，移出列表
            arr_freelist[i] = arr_freelist[--arr_freelist_count];
            a->length   = 0;
            a->refcount = 1;
            return a;
        }
    }
    return NULL;
}

// === Lifecycle ===

static inline t_array* tphp_fn_arr_create(int cap) {
    if (cap < 4) cap = 4;
    // 先查复用池
    t_array *pooled = arr_pool_get(cap);
    if (likely(pooled != NULL)) return pooled;
    // 池中无合适大小，新分配
    size_t sz = sizeof(t_array) + (size_t)cap * sizeof(t_arr_entry);
    t_array *a = (t_array*)calloc(1, sz);
    if (unlikely(a == NULL)) return NULL;
    a->refcount = 1;
    a->capacity = cap;
    return a;
}

/** 带容量提示的创建（至少分配 cap 个槽，但最小 4） */
static inline t_array* tphp_fn_arr_create_hint(int cap, int total) {
    (void)total;
    int c = cap > 0 ? cap : 4;
    if (c < 4) c = 4;
    size_t sz = sizeof(t_array) + (size_t)c * sizeof(t_arr_entry);
    t_array *a = (t_array*)calloc(1, sz);
    if (unlikely(a == NULL)) return NULL;
    a->refcount = 1;
    a->capacity = c;
    return a;
}

static inline t_array* tphp_fn_arr_retain(t_array *a) {
    if (a) a->refcount++;
    return a;
}

void tphp_fn_arr_free(t_array *a);  // forward decl, implemented below

// === Internal: grow (1.5x factor, yyjson-style) ===

static inline t_array* tphp_fn_arr_grow(t_array *a, int need) {
    if (likely(a != NULL && need <= a->capacity)) return a;
    if (unlikely(a == NULL)) return NULL;
    // 1.5x 增长: nc = cap + (cap >> 1)
    int nc = a->capacity + (a->capacity >> 1);
    if (nc < 4) nc = 4;
    if (nc < need) nc = need;
    size_t sz = sizeof(t_array) + (size_t)nc * sizeof(t_arr_entry);
    t_array *na = (t_array*)realloc(a, sz);
    if (unlikely(na == NULL)) return a;
    na->capacity = nc;
    return na;
}

// === Push (int-key append) ===

static inline t_array* tphp_fn_arr_push(t_array *a, t_var val) {
    if (unlikely(a == NULL)) return NULL;
    a = tphp_fn_arr_grow(a, a->length + 1);
    a->entries[a->length].key.type = TYPE_INT;
    a->entries[a->length].key.value._int = a->length;
    a->entries[a->length].val = val;
    a->length++;
    return a;
}

// === Set by int key ===

static inline t_array* tphp_fn_arr_set_int(t_array *a, t_int key, t_var val) {
    if (unlikely(a == NULL || key < 0)) return a;
    // 线性扫描：如果已存在同键则覆盖
    for (int i = 0; i < a->length; i++) {
        if (likely(a->entries[i].key.type == TYPE_INT) &&
            a->entries[i].key.value._int == key) {
            a->entries[i].val = val;
            return a;
        }
    }
    // 追加
    a = tphp_fn_arr_grow(a, a->length + 1);
    a->entries[a->length].key.type = TYPE_INT;
    a->entries[a->length].key.value._int = key;
    a->entries[a->length].val = val;
    a->length++;
    return a;
}

// === Set by str key ===

static inline t_array* tphp_fn_arr_set_str(t_array *a, t_string key, t_var val) {
    if (unlikely(a == NULL)) return a;
    // 线性扫描：覆盖已存在
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[i].key.value._string, key)) {
            a->entries[i].val = val;
            return a;
        }
    }
    // 追加
    a = tphp_fn_arr_grow(a, a->length + 1);
    a->entries[a->length].key.type = TYPE_STRING;
    a->entries[a->length].key.value._string = tphp_rt_str_dup(key);
    a->entries[a->length].val = val;
    a->length++;
    return a;
}

// === Get by int key (returns t_var*) ===

static inline t_var* tphp_fn_arr_get_int(t_array *a, t_int key) {
    if (unlikely(a == NULL)) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT && a->entries[i].key.value._int == key)
            return &a->entries[i].val;
    }
    return NULL;
}

// === Get by str key (returns t_var*) ===

static inline t_var* tphp_fn_arr_get_str(t_array *a, t_string key) {
    if (unlikely(a == NULL)) return NULL;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[i].key.value._string, key))
            return &a->entries[i].val;
    }
    return NULL;
}

// === Typed getters for codegen ===

static inline t_int tphp_arr_item_int(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return 0;
    t_var *v = &a->entries[idx].val;
    return likely(v->type == TYPE_INT) ? v->value._int : 0;
}

static inline t_float tphp_arr_item_float(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return 0.0;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_FLOAT) ? v->value._float : 0.0;
}

static inline t_string tphp_arr_item_str(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return (t_string){NULL, 0};
    t_var *v = &a->entries[idx].val;
    if (v->type == TYPE_STRING) return v->value._string;
    if (v->type == TYPE_INT) {
        static char _b[32];
        int n = snprintf(_b, sizeof(_b), "%lld", (long long)v->value._int);
        return (t_string){.data = _b, .length = n};
    }
    return (t_string){NULL, 0};
}

static inline t_bool tphp_arr_item_bool(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return false;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_BOOL) ? v->value._bool : (v->type == TYPE_INT && v->value._int != 0);
}

static inline t_array* tphp_arr_item_array(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return NULL;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_ARRAY) ? v->value._array : NULL;
}

static inline void* tphp_arr_item_object(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return NULL;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_OBJECT) ? v->value._ptr : NULL;
}

static inline t_callback tphp_arr_item_callback(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return (t_callback){NULL, NULL};
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_CALLBACK) ? v->value._callback : (t_callback){NULL, NULL};
}

// === Index access (for foreach) ===

static inline t_var* tphp_fn_arr_index(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return NULL;
    return &a->entries[idx].val;
}

// === Count ===

static inline int tphp_fn_arr_count(t_array *a) {
    return likely(a != NULL) ? a->length : 0;
}

// === Pop (remove last element, return value) ===

static inline bool tphp_fn_arr_pop(t_array *a, t_var *out) {
    if (a == NULL || a->length == 0) return false;
    a->length--;
    if (out != NULL) *out = a->entries[a->length].val;
    // 释放被弹出 entry 的 string key（堆分配）
    if (a->entries[a->length].key.type == TYPE_STRING)
        tphp_rt_str_free(&a->entries[a->length].key.value._string);
    return true;
}

// === String-key typed getters ===

static inline t_int tphp_fn_arr_get_str_int(t_array *a, t_string key) {
    t_var *v = tphp_fn_arr_get_str(a, key);
    if (v == NULL) return 0;
    if (v->type == TYPE_INT) return v->value._int;
    if (v->type == TYPE_FLOAT) return (t_int)v->value._float;
    return 0;
}

static inline t_string tphp_fn_arr_get_str_str(t_array *a, t_string key) {
    t_var *v = tphp_fn_arr_get_str(a, key);
    if (v == NULL) return (t_string){NULL, 0};
    if (v->type == TYPE_STRING) return v->value._string;
    if (v->type == TYPE_INT) {
        static char _b[32];
        int n = snprintf(_b, sizeof(_b), "%lld", (long long)v->value._int);
        return (t_string){.data = _b, .length = n};
    }
    return (t_string){NULL, 0};
}

static inline t_array* tphp_fn_arr_get_str_arr(t_array *a, t_string key) {
    t_var *v = tphp_fn_arr_get_str(a, key);
    if (v == NULL) return NULL;
    if (v->type == TYPE_ARRAY) return v->value._array;
    return NULL;
}

// === Free (释放 entries + 回收到复用池) ===

void tphp_fn_arr_free(t_array *a) {
    if (unlikely(a == NULL)) return;
    if (--a->refcount > 0) return;
    for (int i = 0; i < a->length; i++) {
        // 释放 string key（堆分配的）
        if (a->entries[i].key.type == TYPE_STRING)
            tphp_rt_str_free(&a->entries[i].key.value._string);
        // 递归释放嵌套数组
        if (a->entries[i].val.type == TYPE_ARRAY && a->entries[i].val.value._array != NULL)
            tphp_fn_arr_free(a->entries[i].val.value._array);
    }
    // 回收到复用池（而非 free），减少后续 malloc
    arr_pool_put(a);
}

// === Hash (unchanged) ===

static inline int tphp_fn_str_hash(t_string s) {
    if (s.data == NULL) return 0;
    unsigned int h = 5381;
    for (int i = 0; i < s.length; i++)
        h = ((h << 5) + h) + (unsigned char)s.data[i];
    return (int)h;
}
