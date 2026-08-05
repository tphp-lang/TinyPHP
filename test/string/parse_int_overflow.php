<?php
#debug 9223372036854775807
#debug -9223372036854775808
#debug 123
#debug -456
// 测试超大整数解析（应返回 INT64_MAX/MIN，不回绕）

class Main
{
    public function main(): void
    {
        echo intval("99999999999999999999") . "\n";   // 9223372036854775807
        echo intval("-99999999999999999999") . "\n";  // -9223372036854775808
        echo intval("123") . "\n";                    // 123
        echo intval("-456") . "\n";                   // -456
    }
}
