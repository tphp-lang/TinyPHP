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
    /** @param string[] $includes */
    /** @param array[] $callbacks [['name'=>'cb','ret'=>'int32_t','params_str'=>'int32_t a'], ...] */
    public function __construct(
        public readonly ?ClassNode $mainClass = null,
        /** @var ClassNode[] */
        public readonly array $extraClasses = [],
        public readonly array $functions = [],
        /** @var ConstNode[] */
        public readonly array $constants = [],
        /** @var EnumNode[] */
        public readonly array $enums = [],
        /** @var array[]  [['file'=>'demo.h','quoted'=>true], ...] */
        public readonly array $includes = [],
        /** @var array[]  [['platform'=>'','flags'=>'-lm'], ...] */
        public readonly array $ccFlags = [],
        /** @var array[]  [['name'=>'cb','ret'=>'int32_t','params_str'=>'...'], ...] */
        public readonly array $callbacks = [],
        /** @var string[] #debug 指令收集的预期输出 */
        public readonly array $debugs = [],
        /** @var array[]  [['name'=>'Point','fields'=>[['type'=>'C.double','name'=>'x'],...]], ...] */
        public readonly array $cstructs = [],
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
        public readonly bool $isGenerator = false,
        /** @var AttributeUseNode[] */
        public readonly array $attributes = [],
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
    /** @param PropertyDeclNode[] $properties
     *  @param ConstNode[] $classConsts */
    public function __construct(
        public readonly string $name,
        public readonly array $methods,
        public readonly string $namespace = '',
        /** @var PropertyDeclNode[] */
        public readonly array $properties = [],
        /** @var ConstNode[] 类成员常量 */
        public readonly array $classConsts = [],
        public readonly ?string $parentName = null,
        public readonly bool $isAbstract = false,
        /** @var string[] */
        public readonly array $implements = [],
        /** @var array[] trait names to flatten */
        public readonly array $traits = [],
        public readonly bool $isReadonly = false, // readonly class: 所有属性自动 readonly
        /** @var AttributeUseNode[] */
        public readonly array $attributes = [],
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitClass($this);
    }
}

// 属性声明: public int $a = 10;  或带 hook: public int $a { get => ...; set => ...; }
class PropertyDeclNode extends ASTNode
{
    /** @param PropertyHook[] $hooks */
    public function __construct(
        public readonly string $name,         // $a
        public readonly string $type,          // int
        public readonly string $visibility,    // public | private
        public readonly ?ExprNode $default,   // 默认值（可为 null）
        public readonly array $hooks = [],    // PropertyHook[]
        public readonly bool $isStatic = false, // 静态属性（生成文件作用域 static 变量）
        public readonly bool $isReadonly = false, // readonly 属性：仅声明处或 __construct 内可赋值一次
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitPropertyDecl($this);
    }
}

// Property Hook: get => expr;  或  set { stmts }
class PropertyHook
{
    /** @param StmtNode[] $body 块形式体（expr 为 null 时使用） */
    public function __construct(
        public readonly string $kind,         // 'get' | 'set'
        public readonly ?ExprNode $expr,      // 短形式: get => expr
        public readonly array $body = [],     // 块形式: get { stmts }
    ) {}
}

// public function name(params): returnType { body }
class MethodNode extends ASTNode
{
    /** @param ParamNode[] $params */
    /** @param PropertyDeclNode[] $promoted */
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,  // public | private
        /** @var ParamNode[] */
        public readonly array $params,
        public readonly string $returnType,
        /** @var StmtNode[]|null null=abstract */
        public readonly array|null $body,
        /** @var PropertyDeclNode[] */
        public readonly array $promoted = [],
        public readonly bool $isGenerator = false,
        public readonly bool $isStatic = false, // 静态方法（签名省略 self 参数）
        /** @var AttributeUseNode[] */
        public readonly array $attributes = [],
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

// 参数: [&] type $name
class ParamNode extends ASTNode
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly bool $byRef = false,
        public readonly ?ExprNode $default = null,  // 默认值表达式
        public readonly bool $isReadonly = false,   // 属性提升 readonly 参数
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
        /** 可选类型标记：'int'|'string'|...|'C.FILE'，null=无标记（按推断） */
        public readonly ?string $type = null,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAssignStmt($this);
    }
}

// list($a, $b) = expr;
class ListStmtNode extends StmtNode
{
    /**
     * @param array        $vars          位置元素: null=跳过, string=变量名, ListStmtNode=嵌套解构
     * @param array        $keyedEntries  键名解构: [[key=>string, var=>string], ...]
     * @param bool         $short         是否短语法 []（仅 Parser 标记用）
     */
    public function __construct(
        /** @var array */
        public readonly array $vars,
        public readonly ExprNode $expr,
        public readonly bool $short = false,
        /** @var array<int, array{key:string, var:string}> */
        public readonly array $keyedEntries = [],
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

// $arr[$i] = value
class AssignArrayStmtNode extends StmtNode
{
    public function __construct(
        public readonly ArrayAccessExpr $target,
        public readonly ExprNode $value,
    ) {}


    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAssignArrayStmt($this);
    }
}

// $arr[] = value  (array push 语法糖)
class AssignArrayPushStmtNode extends StmtNode
{
    public function __construct(
        public readonly string $varName,
        public readonly ExprNode $value,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAssignArrayPushStmt($this);
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
    public function __construct(public readonly int $level = 1) {}
    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitBreakStmt($this);
    }
}

// goto LABEL;
class GotoStmtNode extends StmtNode
{
    public function __construct(public readonly string $label) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitGotoStmt($this);
    }
}

// try { ... } catch (Type $e) { ... } finally { ... }
//   支持多 catch 子句：catchClauses = [['type' => 'Exception', 'var' => 'e', 'body' => [...]], ...]
class TryStmtNode extends StmtNode
{
    /** @param StmtNode[] $tryBody */
    /** @param array<array{type:string, var:string, body:StmtNode[]}> $catchClauses */
    /** @param StmtNode[] $finallyBody */
    public function __construct(
        public readonly array $tryBody,
        public readonly array $catchClauses,
        public readonly array $finallyBody,
    ) {}
    public function accept(ASTVisitor $visitor): string { return $visitor->visitTryStmt($this); }
}

// throw expr;
class ThrowStmtNode extends StmtNode
{
    public function __construct(public readonly ExprNode $expr) {}
    public function accept(ASTVisitor $visitor): string { return $visitor->visitThrowStmt($this); }
}

// throw 表达式（PHP 8.0+）：throw 出现在表达式位置
//   $x = throw new E();  $x ?? throw new E();  $cond ? $a : throw new E();
//   类型为 never（永不返回），编译期展开为 throw 语句，零运行时开销
class ThrowExprNode extends ExprNode
{
    public function __construct(public readonly ExprNode $expr) {}
    public function accept(ASTVisitor $visitor): string { return $visitor->visitThrowExpr($this); }
}

// LABEL:
class LabelStmtNode extends StmtNode
{
    public function __construct(public readonly string $name) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitLabelStmt($this);
    }
}

// continue;
class ContinueStmtNode extends StmtNode
{
    public function __construct(public readonly int $level = 1) {}
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

// static $var = expr;  或  static type $var = expr;
//   函数内静态变量，跨调用保持值（等价于 C 函数内 static 变量）
class StaticStmtNode extends StmtNode
{
    public function __construct(
        public readonly string $varName,
        public readonly ?string $type,     // null = 从初始值推导
        public readonly ?ExprNode $init,   // 初始值（可为 null，仅声明）
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitStaticStmt($this);
    }
}

// 函数内 const NAME = value;  或  const type NAME = value;
//   PHP 8.3+ 函数内常量，等价于 C 函数内 static const 变量
class ConstStmtNode extends StmtNode
{
    public function __construct(
        public readonly string $name,
        public readonly ExprNode $value,
        /** 类型标注：'string'|'int'|'float'|'bool'|'array'，null=从字面量推导 */
        public readonly ?string $type = null,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitConstStmt($this);
    }
}

// 块语句：语句序列（用于链式赋值 $a = $b = 1 展开）
class BlockStmtNode extends StmtNode
{
    /** @param StmtNode[] $stmts */
    public function __construct(
        public readonly array $stmts,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitBlockStmt($this);
    }
}

// defer 语句：注册清理代码，在作用域正常退出时 LIFO 执行
//   defer EXPR;        — 单表达式清理（如 defer C->free($buf);）
//   defer { body }     — 块清理
// 编译期展开到所有 return 点和 fall-through 尾部，零运行时开销。
// 异常路径（longjmp）不执行 defer（与 C 局部变量析构限制一致）。
class DeferStmtNode extends StmtNode
{
    /** @param StmtNode[] $body */
    public function __construct(
        public readonly array $body,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitDeferStmt($this);
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

// __LINE__ / __FILE__ / __DIR__ / DIRECTORY_SEPARATOR
class MagicConstExpr extends ExprNode
{
    public function __construct(
        public readonly string $name,
        int $line = 0,
    ) { $this->line = $line; }
    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitMagicConst($this);
    }
}

// 数组条目: key => value（无 key 时 key=null，自增 int）
class ArrayEntryNode
{
    public function __construct(
        public readonly ?ExprNode $key,    // null = 自增 int 键
        public readonly ExprNode $value,
        public readonly bool $isSpread = false, // ...$arr 展开元素（不允许 key）
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
        public readonly bool $isNullsafe = false,
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

// 注解类型声明: #[Attribute(path: string, method: array)]
//   附着于 const ROUTE = []; 声明注解类型及其参数格式
class AttributeDeclNode extends ASTNode
{
    /** @param array[] $params [['name'=>'path','type'=>'string','default'=>?ExprNode], ...] */
    public function __construct(
        public readonly array $params,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAttributeDecl($this);
    }
}

// 注解使用: #[ROUTE("/test", ["GET", "POST"])]
//   附着于 class/method/function，编译期收集到对应注解常量数组
class AttributeUseNode extends ASTNode
{
    public function __construct(
        public readonly string $name,   // 注解名（如 "ROUTE"），可含命名空间前缀
        /** @var ExprNode[] 位置参数 */
        public readonly array $args,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitAttributeUse($this);
    }
}

// 常量声明（全局 const + 类 const）
class ConstNode extends ASTNode
{
    public function __construct(
        public readonly string $name,
        /** @var ExprNode|null */
        public readonly ?ExprNode $value,
        public readonly string $namespace = '',
        /** 类型标注：'string'|'int'|'float'|'bool'|'array'，null=无标注 */
        public readonly ?string $type = null,
        /** visibility：'public'|'private'|'protected'，null=全局 const */
        public readonly ?string $visibility = null,
        /** 所属类名，null=全局 const */
        public readonly ?string $className = null,
        /** 注解类型声明（#[Attribute(...)]），null=普通常量 */
        public readonly ?AttributeDeclNode $attributeDecl = null,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitConst($this);
    }
}

// 枚举声明: enum Name: int { case A = 1; case B = 2; public function label(): string {...} const MAX = 9; }
class EnumNode extends ASTNode
{
    /** @param EnumCaseNode[] $cases */
    /** @param MethodNode[] $methods */
    /** @param ConstNode[] $classConsts */
    public function __construct(
        public readonly string $name,
        public readonly string $backingType,   // 'int' | 'string'
        /** @var EnumCaseNode[] */
        public readonly array $cases,
        public readonly string $namespace = '',
        /** @var MethodNode[] 实例/静态方法 */
        public readonly array $methods = [],
        /** @var ConstNode[] 枚举常量 */
        public readonly array $classConsts = [],
        /** @var string[] 实现的接口名（记录用，不做 vtable 强制） */
        public readonly array $implements = [],
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
    /** @param array{string,string}[] $useVars  [[varName, type], ...] */
    public function __construct(
        /** @var ParamNode[] */
        public readonly array $params,
        public readonly string $returnType,
        /** @var StmtNode[] */
        public readonly array $body,
        /** @var array{string,string}[] */
        public readonly array $useVars = [],
        public readonly bool $isGenerator = false,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitClosure($this);
    }
}

// yield expr | yield key => expr | yield;
class YieldExpr extends ExprNode
{
    public function __construct(
        public readonly ?ExprNode $key,    // null = auto-increment key
        public readonly ?ExprNode $value,  // null = yield NULL (yield;)
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitYieldExpr($this);
    }
}

// yield from expr  — 委托子生成器/可迭代对象
class YieldFromExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $expr,  // 被委托的表达式（Generator 或 array）
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitYieldFromExpr($this);
    }
}

// pipe operator: left |> right
// right 通常是 CallExpr（含 PlaceholderExpr 占位符）或闭包
class PipeExpr extends ExprNode
{
    public function __construct(
        public readonly ExprNode $left,   // 管道左侧值
        public readonly ExprNode $right,  // 管道右侧 callable（CallExpr / ClosureExpr / VariableExpr）
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitPipeExpr($this);
    }
}

// 参数占位符 `...`（仅在 pipe 上下文使用，表示左侧值的插入位置）
class PlaceholderExpr extends ExprNode
{
    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitPlaceholderExpr($this);
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

// match arm: values => result_expr  (values 为 [expr,...])
class MatchArm
{
    /** @param ExprNode[] $values  */
    public function __construct(
        /** @var ExprNode[] */
        public readonly array $values,
        public readonly ExprNode $body,
    ) {}
}

// match(expr) { arm, arm, ... }
class MatchExpr extends ExprNode
{
    /** @param MatchArm[] $arms */
    public function __construct(
        public readonly ExprNode $condition,
        /** @var MatchArm[] */
        public readonly array $arms,
    ) {}

    public function accept(ASTVisitor $visitor): string
    {
        return $visitor->visitMatchExpr($this);
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
        public readonly bool $isNullsafe = false,
        public readonly bool $isRawC = false,
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
    public function visitAssignArrayStmt(AssignArrayStmtNode $node): string;
    public function visitAssignArrayPushStmt(AssignArrayPushStmtNode $node): string;
    public function visitExprStmt(ExprStmtNode $node): string;
    public function visitStaticStmt(StaticStmtNode $node): string;
    public function visitConstStmt(ConstStmtNode $node): string;
    public function visitBlockStmt(BlockStmtNode $node): string;
    public function visitStringLiteral(StringLiteralExpr $node): string;
    public function visitIntLiteral(IntLiteralExpr $node): string;
    public function visitFloatLiteral(FloatLiteralExpr $node): string;
    public function visitBoolLiteral(BoolLiteralExpr $node): string;
    public function visitNullLiteral(NullLiteralExpr $node): string;
    public function visitMagicConst(MagicConstExpr $node): string;
    public function visitVariable(VariableExpr $node): string;
    public function visitBinary(BinaryExpr $node): string;
    public function visitTernary(TernaryExpr $node): string;
    public function visitNullCoalesce(NullCoalesceExpr $node): string;
    public function visitMatchExpr(MatchExpr $node): string;
    public function visitCall(CallExpr $node): string;
    public function visitCast(CastExpr $node): string;
    public function visitNew(NewExpr $node): string;
    public function visitArrayLiteral(ArrayLiteralExpr $node): string;
    public function visitClosure(ClosureExpr $node): string;
    public function visitYieldExpr(YieldExpr $node): string;
    public function visitYieldFromExpr(YieldFromExpr $node): string;
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
    public function visitGotoStmt(GotoStmtNode $node): string;
    public function visitTryStmt(TryStmtNode $node): string;
    public function visitThrowStmt(ThrowStmtNode $node): string;
    public function visitThrowExpr(ThrowExprNode $node): string;
    public function visitLabelStmt(LabelStmtNode $node): string;
    public function visitContinueStmt(ContinueStmtNode $node): string;
    public function visitPipeExpr(PipeExpr $node): string;
    public function visitPlaceholderExpr(PlaceholderExpr $node): string;
    public function visitAttributeDecl(AttributeDeclNode $node): string;
    public function visitAttributeUse(AttributeUseNode $node): string;
    public function visitDeferStmt(DeferStmtNode $node): string;
}
