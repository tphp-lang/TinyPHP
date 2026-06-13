<?php

namespace Main;

// 函数定义
function hello(): void
{
    var_dump("Hello World!");
}

function main(): void
{
    $a = 1;
    $b = 2;
    $c = test($a, $b);
    var_dump($c);
    hello();
}

// 函数定义
function test(int $a, int $b): int
{
    return $a + $b;
}
