<?php
// 对应 PHP ext/standard/tests/strings/str_pad.phpt
// 测试 str_pad() 基本功能：左/右/两侧填充 + 自定义填充字符串
// tphp 差异： 4 参数必填（无默认值）； pad_type: 0=RIGHT, 1=LEFT, 2=BOTH
// STR_PAD_RIGHT=0, STR_PAD_LEFT=1, STR_PAD_BOTH=2（PHP 常量值）
#debug string(20) "str_pad()           "
#debug string(20) "str_pad()-+-+-+-+-+-"
#debug string(20) "-+-+-+-+-+-str_pad()"
#debug string(20) "str_pad()-+-+-+-+-+-"
#debug string(20) "-+-+-str_pad()-+-+-+"
#debug string(9) "variation"
#debug string(16) "variation       "
#debug string(16) "=======variation"
#debug string(16) "variation======="
#debug string(16) "===variation===="
#debug string(5) "ab123"
#debug string(5) "123ab"
#debug string(5) "1ab12"

class Main
{
    public function main(): void
    {
        // 基本操作（参考 str_pad.phpt）
        // 默认空格 + STR_PAD_RIGHT(0)
        var_dump(str_pad("str_pad()", 20, " ", 0));

        // 自定义填充 + STR_PAD_RIGHT
        var_dump(str_pad("str_pad()", 20, "-+", 0));

        // 自定义填充 + STR_PAD_LEFT(1)
        var_dump(str_pad("str_pad()", 20, "-+", 1));

        // 自定义填充 + STR_PAD_RIGHT(0)
        var_dump(str_pad("str_pad()", 20, "-+", 0));

        // 自定义填充 + STR_PAD_BOTH(2)
        var_dump(str_pad("str_pad()", 20, "-+", 2));

        // 长度小于字符串 → 原串返回
        var_dump(str_pad("variation", 9, "=", 0));

        // 单字符填充
        var_dump(str_pad("variation", 16, " ", 0));
        var_dump(str_pad("variation", 16, "=", 1));
        var_dump(str_pad("variation", 16, "=", 0));
        var_dump(str_pad("variation", 16, "=", 2));

        // 自定义多字符填充（取模循环）
        var_dump(str_pad("ab", 5, "123", 0));
        var_dump(str_pad("ab", 5, "123", 1));
        var_dump(str_pad("ab", 5, "123", 2));
    }
}
