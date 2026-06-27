#pragma once

// ============================================================
// builtin.h — TinyPHP 公开内置函数
//
//   用户 PHP 代码可直接调用这些函数，转译器将对应生成 C 调用。
//   包括：echo, var_dump 等。
// ============================================================

#ifndef _USE_MATH_DEFINES
#define _USE_MATH_DEFINES
#endif
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include "types.h"

// ============================================================
// tphp_echo — 输出字符串原始字节到 stdout
//   二进制安全，不依赖 \0，不解析格式化占位符
//   PHP: echo "hello";
// ============================================================
static inline void tphp_fn_echo(t_string s) {
    if (s.data != NULL && s.length > 0) {
        fwrite(s.data, 1, (size_t)s.length, stdout);
    }
}

// ============================================================
// tphp_var_dump — PHP var_dump 等价实现
//   根据 t_var 的 type 标签，格式化打印到 stdout
//   支持: int, float, bool, string, null, array, callback
//   PHP: var_dump($x);
// ============================================================
static void tphp_fn_var_dump_indent(int depth) {
    for (int i = 0; i < depth; i++) fputs("  ", stdout);
}

static void tphp_fn_var_dump_rec(t_var v, int depth);

static void tphp_fn_var_dump(t_var v) {
    tphp_fn_var_dump_rec(v, 0);
    fputc('\n', stdout);
}

static void tphp_fn_var_dump_rec(t_var v, int depth) {
    switch (v.type) {
    case TYPE_NULL:
        fputs("NULL", stdout);
        break;
    case TYPE_BOOL:
        fputs(v.value._bool ? "bool(true)" : "bool(false)", stdout);
        break;
    case TYPE_INT:
        fprintf(stdout, "int(%lld)", (long long)v.value._int);
        break;
    case TYPE_FLOAT:
        fprintf(stdout, "float(%g)", v.value._float);
        break;
    case TYPE_STRING: {
        int len = (v.value._string.length > 0) ? v.value._string.length : 0;
        fprintf(stdout, "string(%d) \"", len);
        if (len > 0 && v.value._string.data != NULL) {
            fwrite(v.value._string.data, 1, (size_t)len, stdout);
        }
        fputc('"', stdout);
        break;
    }
    case TYPE_CALLBACK:
        fputs("callable", stdout);
        break;
    case TYPE_ARRAY: {
        t_array* a = v.value._array;
        int count = tphp_fn_arr_count(a);
        fprintf(stdout, "array(%d) {\n", count);
        if (a != NULL) {
            for (int i = 0; i < count; i++) {
                t_var* key = &a->entries[i].key;
                t_var* val = &a->entries[i].val;

                tphp_fn_var_dump_indent(depth + 1);

                if (key->type == TYPE_INT) {
                    fprintf(stdout, "[%lld]=>\n", (long long)key->value._int);
                } else if (key->type == TYPE_STRING) {
                    int klen = (key->value._string.length > 0) ? key->value._string.length : 0;
                    fprintf(stdout, "[\"%.*s\"]=>\n",
                        klen,
                        (key->value._string.data != NULL && klen > 0) ? key->value._string.data : "");
                } else {
                    fprintf(stdout, "[?]=>\n");
                }

                tphp_fn_var_dump_indent(depth + 1);

                if (val->type == TYPE_NULL) {
                    fputs("NULL\n", stdout);
                } else {
                    tphp_fn_var_dump_rec(*val, depth + 1);
                }
                fputc('\n', stdout);
            }
        }
        tphp_fn_var_dump_indent(depth);
        fputc('}', stdout);
        break;
    }
    default:
        fputs("unknown", stdout);
        break;
    }
}

// ============================================================
// tphp_fn_exit — 终止程序
//   PHP: exit($code);  exit();
// ============================================================
static inline void tphp_fn_exit(t_int code) {
    exit((int)code);
}

// ============================================================
// tphp_fn_isset — 检测变量是否已设置且不为 null
//   PHP: isset($x);
//   非指针类型（int/float/bool/string 栈值）始终为 true
// ============================================================
static inline bool tphp_fn_isset(void* p) {
    return p != NULL;
}

// ============================================================
// tphp_fn_empty — PHP empty() 按类型分发
//   int:     v == 0
//   float:   v == 0.0
//   bool:    !v
//   string:  tphp_rt_str_is_falsy(s)   (空串或 "0")
//   null:    始终 true
// ============================================================
static inline bool tphp_fn_empty_int(t_int v)       { return v == 0; }
static inline bool tphp_fn_empty_float(t_float v)   { return v == 0.0; }
static inline bool tphp_fn_empty_bool(t_bool v)     { return !v; }
static inline bool tphp_fn_empty_str(t_string s)    { return tphp_rt_str_is_falsy(s); }
static inline bool tphp_fn_empty_null(void* p)      { (void)p; return true; }

// ============================================================
// tphp_fn_unset — PHP unset() 按类型释放
//   int/float/bool: 置零
//   string:         释放堆内存
//   array:          引用计数释放
//   object:         引用计数析构
//   null:           无操作
// ============================================================
static inline void tphp_fn_unset_str(t_string* s)    { tphp_rt_str_free(s); }
static inline void tphp_fn_unset_arr(t_array** a)    { if (*a) { tphp_fn_arr_free(*a); *a = NULL; } }
static inline void tphp_fn_unset_obj(void** o)       { tp_obj_release(*o); *o = NULL; }

// ============================================================
// tphp_fn_is_* — t_var 类型检测（mixed/union 变量用）
//   静态类型变量在编译期直接生成 true/false，不调用这些函数
// ============================================================
static inline bool tphp_fn_is_int(t_var v)    { return v.type == TYPE_INT; }
static inline bool tphp_fn_is_float(t_var v)  { return v.type == TYPE_FLOAT; }
static inline bool tphp_fn_is_string(t_var v) { return v.type == TYPE_STRING; }
static inline bool tphp_fn_is_bool(t_var v)   { return v.type == TYPE_BOOL; }
static inline bool tphp_fn_is_array(t_var v)  { return v.type == TYPE_ARRAY; }
static inline bool tphp_fn_is_null(t_var v)   { return v.type == TYPE_NULL; }
static inline bool tphp_fn_is_object(t_var v) { return v.type == TYPE_OBJECT; }
static inline bool tphp_fn_is_callable(t_var v) { return v.type == TYPE_CALLBACK; }

// ============================================================
// 数组操作函数
// ============================================================

// array_push($arr, $val) — 尾部追加元素，返回新元素个数
static inline t_int tphp_fn_array_push(t_array** a, t_var val) {
    if (a == NULL || *a == NULL) return 0;
    *a = tphp_fn_arr_push(*a, val);
    return (*a)->length;
}

// array_pop($arr) — 弹出尾部元素，返回 t_var（空数组返回 TYPE_NULL 的 t_var）
static inline t_var tphp_fn_array_pop(t_array** a) {
    t_var out = VAR_NULL();
    if (a != NULL && *a != NULL) tphp_fn_arr_pop(*a, &out);
    return out;
}

// in_array($needle, $haystack) — 值是否存在
static inline bool tphp_fn_in_array(t_var needle, t_array* a) {
    if (a == NULL) return false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (needle.type == TYPE_INT && v->type == TYPE_INT) {
            if (needle.value._int == v->value._int) return true;
        } else if (needle.type == TYPE_STRING && v->type == TYPE_STRING) {
            if (tphp_rt_str_eq(needle.value._string, v->value._string)) return true;
        } else if (needle.type == TYPE_BOOL && v->type == TYPE_BOOL) {
            if (needle.value._bool == v->value._bool) return true;
        } else if (needle.type == TYPE_NULL && v->type == TYPE_NULL) {
            return true;
        }
    }
    return false;
}

// array_key_exists($key, $arr) — 键是否存在
static inline bool tphp_fn_array_key_exists_int(t_int key, t_array* a) {
    return tphp_fn_arr_get_int(a, key) != NULL;
}
static inline bool tphp_fn_array_key_exists_str(t_string key, t_array* a) {
    return tphp_fn_arr_get_str(a, key) != NULL;
}

// array_keys($arr) — 返回所有 key 组成的新数组
static inline t_array* tphp_fn_array_keys(t_array* a) {
    t_array* out = tphp_fn_arr_create(a ? a->length : 0);
    if (a == NULL || out == NULL) return out;
    tphp_rt_register((void*)out, 1);
    for (int i = 0; i < a->length; i++) {
        t_var k = a->entries[i].key;
        // 复制 string key 到新数组（堆分配）
        t_var kcopy = k;
        if (k.type == TYPE_STRING && k.value._string.length > 0) {
            kcopy.value._string = tphp_rt_str_dup(k.value._string);
        }
        out = tphp_fn_arr_push(out, kcopy);
    }
    return out;
}

// array_values($arr) — 返回所有 value 组成的新数组
static inline t_array* tphp_fn_array_values(t_array* a) {
    t_array* out = tphp_fn_arr_create(a ? a->length : 0);
    if (a == NULL || out == NULL) return out;
    tphp_rt_register((void*)out, 1);
    for (int i = 0; i < a->length; i++) {
        out = tphp_fn_arr_push(out, a->entries[i].val);
    }
    return out;
}

// array_merge($arr1, $arr2) — 合并两个数组（int key 重新索引，str key 保留）
static inline t_array* tphp_fn_array_merge(t_array* a, t_array* b) {
    int total = (a ? a->length : 0) + (b ? b->length : 0);
    t_array* out = tphp_fn_arr_create(total);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    // 先追加 a
    if (a) {
        for (int i = 0; i < a->length; i++) out = tphp_fn_arr_push(out, a->entries[i].val);
    }
    // 再追加 b（int key 不保留，str key 用 set_str）
    if (b) {
        for (int i = 0; i < b->length; i++) {
            if (b->entries[i].key.type == TYPE_STRING) {
                out = tphp_fn_arr_set_str(out, b->entries[i].key.value._string, b->entries[i].val);
            } else {
                out = tphp_fn_arr_push(out, b->entries[i].val);
            }
        }
    }
    return out;
}

// implode($glue, $arr) — 用分隔符连接数组元素为字符串
// 优化：两遍扫描 → 一次分配，O(N) memcpy 替代 O(N²) 逐对拼接
static inline t_string tphp_fn_implode(t_string glue, t_array* a) {
    if (a == NULL || a->length == 0) return (t_string){NULL, 0};

    int glueLen = (glue.data != NULL && glue.length > 0) ? glue.length : 0;

    // ── Pass 1: 计算总长度 ────────────────────────────────
    int totalLen = 0;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        int partLen = 0;
        if (v->type == TYPE_STRING) {
            partLen = (v->value._string.data != NULL) ? v->value._string.length : 0;
        } else if (v->type == TYPE_INT) {
            char _ib[32];
            partLen = snprintf(_ib, sizeof(_ib), "%lld", (long long)v->value._int);
        } else if (v->type == TYPE_FLOAT) {
            char _fb[64];
            partLen = snprintf(_fb, sizeof(_fb), "%g", v->value._float);
        }
        if (partLen < 0) partLen = 0;
        totalLen += partLen;
        if (i > 0 && glueLen > 0) totalLen += glueLen;
        // 防溢出：超过 8MB 拒绝
        if (unlikely(totalLen > 0x7FFFFF)) return (t_string){NULL, 0};
    }
    if (totalLen <= 0) return (t_string){NULL, 0};

    // ── 一次分配 ──────────────────────────────────────────
    char* buf = str_pool_alloc(totalLen);
    if (unlikely(buf == NULL)) return (t_string){NULL, 0};

    // ── Pass 2: 逐片写入 ──────────────────────────────────
    int pos = 0;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        // 分隔符（首元素前不写）
        if (i > 0 && glueLen > 0) {
            memcpy(buf + pos, glue.data, (size_t)glueLen);
            pos += glueLen;
        }
        // 元素值
        if (v->type == TYPE_STRING) {
            t_string s = v->value._string;
            int slen = (s.data != NULL && s.length > 0) ? s.length : 0;
            if (slen > 0) {
                memcpy(buf + pos, s.data, (size_t)slen);
                pos += slen;
            }
        } else if (v->type == TYPE_INT) {
            int n = snprintf(buf + pos, (size_t)(totalLen - pos + 1),
                             "%lld", (long long)v->value._int);
            if (n > 0) pos += n;
        } else if (v->type == TYPE_FLOAT) {
            int n = snprintf(buf + pos, (size_t)(totalLen - pos + 1),
                             "%g", v->value._float);
            if (n > 0) pos += n;
        }
    }
    buf[totalLen] = '\0';
    return (t_string){buf, totalLen};
}

// explode($delim, $str) — 按分隔符切分字符串为数组
// 优化：先数分隔符 → 精确容量创建，零 realloc
static inline t_array* tphp_fn_explode(t_string delim, t_string s) {
    if (s.data == NULL || s.length == 0) {
        t_array* out = tphp_fn_arr_create(0);
        if (out) tphp_rt_register((void*)out, 1);
        return out;
    }
    // 空分隔符 → 整个字符串作为一个元素
    if (delim.length == 0 || delim.data == NULL) {
        t_array* out = tphp_fn_arr_create(1);
        if (out == NULL) return NULL;
        tphp_rt_register((void*)out, 1);
        out = tphp_fn_arr_push(out, VAR_STRING(tphp_rt_str_dup(s)));
        return out;
    }

    // ── Pass 1: 数分隔符，计算片段数 ────────────────────────
    int pieceCount = 1; // 至少 1 片
    for (int i = 0; i <= s.length; i++) {
        if (i + delim.length <= s.length &&
            memcmp(s.data + i, delim.data, (size_t)delim.length) == 0) {
            pieceCount++;
            i += delim.length - 1; // for 循环会 ++
        }
    }

    // ── 一次分配精确容量 ──────────────────────────────────
    t_array* out = tphp_fn_arr_create(pieceCount);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);

    // ── Pass 2: 切分并 push（容量已够，零 realloc）────────
    int start = 0;
    for (int i = 0; i <= s.length; i++) {
        if (i + delim.length <= s.length &&
            memcmp(s.data + i, delim.data, (size_t)delim.length) == 0) {
            int pieceLen = i - start;
            if (pieceLen > 0) {
                char* piece = str_pool_alloc(pieceLen);
                if (piece) {
                    memcpy(piece, s.data + start, (size_t)pieceLen);
                    piece[pieceLen] = '\0';
                    out = tphp_fn_arr_push(out, VAR_STRING(((t_string){piece, pieceLen})));
                }
            }
            start = i + delim.length;
            i = start - 1;
        }
    }
    int pieceLen = s.length - start;
    if (pieceLen > 0) {
        char* piece = str_pool_alloc(pieceLen);
        if (piece) {
            memcpy(piece, s.data + start, (size_t)pieceLen);
            piece[pieceLen] = '\0';
            out = tphp_fn_arr_push(out, VAR_STRING(((t_string){piece, pieceLen})));
        }
    }
    return out;
}

/* ============================================================
 * String functions
 * ============================================================ */

static inline t_int tphp_fn_strlen(t_string s) {
    return (s.data != NULL && s.length > 0) ? (t_int)s.length : 0;
}

static inline t_string tphp_fn_trim(t_string s) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    int start = 0, end = s.length - 1;
    while (start <= end && (unsigned char)s.data[start] <= ' ') start++;
    while (end >= start && (unsigned char)s.data[end] <= ' ') end--;
    int len = end - start + 1;
    if (len <= 0) return (t_string){NULL, 0};
    if (start == 0 && len == s.length) return s; // zero-alloc shortcut
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){NULL, 0};
    memcpy(buf, s.data + start, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_string tphp_fn_ltrim(t_string s) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    int start = 0;
    while (start < s.length && (unsigned char)s.data[start] <= ' ') start++;
    int len = s.length - start;
    if (len <= 0) return (t_string){NULL, 0};
    if (start == 0) return s; // zero-alloc
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){NULL, 0};
    memcpy(buf, s.data + start, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_string tphp_fn_rtrim(t_string s) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    int end = s.length - 1;
    while (end >= 0 && (unsigned char)s.data[end] <= ' ') end--;
    int len = end + 1;
    if (len <= 0) return (t_string){NULL, 0};
    if (len == s.length) return s; // zero-alloc
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){NULL, 0};
    memcpy(buf, s.data, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_string tphp_fn_substr(t_string s, t_int offset, t_int length) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    int slen = s.length;
    int start = (int)offset;
    if (start < 0) start = slen + start;
    if (start < 0) start = 0;
    if (start >= slen) return (t_string){NULL, 0};
    int len;
    if (length < 0) {
        len = slen - start + (int)length;
        if (len < 0) len = 0;
    } else if (length == 0) {
        len = slen - start;
    } else {
        len = (int)length;
        if (start + len > slen) len = slen - start;
    }
    if (len <= 0) return (t_string){NULL, 0};
    if (start == 0 && len == slen) return s; // zero-alloc full copy
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){NULL, 0};
    memcpy(buf, s.data + start, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_int tphp_fn_strpos(t_string haystack, t_string needle) {
    if (haystack.data == NULL || needle.data == NULL) return -1;
    if (needle.length <= 0) return 0;
    if (needle.length > haystack.length) return -1;
    for (int i = 0; i <= haystack.length - needle.length; i++) {
        if (memcmp(haystack.data + i, needle.data, (size_t)needle.length) == 0)
            return (t_int)i;
    }
    return -1;
}

static inline t_bool tphp_fn_str_contains(t_string haystack, t_string needle) {
    return tphp_fn_strpos(haystack, needle) >= 0;
}

static inline t_string tphp_fn_sprintf(t_string fmt) {
    // No args — just copy format string
    return tphp_rt_str_dup(fmt);
}

static inline t_string tphp_fn_str_replace(t_string search, t_string replace, t_string subject) {
    if (subject.data == NULL || subject.length <= 0) return (t_string){NULL, 0};
    if (search.data == NULL || search.length <= 0 || search.length > subject.length)
        return tphp_rt_str_dup(subject);
    // Count occurrences
    int count = 0;
    for (int i = 0; i <= subject.length - search.length; i++) {
        if (memcmp(subject.data + i, search.data, (size_t)search.length) == 0) {
            count++; i += search.length - 1;
        }
    }
    if (count == 0) return tphp_rt_str_dup(subject);
    // Calculate new length and build result
    int new_len = subject.length + count * (replace.length - search.length);
    if (new_len <= 0) return (t_string){NULL, 0};
    char *buf = str_pool_alloc(new_len);
    if (buf == NULL) return (t_string){NULL, 0};
    int pos = 0, si = 0;
    while (si < subject.length) {
        if (si <= subject.length - search.length &&
            memcmp(subject.data + si, search.data, (size_t)search.length) == 0) {
            memcpy(buf + pos, replace.data, (size_t)replace.length);
            pos += replace.length;
            si += search.length;
        } else {
            buf[pos++] = subject.data[si++];
        }
    }
    buf[new_len] = '\0';
    return (t_string){buf, new_len};
}

/* ============================================================
 * Math / General functions
 * ============================================================ */

static inline t_var tphp_fn_max(t_array *a) {
    if (unlikely(a == NULL || a->length == 0)) {
        tphp_rt_free_all();
        fputs("\nFatal error: max(): Array must contain at least one element\n\n", stderr);
        exit(1);
    }
    t_var result;
    bool found = false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type != TYPE_INT && v->type != TYPE_FLOAT) continue;
        if (!found) { result = *v; found = true; continue; }
        if (v->type == TYPE_INT && result.type == TYPE_INT) {
            if (v->value._int > result.value._int) result = *v;
        } else if (v->type == TYPE_FLOAT && result.type == TYPE_FLOAT) {
            if (v->value._float > result.value._float) result = *v;
        } else {
            t_float a = (v->type == TYPE_INT) ? (t_float)v->value._int : v->value._float;
            t_float b = (result.type == TYPE_INT) ? (t_float)result.value._int : result.value._float;
            if (a > b) result = *v;
        }
    }
    return found ? result : VAR_NULL();
}

static inline t_var tphp_fn_min(t_array *a) {
    if (unlikely(a == NULL || a->length == 0)) {
        tphp_rt_free_all();
        fputs("\nFatal error: min(): Array must contain at least one element\n\n", stderr);
        exit(1);
    }
    t_var result;
    bool found = false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type != TYPE_INT && v->type != TYPE_FLOAT) continue;
        if (!found) { result = *v; found = true; continue; }
        if (v->type == TYPE_INT && result.type == TYPE_INT) {
            if (v->value._int < result.value._int) result = *v;
        } else if (v->type == TYPE_FLOAT && result.type == TYPE_FLOAT) {
            if (v->value._float < result.value._float) result = *v;
        } else {
            t_float a = (v->type == TYPE_INT) ? (t_float)v->value._int : v->value._float;
            t_float b = (result.type == TYPE_INT) ? (t_float)result.value._int : result.value._float;
            if (a < b) result = *v;
        }
    }
    return found ? result : VAR_NULL();
}

/* ============================================================
 * Type conversion functions
 * ============================================================ */

static inline t_int tphp_fn_intval(t_var v) {
    if (v.type == TYPE_INT)   return v.value._int;
    if (v.type == TYPE_FLOAT) return (t_int)v.value._float;
    if (v.type == TYPE_BOOL)  return v.value._bool ? 1 : 0;
    if (v.type == TYPE_STRING) return tphp_rt_parse_int(v.value._string);
    return 0;
}

static inline t_float tphp_fn_floatval(t_var v) {
    if (v.type == TYPE_INT)   return (t_float)v.value._int;
    if (v.type == TYPE_FLOAT) return v.value._float;
    if (v.type == TYPE_BOOL)  return v.value._bool ? 1.0 : 0.0;
    if (v.type == TYPE_STRING) return tphp_rt_parse_float(v.value._string);
    return 0.0;
}

static inline t_string tphp_fn_strval(t_var v) {
    if (v.type == TYPE_INT)   return tphp_rt_str_from_int(v.value._int);
    if (v.type == TYPE_FLOAT) return tphp_rt_str_from_float(v.value._float);
    if (v.type == TYPE_BOOL)  return v.value._bool ? STR_LIT("1") : STR_LIT("");
    if (v.type == TYPE_STRING) return tphp_rt_str_dup(v.value._string);
    if (v.type == TYPE_NULL)  return (t_string){NULL, 0};
    return STR_LIT("");
}

static inline t_bool tphp_fn_boolval(t_var v) {
    if (v.type == TYPE_INT)   return v.value._int != 0;
    if (v.type == TYPE_FLOAT) return v.value._float != 0.0;
    if (v.type == TYPE_BOOL)  return v.value._bool;
    if (v.type == TYPE_STRING) return !tphp_rt_str_is_falsy(v.value._string);
    return false;
}

/* ============================================================
 * String (case conversion)
 * ============================================================ */

static inline t_string tphp_fn_strtolower(t_string s) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    int changed = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)s.data[i];
        if (c >= 'A' && c <= 'Z') { changed = 1; break; }
    }
    if (!changed) return s; // zero-alloc
    char *buf = str_pool_alloc(s.length);
    if (buf == NULL) return (t_string){NULL, 0};
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)s.data[i];
        buf[i] = (c >= 'A' && c <= 'Z') ? (char)(c + 32) : (char)c;
    }
    buf[s.length] = '\0';
    return (t_string){buf, s.length};
}

static inline t_string tphp_fn_strtoupper(t_string s) {
    if (s.data == NULL || s.length <= 0) return (t_string){NULL, 0};
    int changed = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)s.data[i];
        if (c >= 'a' && c <= 'z') { changed = 1; break; }
    }
    if (!changed) return s; // zero-alloc
    char *buf = str_pool_alloc(s.length);
    if (buf == NULL) return (t_string){NULL, 0};
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)s.data[i];
        buf[i] = (c >= 'a' && c <= 'z') ? (char)(c - 32) : (char)c;
    }
    buf[s.length] = '\0';
    return (t_string){buf, s.length};
}

/* ============================================================
 * Math
 * ============================================================ */
#include <math.h>

static inline t_int   tphp_fn_abs(t_int v)   { return llabs(v); }
static inline t_float tphp_fn_round(t_float v) { return round(v); }
static inline t_float tphp_fn_ceil(t_float v)  { return ceil(v); }
static inline t_float tphp_fn_floor(t_float v) { return floor(v); }
static inline t_float tphp_fn_sqrt(t_float v)  { return v >= 0.0 ? sqrt(v) : 0.0; }

/* ============================================================
 * 断言函数 — 测试框架专用，失败时打印错误并 exit(非零)
 *   assert_true($cond)           → 布尔断言
 *   assert_eq_int($a, $b)        → 整数相等
 *   assert_eq_float($a, $b)      → 浮点相等
 *   assert_eq_str($a, $b)        → 字符串相等
 *   assert_false($cond)          → 布尔取反断言
 * ============================================================ */
// ── ord($ch) / chr($n) — 字符 ↔ ASCII ──────────────────────
static inline t_int tphp_fn_ord(t_string s) {
    if (s.data == NULL || s.length < 1) return 0;
    return (t_int)(unsigned char)s.data[0];
}

static inline t_string tphp_fn_chr(t_int n) {
    static char _chr[2];
    _chr[0] = (char)(n & 0xFF);
    _chr[1] = '\0';
    return (t_string){_chr, 1};
}

// ── str_starts_with / str_ends_with — PHP 8.0+ ──────────────
static inline t_bool tphp_fn_str_starts_with(t_string haystack, t_string needle) {
    if (haystack.data == NULL || needle.data == NULL) return false;
    if (needle.length == 0) return true;
    if (needle.length > haystack.length) return false;
    return memcmp(haystack.data, needle.data, (size_t)needle.length) == 0;
}

static inline t_bool tphp_fn_str_ends_with(t_string haystack, t_string needle) {
    if (haystack.data == NULL || needle.data == NULL) return false;
    if (needle.length == 0) return true;
    if (needle.length > haystack.length) return false;
    return memcmp(haystack.data + haystack.length - needle.length,
                  needle.data, (size_t)needle.length) == 0;
}

// ── is_numeric($str) — 检查是否为数值字符串 ──────────────────
static inline t_bool tphp_fn_is_numeric_str(t_string s) {
    if (s.data == NULL || s.length == 0) return false;
    // 需要 null-terminated 副本给 strto*
    char buf[256];
    int len = s.length < 255 ? s.length : 255;
    memcpy(buf, s.data, (size_t)len);
    buf[len] = '\0';
    char *end = NULL;
    strtoll(buf, &end, 10);
    if (end == buf + len) return true;
    strtod(buf, &end);
    if (end == buf + len) return true;
    return false;
}

// ── gettype($v) — 返回类型字符串 ─────────────────────────────
static inline t_string tphp_fn_gettype(t_var v) {
    static const char *names[] = {
        [TYPE_NULL]     = "NULL",
        [TYPE_INT]      = "int",
        [TYPE_FLOAT]    = "float",
        [TYPE_BOOL]     = "bool",
        [TYPE_STRING]   = "string",
        [TYPE_ARRAY]    = "array",
        [TYPE_OBJECT]   = "object",
        [TYPE_CALLBACK] = "object",
    };
    const char *nm = (v.type <= TYPE_CALLBACK) ? names[v.type] : "unknown";
    return (t_string){(char*)nm, (int)strlen(nm)};
}

// ── getenv / putenv — 环境变量 ───────────────────────────────
#include <stdlib.h>
static inline t_string tphp_fn_getenv(t_string key) {
    if (key.data == NULL) return (t_string){NULL, 0};
    static char _env[4096];
    // 临时复制到可写缓冲区（getenv 返回的指针可能不安全）
    char tmp[256];
    int klen = key.length < 255 ? key.length : 255;
    memcpy(tmp, key.data, (size_t)klen);
    tmp[klen] = '\0';
    char *val = getenv(tmp);
    if (val == NULL) return (t_string){NULL, 0};
    int vlen = (int)strlen(val);
    if (vlen > 4095) vlen = 4095;
    memcpy(_env, val, (size_t)vlen);
    _env[vlen] = '\0';
    return (t_string){_env, vlen};
}

static inline void tphp_fn_putenv(t_string key) {
    if (key.data == NULL) return;
    static char _buf[1024];
    int len = key.length < 1023 ? key.length : 1023;
    memcpy(_buf, key.data, (size_t)len);
    _buf[len] = '\0';
    putenv(_buf);
}

// ── 断言 ────────────────────────────────────────────────────
static inline void tphp_fn_assert_true(t_bool cond) {
    if (unlikely(!cond)) {
        tphp_rt_free_all();
        fputs("\nASSERT FAIL: assert_true()\n\n", stderr);
        exit(2);
    }
}
static inline void tphp_fn_assert_false(t_bool cond) {
    if (unlikely(cond)) {
        tphp_rt_free_all();
        fputs("\nASSERT FAIL: assert_false()\n\n", stderr);
        exit(2);
    }
}
static inline void tphp_fn_assert_eq_int(t_int a, t_int b) {
    if (unlikely(a != b)) {
        tphp_rt_free_all();
        fprintf(stderr, "\nASSERT FAIL: assert_eq_int(%lld, %lld)\n\n",
            (long long)a, (long long)b);
        exit(2);
    }
}
static inline void tphp_fn_assert_eq_float(t_float a, t_float b) {
    if (unlikely(a != b)) {
        tphp_rt_free_all();
        fprintf(stderr, "\nASSERT FAIL: assert_eq_float(%g, %g)\n\n", a, b);
        exit(2);
    }
}
static inline void tphp_fn_assert_eq_str(t_string a, t_string b) {
    if (unlikely(!tphp_rt_str_eq(a, b))) {
        tphp_rt_free_all();
        fprintf(stderr, "\nASSERT FAIL: assert_eq_str(len=%d vs len=%d)\n\n",
            a.length, b.length);
        exit(2);
    }
}




