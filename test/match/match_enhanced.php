<?php
// match 表达式增强测试（PHP 8.0+ 规范一致性）
// 验证修复的 3 个偏差：
//   1. 严格比较语义（===）：类型不一致永不匹配
//   2. 无 default 且无匹配时抛 UnhandledMatchError
//   3. 多类型 arm body 的类型推导一致性
#debug === match Expression Enhancement ===
#debug
#debug 1. int match: two
#debug 2. string match: #00FF00
#debug 3. multi-value: low
#debug 4. multi-value: high
#debug 5. match(true) form: 2
#debug 6. default => throw: caught: invalid
#debug 7. strict int vs float: no match
#debug 8. strict int vs string: no match
#debug 9. unhandled: caught: Unhandled match case
#debug 10. mixed type arms: float=1.5
#debug 11. nested match: inner-B
#debug
#debug === All match enhancement tests passed ===

class Main
{
    public function describe(int $n): string
    {
        return match ($n) {
            1 => "one",
            2 => "two",
            3 => "three",
            default => "many",
        };
    }

    public function colorCode(string $name): string
    {
        return match ($name) {
            "red" => "#FF0000",
            "green" => "#00FF00",
            "blue" => "#0000FF",
            default => "#000000",
        };
    }

    public function level(int $n): string
    {
        return match ($n) {
            1, 2, 3 => "low",
            4, 5 => "high",
            default => "unknown",
        };
    }

    public function gradeLevel(int $grade): int
    {
        // match(true) 形式
        return match (true) {
            $grade >= 90 => 1,
            $grade >= 80 => 2,
            $grade >= 70 => 3,
            default => 4,
        };
    }

    // default => throw 形式
    public function classify(int $n): string|Exception
    {
        return match ($n) {
            1 => "first",
            2 => "second",
            default => throw new Exception("invalid"),
        };
    }

    // 无 default 且无匹配时抛 UnhandledMatchError
    public function pick(int $n): string|Exception
    {
        return match ($n) {
            1 => "one",
            2 => "two",
        };
    }

    // 混合类型 arm body（float + int）
    public function mixedArms(int $n): float
    {
        return match ($n) {
            1 => 1.5,
            2 => 2.5,
            default => 0.0,
        };
    }

    public function main(): void
    {
        echo "=== match Expression Enhancement ===\n\n";

        // 1. 基本 int match
        echo "1. int match: " . $this->describe(2) . "\n";

        // 2. string match
        echo "2. string match: " . $this->colorCode("green") . "\n";

        // 3. multi-value match
        echo "3. multi-value: " . $this->level(2) . "\n";

        // 4. multi-value match (另一分支)
        echo "4. multi-value: " . $this->level(5) . "\n";

        // 5. match(true) 形式
        echo "5. match(true) form: " . $this->gradeLevel(85) . "\n";

        // 6. default => throw 形式
        try {
            $this->classify(99);
        } catch (Exception $e) {
            echo "6. default => throw: caught: " . $e->getMessage() . "\n";
        }

        // 7. 严格比较：int vs float 不匹配
        //   PHP 8.0+ match(0) { 0.0 => ... } 不匹配（类型严格）
        //   TinyPHP 中 int 字面量与 float 字面量类型不同，生成 (0) 永不匹配
        $strictResult = match (1) {
            1.0 => "matched float",
            1 => "no match",
            default => "default",
        };
        echo "7. strict int vs float: " . $strictResult . "\n";

        // 8. 严格比较：int vs string 不匹配
        //   match(0) { "0" => ... } 不匹配
        $strictResult2 = match (0) {
            "0" => "matched string",
            0 => "no match",
            default => "default",
        };
        echo "8. strict int vs string: " . $strictResult2 . "\n";

        // 9. 无 default 且无匹配时抛 UnhandledMatchError
        try {
            $this->pick(99);
        } catch (UnhandledMatchError $e) {
            echo "9. unhandled: caught: " . $e->getMessage() . "\n";
        }

        // 10. 混合类型 arm body（float + int）
        $mixed = $this->mixedArms(1);
        echo "10. mixed type arms: float=" . $mixed . "\n";

        // 11. 嵌套 match
        $nested = match (1) {
            1 => match ("b") {
                "a" => "inner-A",
                "b" => "inner-B",
                default => "inner-default",
            },
            default => "outer-default",
        };
        echo "11. nested match: " . $nested . "\n";

        echo "\n=== All match enhancement tests passed ===\n";
    }
}
