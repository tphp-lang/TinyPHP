#pragma once

#include <stdlib.h>
#include <string.h>
#include "types.h"

// ============================================================
// PHP 万能数组 API
//
//   键: int | string，值: 任意 t_var
//   堆分配 + 引用计数，递归释放嵌套数组
//
// PHP → C 转译示例:
//   $a = [1, "hi", true];          → t_array* a = ... tphp_fn_arr_push(a, VAR_INT(1)) ...
//   $a["key"] = 42;                → tphp_fn_arr_set_str(a, STR_LIT("key"), VAR_INT(42))
//   $b = $a[0];                    → t_var* b = tphp_fn_arr_index(a, 0);
//   $nested = [1, [2, 3]];         → 嵌套时 t_array* 自动引用计数
// ============================================================

// ── 辅助函数前向声明（GCC 兼容） ──────────────────────
static int tphp_fn_str_hash(t_string s);

// ── 生命周期 ─────────────────────────────────────────────

/** 创建空数组 */
static inline t_array* tphp_fn_arr_create(void) {
    t_array* a = (t_array*)calloc(1, sizeof(t_array));
    if (a != NULL) a->refcount = 1;
    return a;
}

/** 保留数组（refcount++) */
static inline t_array* tphp_fn_arr_retain(t_array* a) {
    if (a != NULL) a->refcount++;
    return a;
}

/** 释放数组（递归释放所有条目，含嵌套数组） */
void tphp_fn_arr_free(t_array* a);
// 注：实现在下方，因为递归需要完整定义

// ── 写操作 ────────────────────────────────────────

/** 确保容量 */
static inline void tphp_fn_arr_grow(t_array* a, int need) {
    if (a == NULL || need <= a->capacity) return;
    int nc = a->capacity ? a->capacity * 2 : 8;
    if (nc < need) nc = need;
    t_entry* ne = (t_entry*)realloc(a->entries, (size_t)nc * sizeof(t_entry));
    if (ne == NULL) return;
    a->entries = ne;
    a->capacity = nc;
}

/** 追加元素（自动 int 键，类似 $a[] = val） */
static inline void tphp_fn_arr_push(t_array* a, t_var val) {
    if (a == NULL) return;
    tphp_fn_arr_grow(a, a->length + 1);
    // 分配 key
    t_var* k = (t_var*)malloc(sizeof(t_var));
    if (k == NULL) return;
    k->type = TYPE_INT;
    k->value._int = (t_int)a->length;
    // 分配 value
    t_var* v = (t_var*)malloc(sizeof(t_var));
    if (v == NULL) { free(k); return; }
    *v = val;
    // 如果 push 的是数组，retain 它
    if (val.type == TYPE_ARRAY && val.value._array != NULL) {
        tphp_fn_arr_retain(val.value._array);
    }
    // 计算 hash
    int hash = (int)(size_t)a->length;
    a->entries[a->length] = (t_entry){k, v, hash};
    a->length++;
}

/** 设置字符串键 */
static inline void tphp_fn_arr_set_str(t_array* a, t_string key, t_var val) {
    if (a == NULL) return;
    // 查找是否已存在
    int khash = tphp_fn_str_hash(key);
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key == NULL) continue;
        if (a->entries[i].hash == khash &&
            a->entries[i].key->type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[i].key->value._string, key)) {
            // 替换 value
            if (a->entries[i].value->type == TYPE_ARRAY && a->entries[i].value->value._array != NULL) {
                tphp_fn_arr_free(a->entries[i].value->value._array);
            }
            *a->entries[i].value = val;
            if (val.type == TYPE_ARRAY && val.value._array != NULL) {
                tphp_fn_arr_retain(val.value._array);
            }
            return;
        }
    }
    // 新增
    tphp_fn_arr_grow(a, a->length + 1);
    t_var* k = (t_var*)malloc(sizeof(t_var));
    if (k == NULL) return;
    k->type = TYPE_STRING;
    k->value._string = key;
    t_var* v = (t_var*)malloc(sizeof(t_var));
    if (v == NULL) { free(k); return; }
    *v = val;
    if (val.type == TYPE_ARRAY && val.value._array != NULL) {
        tphp_fn_arr_retain(val.value._array);
    }
    a->entries[a->length] = (t_entry){k, v, khash};
    a->length++;
}

/** 设置整数键 */
static inline void tphp_fn_arr_set_int(t_array* a, t_int key, t_var val) {
    if (a == NULL) return;
    int khash = (int)(size_t)key;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key == NULL) continue;
        if (a->entries[i].hash == khash &&
            a->entries[i].key->type == TYPE_INT &&
            a->entries[i].key->value._int == key) {
            if (a->entries[i].value->type == TYPE_ARRAY && a->entries[i].value->value._array != NULL) {
                tphp_fn_arr_free(a->entries[i].value->value._array);
            }
            *a->entries[i].value = val;
            if (val.type == TYPE_ARRAY && val.value._array != NULL) {
                tphp_fn_arr_retain(val.value._array);
            }
            return;
        }
    }
    tphp_fn_arr_grow(a, a->length + 1);
    t_var* k = (t_var*)malloc(sizeof(t_var));
    if (k == NULL) return;
    k->type = TYPE_INT;
    k->value._int = key;
    t_var* v = (t_var*)malloc(sizeof(t_var));
    if (v == NULL) { free(k); return; }
    *v = val;
    if (val.type == TYPE_ARRAY && val.value._array != NULL) {
        tphp_fn_arr_retain(val.value._array);
    }
    a->entries[a->length] = (t_entry){k, v, khash};
    a->length++;
}

// ── 读操作 ────────────────────────────────────────

/** 按位置索引获取 */
static inline t_var* tphp_fn_arr_index(t_array* a, int idx) {
    if (a == NULL || idx < 0 || idx >= a->length) return NULL;
    return a->entries[idx].value;
}

/** 按位置获取 int 值 */
static inline t_int tphp_arr_item_int(t_array* a, int idx) {
    t_var* v = tphp_fn_arr_index(a, idx);
    if (v == NULL || v->type != TYPE_INT) return 0;
    return v->value._int;
}
/** 按位置获取 float 值 */
static inline t_float tphp_arr_item_float(t_array* a, int idx) {
    t_var* v = tphp_fn_arr_index(a, idx);
    if (v == NULL || v->type != TYPE_FLOAT) return 0.0;
    return v->value._float;
}
/** 按位置获取 string 值 */
static inline t_string tphp_arr_item_str(t_array* a, int idx) {
    t_var* v = tphp_fn_arr_index(a, idx);
    if (v == NULL || v->type != TYPE_STRING) return (t_string){NULL, 0};
    return v->value._string;
}
/** 按位置获取 bool 值 */
static inline t_bool tphp_arr_item_bool(t_array* a, int idx) {
    t_var* v = tphp_fn_arr_index(a, idx);
    if (v == NULL || v->type != TYPE_BOOL) return false;
    return v->value._bool;
}

/** 按 int 键查找 */
static inline t_var* tphp_fn_arr_get_int(t_array* a, t_int key) {
    if (a == NULL) return NULL;
    int khash = (int)(size_t)key;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key != NULL &&
            a->entries[i].hash == khash &&
            a->entries[i].key->type == TYPE_INT &&
            a->entries[i].key->value._int == key) {
            return a->entries[i].value;
        }
    }
    return NULL;
}

/** 按 string 键查找 */
static inline t_var* tphp_fn_arr_get_str(t_array* a, t_string key) {
    if (a == NULL) return NULL;
    int khash = tphp_fn_str_hash(key);
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key != NULL &&
            a->entries[i].hash == khash &&
            a->entries[i].key->type == TYPE_STRING &&
            tphp_rt_str_eq(a->entries[i].key->value._string, key)) {
            return a->entries[i].value;
        }
    }
    return NULL;
}

/** 元素个数 */
static inline int tphp_fn_arr_count(t_array* a) {
    return a ? a->length : 0;
}

/** 是否存在 int 键 */
static inline bool tphp_fn_arr_has_int(t_array* a, t_int key) {
    return tphp_fn_arr_get_int(a, key) != NULL;
}

/** 是否存在 str 键 */
static inline bool tphp_fn_arr_has_str(t_array* a, t_string key) {
    return tphp_fn_arr_get_str(a, key) != NULL;
}

// ── 释放实现 ────────────────────────────────────────

void tphp_fn_arr_free(t_array* a) {
    if (a == NULL) return;
    if (--a->refcount > 0) return;
    for (int i = 0; i < a->length; i++) {
        if (a->entries[i].key != NULL) {
            // 如果 key 是 string 且 data 不在 .rodata，需要释放
            // （当前简化：全部 free key 结构体，不释放 string.data）
            free(a->entries[i].key);
        }
        if (a->entries[i].value != NULL) {
            t_var* v = a->entries[i].value;
            // 递归释放嵌套数组
            if (v->type == TYPE_ARRAY && v->value._array != NULL) {
                tphp_fn_arr_free(v->value._array);
            }
            free(v);
        }
    }
    free(a->entries);
    free(a);
}

// ── 辅助函数 ────────────────────────────────────────

/** 字符串 hash（djb2） */
static inline int tphp_fn_str_hash(t_string s) {
    int hash = 5381;
    if (s.data == NULL) return hash;
    for (int i = 0; i < s.length; i++) {
        hash = ((hash << 5) + hash) + (unsigned char)s.data[i]; // hash * 33 + c
    }
    return hash;
}


