<?php

class Main
{
    public function main(): void
    {
        $name = "TinyPHP";
        $ver = "1.0";

        // 1. heredoc 插值
        $h1 = <<<EOD
Hello $name!
Welcome to heredoc.
EOD;
        var_dump($h1);

        // 2. \$name 不插值（字面量 $name）
        $h2 = <<<EOD
Literal: \$name
Interpolated: $name
EOD;
        var_dump($h2);

        // 3. nowdoc 不插值
        $n1 = <<<'NOW'
Hello $name!
\$name too.
No interpolation.
NOW;
        var_dump($n1);

        // 4. 多行 + {$var} 语法
        $h3 = <<<ML
Line 1
Line 2: version {$ver}
Line 3: name $name
ML;
        var_dump($h3);

        // 5. 转义序列 \n \t \\
        $h4 = <<<ESC
First line\nSecond line
Tab\there
Backslash: \\
Dollar sign: \$notavar
ESC;
        var_dump($h4);
    }
}
