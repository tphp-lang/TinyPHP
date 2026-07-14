<?php
#debug 1. intval(3.14)=3 intval(true)=1
#debug 2. floatval(42)=float(42)
#debug
#debug 3. strval(42)=42 strval(false)=[]
#debug 4. boolval(0)=0 boolval(1)=1 boolval(0.0)=0
#debug 5. rand(10,12) in range: 1
#debug 6. mt_rand(100,100)=100

class Main {
    public function main(): void {
        // ── 1. intval ──
        echo "1. intval(3.14)=" . intval(3.14) . " intval(true)=" . intval(true) . "\n";

        // ── 2. floatval ──
        echo "2. floatval(42)="; var_dump(floatval(42));
        echo "\n";

        // ── 3. strval ──
        echo "3. strval(42)=" . strval(42) . " strval(false)=[" . strval(false) . "]\n";

        // ── 4. boolval ──
        echo "4. boolval(0)=" . boolval(0) . " boolval(1)=" . boolval(1) . " boolval(0.0)=" . boolval(0.0) . "\n";

        // ── 5. rand ──
        $r1 = rand(10, 12);
        $inRange = ($r1 >= 10 && $r1 <= 12) ? 1 : 0;
        echo "5. rand(10,12) in range: $inRange\n";

        // ── 6. mt_rand ──
        $r2 = mt_rand(100, 100);
        echo "6. mt_rand(100,100)=$r2\n";
    }
}
