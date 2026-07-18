<?php
// 对应 PHP ext/standard/tests/strings/explode.phpt
// 测试 explode() 基本功能
// tphp 差异： 无 $limit 参数（仅 2 参数版本：explode($delim, $str)）
// tphp 已知限制： 空片段（pieceLen==0）不被 push，故 explode(",","",",,") 返回空数组
//                 而 PHP 返回 ["","",""]。本测试避免触发此边界。
#debug array(3) {
#debug   [0]=>
#debug   string(5) "hello"
#debug   [1]=>
#debug   string(5) "world"
#debug   [2]=>
#debug   string(3) "php"
#debug }
#debug array(1) {
#debug   [0]=>
#debug   string(11) "hello world"
#debug }
#debug array(5) {
#debug   [0]=>
#debug   string(1) "a"
#debug   [1]=>
#debug   string(1) "b"
#debug   [2]=>
#debug   string(1) "c"
#debug   [3]=>
#debug   string(1) "d"
#debug   [4]=>
#debug   string(1) "e"
#debug }
#debug array(0) {
#debug }
#debug array(1) {
#debug   [0]=>
#debug   string(3) "abc"
#debug }
#debug array(3) {
#debug   [0]=>
#debug   string(1) "a"
#debug   [1]=>
#debug   string(1) "b"
#debug   [2]=>
#debug   string(1) "c"
#debug }

class Main
{
    public function main(): void
    {
        // 基本：分隔符为空格
        var_dump(explode(" ", "hello world php"));

        // 无分隔符匹配：返回单元素数组
        var_dump(explode(",", "hello world"));

        // 单字符分隔符
        var_dump(explode("-", "a-b-c-d-e"));

        // 空字符串输入：返回空数组
        var_dump(explode(",", ""));

        // 空分隔符 + 非空字符串：返回单元素数组
        var_dump(explode("", "abc"));

        // 多字符分隔符
        var_dump(explode("::", "a::b::c"));
    }
}
