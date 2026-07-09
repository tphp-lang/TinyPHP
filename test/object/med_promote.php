<?php
#debug User name=Bob age=25

class User {
    public function __construct(
        public string $name,
        public int $age,
    ) {}

    public function info(): void {
        echo "User name=" . $this->name . " age=" . $this->age . "\n";
    }
}

class Main {
    public function main(): void {
        $u = new User("Bob", 25);
        $u->info();
    }
}
