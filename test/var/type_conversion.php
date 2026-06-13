<?php

namespace Main;

function main(): void
{
    echo "int\n";
    typeInt();
    echo "int-end\n";
    echo "float\n";
    typeFloat();
    echo "float-end\n";
    echo "string\n";
    typeString();
    echo "string-end\n";
}

// 各个类型转换为int
function typeInt(): void
{
    // 浮点数转整数：截断小数部分（非四舍五入）
    var_dump((int)3.999);   // 3
    var_dump((int)-2.1);    // -2

    // 字符串转整数：仅提取开头连续的数字部分
    var_dump((int)"123abc"); // 123
    var_dump((int)"abc123"); // 0（非数字开头）
    var_dump((int)"12.34"); // 12（只取整数部分）
    var_dump((int)"-45");    // -45

    // 布尔值转整数
    var_dump((int)true); // 1
    var_dump((int)false); // 0

    // null转整数
    var_dump((int)null);     // 0

    // 数组转整数
    $kong = array("int", []);
    $nkong = array("int", [1, 2]);
    var_dump((int)$kong);       // 0（空数组）
    var_dump((int)$nkong);    // 1（非空数组）

    // 对象转整数：抛出编译错误
    // $obj = new FakeClass();
    // echo (int)$obj . "\n";  // Error: Object cannot be converted to int
}

// 各个类型转换为float
function typeFloat(): void
{
    //浮点数，默认是保留6位小数点的,但是输出的话是默认保留1位小数点

    // 整数转浮点数
    var_dump((float)10);     // 10.0

    // 字符串转浮点数
    var_dump((float)"123.45abc"); // 123.45
    var_dump((float)"abc123.45"); // 0.0 （非数字开头）
    var_dump((float)"1.2e3");     // 1200.0（支持科学计数法）

    // 布尔值转浮点数
    var_dump((float)true);    // 1.0
    var_dump((float)false);   // 0.0

    // null转浮点数
    var_dump((float)null);    // 0.0

    // 数组转浮点数
    $kong = array("int", []);
    $nkong = array("int", [1, 2]);
    var_dump((int)$kong);      // 0.0
    var_dump((int)$nkong);   // 1.0

    // 注意：对象无法转换为浮点数，会抛出TypeError
}


// 各个类型转换为string
function typeString(): void
{
    // 数字转字符串

    $a = 456;
    $b = "$a";
    var_dump($b); // "456"

    $c = 456.789;
    $d = "$c";
    var_dump($d); // "456.789"

    var_dump((string)123);    // "123"
    var_dump((string)123.45); // "123.45"

    // 布尔值转字符串
    var_dump((string)true);   // "1"
    var_dump((string)false);  // "0"

    // null转字符串
    var_dump((string)null);  // ""（空字符串）

    // 数组/对象转字符串：会报错，数组不能转换为字符串
    // echo (string)array("int", [1, 2]) . "\n";  // Error: Array cannot be converted to string
}
