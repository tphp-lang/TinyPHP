<?php
#debug 1. sort: 1020304050
#debug 2. rsort: 302010
#debug 3. unique: len=4 vals=1234
#debug 4. range(1,5): len=5 val=15
#debug 5. range(10,1,-3): len=4 val=10741
#debug 6. fill(0,3,99): len=3 at0=99 at2=99
#debug 7. replace: 'hi world hi'
#debug 8. replace nomatch: 'abc'

class Main {
    public function main(): void {
        // ── 1. sort ──
        $a1 = [30, 10, 20, 50, 40];
        sort($a1);
        echo "1. sort: " . $a1[0] . $a1[1] . $a1[2] . $a1[3] . $a1[4] . "\n";

        // ── 2. rsort ──
        $a2 = [30, 10, 20];
        rsort($a2);
        echo "2. rsort: " . $a2[0] . $a2[1] . $a2[2] . "\n";

        // ── 3. array_unique ──
        $a3 = [1, 2, 2, 3, 1, 4, 3];
        $u3 = array_unique($a3);
        echo "3. unique: len=" . count($u3) . " vals=" . $u3[0] . $u3[1] . $u3[2] . $u3[3] . "\n";

        // ── 4. range ascending ──
        $r4 = range(1, 5);
        echo "4. range(1,5): len=" . count($r4) . " val=" . $r4[0] . $r4[4] . "\n";

        // ── 5. range descending ──
        $r5 = range(10, 1, -3);
        echo "5. range(10,1,-3): len=" . count($r5) . " val=" . $r5[0] . $r5[1] . $r5[2] . $r5[3] . "\n";

        // ── 6. array_fill ──
        $f6 = array_fill(0, 3, 99);
        echo "6. fill(0,3,99): len=" . count($f6) . " at0=" . $f6[0] . " at2=" . $f6[2] . "\n";

        // ── 7. str_replace ──
        $s7 = "hello world hello";
        $r7 = str_replace("hello", "hi", $s7);
        echo "7. replace: '$r7'\n";

        // ── 8. str_replace no match ──
        $s8 = "abc";
        $r8 = str_replace("xyz", "X", $s8);
        echo "8. replace nomatch: '$r8'\n";
    }
}
