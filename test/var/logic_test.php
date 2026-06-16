<?php
namespace Main;
function main(): void {
    echo "=== logic ! && || ===\n";
    var_dump(!0);
    var_dump(!1);
    var_dump(!!1);
    var_dump(!42);
    var_dump(1 && 1);
    var_dump(0 && 1);
    var_dump(1 && 0);
    var_dump(0 && 0);
    var_dump(0 || 1);
    var_dump(1 || 0);
    var_dump(0 || 0);
    var_dump(1 || 1);
    $x = 10;
    var_dump($x > 0 && $x < 20);
    var_dump($x < 0 && $x < 20);
    var_dump($x < 0 || $x > 5);
    var_dump($x < 0 || $x > 20);
    var_dump(!($x > 0));
    // combined
    var_dump(!($x > 0 && $x < 20) || $x == 10);
}
