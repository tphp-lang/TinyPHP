<?php
// 对应 PHP ext/standard/tests/strings/str_repeat.phpt
// 测试 str_repeat() 基本功能
// tphp 差异： $times < 0 抛错；上限 0x3FFFFF；0 次返回空串
#debug string(0) ""
#debug string(1) "a"
#debug string(3) "aaa"
#debug string(0) ""
#debug string(3) "foo"
#debug string(9) "foofoofoo"
#debug string(0) ""
#debug string(0) ""

class Main
{
    public function main(): void
    {
        // 0 次 → 空串
        var_dump(str_repeat("a", 0));

        // 1 次
        var_dump(str_repeat("a", 1));

        // 多次
        var_dump(str_repeat("a", 3));

        // 多字符字符串： 0 次
        var_dump(str_repeat("foo", 0));

        // 多字符字符串： 1 次
        var_dump(str_repeat("foo", 1));

        // 多字符字符串： 3 次
        var_dump(str_repeat("foo", 3));

        // 空字符串 + 任意次数 → 空串
        var_dump(str_repeat("", 0));
        var_dump(str_repeat("", 5));
    }
}
