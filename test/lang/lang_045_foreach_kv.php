<?php
// 对应 PHP 原生 tests/lang/foreachLoop.001.phpt — foreach as $k => $v
// 选取基本 value 遍历与 key => value 遍历部分；过滤 &$v 引用、current() 等
#debug string(1) "a"
#debug string(1) "b"
#debug string(1) "c"
#debug int(0)
#debug string(1) "a"
#debug int(1)
#debug string(1) "b"
#debug int(2)
#debug string(1) "c"
#debug red
#debug green
#debug blue
#debug 0=red
#debug 1=green
#debug 2=blue

class Main {
    public function main(): void {
        // 纯 value 遍历
        $a = ["a", "b", "c"];
        foreach ($a as $v) {
            var_dump($v);
        }

        // key => value 遍历
        foreach ($a as $k => $v) {
            var_dump($k);
            var_dump($v);
        }

        // 字符串数组遍历并输出
        $colors = ["red", "green", "blue"];
        foreach ($colors as $color) {
            echo $color . "\n";
        }

        // key => value 输出
        foreach ($colors as $i => $color) {
            echo "$i=$color\n";
        }
    }
}
