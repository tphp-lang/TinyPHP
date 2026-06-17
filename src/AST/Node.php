<?php

declare(strict_types=1);

// ============================================================
// AST 节点定义
// ============================================================

abstract class ASTNode
{
    abstract public function accept(ASTVisitor $visitor): string;
}

// 整个程序 = 入口类 Main + 辅助类 + 独立函数
class ProgramNode extends ASTNode
{
    /** @param ClassNode[] $extraClasses */
    /** @param FunctionNode[] $functions */
    public function __construct(
        public readonly ?ClassNode $mainClass = null,
        /** @var ClassNode[] */
        public readonly array $extraClasses = [],
        public readonly array $functions = [],
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitProgram($this);
    }
}

// 独立函数 function name(params): ret { body }
class FunctionNode extends ASTNode
{
    /** @param ParamNode[] $params */
    public function __construct(
        public readonly string $name,
        /** @var ParamNode[] */
        public readonly array $params,
        public readonly string $returnType,
        /** @var StmtNode[] */
        public readonly array $body,
        public readonly string $namespace = '',  // 所属命名空间
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitFunction($this);
    }
}

// class Main { ... }
class ClassNode extends ASTNode
{
    /** @param MethodNode[] $methods */
    public function __construct(
        public readonly string $name,
        public readonly array $methods,
        public readonly string $namespace = '',  // 所属命名空间
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitClass($this);
    }
}

// public function name(params): returnType { body }
class MethodNode extends ASTNode
{
    /** @param ParamNode[] $params */
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,  // public | private
        /** @var ParamNode[] */
        public readonly array $params,
        public readonly string $returnType,
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitMethod($this);
    }

    public function isMagic(): bool
    {
        return $this->name === '__construct' || $this->name === '__destruct';
    }
}

// 参数: type $name
class ParamNode extends ASTNode
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitParam($this);
    }
}

// === 语句 ===

abstract class StmtNode extends ASTNode {}

// echo expr;
class EchoStmtNode extends StmtNode
{
    public function __construct(
        /** @var ExprNode[] */
        public readonly array $exprs,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitEchoStmt($this);
    }
}

// return expr;
class ReturnStmtNode extends StmtNode
{
    public function __construct(
        public readonly ?ExprNode $expr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitReturnStmt($this);
    }
}

// $var = expr;
class AssignStmtNode extends StmtNode
{
    public function __construct(
        public readonly string $varName,
        public readonly ExprNode $expr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAssignStmt($this);
    }
}

// expr;
class ExprStmtNode extends StmtNode
{
    public function __construct(
        public readonly ExprNode $expr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitExprStmt($this);
    }
}

// === 表达式 ===

abstract class ExprNode extends ASTNode {}

// 字符串字面量
class StringLiteralExpr extends ExprNode
{
    public function __construct(
        public readonly string $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitStringLiteral($this);
    }
}

// 整数
class IntLiteralExpr extends ExprNode
{
    public function __construct(
        public readonly int $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitIntLiteral($this);
    }
}

// 浮点
class FloatLiteralExpr extends ExprNode
{
    public function __construct(
        public readonly float $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitFloatLiteral($this);
    }
}

// 布尔
class BoolLiteralExpr extends ExprNode
{
    public function __construct(
        public readonly bool $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitBoolLiteral($this);
    }
}

// null
class NullLiteralExpr extends ExprNode
{
    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitNullLiteral($this);
    }
}

// 数组字面量 [1, 2, 3]
class ArrayLiteralExpr extends ExprNode
{
    /** @param ExprNode[] $elements */
    public function __construct(
        /** @var ExprNode[] */
        public readonly array $elements,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitArrayLiteral($this);
    }
}

// 匿名函数 / 闭包: function(): int { return 10; }
class ClosureExpr extends ExprNode
{
    /** @param ParamNode[] $params */
    public function __construct(
        /** @var ParamNode[] */
        public readonly array $params,
        public readonly string $returnType,
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitClosure($this);
    }
}

// 变量
class VariableExpr extends ExprNode
{
    public function __construct(
        public readonly string $name, // 含 $ 前缀
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitVariable($this);
    }
}

// 二元运算
class BinaryExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $left,
        public readonly string $operator,
        public readonly ExprNode $right,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitBinary($this);
    }
}

// 函数调用 / 方法调用
class CallExpr extends ExprNode
{
    public function __construct(
        public readonly ?ExprNode $callee,
        public readonly string $name,
        /** @var ExprNode[] */
        public readonly array $args,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitCall($this);
    }
}

// 类型转换 (int)$x
class CastExpr extends ExprNode
{
    public function __construct(
        public readonly string $castType,
        public readonly ExprNode $expr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitCast($this);
    }
}

// new ClassName(args)
class NewExpr extends ExprNode
{
    public function __construct(
        public readonly string $className,
        /** @var ExprNode[] */
        public readonly array $args,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitNew($this);
    }
}

// ============================================================
// Visitor 接口
// ============================================================
interface ASTVisitor
{
    public function visitProgram(ProgramNode $node): string;
    public function visitClass(ClassNode $node): string;
    public function visitFunction(FunctionNode $node): string;
    public function visitMethod(MethodNode $node): string;
    public function visitParam(ParamNode $node): string;
    public function visitEchoStmt(EchoStmtNode $node): string;
    public function visitReturnStmt(ReturnStmtNode $node): string;
    public function visitAssignStmt(AssignStmtNode $node): string;
    public function visitExprStmt(ExprStmtNode $node): string;
    public function visitStringLiteral(StringLiteralExpr $node): string;
    public function visitIntLiteral(IntLiteralExpr $node): string;
    public function visitFloatLiteral(FloatLiteralExpr $node): string;
    public function visitBoolLiteral(BoolLiteralExpr $node): string;
    public function visitNullLiteral(NullLiteralExpr $node): string;
    public function visitVariable(VariableExpr $node): string;
    public function visitBinary(BinaryExpr $node): string;
    public function visitCall(CallExpr $node): string;
    public function visitCast(CastExpr $node): string;
    public function visitNew(NewExpr $node): string;
    public function visitArrayLiteral(ArrayLiteralExpr $node): string;
    public function visitClosure(ClosureExpr $node): string;
}
