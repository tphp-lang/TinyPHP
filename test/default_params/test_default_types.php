<?php
#debug 42
#debug 3.14
#debug hello world
#debug 1
#debug 100
#debug 40
#debug 100
#debug 25
#debug -5

// 测试 int 默认值
function add_int(int $a, int $b = 10): int {
    return $a + $b;
}

// 测试 float 默认值
function add_float(float $a, float $b = 1.5): float {
    return $a + $b;
}

// 测试 string 默认值
function greet(string $name, string $prefix = "hello"): string {
    return $prefix . " " . $name;
}

// 测试 bool 默认值 (简化为返回 int)
function check_bool(int $n, bool $flag = true): int {
    if ($flag) {
        return $n;
    }
    return 0;
}

// 测试多个默认值
function calc(int $a, int $b = 5, int $c = 10, int $d = 15): int {
    return $a + $b + $c + $d;
}

// 测试负数默认值
function subtract(int $a, int $b = 10): int {
    return $a - $b;
}

class Main {
    public function main(): void {
        // 测试1: int 默认值
        echo add_int(32) . "\n";  // 32 + 10 = 42

        // 测试2: float 默认值
        echo add_float(1.64) . "\n";  // 1.64 + 1.5 = 3.14

        // 测试3: string 默认值
        echo greet("world") . "\n";  // hello world

        // 测试4: bool 默认值
        echo check_bool(1) . "\n";  // 1 (flag=true, returns n)

        // 测试5: 多个默认值 (全部使用)
        echo calc(70) . "\n";  // 70 + 5 + 10 + 15 = 100

        // 测试6: 多个默认值 (部分覆盖)
        echo calc(5, 10) . "\n";  // 5 + 10 + 10 + 15 = 40

        // 测试7: 多个默认值 (全覆盖)
        echo calc(10, 20, 30, 40) . "\n";  // 10 + 20 + 30 + 40 = 100

        // 测试8: 覆盖默认值
        echo add_int(0, 25) . "\n";  // 0 + 25 = 25

        // 测试9: 负数默认值
        echo subtract(5) . "\n";  // 5 - 10 = -5
    }
}
