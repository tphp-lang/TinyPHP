<?php

namespace Main;

function main(): void
{
    // 整型变量
    $a = 1; // 默认 强类型 int,类似 c 的 long long
    var_dump($a); // 输出 (int)1

    // 浮点型变量
    $b = 1.0; // 默认 强类型 float,类似 c 的 double
    var_dump($b); // 输出 (float)1.0

    // 字符串变量
    $c = 'hello world🚀'; // 默认 强类型 string,类似 c 的 string 结构体
    var_dump($c); // 输出 (string)hello world
    var_dump($c[0]); // 输出 (string)h
    var_dump(strlen($c)); // 输出 (int)15
    var_dump($c[11][15]); // 输出 (string)🚀 返回字符串从索引 11 到 15（不包含 15）的字节范围
    // 字符串拼接, 这里和PHP一样
    var_dump($c . $c . "-$c"); // 输出 (string)hello worldhello world-hello world

    // $d; // 不接受未初始化的变量, 会报错

    // 布尔型变量
    $e = true; // 默认 强类型 bool,类似 c 的 bool
    var_dump($e); // 输出 (bool) true

    // 空型变量
    $f = null; // 默认 强类型 null,类似 c 的 NULL
    var_dump($f); // 输出 (null)

    // 回调型变量
    $g = function (int $a, int $b): int { // 必须是php 强类型声明
        return $a + $b;
    };
    var_dump($g(1, 2)); // 输出 (int)3
}
