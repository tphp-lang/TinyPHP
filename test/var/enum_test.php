<?php

enum Num: int
{
    case ONE = 1;
    case TWO = 2;
}

class Main
{
    public function main(): void
    {
        $one = Num::ONE;
        var_dump($one);
    }
}
