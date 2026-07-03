<?php
#debug === Advanced Control Flow Tests ===
#debug
#debug -- Fix 1: for loop scope lifting --
#debug [1a] for var after loop:
#debug int(5)
#debug [1b] for with float counter:
#debug float(3)
#debug [1c] nested for loops:
#debug int(2)
#debug int(3)
#debug [1d] for with pre-declared var:
#debug int(4)
#debug [1e] for loop array iteration:
#debug int(60)
#debug int(3)
#debug
#debug -- Fix 2: foreach string key support --
#debug [2a] foreach str keys:
#debug string(5) "Alice"
#debug string(7) "Beijing"
#debug [2b] foreach nested str keys:
#debug int(4)
#debug [2c] foreach value-only:
#debug int(600)
#debug [2d] foreach int keys:
#debug int(3)
#debug
#debug -- Fix 3: match without default --
#debug [3a] match with default:
#debug string(3) "two"
#debug [3b] match no default, covered:
#debug string(5) "first"
#debug [3c] match int result, no default:
#debug int(2)
#debug [3d] match bool result:
#debug bool(true)
#debug
#debug -- Switch tests --
#debug [4a] int switch:
#debug   two
#debug [4b] string switch:
#debug   green
#debug
#debug -- Combined: for + foreach --
#debug [5] for with foreach inside:
#debug int(21)
#debug
#debug === All control flow tests passed! ===

class Main {
    public function main(): void {
        echo "=== Advanced Control Flow Tests ===\n\n";

        // ═══════════════════════════════════════════
        // Fix 1: for loop scope lifting
        // ═══════════════════════════════════════════
        echo "-- Fix 1: for loop scope lifting --\n";

        // Test 1a: for loop variable accessible after loop
        echo "[1a] for var after loop:\n";
        for ($i = 0; $i < 5; $i++) {
            // just counting
        }
        var_dump($i); // expected: int(5) — 循环后仍可访问

        // Test 1b: for with float counter
        echo "[1b] for with float counter:\n";
        for ($f = 0.0; $f < 3.0; $f = $f + 1.0) {
            // just counting
        }
        var_dump($f); // expected: float(3.0)

        // Test 1c: nested for loops with different variables
        echo "[1c] nested for loops:\n";
        for ($row = 0; $row < 2; $row = $row + 1) {
            for ($col = 0; $col < 3; $col = $col + 1) {
                // nested loop
            }
        }
        var_dump($row); // expected: int(2)
        var_dump($col); // expected: int(3)

        // Test 1d: for loop with string init (already declared var)
        echo "[1d] for with pre-declared var:\n";
        $counter = 0;
        for ($counter = 1; $counter < 4; $counter = $counter + 1) {
            // reuse declared var
        }
        var_dump($counter); // expected: int(4)

        // Test 1e: for loop used for array iteration
        echo "[1e] for loop array iteration:\n";
        $arr = [10, 20, 30];
        $sum = 0;
        for ($idx = 0; $idx < count($arr); $idx = $idx + 1) {
            $sum = $sum + $arr[$idx];
        }
        var_dump($sum); // expected: int(60)
        var_dump($idx); // expected: int(3)

        // ═══════════════════════════════════════════
        // Fix 2: foreach string key support
        // ═══════════════════════════════════════════
        echo "\n-- Fix 2: foreach string key support --\n";

        // Test 2a: foreach with string keys
        echo "[2a] foreach str keys:\n";
        $map = ["name" => "Alice", "city" => "Beijing"];
        foreach ($map as $k => $v) {
            if ($k == "name") {
                var_dump($v); // expected: string("Alice")
            }
            if ($k == "city") {
                var_dump($v); // expected: string("Beijing")
            }
        }

        // Test 2b: foreach nested array with string keys
        echo "[2b] foreach nested str keys:\n";
        $catalog = [
        "a" => [1, 2],
        "b" => [3, 4],
        ];
        $found = 0;
        foreach ($catalog as $section => $items) {
            if ($section == "b") {
                $found = $items[1]; // 4
            }
        }
        var_dump($found); // expected: int(4)

        // Test 2c: foreach value-only (int-keyed array, should still work)
        echo "[2c] foreach value-only:\n";
        $nums = [100, 200, 300];
        $total = 0;
        foreach ($nums as $item) {
            $total = $total + $item;
        }
        var_dump($total); // expected: int(600)

        // Test 2d: foreach int-keyed with key access
        echo "[2d] foreach int keys:\n";
        $items = [7, 8, 9];
        $keySum = 0;
        foreach ($items as $idx => $val) {
            $keySum = $keySum + $idx;
        }
        var_dump($keySum); // expected: int(3) (0+1+2)

        // ═══════════════════════════════════════════
        // Fix 3: match without default
        // ═══════════════════════════════════════════
        echo "\n-- Fix 3: match without default --\n";

        // Test 3a: match with default
        echo "[3a] match with default:\n";
        $mval = match (2) {
            1 => "one",
            2 => "two",
            default => "other",
        };
        var_dump($mval); // expected: string("two")

        // Test 3b: match without default (all cases covered)
        echo "[3b] match no default, covered:\n";
        $code = 1;
        $label = match ($code) {
            1 => "first",
            2 => "second",
            3 => "third",
        };
        var_dump($label); // expected: string("first")

        // Test 3c: match with int result no default
        echo "[3c] match int result, no default:\n";
        $grade = 85;
        $level = match (true) {
            $grade >= 90 => 1,
            $grade >= 80 => 2,
            $grade >= 70 => 3,
            $grade >= 60 => 4,
        };
        var_dump($level); // expected: int(2)

        // Test 3d: match with bool result
        echo "[3d] match bool result:\n";
        $status = 1;
        $ok = match ($status) {
            0 => false,
            1 => true,
        };
        var_dump($ok); // expected: bool(true)

        // ═══════════════════════════════════════════
        // Switch testing
        // ═══════════════════════════════════════════
        echo "\n-- Switch tests --\n";

        // Test 4a: switch with int
        echo "[4a] int switch:\n";
        $x = 2;
        switch ($x) {
            case 1: echo " one\n"; break;
            case 2: echo " two\n"; break;
            default: echo " other\n"; break;
        }

        // Test 4b: switch with string
        echo "[4b] string switch:\n";
        $color = "green";
        switch ($color) {
            case "red": echo " red\n"; break;
            case "green": echo " green\n"; break;
            default: echo " unknown\n"; break;
        }

        // ═══════════════════════════════════════════
        // Combined tests: for + foreach
        // ═══════════════════════════════════════════
        echo "\n-- Combined: for + foreach --\n";

        // Test 5: for with foreach inside
        echo "[5] for with foreach inside:\n";
        $matrix = [[1, 2], [3, 4], [5, 6]];
        $grandTotal = 0;
        for ($r = 0; $r < count($matrix); $r = $r + 1) {
            foreach ($matrix[$r] as $cell) {
                $grandTotal = $grandTotal + $cell;
            }
        }
        var_dump($grandTotal); // expected: int(21)

        echo "\n=== All control flow tests passed! ===\n";
    }
}
