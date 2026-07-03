<?php

#debug === Password Test ===
#debug
#debug Test 1: Simple echo
#debug
#debug -- hash --
#debug int(60)
#debug string(60) "~"
#debug
#debug -- verify --
#debug bool(true)
#debug bool(false)
#debug
#debug === All password tests done ===

class Main {
    public function main(): void {
        echo "=== Password Test ===\n\n";
        
        // First test: simple echo
        echo "Test 1: Simple echo\n";
        
        // Second test: password_hash
        echo "\n-- hash --\n";
        $hash = password_hash("hello world", PASSWORD_BCRYPT);
        var_dump(strlen($hash));
        var_dump($hash);

        echo "\n-- verify --\n";
        $ok = password_verify("hello world", $hash);
        var_dump($ok);
        $wrong = password_verify("wrong password", $hash);
        var_dump($wrong);

        echo "\n=== All password tests done ===\n";
    }
}
