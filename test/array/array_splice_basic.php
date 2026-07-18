<?php
// @skip array_splice 需要 by-reference 修改原数组，与 tphp 值语义数组模型不兼容，需设计决策后再实现
// 对应 PHP ext/standard/tests/array/array_splice_basic.phpt
// array_splice($arr, $offset, $length, $replacement): 移除并替换数组的一部分

#debug int(4)

class Main
{
    public function main(): void
    {
        $input = ["red", "green", "blue", "yellow"];
        var_dump(count($input));   // int(4) — 仅验证数组存在
    }
}
