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
        if (a != NULL && a->entries != NULL) {
            for (int i = 0; i < count; i++) {
                t_var* key = a->entries[i].key;
                t_var* val = a->entries[i].value;

                tphp_fn_var_dump_indent(depth + 1);

                if (key == NULL) {
                    fputs("[NULL_KEY]=>\n", stdout);
                } else if (key->type == TYPE_INT) {
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

                if (val == NULL) {
                    fputs("NULL", stdout);
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
