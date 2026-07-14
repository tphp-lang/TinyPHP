<?php
#debug 1. fn double=42
#debug 2. fn add=30

class Main {
    public function main(): void {
        $dbl = fn(int $x): int => $x * 2;
        $r1 = $dbl(21);
        echo "1. fn double=" . $r1 . "\n";

        $adder = fn(int $a, int $b): int => $a + $b;
        $r2 = $adder(10, 20);
        echo "2. fn add=" . $r2 . "\n";
    }
}
