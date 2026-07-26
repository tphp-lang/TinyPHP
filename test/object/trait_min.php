<?php
#debug Hello from trait
#debug OK

trait Greeter {
    public function sayHello(): void {
        echo "Hello from trait\n";
    }
}

class User {
    use Greeter;
}

class Main {
    public function main(): void {
        $u = new User();
        $u->sayHello();
        echo "OK\n";
    }
}
