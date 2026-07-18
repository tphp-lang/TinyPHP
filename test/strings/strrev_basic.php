<?php
// 对应 PHP ext/standard/tests/strings/strrev_basic.phpt
// 测试 strrev() 基本功能
#debug string(12) "dlroW ,olleH"
#debug string(1) "H"
#debug string(6) "HHHHHH"
#debug string(6) "HhhhhH"
#debug string(0) ""

class Main
{
    public function main(): void
    {
        // 普通字符串
        var_dump(strrev("Hello, World"));

        // 单字符
        var_dump(strrev("H"));

        // 相同字符
        var_dump(strrev("HHHHHH"));

        // 部分相同
        var_dump(strrev("HhhhhH"));

        // 空字符串
        var_dump(strrev(""));
    }
}
