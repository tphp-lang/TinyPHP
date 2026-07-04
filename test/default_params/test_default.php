<?php
#debug 15
#debug 20
#debug 30

function add(int $a, int $b = 10): int {
    return $a + $b;
}

class Main {
    public function main(): void {
        // 测试1: 使用默认值
        echo add(5) . "\n";  // 5 + 10 = 15

        // 测试2: 覆盖默认值
        echo add(5, 15) . "\n";  // 5 + 15 = 20

        // 测试3: 多个默认值
        echo multiply(2, 3, 5) . "\n";  // 2 * 3 * 5 = 30
    }
}

function multiply(int $a, int $b = 2, int $c = 3): int {
    return $a * $b * $c;
}
