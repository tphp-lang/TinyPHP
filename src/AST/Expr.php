<?php

declare(strict_types=1);

namespace Tphp\AST;

// ---- Literals ----

final readonly class IntegerLiteralNode extends ExprNode
{
    public function __construct(int $line, public int $value) { parent::__construct($line); }
}

final readonly class FloatLiteralNode extends ExprNode
{
    public function __construct(int $line, public float $value) { parent::__construct($line); }
}

final readonly class StringLiteralNode extends ExprNode
{
    public function __construct(int $line, public string $value) { parent::__construct($line); }
}

final readonly class BoolLiteralNode extends ExprNode
{
    public function __construct(int $line, public bool $value) { parent::__construct($line); }
}

final readonly class NullLiteralNode extends ExprNode
{
    public function __construct(int $line) { parent::__construct($line); }
}

// ---- Variable / Const references ----

final readonly class VarRefNode extends ExprNode
{
    public function __construct(int $line, public string $name) { parent::__construct($line); }
}

final readonly class ConstRefNode extends ExprNode
{
    public function __construct(
        int $line,
        public string $name,
    ) { parent::__construct($line); }
}

// ---- Binary operation ----

final readonly class BinaryOpNode extends ExprNode
{
    public function __construct(
        int $line,
        public ExprNode $left,
        public string $op,
        public ExprNode $right,
    ) { parent::__construct($line); }
}

// ---- Function call ----

final readonly class FuncCallNode extends ExprNode
{
    /**
     * @param ExprNode[] $args
     */
    public function __construct(
        int $line,
        public string $name,
        public array $args,
    ) { parent::__construct($line); }
}

// ---- Index / String range ----

final readonly class IndexAccessNode extends ExprNode
{
    public function __construct(
        int $line,
        public ExprNode $target,
        public ExprNode $index,
    ) { parent::__construct($line); }
}

final readonly class StringRangeNode extends ExprNode
{
    public function __construct(
        int $line,
        public ExprNode $target,
        public ExprNode $start,
        public ExprNode $end,
    ) { parent::__construct($line); }
}

// ---- Array ----

final readonly class ArrayLiteralNode extends ExprNode
{
    /**
     * @param ExprNode[] $elements
     */
    public function __construct(
        int $line,
        public TphpType $elementType,
        public array $elements,
    ) { parent::__construct($line); }
}

// ---- Expression-call (for calling array element, etc.) ----

final readonly class ExprCallNode extends ExprNode
{
    /**
     * @param ExprNode[] $args
     */
    public function __construct(
        int $line,
        public ExprNode $callee,
        public array $args,
    ) { parent::__construct($line); }
}

// ---- Post-increment / Post-decrement ----

final readonly class PostIncrementNode extends ExprNode
{
    public function __construct(
        int $line,
        public string $varName,
        public bool $isDecrement = false,
    ) { parent::__construct($line); }
}

// ---- Closure ----

final readonly class ClosureNode extends ExprNode
{
    /**
     * @param ParamNode[] $params
     * @param StmtNode[] $body
     */
    public function __construct(
        int $line,
        public array $params,
        public TphpType $returnType,
        public array $body,
    ) { parent::__construct($line); }
}

// ---- OOP ----

final readonly class ThisExprNode extends ExprNode
{
    public function __construct(int $line) { parent::__construct($line); }
}

final readonly class MethodCallNode extends ExprNode
{
    /**
     * @param ExprNode $object  the object expression (e.g., VarRefNode or ThisExprNode)
     * @param string $methodName
     * @param ExprNode[] $args
     */
    public function __construct(
        int $line,
        public ExprNode $object,
        public string $methodName,
        public array $args,
    ) { parent::__construct($line); }
}

final readonly class NewExprNode extends ExprNode
{
    public function __construct(
        int $line,
        public string $className,  // unqualified class name as written in source
    ) { parent::__construct($line); }
}

// ---- Type cast ----

final readonly class CastExprNode extends ExprNode
{
    public function __construct(
        int $line,
        public TphpType $targetType,
        public ExprNode $operand,
    ) { parent::__construct($line); }
}

// ---- FFI ----

final readonly class CFuncCallNode extends ExprNode
{
    /** @param ExprNode[] $args */
    public function __construct(
        int $line,
        public string $funcName,
        public array $args,
    ) { parent::__construct($line); }
}

// ---- Enum ----

/** EnumName::Case → resolves to the case's value at compile time */
final readonly class EnumAccessNode extends ExprNode
{
    public function __construct(
        int $line,
        public string $enumName,
        public string $caseName,
    ) { parent::__construct($line); }
}
