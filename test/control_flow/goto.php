<?php
#debug int(3)
#debug int(10)
#debug goto ok

class Main
{
    public function main(): void
    {
        $x = 0;

        loop_start:
        $x = $x + 1;
        if ($x < 3) {
            goto loop_start;
        }

        var_dump($x);

        // skip
        $y = 10;
        goto skip;
        $y = 999;
        skip:
        var_dump($y);

        echo "goto ok\n";
    }
}
