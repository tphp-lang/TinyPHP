<?php

class Main
{
    public function main(): void
    {
        echo "9dig: " . json_encode(999999999) . "\n";
        echo "10dig: " . json_encode(1234567890) . "\n";
        echo "DONE\n";
    }
}
