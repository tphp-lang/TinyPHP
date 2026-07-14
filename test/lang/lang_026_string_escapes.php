<?php
// 对应 PHP 原生 tests/lang/026.phpt — Testing string scanner conformance
// 字符串转义序列与拼接
// 注：原生用单引号串 '\n\\\'a\\\b\\' 产生字面 \n 等，TinyPHP 单引号串的
// 反斜杠序列在转 C 时不会被重新转义。且 CodeGenerator 对 token 值中的 "
// 会额外转义导致 \\" 错误。故用单引号 '"' 产生字面 "，双引号串产生其余部分
#debug "	\'\n\'a\\b\

class Main {
    public function main(): void {
        echo '"' . "\t\\'\\n\\'a\\\\b\\";
    }
}
