#pragma once
// Arena allocator wrapper (基于 klib kalloc 概念的简化版)
// 非线程安全，适合请求级内存管理

#include <stdlib.h>
#include <string.h>

typedef struct {
    char  *buf;
    size_t len;
    size_t cap;
} tphp_arena_t;

static inline tphp_arena_t tphp_arena_new(size_t capacity) {
    tphp_arena_t a = {0};
    a.cap = capacity > 0 ? capacity : 65536;
    a.buf = (char*)malloc(a.cap);
    return a;
}

static inline void* tphp_arena_alloc(tphp_arena_t *a, size_t size) {
    if (a->len + size > a->cap) {
        a->cap = (a->len + size) * 2;
        a->buf = (char*)realloc(a->buf, a->cap);
    }
    void *p = a->buf + a->len;
    a->len += size;
    return p;
}

static inline void tphp_arena_free(tphp_arena_t *a) {
    free(a->buf);
    a->buf = NULL;
    a->len = a->cap = 0;
}
