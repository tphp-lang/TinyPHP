<?php
// 对应 PHP ext/standard/tests/strings/str_split_basic.phpt
// 测试 str_split() 基本功能
// tphp 差异： $length 必填（无默认值 1）；< 1 抛错；空串返回空数组
#debug array(5) {
#debug   [0]=>
#debug   string(5) "This "
#debug   [1]=>
#debug   string(5) "is ba"
#debug   [2]=>
#debug   string(5) "sic t"
#debug   [3]=>
#debug   string(5) "estca"
#debug   [4]=>
#debug   string(2) "se"
#debug }
#debug array(22) {
#debug   [0]=>
#debug   string(1) "T"
#debug   [1]=>
#debug   string(1) "h"
#debug   [2]=>
#debug   string(1) "i"
#debug   [3]=>
#debug   string(1) "s"
#debug   [4]=>
#debug   string(1) " "
#debug   [5]=>
#debug   string(1) "i"
#debug   [6]=>
#debug   string(1) "s"
#debug   [7]=>
#debug   string(1) " "
#debug   [8]=>
#debug   string(1) "b"
#debug   [9]=>
#debug   string(1) "a"
#debug   [10]=>
#debug   string(1) "s"
#debug   [11]=>
#debug   string(1) "i"
#debug   [12]=>
#debug   string(1) "c"
#debug   [13]=>
#debug   string(1) " "
#debug   [14]=>
#debug   string(1) "t"
#debug   [15]=>
#debug   string(1) "e"
#debug   [16]=>
#debug   string(1) "s"
#debug   [17]=>
#debug   string(1) "t"
#debug   [18]=>
#debug   string(1) "c"
#debug   [19]=>
#debug   string(1) "a"
#debug   [20]=>
#debug   string(1) "s"
#debug   [21]=>
#debug   string(1) "e"
#debug }
#debug array(0) {
#debug }
#debug array(0) {
#debug }

class Main
{
    public function main(): void
    {
        // chunk_length=5
        var_dump(str_split("This is basic testcase", 5));

        // chunk_length=1（PHP 默认值，但 tphp 必填）
        var_dump(str_split("This is basic testcase", 1));

        // 空串 → 空数组
        var_dump(str_split("", 1));

        // 空串 + 任意长度 → 空数组
        var_dump(str_split("", 100));
    }
}
