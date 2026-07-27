<?php

declare(strict_types=1);

// ============================================================
// UsedFeatures — 编译过程中使用过的语言特性集中跟踪
//
// 设计目标：
//   - 在 Parser / TypeChecker / CodeGenerator 各阶段统一记录源码中
//     出现过的语言特性（闭包、match、注解、指令、C 互操作、OOP 等）
//   - 后续可用于：运行时降级判断、能力探测、特性统计、诊断输出
//   - 字段全部 public bool，默认 false，由各阶段按需置 true
//
// 与 NodeKind 的关系：
//   - markFromNodeKind() 仅处理能由 NodeKind 直接判定的特性
//     （ClosureExpr、MatchExpr、YieldExpr、TryStmtNode 等）
//   - 不能由单一 NodeKind 判定的特性（如 namedArgs、spread、
//     readonlyClass、staticMethod、各种指令等）需通过 markXxx()
//     或 mark(name) 显式标记
// ============================================================

require_once __DIR__ . '/AST/FlatAst.php';

class UsedFeatures
{
    // ─────────────────────────────────────────────────────────────
    // 字段（全部 public bool，默认 false）
    // ─────────────────────────────────────────────────────────────

    /** 闭包 */
    public bool $closure = false;
    /** 箭头函数 */
    public bool $arrowFunction = false;
    /** 注解 */
    public bool $attribute = false;
    /** match 表达式 */
    public bool $match = false;
    /** 命名参数 */
    public bool $namedArgs = false;
    /** spread 操作 */
    public bool $spread = false;
    /** first-class callable */
    public bool $firstClassCallable = false;
    /** 属性钩子 */
    public bool $propertyHook = false;
    /** readonly class */
    public bool $readonlyClass = false;
    /** 管道运算符 */
    public bool $pipeOperator = false;
    /** throw 表达式 */
    public bool $throwExpression = false;
    /** or {} 块 */
    public bool $orBlock = false;
    /** #flag 指令 */
    public bool $flagDirective = false;
    /** #import 指令 */
    public bool $importDirective = false;
    /** #include 指令 */
    public bool $includeDirective = false;
    /** #callback 指令 */
    public bool $callbackDirective = false;
    /** #debug 指令 */
    public bool $debugDirective = false;
    /** 条件编译 #if/#else/#endif */
    public bool $conditionalCompilation = false;
    /** struct C.Foo 声明 */
    public bool $cStructDecl = false;
    /** function C.foo() 声明 */
    public bool $cFunctionDecl = false;
    /** yield/generator */
    public bool $generator = false;
    /** try/catch/finally */
    public bool $tryCatch = false;
    /** ?T 可空类型 */
    public bool $nullableType = false;
    /** T|U 联合类型 */
    public bool $unionType = false;
    /** enum 声明 */
    public bool $enum = false;
    /** trait 声明 */
    public bool $trait = false;
    /** interface 声明 */
    public bool $interface = false;
    /** abstract class */
    public bool $abstractClass = false;
    /** 静态方法 */
    public bool $staticMethod = false;
    /** 静态属性 */
    public bool $staticProperty = false;
    /** __destruct */
    public bool $destructor = false;

    // ─────────────────────────────────────────────────────────────
    // SubTask 9.2: 标记方法 — 每个特性一个 markXxx()
    // ─────────────────────────────────────────────────────────────

    public function markClosure(): void { $this->closure = true; }
    public function markArrowFunction(): void { $this->arrowFunction = true; }
    public function markAttribute(): void { $this->attribute = true; }
    public function markMatch(): void { $this->match = true; }
    public function markNamedArgs(): void { $this->namedArgs = true; }
    public function markSpread(): void { $this->spread = true; }
    public function markFirstClassCallable(): void { $this->firstClassCallable = true; }
    public function markPropertyHook(): void { $this->propertyHook = true; }
    public function markReadonlyClass(): void { $this->readonlyClass = true; }
    public function markPipeOperator(): void { $this->pipeOperator = true; }
    public function markThrowExpression(): void { $this->throwExpression = true; }
    public function markOrBlock(): void { $this->orBlock = true; }
    public function markFlagDirective(): void { $this->flagDirective = true; }
    public function markImportDirective(): void { $this->importDirective = true; }
    public function markIncludeDirective(): void { $this->includeDirective = true; }
    public function markCallbackDirective(): void { $this->callbackDirective = true; }
    public function markDebugDirective(): void { $this->debugDirective = true; }
    public function markConditionalCompilation(): void { $this->conditionalCompilation = true; }
    public function markCStructDecl(): void { $this->cStructDecl = true; }
    public function markCFunctionDecl(): void { $this->cFunctionDecl = true; }
    public function markGenerator(): void { $this->generator = true; }
    public function markTryCatch(): void { $this->tryCatch = true; }
    public function markNullableType(): void { $this->nullableType = true; }
    public function markUnionType(): void { $this->unionType = true; }
    public function markEnum(): void { $this->enum = true; }
    public function markTrait(): void { $this->trait = true; }
    public function markInterface(): void { $this->interface = true; }
    public function markAbstractClass(): void { $this->abstractClass = true; }
    public function markStaticMethod(): void { $this->staticMethod = true; }
    public function markStaticProperty(): void { $this->staticProperty = true; }
    public function markDestructor(): void { $this->destructor = true; }

    // ─────────────────────────────────────────────────────────────
    // SubTask 9.2: 通过特性名标记 — mark(string $feature)
    //
    // $feature 取值为字段名去掉 $ 后的字符串（如 'closure'、'arrowFunction'）。
    // 未知特性名静默忽略（便于后续扩展）。
    // ─────────────────────────────────────────────────────────────

    public function mark(string $feature): void
    {
        switch ($feature) {
            case 'closure':                   $this->closure = true; break;
            case 'arrowFunction':             $this->arrowFunction = true; break;
            case 'attribute':                 $this->attribute = true; break;
            case 'match':                     $this->match = true; break;
            case 'namedArgs':                 $this->namedArgs = true; break;
            case 'spread':                    $this->spread = true; break;
            case 'firstClassCallable':        $this->firstClassCallable = true; break;
            case 'propertyHook':              $this->propertyHook = true; break;
            case 'readonlyClass':             $this->readonlyClass = true; break;
            case 'pipeOperator':              $this->pipeOperator = true; break;
            case 'throwExpression':           $this->throwExpression = true; break;
            case 'orBlock':                   $this->orBlock = true; break;
            case 'flagDirective':             $this->flagDirective = true; break;
            case 'importDirective':           $this->importDirective = true; break;
            case 'includeDirective':          $this->includeDirective = true; break;
            case 'callbackDirective':         $this->callbackDirective = true; break;
            case 'debugDirective':            $this->debugDirective = true; break;
            case 'conditionalCompilation':    $this->conditionalCompilation = true; break;
            case 'cStructDecl':               $this->cStructDecl = true; break;
            case 'cFunctionDecl':             $this->cFunctionDecl = true; break;
            case 'generator':                 $this->generator = true; break;
            case 'tryCatch':                  $this->tryCatch = true; break;
            case 'nullableType':              $this->nullableType = true; break;
            case 'unionType':                 $this->unionType = true; break;
            case 'enum':                      $this->enum = true; break;
            case 'trait':                     $this->trait = true; break;
            case 'interface':                 $this->interface = true; break;
            case 'abstractClass':             $this->abstractClass = true; break;
            case 'staticMethod':              $this->staticMethod = true; break;
            case 'staticProperty':            $this->staticProperty = true; break;
            case 'destructor':                $this->destructor = true; break;
            default: /* 未知特性名，静默忽略 */ break;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // SubTask 9.2: 根据节点类型自动标记 — markFromNodeKind(int $kind)
    //
    // 仅处理能由 NodeKind 直接判定的特性。不能由单一 NodeKind 判定的
    // （如 namedArgs、spread、readonlyClass、staticMethod、各指令等）
    // 需调用方通过 markXxx() 或 mark(name) 显式标记。
    // ─────────────────────────────────────────────────────────────

    public function markFromNodeKind(int $kind): void
    {
        // NodeKind 是 backed enum，按 int 值匹配
        // 注意：本项目中箭头函数也用 ClosureExpr + extra.isArrow 表示，
        // 因此 markFromNodeKind 无法区分闭包/箭头函数，统一标记 closure；
        // 箭头函数需调用方在解析 isArrow 标志后调用 markArrowFunction()。
        switch ($kind) {
            case NodeKind::ClosureExpr->value:
                $this->closure = true;
                break;
            case NodeKind::MatchExpr->value:
                $this->match = true;
                break;
            case NodeKind::YieldExpr->value:
            case NodeKind::YieldFromExpr->value:
                $this->generator = true;
                break;
            case NodeKind::TryStmtNode->value:
                $this->tryCatch = true;
                break;
            case NodeKind::ThrowStmtNode->value:
            case NodeKind::ThrowExprNode->value:
                $this->throwExpression = true;
                break;
            case NodeKind::EnumNode->value:
                $this->enum = true;
                break;
            case NodeKind::AttributeDeclNode->value:
            case NodeKind::AttributeUseNode->value:
                $this->attribute = true;
                break;
            case NodeKind::PipeExpr->value:
                $this->pipeOperator = true;
                break;
            case NodeKind::CallableConvertExpr->value:
                $this->firstClassCallable = true;
                break;
            case NodeKind::OrBlockExpr->value:
                $this->orBlock = true;
                break;
            case NodeKind::PropertyHook->value:
                $this->propertyHook = true;
                break;
            default:
                /* 其它节点类型不在此处理 */
                break;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // SubTask 9.3: 聚合查询方法
    // ─────────────────────────────────────────────────────────────

    /** closure || arrowFunction */
    public function hasClosureOrArrow(): bool
    {
        return $this->closure || $this->arrowFunction;
    }

    /** flag/import/include/callback/debug/conditionalCompilation 任一为 true */
    public function hasAnyDirective(): bool
    {
        return $this->flagDirective
            || $this->importDirective
            || $this->includeDirective
            || $this->callbackDirective
            || $this->debugDirective
            || $this->conditionalCompilation;
    }

    /** cStructDecl || cFunctionDecl */
    public function hasAnyCInterop(): bool
    {
        return $this->cStructDecl || $this->cFunctionDecl;
    }

    /** enum || trait || interface || abstractClass */
    public function hasAnyOop(): bool
    {
        return $this->enum
            || $this->trait
            || $this->interface
            || $this->abstractClass;
    }

    /** nullableType || unionType */
    public function hasAnyAdvancedType(): bool
    {
        return $this->nullableType || $this->unionType;
    }

    /** 统计已使用的特性数（值为 true 的字段数） */
    public function count(): int
    {
        $n = 0;
        if ($this->closure) $n++;
        if ($this->arrowFunction) $n++;
        if ($this->attribute) $n++;
        if ($this->match) $n++;
        if ($this->namedArgs) $n++;
        if ($this->spread) $n++;
        if ($this->firstClassCallable) $n++;
        if ($this->propertyHook) $n++;
        if ($this->readonlyClass) $n++;
        if ($this->pipeOperator) $n++;
        if ($this->throwExpression) $n++;
        if ($this->orBlock) $n++;
        if ($this->flagDirective) $n++;
        if ($this->importDirective) $n++;
        if ($this->includeDirective) $n++;
        if ($this->callbackDirective) $n++;
        if ($this->debugDirective) $n++;
        if ($this->conditionalCompilation) $n++;
        if ($this->cStructDecl) $n++;
        if ($this->cFunctionDecl) $n++;
        if ($this->generator) $n++;
        if ($this->tryCatch) $n++;
        if ($this->nullableType) $n++;
        if ($this->unionType) $n++;
        if ($this->enum) $n++;
        if ($this->trait) $n++;
        if ($this->interface) $n++;
        if ($this->abstractClass) $n++;
        if ($this->staticMethod) $n++;
        if ($this->staticProperty) $n++;
        if ($this->destructor) $n++;
        return $n;
    }

    /**
     * 返回所有特性及其状态（字段名 => bool）。
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'closure'                => $this->closure,
            'arrowFunction'          => $this->arrowFunction,
            'attribute'              => $this->attribute,
            'match'                  => $this->match,
            'namedArgs'              => $this->namedArgs,
            'spread'                 => $this->spread,
            'firstClassCallable'     => $this->firstClassCallable,
            'propertyHook'           => $this->propertyHook,
            'readonlyClass'          => $this->readonlyClass,
            'pipeOperator'           => $this->pipeOperator,
            'throwExpression'        => $this->throwExpression,
            'orBlock'                => $this->orBlock,
            'flagDirective'          => $this->flagDirective,
            'importDirective'        => $this->importDirective,
            'includeDirective'       => $this->includeDirective,
            'callbackDirective'      => $this->callbackDirective,
            'debugDirective'         => $this->debugDirective,
            'conditionalCompilation' => $this->conditionalCompilation,
            'cStructDecl'            => $this->cStructDecl,
            'cFunctionDecl'          => $this->cFunctionDecl,
            'generator'              => $this->generator,
            'tryCatch'               => $this->tryCatch,
            'nullableType'           => $this->nullableType,
            'unionType'              => $this->unionType,
            'enum'                   => $this->enum,
            'trait'                  => $this->trait,
            'interface'              => $this->interface,
            'abstractClass'          => $this->abstractClass,
            'staticMethod'           => $this->staticMethod,
            'staticProperty'         => $this->staticProperty,
            'destructor'             => $this->destructor,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // SubTask 9.4: 重置 — reset(): void
    // ─────────────────────────────────────────────────────────────

    public function reset(): void
    {
        $this->closure                = false;
        $this->arrowFunction          = false;
        $this->attribute              = false;
        $this->match                  = false;
        $this->namedArgs              = false;
        $this->spread                 = false;
        $this->firstClassCallable     = false;
        $this->propertyHook           = false;
        $this->readonlyClass          = false;
        $this->pipeOperator           = false;
        $this->throwExpression        = false;
        $this->orBlock                = false;
        $this->flagDirective          = false;
        $this->importDirective        = false;
        $this->includeDirective       = false;
        $this->callbackDirective      = false;
        $this->debugDirective         = false;
        $this->conditionalCompilation = false;
        $this->cStructDecl            = false;
        $this->cFunctionDecl          = false;
        $this->generator              = false;
        $this->tryCatch               = false;
        $this->nullableType           = false;
        $this->unionType              = false;
        $this->enum                   = false;
        $this->trait                  = false;
        $this->interface              = false;
        $this->abstractClass          = false;
        $this->staticMethod           = false;
        $this->staticProperty         = false;
        $this->destructor             = false;
    }
}
