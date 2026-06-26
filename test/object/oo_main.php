<?php // @multi @with oo_models.php

class Main
{
    public function main(): void
    {
        echo "=== OOP Test ===\n\n";
        $u = new User(1, 'Alice');
        echo "1. id=" . $u->getId() . "\n";
        echo "2. name=" . $u->getName() . "\n";
        echo "\n=== OK ===\n";
    }
}
