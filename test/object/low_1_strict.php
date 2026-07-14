<?php
#debug 1a. int 10===10: 1
#debug 1b. int 10===20: 0
#debug 1c. int 10!==20: 1
#debug 1d. int 10!==10: 0
#debug 2a. str hello===hello: 1
#debug 2b. str hello===world: 0
#debug 2c. str hello!==world: 1
#debug 3a. float 3.14===3.14: 1
#debug 3b. float 3.14===2.71: 0
#debug 3c. float 3.14!==2.71: 1
#debug 4a. bool true===true: 1
#debug 4b. bool true===false: 0
#debug 4c. bool true!==false: 1
#debug 5a. int 10==10: 1 vs ===: 1
#debug 6. if === 42: pass
#debug 7. if !== 0: pass

class Main {
    public function main(): void {
        // ── int === / !== ──
        $ai = 10; $bi = 10; $ci = 20;
        echo "1a. int 10===10: " . ($ai === $bi) . "\n";
        echo "1b. int 10===20: " . ($ai === $ci) . "\n";
        echo "1c. int 10!==20: " . ($ai !== $ci) . "\n";
        echo "1d. int 10!==10: " . ($ai !== $bi) . "\n";

        // ── string === / !== ──
        $as = "hello"; $bs = "hello"; $cs = "world";
        echo "2a. str hello===hello: " . ($as === $bs) . "\n";
        echo "2b. str hello===world: " . ($as === $cs) . "\n";
        echo "2c. str hello!==world: " . ($as !== $cs) . "\n";

        // ── float === / !== ──
        $af = 3.14; $bf = 3.14; $cf = 2.71;
        echo "3a. float 3.14===3.14: " . ($af === $bf) . "\n";
        echo "3b. float 3.14===2.71: " . ($af === $cf) . "\n";
        echo "3c. float 3.14!==2.71: " . ($af !== $cf) . "\n";

        // ── bool === / !== ──
        $at = true; $bf2 = false;
        echo "4a. bool true===true: " . ($at === true) . "\n";
        echo "4b. bool true===false: " . ($at === false) . "\n";
        echo "4c. bool true!==false: " . ($at !== $bf2) . "\n";

        // ── mixed type comparison (AOT: same as == since types fixed) ──
        echo "5a. int 10==10: " . ($ai == $bi) . " vs ===: " . ($ai === $bi) . "\n";

        // ── conditional use ──
        $val = 42;
        if ($val === 42) { echo "6. if === 42: pass\n"; }
        if ($val !== 0)  { echo "7. if !== 0: pass\n"; }
    }
}
