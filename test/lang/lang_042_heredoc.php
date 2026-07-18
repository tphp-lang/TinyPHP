<?php
// 对应 PHP tests/lang/ — heredoc / nowdoc 语法
// 无直接对应 .phpt（lang/ 目录无 heredoc 专项测试）
#debug === heredoc ===
#debug Hello, World!
#debug === nowdoc ===
#debug Literal $name here

class Main {
    public function main(): void {
        $name = "World";

        // heredoc 带变量插值（body 含尾换行）
        echo "=== heredoc ===\n";
        $s = <<<EOT
Hello, $name!
EOT;
        echo $s;

        // nowdoc 无插值（单引号标识符）
        echo "=== nowdoc ===\n";
        $n = <<<'EOT'
Literal $name here
EOT;
        echo $n;
    }
}
