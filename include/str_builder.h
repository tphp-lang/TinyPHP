#pragma once
// ============================================================
// str_builder.h — klib kstring 驱动的字符串构建器
//   零拷贝追加: kputsn → memcpy 整块数据
//   批量拼接: vsnprintf 测量 → malloc 精确分配
//   用法: sb_init() → sb_write_c/sb_write → sb_finish() → free
// ============================================================

#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <stdio.h>
#include "klib/kstring.h"
#include "types.h"
#include "runtime.h" // str_pool_alloc

// ── 构建器初始化 ──
static inline kstring_t sb_init(void) {
    return (kstring_t){0, 0, NULL};
}

// ── 追加 C 字符串 (零拷贝, 直接 memcpy) ──
static inline void sb_write_c(kstring_t *ks, const char *s, int len) {
    if (len <= 0) return;
    kroundup32(ks->m);
    if (ks->l + len + 1 > (int)ks->m) {
        ks->m = (size_t)(ks->l + len + 1);
        kroundup32(ks->m);
        ks->s = (char*)realloc(ks->s, ks->m);
    }
    memcpy(ks->s + ks->l, s, (size_t)len);
    ks->l += len;
    ks->s[ks->l] = '\0';
}

// ── 追加 TinyPHP 字符串 ──
static inline void sb_write(kstring_t *ks, t_string s) {
    const char *src = STR_PTR(s);
    if (src != NULL && s.length > 0) sb_write_c(ks, src, s.length);
}

// ── 追加字符 ──
static inline void sb_putc(kstring_t *ks, int c) {
    if (ks->l + 2 > (int)ks->m) {
        ks->m = (size_t)(ks->l + 2);
        kroundup32(ks->m);
        ks->s = (char*)realloc(ks->s, ks->m);
    }
    ks->s[ks->l++] = (char)c;
    ks->s[ks->l] = '\0';
}

// ── 格式化追加 ──
static inline int sb_printf(kstring_t *ks, const char *fmt, ...) {
    va_list ap;
    va_start(ap, fmt);
    int ret = kvsprintf(ks, fmt, ap);
    va_end(ap);
    return ret;
}

// ── 获取当前长度 ──
static inline int sb_len(const kstring_t *ks) { return (int)ks->l; }

// ── 获取 C 字符串指针 ──
static inline const char* sb_cstr(const kstring_t *ks) { return ks->s ? ks->s : ""; }

// ── 释放构建器内存 ──
static inline void sb_free(kstring_t *ks) {
    free(ks->s);
    ks->s = NULL;
    ks->l = ks->m = 0;
}

// ── 转换为 t_string (复制到内存池) ──
static inline t_string sb_finish_via_pool(kstring_t *ks) {
    if (ks->l <= 0) { sb_free(ks); return (t_string){NULL, 0}; }
    char *d = str_pool_alloc(ks->l);
    if (d) { memcpy(d, ks->s, (size_t)ks->l); d[ks->l] = '\0'; }
    sb_free(ks);
    return d ? (t_string){d, ks->l} : (t_string){NULL, 0};
}

// ── 转换为 t_string (转移所有权到池, 避免复制) ──
static inline t_string sb_move_to_pool(kstring_t *ks) {
    if (ks->l <= 0) { free(ks->s); return (t_string){NULL, 0}; }
    // 尝试收窄到池 (如果 ≤512 且池有空间, copy+free)
    if (ks->l <= 512) {
        t_string r = sb_finish_via_pool(ks); // 会 free ks->s
        return r;
    }
    // 大字符串: 直接持有 realloc 的内存
    char *d = (char*)realloc(ks->s, (size_t)(ks->l + 1));
    return (t_string){.data = d, .length = (int)ks->l, .is_local = false};
}
