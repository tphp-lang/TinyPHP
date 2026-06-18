<?php

declare(strict_types=1);

// ============================================================
// AST 节点定义
// ============================================================

abstract class ASTNode
{
    abstract public function accept(ASTVisitor $visitor): string;
}

// 整个程序 = 入口类 Main + 辅助类 + 独立函数 + 常量 + 枚举
class ProgramNode extends ASTNode
{
    /** @param ClassNode[] $extraClasses */
    /** @param FunctionNode[] $functions */
    /** @param ConstNode[] $constants */
    /** @param EnumNode[] $enums */
    public function __construct(
        public readonly ?ClassNode $mainClass = null,
        /** @var ClassNode[] */
        public readonly array $extraClasses = [],
        public readonly array $functions = [],
        /** @var ConstNode[] */
        public readonly array $constants = [],
        /** @var EnumNode[] */
        public readonly array $enums = [],
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
    /** @param PropertyDeclNode[] $properties */
    public function __construct(
        public readonly string $name,
        public readonly array $methods,
        public readonly string $namespace = '',
        /** @var PropertyDeclNode[] */
        public readonly array $properties = [],
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitClass($this);
    }
}

// 属性声明: public int $a = 10;
class PropertyDeclNode extends ASTNode
{
    public function __construct(
        public readonly string $name,         // $a
        public readonly string $type,          // int
        public readonly string $visibility,    // public | private
        public readonly ?ExprNode $default,   // 默认值（可为 null）
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitPropertyDecl($this);
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

// list($a, $b) = expr;
class ListStmtNode extends StmtNode
{
    /** @param string[] $vars (不含 $ 前缀的变量名) */
    public function __construct(
        /** @var string[] */
        public readonly array $vars,
        public readonly ExprNode $expr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitListStmt($this);
    }
}

// $obj->prop = expr;  或  $this->prop = expr;
class AssignPropStmtNode extends StmtNode
{
    public function __construct(
        public readonly PropertyAccessExpr $target,
        public readonly ExprNode $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAssignPropStmt($this);
    }
}

// if (cond) { body } elseif (cond) { body } else { body }
class IfStmtNode extends StmtNode
{
    /** @param ElseIfBranch[] $elseifs */
    public function __construct(
        public readonly ExprNode $condition,
        /** @var StmtNode[] */
        public readonly array $thenBody,
        /** @var ElseIfBranch[] */
        public readonly array $elseifs = [],
        /** @var StmtNode[] */
        public readonly array $elseBody = [],
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitIfStmt($this);
    }
}

// elseif (cond) { body }
class ElseIfBranch
{
    public function __construct(
        public readonly ExprNode $condition,
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}
}

// while (cond) { body }
class WhileStmtNode extends StmtNode
{
    public function __construct(
        public readonly ExprNode $condition,
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitWhileStmt($this);
    }
}

// do { body } while (cond);
class DoWhileStmtNode extends StmtNode
{
    /** @param StmtNode[] $body */
    public function __construct(
        public readonly ExprNode $condition,
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitDoWhileStmt($this);
    }
}

// for (init; cond; step) { body }
class ForStmtNode extends StmtNode
{
    public function __construct(
        public readonly ?ExprNode $init,
        public readonly ?ExprNode $condition,
        public readonly ?ExprNode $step,
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitForStmt($this);
    }
}

// foreach ($arr as $val) / foreach ($arr as $key => $val)
class ForeachStmtNode extends StmtNode
{
    public function __construct(
        public readonly ExprNode $array,
        public readonly string $valueVar,     // $val
        public readonly ?string $keyVar = null, // $key (optional)
        /** @var StmtNode[] */
        public readonly array $body = [],
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitForeachStmt($this);
    }
}

// switch (cond) { case val: body break; default: body }
class SwitchStmtNode extends StmtNode
{
    /** @param CaseBranch[] $cases */
    public function __construct(
        public readonly ExprNode $condition,
        /** @var CaseBranch[] */
        public readonly array $cases = [],
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitSwitchStmt($this);
    }
}

// case val: / default:
class CaseBranch
{
    /** @param StmtNode[] $body */
    public function __construct(
        public readonly ?ExprNode $value,  // null = default case
        /** @var StmtNode[] */
        public readonly array $body,
    ) {}
}

// break;
class BreakStmtNode extends StmtNode
{
    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitBreakStmt($this);
    }
}

// continue;
class ContinueStmtNode extends StmtNode
{
    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitContinueStmt($this);
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

abstract class ExprNode extends ASTNode
{
    public int $line = 0;
    public int $column = 0;
}

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

// 数组条目: key => value（无 key 时 key=null，自增 int）
class ArrayEntryNode
{
    public function __construct(
        public readonly ?ExprNode $key,    // null = 自增 int 键
        public readonly ExprNode $value,
    ) {}
}

// 数组字面量 [1, 2, 3] 或 ["a"=>1, "b"=>2]
class ArrayLiteralExpr extends ExprNode
{
    /** @param ArrayEntryNode[] $entries */
    public function __construct(
        /** @var ArrayEntryNode[] */
        public readonly array $entries,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitArrayLiteral($this);
    }
}

// 数组访问: $arr[0] 或 $arr["key"]
class ArrayAccessExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $array,
        public readonly ExprNode $index,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitArrayAccess($this);
    }
}

// 属性访问: $obj->prop 或 $this->prop
class PropertyAccessExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $object,
        public readonly string $property,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitPropertyAccess($this);
    }
}

// 枚举访问: Color::RED
class EnumAccessExpr extends ExprNode
{
    public function __construct(
        public readonly string $enumName,
        public readonly string $caseName,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitEnumAccess($this);
    }
}

// 常量声明
class ConstNode extends ASTNode
{
    public function __construct(
        public readonly string $name,
        /** @var ExprNode|null */
        public readonly ?ExprNode $value,
        public readonly string $namespace = '',
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitConst($this);
    }
}

// 枚举声明: enum Name: int { case A = 1; case B = 2; }
class EnumNode extends ASTNode
{
    /** @param EnumCaseNode[] $cases */
    public function __construct(
        public readonly string $name,
        public readonly string $backingType,   // 'int' | 'string'
        /** @var EnumCaseNode[] */
        public readonly array $cases,
        public readonly string $namespace = '',
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitEnum($this);
    }
}

// 枚举条目: case NAME = value;
class EnumCaseNode
{
    public function __construct(
        public readonly string $name,
        public readonly ExprNode $value,
    ) {}
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

// 一元运算
class UnaryExpr extends ExprNode
{
    public function __construct(
        public readonly string $operator,   // '-', '!', '++', '--'
        public readonly ExprNode $expr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitUnary($this);
    }
}

// $var++, $var-- 后缀运算符
class PostfixExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $expr,
        public readonly string $operator,   // '++', '--'
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitPostfix($this);
    }
}

// $var += expr (复合赋值)
class CompoundAssignExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $target,
        public readonly string $operator,
        public readonly ExprNode $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitCompoundAssign($this);
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

// 三元: cond ? then : else
class TernaryExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $condition,
        public readonly ExprNode $thenExpr,
        public readonly ExprNode $elseExpr,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitTernary($this);
    }
}

// null 合并: $a ?? $b
class NullCoalesceExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $left,
        public readonly ExprNode $right,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitNullCoalesce($this);
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
    public function visitAssignPropStmt(AssignPropStmtNode $node): string;
    public function visitExprStmt(ExprStmtNode $node): string;
    public function visitStringLiteral(StringLiteralExpr $node): string;
    public function visitIntLiteral(IntLiteralExpr $node): string;
    public function visitFloatLiteral(FloatLiteralExpr $node): string;
    public function visitBoolLiteral(BoolLiteralExpr $node): string;
    public function visitNullLiteral(NullLiteralExpr $node): string;
    public function visitVariable(VariableExpr $node): string;
    public function visitBinary(BinaryExpr $node): string;
    public function visitTernary(TernaryExpr $node): string;
    public function visitNullCoalesce(NullCoalesceExpr $node): string;
    public function visitCall(CallExpr $node): string;
    public function visitCast(CastExpr $node): string;
    public function visitNew(NewExpr $node): string;
    public function visitArrayLiteral(ArrayLiteralExpr $node): string;
    public function visitClosure(ClosureExpr $node): string;
    public function visitUnary(UnaryExpr $node): string;
    public function visitPostfix(PostfixExpr $node): string;
    public function visitCompoundAssign(CompoundAssignExpr $node): string;
    public function visitArrayAccess(ArrayAccessExpr $node): string;
    public function visitPropertyAccess(PropertyAccessExpr $node): string;
    public function visitPropertyDecl(PropertyDeclNode $node): string;
    public function visitConst(ConstNode $node): string;
    public function visitEnum(EnumNode $node): string;
    public function visitEnumAccess(EnumAccessExpr $node): string;
    public function visitIfStmt(IfStmtNode $node): string;
    public function visitWhileStmt(WhileStmtNode $node): string;
    public function visitDoWhileStmt(DoWhileStmtNode $node): string;
    public function visitListStmt(ListStmtNode $node): string;
    public function visitForStmt(ForStmtNode $node): string;
    public function visitForeachStmt(ForeachStmtNode $node): string;
    public function visitSwitchStmt(SwitchStmtNode $node): string;
    public function visitBreakStmt(BreakStmtNode $node): string;
    public function visitContinueStmt(ContinueStmtNode $node): string;
}
