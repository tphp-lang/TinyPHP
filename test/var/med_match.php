<?php

class Main {
    public function main(): void {
        // match with multi-value arms
        $v1 = match(1) { 1, 2, 3 => "small", 4, 5 => "mid", default => "big" };
        echo "1. match 1=" . $v1 . "\n";

        $v2 = match(5) { 1, 2, 3 => "small", 4, 5 => "mid", default => "big" };
        echo "2. match 5=" . $v2 . "\n";

        $v3 = match(10) { 1, 2 => "few", default => "many" };
        echo "3. match 10=" . $v3 . "\n";
    }
}
