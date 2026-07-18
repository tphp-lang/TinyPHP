<?php
// 对应 PHP ext/standard/tests/array/array_pad.phpt
// array_pad($arr, $size, $value): 将数组填充到指定长度

#debug int(5)
#debug int(5)
#debug int(2)
#debug int(3)

class Main
{
    public function main(): void
    {
        $a = [1, 2];
        $r1 = array_pad($a, 5, 0);
        var_dump(count($r1));   // int(5) — 右侧填充

        $r2 = array_pad($a, -5, 0);
        var_dump(count($r2));   // int(5) — 左侧填充

        $r3 = array_pad($a, 2, 0);
        var_dump(count($r3));   // int(2) — abs(size)<=length 原样返回

        $r4 = array_pad([1, 2, 3], 2, 0);
        var_dump(count($r4));   // int(3) — abs(size)<length 原样返回
    }
}
