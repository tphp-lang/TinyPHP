<?php

declare(strict_types=1);

namespace Tphp\AST;

// ---- Base ----

abstract readonly class ASTNode
{
    public int $line;
    public function __construct(int $line) { $this->line = $line; }
}

// ---- Types ----

enum TphpType: string
{
    case Int    = 'int';
    case Float  = 'float';
    case String = 'string';
    case Bool   = 'bool';
    case Null   = 'null';
    case Void   = 'void';
    case Callable_ = 'callable';
    case Array_ = 'array';

    public function size(): int
    {
        return match ($this) {
            self::Int    => 8,  // i64
            self::Float  => 8,  // f64
            self::String => 16, // pointer(8) + length(8)
            self::Bool   => 1,
            self::Null   => 8,
            self::Void   => 0,
            self::Callable_ => 8, // function pointer
            self::Array_ => 0,    // variable size, depends on element type
        };
    }
}

// ---- Base Expr/Stmt markers ----

abstract readonly class ExprNode extends ASTNode {}

abstract readonly class StmtNode extends ASTNode {}
