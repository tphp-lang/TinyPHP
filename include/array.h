#pragma once
// ============================================================
// PHP 万能数组 API — yyjson 风格 flat memory + 对象池复用
//
//   • 所有 entry 在一块 malloc 中（单次分配/释放）
//   • push 1.5x 扩容 realloc 无需逐 entry malloc
//   • 键: int | string，值: 任意 t_var
//   • 引用计数 + 嵌套数组自动 retain/free
//   • 数组对象池：空闲数组回收复用，减少 malloc/free 抖动
//
//   优化路径 (klib):
//     tphp_hash.h → O(1) string-key 查找 (khash)
//     ksort.h     → 整数基数排序 (radix sort)
//     kvec.h      → 泛型 vector (内部数据管理)
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
static inline void tphp_rt_unregister(void *ptr);
static inline void tphp_rt_free_all(void);
/* 前向声明 object.h 中的引用计数函数（common.h 中 object.h 在 array.h 之前 include） */
static inline void* tp_obj_retain(void *obj);
static inline void* tp_obj_release(void *obj);

/* ============================================================
 * 引用计数辅助：t_var 值的 retain/release
 *   数组/对象放入数组容器时需 retain，被覆盖/移除时需 release
 *   标量（int/float/bool/string）无需管理，直接拷贝
 * ============================================================ */
static inline t_var _arr_val_retain(t_var v) {
    if (v.type == TYPE_ARRAY && v.value._array != NULL)
        tphp_fn_arr_retain(v.value._array);
    else if (v.type == TYPE_OBJECT && v.value._ptr != NULL)
        tp_obj_retain(v.value._ptr);
    return v;
}
static inline void _arr_val_release(t_var v) {
    if (v.type == TYPE_ARRAY && v.value._array != NULL)
        tphp_fn_arr_free(v.value._array);
    else if (v.type == TYPE_OBJECT && v.value._ptr != NULL)
        tp_obj_release(v.value._ptr);
}

// ============================================================
// String Key Hash Index — pointer-free open-addressing table
//
//   Stores (hash, entry_index) pairs — NO string pointers.
//   String verification reads directly from entries[], so the
//   index survives arr_grow's realloc (entry indices are stable).
//
//   Slot values:  0 = empty, -1 = tombstone, >0 = entry_idx+1
// ============================================================

#define ARR_HASH_THRESHOLD 8   // don't build index below this size

typedef struct {
    int *slots;    // entry_idx+1 (0=empty, -1=tomb)
    int  cap;      // power of 2
    int  count;    // active entries (excludes tombstones)
} arr_stridx;

static inline uint32_t _arr_key_hash(t_string s) {
    const char *p = STR_PTR(s);
    uint32_t h = 5381u;
    for (int i = 0; i < s.length; i++)
        h = ((h << 5) + h) + (unsigned char)p[i];
    return h;
}

/** Free the hash index (safe to call if NULL). */
static inline void arr_stridx_free(t_array *a) {
    if (a->str_index != NULL) {
        arr_stridx *idx = (arr_stridx*)a->str_index;
        free(idx->slots);
        free(idx);
        a->str_index = NULL;
    }
}

/** Build index from all existing string keys. No-op if already built. */
static inline void arr_stridx_build(t_array *a) {
    if (a->str_index != NULL) return;
    int cap = 16;
    while (cap < a->length * 2) cap *= 2;
    arr_stridx *idx = (arr_stridx*)malloc(sizeof(arr_stridx));
    if (idx == NULL) { tp_throw("arr_stridx_build: out of memory"); return; }
    idx->slots = (int*)calloc((size_t)cap, sizeof(int));
    if (idx->slots == NULL) { free(idx); tp_throw("arr_stridx_build: out of memory"); return; }
    idx->cap = cap;
    idx->count = 0;
    a->str_index = idx;
    int mask = cap - 1;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_STRING) {
            uint32_t h = _arr_key_hash(a->entries[i].key.value._string);
            int slot = (int)(h & mask);
            while (idx->slots[slot] != 0)
                slot = (slot + 1) & mask;
            idx->slots[slot] = i + 1;
            idx->count++;
        }
    }
}

/** Lazily build index if array is large enough. */
static inline void arr_stridx_ensure(t_array *a) {
    if (a->str_index == NULL && a->length >= ARR_HASH_THRESHOLD)
        arr_stridx_build(a);
}

/** O(1) lookup. Returns entry index or -1. */
static inline int arr_stridx_lookup(t_array *a, t_string key) {
    if (a->str_index == NULL) return -1;
    arr_stridx *idx = (arr_stridx*)a->str_index;
    uint32_t h = _arr_key_hash(key);
    int mask = idx->cap - 1;
    int slot = (int)(h & mask);
    while (idx->slots[slot] != 0) {
        int ei = idx->slots[slot] - 1;
        if (ei >= 0 && ei < a->length &&
            a->entries[ei].key.type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[ei].key.value._string, key))
            return ei;
        slot = (slot + 1) & mask;
    }
    return -1;
}

/** Insert a new (key → entry_idx) mapping. Auto-resizes at 0.75 load. */
static inline void arr_stridx_insert(t_array *a, t_string key, int entry_idx) {
    if (a->str_index == NULL) return;
    arr_stridx *idx = (arr_stridx*)a->str_index;
    // Resize if load factor > 0.75
    if (idx->count * 4 >= idx->cap * 3) {
        int newcap = idx->cap * 2;
        int *ns = (int*)calloc((size_t)newcap, sizeof(int));
        if (ns == NULL) { tp_throw("arr_stridx_insert: out of memory"); return; }
        int nmask = newcap - 1, ncount = 0;
        for (int i = 0; i < idx->cap; i++) {
            if (idx->slots[i] > 0) {
                int ei = idx->slots[i] - 1;
                uint32_t h = _arr_key_hash(a->entries[ei].key.value._string);
                int s = (int)(h & nmask);
                while (ns[s] != 0) s = (s + 1) & nmask;
                ns[s] = ei + 1;
                ncount++;
            }
        }
        free(idx->slots);
        idx->slots = ns;
        idx->cap = newcap;
        idx->count = ncount;
    }
    uint32_t h = _arr_key_hash(key);
    int mask = idx->cap - 1;
    int slot = (int)(h & mask);
    while (idx->slots[slot] > 0)
        slot = (slot + 1) & mask;
    idx->slots[slot] = entry_idx + 1;
    idx->count++;
}

/** Delete a key from the index (sets tombstone). */
static inline void arr_stridx_delete(t_array *a, t_string key) {
    if (a->str_index == NULL) return;
    arr_stridx *idx = (arr_stridx*)a->str_index;
    uint32_t h = _arr_key_hash(key);
    int mask = idx->cap - 1;
    int slot = (int)(h & mask);
    while (idx->slots[slot] != 0) {
        int ei = idx->slots[slot] - 1;
        if (ei >= 0 && ei < a->length &&
            a->entries[ei].key.type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[ei].key.value._string, key)) {
            idx->slots[slot] = -1;
            idx->count--;
            return;
        }
        slot = (slot + 1) & mask;
    }
}

// ============================================================
// Int Key Hash Index — same open-addressing layout as arr_stridx
//
//   Slot values:  0 = empty, -1 = tombstone, >0 = entry_idx+1
//   Key verification reads entries[ei].key.value._int directly,
//   so the index survives arr_grow's realloc (entry indices stable).
//
//   仅对稀疏整数键（非 0,1,2... 连续）有意义；连续键走
//   tphp_fn_arr_get_int/set_int 的直接下标快路径，无需哈希。
// ============================================================

typedef struct {
    int *slots;    // entry_idx+1 (0=empty, -1=tomb)
    int  cap;      // power of 2
    int  count;    // active entries (excludes tombstones)
} arr_intidx;

static inline uint32_t _arr_int_hash(t_int key) {
    // Mix30 splitmix64-style finalizer — good avalanche for int64
    uint64_t k = (uint64_t)key;
    k ^= k >> 33;
    k *= 0xff51afd7ed558ccdULL;
    k ^= k >> 33;
    return (uint32_t)k;
}

/** Free the int hash index (safe to call if NULL). */
static inline void arr_intidx_free(t_array *a) {
    if (a->int_index != NULL) {
        arr_intidx *idx = (arr_intidx*)a->int_index;
        free(idx->slots);
        free(idx);
        a->int_index = NULL;
    }
}

/** Build int index from all existing int keys. No-op if already built. */
static inline void arr_intidx_build(t_array *a) {
    if (a->int_index != NULL) return;
    int cap = 16;
    while (cap < a->length * 2) cap *= 2;
    arr_intidx *idx = (arr_intidx*)malloc(sizeof(arr_intidx));
    if (idx == NULL) { tp_throw("arr_intidx_build: out of memory"); return; }
    idx->slots = (int*)calloc((size_t)cap, sizeof(int));
    if (idx->slots == NULL) { free(idx); tp_throw("arr_intidx_build: out of memory"); return; }
    idx->cap = cap;
    idx->count = 0;
    a->int_index = idx;
    int mask = cap - 1;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT) {
            uint32_t h = _arr_int_hash(a->entries[i].key.value._int);
            int slot = (int)(h & mask);
            while (idx->slots[slot] != 0)
                slot = (slot + 1) & mask;
            idx->slots[slot] = i + 1;
            idx->count++;
        }
    }
}

/** Lazily build int index if array is large enough. */
static inline void arr_intidx_ensure(t_array *a) {
    if (a->int_index == NULL && a->length >= ARR_HASH_THRESHOLD)
        arr_intidx_build(a);
}

/** O(1) lookup. Returns entry index or -1. */
static inline int arr_intidx_lookup(t_array *a, t_int key) {
    if (a->int_index == NULL) return -1;
    arr_intidx *idx = (arr_intidx*)a->int_index;
    uint32_t h = _arr_int_hash(key);
    int mask = idx->cap - 1;
    int slot = (int)(h & mask);
    while (idx->slots[slot] != 0) {
        int ei = idx->slots[slot] - 1;
        if (ei >= 0 && ei < a->length &&
            a->entries[ei].key.type == TYPE_INT &&
            a->entries[ei].key.value._int == key)
            return ei;
        slot = (slot + 1) & mask;
    }
    return -1;
}

/** Insert a new (key → entry_idx) mapping. Auto-resizes at 0.75 load. */
static inline void arr_intidx_insert(t_array *a, t_int key, int entry_idx) {
    if (a->int_index == NULL) return;
    arr_intidx *idx = (arr_intidx*)a->int_index;
    // Resize if load factor > 0.75
    if (idx->count * 4 >= idx->cap * 3) {
        int newcap = idx->cap * 2;
        int *ns = (int*)calloc((size_t)newcap, sizeof(int));
        if (ns == NULL) { tp_throw("arr_intidx_insert: out of memory"); return; }
        int nmask = newcap - 1, ncount = 0;
        for (int i = 0; i < idx->cap; i++) {
            if (idx->slots[i] > 0) {
                int ei = idx->slots[i] - 1;
                uint32_t h = _arr_int_hash(a->entries[ei].key.value._int);
                int s = (int)(h & nmask);
                while (ns[s] != 0) s = (s + 1) & nmask;
                ns[s] = ei + 1;
                ncount++;
            }
        }
        free(idx->slots);
        idx->slots = ns;
        idx->cap = newcap;
        idx->count = ncount;
    }
    uint32_t h = _arr_int_hash(key);
    int mask = idx->cap - 1;
    int slot = (int)(h & mask);
    while (idx->slots[slot] > 0)
        slot = (slot + 1) & mask;
    idx->slots[slot] = entry_idx + 1;
    idx->count++;
}

/** Delete a key from the index (sets tombstone). */
static inline void arr_intidx_delete(t_array *a, t_int key) {
    if (a->int_index == NULL) return;
    arr_intidx *idx = (arr_intidx*)a->int_index;
    uint32_t h = _arr_int_hash(key);
    int mask = idx->cap - 1;
    int slot = (int)(h & mask);
    while (idx->slots[slot] != 0) {
        int ei = idx->slots[slot] - 1;
        if (ei >= 0 && ei < a->length &&
            a->entries[ei].key.type == TYPE_INT &&
            a->entries[ei].key.value._int == key) {
            idx->slots[slot] = -1;
            idx->count--;
            return;
        }
        slot = (slot + 1) & mask;
    }
}

// === Array Freelist Pool (LIFO, up to 128 cached arrays) ===

// ARR_POOL_MAX 已在 types.h 中定义
#include "compat/tls.h"  // TCC+Windows 时定义 arr_freelist 访问宏

/* str_pool_alloc 定义在 runtime.h（在 array.h 之后 include），
 * 前置声明以供 arr_item_str/arr_get_str_str 使用 */
static inline char* str_pool_alloc(int len);

#if !TPHP_USE_WIN_TLS && !TPHP_USE_PTHREAD_TLS
static _Thread_local t_array*  arr_freelist[ARR_POOL_MAX];
static _Thread_local int       arr_freelist_count = 0;
#endif

/** 将数组归还到复用池（仅当引用计数为 0 且无外部持有者时调用） */
static inline void arr_pool_put(t_array *a) {
    if (unlikely(a == NULL)) return;
    arr_stridx_free(a);  // free hash index before recycling or freeing
    arr_intidx_free(a);  // free int hash index before recycling or freeing
    if (arr_freelist_count >= ARR_POOL_MAX) { free(a); return; }
    // 重置状态
    a->length   = 0;
    a->refcount = 1;
    a->cursor   = 0;
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
            a->cursor   = 0;
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
    if (unlikely(a == NULL)) { tp_throw("array_create: out of memory"); return NULL; }
    a->refcount = 1;
    a->capacity = cap;
    return a;
}

// 前置声明（定义在后，GCC/Clang 需要）
static inline t_array* tphp_fn_arr_push(t_array *a, t_var val);

/** 标量/值转单元素数组 — 避免 ({...}) 跨平台兼容问题 */
static inline t_array* tphp_fn_arr_from_val(t_var val) {
    t_array* a = tphp_fn_arr_create(1);
    if (a != NULL) a = tphp_fn_arr_push(a, val);
    return a;
}

/** 带容量提示的创建（至少分配 cap 个槽，但最小 4） */
static inline t_array* tphp_fn_arr_create_hint(int cap, int total) {
    (void)total;
    int c = cap > 0 ? cap : 4;
    if (c < 4) c = 4;
    size_t sz = sizeof(t_array) + (size_t)c * sizeof(t_arr_entry);
    t_array *a = (t_array*)calloc(1, sz);
    if (unlikely(a == NULL)) { tp_throw("array_create: out of memory"); return NULL; }
    a->refcount = 1;
    a->capacity = c;
    return a;
}

static inline t_array* tphp_fn_arr_retain(t_array *a) {
    if (a) a->refcount++;
    return a;
}

static inline void tphp_fn_arr_free(t_array *a);  // forward decl, implemented below

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
    if (unlikely(na == NULL)) { tp_throw("array_grow: out of memory"); return a; }
    na->capacity = nc;
    return na;
}

// === Push (int-key append) ===

static inline t_array* tphp_fn_arr_push(t_array *a, t_var val) {
    if (unlikely(a == NULL)) return NULL;
    a = tphp_fn_arr_grow(a, a->length + 1);
    a->entries[a->length].key.type = TYPE_INT;
    a->entries[a->length].key.value._int = a->length;
    a->entries[a->length].val = _arr_val_retain(val);
    a->length++;
    return a;
}

// === Set by int key ===

static inline t_array* tphp_fn_arr_set_int(t_array *a, t_int key, t_var val) {
    if (unlikely(a == NULL || key < 0)) return a;
    // 快路径 1: 连续整数键 (0,1,2,...,n-1) — 直接下标 O(1)
    if (key < a->length &&
        a->entries[key].key.type == TYPE_INT &&
        a->entries[key].key.value._int == key) {
        _arr_val_release(a->entries[key].val);
        a->entries[key].val = _arr_val_retain(val);
        return a;
    }
    // 快路径 2: 哈希索引 O(1)（稀疏整数键，如 ID→对象映射）
    if (a->int_index != NULL) {
        int idx = arr_intidx_lookup(a, key);
        if (idx >= 0) {
            _arr_val_release(a->entries[idx].val);
            a->entries[idx].val = _arr_val_retain(val);
            return a;
        }
    } else if (a->length >= ARR_HASH_THRESHOLD) {
        // 大数组但索引未建：先建索引再查
        arr_intidx_build(a);
        int idx = arr_intidx_lookup(a, key);
        if (idx >= 0) {
            _arr_val_release(a->entries[idx].val);
            a->entries[idx].val = _arr_val_retain(val);
            return a;
        }
    } else {
        // 小数组：线性扫描
        for (int i = 0; i < a->length; i++) {
            if (likely(a->entries[i].key.type == TYPE_INT) &&
                a->entries[i].key.value._int == key) {
                _arr_val_release(a->entries[i].val);
                a->entries[i].val = _arr_val_retain(val);
                return a;
            }
        }
    }
    // 追加新 entry
    a = tphp_fn_arr_grow(a, a->length + 1);
    a->entries[a->length].key.type = TYPE_INT;
    a->entries[a->length].key.value._int = key;
    a->entries[a->length].val = _arr_val_retain(val);
    int new_idx = a->length;
    a->length++;
    // 维护索引：已存在则插入，达到阈值则构建
    if (a->int_index != NULL)
        arr_intidx_insert(a, key, new_idx);
    else if (a->length >= ARR_HASH_THRESHOLD)
        arr_intidx_build(a);
    return a;
}

// === Set by str key ===

static inline t_array* tphp_fn_arr_set_str(t_array *a, t_string key, t_var val) {
    if (unlikely(a == NULL)) return a;
    // Use hash index for O(1) lookup if available
    if (a->str_index != NULL) {
        int idx = arr_stridx_lookup(a, key);
        if (idx >= 0) {
            _arr_val_release(a->entries[idx].val);
            a->entries[idx].val = _arr_val_retain(val);
            return a;
        }
    } else {
        // Small array: linear scan
        for (int i = 0; i < a->length; i++) {
            if (a->entries[i].key.type == TYPE_STRING &&
                tphp_rt_str_eq(a->entries[i].key.value._string, key)) {
                _arr_val_release(a->entries[i].val);
                a->entries[i].val = _arr_val_retain(val);
                return a;
            }
        }
    }
    // Append new entry
    a = tphp_fn_arr_grow(a, a->length + 1);
    a->entries[a->length].key.type = TYPE_STRING;
    a->entries[a->length].key.value._string = tphp_rt_str_dup(key);
    a->entries[a->length].val = _arr_val_retain(val);
    int new_idx = a->length;
    a->length++;
    // Maintain index: update if exists, build if threshold reached
    if (a->str_index != NULL)
        arr_stridx_insert(a, key, new_idx);
    else if (a->length >= ARR_HASH_THRESHOLD)
        arr_stridx_build(a);
    return a;
}

/** spread 展开: 将 src 的所有元素追加到 dst
 *  int 键重新索引（append），string 键保留并覆盖 — PHP spread 语义
 *  提取为函数避免 TCC 对 ({...}) 内嵌 for+realloc 生成错误代码 */
static inline t_array* tphp_fn_arr_spread(t_array *dst, const t_array *src) {
    if (unlikely(dst == NULL || src == NULL)) return dst;
    for (int i = 0; i < src->length; i++) {
        if (src->entries[i].key.type == TYPE_STRING)
            dst = tphp_fn_arr_set_str(dst, src->entries[i].key.value._string, src->entries[i].val);
        else
            dst = tphp_fn_arr_push(dst, src->entries[i].val);
    }
    return dst;
}

// === Get by int key (returns t_var*) ===

static inline t_var* tphp_fn_arr_get_int(t_array *a, t_int key) {
    if (unlikely(a == NULL)) return NULL;
    // 快路径 1: 连续整数键 (0,1,2,...,n-1) — 直接下标 O(1)
    if (key >= 0 && key < a->length &&
        a->entries[key].key.type == TYPE_INT &&
        a->entries[key].key.value._int == key) {
        return &a->entries[key].val;
    }
    // 快路径 2: 哈希索引 O(1)（稀疏整数键）
    arr_intidx_ensure(a);  // 大数组惰性建索引
    if (a->int_index != NULL) {
        int idx = arr_intidx_lookup(a, key);
        if (idx >= 0) return &a->entries[idx].val;
        return NULL;  // 索引覆盖所有 int 键，未命中即不存在
    }
    // 小数组：线性扫描
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT && a->entries[i].key.value._int == key)
            return &a->entries[i].val;
    }
    return NULL;
}

// === Get by str key (returns t_var*) ===

static inline t_var* tphp_fn_arr_get_str(t_array *a, t_string key) {
    if (unlikely(a == NULL)) return NULL;
    // Lazily build index for large arrays
    arr_stridx_ensure(a);
    if (a->str_index != NULL) {
        int idx = arr_stridx_lookup(a, key);
        if (idx >= 0) return &a->entries[idx].val;
        return NULL;
    }
    // Small array: linear scan
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[i].key.value._string, key))
            return &a->entries[i].val;
    }
    return NULL;
}

// === Typed getters for codegen ===

static inline t_int tphp_fn_arr_item_int(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return 0;
    t_var *v = &a->entries[idx].val;
    return likely(v->type == TYPE_INT) ? v->value._int : 0;
}

static inline t_float tphp_fn_arr_item_float(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return 0.0;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_FLOAT) ? v->value._float : 0.0;
}

static inline t_string tphp_fn_arr_item_str(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return (t_string){.data = NULL, .length = 0, .is_local = false};
    t_var *v = &a->entries[idx].val;
    if (v->type == TYPE_STRING) return v->value._string;
    if (v->type == TYPE_INT) {
        char *_b = str_pool_alloc(32);
        if (!_b) { tp_throw("arr_item_str: out of memory"); return (t_string){.data = NULL, .length = 0, .is_local = false}; }
        int n = snprintf(_b, 32, "%lld", (long long)v->value._int);
        return (t_string){.data = _b, .length = n};
    }
    return (t_string){.data = NULL, .length = 0, .is_local = false};
}

static inline t_bool tphp_fn_arr_item_bool(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return false;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_BOOL) ? v->value._bool : (v->type == TYPE_INT && v->value._int != 0);
}

static inline t_array* tphp_fn_arr_item_array(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return NULL;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_ARRAY) ? v->value._array : NULL;
}

static inline void* tphp_fn_arr_item_object(t_array *a, int idx) {
    if (unlikely(a == NULL || idx < 0 || idx >= a->length)) return NULL;
    t_var *v = &a->entries[idx].val;
    return (v->type == TYPE_OBJECT) ? v->value._ptr : NULL;
}

static inline t_callback tphp_fn_arr_item_callback(t_array *a, int idx) {
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

// count($arr, COUNT_RECURSIVE) — 递归计数（含所有嵌套数组元素）
static inline int tphp_fn_arr_count_recursive(t_array *a) {
    if (a == NULL) return 0;
    int n = a->length;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].val.type == TYPE_ARRAY) {
            n += tphp_fn_arr_count_recursive(a->entries[i].val.value._array);
        }
    }
    return n;
}

// array_pad($arr, $size, $value) — 将数组填充到指定长度
// size > 0: 在右侧填充；size < 0: 在左侧填充；|size| <= length: 原样返回（拷贝）
static inline t_array* tphp_fn_arr_pad(t_array *a, t_int size, t_var val) {
    if (a == NULL) return NULL;
    int len = a->length;
    int abs_size = size < 0 ? -size : size;
    if (abs_size <= len) {
        // 原样返回拷贝
        t_array* out = tphp_fn_arr_create(len);
        if (out == NULL) { tp_throw("arr_pad: out of memory"); return NULL; }
        for (int i = 0; i < len; i++) out->entries[i] = a->entries[i];
        out->length = len;
        return out;
    }
    int pad = abs_size - len;
    int total = abs_size;
    t_array* out = tphp_fn_arr_create(total);
    if (out == NULL) { tp_throw("arr_pad: out of memory"); return NULL; }
    if (size < 0) {
        // 左侧填充
        for (int i = 0; i < pad; i++) {
            out->entries[i].key.type = TYPE_INT;
            out->entries[i].key.value._int = i;
            out->entries[i].val = val;
        }
        for (int i = 0; i < len; i++) {
            out->entries[pad + i] = a->entries[i];
        }
    } else {
        // 右侧填充
        for (int i = 0; i < len; i++) {
            out->entries[i] = a->entries[i];
        }
        for (int i = len; i < total; i++) {
            out->entries[i].key.type = TYPE_INT;
            out->entries[i].key.value._int = i;
            out->entries[i].val = val;
        }
    }
    out->length = total;
    return out;
}

// === Pop (remove last element, return value) ===

static inline bool tphp_fn_arr_pop(t_array *a, t_var *out) {
    if (a == NULL || a->length == 0) return false;
    a->length--;
    if (out != NULL) *out = a->entries[a->length].val;
    // 释放被弹出 entry 的 key（int 键从 int_index 删除，string 键释放内存+从 str_index 删除）
    if (a->entries[a->length].key.type == TYPE_STRING) {
        if (a->str_index != NULL)
            arr_stridx_delete(a, a->entries[a->length].key.value._string);
        tphp_rt_str_free(&a->entries[a->length].key.value._string);
    } else if (a->entries[a->length].key.type == TYPE_INT) {
        if (a->int_index != NULL)
            arr_intidx_delete(a, a->entries[a->length].key.value._int);
    }
    return true;
}

// === String-key typed getters ===

static inline t_int tphp_fn_arr_get_str_int(t_array *a, t_string key) {
    t_var *v = tphp_fn_arr_get_str(a, key);
    if (v == NULL) return 0;
    if (v->type == TYPE_INT) return v->value._int;
    if (v->type == TYPE_FLOAT) return (t_int)v->value._float;
    if (v->type == TYPE_BOOL) return v->value._bool ? 1 : 0;
    return 0;
}

static inline t_string tphp_fn_arr_get_str_str(t_array *a, t_string key) {
    t_var *v = tphp_fn_arr_get_str(a, key);
    if (v == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (v->type == TYPE_STRING) return v->value._string;
    if (v->type == TYPE_INT) {
        char *_b = str_pool_alloc(32);
        if (!_b) { tp_throw("arr_get_str_str: out of memory"); return (t_string){.data = NULL, .length = 0, .is_local = false}; }
        int n = snprintf(_b, 32, "%lld", (long long)v->value._int);
        return (t_string){.data = _b, .length = n};
    }
    if (v->type == TYPE_BOOL) {
        // PHP 语义: (string)(true) = "1", (string)(false) = ""
        return v->value._bool ? STR_LIT("1") : ((t_string){.data = NULL, .length = 0, .is_local = false});
    }
    return (t_string){.data = NULL, .length = 0, .is_local = false};
}

static inline t_array* tphp_fn_arr_get_str_arr(t_array *a, t_string key) {
    t_var *v = tphp_fn_arr_get_str(a, key);
    if (v == NULL) return NULL;
    if (v->type == TYPE_ARRAY) return v->value._array;
    return NULL;
}

// === Int-key typed getters ===
//   与 tphp_fn_arr_item_* (positional) 不同，这些函数按键查找，
//   支持稀疏整数键 (如 $arr[100])。tphp_fn_arr_get_int 内部有连续键快路径。

static inline t_int tphp_fn_arr_get_int_int(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return 0;
    if (v->type == TYPE_INT) return v->value._int;
    if (v->type == TYPE_FLOAT) return (t_int)v->value._float;
    if (v->type == TYPE_BOOL) return v->value._bool ? 1 : 0;
    return 0;
}

static inline t_float tphp_fn_arr_get_int_float(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return 0.0;
    return (v->type == TYPE_FLOAT) ? v->value._float : 0.0;
}

static inline t_string tphp_fn_arr_get_int_str(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (v->type == TYPE_STRING) return v->value._string;
    if (v->type == TYPE_INT) {
        char *_b = str_pool_alloc(32);
        if (!_b) { tp_throw("arr_get_int_str: out of memory"); return (t_string){.data = NULL, .length = 0, .is_local = false}; }
        int n = snprintf(_b, 32, "%lld", (long long)v->value._int);
        return (t_string){.data = _b, .length = n};
    }
    if (v->type == TYPE_BOOL) {
        // PHP 语义: (string)(true) = "1", (string)(false) = ""
        return v->value._bool ? STR_LIT("1") : ((t_string){.data = NULL, .length = 0, .is_local = false});
    }
    return (t_string){.data = NULL, .length = 0, .is_local = false};
}

static inline t_bool tphp_fn_arr_get_int_bool(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return false;
    return (v->type == TYPE_BOOL) ? v->value._bool : (v->type == TYPE_INT && v->value._int != 0);
}

static inline t_array* tphp_fn_arr_get_int_arr(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return NULL;
    return (v->type == TYPE_ARRAY) ? v->value._array : NULL;
}

static inline void* tphp_fn_arr_get_int_object(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return NULL;
    return (v->type == TYPE_OBJECT) ? v->value._ptr : NULL;
}

static inline t_callback tphp_fn_arr_get_int_callback(t_array *a, t_int key) {
    t_var *v = tphp_fn_arr_get_int(a, key);
    if (v == NULL) return (t_callback){NULL, NULL};
    return (v->type == TYPE_CALLBACK) ? v->value._callback : (t_callback){NULL, NULL};
}

// === Free (释放 entries + 回收到复用池) ===

static inline void tphp_fn_arr_free(t_array *a) {
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

// === Shift (remove first element, shift left) ===

static inline bool tphp_fn_arr_shift(t_array *a, t_var *out) {
    if (unlikely(a == NULL || a->length == 0)) return false;
    if (out != NULL) *out = a->entries[0].val;
    // Free string key of first entry
    if (a->entries[0].key.type == TYPE_STRING)
        tphp_rt_str_free(&a->entries[0].key.value._string);
    // Invalidate index — shifting changes entry positions
    arr_stridx_free(a);
    arr_intidx_free(a);
    // Shift remaining entries left
    memmove(&a->entries[0], &a->entries[1],
            (size_t)(a->length - 1) * sizeof(t_arr_entry));
    a->length--;
    return true;
}

// === Unshift (prepend, shift right) ===

static inline int tphp_fn_arr_unshift(t_array *a, t_var val) {
    if (unlikely(a == NULL)) return 0;
    a = tphp_fn_arr_grow(a, a->length + 1);
    memmove(&a->entries[1], &a->entries[0],
            (size_t)a->length * sizeof(t_arr_entry));
    a->entries[0].key.type = TYPE_INT;
    a->entries[0].key.value._int = 0;
    a->entries[0].val = val;
    // Re-key int keys of shifted entries
    for (int i = 1; i <= a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT)
            a->entries[i].key.value._int = i;
    }
    a->length++;
    // Invalidate index — prepending changes entry positions
    arr_stridx_free(a);
    arr_intidx_free(a);
    return a->length;
}

// === Sum ===

static inline t_var tphp_fn_arr_sum(t_array *a) {
    if (unlikely(a == NULL || a->length == 0)) return VAR_INT(0);
    t_int i_sum = 0;
    t_float f_sum = 0.0;
    bool has_float = false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type == TYPE_INT) {
            if (has_float) f_sum += (t_float)v->value._int;
            else i_sum += v->value._int;
        } else if (v->type == TYPE_FLOAT) {
            if (!has_float) { has_float = true; f_sum = (t_float)i_sum; }
            f_sum += v->value._float;
        }
    }
    return has_float ? VAR_FLOAT(f_sum) : VAR_INT(i_sum);
}

// === Product ===

static inline t_var tphp_fn_arr_product(t_array *a) {
    if (unlikely(a == NULL || a->length == 0)) return VAR_INT(1);
    t_int i_prod = 1;
    t_float f_prod = 1.0;
    bool has_float = false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type == TYPE_INT) {
            if (has_float) f_prod *= (t_float)v->value._int;
            else i_prod *= v->value._int;
        } else if (v->type == TYPE_FLOAT) {
            if (!has_float) { has_float = true; f_prod = (t_float)i_prod; }
            f_prod *= v->value._float;
        }
    }
    return has_float ? VAR_FLOAT(f_prod) : VAR_INT(i_prod);
}

// === Reverse ===

static inline t_array* tphp_fn_arr_reverse(t_array *a, bool preserve_keys) {
    if (unlikely(a == NULL)) return NULL;
    t_array *r = tphp_fn_arr_create(a->length);
    if (unlikely(r == NULL)) { tp_throw("arr_reverse: out of memory"); return NULL; }
    for (int i = a->length - 1, j = 0; i >= 0; i--, j++) {
        r->entries[j] = a->entries[i];
        if (!preserve_keys) {
            r->entries[j].key.type = TYPE_INT;
            r->entries[j].key.value._int = j;
        } else if (a->entries[i].key.type == TYPE_STRING) {
            // Deep copy string key to avoid dangling pointer
            r->entries[j].key.value._string = tphp_rt_str_dup(a->entries[i].key.value._string);
        }
    }
    r->length = a->length;
    return r;
}

// === Slice ===

static inline t_array* tphp_fn_arr_slice(t_array *a, int offset, int length, bool preserve_keys) {
    if (unlikely(a == NULL)) return tphp_fn_arr_create(0);
    int alen = a->length;
    // Handle negative offset
    if (offset < 0) offset = alen + offset;
    if (offset < 0) offset = 0;
    if (offset >= alen) return tphp_fn_arr_create(0);
    // length < 0 means until end (PHP semantics: null length)
    int end;
    if (length <= 0) {
        end = alen;
    } else {
        end = offset + length;
        if (end > alen) end = alen;
    }
    int count = end - offset;
    if (count <= 0) return tphp_fn_arr_create(0);
    t_array *r = tphp_fn_arr_create(count);
    if (unlikely(r == NULL)) { tp_throw("arr_slice: out of memory"); return NULL; }
    for (int i = 0; i < count; i++) {
        r->entries[i] = a->entries[offset + i];
        if (!preserve_keys) {
            r->entries[i].key.type = TYPE_INT;
            r->entries[i].key.value._int = i;
        } else if (a->entries[offset + i].key.type == TYPE_STRING) {
            r->entries[i].key.value._string =
                tphp_rt_str_dup(a->entries[offset + i].key.value._string);
        }
    }
    r->length = count;
    return r;
}

// === array_search($needle, $haystack) → key 或 -1 ===

static inline t_int tphp_fn_arr_search(t_array *a, t_var needle) {
    if (unlikely(a == NULL)) return -1;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type != needle.type) continue;
        if (v->type == TYPE_INT    && v->value._int    == needle.value._int)    return i;
        if (v->type == TYPE_FLOAT  && v->value._float  == needle.value._float)  return i;
        if (v->type == TYPE_STRING && needle.value._string.length == v->value._string.length
            && memcmp(STR_PTR_V(v->value._string), STR_PTR_V(needle.value._string), (size_t)needle.value._string.length) == 0) return i;
        if (v->type == TYPE_BOOL   && v->value._bool   == needle.value._bool)   return i;
    }
    return -1;
}

// === shuffle — Fisher-Yates in-place ===

static inline void tphp_fn_shuffle(t_array *a) {
    if (unlikely(a == NULL || a->length <= 1)) return;
    arr_stridx_free(a);  // shuffling changes entry positions
    arr_intidx_free(a);  // shuffling changes entry positions
    for (int i = a->length - 1; i > 0; i--) {
        int j = rand() % (i + 1);
        if (j != i) {
            t_arr_entry tmp = a->entries[i];
            a->entries[i]   = a->entries[j];
            a->entries[j]   = tmp;
        }
    }
    // Re-key int keys
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT)
            a->entries[i].key.value._int = i;
    }
}

// === Sort (in-place quicksort, ascending by value) ===

// 前向声明（定义在本文件后段 ksort 区，供 sort/rsort 共用）
static int _tphp_cmp_var(const t_var *a, const t_var *b);

static inline int _arr_sort_cmp_asc(const void *a, const void *b) {
    return _tphp_cmp_var(&((const t_arr_entry *)a)->val,
                         &((const t_arr_entry *)b)->val);
}

static inline int _arr_sort_cmp_desc(const void *a, const void *b) {
    return -_arr_sort_cmp_asc(a, b);
}

static inline void tphp_fn_sort(t_array *a) {
    if (unlikely(a == NULL || a->length <= 1)) return;
    arr_stridx_free(a);  // sorting changes entry positions
    arr_intidx_free(a);  // sorting changes entry positions
    qsort(a->entries, (size_t)a->length, sizeof(t_arr_entry), _arr_sort_cmp_asc);
    // Re-key int keys after sort
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT)
            a->entries[i].key.value._int = i;
    }
}

static inline void tphp_fn_rsort(t_array *a) {
    if (unlikely(a == NULL || a->length <= 1)) return;
    arr_stridx_free(a);  // sorting changes entry positions
    arr_intidx_free(a);  // sorting changes entry positions
    qsort(a->entries, (size_t)a->length, sizeof(t_arr_entry), _arr_sort_cmp_desc);
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type == TYPE_INT)
            a->entries[i].key.value._int = i;
    }
}

// === array_unique ===

// Simple hash helper for array_unique
static inline uint32_t _arr_val_hash(t_var v) {
    if (v.type == TYPE_INT)    return (uint32_t)(v.value._int ^ (v.value._int >> 32));
    if (v.type == TYPE_FLOAT)  { uint64_t bits; memcpy(&bits, &v.value._float, 8); return (uint32_t)(bits ^ (bits >> 32)); }
    if (v.type == TYPE_STRING) {
        uint32_t h = 5381;
        for (int i = 0; i < v.value._string.length; i++)
            h = ((h << 5) + h) + (unsigned char)STR_PTR_V(v.value._string)[i];
        return h;
    }
    return (uint32_t)v.type;
}

static inline bool _arr_val_eq(t_var a, t_var b) {
    if (unlikely(a.type != b.type)) return false;
    if (a.type == TYPE_INT)    return a.value._int    == b.value._int;
    if (a.type == TYPE_FLOAT)  return a.value._float  == b.value._float;
    if (a.type == TYPE_STRING) return a.value._string.length == b.value._string.length
        && (STR_PTR_V(a.value._string) == STR_PTR_V(b.value._string)
            || memcmp(STR_PTR_V(a.value._string), STR_PTR_V(b.value._string), (size_t)a.value._string.length) == 0);
    if (a.type == TYPE_BOOL)   return a.value._bool   == b.value._bool;
    return a.type == TYPE_NULL;
}

static inline t_array* tphp_fn_arr_unique(t_array *a) {
    if (unlikely(a == NULL)) return NULL;
    t_array *r = tphp_fn_arr_create(a->length > 0 ? a->length : 4);
    if (unlikely(r == NULL)) { tp_throw("arr_unique: out of memory"); return NULL; }
    if (a->length <= 16) { // small: O(n²) is fine
        for (int i = 0; i < a->length; i++) {
            bool dup = false;
            for (int j = 0; j < r->length; j++) {
                if (_arr_val_eq(a->entries[i].val, r->entries[j].val)) { dup = true; break; }
            }
            if (!dup) r = tphp_fn_arr_push(r, a->entries[i].val);
        }
        return r;
    }
    // Large: open-addressing hash set, power-of-2 capacity
    uint32_t cap = 16; while (cap < (uint32_t)a->length * 2) cap *= 2;
    uint32_t *used = (uint32_t*)calloc(cap, sizeof(uint32_t));
    if (!used) { tp_throw("arr_unique: out of memory"); return NULL; }
    for (int i = 0; i < a->length; i++) {
        uint32_t h = _arr_val_hash(a->entries[i].val) & (cap - 1);
        int found = 0;
        while (used[h]) {
            if (_arr_val_eq(a->entries[i].val, r->entries[(int)used[h]-1].val)) { found = 1; break; }
            h = (h + 1) & (cap - 1);
        }
        if (!found) {
            used[h] = (uint32_t)(r->length + 1);
            r = tphp_fn_arr_push(r, a->entries[i].val);
        }
    }
    free(used);
    return r;
}

// === range($start, $end, $step=1) ===

static inline t_array* tphp_fn_range(t_int start, t_int end, t_int step) {
    if (step == 0) {
        tp_throw("range(): step must not be 0");
    }
    int count = 0;
    if (step > 0) {
        if (end < start) count = 0;
        else count = (int)((end - start) / step) + 1;
    } else {
        if (end > start) count = 0;
        else count = (int)((start - end) / (-step)) + 1;
    }
    t_array *r = tphp_fn_arr_create(count > 0 ? count : 4);
    if (unlikely(r == NULL)) { tp_throw("range: out of memory"); return NULL; }
    if (count <= 0) return r;
    for (t_int v = start; count > 0; v += step, count--) {
        r = tphp_fn_arr_push(r, VAR_INT(v));
    }
    return r;
}

// === array_fill($start_index, $count, $value) ===

static inline t_array* tphp_fn_arr_fill(t_int start, t_int count, t_var val) {
    if (count < 0) {
        tp_throw("arr_fill(): count must not be negative");
    }
    t_array *r = tphp_fn_arr_create(count > 0 ? count : 4);
    if (unlikely(r == NULL)) { tp_throw("arr_fill: out of memory"); return NULL; }
    for (t_int i = 0; i < count; i++) {
        r = tphp_fn_arr_set_int(r, start + i, val);
    }
    return r;
}

// ── array_key_first / array_key_last — O(1) ──────────────────
static inline t_int tphp_fn_array_key_first(t_array* a) {
    if (a == NULL || a->length == 0) return -1;
    // 返回第一个 entry 的 key（int 键时）
    if (a->entries[0].key.type == TYPE_INT) return a->entries[0].key.value._int;
    return 0; // string key 返回 0 占位
}

static inline t_int tphp_fn_array_key_last(t_array* a) {
    if (a == NULL || a->length == 0) return -1;
    int idx = a->length - 1;
    if (a->entries[idx].key.type == TYPE_INT) return a->entries[idx].key.value._int;
    return idx;
}

// ── array_rand($arr) — 随机取键 ──────────────────────────────
static inline t_int tphp_fn_rand_int(t_int min, t_int max);

static inline t_int tphp_fn_array_rand(t_array* a) {
    if (a == NULL || a->length == 0) {
        tp_throw("array_rand(): Argument #1 ($array) cannot be empty");
        return -1;
    }
    int idx = (int)tphp_fn_rand_int(0, a->length - 1);
    return (a->entries[idx].key.type == TYPE_INT) ? a->entries[idx].key.value._int : idx;
}

// ── array_is_list($arr) — PHP 8.1+，检查是否 0,1,2...n-1 键 ─
static inline t_bool tphp_fn_array_is_list_int(t_array* a) {
    if (a == NULL) return false;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key.type != TYPE_INT || a->entries[i].key.value._int != i)
            return false;
    }
    return true;
}

// ── 数组内部指针：current / key / next / prev / end / reset ───
static inline t_var tphp_fn_current(t_array* a) {
    if (a == NULL || a->length == 0) return (t_var){TYPE_NULL, {0}};
    if (a->cursor < 0 || a->cursor >= a->length) return (t_var){TYPE_NULL, {0}};
    return a->entries[a->cursor].val;
}

static inline t_var tphp_fn_key(t_array* a) {
    if (a == NULL || a->length == 0) return (t_var){TYPE_NULL, {0}};
    if (a->cursor < 0 || a->cursor >= a->length) return (t_var){TYPE_NULL, {0}};
    return a->entries[a->cursor].key;
}

static inline t_var tphp_fn_next(t_array* a) {
    if (a == NULL || a->length == 0) return (t_var){TYPE_NULL, {0}};
    a->cursor++;
    if (a->cursor >= a->length) return (t_var){TYPE_NULL, {0}};
    return a->entries[a->cursor].val;
}

static inline t_var tphp_fn_prev(t_array* a) {
    if (a == NULL || a->length == 0) return (t_var){TYPE_NULL, {0}};
    a->cursor--;
    if (a->cursor < 0) return (t_var){TYPE_NULL, {0}};
    return a->entries[a->cursor].val;
}

static inline t_var tphp_fn_end(t_array* a) {
    if (a == NULL || a->length == 0) return (t_var){TYPE_NULL, {0}};
    a->cursor = a->length - 1;
    return a->entries[a->cursor].val;
}

static inline t_var tphp_fn_reset(t_array* a) {
    if (a == NULL || a->length == 0) return (t_var){TYPE_NULL, {0}};
    a->cursor = 0;
    return a->entries[0].val;
}

// ── 第二梯队数组函数 ────────────────────────────────────────

// array_chunk($arr, $size) — 分组切片
static inline t_array* tphp_fn_array_chunk(t_array* a, t_int size) {
    t_array* out = tphp_fn_arr_create(0);
    if (out == NULL) { tp_throw("array_chunk: out of memory"); return NULL; }
    tphp_rt_register((void*)out, 1);
    if (size < 1) {
        tp_throw("array_chunk(): Argument #2 ($length) must be greater than 0");
        return out;
    }
    if (a == NULL || a->length == 0) return out;
    int chunks = (a->length + (int)size - 1) / (int)size;
    for (int c = 0; c < chunks; c++) {
        t_array* chunk = tphp_fn_arr_create((int)size);
        if (chunk == NULL) { tp_throw("array_chunk: out of memory"); return out; }
        tphp_rt_register((void*)chunk, 1);
        int start = c * (int)size;
        int end = start + (int)size;
        if (end > a->length) end = a->length;
        for (int i = start; i < end; i++)
            chunk = tphp_fn_arr_push(chunk, a->entries[i].val);
        out = tphp_fn_arr_push(out, VAR_ARRAY(chunk));
    }
    return out;
}

// array_combine($keys, $values) — 键值合并
static inline t_array* tphp_fn_array_combine(t_array* keys, t_array* values) {
    if (keys == NULL || values == NULL) return NULL;
    if (keys->length != values->length) {
        tp_throw("array_combine(): keys and values must be the same length");
        return NULL;
    }
    t_array* out = tphp_fn_arr_create(keys->length);
    if (out == NULL) { tp_throw("array_combine: out of memory"); return NULL; }
    tphp_rt_register((void*)out, 1);
    for (int i = 0; i < keys->length; i++) {
        t_var k = keys->entries[i].val;
        t_var v = values->entries[i].val;
        if (k.type == TYPE_INT)
            out = tphp_fn_arr_set_int(out, k.value._int, v);
        else if (k.type == TYPE_STRING)
            out = tphp_fn_arr_set_str(out, k.value._string, v);
    }
    return out;
}

// array_flip($arr) — 键值互换
static inline t_array* tphp_fn_array_flip(t_array* a) {
    t_array* out = tphp_fn_arr_create(a ? a->length : 4);
    if (out == NULL) { tp_throw("array_flip: out of memory"); return NULL; }
    tphp_rt_register((void*)out, 1);
    if (a == NULL) return out;
    for (int i = 0; i < a->length; i++) {
        t_var *kv = &a->entries[i].key;
        t_var *vv = &a->entries[i].val;
        if (vv->type == TYPE_INT)
            out = tphp_fn_arr_set_int(out, vv->value._int, *kv);
        else if (vv->type == TYPE_STRING)
            out = tphp_fn_arr_set_str(out, vv->value._string, *kv);
    }
    return out;
}

// array_column($arr, $col) — 提取列
// col 为 string 键名或 int 列索引（不支持 null 第3参数，暂支持双参）
static inline t_array* tphp_fn_array_column_str(t_array* a, t_string col) {
    t_array* out = tphp_fn_arr_create(a ? a->length : 4);
    if (out == NULL) { tp_throw("array_column_str: out of memory"); return NULL; }
    tphp_rt_register((void*)out, 1);
    if (a == NULL) return out;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type == TYPE_ARRAY && v->value._array != NULL) {
            t_array *row = v->value._array;
            for (int j = 0; j < row->length; j++) {
                if (row->entries[j].key.type == TYPE_STRING &&
                    tphp_rt_str_eq(row->entries[j].key.value._string, col)) {
                    out = tphp_fn_arr_push(out, row->entries[j].val);
                    break;
                }
            }
        } else if (v->type == TYPE_OBJECT) {
            // 对象暂不支持 > 返回 null 占位
            out = tphp_fn_arr_push(out, (t_var){TYPE_NULL, {0}});
        }
    }
    return out;
}


// ── ksort/krsort/asort/arsort (qsort pointer sort) ──────

// 通用 t_var 比较：先按 type tag 排（NULL<BOOL<INT<FLOAT<STRING<ARRAY<OBJECT<CALLBACK），
// 同类型内部按值排。行为对齐 PHP sort() 对混合类型的处理。
static int _tphp_cmp_var(const t_var *a, const t_var *b) {
    if (a->type != b->type) {
        // 不同类型：按 type tag 数值升序（与 PHP 大致一致：NULL<BOOL<INT<FLOAT<STRING<ARRAY<OBJECT）
        return (int)a->type - (int)b->type;
    }
    switch (a->type) {
        case TYPE_NULL:
            return 0;
        case TYPE_BOOL:
            return (int)(a->value._bool > b->value._bool) - (int)(a->value._bool < b->value._bool);
        case TYPE_INT:
            return (a->value._int > b->value._int) - (a->value._int < b->value._int);
        case TYPE_FLOAT: {
            t_float d = a->value._float - b->value._float;
            return (d > 0) - (d < 0);
        }
        case TYPE_STRING: {
            int la = a->value._string.length;
            int lb = b->value._string.length;
            int n = la < lb ? la : lb;
            int c = (n > 0) ? memcmp(STR_PTR(a->value._string), STR_PTR(b->value._string), (size_t)n) : 0;
            return c != 0 ? c : (la - lb);
        }
        default:
            // ARRAY/OBJECT/CALLBACK 不参与值排序，保持稳定
            return 0;
    }
}

static int _cmp_val(const void *a, const void *b) {
    return _tphp_cmp_var(&(*(const t_arr_entry* const*)a)->val,
                         &(*(const t_arr_entry* const*)b)->val);
}
static int _cmp_val_r(const void *a, const void *b) {
    return _tphp_cmp_var(&(*(const t_arr_entry* const*)b)->val,
                         &(*(const t_arr_entry* const*)a)->val);
}
static int _cmp_key(const void *a, const void *b) {
    return _tphp_cmp_var(&(*(const t_arr_entry* const*)a)->key,
                         &(*(const t_arr_entry* const*)b)->key);
}
static int _cmp_key_r(const void *a, const void *b) {
    return _tphp_cmp_var(&(*(const t_arr_entry* const*)b)->key,
                         &(*(const t_arr_entry* const*)a)->key);
}
static inline void tphp_fn_asort(t_array* a) {
    if (a == NULL || a->length < 2) return;
    arr_stridx_free(a);  // sorting changes entry positions
    arr_intidx_free(a);  // sorting changes entry positions
    t_arr_entry **ptrs = (t_arr_entry**)malloc((size_t)a->length * sizeof(t_arr_entry*));
    if (!ptrs) { tp_throw("asort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) ptrs[i] = &a->entries[i];
    qsort(ptrs, (size_t)a->length, sizeof(t_arr_entry*), _cmp_val);
    t_arr_entry *tmp = (t_arr_entry*)malloc((size_t)a->length * sizeof(t_arr_entry));
    if (!tmp) { free(ptrs); tp_throw("asort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) tmp[i] = *ptrs[i];
    for (int i = 0; i < a->length; i++) a->entries[i] = tmp[i];
    free(tmp); free(ptrs);
}
static inline void tphp_fn_arsort(t_array* a) {
    if (a == NULL || a->length < 2) return;
    arr_stridx_free(a);  // sorting changes entry positions
    arr_intidx_free(a);  // sorting changes entry positions
    t_arr_entry **ptrs = (t_arr_entry**)malloc((size_t)a->length * sizeof(t_arr_entry*));
    if (!ptrs) { tp_throw("arsort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) ptrs[i] = &a->entries[i];
    qsort(ptrs, (size_t)a->length, sizeof(t_arr_entry*), _cmp_val_r);
    t_arr_entry *tmp = (t_arr_entry*)malloc((size_t)a->length * sizeof(t_arr_entry));
    if (!tmp) { free(ptrs); tp_throw("arsort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) tmp[i] = *ptrs[i];
    for (int i = 0; i < a->length; i++) a->entries[i] = tmp[i];
    free(tmp); free(ptrs);
}
static inline void tphp_fn_ksort(t_array* a) {
    if (a == NULL || a->length < 2) return;
    arr_stridx_free(a);  // sorting changes entry positions
    arr_intidx_free(a);  // sorting changes entry positions
    t_arr_entry **ptrs = (t_arr_entry**)malloc((size_t)a->length * sizeof(t_arr_entry*));
    if (!ptrs) { tp_throw("ksort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) ptrs[i] = &a->entries[i];
    qsort(ptrs, (size_t)a->length, sizeof(t_arr_entry*), _cmp_key);
    t_arr_entry *tmp = (t_arr_entry*)malloc((size_t)a->length * sizeof(t_arr_entry));
    if (!tmp) { free(ptrs); tp_throw("ksort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) tmp[i] = *ptrs[i];
    for (int i = 0; i < a->length; i++) a->entries[i] = tmp[i];
    free(tmp); free(ptrs);
}
static inline void tphp_fn_krsort(t_array* a) {
    if (a == NULL || a->length < 2) return;
    arr_stridx_free(a);  // sorting changes entry positions
    arr_intidx_free(a);  // sorting changes entry positions
    t_arr_entry **ptrs = (t_arr_entry**)malloc((size_t)a->length * sizeof(t_arr_entry*));
    if (!ptrs) { tp_throw("krsort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) ptrs[i] = &a->entries[i];
    qsort(ptrs, (size_t)a->length, sizeof(t_arr_entry*), _cmp_key_r);
    t_arr_entry *tmp = (t_arr_entry*)malloc((size_t)a->length * sizeof(t_arr_entry));
    if (!tmp) { free(ptrs); tp_throw("krsort: out of memory"); return; }
    for (int i = 0; i < a->length; i++) tmp[i] = *ptrs[i];
    for (int i = 0; i < a->length; i++) a->entries[i] = tmp[i];
    free(tmp); free(ptrs);
}