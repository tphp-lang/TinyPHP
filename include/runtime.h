#pragma once

// ============================================================
// runtime.h — TinyPHP 运行时内部辅助函数
//
//   这些函数为转译器自动生成代码所依赖，用户 PHP 代码不直接调用。
//   包括：初始化、内存管理、字符串转换/比较/拼接、对象析构等。
// ============================================================

#ifndef _USE_MATH_DEFINES
#define _USE_MATH_DEFINES
#endif
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include "types.h"

#ifdef _WIN32
#include <windows.h>
#endif
#include <math.h>

// ── 初始化 ────────────────────────────────────────────

/** tphp_rt_init — 初始化运行时（Windows 下设置控制台 UTF-8，预热数组池） */
static inline void tphp_rt_init(void) {
#ifdef _WIN32
    SetConsoleOutputCP(65001); // CP_UTF8
    SetConsoleCP(65001);
#endif
#if TPHP_USE_WIN_TLS || TPHP_USE_PTHREAD_TLS
    tphp_tls_init();  // 初始化主线程 TLS slot
#endif
    // 预热数组复用池：预分配 16 个空数组，后续 [] 从池 O(1) 获取
    for (int _pi = 0; _pi < 16 && arr_freelist_count < ARR_POOL_MAX; _pi++) {
        size_t _sz = sizeof(t_array) + (size_t)4 * sizeof(t_arr_entry);
        t_array *_pa = (t_array*)calloc(1, _sz);
        if (_pa) {
            _pa->refcount = 1;
            _pa->capacity = 4;
            _pa->length   = 0;
            arr_freelist[arr_freelist_count++] = _pa;
        }
    }
}

// ── 安全内存 ──────────────────────────────────────────

/** tphp_rt_malloc — 安全的 malloc，失败则 abort（calloc 零初始化） */
static inline void* tphp_rt_malloc(size_t size) {
    void* p = calloc(1, size);
    if (p == NULL) { fputs("FATAL: out of memory\n", stderr); abort(); }
    return p;
}

/** tphp_rt_free — 安全的 free，先检查非空再释放 */
static inline void tphp_rt_free(void* p) {
    if (p != NULL) free(p);
}

/** tphp_err — 运行时致命错误 */
static void tphp_rt_err(const char* msg) {
    fprintf(stderr, "Fatal error: %s\n", msg);
    abort();
}

// ── 入口参数 ──────────────────────────────────────────

/** tphp_rt_build_argv — 将 C 的 char** argv 转为 t_array*（万能数组） */
static inline t_array* tphp_rt_build_argv(int argc, char **argv) {
    t_array* a = tphp_fn_arr_create(0);
    if (a == NULL) return NULL;
    for (int i = 0; i < argc; i++) {
        if (argv[i] != NULL) {
            t_string s = {argv[i], (int)strlen(argv[i])};
            a = tphp_fn_arr_push(a, VAR_STRING(s));
        } else {
            a = tphp_fn_arr_push(a, VAR_STRING(((t_string){.data = NULL, .length = 0, .is_local = false})));
        }
    }
    return a;
}

// ── 前置声明 ─────────────────────────────────────────
static inline char* str_pool_alloc(int len);

// ── 类型转换辅助 ─────────────────────────────────────

/** int → t_string */
static inline t_string tphp_rt_str_from_int(t_int v) {
    char* buf = str_pool_alloc(32);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = snprintf(buf, 32, "%lld", (long long)v);
    return (t_string){.data = buf, .length = len > 0 ? len : 0, .is_local = false};
}

static inline t_string tphp_rt_str_from_float(t_float v) {
    char* buf = str_pool_alloc(64);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = snprintf(buf, 64, "%g", v);
    return (t_string){.data = buf, .length = len > 0 ? len : 0, .is_local = false};
}

static inline t_string tphp_rt_str_from_bool(t_bool v) {
    return v ? STR_LIT("true") : STR_LIT("false");
}

// ── 字符串解析 ────────────────────────────────────────

/** 从字符串解析整数（跳前导空白，符号位，连续数字） */
static inline t_int tphp_rt_parse_int(t_string s) {
    const char *d = STR_PTR(s);
    if (d == NULL || s.length <= 0) return 0;
    int i = 0;
    while (i < s.length && (d[i] == ' ' || d[i] == '\t')) i++;
    int sign = 1;
    if (i < s.length && d[i] == '-') { sign = -1; i++; }
    else if (i < s.length && d[i] == '+') { i++; }
    t_int val = 0;
    while (i < s.length && d[i] >= '0' && d[i] <= '9') {
        val = val * 10 + (t_int)(d[i] - '0');
        i++;
    }
    return val * sign;
}

/** 从字符串解析浮点数（支持科学计数法） */
static inline t_float tphp_rt_parse_float(t_string s) {
    const char *d = STR_PTR(s);
    if (d == NULL || s.length <= 0) return 0.0;
    char temp[128];
    int len = (s.length < 127) ? s.length : 127;
    memcpy(temp, d, (size_t)len);
    temp[len] = '\0';
    return strtod(temp, NULL);
}

/** 字符串是否为 PHP 假值（空串或 "0"） */
static inline t_bool tphp_rt_str_is_falsy(t_string s) {
    const char *d = STR_PTR(s);
    if (d == NULL || s.length <= 0) return true;
    if (s.length == 1 && d[0] == '0') return true;
    return false;
}

// ── 字符串比较 ────────────────────────────────────────

/** tphp_str_cmp — 字典序比较（memcmp 语义，返回 -1/0/1） */
static inline int tphp_rt_str_cmp(t_string a, t_string b) {
    const char *ad = STR_PTR(a), *bd = STR_PTR(b);
    if (ad == bd) return 0;
    if (ad == NULL) return -1;
    if (bd == NULL) return 1;
    int minlen = (a.length < b.length) ? a.length : b.length;
    int r = memcmp(ad, bd, (size_t)minlen);
    if (r != 0) return r;
    if (a.length < b.length) return -1;
    if (a.length > b.length) return 1;
    return 0;
}

static inline t_bool tphp_rt_str_lt(t_string a, t_string b)  { return tphp_rt_str_cmp(a, b) < 0; }
static inline t_bool tphp_rt_str_le(t_string a, t_string b)  { return tphp_rt_str_cmp(a, b) <= 0; }
static inline t_bool tphp_rt_str_gt(t_string a, t_string b)  { return tphp_rt_str_cmp(a, b) > 0; }
static inline t_bool tphp_rt_str_ge(t_string a, t_string b)  { return tphp_rt_str_cmp(a, b) >= 0; }
static inline t_bool tphp_rt_str_eq(t_string a, t_string b)  { return tphp_rt_str_cmp(a, b) == 0; }
static inline t_bool tphp_rt_str_ne(t_string a, t_string b)  { return tphp_rt_str_cmp(a, b) != 0; }

// ── 字符串池 + Arena (bump allocator, 128KB主池 + 链接溢出块) ──

// STR_POOL_SIZE 已在 types.h 中定义（供 tls.h 使用）
#include "compat/tls.h"  // TCC+Windows/TCC+macOS 时定义 str_pool_buf 等访问宏

#if !TPHP_USE_WIN_TLS && !TPHP_USE_PTHREAD_TLS
static _Thread_local char  str_pool_buf[STR_POOL_SIZE];
static _Thread_local char *str_pool_cur = NULL;  /* lazy init in str_pool_alloc */
#endif

// Arena 溢出块链表（主池满时分配，tphp_rt_free_all 时释放）
typedef struct _str_arena_block {
    char  *buf;
    char  *cur;
    int    size;
    struct _str_arena_block *next;
} str_arena_block;
#if !TPHP_USE_WIN_TLS && !TPHP_USE_PTHREAD_TLS
static _Thread_local str_arena_block *str_arena_head = NULL;
#endif

/** 分配一个新的 arena 块（64KB），挂在链表头部 */
static inline str_arena_block* str_arena_new_block(int minSize) {
    int sz = (minSize + 1 > 65536) ? (minSize + 1 + 4095) & ~4095 : 65536;
    str_arena_block *b = (str_arena_block*)malloc(sizeof(str_arena_block));
    if (unlikely(b == NULL)) return NULL;
    b->buf = (char*)malloc((size_t)sz);
    if (unlikely(b->buf == NULL)) { free(b); return NULL; }
    b->cur  = b->buf;
    b->size = sz;
    b->next = str_arena_head;
    str_arena_head = b;
    return b;
}

/** 释放所有 arena 溢出块 */
static inline void str_arena_free_all(void) {
    str_arena_block *b = str_arena_head;
    while (b) {
        str_arena_block *next = b->next;
        if (b->buf) free(b->buf);
        free(b);
        b = next;
    }
    str_arena_head = NULL;
}

/** 从小字符串池分配 len+1 字节（<=512 用池；池满→arena块；超512→独立malloc） */
static inline char* str_pool_alloc(int len) {
    if (len <= 0) return NULL;
    // 首次调用：初始化 bump 指针（每个线程独立的 str_pool_buf）
    if (unlikely(str_pool_cur == NULL)) str_pool_cur = str_pool_buf;
    // 大块：直接 malloc（不走池也不走 arena）
    if (unlikely(len > 512)) return (char*)malloc((size_t)len + 1);
    // 主池足够 → O(1) bump
    if (likely(str_pool_cur + len + 1 <= str_pool_buf + STR_POOL_SIZE)) {
        char *p = str_pool_cur;
        str_pool_cur += len + 1;
        return p;
    }
    // 主池满 → 尝试当前 arena 块，不够则新建块
    str_arena_block *b = str_arena_head;
    if (b == NULL || b->cur + len + 1 > b->buf + b->size) {
        b = str_arena_new_block(len);
        if (unlikely(b == NULL)) return (char*)malloc((size_t)len + 1);
    }
    char *p = b->cur;
    b->cur += len + 1;
    return p;
}

// ── 字符串拼接/拷贝/释放 ─────────────────────────────

/** tphp_str_concat — 字符串拼接 */
static inline t_string tphp_rt_str_concat(t_string a, t_string b) {
    const char *ad = STR_PTR(a), *bd = STR_PTR(b);
    int alen = (a.length > 0 && ad != NULL) ? a.length : 0;
    int blen = (b.length > 0 && bd != NULL) ? b.length : 0;
    if (alen == 0 && blen == 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (alen < 0) alen = 0; if (blen < 0) blen = 0;
    if (alen > 0x7FFFFF || blen > 0x7FFFFF) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len = alen + blen;
    if (len <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    char* data = str_pool_alloc(len);
    if (data == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int pos = 0;
    if (alen > 0) { memcpy(data + pos, ad, (size_t)alen); pos += alen; }
    if (blen > 0) { memcpy(data + pos, bd, (size_t)blen); pos += blen; }
    data[pos] = '\0';
    return (t_string){.data = data, .length = pos, .is_local = false};
}

/** tphp_rt_str_concat_multi — ROPE 多片段拼接 */
static inline t_string tphp_rt_str_concat_multi(int count, const t_string parts[]) {
    if (unlikely(count <= 0)) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (count == 1) return parts[0];
    int total = 0;
    for (int i = 0; i < count; i++) {
        const char *pd = STR_PTR(parts[i]);
        int len = (parts[i].length > 0 && pd != NULL) ? parts[i].length : 0;
        if (len < 0) len = 0;
        if (len > 0x7FFFFF) return (t_string){.data = NULL, .length = 0, .is_local = false};
        if (unlikely(total > 0x7FFFFF - len)) return (t_string){.data = NULL, .length = 0, .is_local = false}; // 防累加上溢
        total += len;
    }
    if (total <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    char* buf = str_pool_alloc(total);
    if (unlikely(buf == NULL)) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int pos = 0;
    for (int i = 0; i < count; i++) {
        const char *pd = STR_PTR(parts[i]);
        int len = (parts[i].length > 0 && pd != NULL) ? parts[i].length : 0;
        if (len > 0) { memcpy(buf + pos, pd, (size_t)len); pos += len; }
    }
    buf[total] = '\0';
    return (t_string){.data = buf, .length = total, .is_local = false};
}

/** tphp_str_dup — 深拷贝 t_string（≤23字节用 SSO，否则走池） */
static inline t_string tphp_rt_str_dup(t_string s) {
    const char *src = STR_PTR(s);
    if (src == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    // 字面量(.rodata)直接返回，零开销 — 不可变串无需深拷贝
    if (s.is_lit) return s;
    // SSO: 短串直接内联，零堆分配
    if (likely(s.length <= STR_SSO_MAX)) {
        t_string r = {.is_local = true, .length = s.length};
        memcpy(r.local, src, (size_t)s.length);
        r.local[s.length] = '\0';
        return r;
    }
    char* d = str_pool_alloc(s.length);
    if (d == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    memcpy(d, src, (size_t)s.length);
    d[s.length] = '\0';
    return (t_string){.data = d, .length = s.length, .is_local = false};
}

/** tphp_str_free — 安全释放 t_string（SSO 跳过，池/arena 跳过，否则 free） */
static inline void tphp_rt_str_free(t_string* s) {
    if (unlikely(s == NULL || s->length <= 0)) return;
    if (s->is_lit)   { s->data = NULL; s->length = 0; return; }   // .rodata literal — never free()
    if (s->is_local) { s->length = 0; s->is_local = false; return; }
    const char *d = STR_PTR_P(s);
    if (d == NULL) { s->length = 0; return; }
    // 主池内的指针不释放
    if (d >= str_pool_buf && d < str_pool_buf + STR_POOL_SIZE) { s->data = NULL; s->length = 0; return; }
    // Arena 溢出块内的指针不释放
    str_arena_block *b;
    for (b = str_arena_head; b; b = b->next) {
        if (d >= b->buf && d < b->buf + b->size) { s->data = NULL; s->length = 0; return; }
    }
    free(d);
    s->data = NULL;
    s->length = 0;
}

// ── 对象生命周期 ──────────────────────────────────────

/** tphp_object_free — 统一析构入口：refcount 减 1，归零调 dtor + free */
static inline void tphp_rt_object_free(void *obj) {
    tp_obj_release(obj);
}

// ============================================================
// 幂运算
// ============================================================

/** tphp_rt_pow_int — 整数幂 (a^b)，简单循环，b >= 0 */
static inline t_int tphp_rt_pow_int(t_int base, t_int exp) {
    if (exp < 0) return 0;
    t_int result = 1;
    while (exp > 0) {
        if (exp & 1) result *= base;
        base *= base;
        exp >>= 1;
    }
    return result;
}

/** tphp_rt_pow_float — 浮点幂，直接调 libc pow */
static inline t_float tphp_rt_pow_float(t_float base, t_float exp) {
    return (t_float)pow(base, exp);
}

// ============================================================
// 全局资源追踪（error 时自动清理）
// ============================================================

typedef struct tphp_rt_alloc {
    void  *ptr;
    int    type;          // 0=object 1=array 2=heap_str
    struct tphp_rt_alloc *next;
} tphp_rt_alloc;

#if !TPHP_USE_WIN_TLS && !TPHP_USE_PTHREAD_TLS
static _Thread_local tphp_rt_alloc *tphp_alloc_head = NULL;
#endif

static inline void tphp_rt_register(void *ptr, int type) {
    if (ptr == NULL) return;
    tphp_rt_alloc *n = (tphp_rt_alloc *)malloc(sizeof(tphp_rt_alloc));
    if (n == NULL) return;
    n->ptr  = ptr;
    n->type = type;
    n->next = tphp_alloc_head;
    tphp_alloc_head = n;
}

static inline void tphp_rt_unregister(void *ptr) {
    tphp_rt_alloc **pp = &tphp_alloc_head;
    while (*pp) {
        if ((*pp)->ptr == ptr) {
            tphp_rt_alloc *d = *pp;
            *pp = d->next;
            free(d);
            return;
        }
        pp = &(*pp)->next;
    }
}

static inline void tphp_rt_free_all(void) {
    tphp_rt_alloc *n = tphp_alloc_head;
    while (n) {
        tphp_rt_alloc *next = n->next;
        if (n->ptr) {
            switch (n->type) {
                case 0: tp_obj_release(n->ptr); break;
                case 1: tphp_fn_arr_free((t_array *)n->ptr);    break;
                case 2: { t_string *s = (t_string *)n->ptr; free(STR_PTR_P(s)); free(s); } break;
                case 3: free(n->ptr); break; /* closure capture env / generic heap */
            }
        }
        free(n);
        n = next;
    }
    tphp_alloc_head = NULL;
    str_arena_free_all();
}

// ============================================================
// 线程退出清理 — 释放子线程的 thread-local 内存池
// （主线程不需要调用，进程退出时 OS 回收全部资源）
// ============================================================

static inline void tphp_thread_cleanup(void) {
    // 1. 释放 arena 溢出块
    str_arena_free_all();
    str_pool_cur = NULL;  // 重置 bump 指针
    // 2. 释放数组复用池中的缓存数组
    for (int i = 0; i < arr_freelist_count; i++) {
        if (arr_freelist[i]) free(arr_freelist[i]);
    }
    arr_freelist_count = 0;
    // 3. 释放对象复用池中的缓存对象
    for (int i = 0; i < _obj_freelist_count; i++) {
        if (_obj_freelist[i].ptr) free(_obj_freelist[i].ptr);
    }
    _obj_freelist_count = 0;
    // 4. 释放 GC 追踪列表（异常安全网）
    tphp_rt_free_all();
#if TPHP_USE_WIN_TLS || TPHP_USE_PTHREAD_TLS
    // 5. TCC+Windows/TCC+macOS: 释放 TLS 结构体本身
    tphp_tls_destroy();
#endif
}
