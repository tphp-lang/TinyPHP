<?php // @skip — companion file, no class Main

// Interface with a single method
interface Speakable {
    public function speak(): string;
}

// Interface with a single method
interface Trackable {
    public function getTrackId(): int;
}

// Interface extending another interface (stored in implements field)
interface Named extends Trackable {
    public function getName(): string;
}

// Abstract class implementing an interface
abstract class Animal implements Speakable {
    public int $id;

    public function __construct(int $id) {
        $this->id = $id;
    }

    // Concrete method satisfying Trackable via Named
    public function getTrackId(): int {
        return $this->id;
    }

    // Abstract method — subclass must implement
    abstract public function speak(): string;
}

// Concrete class: extends abstract Animal, implements Named interface
class Dog extends Animal implements Named {
    public string $name;

    public function __construct(int $id, string $name) {
        parent::__construct($id);
        $this->name = $name;
    }

    // Implements abstract method from Animal (satisfies Speakable)
    public function speak(): string {
        return $this->name . " barks!";
    }

    // Implements method from Named interface
    public function getName(): string {
        return $this->name;
    }
}

// Final class: cannot be extended
final class Cat extends Animal {
    public string $name;

    public function __construct(int $id, string $name) {
        parent::__construct($id);
        $this->name = $name;
    }

    public function speak(): string {
        return $this->name . " meows!";
    }

    // Final method: cannot be overridden by subclasses
    // (Cat is final so no subclasses exist, but this tests final method parsing)
    final public function getName(): string {
        return $this->name;
    }
}
