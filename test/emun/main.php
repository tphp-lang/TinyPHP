<?php

namespace Main;

/* use MyEmun\IsMyEmun; // 引入类型
use MyEmun\MyInt; // 引入枚举
use MyEmun\MyStr; // 引入枚举 */

// 组 引入方式写法
use MyEmun\{IsMyEmun, MyInt, MyStr};

function main(): void
{
    echo "hello world\n";

    $isMyEmun = new IsMyEmun();
    echo $isMyEmun->isMyInt(MyInt::A) . "\n"; // 输出 1
    echo $isMyEmun->isMyStr(MyStr::A) . "\n"; // 输出 b
    var_dump(MyStr::A->value); // 输出 (string) a
}
