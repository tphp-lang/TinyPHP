#pragma once
// ============================================================
// os/json.h — TinyPHP JSON 编解码
//
//   json_encode($val)  → t_string (堆分配, ≤512B 用字符串池)
//   json_decode($str)  → t_var   (mixed 类型)
//
//   支持: null, bool, int, float, string, array, object
//   内存安全: 编码使用 tphp_rt_str_concat, 解码递归释放中间数组
// ============================================================

#ifndef _USE_MATH_DEFINES
#define _USE_MATH_DEFINES
#endif
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
// types.h + val.h 由 common.h 统一引入, 不在此重复

/* TCC lacks isinf/isnan → inline fallback; other compilers get them from math.h */
#ifdef __TINYC__
#undef isinf
#undef isnan
static inline int _tphp_isinf(double x) {
    union { double d; uint64_t u; } u = {x};
    return (u.u & 0x7FFFFFFFFFFFFFFFULL) == 0x7FF0000000000000ULL;
}
#define isinf(x) _tphp_isinf(x)
#define isnan(x) ((x) != (x))
#endif

/* === JSON Encode ========================================= */

// ── 字节复制 (供后续 digit_table 优化预留) ──
static inline void yj_memcpy2(void *d, const void *s) { memcpy(d, s, 2); }

/** JSON 转义位图 — 256 字符只需 O(1) 位测试 */
static const uint32_t json_esc_bits[8] = {
    0xffffffff, 0x00000004, 0x10000000, 0,0,0,0,0,
};

/** 快速 uint32 → 写入 (纯标准 C: %10 / 除法, 三编译器兼容) */
static inline int json_write_u32(uint32_t val, char *out) {
    if (val == 0) { out[0] = '0'; return 1; }
    char tmp[12];
    int len = 0;
    while (val > 0) { tmp[len++] = (char)('0' + (int)(val % 10)); val /= 10; }
    for (int i = 0; i < len; i++) out[i] = tmp[len - 1 - i];
    return len;
}

/** 快速 int → 字符串 (int64, yyjson digit_table 风格) */
static inline int json_itoa(t_int val, char *out) {
    if (val == 0) { out[0] = '0'; return 1; }
    int off = 0;
    if (val < 0) { out[off++] = '-'; val = -val; }
    return off + json_write_u32((uint32_t)val, out + off);
}

/** 快速 int 位数 (不计负号, 0→1) — 用于 json_calc_size, 零写零开销 */
static inline int json_ilen(t_int val) {
    if (val == 0) return 1;
    if (val < 0) val = -val;
    // 查表法: 2次比较定位量级
    if (val < 10000) {
        if (val < 100) return (val < 10) ? 1 : 2;
        return (val < 1000) ? 3 : 4;
    }
    if (val < 100000000) {
        if (val < 1000000) return (val < 100000) ? 5 : 6;
        return (val < 10000000) ? 7 : 8;
    }
    if (val < 100000000000LL) {
        if (val < 10000000000LL) return (val < 1000000000) ? 9 : 10;
        return 11;
    }
    return 12; // int32 max ≈ 2.1B, fits in 10 digits normally; safe upper bound
}

/** fastesc: 字符串 JSON 转义 → 栈写入到 out，返回写入长度 */
static int json_escape_to(t_string s, char *out) {
    if (STR_PTR(s) == NULL || s.length <= 0) { out[0] = '"'; out[1] = '"'; return 2; }
    int pos = 0;
    out[pos++] = '"';
    int safe_start = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (!(json_esc_bits[c >> 5] & (1u << (c & 0x1f)))) continue;
        if (i > safe_start) { memcpy(out + pos, STR_PTR(s) + safe_start, i - safe_start); pos += i - safe_start; }
        safe_start = i + 1;
        if (c == '"')  { out[pos++] = '\\'; out[pos++] = '"'; }
        else if (c == '\\') { out[pos++] = '\\'; out[pos++] = '\\'; }
        else if (c == '\n') { out[pos++] = '\\'; out[pos++] = 'n'; }
        else if (c == '\r') { out[pos++] = '\\'; out[pos++] = 'r'; }
        else if (c == '\t') { out[pos++] = '\\'; out[pos++] = 't'; }
        else if (c < 0x20) {
            out[pos++] = '\\'; out[pos++] = 'u'; out[pos++] = '0'; out[pos++] = '0';
            out[pos++] = "0123456789abcdef"[c >> 4];
            out[pos++] = "0123456789abcdef"[c & 0xf];
        }
    }
    if (s.length > safe_start) { memcpy(out + pos, STR_PTR(s) + safe_start, s.length - safe_start); pos += s.length - safe_start; }
    out[pos++] = '"';
    return pos;
}

/** 计算 JSON 转义后长度（不实际写入） */
static int json_escape_len(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return 2;
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (json_esc_bits[c >> 5] & (1u << (c & 0x1f))) {
            if (c == '\n' || c == '\r' || c == '\t') extra += 1;
            else extra += (c < 0x20) ? 5 : 1;
        }
    }
    return s.length + extra + 2;
}

/* ── 前向声明 ── */
static int json_calc_size(t_var v);

/** 计算 JSON 编码总长度（递归，零分配） */
static int json_calc_size(t_var v) {
    switch (v.type) {
    case TYPE_NULL: return 4;
    case TYPE_BOOL: return v.value._bool ? 4 : 5;
    case TYPE_INT:  return (v.value._int < 0 ? 1 : 0) + json_ilen(v.value._int);
    case TYPE_FLOAT: {
        if (isnan(v.value._float) || isinf(v.value._float)) return 4;
        char _buf[64];
        int n = snprintf(_buf, sizeof(_buf), "%.14g", v.value._float);
        char *dot = strchr(_buf, '.');
        if (dot) { char *end = _buf + n - 1; while (end > dot && *end == '0') end--; if (end == dot) end++; n = (int)(end - _buf + 1); }
        return n;
    }
    case TYPE_STRING: return json_escape_len(v.value._string);
    case TYPE_ARRAY: {
        t_array *a = v.value._array;
        if (a == NULL) return 2;
        bool is_obj = false;
        for (int i = 0; i < a->length && !is_obj; i++)
            if (a->entries[i].key.type == TYPE_STRING) is_obj = true;
        int total = 2; // {} or []
        for (int i = 0; i < a->length; i++) {
            if (i > 0) total++;
            if (is_obj) { total += json_escape_len(a->entries[i].key.value._string) + 1; }
            total += json_calc_size(a->entries[i].val);
        }
        return total;
    }
    default: return 4;
    }
}

/** 写入编码到预分配缓冲区，返回写入长度 */
static int json_write_to(t_var v, char *out);

static int json_write_to(t_var v, char *out) {
    switch (v.type) {
    case TYPE_NULL: { memcpy(out, "null", 4); return 4; }
    case TYPE_BOOL: {
        if (v.value._bool) { memcpy(out, "true", 4); return 4; }
        else { memcpy(out, "false", 5); return 5; }
    }
    case TYPE_INT: return json_itoa(v.value._int, out);
    case TYPE_FLOAT: {
        if (isnan(v.value._float) || isinf(v.value._float)) { memcpy(out, "null", 4); return 4; }
        int n = snprintf(out, 64, "%.14g", v.value._float);
        char *dot = strchr(out, '.');
        if (dot) { char *end = out + n - 1; while (end > dot && *end == '0') end--; if (end == dot) end++; n = (int)(end - out + 1); }
        return n;
    }
    case TYPE_STRING: return json_escape_to(v.value._string, out);
    case TYPE_ARRAY: {
        t_array *a = v.value._array;
        bool is_obj = false;
        if (a != NULL)
            for (int i = 0; i < a->length; i++)
                if (a->entries[i].key.type == TYPE_STRING) { is_obj = true; break; }
        int pos = 0;
        out[pos++] = is_obj ? '{' : '[';
        if (a != NULL) {
            for (int i = 0; i < a->length; i++) {
                if (i > 0) out[pos++] = ',';
                if (is_obj) {
                    pos += json_escape_to(a->entries[i].key.value._string, out + pos);
                    out[pos++] = ':';
                }
                pos += json_write_to(a->entries[i].val, out + pos);
            }
        }
        out[pos++] = is_obj ? '}' : ']';
        return pos;
    }
    default: { memcpy(out, "null", 4); return 4; }
    }
}

/** json_encode($val) → JSON 字符串
 *  两趟法：第1趟计算总长度(零分配)，第2趟一次性写入目标缓冲区
 *  完全消除 str_concat 的 O(n²) 拷贝开销 */
static inline t_string tphp_fn_json_encode(t_var v) {
    int total = json_calc_size(v);
    if (total <= 0) return tphp_rt_str_dup(STR_LIT("null"));
    if (total > (1 << 23)) return tphp_rt_str_dup(STR_LIT("null")); // 8MB cap
    char *buf = str_pool_alloc(total);
    if (buf == NULL) return tphp_rt_str_dup(STR_LIT("null"));
    int written = json_write_to(v, buf);
    return (t_string){.data = buf, .length = written > 0 ? written : total};
}

/* === JSON Decode ========================================= */

typedef struct {
    const char *cur;
    const char *end;
} json_parser;

static inline void json_skip_ws(json_parser *p) {
    while (p->cur < p->end &&
           (*p->cur == ' ' || *p->cur == '\t' || *p->cur == '\n' || *p->cur == '\r'))
        p->cur++;
}

static t_var json_parse_value(json_parser *p);

/** 解析 JSON 字符串（含转义） */

// 解析 4 位十六进制，返回 codepoint；p->cur 指向 'u' 之后的第一个 hex
static uint32_t json_hex4(const char *s) {
    uint32_t cp = 0;
    for (int i = 0; i < 4; i++) {
        char c = s[i];
        cp <<= 4;
        if (c >= '0' && c <= '9') cp |= (uint32_t)(c - '0');
        else if (c >= 'a' && c <= 'f') cp |= (uint32_t)(c - 'a' + 10);
        else if (c >= 'A' && c <= 'F') cp |= (uint32_t)(c - 'A' + 10);
        else return 0xFFFFFFFF; // 非法
    }
    return cp;
}

// 返回 codepoint 的 UTF-8 编码字节数（1-4）
static int json_utf8_len(uint32_t cp) {
    if (cp < 0x80) return 1;
    if (cp < 0x800) return 2;
    if (cp < 0x10000) return 3;
    return 4;
}

// 将 codepoint 编码为 UTF-8 写入 buf，返回写入字节数
static int json_utf8_encode(uint32_t cp, char *buf) {
    if (cp < 0x80) {
        buf[0] = (char)cp;
        return 1;
    } else if (cp < 0x800) {
        buf[0] = (char)(0xC0 | (cp >> 6));
        buf[1] = (char)(0x80 | (cp & 0x3F));
        return 2;
    } else if (cp < 0x10000) {
        buf[0] = (char)(0xE0 | (cp >> 12));
        buf[1] = (char)(0x80 | ((cp >> 6) & 0x3F));
        buf[2] = (char)(0x80 | (cp & 0x3F));
        return 3;
    } else {
        buf[0] = (char)(0xF0 | (cp >> 18));
        buf[1] = (char)(0x80 | ((cp >> 12) & 0x3F));
        buf[2] = (char)(0x80 | ((cp >> 6) & 0x3F));
        buf[3] = (char)(0x80 | (cp & 0x3F));
        return 4;
    }
}

// 解析 \uXXXX（可能跟 surrogate pair），返回 codepoint；advance p->cur 越过整个 \uXXXX[+low surrogate]
// p->cur 进入时指向 'u'；成功返回 codepoint，失败返回 0xFFFFFFFF
static uint32_t json_parse_u(json_parser *p) {
    // p->cur 指向 'u'，需要后面 4 hex
    if (p->cur + 5 > p->end) return 0xFFFFFFFF;
    uint32_t cp = json_hex4(p->cur + 1);
    if (cp == 0xFFFFFFFF) return 0xFFFFFFFF;
    p->cur += 5; // 跳过 u+4hex
    // high surrogate → 尝试读 low surrogate
    if (cp >= 0xD800 && cp <= 0xDBFF) {
        if (p->cur + 6 <= p->end && p->cur[0] == '\\' && p->cur[1] == 'u') {
            uint32_t lo = json_hex4(p->cur + 2);
            if (lo != 0xFFFFFFFF && lo >= 0xDC00 && lo <= 0xDFFF) {
                cp = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00);
                p->cur += 6; // 跳过 \uXXXX (low surrogate)
            }
        }
    }
    return cp;
}

static t_var json_parse_string(json_parser *p) {
    if (p->cur >= p->end || *p->cur != '"') return VAR_NULL();
    p->cur++; // skip opening "
    // 第一遍: 计算长度
    const char *start = p->cur;
    int len = 0;
    while (p->cur < p->end && *p->cur != '"') {
        if (*p->cur == '\\') {
            p->cur++;
            if (p->cur >= p->end) return VAR_NULL();
            switch (*p->cur) {
            case '"': case '\\': case '/': len++; break;
            case 'n': case 'r': case 't': case 'b': case 'f': len++; break;
            case 'u': {
                uint32_t cp = json_parse_u(p);
                if (cp == 0xFFFFFFFF) return VAR_NULL();
                len += json_utf8_len(cp);
                continue; // json_parse_u 已 advance p->cur，跳过循环末尾 p->cur++
            }
            default: len += 2; break;
            }
        } else {
            len++;
        }
        p->cur++;
    }
    if (p->cur >= p->end) return VAR_NULL();
    // 第二遍: 复制
    char *buf = str_pool_alloc(len);
    if (buf == NULL) { p->cur++; return VAR_NULL(); }
    p->cur = start;
    int pos = 0;
    while (p->cur < p->end && *p->cur != '"') {
        if (*p->cur == '\\') {
            p->cur++;
            switch (*p->cur) {
            case '"':  buf[pos++] = '"';  break;
            case '\\': buf[pos++] = '\\'; break;
            case '/':  buf[pos++] = '/';  break;
            case 'n':  buf[pos++] = '\n'; break;
            case 'r':  buf[pos++] = '\r'; break;
            case 't':  buf[pos++] = '\t'; break;
            case 'b':  buf[pos++] = '\b'; break;
            case 'f':  buf[pos++] = '\f'; break;
            case 'u': {
                uint32_t cp = json_parse_u(p);
                if (cp == 0xFFFFFFFF) { /* 容错：写占位 */ buf[pos++] = '?'; continue; }
                pos += json_utf8_encode(cp, buf + pos);
                continue; // json_parse_u 已 advance p->cur
            }
            default:   buf[pos++] = '\\'; buf[pos++] = *p->cur; break;
            }
        } else {
            buf[pos++] = *p->cur;
        }
        p->cur++;
    }
    p->cur++; // skip closing "
    t_string _s = {buf, pos};
    return VAR_STRING(_s);
}

/** 解析 JSON 数字 */
static t_var json_parse_number(json_parser *p) {
    const char *start = p->cur;
    bool is_float = false;
    if (p->cur < p->end && *p->cur == '-') p->cur++;
    while (p->cur < p->end && *p->cur >= '0' && *p->cur <= '9') p->cur++;
    if (p->cur < p->end && *p->cur == '.') {
        is_float = true; p->cur++;
        while (p->cur < p->end && *p->cur >= '0' && *p->cur <= '9') p->cur++;
    }
    if (p->cur < p->end && (*p->cur == 'e' || *p->cur == 'E')) {
        is_float = true; p->cur++;
        if (p->cur < p->end && (*p->cur == '+' || *p->cur == '-')) p->cur++;
        while (p->cur < p->end && *p->cur >= '0' && *p->cur <= '9') p->cur++;
    }
    int nlen = (int)(p->cur - start);
    if (nlen <= 0) return VAR_NULL();
    char buf[64];
    if (nlen > 63) nlen = 63;
    memcpy(buf, start, (size_t)nlen);
    buf[nlen] = '\0';
    if (is_float)
        return VAR_FLOAT(atof(buf));
    else
        return VAR_INT((t_int)atoll(buf));
}

/** 解析 JSON 数组 */
static t_var json_parse_array(json_parser *p) {
    p->cur++; // skip [
    json_skip_ws(p);
    t_array *a = tphp_fn_arr_create(8);
    if (a == NULL) return VAR_NULL();
    tphp_rt_register((void*)a, 1);
    if (p->cur < p->end && *p->cur == ']') { p->cur++; return VAR_ARRAY(a); }
    bool closed = false;
    while (p->cur < p->end) {
        t_var val = json_parse_value(p);
        a = tphp_fn_arr_push(a, val);
        json_skip_ws(p);
        if (p->cur < p->end && *p->cur == ',') { p->cur++; json_skip_ws(p); }
        else if (p->cur < p->end && *p->cur == ']') { closed = true; break; }
        else { tphp_fn_arr_free(a); return VAR_NULL(); }
    }
    if (!closed) { tphp_fn_arr_free(a); return VAR_NULL(); }
    p->cur++; // skip ]
    return VAR_ARRAY(a);
}

/** 解析 JSON 对象 */
static t_var json_parse_object(json_parser *p) {
    p->cur++; // skip {
    json_skip_ws(p);
    t_array *a = tphp_fn_arr_create(8);
    if (a == NULL) return VAR_NULL();
    tphp_rt_register((void*)a, 1);
    if (p->cur < p->end && *p->cur == '}') { p->cur++; return VAR_ARRAY(a); }
    bool closed = false;
    while (p->cur < p->end) {
        t_var key_v = json_parse_string(p);
        if (key_v.type != TYPE_STRING) { tphp_fn_arr_free(a); return VAR_NULL(); }
        json_skip_ws(p);
        if (p->cur >= p->end || *p->cur != ':') { tphp_fn_arr_free(a); return VAR_NULL(); }
        p->cur++; // skip :
        json_skip_ws(p);
        t_var val = json_parse_value(p);
        a = tphp_fn_arr_set_str(a, key_v.value._string, val);
        json_skip_ws(p);
        if (p->cur < p->end && *p->cur == ',') { p->cur++; json_skip_ws(p); }
        else if (p->cur < p->end && *p->cur == '}') { closed = true; break; }
        else { tphp_fn_arr_free(a); return VAR_NULL(); }
    }
    if (!closed) { tphp_fn_arr_free(a); return VAR_NULL(); }
    p->cur++; // skip }
    return VAR_ARRAY(a);
}

/** 解析 JSON value */
static t_var json_parse_value(json_parser *p) {
    json_skip_ws(p);
    if (p->cur >= p->end) return VAR_NULL();
    char c = *p->cur;
    if (c == 'n') {
        if (p->end - p->cur >= 4 && memcmp(p->cur, "null", 4) == 0)
            { p->cur += 4; return VAR_NULL(); }
    } else if (c == 't') {
        if (p->end - p->cur >= 4 && memcmp(p->cur, "true", 4) == 0)
            { p->cur += 4; return VAR_BOOL(true); }
    } else if (c == 'f') {
        if (p->end - p->cur >= 5 && memcmp(p->cur, "false", 5) == 0)
            { p->cur += 5; return VAR_BOOL(false); }
    } else if (c == '"') {
        return json_parse_string(p);
    } else if (c == '[') {
        return json_parse_array(p);
    } else if (c == '{') {
        return json_parse_object(p);
    } else if (c == '-' || (c >= '0' && c <= '9')) {
        return json_parse_number(p);
    }
    return VAR_NULL();
}

/** json_decode($str) → mixed (t_var) */
static inline t_var tphp_fn_json_decode(t_string json) {
    if (STR_PTR_V(json) == NULL || json.length <= 0) return VAR_NULL();
    json_parser p = {.cur = (char*)STR_PTR_V(json), .end = (char*)STR_PTR_V(json) + json.length};
    json_skip_ws(&p);
    if (p.cur >= p.end) return VAR_NULL();
    const char *start = p.cur;
    t_var result = json_parse_value(&p);
    if (p.cur <= start) return VAR_NULL();
    json_skip_ws(&p);
    if (p.cur < p.end) return VAR_NULL();
    return result;
}

/** json_validate($str) → bool — 复用 json_decode 验证 JSON 有效性 */
static inline t_bool tphp_fn_json_validate(t_string s) {
    return tphp_fn_json_decode(s).type != TYPE_NULL;
}
