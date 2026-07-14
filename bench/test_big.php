<?php

class Main
{
    public function main(): void
    {
        echo "big: " . json_encode(-2147483648) . "\n";
        echo "DONE\n";
    }
}
