<?php

declare(strict_types=1);

namespace Tphp\AST;

// ---- Statements ----

final readonly class VarDeclNode extends StmtNode
{
    public function __construct(
        int $line,
        public string $name,
        public ExprNode $init,
    ) { parent::__construct($line); }
}

final readonly class ExprStmtNode extends StmtNode
{
    public function __construct(
        int $line,
        public ExprNode $expr,
    ) { parent::__construct($line); }
}

final readonly class PrintStmtNode extends StmtNode
{
    /** @param ExprNode[] $args */
    public function __construct(
        int $line,
        public array $args,
        public bool $isVarDump = false,
    ) { parent::__construct($line); }
}

final readonly class ReturnStmtNode extends StmtNode
{
    public function __construct(
        int $line,
        public ?ExprNode $expr,
    ) { parent::__construct($line); }
}

final readonly class IfStmtNode extends StmtNode
{
    public function __construct(
        int $line,
        public ExprNode $condition,
        /** @var StmtNode[] */ public array $thenBody,
        /** @var StmtNode[] */ public array $elseBody,
    ) { parent::__construct($line); }
}

final readonly class WhileStmtNode extends StmtNode
{
    public function __construct(
        int $line,
        public ExprNode $condition,
        /** @var StmtNode[] */ public array $body,
    ) { parent::__construct($line); }
}

final readonly class ForStmtNode extends StmtNode
{
    /**
     * @param ?StmtNode $init init statement (e.g. VarDeclNode for $i = 0)
     * @param ?ExprNode $condition loop condition
     * @param ?ExprNode $increment loop increment expression
     * @param StmtNode[] $body loop body
     */
    public function __construct(
        int $line,
        public ?StmtNode $init,
        public ?ExprNode $condition,
        public ?ExprNode $increment,
        /** @var StmtNode[] */ public array $body,
    ) { parent::__construct($line); }
}

final readonly class SwitchStmtNode extends StmtNode
{
    /**
     * @param SwitchCaseNode[] $cases
     * @param StmtNode[] $defaultBody
     */
    public function __construct(
        int $line,
        public ExprNode $subject,
        public array $cases,
        public array $defaultBody,
    ) { parent::__construct($line); }
}

final readonly class SwitchCaseNode extends ASTNode
{
    /** @param StmtNode[] $body */
    public function __construct(
        int $line,
        public ExprNode $condition,
        public array $body,
    ) { parent::__construct($line); }
}

final readonly class BreakStmtNode extends StmtNode
{
    public function __construct(int $line) { parent::__construct($line); }
}

final readonly class UnsetStmtNode extends StmtNode
{
    public function __construct(
        int $line,
        public string $name,
        public ExprNode $index,
    ) { parent::__construct($line); }
}

final readonly class ArrayAppendStmtNode extends StmtNode
{
    public function __construct(
        int $line,
        public string $name,
        public ExprNode $value,
    ) { parent::__construct($line); }
}
