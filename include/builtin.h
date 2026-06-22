#pragma once

// ============================================================
// builtin.h — TinyPHP 公开内置函数
//
//   用户 PHP 代码可直接调用这些函数，转译器将对应生成 C 调用。
//   包括：echo, var_dump 等。
// ============================================================

#include <stdio.h>
#include <stdlib.h>
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
static inline void tphp_fn_unset_obj(t_object** o)   { tphp_rt_object_free(*o); *o = NULL; }

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
static inline t_string tphp_fn_implode(t_string glue, t_array* a) {
    if (a == NULL || a->length == 0) return (t_string){NULL, 0};
    // 先逐一连接，用 tphp_rt_str_concat
    t_string result = (t_string){NULL, 0};
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        t_string part = (t_string){NULL, 0};
        if (v->type == TYPE_STRING) {
            part = v->value._string;
        } else if (v->type == TYPE_INT) {
            static char _ib[32];
            int n = snprintf(_ib, sizeof(_ib), "%lld", (long long)v->value._int);
            part = (t_string){.data = _ib, .length = n};
        } else if (v->type == TYPE_FLOAT) {
            static char _fb[32];
            int n = snprintf(_fb, sizeof(_fb), "%g", v->value._float);
            part = (t_string){.data = _fb, .length = n};
        }
        if (result.data == NULL) {
            result = tphp_rt_str_dup(part);
        } else {
            if (i > 0) result = tphp_rt_str_concat(result, glue);
            result = tphp_rt_str_concat(result, part);
        }
    }
    return result;
}

// explode($delim, $str) — 按分隔符切分字符串为数组
static inline t_array* tphp_fn_explode(t_string delim, t_string s) {
    t_array* out = tphp_fn_arr_create(8);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    if (s.data == NULL || s.length == 0) return out;
    // 空分隔符 → 整个字符串作为一个元素
    if (delim.length == 0 || delim.data == NULL) {
        out = tphp_fn_arr_push(out, VAR_STRING(tphp_rt_str_dup(s)));
        return out;
    }
    int start = 0;
    for (int i = 0; i <= s.length; i++) {
        if (i + delim.length <= s.length &&
            memcmp(s.data + i, delim.data, (size_t)delim.length) == 0) {
            int pieceLen = i - start;
            char* piece = str_pool_alloc(pieceLen);
            if (piece) {
                memcpy(piece, s.data + start, (size_t)pieceLen);
                piece[pieceLen] = '\0';
                t_string pieceStr = {.data = piece, .length = pieceLen};
                out = tphp_fn_arr_push(out, VAR_STRING(pieceStr));
            }
            start = i + delim.length;
            i = start - 1;
        }
    }
    int pieceLen = s.length - start;
    char* piece = str_pool_alloc(pieceLen);
    if (piece) {
        memcpy(piece, s.data + start, (size_t)pieceLen);
        piece[pieceLen] = '\0';
        t_string pieceStr = {.data = piece, .length = pieceLen};
        out = tphp_fn_arr_push(out, VAR_STRING(pieceStr));
    }
    return out;
}
