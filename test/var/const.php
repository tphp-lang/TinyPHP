<?php

namespace Main;

// 常量
// 常量必须在顶部定义，作用域在 namespace 内任意调用,而且常量的值不能被修改
const PI = 3.1415926;
const RED = "red";
// ... 以此类推

function main(): void
{

    // echo 和 print 都可以输出常量，echo只是不用写括号 括着内容;
    echo PI . "\n";
    echo RED . "\n";
}
