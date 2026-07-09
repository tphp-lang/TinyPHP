#pragma once
#include <math.h>
#include <string.h>
#include <stdlib.h>
#include <stdint.h>

// ── 基础函数（已有）─────────────────────────────────────

double calc_distance(double x1, double y1, double x2, double y2) {
    double dx = x2 - x1;
    double dy = y2 - y1;
    return sqrt(dx * dx + dy * dy);
}

static char reverse_buf[1024];
const char* reverse_str(const char* input) {
    if (!input) return "";
    int len = (int)strlen(input);
    if (len >= 1023) len = 1023;
    for (int i = 0; i < len; i++) {
        reverse_buf[i] = input[len - 1 - i];
    }
    reverse_buf[len] = '\0';
    return reverse_buf;
}

int64_t factorial(int n) {
    if (n <= 1) return 1;
    return n * factorial(n - 1);
}

// ── 数组互操作测试函数 ─────────────────────────────────

// 对 int32_t 数组求和
int64_t sum_ints(const int32_t* data, int len) {
    int64_t s = 0;
    for (int i = 0; i < len; i++) s += data[i];
    return s;
}

// 对 double 数组求和
double sum_dbls(const double* data, int len) {
    double s = 0.0;
    for (int i = 0; i < len; i++) s += data[i];
    return s;
}

// 深拷贝 int 数组（调用方负责 free）
int32_t* copy_ints(const int32_t* data, int len) {
    int32_t* out = (int32_t*)malloc((size_t)len * sizeof(int32_t));
    if (out) memcpy(out, data, (size_t)len * sizeof(int32_t));
    return out;
}

// 对每个元素翻倍（原地修改）
void double_each(int32_t* data, int len) {
    for (int i = 0; i < len; i++) data[i] *= 2;
}

// ── 对象互操作测试：Point ──────────────────────────────

typedef struct {
    double x;
    double y;
} Point;

Point* point_create(double x, double y) {
    Point* p = (Point*)malloc(sizeof(Point));
    if (p) { p->x = x; p->y = y; }
    return p;
}

double point_distance(const Point* p1, const Point* p2) {
    double dx = p2->x - p1->x;
    double dy = p2->y - p1->y;
    return sqrt(dx * dx + dy * dy);
}

double point_norm(const Point* p) {
    return sqrt(p->x * p->x + p->y * p->y);
}

void point_free(Point* p) {
    free(p);
}

// ── C 类型参数/返回值测试函数 ──────────────────────────
// 这些函数用于测试 TinyPHP 的 C.TYPE 类型注解功能

// 返回 Point 指针（测试 C.Point 返回类型）
Point* point_origin(void) {
    Point* p = (Point*)malloc(sizeof(Point));
    if (p) { p->x = 0.0; p->y = 0.0; }
    return p;
}

// 接受 Point 指针参数，返回 double（测试 C.Point 参数 + C.double 返回）
double point_get_x(Point* p) {
    return p ? p->x : 0.0;
}

// 字符串处理：接受 const char*，返回 const char*（测试 C.char_ptr）
const char* greet(const char* name) {
    static char buf[256];
    if (!name) return "Hello, stranger!";
    snprintf(buf, sizeof(buf), "Hello, %s!", name);
    return buf;
}

// 接受 C int，返回 C int（测试 C.int）
int int_square(int x) {
    return x * x;
}

// 返回 NULL 测试（错误路径）
Point* point_create_null(void) {
    return NULL;
}

// 拼接字符串数组（测试 phpc_arr_str / phpc_free_str_arr）
const char* join_strs(char** strs, int len) {
    static char buf[4096];
    buf[0] = '\0';
    if (!strs || len <= 0) return "";
    for (int i = 0; i < len; i++) {
        if (i > 0) strcat(buf, ",");
        if (strs[i]) strncat(buf, strs[i], sizeof(buf) - strlen(buf) - 1);
    }
    return buf;
}

// 返回 NULL 指针（用于 phpc_assert_ptr 测试）
void* get_null_ptr(void) {
    return NULL;
}

// ── 对象互操作 ─────────────────────────────────────────
// 验证 phpc_obj 提取的指针有效（非 NULL）
int obj_valid(void* obj) { return (obj != NULL) ? 1 : 0; }

// 从对象指针读取字段（TinyPHP 对象 = t_object _base + 字段）
// offset: sizeof(t_object)=16 (vtable* + refcount)，x 在其后
double obj_read_x(void* obj, int offset) {
    return *(double*)((char*)obj + offset);
}
double obj_read_y(void* obj, int offset_y) {
    return *(double*)((char*)obj + offset_y);
}

// ── 回调互操作 ──────────────────────────────────────────
// C 库自主定义回调签名，不依赖 TinyPHP 内部类型
// tphp 侧通过 phpc_fn_xxx() 完成类型转换

int64_t apply_closure(int32_t (*fn)(int32_t, void*), void* env, int32_t val) {
    return (int64_t)fn(val, env);
}

// 带回调的数组变换：对每个元素调 fn，返回新数组
int32_t* map_ints(const int32_t* src, int len,
                  int32_t (*fn)(int32_t, void*), void* env) {
    int32_t* out = (int32_t*)malloc((size_t)len * sizeof(int32_t));
    if (!out) return NULL;
    for (int i = 0; i < len; i++) {
        out[i] = fn(src[i], env);
    }
    return out;
}

// 与上面相同但无 void* env——测试 thunk 机制
int32_t* map_ints_ne(const int32_t* src, int len,
                     int32_t (*fn)(int32_t)) {
    int32_t* out = (int32_t*)malloc((size_t)len * sizeof(int32_t));
    if (!out) return NULL;
    for (int i = 0; i < len; i++) {
        out[i] = fn(src[i]);
    }
    return out;
}

// 多参数 + 混合类型回调（无 env）——测试 #callback + phpc_thunk
double fold_dbl(const double* src, int len,
                double (*fn)(int32_t idx, double val)) {
    double acc = 0.0;
    for (int i = 0; i < len; i++) {
        acc += fn((int32_t)i, src[i]);
    }
    return acc;
}
