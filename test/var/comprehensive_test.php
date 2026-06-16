<?php
namespace Main;
function main(): void {
    echo "=== logic ===\n";
    var_dump(!0); var_dump(!!1);
    var_dump(1 && 1); var_dump(0 || 1);
    $x = 10; var_dump($x > 0 && $x < 20);

    echo "=== compound ===\n";
    $a = 10; $a += 5; var_dump($a);
    $s = "hello"; $s .= " world"; var_dump($s);

    echo "=== float cmp ===\n";
    var_dump(1.0 == 1.0); var_dump(1.0 < 2.0);
    var_dump(2.0 > 1.0); var_dump(1.0 <= 1.0);

    echo "=== combined ===\n";
    var_dump(!($a > 0 && $a < 20) || $a == 15);
    var_dump((bool)0); var_dump((bool)1); var_dump((bool)"hello");
    var_dump((int)3.99); var_dump((string)42);

    echo "=== array ===\n";
    $arr = array("int", [1, 2, 3]);
    var_dump($arr[0]);
    var_dump(count($arr));
}
