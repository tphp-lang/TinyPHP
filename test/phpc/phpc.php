<?php // @skip — companion file, no class Main


namespace Phpc;

#include "include/demo.h"       // 项目头文件
#include <math.h>                // 系统头文件

// #callback 声明 C 回调签名（供 php_thunk 按签名生成 thunk）
#callback int32_t map_ne_cb(int32_t x)
#callback double fold_dbl_cb(int32_t idx, double val)

// 全平台通用标志
#flag -DNDEBUG

// Linux 特定：链接数学库
#flag Linux -lm

// 编译器特定优化（TCC 忽略 -O2，GCC/Clang 使用）
#flag GCC -O2
#flag Clang -O2 -Wall

// 平台 + 编译器组合
#flag Linux GCC -march=native

// ── 基础类型桥接 ──

function calc_distance(float $x1, float $y1, float $x2, float $y2): float
{
    return php_float(C->calc_distance(c_float($x1), c_float($y1), c_float($x2), c_float($y2)));
}

function calc_factorial(int $n): int
{
    return php_int(C->factorial(c_int($n)));
}

// ── 数组互操作 ──

function sum_array_int(array $arr): int
{
    $data = phpc_arr_int($arr);
    $result = C->sum_ints($data, c_int(count($arr)));
    phpc_free($data);
    return php_int($result);
}

function sum_array_dbl(array $arr): float
{
    $data = phpc_arr_dbl($arr);
    $result = C->sum_dbls($data, c_int(count($arr)));
    phpc_free($data);
    return php_float($result);
}

function double_each_value(array $arr): array
{
    $len = count($arr);
    $data = phpc_arr_int($arr);
    C->double_each($data, c_int($len));
    $out = phpc_new_arr_int($data, $len);
    phpc_free($data);
    return $out;
}

// ── 对象互操作 ──

function obj_is_valid(MyPoint $p): int
{
    $ptr = phpc_obj($p);
    return php_int(C->obj_valid($ptr));
}

function obj_read_x(MyPoint $p): float
{
    $ptr = phpc_obj($p);
    return php_float(C->obj_read_x($ptr, c_int(16)));
}

function obj_read_y(MyPoint $p): float
{
    $ptr = phpc_obj($p);
    return php_float(C->obj_read_y($ptr, c_int(24)));
}

// ── 回调互操作 ──
// phpc_fn_i32() 将 TinyPHP 闭包 cast 为 int32_t→int32_t C 回调指针

function apply_square(int $val): int
{
    $square = function (int $x): int {
        return $x * $x;
    };
    return php_int(C->apply_closure(phpc_fn_i32($square), phpc_env($square), c_int($val)));
}

function map_with_closure(array $arr, callable $fn): array
{
    $len = count($arr);
    $data = phpc_arr_int($arr);
    $result = C->map_ints($data, c_int($len), phpc_fn_i32($fn), phpc_env($fn));
    $out = phpc_new_arr_int($result, $len);
    phpc_free($data);
    phpc_free($result);
    return $out;
}


// ── 无 env 回调（thunk 测试）─┬

function map_ints_noenv(array $arr, callable $fn): array
{
    $len = count($arr);
    $data = phpc_arr_int($arr);
    $result = C->map_ints_ne($data, c_int($len), phpc_thunk('map_ne_cb', $fn));
    $out = phpc_new_arr_int($result, $len);
    phpc_free($data);
    phpc_free($result);
    return $out;
}

// 多参数回调 — phpc_thunk('fold_dbl_cb', $fn)  按 #callback 签名生成 thunk
function fold_double(array $arr, callable $fn): float
{
    $len = count($arr);
    $data = phpc_arr_dbl($arr);
    $result = C->fold_dbl($data, c_int($len), phpc_thunk('fold_dbl_cb', $fn));
    phpc_free($data);
    return php_float($result);
}

// ── C 类型参数/返回值测试 ──
// 借鉴 vlang 的 C.Type 设计：函数参数和返回值可直接使用 C 类型

// C.Point 返回类型：直接返回 C 结构体指针
function create_origin(): C.Point*
{
    return C->point_origin();
}

// C.Point 参数 + C.double 返回
function get_point_x(C.Point* $p): C.double
{
    return C->point_get_x($p);
}

// C.char* 参数 + C.char* 返回
function greet_name(string $name): string
{
    $result = C->greet(c_str($name));
    return php_str($result);
}

// C.int 参数 + C.int 返回
function square_int(int $x): int
{
    return php_int(C->int_square(c_int($x)));
}

// 错误路径：返回 NULL 的函数
function create_null_point(): C.Point*
{
    return C->point_create_null();
}

// 字符串数组互操作
function join_string_array(array $arr): string
{
    $data = phpc_arr_str($arr);
    $len = c_int(count($arr));
    // 调用 C 函数拼接字符串数组
    $result = C->join_strs($data, $len);
    phpc_free_str_arr($data, $len);
    return php_str($result);
}

// phpc_unregister_obj 测试：C 库自行释放对象
function create_and_free_point(float $x, float $y): int
{
    $p = C->point_create(c_float($x), c_float($y));
    if (!$p) {
        return 0;
    }
    $valid = C->obj_valid($p);
    C->point_free($p);
    phpc_unregister_obj($p);
    return php_int($valid);
}

// ── 安全 API 测试 ──

// phpc_obj_steal 测试：标记对象分离后 C 库 free，防 double-free
function steal_and_free_point(float $x, float $y): int
{
    $p = C->point_create(c_float($x), c_float($y));
    if (!$p) {
        return 0;
    }
    $valid = C->obj_valid($p);
    phpc_obj_steal($p);   // 标记分离，防止 GC double-free
    C->point_free($p);    // C 库释放
    return php_int($valid);
}

// phpc_assert_ptr 测试：NULL 指针断言
function test_assert_null_ptr(): int
{
    $ptr = C->get_null_ptr();
    try {
        phpc_assert_ptr($ptr, "test_ptr");
        return 0;  // 没抛异常 = 失败
    } catch (\Throwable $e) {
        return 1;  // 捕获到异常 = 成功
    }
}

// phpc_arr_int 类型不匹配 → tp_throw 异常路径
function test_arr_type_mismatch(): int
{
    $arr = [1, "two", 3];  // 混合类型
    try {
        $data = phpc_arr_int($arr);
        phpc_free($data);
        return 0;  // 没抛异常 = 失败
    } catch (\Throwable $e) {
        return 1;  // 捕获到异常 = 成功
    }
}

// phpc_free 自动置零验证
function test_free_zeroes_var(): int
{
    $data = phpc_arr_int([10, 20, 30]);
    phpc_free($data);
    // phpc_free 后 $data 应被自动置 NULL
    return ($data == null) ? 1 : 0;
}

// phpc_env_pin / phpc_env_unpin 测试（需要有捕获的闭包才能产生 env）
function test_env_pin(int $captured): int
{
    $fn = function(int $x) use ($captured): int { return $x * $captured; };
    $env = phpc_env_pin($fn);
    if ($env == 0) {
        return 0;  // 无 env（闭包未捕获变量）
    }
    phpc_env_unpin($env);
    return 1;
}
