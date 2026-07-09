<?php
#debug ===== int match =====
#debug string(3) "two"
#debug int(30)
#debug int(999)
#debug ===== string match =====
#debug string(7) "#00FF00"
#debug int(99)
#debug ===== match with or =====
#debug string(3) "low"
#debug string(4) "high"
#debug ===== match in expression =====
#debug int(60)
#debug match in if ok
#debug ===== match in closure =====
#debug string(3) "one"
#debug string(4) "many"
#debug string(11) "item-second"
#debug ===== match in function =====
#debug int(20)
#debug all match tests passed

class Main
{
    public function main(): void
    {
        echo "===== int match =====\n";
        $this->testIntMatch();

        echo "===== string match =====\n";
        $this->testStrMatch();

        echo "===== match with or =====\n";
        $this->testOrMatch();

        echo "===== match in expression =====\n";
        $this->testExprMatch();

        echo "===== match in closure =====\n";
        $this->testClosureMatch();

        echo "===== match in function =====\n";
        $this->testFuncMatch();

        echo "all match tests passed\n";
    }

    private function testIntMatch(): void
    {
        // 1. basic int match
        $r1 = match(2) {
            1 => "one",
            2 => "two",
            3 => "three",
            default => "other",
        };
        var_dump($r1);

        // 2. int match with int result
        $r2 = match(3) {
            1 => 10,
            2 => 20,
            3 => 30,
            default => 0,
        };
        var_dump($r2);

        // 3. int match - default fallback
        $r3 = match(99) {
            1 => 100,
            2 => 200,
            default => 999,
        };
        var_dump($r3);
    }

    private function testStrMatch(): void
    {
        // 4. string match
        $color = "green";
        $code = match($color) {
            "red" => "#FF0000",
            "green" => "#00FF00",
            "blue" => "#0000FF",
            default => "#000000",
        };
        var_dump($code);

        // 5. string match - default
        $r = match("purple") {
            "red" => 1,
            "green" => 2,
            default => 99,
        };
        var_dump($r);
    }

    private function testOrMatch(): void
    {
        // 6. match with multiple values (or)
        $r1 = match(2) {
            1, 2 => "low",
            3, 4 => "high",
            default => "mid",
        };
        var_dump($r1);

        // 7. match with multiple values - second group
        $r2 = match(4) {
            1, 2 => "low",
            3, 4 => "high",
            default => "mid",
        };
        var_dump($r2);
    }

    private function testExprMatch(): void
    {
        // 8. match used in arithmetic
        $val = match(3) {
            1 => 10,
            2 => 20,
            3 => 30,
            default => 0,
        };
        $doubleVal = $val * 2;
        var_dump($doubleVal);

        // 9. match used as if condition
        $result = match(2) {
            1 => 100,
            2 => 200,
            default => 0,
        };
        if ($result == 200) {
            echo "match in if ok\n";
        }
    }

    private function testClosureMatch(): void
    {
        // 10. match inside closure
        $fn = function(int $x): string {
            return match($x) {
                1 => "one",
                2 => "two",
                default => "many",
            };
        };
        var_dump($fn(1));
        var_dump($fn(3));

        // 11. closure with use + match
        $prefix = "item-";
        $labelFn = function(int $id) use ($prefix): string {
            return $prefix . match($id) {
                1 => "first",
                2 => "second",
                default => "other",
            };
        };
        var_dump($labelFn(2));
    }

    private function testFuncMatch(): void
    {
        // 12. nested match
        $outer = match(1) {
            1 => match("b") {
                "a" => 10,
                "b" => 20,
                default => 30,
            },
            default => 0,
        };
        var_dump($outer);
    }
}
