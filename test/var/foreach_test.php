<?php
namespace Main;
function main(): void {
    $a = array("int", [10, 20, 30]);
    foreach ($a as $v1) {
        var_dump($v1);
    }
    $b = array("string", ["x", "y"]);
    foreach ($b as $k => $v2) {
        var_dump($k);
        var_dump($v2);
    }
    var_dump(0);var_dump(1);var_dump(2);var_dump(3);var_dump(4);
    var_dump(5);var_dump(6);var_dump(7);var_dump(8);var_dump(9);
    var_dump(10);var_dump(11);var_dump(12);var_dump(13);var_dump(14);
}
