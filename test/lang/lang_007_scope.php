<?php
// 简化版 tests/lang/007 — 静态变量与全局变量
// TinyPHP 的 global 关键字导入全局作用域变量
#debug static call 1
#debug static call 2

function TestStatic(): void {
    static $count = 0;
    $count++;
    echo "static call $count\n";
}

class Main {
    public function main(): void {
        TestStatic();
        TestStatic();
    }
}
