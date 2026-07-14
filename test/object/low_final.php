<?php
#debug 1. 10===10: 1 5!==5: 0
#debug OK-static-called OK-normal 2. all done

class Main {
    public static int $counter = 0;

    public function main(): void {
        // === / !==
        echo "1. 10===10: " . (10 === 10) . " 5!==5: " . (5 !== 5) . "\n";

        // static method call via Main::inc()
        Main::inc();

        // never return type
        $this->ok();

        echo "2. all done\n";
    }

    public static function inc(): void {
        self::$counter = self::$counter + 1;
        echo "OK-static-called ";
    }

    public function ok(): void {
        echo "OK-normal ";
    }

    public function dieNow(): never {
        exit(1);
    }
}
