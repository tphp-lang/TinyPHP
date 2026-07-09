<?php
#debug === 1. 对象数组 ===
#debug u[0]: Alice age=25
#debug u[1]: Bob age=30
#debug
#debug === 2. 对象覆盖 ===
#debug overwritten: Eve age=22
#debug === done ===

class User
{
    public string $name;
    public int $age;

    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age  = $age;
    }
}

class Main
{
    public function main(): void
    {
        // === 1. 对象数组 ===
        echo "=== 1. 对象数组 ===\n";
        $u1 = new User("Alice", 25);
        $u2 = new User("Bob",   30);
        $users = [$u1, $u2];

        $u = $users[0];
        echo "u[0]: " . $u->name . " age=" . $u->age . "\n";

        $u = $users[1];
        echo "u[1]: " . $u->name . " age=" . $u->age . "\n";

        // === 2. 对象覆盖 ===
        echo "\n=== 2. 对象覆盖 ===\n";
        $u3 = new User("Eve", 22);
        $users[0] = $u3;
        $u = $users[0];
        echo "overwritten: " . $u->name . " age=" . $u->age . "\n";

        echo "=== done ===\n";
    }
}
