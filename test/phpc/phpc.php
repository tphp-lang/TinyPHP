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
