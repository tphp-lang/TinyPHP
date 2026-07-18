<?php
// 对应 PHP ext/standard/tests/strings/strtolower.phpt
// 对应 PHP ext/standard/tests/strings/strtoupper1.phpt
// 测试 strtolower() / strtoupper() 基本功能
// tphp 差异： 仅 ASCII（A-Z/a-z），不支持 Unicode
#debug string(11) "hello world"
#debug string(11) "HELLO WORLD"
#debug string(6) "string"
#debug string(6) "STRING"
#debug string(8) "abc123xy"
#debug string(8) "ABC123XY"
#debug string(0) ""
#debug string(0) ""
#debug string(11) "hello world"

class Main
{
    public function main(): void
    {
        // 基本大小写转换
        var_dump(strtolower("Hello World"));
        var_dump(strtoupper("Hello World"));

        // 已是小写 / 已是大写 → 零分配原串返回
        var_dump(strtolower("string"));
        var_dump(strtoupper("STRING"));

        // 混合字母数字
        var_dump(strtolower("ABC123xy"));
        var_dump(strtoupper("abc123XY"));

        // 空字符串
        var_dump(strtolower(""));
        var_dump(strtoupper(""));

        // 数字与符号不变
        var_dump(strtolower("HELLO WORLD"));
    }
}
