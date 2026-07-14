<?php
#debug === OOP Test ===
#debug
#debug 1. id=1
#debug 2. name=Alice
#debug
#debug === OK ===

interface Named { public function getName(): string; }
interface Identifiable { public function getId(): int; }

abstract class Entity implements Named
{
    public int $id;
    public function getId(): int { return $this->id; }
    abstract public function getName(): string;
}

class User extends Entity implements Named, Identifiable
{
    public string $name;
    public function __construct(int $id, string $name)
    {
        $this->id = $id; $this->name = $name;
    }
    public function getName(): string { return $this->name; }
}

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
