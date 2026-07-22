#pragma once
// std/ctrl.h — 断言/随机数/ctype
//   对应 PHP ext/standard assertions + ext/random + ext/ctype

static inline void tphp_fn_assert_true(t_bool cond) {
    if (unlikely(!cond)) {
        tp_throw("assert_true(): assertion failed");
    }
}
static inline void tphp_fn_assert_false(t_bool cond) {
    if (unlikely(cond)) {
        tp_throw("assert_false(): assertion failed");
    }
}
static inline void tphp_fn_assert_eq_int(t_int a, t_int b) {
    if (unlikely(a != b)) {
        char _msg[128];
        snprintf(_msg, sizeof(_msg), "assert_eq_int(%lld, %lld): assertion failed",
            (long long)a, (long long)b);
        tp_throw(_msg);
    }
}
static inline void tphp_fn_assert_eq_float(t_float a, t_float b) {
    if (unlikely(a != b)) {
        char _msg[128];
        snprintf(_msg, sizeof(_msg), "assert_eq_float(%g, %g): assertion failed", a, b);
        tp_throw(_msg);
    }
}
static inline void tphp_fn_assert_eq_str(t_string a, t_string b) {
    if (unlikely(!tphp_rt_str_eq(a, b))) {
        char _msg[128];
        snprintf(_msg, sizeof(_msg), "assert_eq_str(len=%d vs len=%d): assertion failed",
            a.length, b.length);
        tp_throw(_msg);
    }
}

// ============================================================
// ctype — 字符类型检测（映射 C <ctype.h>，零堆分配）
//   每个函数检查字符串中所有字符是否满足指定类型
//   空字符串返回 false（PHP 行为）
// ============================================================

#define _TPHP_CTYPE_CHECK(fn, s)                         \
    do {                                                  \
        if (unlikely((s).data == NULL || (s).length == 0)) return false; \
        for (int _i = 0; _i < (s).length; _i++) {         \
            if (!fn((unsigned char)(s).data[_i])) return false; \
        }                                                 \
        return true;                                       \
    } while(0)

static inline t_bool tphp_fn_ctype_alnum(t_string s) { _TPHP_CTYPE_CHECK(isalnum, s); }
static inline t_bool tphp_fn_ctype_alpha(t_string s) { _TPHP_CTYPE_CHECK(isalpha, s); }
static inline t_bool tphp_fn_ctype_cntrl(t_string s) { _TPHP_CTYPE_CHECK(iscntrl, s); }
static inline t_bool tphp_fn_ctype_digit(t_string s) { _TPHP_CTYPE_CHECK(isdigit, s); }
static inline t_bool tphp_fn_ctype_graph(t_string s) { _TPHP_CTYPE_CHECK(isgraph, s); }
static inline t_bool tphp_fn_ctype_lower(t_string s) { _TPHP_CTYPE_CHECK(islower, s); }
static inline t_bool tphp_fn_ctype_print(t_string s) { _TPHP_CTYPE_CHECK(isprint, s); }
static inline t_bool tphp_fn_ctype_punct(t_string s) { _TPHP_CTYPE_CHECK(ispunct, s); }
static inline t_bool tphp_fn_ctype_space(t_string s) { _TPHP_CTYPE_CHECK(isspace, s); }
static inline t_bool tphp_fn_ctype_upper(t_string s) { _TPHP_CTYPE_CHECK(isupper, s); }
static inline t_bool tphp_fn_ctype_xdigit(t_string s) { _TPHP_CTYPE_CHECK(isxdigit, s); }

#undef _TPHP_CTYPE_CHECK

// ============================================================
// random_int / random_bytes — CSPRNG 安全随机（委托 rand.h）
// ============================================================

// 前向声明（定义在 rand.h）
static inline int _tphp_random_bytes(unsigned char* buf, size_t n);

static inline t_int tphp_fn_random_int(t_int min, t_int max) {
    if (min > max) { tp_throw("random_int(): min must be <= max"); return 0; }
    return tphp_fn_rand_int(min, max);
}

static inline t_string tphp_fn_random_bytes(t_int length) {
    if (length <= 0) return (t_string){NULL, 0};
    if (length > 1048576) {
        tp_throw("random_bytes(): length must be <= 1048576");
        return (t_string){NULL, 0};
    }
    unsigned char* buf = (unsigned char*)malloc((size_t)length);
    if (!buf) { tp_throw("random_bytes(): out of memory"); return (t_string){NULL, 0}; }
    if (_tphp_random_bytes(buf, (size_t)length) != 0) {
        free(buf);
        tp_throw("random_bytes(): unable to generate random bytes");
        return (t_string){NULL, 0};
    }
    t_string s = tphp_rt_str_dup((t_string){(char*)buf, (int)length});
    free(buf);
    return s;
}

