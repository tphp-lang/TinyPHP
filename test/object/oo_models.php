<?php // @skip — companion file, no class Main


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
