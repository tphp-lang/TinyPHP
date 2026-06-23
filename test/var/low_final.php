<?php

class Main {
    public static int $counter = 0;

    public function main(): void {
        // === / !==
        echo "1. 10===10: " . (10 === 10) . " 5!==5: " . (5 !== 5) . "\n";

        // static method (MUST use simple call - Main:: still WIP)
        $this->inc();

        // never return type
        $this->ok();

        echo "2. all done\n";
    }

    public static function inc(): void {
        echo "OK-static-called ";
    }

    public function ok(): void {
        echo "OK-normal ";
    }

    public function dieNow(): never {
        exit(1);
    }
}
