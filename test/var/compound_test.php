<?php
namespace Main;
function main(): void {
    $a = 10; $a += 5; var_dump($a);
    $a -= 3; var_dump($a);
    $a += 10; var_dump($a);
    $s = "hello"; $s .= " world"; var_dump($s);
    $s .= "!"; var_dump($s);
    var_dump((bool)0);var_dump((bool)1);var_dump((int)3);var_dump((string)42);
    var_dump(0);var_dump(1);var_dump(2);var_dump(3);var_dump(4);
}
