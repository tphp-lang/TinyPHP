<?php
#debug 100
#debug 40
#debug 100
#debug -25
#debug -25

function calculate(int $a, int $b = 5, int $c = 10, int $d = 15): int {
    return $a + $b + $c + $d;
}

function subtract(int $a, int $b = 10, int $c = 20): int {
    return $a - $b - $c;
}

class Main {
    public function main(): void {
        // 测试1: 所有默认值
        echo calculate(70) . "\n";  // 70 + 5 + 10 + 15 = 100

        // 测试2: 部分覆盖
        echo calculate(5, 10, 10) . "\n";  // 5 + 10 + 10 + 15 = 40

        // 测试3: 全覆盖
        echo calculate(10, 20, 30, 40) . "\n";  // 10 + 20 + 30 + 40 = 100

        // 测试4: 负数默认值
        echo subtract(5) . "\n";  // 5 - 10 - 20 = -25

        // 测试5: 部分覆盖负数
        echo subtract(5, 10) . "\n";  // 5 - 10 - 20 = -25
    }
}
