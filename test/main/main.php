<?php // 开头必须是 <?php

namespace Main; // 入口命名空间必须是 Main

// 不接受任何游离代码

// 入口函数必须是 main, 且返回值必须是 void, 必须是php 强类型声明。 类似 c 语言的int main()
function main(): void
{
    var_dump('hello world'); // 输出 (string) hello world

    /**
     * print 是 php 的输出函数, 可以输出字符串, 浮点数, 整数, 布尔值等，但是不能直接输出对象和数组
     * 双引号支持变量替换和花括号{}, 单引号不支持
     */
    print("hello world\n"); // 输出 hello world
    $a = 123;
    print("$a\n"); // 输出 123
    $b = true;
    print("$b\n"); // 输出 1
    $c = 12.32;
    print("$c\n"); // 输出 12.32
    $arr = array("int", [123, 456]);
    // print $arr; // 报错 不能直接显示数组
    print($arr[0]); // 输出 123

    print("{$arr[1]}\n"); // 输出 456

    // ... 以此类推
}
