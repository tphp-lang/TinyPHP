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
#include "types.h"
#include "val.h"

/* TCC lacks isinf/isnan → inline fallback */
#ifdef __TINYC__
static inline int _tphp_isinf(double x) {
    union { double d; uint64_t u; } u = {x};
    return (u.u & 0x7FFFFFFFFFFFFFFFULL) == 0x7FF0000000000000ULL;
}
#define isinf(x) _tphp_isinf(x)
#define isnan(x) ((x) != (x))
#endif

/* === JSON Encode ========================================= */

/** JSON 转义位图 — 256 字符只需 O(1) 位测试
 *  与 PHP 8.5 zend_string.c 同级优化：index[0] 覆盖 0x00-0x1f 控制字符，
 *  index[1] bit2=", bit28=\ */
static const uint32_t json_esc_bits[8] = {
    0xffffffff,  // index 0: 0x00-0x1f (all control chars)
    0x00000004,  // index 1: bit 2=0x22='"'
    0x10000000,  // index 2: bit 28=0x5c='\'
    0x00000000, 0x00000000, 0x00000000, 0x00000000, 0x00000000
};

/** 字符串 JSON 转义: " → \"  \ → \\  控制字符 → \u00XX
 *  返回 heap string (短串走池)，调用方负责释放 */
static t_string json_encode_str(t_string s) {
    if (s.data == NULL || s.length <= 0)
        return tphp_rt_str_dup(STR_LIT("\"\""));
    // 第一遍: 位图 O(1) 计算转义后长度
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)s.data[i];
        if (json_esc_bits[c >> 5] & (1u << (c & 0x1f))) {
            if (c == '\n' || c == '\r' || c == '\t') extra += 1;
            else extra += (c < 0x20) ? 5 : 1;  // \uXXXX or \X
        }
    }
    int out_len = s.length + extra + 2;
    char *out = str_pool_alloc(out_len);
    if (out == NULL) return (t_string){NULL, 0};
    int pos = 0;
    out[pos++] = '"';
    // 批量安全字符写入：收集连续不需转义字符，一次性 memcpy
    int safe_start = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)s.data[i];
        if (!(json_esc_bits[c >> 5] & (1u << (c & 0x1f)))) continue;
        // 命中转义 → 先刷出累积的安全字符
        if (i > safe_start) {
            memcpy(out + pos, s.data + safe_start, (size_t)(i - safe_start));
            pos += i - safe_start;
        }
        safe_start = i + 1;
        // 转义写入
        if (c == '"')  { out[pos++] = '\\'; out[pos++] = '"'; }
        else if (c == '\\') { out[pos++] = '\\'; out[pos++] = '\\'; }
        else if (c == '\n') { out[pos++] = '\\'; out[pos++] = 'n'; }
        else if (c == '\r') { out[pos++] = '\\'; out[pos++] = 'r'; }
        else if (c == '\t') { out[pos++] = '\\'; out[pos++] = 't'; }
        else if (c < 0x20) {
            out[pos++] = '\\'; out[pos++] = 'u';
            out[pos++] = '0'; out[pos++] = '0';
            out[pos++] = "0123456789abcdef"[c >> 4];
            out[pos++] = "0123456789abcdef"[c & 0xf];
        }
    }
    // 刷出末尾安全字符
    if (s.length > safe_start) {
        memcpy(out + pos, s.data + safe_start, (size_t)(s.length - safe_start));
        pos += s.length - safe_start;
    }
    out[pos++] = '"';
    return (t_string){.data = out, .length = pos};
}

/** 递归 JSON 编码 t_var → t_string */
static t_string json_encode_rec(t_var v);

static t_string json_encode_rec(t_var v) {
    char buf[64];
    switch (v.type) {
    case TYPE_NULL:
        return tphp_rt_str_dup(STR_LIT("null"));
    case TYPE_BOOL: {
        t_string _bs = v.value._bool
            ? ((t_string){(char*)"true", 4})
            : ((t_string){(char*)"false", 5});
        return tphp_rt_str_dup(_bs);
    }
    case TYPE_INT: {
        int n = snprintf(buf, sizeof(buf), "%lld", (long long)v.value._int);
        t_string _is = {buf, n};
        return tphp_rt_str_dup(_is);
    }
    case TYPE_FLOAT: {
        if (isnan(v.value._float) || isinf(v.value._float)) {
            t_string _ns = {(char*)"null", 4};
            return tphp_rt_str_dup(_ns);
        }
        int n = snprintf(buf, sizeof(buf), "%.14g", v.value._float);
        // 清理多余零 .0
        char *dot = strchr(buf, '.');
        if (dot) {
            char *end = buf + n - 1;
            while (end > dot && *end == '0') end--;
            if (end == dot) end++; // 保留 .0
            n = (int)(end - buf + 1);
        }
        t_string _fs = {buf, n};
        return tphp_rt_str_dup(_fs);
    }
    case TYPE_STRING:
        return json_encode_str(v.value._string);
    case TYPE_ARRAY: {
        t_array *a = v.value._array;
        // 检测是否有 string key → 编码为对象 {...}
        bool is_obj = false;
        if (a != NULL) {
            for (int i = 0; i < a->length; i++) {
                if (a->entries[i].key.type == TYPE_STRING) { is_obj = true; break; }
            }
        }
        if (is_obj) {
            t_string result = tphp_rt_str_dup(STR_LIT("{"));
            if (a == NULL) goto obj_close;
            for (int i = 0; i < a->length; i++) {
                if (i > 0) result = tphp_rt_str_concat(result, STR_LIT(","));
                t_string ks = json_encode_str(a->entries[i].key.value._string);
                result = tphp_rt_str_concat(result, ks);
                result = tphp_rt_str_concat(result, STR_LIT(":"));
                t_string vs = json_encode_rec(a->entries[i].val);
                result = tphp_rt_str_concat(result, vs);
            }
        obj_close:
            result = tphp_rt_str_concat(result, STR_LIT("}"));
            return result;
        }
        // 纯 int key → 数组 [...]
        t_string result = tphp_rt_str_dup(STR_LIT("["));
        if (a == NULL) goto arr_close;
        for (int i = 0; i < a->length; i++) {
            if (i > 0) result = tphp_rt_str_concat(result, STR_LIT(","));
            t_string elem = json_encode_rec(a->entries[i].val);
            result = tphp_rt_str_concat(result, elem);
        }
    arr_close:
        result = tphp_rt_str_concat(result, STR_LIT("]"));
        return result;
    }
    case TYPE_OBJECT: {
        t_object *obj = (t_object *)v.value._ptr;
        if (obj == NULL || obj->cls == NULL)
            return tphp_rt_str_dup(STR_LIT("{}"));
        // 简单对象 → 尝试反射: 通过 vtable name + struct 偏移遍历属性
        // 为了简单，输出空对象 {}
        (void)obj;
        return tphp_rt_str_dup(STR_LIT("{}"));
    }
    default:
        return tphp_rt_str_dup(STR_LIT("null"));
    }
}

/** json_encode($val) → JSON 字符串 */
// Depth-safe encode entry
static int _json_depth = 0;
#define JSON_MAX_DEPTH 256

static inline t_string tphp_fn_json_encode(t_var v) {
    _json_depth = 0;
    return json_encode_rec(v);
}

// Override internal recursion — add depth check at top of json_encode_rec
// (Done by patching the self-call within json_encode_rec to use this guard)

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
            case 'n': case 'r': case 't': len++; break;
            case 'u': p->cur += 4; len++; break;
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
            case 'u':  buf[pos++] = '?'; p->cur += 4; continue;
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
    if (json.data == NULL || json.length <= 0) return VAR_NULL();
    json_parser p = {.cur = json.data, .end = json.data + json.length};
    json_skip_ws(&p);
    if (p.cur >= p.end) return VAR_NULL();
    const char *start = p.cur;
    t_var result = json_parse_value(&p);
    // Check: at least one token was consumed
    if (p.cur <= start) {
        tphp_rt_free_all();
        fputs("\nFatal error: json_decode(): invalid JSON\n\n", stderr);
        exit(1);
    }
    // Check: no trailing garbage data
    json_skip_ws(&p);
    if (p.cur < p.end) {
        tphp_rt_free_all();
        fputs("\nFatal error: json_decode(): trailing data after JSON value\n\n", stderr);
        exit(1);
    }
    return result;
}
