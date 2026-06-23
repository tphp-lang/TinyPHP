<?php

#include "demo.h"

function p2c_distance(float $x1, float $y1, float $x2, float $y2): float {
    return php_float(C->calc_distance(c_float($x1), c_float($y1), c_float($x2), c_float($y2)));
}

function p2c_factorial(int $n): int {
    return php_int(C->factorial(c_int($n)));
}
