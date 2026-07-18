<?php
// 对应 PHP tests/func/002.phpt — pass by reference (&$param)
#debug int(15)
#debug string(8) "modified"
#debug int(30)
#debug int(20)
#debug swap: 200 100

// 单个 int 引用: 修改调用者变量
function increment(int &$x): void {
    $x = $x + 5;
}

// string 引用: 直接赋值新字符串
// 注: tphp 存在 bug — 对 by-ref string 参数做 $s = $s . "x" 拼接时,
// $s 会被当作对象输出 "Object", 故此处改用直接赋值
function setStr(string &$s): void {
    $s = "modified";
}

// 多个引用参数: 同时修改
function addBoth(int &$a, int &$b): void {
    $a = $a + 10;
    $b = $b + 10;
}

// 经典 swap
function swapInts(int &$a, int &$b): void {
    $t = $a;
    $a = $b;
    $b = $t;
}

class Main
{
    public function main(): void
    {
        // 1. int 引用修改
        $a = 10;
        increment($a);
        var_dump($a);            // int(15)

        // 2. string 引用直接赋值
        $s = "hello";
        setStr($s);
        var_dump($s);            // string(8) "modified"

        // 3. 多个引用参数
        $x = 20;
        $y = 10;
        addBoth($x, $y);
        var_dump($x);            // int(30)
        var_dump($y);            // int(20)

        // 4. swap via byRef
        $p = 100;
        $q = 200;
        swapInts($p, $q);
        echo "swap: " . $p . " " . $q . "\n";  // swap: 200 100
    }
}
