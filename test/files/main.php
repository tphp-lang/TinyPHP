<?php

// 多文件编译
// 入口文件必须是 main.php
// 而且必须是 Main 命名空间
// 和必须有 main 入口函数

// 编译指令: 
// php tphp.php main.php demo.php ...php文件
// 或者
// php tphp.php . // 编译当前目录下所有php文件包括所有子目录，但是当前目录的跟目录必须有main.php入口文件
// 当然这里 -o 默认是入口文件的名称

namespace Main;

// 引入命名空间，和PHP写法一样，没有引入的不能用里面的函数和类等
use Demo\MyDemo; // 引入 class类
use function MyAdmin\Name\getAge; // 引入函数
use function Demo\myDemo; // 引入函数
use function Other\otherFn; // 引入函数
use function Other\other2Fn; // 引入函数2

function main(): void
{
    all_hello();
    // 调用Demo命名空间下的myDemo函数
    myDemo(); // 输出 (string) hello function myDemo

    // 调用MyAdmin\Name命名空间下的getAge函数
    $age = getAge(10.5);
    var_dump($age);  // 输出 (float) 21.0

    $myClass = new MyDemo(); // 输出 (string) hello class MyDemo

    $myClass->hello();
    // 输出 
    // (string) hello class MyDemo hello2
    // (string) hello class MyDemo hello

    // 这里没有再使用$myClass，所以会自动调用__destruct函数
    // 输出 (string) hello class MyDemo __destruct

    // 调用其他命名空间下的函数
    otherFn(); // 输出 其他可用函数
    // 这里我注释了引入 function Other\other2Fn，所以会报错
    other2Fn(); // 输出 其他可用函数2

    var_dump('hello world');
}


/**
 * Main 命名空间下的函数只在同命名空间内可用，不会被其他命名空间直接访问。
 * 全局可用函数应放在无 namespace 声明的文件中。
 */
function allFn(): void
{
    echo "相同命名空间函数\n";
}
