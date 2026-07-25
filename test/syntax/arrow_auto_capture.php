<?php
#debug === Arrow Function Auto-Capture Tests ===
#debug
#debug [1] int capture:
#debug int(15)
#debug
#debug [2] string capture:
#debug string(11) "Hello World"
#debug
#debug [3] multiple captures:
#debug string(3) "yes"
#debug
#debug [4] captured value copy isolation:
#debug int(100)
#debug int(100)
#debug
#debug [5] arrow reused:
#debug int(11)
#debug int(21)
#debug
#debug [6] nested arrow capture:
#debug int(43)
#debug
#debug [7] capture in block body:
#debug int(30)
#debug
#debug [8] this auto-capture:
#debug int(42)
#debug
#debug [9] arrow without capture:
#debug int(10)
#debug
#debug [10] capture float:
#debug float(6.5)
#debug
#debug [11] multiple arrows, same capture:
#debug int(15)
#debug int(110)
#debug
#debug === All arrow auto-capture tests passed! ===

class Main {
    public int $value = 42;

    public function main(): void {
        echo "=== Arrow Function Auto-Capture Tests ===\n\n";

        // [1] int capture: arrow function auto-captures $base by value
        echo "[1] int capture:\n";
        $base = 10;
        $add = fn(int $x): int => $x + $base;
        var_dump($add(5));  // int(15)

        echo "\n";

        // [2] string capture
        echo "[2] string capture:\n";
        $greeting = "Hello";
        $make = fn(string $name): string => $greeting . " " . $name;
        var_dump($make("World"));  // string(11) "Hello World"

        echo "\n";

        // [3] multiple captures: two outer variables auto-captured
        echo "[3] multiple captures:\n";
        $threshold = 10;
        $label = "yes";
        $check = fn(int $x): string => $x > $threshold ? $label : "no";
        var_dump($check(20));  // string(3) "yes"

        echo "\n";

        // [4] captured value copy isolation: modifying outer after capture doesn't affect arrow
        echo "[4] captured value copy isolation:\n";
        $count = 100;
        $snapshot = fn(): int => $count;
        var_dump($snapshot());  // int(100)
        $count = 101;  // modify outer
        var_dump($snapshot());  // int(100) — captured by value, unchanged

        echo "\n";

        // [5] arrow reused: same arrow called multiple times
        echo "[5] arrow reused:\n";
        $offset = 1;
        $inc = fn(int $x): int => $x + $offset;
        var_dump($inc(10));  // int(11)
        var_dump($inc(20));  // int(21)

        echo "\n";

        // [6] nested arrow capture: inner arrow captures from outer arrow's scope
        echo "[6] nested arrow capture:\n";
        $factor = 2;
        $outer = fn(int $x): int => {
            $multiplier = 10;
            // inner arrow captures both $factor (from main) and $multiplier (from outer arrow)
            $inner = fn(int $y): int => $y * $factor + $multiplier;
            return $inner($x) + $x;
        };
        // inner(11) = 11 * 2 + 10 = 32; 32 + 11 = 43
        var_dump($outer(11));  // int(43)

        echo "\n";

        // [7] capture in block body: block-body arrow captures outer variable
        echo "[7] capture in block body:\n";
        $base2 = 30;
        $compute = fn(int $x): int => {
            return $x + $base2;
        };
        var_dump($compute(0));  // int(30)

        echo "\n";

        // [8] this auto-capture: arrow function in method captures $this
        echo "[8] this auto-capture:\n";
        $getter = fn(): int => $this->value;
        var_dump($getter());  // int(42)

        echo "\n";

        // [9] arrow without capture: pure parameter-only arrow
        echo "[9] arrow without capture:\n";
        $twice = fn(int $x): int => $x * 2;
        var_dump($twice(5));  // int(10)

        echo "\n";

        // [10] capture float
        echo "[10] capture float:\n";
        $half = 0.5;
        $bump = fn(float $x): float => $x + $half;
        var_dump($bump(6.0));  // float(6.5)

        echo "\n";

        // [11] multiple arrows, same capture: two arrows capture same outer variable
        echo "[11] multiple arrows, same capture:\n";
        $shared = 10;
        $f1 = fn(int $x): int => $x + $shared;
        $f2 = fn(int $x): int => $x * $shared;
        var_dump($f1(5));   // int(15)
        var_dump($f2(11));  // int(110)

        echo "\n";
        echo "=== All arrow auto-capture tests passed! ===\n";
    }
}
