<?php

#debug Hello, World!
#debug This is a heredoc.
#debug 
#debug ---
#debug Welcome to TinyPHP v1
#debug Line two with TinyPHP again.
#debug 
#debug ---
#debug Value is 100 dollars.
#debug 
#debug ---
#debug Box value: 42
#debug Braced: 42
#debug 
#debug ---
#debug Tab	here
#debug Newline
#debug here
#debug Backslash\here
#debug Dollar$here
#debug 
#debug ---
#debug No interpolation: $name \$name
#debug Literal backslash \n \t
#debug 
#debug ---
#debug Direct heredoc with TinyPHP
#debug 
#debug ---
#debug empty_len=0
#debug ---
#debug only one line
#debug 
#debug ---
#debug Braces { } and arrows -> not interpolated
#debug HTML <tag> and "quotes" and 'apostrophe'
#debug 
// Heredoc / Nowdoc 完整测试

class Box
{
    public int $value = 42;
}

class Main
{
    public function main(): void
    {
        // 1. 基本heredoc — 纯文本
        $a = <<<TEXT
Hello, World!
This is a heredoc.
TEXT;
        echo $a;
        echo "\n---\n";

        // 2. heredoc 带变量插值
        $name = "TinyPHP";
        $version = 1;
        $b = <<<MSG
Welcome to $name v$version
Line two with $name again.
MSG;
        echo $b;
        echo "\n---\n";

        // 3. heredoc 带 {$var} 花括号插值
        $x = 100;
        $c = <<<CALC
Value is {$x} dollars.
CALC;
        echo $c;
        echo "\n---\n";

        // 4. heredoc 带对象属性插值
        $box = new Box();
        $d = <<<OBJ
Box value: $box->value
Braced: {$box->value}
OBJ;
        echo $d;
        echo "\n---\n";

        // 5. heredoc 带转义序列
        $e = <<<ESC
Tab\there
Newline\nhere
Backslash\\here
Dollar\$here
ESC;
        echo $e;
        echo "\n---\n";

        // 6. nowdoc — 无插值
        $raw = <<<'NOW'
No interpolation: $name \$name
Literal backslash \n \t
NOW;
        echo $raw;
        echo "\n---\n";

        // 7. heredoc 直接 echo
        echo <<<DIRECT
Direct heredoc with $name
DIRECT;
        echo "\n---\n";

        // 8. heredoc 空内容
        $empty = <<<EMPTY
EMPTY;
        echo "empty_len=" . strlen($empty);
        echo "\n---\n";

        // 9. heredoc 单行内容
        $single = <<<SINGLE
only one line
SINGLE;
        echo $single;
        echo "\n---\n";

        // 10. heredoc 带特殊字符
        $special = <<<SPEC
Braces { } and arrows -> not interpolated
HTML <tag> and "quotes" and 'apostrophe'
SPEC;
        echo $special;
        echo "\n";
    }
}
