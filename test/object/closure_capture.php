<?php
#debug === Closure Capture Type Tests ===
#debug
#debug [1] int capture:
#debug int(15)
#debug [2] float capture:
#debug float(6)
#debug [3] string capture:
#debug string(11) "Hello World"
#debug [4] bool capture:
#debug int(14)
#debug [5] array capture:
#debug int(60)
#debug [6] null capture:
#debug int(999)
#debug [7] multiple captures:
#debug string(3) "yes"
#debug [8] captured value copy isolation:
#debug int(100)
#debug int(101)
#debug [9] closure reused:
#debug int(10)
#debug int(20)
#debug int(50)
#debug [10] multiple closures, same capture:
#debug int(15)
#debug int(50)
#debug [11] capture with conditional:
#debug int(20)
#debug [12] closure without capture:
#debug int(10)
#debug [13] unset closure with capture:
#debug int(50)
#debug   unset ok
#debug int(42)
#debug
#debug === All capture tests passed! ===

class Main {
    public function main(): void {
        echo "=== Closure Capture Type Tests ===\n\n";

        // ── Test 1: int capture ──
        echo "[1] int capture:\n";
        $base = 10;
        $fn1 = function (int $x) use ($base): int {
            return $x + $base;
        };
        var_dump($fn1(5));      // expected: int(15)

        // ── Test 2: float capture ──
        echo "[2] float capture:\n";
        $rate = 1.5;
        $fn2 = function (float $x) use ($rate): float {
            return $x * $rate;
        };
        var_dump($fn2(4.0));     // expected: float(6.0)

        // ── Test 3: string capture ──
        echo "[3] string capture:\n";
        $prefix = "Hello ";
        $fn3 = function (string $name) use ($prefix): string {
            return $prefix . $name;
        };
        var_dump($fn3("World")); // expected: string("Hello World")

        // ── Test 4: bool capture ──
        echo "[4] bool capture:\n";
        $flag = true;
        $fn4 = function (int $x) use ($flag): int {
            if ($flag) {
                return $x * 2;
            }
            return $x;
        };
        var_dump($fn4(7));      // expected: int(14)

        // ── Test 5: array capture ──
        echo "[5] array capture:\n";
        $data = [10, 20, 30];
        $fn5 = function () use ($data): int {
            $sum = 0;
            foreach ($data as $v) {
                $sum = $sum + $v;
            }
            return $sum;
        };
        var_dump($fn5());       // expected: int(60)

        // ── Test 6: null capture ──
        echo "[6] null capture:\n";
        $nil = null;
        $fn6 = function () use ($nil): int {
            if ($nil == null) {
                return 999;
            }
            return 0;
        };
        var_dump($fn6());       // expected: int(999)

        // ── Test 7: multiple captures (int + string + bool) ──
        echo "[7] multiple captures:\n";
        $a = 3;
        $b = "items";
        $c = true;
        $fn7 = function (int $count) use ($a, $b, $c): string {
            $total = $count * $a;
            if ($c) {
                return "yes";
            }
            return "no";
        };
        var_dump($fn7(4));      // expected: string("yes")

        // ── Test 8: closure modifying captured int (value copy, original unchanged) ──
        echo "[8] captured value copy isolation:\n";
        $counter = 100;
        $fn8 = function () use ($counter): int {
            $local = $counter + 1;
            return $local;
        };
        $result = $fn8();
        var_dump($counter);     // expected: int(100) — not modified
        var_dump($result);      // expected: int(101)

        // ── Test 9: closure used multiple times ──
        echo "[9] closure reused:\n";
        $mult = 10;
        $fn9 = function (int $x) use ($mult): int {
            return $x * $mult;
        };
        var_dump($fn9(1));      // expected: int(10)
        var_dump($fn9(2));      // expected: int(20)
        var_dump($fn9(5));      // expected: int(50)

        // ── Test 10: multiple closures with same capture ──
        echo "[10] multiple closures, same capture:\n";
        $offset = 5;
        $fn10a = function (int $x) use ($offset): int { return $x + $offset; };
        $fn10b = function (int $x) use ($offset): int { return $x * $offset; };
        var_dump($fn10a(10));  // expected: int(15)
        var_dump($fn10b(10));  // expected: int(50)

        // ── Test 11: capture with ternary result ──
        echo "[11] capture with conditional:\n";
        $mode = 1;
        $fn11 = function (int $x) use ($mode): int {
            $v = ($mode > 0) ? ($x * 2) : ($x * 3);
            return $v;
        };
        var_dump($fn11(10));     // expected: int(20)

        // ── Test 12: closure without capture (should still work) ──
        echo "[12] closure without capture:\n";
        $fn12 = function (int $a, int $b): int {
            return $a + $b;
        };
        var_dump($fn12(3, 7));   // expected: int(10)

        // ── Test 13: unset closure with capture (memory safe) ──
        echo "[13] unset closure with capture:\n";
        $val = 42;
        $fn13 = function (int $x) use ($val): int { return $x + $val; };
        var_dump($fn13(8));      // expected: int(50)
        unset($fn13);
        echo "  unset ok\n";
        var_dump($val);          // expected: int(42) — still alive

        echo "\n=== All capture tests passed! ===\n";
    }
}
