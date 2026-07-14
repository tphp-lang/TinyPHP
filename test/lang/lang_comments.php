<?php
// 对应 PHP 原生 tests/lang/comments.phpt — #-style comments
// 原生在 <?php 前有 #teste 文本输出，TinyPHP 不支持，仅测试 PHP 内注释
#debug #ola
#debug uhm # ah
#debug e este, # hein?

class Main {
    public function main(): void {
        // line comment with //
        # hash comment with #
        echo '#ola'; //?
        echo "\n";
        echo 'uhm # ah'; #ah?
        echo "\n";
        echo "e este, # hein?";
        echo "\n";
    }
}
