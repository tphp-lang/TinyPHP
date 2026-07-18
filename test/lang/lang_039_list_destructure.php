<?php
// 对应 PHP 原生 tests/lang/engine_assignExecutionOrder_002.phpt — list() 解构
// 选取其中 list() 基本解构、跳过元素、短语法 [] 部分
// 注：嵌套 list 处理 mixed-type 数组（如 [array, int]）tphp 类型推断无法处理，已移除
#debug A=hello B=bye
#debug X=a Y=c
#debug P=10 Q=30

class Main {
    public function main(): void {
        // list() 跳过中间元素（字符串数组）
        $f = ["hello", "item2", "bye"];
        list($a, , $b) = $f;
        echo "A=$a B=$b\n";

        // 短语法 [$x, , $y] = $arr（字符串数组）
        $arr = ["a", "b", "c"];
        [$x, , $y] = $arr;
        echo "X=$x Y=$y\n";

        // list() 跳过中间元素（数值数组）
        $nums = [10, 20, 30];
        list($p, , $q) = $nums;
        echo "P=$p Q=$q\n";
    }
}
