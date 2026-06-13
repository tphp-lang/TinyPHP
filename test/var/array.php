<?php

namespace Main;

function main(): void
{
    // 数组类型
    // 这里的数组不用原生PHP的万能数组，因为不符合编译原理

    $a = array("int", [1, 2, 3]); // 表示强类型 int 数组
    $aa = array("int", []); // 表示强类型 int 数组

    var_dump($a); // 输出 (array(int)) [1, 2, 3] 
    var_dump($aa);  // 输出 (array(int)) [] 
    var_dump(count($a)); // 输出 (int) 3
    var_dump(count($aa)); // 输出 (int) 0
    var_dump($a[1]); // 输出 (int) 2

    // ... 字符串、布尔、float、null、callback 等类型 以此类推

    // 其中 callback 类型
    $b = array("callback", [
        function (int $a, int $b): int {
            return $a + $b;
        },
        function (string $a, string $b): string {
            return $a . $b;
        },
        function (): void {
            var_dump("hello world");
        }
    ]);
    var_dump($b);
    // 输出 
    // (array(callback)) 
    // [ 
    //     function (int $a, int $b): int, 
    //     function (string $a, string $b): string, 
    //     function (): void
    // ]
    var_dump($b[0](1, 2)); // 输出 (int) 3
    var_dump($b[1]("hello", "world")); // 输出 (string) helloworld
    $b[2](); // 输出 (string) hello world

    // 数组的追加
    $a[] = 4;
    var_dump($a); // 输出 (array(int)) [1, 2, 3, 4]

    // 数组的删除
    unset($a[1]);
    var_dump($a); // 输出 (array(int)) [1, 3, 4]

    // ...以此类推
    
}
