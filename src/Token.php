<?php

declare(strict_types=1);

class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $lexeme,
        public readonly int $line,
        public readonly int $column,
        public readonly mixed $literal = null,
    ) {}

    public function __toString(): string
    {
        $lit = $this->literal !== null ? " ({$this->literal})" : '';
        return "{$this->type->value}{$lit} at {$this->line}:{$this->column}";
    }
}
