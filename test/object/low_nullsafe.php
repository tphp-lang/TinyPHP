<?php
#debug 1. nullsafe val=42
#debug 2. nullsafe str=ok

class Demo {
    public function getVal(): int {
        return 42;
    }
    public function getName(): string {
        return "ok";
    }
}

class Main {
    public function main(): void {
        $d = new Demo();

        // nullsafe on non-null object
        $v1 = $d?->getVal();
        echo "1. nullsafe val=" . $v1 . "\n";

        $s1 = $d?->getName();
        echo "2. nullsafe str=" . $s1 . "\n";
    }
}
