<?php

#debug === Chain Assignment Test ===
#debug
#debug [2-way int] a=1 b=1
#debug [3-way str] x=hello y=hello z=hello
#debug [typed] p=42 q=42
#debug [expr] m=3 n=3
#debug
#debug === All chain tests done ===

class Main {
    public function main(): void {
        echo "=== Chain Assignment Test ===\n\n";

        // 2-way: $a = $b = 1
        $a = $b = 1;
        echo "[2-way int] a=" . $a . " b=" . $b . "\n";

        // 3-way: $x = $y = $z = "hello"
        $x = $y = $z = "hello";
        echo "[3-way str] x=" . $x . " y=" . $y . " z=" . $z . "\n";

        // Typed chain: int $p = $q = 42
        int $p = $q = 42;
        echo "[typed] p=" . $p . " q=" . $q . "\n";

        // Chain with expression
        $m = $n = 1 + 2;
        echo "[expr] m=" . $m . " n=" . $n . "\n";

        echo "\n=== All chain tests done ===\n";
    }
}
