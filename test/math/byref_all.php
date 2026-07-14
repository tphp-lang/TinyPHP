<?php
// byRef 全类型测试：int, float, string, bool, array, swap
#debug === byRef all types test ===
#debug
#debug -- int --
#debug int(11)
#debug -- float --
#debug float(3.5)
#debug -- bool --
#debug bool(false)
#debug bool(true)
#debug -- string --
#debug string(8) "modified"
#debug -- array push --
#debug array(3) {
#debug   [0]=>
#debug   int(10)
#debug   [1]=>
#debug   int(20)
#debug   [2]=>
#debug   int(30)
#debug }
#debug count=3
#debug -- array modify --
#debug array(2) {
#debug   [0]=>
#debug   int(999)
#debug   [1]=>
#debug   int(2)
#debug }
#debug -- swap --
#debug before: 100 200
#debug after:  200 100
#debug -- multi --
#debug p=6 q=10
#debug -- nested --
#debug int(44)
#debug
#debug === ALL byRef tests done ===

// === int ===
function incInt(int &$x): void { $x = $x + 1; }

// === float ===
function incFloat(float &$x): void { $x = $x + 0.5; }

// === bool ===
function toggleBool(bool &$x): void { $x = !$x; }

// === string ===
function setStr(string &$x): void { $x = "modified"; }

// === array ===
function pushItem(array &$arr, int $v): void { $arr[] = $v; }
function setArrElement(array &$arr): void { $arr[0] = 999; }

// === multi byRef ===
function swapInts(int &$a, int &$b): void { $t = $a; $a = $b; $b = $t; }
function addXY(int &$x, int &$y): void { $x = $x + 1; $y = $y + 1; }

// === nested byRef ===
function doubleInc(int &$x): void { incInt($x); incInt($x); }

class Main {
    public function main(): void {
        echo "=== byRef all types test ===\n\n";

        // 1. int
        echo "-- int --\n";
        $a = 10; incInt($a); var_dump($a);

        // 2. float
        echo "-- float --\n";
        $f = 3.0; incFloat($f); var_dump($f);

        // 3. bool
        echo "-- bool --\n";
        $b = true; toggleBool($b); var_dump($b);
        toggleBool($b); var_dump($b);

        // 4. string
        echo "-- string --\n";
        $s = "hello"; setStr($s); var_dump($s);

        // 5. array push via byRef
        echo "-- array push --\n";
        $arr = [];
        $arr[] = 10; $arr[] = 20;
        pushItem($arr, 30);
        var_dump($arr);
        echo "count=", count($arr), "\n";

        // 6. modify array element via byRef
        echo "-- array modify --\n";
        $arr2 = [];
        $arr2[] = 1; $arr2[] = 2;
        setArrElement($arr2);
        var_dump($arr2);

        // 7. swap
        echo "-- swap --\n";
        $x = 100; $y = 200;
        echo "before: ", $x, " ", $y, "\n";
        swapInts($x, $y);
        echo "after:  ", $x, " ", $y, "\n";

        // 8. multi byRef + local read
        echo "-- multi --\n";
        $p = 5; $q = 9;
        addXY($p, $q);
        echo "p=", $p, " q=", $q, "\n";

        // 9. nested byRef
        echo "-- nested --\n";
        $n = 42;
        doubleInc($n);
        var_dump($n);

        echo "\n=== ALL byRef tests done ===\n";
    }
}
