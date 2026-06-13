<?php

declare(strict_types=1);

namespace Tphp\AST;

// ---- Program ----

final readonly class ProgramNode extends ASTNode
{
    /**
     * @param string|null $namespace  namespace name, e.g. 'Main', 'Demo', 'MyAdmin\Name', null if none
     * @param UseImportNode[] $imports
     * @param ConstDeclNode[] $consts
     * @param EnumDeclNode[] $enums
     * @param ExternFuncNode[] $externs
     * @param FunctionDeclNode[] $functions
     * @param ClassDeclNode[] $classes
     */
    public function __construct(
        int $line,
        public ?string $namespace,
        public array $imports,
        public array $consts,
        public array $enums,
        public array $externs,
        public array $functions,
        public array $classes = [],
    ) { parent::__construct($line); }
}

// ---- Use import ----

final readonly class UseImportNode extends ASTNode
{
    /**
     * @param string $importType  'function' or 'class'
     * @param string $fullName    fully qualified name, e.g. 'Demo\myDemo'
     * @param string $alias       short alias name, e.g. 'myDemo'
     */
    public function __construct(
        int $line,
        public string $importType,
        public string $fullName,
        public string $alias,
    ) { parent::__construct($line); }
}

// ---- Const declaration ---- 

final readonly class ConstDeclNode extends ASTNode
{
    public function __construct(
        int $line,
        public string $name,
        public ExprNode $init,
    ) { parent::__construct($line); }
}

// ---- Function declaration ----

final readonly class FunctionDeclNode extends ASTNode
{
    /**
     * @param ParamNode[] $params
     * @param StmtNode[] $body
     */
    public function __construct(
        int $line,
        public string $name,
        public TphpType $returnType,
        public array $params,
        public array $body,
    ) { parent::__construct($line); }
}

final readonly class ParamNode extends ASTNode
{
    public function __construct(
        int $line,
        public string $name,
        public TphpType $type,
        public ?string $typeName = null,  // original type name for enum/class type hints
    ) { parent::__construct($line); }
}

// ---- Class & Method (OOP) ----

final readonly class ClassDeclNode extends ASTNode
{
    /**
     * @param MethodDeclNode[] $methods
     */
    public function __construct(
        int $line,
        public string $name,
        public array $methods,
    ) { parent::__construct($line); }
}

final readonly class MethodDeclNode extends ASTNode
{
    /**
     * @param ParamNode[] $params
     * @param StmtNode[] $body
     */
    public function __construct(
        int $line,
        public string $name,
        public string $visibility, // 'public' or 'private'
        public TphpType $returnType,
        public array $params,
        public array $body,
    ) { parent::__construct($line); }
}

// ---- Enum ----

final readonly class EnumDeclNode extends ASTNode
{
    /**
     * @param EnumCaseNode[] $cases
     */
    public function __construct(
        int $line,
        public string $name,
        public TphpType $backingType,  // Int or String
        public array $cases,
    ) { parent::__construct($line); }
}

final readonly class EnumCaseNode extends ASTNode
{
    public function __construct(
        int $line,
        public string $name,
        /** int|string */ public mixed $value,
    ) { parent::__construct($line); }
}

// ---- FFI: extern function declaration ----

final readonly class ExternFuncNode extends ASTNode
{
    /** @param ParamNode[] $params */
    public function __construct(
        int $line,
        public string $name,
        public TphpType $returnType,
        public array $params,
    ) { parent::__construct($line); }
}
