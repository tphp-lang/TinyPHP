<?php
#debug === Extends Test ===
#debug
#debug 1. speak=Rex barks!
#debug 2. name=Rex
#debug 3. breed=Husky
#debug 4. age=3
#debug
#debug === OK ===

class Animal
{
    public string $name;
    public int $age;

    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age  = $age;
    }

    public function speak(): string
    {
        return $this->name . ' makes sound';
    }

    public function getAge(): int
    {
        return $this->age;
    }
}

class Dog extends Animal
{
    public string $breed;

    public function __construct(string $name, int $age, string $breed)
    {
        $this->name  = $name;
        $this->age   = $age;
        $this->breed = $breed;
    }

    public function speak(): string
    {
        return $this->name . ' barks!';
    }

    public function getBreed(): string
    {
        return $this->breed;
    }
}

class Main
{
    public function main(): void
    {
        echo "=== Extends Test ===\n\n";

        $d = new Dog('Rex', 3, 'Husky');

        echo '1. speak=' . $d->speak() . "\n";
        echo '2. name=' . $d->name . "\n";
        echo '3. breed=' . $d->getBreed() . "\n";
        echo '4. age=' . $d->getAge() . "\n";

        echo "\n=== OK ===\n";
    }
}
