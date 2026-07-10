<?php
#debug int(111)
#debug int(606)
#debug int(1006)
#debug int(3)
#debug string(5) "world"
#debug string(11) "hello hello"
#debug string(11) "hello world"
#debug int(42)
#debug int(99)
#debug float(3.14)
#debug float(2.71)

// 独立函数 — 多默认参数 + 部分传参
function multi(int $a, int $b = 10, int $c = 100): int {
    return $a + $b + $c;
}

// 独立函数 — 数组默认值
function withArray(array $arr = [1, 2, 3]): int {
    return count($arr);
}

// 独立函数 — 字符串默认值
function withString(string $s = "world"): string {
    return $s;
}

// 独立函数 — 单个默认参数
function greet(int $a, int $b = 99): int {
    return $a + $b;
}

// 独立函数 — float 默认值
function withFloat(float $x = 3.14): float {
    return $x;
}

class Calc {
    // 方法 — 单个默认参数
    public function add(int $a, int $b = 99): int {
        return $a + $b;
    }
    // 方法 — 字符串默认参数
    public function greet(string $who = "world"): string {
        return "hello " . $who;
    }
}

class Main {
    public function main(): void {
        // 独立函数：只传 a → 1+10+100 = 111
        var_dump(multi(1));
        // 独立函数：全传 → 5+1+600 = 606
        var_dump(multi(5, 1, 600));
        // 独立函数：传 a, b → 6+900+100 = 1006
        var_dump(multi(6, 900));
        // 独立函数：数组默认值 → count = 3
        var_dump(withArray());
        // 独立函数：字符串默认值
        var_dump(withString());
        // 方法：传参覆盖默认值
        $c = new Calc();
        var_dump($c->greet("hello"));
        // 方法：使用默认值
        var_dump($c->greet());
        // 独立函数：传参覆盖默认值
        var_dump(greet(0, 42));
        // 独立函数：使用默认值
        var_dump(greet(0));
        // float 默认值
        var_dump(withFloat());
        // float 传参覆盖默认值
        var_dump(withFloat(2.71));
    }
}
