<?php
// @skip tphp 的 list/[] 解构不支持整数字面量键名（仅支持字符串键名 "k" => $v，Parser.php::parseListVars 仅检查 STRING_LIT），待 Task 8 修复
// 对应 PHP ext/standard/tests/array/005.phpt 中 list(1 => $b, 0 => $a) = [1, 2] 用法
// list(1 => $b, 0 => $a) = [1, 2]: 通过整数键指定解构顺序

#debug int(1)
#debug int(2)

class Main
{
    public function main(): void
    {
        // 期望行为（PHP 原生）：
        //   list(1 => $b, 0 => $a) = [1, 2];
        //   // $a = 1 (key 0), $b = 2 (key 1) — 顺序与位置无关，按键匹配
        //   var_dump($a);   // int(1)
        //   var_dump($b);   // int(2)
        //
        // 当前 tphp 的 list 解构仅支持字符串字面量键名：
        //   src/Parser.php::parseListVars 中仅检查 STRING_LIT => $var
        //   整数字面量键 (1 => $b) 会触发 "Expected =>" 解析错误
        // 一旦 Parser 增加对 INT_LIT 键的支持，可移除 @skip 启用本测试。

        // 字符串键解构（已支持，作为对照）
        $arr = ["x" => 1, "y" => 2];
        ["x" => $a, "y" => $b] = $arr;
        var_dump($a);   // int(1)
        var_dump($b);   // int(2)
    }
}
