<?php

class Main
{
    public function main(): void
    {
        echo "=============str=============\n";
        $this->str();
        echo "=============int=============\n";
        $this->int();
        echo "=============float=============\n";
        $this->float();
        echo "=============bool=============\n";
        $this->bool();
        echo "=============array=============\n";
        $this->array();
        echo "=============null=============\n";
        $this->null();
    }

    public function str(): void
    {
        // 数字转字符串
        $a = 123;

        echo (string)$a . "\n"; // "123"

        echo "" . $a . "\n"; // 同上 // 除不可转化为字符串的类型外，其他类型都可以直接拼接字符串来转化为字符串
        echo "$a" . "\n"; // 同上
        echo "{$a}" . "\n"; // 同上 

        $b = 123.45;
        echo (string)$b . "\n"; // "123.45"
        var_dump((string)$b . "\n");

        // 布尔值转字符串
        echo (string)true . "\n";   // "1"
        echo (string)false . "\n";  // ""（空字符串）

        // null转字符串
        echo (string)null . "\n";   // ""（空字符串）

        // 数组转字符串：报出错误，数组不能转换为字符串
        // echo (string)[1, 2] . "\n";  // "Array"

        // 对象转字符串：报出错误，对象不能转换为字符串
        // echo (string)new MyClass() . "\n";
    }

    public function int(): void
    {
        // 浮点数转整数：截断小数部分（非四舍五入）
        echo (int)3.999 . "\n";   // 3
        echo (int)-2.1 . "\n";    // -2

        // 字符串转整数：仅提取开头连续的数字部分
        echo (int)"123abc" . "\n"; // 123
        echo (int)"abc123" . "\n"; // 0（非数字开头）
        echo (int)"12.34" . "\n";  // 12（只取整数部分）
        echo (int)"-45" . "\n";    // -45

        // 布尔值转整数
        echo (int)true . "\n";     // 1
        echo (int)false . "\n";    // 0

        // null转整数
        echo (int)null . "\n";     // 0

        // 数组转整数
        echo (int)[] . "\n";       // 0（空数组）
        echo (int)[1, 2] . "\n";    // 1（非空数组）

        // 注意：对象无法转换为整数，会抛出TypeError
        // echo (int)new MyClass(); // 错误
    }

    public function float(): void
    {
        // 整数转浮点数
        echo (float)10 . "\n";     // 10

        // 字符串转浮点数
        echo (float)"123.45abc" . "\n"; // 123.45
        echo (float)"abc123.45" . "\n"; // 0
        echo (float)"1.2e3" . "\n";     // 1200（支持科学计数法）

        // 布尔值转浮点数
        echo (float)true . "\n";    // 1
        echo (float)false . "\n";   // 0

        // null转浮点数
        echo (float)null . "\n";    // 0

        // 数组转浮点数
        echo (float)[] . "\n";      // 0
        echo (float)[1, 2] . "\n";   // 1

        // 注意：对象无法转换为浮点数，会抛出TypeError
        // echo (float)new MyClass(); 
    }

    public function bool(): void
    {
        // 转换为false的情况
        var_dump((bool)0);      // bool(false)
        var_dump((bool)0.0);    // bool(false)
        var_dump((bool)"");     // bool(false)
        var_dump((bool)"0");    // bool(false)
        var_dump((bool)null);   // bool(false)
        var_dump((bool)[]);     // bool(false)

        // 转换为true的情况
        var_dump((bool)"0.0");  // bool(true)
        var_dump((bool)"false"); // bool(true)
        var_dump((bool)[0]);    // bool(true)
        var_dump((bool)new MyClass()); // bool(true)
        var_dump((bool)-1);     // bool(true)
    }

    public function array(): void
    {
        // 标量类型转数组：生成包含该值的单元素数组
        $intArr = (array)123;
        echo $intArr[0] . "\n";   // 123

        $strArr = (array)"hello";
        echo $strArr[0] . "\n";   // hello

        $boolArr = (array)true;
        echo $boolArr[0] . "\n";  // 1

        $nullArr = (array)null;
        echo count($nullArr) . "\n"; // 0（空数组）


    }
}

class MyClass {}
