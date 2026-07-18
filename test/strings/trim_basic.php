<?php
// 对应 PHP ext/standard/tests/strings/trim.phpt
// 对应 PHP ext/standard/tests/strings/ltrim_basic.phpt
// 对应 PHP ext/standard/tests/strings/rtrim_basic.phpt
// 测试 trim/ltrim/rtrim 基本功能
// tphp 差异： 仅默认空白（<= ' '），无 $characters 自定义参数
#debug string(5) "hello"
#debug string(5) "hello"
#debug string(5) "hello"
#debug string(0) ""
#debug string(0) ""
#debug string(7) "hello  "
#debug string(7) "  world"
#debug string(5) "hello"
#debug string(5) "world"

class Main
{
    public function main(): void
    {
        // trim：去除两端空白
        var_dump(trim("  hello  "));
        var_dump(trim("hello   "));
        var_dump(trim("   hello"));

        // 全空白字符串 → 空串
        var_dump(trim("   "));
        var_dump(trim(""));

        // ltrim：仅去除左端
        var_dump(ltrim("  hello  "));

        // rtrim：仅去除右端
        var_dump(rtrim("  world  "));

        // 制表符与换行符也算空白（<= ' '）
        var_dump(trim("\thello\n"));
        var_dump(trim("\nworld\t"));
    }
}
