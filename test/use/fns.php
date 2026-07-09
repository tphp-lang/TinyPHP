<?php // @skip — companion file, no class Main

namespace Demo\Sub;

function greet(string $name): string {
    return "Hello, " . $name . "!";
}

function add(int $a, int $b): int {
    return $a + $b;
}

function multiply(int $a, int $b): int {
    return $a * $b;
}

function double(int $x): int {
    return $x * 2;
}
