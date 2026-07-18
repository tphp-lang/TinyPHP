<?php
// 对应 PHP ext/standard/tests/array/count_recursive.phpt
// count($arr, COUNT_RECURSIVE): 递归计数多维数组

#debug int(2)
#debug int(2)
#debug int(8)

class Main
{
    public function main(): void
    {
        $arr = [1, [3, 4, [6, [8]]]];
        var_dump(count($arr));                 // int(2) — 仅顶层
        var_dump(count($arr, 0));              // int(2) — COUNT_NORMAL
        var_dump(count($arr, 1));              // int(8) — COUNT_RECURSIVE
    }
}
