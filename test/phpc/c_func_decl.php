<?php
// 测试 vlang 风格 C 函数签名声明: function C.foo(...): C.ret;
// 验证 C 函数签名声明的解析、类型推导、参数转换（char*）和变参（...）支持
//   - 声明的签名仅用于类型信息，不生成 C 代码实现
//   - TypeChecker 据此推导 C 函数调用的返回类型
//   - CodeGenerator 据此进行参数类型转换（char* 参数自动剥去 STR_LIT 包装）
//   - 变参（...）之后的参数原样传递，不做转换
#include "include/demo.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#debug === C Function Declaration Test ===
#debug
#debug 1. int_square(12): 144
#debug 2. calc_distance(0,0,3,4): 5
#debug 3. greet(TinyPHP): Hello, TinyPHP!
#debug 4. point norm: 5
#debug 5. factorial(5): 120
#debug 6. strlen(hello): 5
#debug 7. snprintf: answer = 42
#debug
#debug === All passed ===

// Point 结构体声明（用于测试 C.Point* 返回类型 + 字段访问）
struct C.Point {
    C.double x;
    C.double y;
}

// ── C 函数签名声明（vlang 风格 function C.foo(...): C.ret;）──

// 整数参数和返回
function C.int_square(C.int $x): C.int;
// 浮点参数和返回
function C.calc_distance(C.double $x1, C.double $y1, C.double $x2, C.double $y2): C.double;
// 字符串参数和返回（测试 char* 参数自动转换）
function C.greet(C.char* $name): C.char*;
// void 返回
function C.point_free(C.Point* $p): C.void;
// 指针返回
function C.point_create(C.double $x, C.double $y): C.Point*;
// 递归函数
function C.factorial(C.int $n): C.int;
// 内存管理
function C.malloc(C.size_t $size): C.void*;
function C.free(C.void* $ptr): C.void;
// 字符串长度
function C.strlen(C.char* $s): C.size_t;
// 数学库
function C.sqrt(C.double $x): C.double;
// 变参函数（... 表示可变参数，之后参数不转换）
function C.snprintf(C.char* $buf, C.size_t $n, C.char* $fmt, ...): C.int;

class Main {
    public function main(): void {
        echo "=== C Function Declaration Test ===\n\n";

        // 1. C.int 返回 → int 变量（类型推导）
        int $sq = C->int_square(12);
        echo "1. int_square(12): " . $sq . "\n";

        // 2. C.double 返回 → float 变量
        float $dist = C->calc_distance(0.0, 0.0, 3.0, 4.0);
        echo "2. calc_distance(0,0,3,4): " . $dist . "\n";

        // 3. C.char* 参数和返回
        //   声明 char* 参数后，字符串字面量自动剥去 STR_LIT 包装转为 C 字符串
        C.char* $greeting = C->greet("TinyPHP");
        echo "3. greet(TinyPHP): " . php_str($greeting) . "\n";

        // 4. 指针返回 + 结构体字段访问 + void 返回
        C.Point* $p = C->point_create(3.0, 4.0);
        float $norm = C->sqrt($p->x * $p->x + $p->y * $p->y);
        echo "4. point norm: " . $norm . "\n";
        C->point_free($p);

        // 5. 递归 C 函数
        int $fact = C->factorial(5);
        echo "5. factorial(5): " . $fact . "\n";

        // 6. C.char* 参数 + size_t 返回
        int $len = C->strlen("hello");
        echo "6. strlen(hello): " . $len . "\n";

        // 7. 变参函数（... 声明，可变参数不转换）
        //    fmt 为 char* 参数（字符串字面量自动转换）
        //    42 为变参（int 字面量原样传递）
        C.char* $buf = C->malloc(64);
        defer C->free($buf);
        C->snprintf($buf, 64, "answer = %d", 42);
        echo "7. snprintf: " . php_str($buf) . "\n";

        echo "\n=== All passed ===\n";
    }
}
