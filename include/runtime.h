#pragma once

// ============================================================
// runtime.h — TinyPHP 运行时内部辅助函数
//
//   这些函数为转译器自动生成代码所依赖，用户 PHP 代码不直接调用。
//   包括：初始化、内存管理、字符串转换/比较/拼接、对象析构等。
// ============================================================

#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <math.h>
#include "types.h"

#ifdef _WIN32
#include <windows.h>
#endif

// ── 初始化 ────────────────────────────────────────────

/** tphp_rt_init — 初始化运行时（Windows 下设置控制台 UTF-8） */
static inline void tphp_rt_init(void) {
#ifdef _WIN32
    SetConsoleOutputCP(65001); // CP_UTF8
    SetConsoleCP(65001);
#endif
    (void)0;
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
            a = tphp_fn_arr_push(a, VAR_STRING(((t_string){NULL, 0})));
        }
    }
    return a;
}

// ── 类型转换辅助 ─────────────────────────────────────

/** int → t_string（栈缓冲区，单线程安全） */
static inline t_string tphp_rt_str_from_int(t_int v) {
    static char _buf[32];
    int len = snprintf(_buf, sizeof(_buf), "%lld", (long long)v);
    return (t_string){_buf, len > 0 ? len : 0};
}

static inline t_string tphp_rt_str_from_float(t_float v) {
    static char _buf[64];
    int len = snprintf(_buf, sizeof(_buf), "%g", v);
    return (t_string){_buf, len > 0 ? len : 0};
}

static inline t_string tphp_rt_str_from_bool(t_bool v) {
    return v ? STR_LIT("true") : STR_LIT("false");
}

// ── 字符串解析 ────────────────────────────────────────

/** 从字符串解析整数（跳前导空白，符号位，连续数字） */
static inline t_int tphp_rt_parse_int(t_string s) {
    if (s.data == NULL || s.length <= 0) return 0;
    int i = 0;
    while (i < s.length && (s.data[i] == ' ' || s.data[i] == '\t')) i++;
    int sign = 1;
    if (i < s.length && s.data[i] == '-') { sign = -1; i++; }
    else if (i < s.length && s.data[i] == '+') { i++; }
    t_int val = 0;
    while (i < s.length && s.data[i] >= '0' && s.data[i] <= '9') {
        val = val * 10 + (t_int)(s.data[i] - '0');
        i++;
    }
    return val * sign;
}

/** 从字符串解析浮点数（支持科学计数法） */
static inline t_float tphp_rt_parse_float(t_string s) {
    if (s.data == NULL || s.length <= 0) return 0.0;
    char temp[128];
    int len = (s.length < 127) ? s.length : 127;
    memcpy(temp, s.data, (size_t)len);
    temp[len] = '\0';
    return strtod(temp, NULL);
}

/** 字符串是否为 PHP 假值（空串或 "0"） */
static inline t_bool tphp_rt_str_is_falsy(t_string s) {
    if (s.data == NULL || s.length <= 0) return true;
    if (s.length == 1 && s.data[0] == '0') return true;
    return false;
}

// ── 字符串比较 ────────────────────────────────────────

/** tphp_str_cmp — 字典序比较（memcmp 语义，返回 -1/0/1） */
static inline int tphp_rt_str_cmp(t_string a, t_string b) {
    if (a.data == b.data) return 0;
    if (a.data == NULL) return -1;
    if (b.data == NULL) return 1;
    int minlen = (a.length < b.length) ? a.length : b.length;
    int r = memcmp(a.data, b.data, (size_t)minlen);
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

// ── 小字符串池 (bump allocator, 64KB, yyjson-style) ────

#define STR_POOL_SIZE 65536
static char  str_pool_buf[STR_POOL_SIZE];
static char *str_pool_cur = str_pool_buf;

/** 从小字符串池分配 len+1 字节（<=512 字节用池，超限回退 malloc） */
static inline char* str_pool_alloc(int len) {
    if (len <= 0) return NULL;
    if (unlikely(len > 512)) return (char*)malloc((size_t)len + 1);
    if (unlikely(str_pool_cur + len + 1 > str_pool_buf + STR_POOL_SIZE))
        return (char*)malloc((size_t)len + 1);
    char *p = str_pool_cur;
    str_pool_cur += len + 1;
    return p;
}

// ── 字符串拼接/拷贝/释放 ─────────────────────────────

/** tphp_str_concat — 字符串拼接（短串用池，长串堆分配） */
static inline t_string tphp_rt_str_concat(t_string a, t_string b) {
    int alen = (a.length > 0 && a.data != NULL) ? a.length : 0;
    int blen = (b.length > 0 && b.data != NULL) ? b.length : 0;
    if (alen == 0 && blen == 0) return (t_string){NULL, 0};
    if (alen < 0) alen = 0;
    if (blen < 0) blen = 0;
    if (alen > 0x7FFFFF || blen > 0x7FFFFF) return (t_string){NULL, 0};
    int len = alen + blen;
    if (len <= 0) return (t_string){NULL, 0};
    char* data = str_pool_alloc(len);
    if (data == NULL) return (t_string){NULL, 0};
    int pos = 0;
    if (alen > 0) { memcpy(data + pos, a.data, (size_t)alen); pos += alen; }
    if (blen > 0) { memcpy(data + pos, b.data, (size_t)blen); pos += blen; }
    data[pos] = '\0';
    return (t_string){data, pos};
}

/** tphp_str_dup — 深拷贝 t_string（短串用池，长串堆分配） */
static inline t_string tphp_rt_str_dup(t_string s) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    char* d = str_pool_alloc(s.length);
    if (d == NULL) return (t_string){NULL, 0};
    memcpy(d, s.data, (size_t)s.length);
    d[s.length] = '\0';
    return (t_string){d, s.length};
}

/** tphp_str_free — 安全释放 t_string 的堆 data（池内指针跳过，置 NULL 防 double-free） */
static inline void tphp_rt_str_free(t_string* s) {
    if (unlikely(s == NULL || s->data == NULL || s->length <= 0)) return;
    // 小字符串池内的指针不释放（bump allocator 无需逐块 free）
    if (s->data >= str_pool_buf && s->data < str_pool_buf + STR_POOL_SIZE) {
        s->data = NULL;
        s->length = 0;
        return;
    }
    free(s->data);
    s->data = NULL;
    s->length = 0;
}

// ── 对象生命周期 ──────────────────────────────────────

/** tphp_object_free — 统一析构入口：refcount 减 1，归零调 dtor + free */
static inline void tphp_rt_object_free(t_object *obj) {
    if (obj == NULL) return;
    if (--obj->refcount > 0) return;
    if (obj->vtable != NULL && obj->vtable->dtor != NULL) {
        obj->vtable->dtor(obj);
    }
    free(obj);
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

static tphp_rt_alloc *tphp_alloc_head = NULL;

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
                case 0: tphp_rt_object_free((t_object *)n->ptr); break;
                case 1: tphp_fn_arr_free((t_array *)n->ptr);    break;
                case 2: { t_string *s = (t_string *)n->ptr; free(s->data); free(s); } break;
            }
        }
        free(n);
        n = next;
    }
    tphp_alloc_head = NULL;
}

// ============================================================
// error() — 报错并安全退出
// ============================================================

static inline void tphp_fn_error(t_string msg, const char *php_file, int php_line) {
    tphp_rt_free_all();
    fprintf(stderr, "\nFatal error: %.*s\n  in %s on line %d\n\n",
            msg.length > 0 ? msg.length : 0, msg.data ? msg.data : "", php_file, php_line);
    exit(1);
}
