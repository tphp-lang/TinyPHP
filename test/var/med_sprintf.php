<?php

class Main {
    public function main(): void {
        $name = "Alice";
        $age = 30;

        $s1 = sprintf("Hi %s", $name);
        echo "1. " . $s1 . "\n";

        $s2 = sprintf("Age: %d", $age);
        echo "2. " . $s2 . "\n";

        $s3 = sprintf("%s is %d years old", $name, $age);
        echo "3. " . $s3 . "\n";
    }
}
