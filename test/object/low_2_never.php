<?php
#debug never test ok

class Main {
    public function main(): void {
        echo "never test ok\n";
    }

    public function fatal(): never {
        exit(1);
    }
}
