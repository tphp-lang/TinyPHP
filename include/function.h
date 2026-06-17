#pragma once

#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include "types.h"

// ============================================================
// tphp_echo — 输出字符串原始字节到 stdout
//   二进制安全，不依赖 \0，不解析格式化占位符
// ============================================================
static inline void tphp_echo(t_string s) {
    if (s.data != NULL && s.length > 0) {
        fwrite(s.data, 1, (size_t)s.length, stdout);
    }
}

// ============================================================
// tphp_safe_malloc — 安全的 malloc，失败则 abort
//   calloc 返回零初始化内存，避免未初始化的指针
// ============================================================
static inline void* tphp_safe_malloc(size_t size) {
    void* p = calloc(1, size);
    if (p == NULL) {
        fputs("FATAL: out of memory\n", stderr);
        abort();
    }
    return p;
}

// ============================================================
// tphp_safe_free — 安全的 free，先检查非空再释放
// ============================================================
static inline void tphp_safe_free(void* p) {
    if (p != NULL) {
        free(p);
    }
}

// ============================================================
// tphp_build_argv — 将 C 的 char** argv 转为 t_array*（万能数组）
//   由 int main() 调用，供 Main.__construct 接收
// ============================================================
static inline t_array* tphp_build_argv(int argc, char **argv) {
    t_array* a = tphp_arr_create();
    if (a == NULL) return NULL;
    for (int i = 0; i < argc; i++) {
        if (argv[i] != NULL) {
            t_string s = {argv[i], (int)strlen(argv[i])};
            tphp_arr_push(a, VAR_STRING(s));
        } else {
            tphp_arr_push(a, VAR_STRING(((t_string){NULL, 0})));
        }
    }
    return a;
}

// ============================================================
// tphp_str_from_int — int → t_string（栈缓冲区，单线程安全）
//   用于 echo 非字符串值时的自动转换
// ============================================================
static inline t_string tphp_str_from_int(t_int v) {
    static char _buf[32];
    int len = snprintf(_buf, sizeof(_buf), "%lld", (long long)v);
    return (t_string){_buf, len > 0 ? len : 0};
}

static inline t_string tphp_str_from_float(t_float v) {
    static char _buf[64];
    int len = snprintf(_buf, sizeof(_buf), "%g", v);
    return (t_string){_buf, len > 0 ? len : 0};
}

static inline t_string tphp_str_from_bool(t_bool v) {
    return v ? STR_LIT("true") : STR_LIT("false");
}

// ============================================================
// tphp_object_free — 统一析构入口
//   refcount 减 1，归零则调用 vtable->dtor 并 free
// ============================================================
static inline void tphp_object_free(t_object *obj) {
    if (obj == NULL) return;
    if (--obj->refcount > 0) return;
    if (obj->vtable != NULL && obj->vtable->dtor != NULL) {
        obj->vtable->dtor(obj);           // 先析构（用户清理内部资源）
    }
    free(obj);                            // 再释放对象自身内存
}

// ============================================================
// tphp_var_dump — PHP var_dump 等价实现
//   根据 t_var 的 type 标签，格式化打印到 stdout
//   支持: int, float, bool, string, null, array, object
// ============================================================
static void tphp_var_dump_indent(int depth) {
    for (int i = 0; i < depth; i++) fputs("  ", stdout);
}

static void tphp_var_dump_rec(t_var v, int depth);

static void tphp_var_dump(t_var v) {
    tphp_var_dump_rec(v, 0);
    fputc('\n', stdout);
}

static void tphp_var_dump_rec(t_var v, int depth) {
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
        /* 防御: 保护异常长度（负数）和无效指针 */
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
        int count = tphp_arr_count(a);
        fprintf(stdout, "array(%d) {\n", count);
        if (a != NULL && a->entries != NULL) {
            for (int i = 0; i < count; i++) {
                t_var* key = a->entries[i].key;
                t_var* val = a->entries[i].value;

                tphp_var_dump_indent(depth + 1);

                /* 安全输出 key */
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

                tphp_var_dump_indent(depth + 1);

                /* 安全输出 value */
                if (val == NULL) {
                    fputs("NULL", stdout);
                } else {
                    tphp_var_dump_rec(*val, depth + 1);
                }
                fputc('\n', stdout);
            }
        }
        tphp_var_dump_indent(depth);
        fputc('}', stdout);
        break;
    }
    default:
        fputs("unknown", stdout);
        break;
    }
}
