<?php
#debug hello world
#debug 3
#debug inner scope

class Main {
    public function main(): void {
        // 测试字符串自动释放
        $s1 = "hello";
        $s2 = " world";
        $result = $s1 . $s2;
        echo $result . "\n";

        // 测试数组自动释放
        $arr = [1, 2, 3];
        echo count($arr) . "\n";

        // 测试嵌套作用域
        if (true) {
            $inner = "inner scope";
            echo $inner . "\n";
        }
    }
}
