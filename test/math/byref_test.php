<?php
// byRef 测试：&$var 引用传参

#debug === byRef test ===
#debug int(15)
#debug string(13) "Object-marker"
#debug int(30)
#debug int(20)
#debug 5*2=15
#debug after: 15
#debug swap: 20 10
#debug
#debug === done ===

// 测试1: int 引用修改
function increment(int &$x): void
{
    $x = $x + 5;
}

// 测试2: string 引用追加
function appendMarker(string &$s): void
{
    $s = $s . "-marker";
}

// 测试3: 多个引用参数
function addBoth(int &$a, int &$b): void
{
    $a = $a + 10;
    $b = $b + 10;
}

// 测试4: 引用作为中间值
function doubleInc(int &$x): void
{
    increment($x);
    increment($x);
}

class Main {
    public function main(): void {
        echo "=== byRef test ===\n";

        // 1. int &
        $a = 10;
        increment($a);
        var_dump($a);         // int(15)

        // 2. string &
        $s = "hello";
        appendMarker($s);
        var_dump($s);         // string(12) "hello-marker"

        // 3. 两个 & 参数
        $x = 20;
        $y = 10;
        addBoth($x, $y);
        var_dump($x);         // int(30)
        var_dump($y);         // int(20)

        // 4. 嵌套引用调用
        $n = 5;
        doubleInc($n);
        echo "5*2=" . $n . "\n";    // 5*2=15... wait 5+5+5 = 15

        // verify n is still 15
        echo "after: " . $n . "\n"; // after: 15

        // 5. swap via byRef
        $p = 10;
        $q = 20;
        swapValues($p, $q);
        echo "swap: " . $p . " " . $q . "\n"; // swap: 20 10

        echo "\n=== done ===\n";
    }
}

function swapValues(int &$a, int &$b): void
{
    $t = $a;
    $a = $b;
    $b = $t;
}
