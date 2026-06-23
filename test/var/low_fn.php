<?php

class Main {
    public function main(): void {
        $dbl = fn($x) => $x * 2;
        $r1 = $dbl(21);
        echo "1. fn double=" . $r1 . "\n";

        $adder = fn($a, $b) => $a + $b;
        $r2 = $adder(10, 20);
        echo "2. fn add=" . $r2 . "\n";
    }
}
