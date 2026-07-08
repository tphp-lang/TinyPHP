<?php
// 表达式默认值测试（P1-6）：参数默认值与类属性默认值支持任意表达式
#debug 3
#debug 6
#debug 4
#debug 9
#debug ab
#debug 255
#debug 17
#debug 30
#debug 50
#debug 11

// 函数参数：算术表达式
function add_expr(int $a, int $b = 1 + 2): int {
    return $a + $b;
}

// 函数参数：乘除
function mul_expr(int $a, int $b = 2 * 3): int {
    return $a + $b;
}

// 函数参数：混合优先级
function mix_expr(int $a, int $b = 10 - 3 * 2): int {
    return $a + $b;
}

// 函数参数：括号
function paren_expr(int $a, int $b = (1 + 2) * 3): int {
    return $a + $b;
}

// 函数参数：字符串拼接
function str_expr(string $s, string $prefix = "a" . "b"): string {
    return $prefix . $s;
}

// 函数参数：位运算
function bit_expr(int $a, int $b = 0xFF | 0x10): int {
    return $a + $b;
}

// 函数参数：负数字面量与表达式
function neg_expr(int $a, int $b = -8 - 2): int {
    return $a + $b + 27;
}

class Counter {
    // 类属性：表达式默认值
    public int $step = 5 * 6;
    public int $base = 1 + 2 + 3;
    public int $total = 0;

    // 方法参数：表达式默认值
    public function inc(int $n, int $delta = 1 + 10): int {
        return $n + $delta;
    }
}

class Main {
    public function main(): void {
        // 测试1: 算术表达式默认值 1 + (1+2) = 3
        echo add_expr(0) . "\n";

        // 测试2: 乘法默认值 0 + (2*3) = 6
        echo mul_expr(0) . "\n";

        // 测试3: 混合优先级 0 + (10-3*2) = 4
        echo mix_expr(0) . "\n";

        // 测试4: 括号 0 + ((1+2)*3) = 9
        echo paren_expr(0) . "\n";

        // 测试5: 字符串拼接 "ab" . ""
        echo str_expr("") . "\n";

        // 测试6: 位运算 0 + (0xFF | 0x10) = 255
        echo bit_expr(0) . "\n";

        // 测试7: 负数表达式 0 + (-8-2) + 27 = 17
        echo neg_expr(0) . "\n";

        // 测试8: 类属性表达式默认值 step = 5*6 = 30
        $c = new Counter();
        echo $c->step . "\n";

        // 测试9: 类属性表达式默认值 base = 1+2+3 = 6, 50 - 6 = 44? 
        // 实际：base = 6, 但我们输出 step + base + (main 给的) = 30 + 6 + 14 = 50
        echo ($c->step + $c->base + 14) . "\n";

        // 测试10: 方法参数表达式默认值 0 + (1+10) = 11
        echo $c->inc(0) . "\n";
    }
}
