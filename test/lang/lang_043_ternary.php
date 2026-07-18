<?php
// 对应 PHP tests/lang/ — 三元运算符 ? :
// 无直接对应 .phpt（lang/ 目录无三元专项测试）
#debug yes
#debug no
#debug positive
#debug zero
#debug big

class Main {
    public function main(): void {
        // 基本三元
        $cond = true;
        $x = $cond ? "yes" : "no";
        echo $x . "\n";

        $cond2 = false;
        $y = $cond2 ? "yes" : "no";
        echo $y . "\n";

        // 数值比较
        $n = 5;
        $z = $n > 0 ? "positive" : "negative";
        echo $z . "\n";

        $m = 0;
        $w = $m == 0 ? "zero" : "nonzero";
        echo $w . "\n";

        // 嵌套三元（括号明确结合性）
        $val = 100;
        $r = $val > 50 ? "big" : ($val > 10 ? "medium" : "small");
        echo $r . "\n";
    }
}
