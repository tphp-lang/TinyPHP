<?php

class Widget
{
    public string $label;

    public function __construct(string $label)
    {
        $this->label = $label;
    }
}

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. is_object 静态类型 — 编译期 false（全部基本类型）
        // ============================================================
        echo "===== 1. primitives => false =====\n";

        $i = 42;
        $f = 3.14;
        $s = "hello";
        $b = true;
        $arr = [1, 2];
        $n = null;

        var_dump(is_object($i));       // bool(false)
        var_dump(is_object($f));       // bool(false)
        var_dump(is_object($s));       // bool(false)
        var_dump(is_object($b));       // bool(false)
        var_dump(is_object($arr));     // bool(false)
        var_dump(is_object($n));       // bool(false)

        // ============================================================
        // 2. is_object 静态类型 — 编译期 true（对象实例）
        // ============================================================
        echo "\n===== 2. object => true =====\n";

        $w = new Widget("test");
        var_dump(is_object($w));       // bool(true)

        $w2 = new Widget("alpha");
        var_dump(is_object($w2));      // bool(true)

        // ============================================================
        // 3. is_object 在 if / else 条件中
        // ============================================================
        echo "\n===== 3. in if/else =====\n";

        if (is_object($w)) {
            echo "w is object\n";
        } else {
            echo "w is NOT object\n";
        }

        if (is_object($i)) {
            echo "i is object\n";
        } else {
            echo "i is NOT object\n";
        }

        // ============================================================
        // 4. is_object 取反（!）
        // ============================================================
        echo "\n===== 4. negation (!) =====\n";

        if (!is_object($i)) {
            echo "i is not object (neg)\n";
        }

        if (!is_object($w)) {
            echo "w is not object (neg)\n";
        } else {
            echo "w IS object (neg)\n";
        }

        // ============================================================
        // 5. is_object 组合表达式 && / ||
        // ============================================================
        echo "\n===== 5. compound =====\n";

        // 两个都是对象 → true
        var_dump(is_object($w) && is_object($w2));
        // 一个是对象一个不是 → false
        var_dump(is_object($w) && is_object($i));
        // || 运算
        var_dump(is_object($i) || is_object($w));
        // !两边取反后 &&
        var_dump(!is_object($i) && !is_object($s));
        // 嵌套 var_dump 中组合
        var_dump(is_object($w2));

        // ============================================================
        // 6. is_object 在 match 中（编译期分支消除）
        // ============================================================
        echo "\n===== 6. in match =====\n";

        $result = match (true) {
            is_object($w) => "matched: object",
            is_object($i) => "matched: int (should NOT happen)",
            default       => "matched: default",
        };
        echo $result . "\n";

        // ============================================================
        // 7. is_object 与其他 is_* 交叉验证
        // ============================================================
        echo "\n===== 7. cross check =====\n";

        // 对象类型不是 int/string/array/bool/null
        var_dump(is_int($w));
        var_dump(is_string($w));
        var_dump(is_array($w));
        var_dump(is_bool($w));
        var_dump(is_null($w));
        // 基本类型不是 object
        var_dump(is_object($i));
        var_dump(is_object($s));
        var_dump(is_object($f));
        var_dump(is_object($arr));
        var_dump(is_object($b));
        var_dump(is_object($n));

        echo "\n=== all is_object tests done ===\n";
    }
}
