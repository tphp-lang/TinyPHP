#pragma once
// tphp_hash.h — khash 包装, 提供 O(1) string→int 映射
// 不侵入 t_array, 作为独立优化路径使用

#include <stdlib.h>
#include <string.h>
#include "khash.h"
#include "../types.h"

// string → int 映射
KHASH_MAP_INIT_STR(tp_hsi, int)

static inline khash_t(tp_hsi)* tphp_hash_new(void) {
    return kh_init(tp_hsi);
}

static inline void tphp_hash_put(khash_t(tp_hsi) *h, t_string key, int val) {
    int absent;
    khint_t k = kh_put(tp_hsi, h, STR_PTR(key), &absent);
    kh_val(h, k) = val;
}

// O(1) 查找, 返回 -1 表示不存在
static inline int tphp_hash_get(khash_t(tp_hsi) *h, t_string key) {
    khint_t k = kh_get(tp_hsi, h, STR_PTR(key));
    return (k != kh_end(h)) ? kh_val(h, k) : -1;
}

static inline void tphp_hash_del(khash_t(tp_hsi) *h, t_string key) {
    khint_t k = kh_get(tp_hsi, h, STR_PTR(key));
    if (k != kh_end(h)) kh_del(tp_hsi, h, k);
}

static inline void tphp_hash_free(khash_t(tp_hsi) *h) {
    kh_destroy(tp_hsi, h);
}

static inline int tphp_hash_size(khash_t(tp_hsi) *h) {
    return kh_size(h);
}
