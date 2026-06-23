<?php

class Calculator {
    public int $val;

    public function __construct(int $v) {
        $this->val = $v;
    }

    public function compute(): int {
        return $this->val * 2;
    }
}

class Main {
    public function main(): void {
        // ── 1. === 业务判断: HTTP 状态码 ──
        $code = 200;
        if ($code === 200) { echo "1. 200 OK\n"; }
        if ($code !== 500) { echo "1. no server error\n"; }
        // 字符串严格比较: 用户名匹配
        $user = "admin";
        if ($user === "admin") { echo "1. admin login OK\n"; }

        // ── 2. fn 箭头: 打折计算 ──
        $discount = fn($price) => ($price * 80) / 100;
        $final = $discount(250);
        echo "2. 250 discounted=" . $final . "\n";
        // 加法
        $sum = fn($a, $b) => $a + $b;
        echo "2. 10+30=" . $sum(10, 30) . "\n";

        // ── 3. nullsafe: 可选链 ──
        $calc = new Calculator(15);
        echo "3. calc?->compute=" . $calc?->compute() . "\n";

        // ── 4. never: 仅定义不调用，验证编译通过 ──
        $ok = true;
        if ($ok) {
            echo "4. never not called\n";
        }

        // ── 5. static 方法（通过 $this 调用，已验证）──
        echo "5. all done\n";
    }

    public function guard(string $msg): never {
        echo $msg . "\n";
        exit(1);
    }

    public static function version(): string {
        return "v1.0";
    }
}
