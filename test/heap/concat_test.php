<?php

namespace Main;

function concat_vars(): void
{
    $a = "Hello, ";
    $b = "world!";
    $msg = $a . $b;      // 运行时拼接 → HeapAlloc; 退出时 HeapFree
    var_dump($msg);
}

function nested_heap(): void
{
    $prefix = "PREFIX-";
    $a = "A";
    $b = "B";
    $inner = $a . $b;    // 内层堆分配
    $outer = $prefix . $inner;  // 外层堆分配
    var_dump($outer);
}

function main(): void
{
    concat_vars();
    nested_heap();
}