<?php
#debug === enum test ===
#debug ONE=int(1)
#debug TWO=int(2)
#debug name=string(3) "ONE"
#debug none=0
#debug === done ===

enum Num: int
{
    case ONE = 1;
    case TWO = 2;
}

class Main
{
    public function main(): void
    {
        echo "=== enum test ===\n";
        echo "ONE="; var_dump(Num::ONE->value);
        echo "TWO="; var_dump(Num::TWO->value);
        echo "name="; var_dump(Num::ONE->name);
        echo "none=" . (Num::ONE->value - 1) . "\n";
        echo "=== done ===\n";
    }
}
