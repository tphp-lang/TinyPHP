<?php

class Main {
    public function main(): void {
        // 通过桥接层调用 C
        $d = p2c_distance(0.0, 0.0, 3.0, 4.0);
        echo '1. C calc_distance(0,0,3,4)='; var_dump($d); echo "\n";

        $f = p2c_factorial(10);
        echo '2. C factorial(10)=' . $f . "\n";

        if ($f === 3628800) { echo "3. factorial OK\n"; }

        echo '4. file=' . __FILE__ . "\n";
    }
}
