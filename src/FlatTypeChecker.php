<?php

declare(strict_types=1);

// ============================================================
// FlatTypeChecker — 消费 FlatAst 的类型检查器
//
// 设计目标：
//   - 不重写现有 src/TypeChecker.php（仍服务于主编译流水线）
//   - 专门消费 FlatAst 数据结构，验证 FlatAst 的可用性
//   - 填充 FlatAst 节点的 typ 字段（存 Type::idx()）
//   - 复用现有 SymbolTable 进行函数/类/方法/属性类型查询
//   - 内部维护变量作用域栈（与 TypeChecker 一致）
//   - 简单错误收集（不抛异常）
//
// 类型表示：
//   - typ 字段存 Type 对象的 idx()（int）
//   - 0 = 未推导 / IDX_VOID（无值表达式不存在，故无歧义）
//   - 内置 idx：1=int, 2=float, 3=string, 4=bool, 5=null, 6=array,
//               7=mixed, 8=object, 9=callback, 11=never
//
// dispatch 模式：
//   - checkNode(idx): void — 处理语句节点（递归子节点）
//   - inferType(idx): int  — 处理表达式节点（返回类型 idx，同时设置 typ 字段）
// ============================================================

class FlatTypeChecker
{
    private FlatAst $ast;
    private SymbolTable $symbols;

    /** @var array<string,Type> 当前作用域变量名（不含 $）→ Type */
    private array $scope = [];

    /** @var array<int,array<string,Type>> 作用域栈 */
    private array $scopeStack = [];

    /** 当前函数/方法的声明返回类型（用于 return 检查），null=不在函数内 */
    private ?Type $currentReturn = null;

    /** 当前类的 C 名（如 tphp_class_Foo），null=不在类内 */
    private ?string $currentClassCName = null;

    /** 当前是否在静态上下文 */
    private bool $inStaticContext = false;

    /** @var array<string,string> 变量名 → 类 C 名（用于方法调用/属性访问类型推导） */
    private array $varClassCNames = [];

    /** @var array<int,array{msg:string,pos:array}> 收集的错误列表 */
    private array $errors = [];

    /** 严格模式：访问未声明变量时报错（默认 false，PHP 动态语义回退 mixed） */
    private bool $strictUndefinedVar = false;

    public function __construct(FlatAst $ast, SymbolTable $symbols)
    {
        $this->ast = $ast;
        $this->symbols = $symbols;
    }

    // ─────────────────────────────────────────────────────────────
    // 公共入口
    // ─────────────────────────────────────────────────────────────

    /**
     * 检查整个 FlatAst：先 prescan 注册符号，再从 root 开始 checkNode。
     * 填充节点的 typ 字段，收集错误。
     */
    public function check(): void
    {
        $this->prescan();
        if ($this->ast->root >= 0) {
            $this->checkNode($this->ast->root);
        }
    }

    /** @return array<int,array{msg:string,pos:array}> 收集到的错误列表 */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function setStrictUndefinedVar(bool $v): void
    {
        $this->strictUndefinedVar = $v;
    }

    // ─────────────────────────────────────────────────────────────
    // prescan：注册函数/类/方法/属性到 SymbolTable
    //   遍历 ProgramNode 的 children，按 FlatAstConverter.visitProgram 的
    //   顺序（mainClass → extraClasses → functions → constants → enums）处理
    // ─────────────────────────────────────────────────────────────

    private function prescan(): void
    {
        if ($this->ast->root < 0) return;
        $root = $this->ast->nodes[$this->ast->root];
        if ($root['kind'] !== NodeKind::ProgramNode) return;

        $extra = $root['extra'];
        $offset = 0;

        $hasMainClass = $extra['hasMainClass'] ?? false;
        if ($hasMainClass) {
            $this->registerClass($this->ast->child($this->ast->root, $offset));
            $offset++;
        }

        $extraClassCount = $extra['extraClassCount'] ?? 0;
        for ($i = 0; $i < $extraClassCount; $i++) {
            $this->registerClass($this->ast->child($this->ast->root, $offset));
            $offset++;
        }

        $functionCount = $extra['functionCount'] ?? 0;
        for ($i = 0; $i < $functionCount; $i++) {
            $this->registerFunction($this->ast->child($this->ast->root, $offset));
            $offset++;
        }
        // constants/enums 的注册对本 checker 当前测试不关键，略过
    }

    private function registerClass(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $name = (string)($node['value'] ?? '');
        $cn = $this->classCNameFromName($name);

        $parentName = $extra['parentName'] ?? null;
        $parentCN = ($parentName !== null && $parentName !== '')
            ? $this->classCNameFromName($parentName)
            : '';

        $this->symbols->addClass(
            $cn, $parentCN,
            $extra['isAbstract'] ?? false,
            $extra['implements'] ?? [],
            $extra['isReadonly'] ?? false,
            $extra['isFinal'] ?? false,
            $extra['isInterface'] ?? false,
        );
        $this->symbols->addClassName($name, $cn);

        // Children 顺序：attributes, properties, methods, classConsts
        $attributeCount = $extra['attributeCount'] ?? 0;
        $propertyCount  = $extra['propertyCount'] ?? 0;
        $methodCount    = $extra['methodCount'] ?? 0;

        $offset = $attributeCount;
        for ($i = 0; $i < $propertyCount; $i++) {
            $this->registerProperty($cn, $this->ast->child($idx, $offset + $i));
        }
        $offset += $propertyCount;
        for ($i = 0; $i < $methodCount; $i++) {
            $this->registerMethod($cn, $this->ast->child($idx, $offset + $i));
        }
    }

    private function registerProperty(string $cn, int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $name = ltrim((string)($node['value'] ?? ''), '$');
        $extra = $node['extra'];
        $type = $extra['type'] ?? '';
        $isStatic = $extra['isStatic'] ?? false;
        $cType = $this->phpTypeToCType($type);
        $this->symbols->addClassProp($cn, $name, $cType, true, $isStatic);
    }

    private function registerMethod(string $cn, int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $name = (string)($node['value'] ?? '');
        $retType = $this->phpTypeToCType($extra['returnType'] ?? '');

        $attributeCount = $extra['attributeCount'] ?? 0;
        $paramCount     = $extra['paramCount'] ?? 0;
        $paramTypes = [];
        $paramNames = [];
        $isVariadic = false;
        for ($i = 0; $i < $paramCount; $i++) {
            $pIdx = $this->ast->child($idx, $attributeCount + $i);
            $pNode = $this->ast->nodes[$pIdx];
            $pExtra = $pNode['extra'];
            if ($pExtra['isVariadic'] ?? false) {
                $paramTypes[] = 't_array*';
                $isVariadic = true;
            } else {
                $paramTypes[] = $this->phpTypeToCType($pExtra['type'] ?? '');
            }
            $paramNames[] = ltrim((string)($pNode['value'] ?? ''), '$');
        }

        $this->symbols->addClassMethod($cn, $name, new MethodInfo(
            $retType, $paramTypes,
            $extra['isStatic'] ?? false,
            $extra['visibility'] ?? 'public',
            0, $paramCount,
            $extra['isFinal'] ?? false,
            !($extra['hasBody'] ?? true),
            $paramNames, $isVariadic,
            $extra['isStaticDispatch'] ?? false,
        ));
    }

    private function registerFunction(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        if ($extra['isCDeclaration'] ?? false) return;

        $name = (string)($node['value'] ?? '');
        $retType = $this->phpTypeToCType($extra['returnType'] ?? '');

        $attributeCount = $extra['attributeCount'] ?? 0;
        $paramCount     = $extra['paramCount'] ?? 0;
        $paramTypes = [];
        $paramNames = [];
        $isVariadic = false;
        for ($i = 0; $i < $paramCount; $i++) {
            $pIdx = $this->ast->child($idx, $attributeCount + $i);
            $pNode = $this->ast->nodes[$pIdx];
            $pExtra = $pNode['extra'];
            if ($pExtra['isVariadic'] ?? false) {
                $paramTypes[] = 't_array*';
                $isVariadic = true;
            } else {
                $paramTypes[] = $this->phpTypeToCType($pExtra['type'] ?? '');
            }
            $paramNames[] = ltrim((string)($pNode['value'] ?? ''), '$');
        }

        $fnCName = 'tphp_fn_' . $name;
        $this->symbols->addFunc($fnCName, new FunctionInfo(
            $retType, $paramTypes, 0, $paramCount,
            $extra['isGenerator'] ?? false,
            $paramNames, $isVariadic,
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // checkNode：按 NodeKind 分发
    //   - 语句节点：递归子节点（不返回值）
    //   - 表达式节点：调用 inferType（设置 typ 字段）
    // ─────────────────────────────────────────────────────────────

    public function checkNode(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        match ($node['kind']) {
            // === 顶层结构 ===
            NodeKind::ProgramNode      => $this->checkProgram($idx),
            NodeKind::FunctionNode     => $this->checkFunction($idx),
            NodeKind::ClassNode        => $this->checkClass($idx),
            NodeKind::MethodNode       => $this->checkMethod($idx),
            NodeKind::PropertyDeclNode => $this->checkPropertyDecl($idx),
            NodeKind::ParamNode        => $this->checkParam($idx),
            NodeKind::ConstNode        => $this->checkConstNode($idx),
            NodeKind::EnumNode,
            NodeKind::EnumCaseNode,
            NodeKind::AttributeDeclNode,
            NodeKind::AttributeUseNode,
            NodeKind::PropertyHook     => null,

            // === 语句 ===
            NodeKind::EchoStmtNode           => $this->checkEcho($idx),
            NodeKind::ReturnStmtNode         => $this->checkReturn($idx),
            NodeKind::AssignStmtNode         => $this->checkAssign($idx),
            NodeKind::AssignPropStmtNode     => $this->checkAssignProp($idx),
            NodeKind::AssignArrayStmtNode    => $this->checkAssignArray($idx),
            NodeKind::AssignArrayPushStmtNode => $this->checkAssignArrayPush($idx),
            NodeKind::IfStmtNode             => $this->checkIf($idx),
            NodeKind::ElseIfBranch           => $this->checkElseIf($idx),
            NodeKind::WhileStmtNode          => $this->checkWhile($idx),
            NodeKind::DoWhileStmtNode        => $this->checkDoWhile($idx),
            NodeKind::ForStmtNode            => $this->checkFor($idx),
            NodeKind::ForeachStmtNode        => $this->checkForeach($idx),
            NodeKind::SwitchStmtNode         => $this->checkSwitch($idx),
            NodeKind::CaseBranch             => $this->checkCaseBranch($idx),
            NodeKind::BlockStmtNode          => $this->checkBlock($idx),
            NodeKind::ExprStmtNode           => $this->checkExprStmt($idx),
            NodeKind::ConstStmtNode          => $this->checkConstStmt($idx),
            NodeKind::StaticStmtNode         => $this->checkStatic($idx),
            NodeKind::TryStmtNode            => $this->checkTry($idx),
            NodeKind::ThrowStmtNode          => $this->checkThrow($idx),
            NodeKind::DeferStmtNode          => $this->checkDefer($idx),
            NodeKind::NopStmtNode,
            NodeKind::BreakStmtNode,
            NodeKind::ContinueStmtNode,
            NodeKind::GotoStmtNode,
            NodeKind::LabelStmtNode          => null,

            // === 表达式 → 委托给 inferType ===
            NodeKind::IntLiteralExpr,
            NodeKind::StringLiteralExpr,
            NodeKind::FloatLiteralExpr,
            NodeKind::BoolLiteralExpr,
            NodeKind::NullLiteralExpr,
            NodeKind::MagicConstExpr,
            NodeKind::VariableExpr,
            NodeKind::BinaryExpr,
            NodeKind::UnaryExpr,
            NodeKind::PostfixExpr,
            NodeKind::CompoundAssignExpr,
            NodeKind::TernaryExpr,
            NodeKind::NullCoalesceExpr,
            NodeKind::CallExpr,
            NodeKind::CastExpr,
            NodeKind::NewExpr,
            NodeKind::ClosureExpr,
            NodeKind::ArrayLiteralExpr,
            NodeKind::ArrayEntryNode,
            NodeKind::ArrayAccessExpr,
            NodeKind::ArrayAppendExpr,
            NodeKind::PropertyAccessExpr,
            NodeKind::EnumAccessExpr,
            NodeKind::MatchExpr,
            NodeKind::MatchArm,
            NodeKind::YieldExpr,
            NodeKind::YieldFromExpr,
            NodeKind::ThrowExprNode,
            NodeKind::OrBlockExpr,
            NodeKind::PipeExpr,
            NodeKind::PlaceholderExpr,
            NodeKind::CallableConvertExpr   => $this->inferType($idx),

            default                          => null,
        };
    }

    // ─────────────────────────────────────────────────────────────
    // inferType：按 NodeKind 推导表达式类型，设置 typ 字段，返回类型 idx
    // ─────────────────────────────────────────────────────────────

    public function inferType(int $idx): int
    {
        $node = $this->ast->nodes[$idx];
        $t = match ($node['kind']) {
            NodeKind::IntLiteralExpr      => Type::$int,
            NodeKind::StringLiteralExpr   => Type::$string,
            NodeKind::FloatLiteralExpr    => Type::$float,
            NodeKind::BoolLiteralExpr     => Type::$bool,
            NodeKind::NullLiteralExpr     => Type::$null,
            NodeKind::MagicConstExpr      => $node['value'] === '__LINE__' ? Type::$int : Type::$string,
            NodeKind::VariableExpr        => $this->inferVariable($idx),
            NodeKind::BinaryExpr          => $this->inferBinary($idx),
            NodeKind::UnaryExpr           => $this->inferUnary($idx),
            NodeKind::PostfixExpr         => $this->inferPostfix($idx),
            NodeKind::CompoundAssignExpr  => $this->inferCompoundAssign($idx),
            NodeKind::TernaryExpr         => $this->inferTernary($idx),
            NodeKind::NullCoalesceExpr    => $this->inferNullCoalesce($idx),
            NodeKind::CallExpr            => $this->inferCall($idx),
            NodeKind::CastExpr            => $this->inferCast($idx),
            NodeKind::NewExpr             => $this->inferNew($idx),
            NodeKind::ClosureExpr         => $this->inferClosure($idx),
            NodeKind::ArrayLiteralExpr    => $this->inferArrayLiteral($idx),
            NodeKind::ArrayAccessExpr     => $this->inferArrayAccess($idx),
            NodeKind::ArrayAppendExpr     => Type::$array,
            NodeKind::PropertyAccessExpr  => $this->inferPropertyAccess($idx),
            NodeKind::EnumAccessExpr      => Type::$object,
            NodeKind::MatchExpr           => $this->inferMatch($idx),
            NodeKind::ArrayEntryNode      => $this->inferArrayEntry($idx),
            default                       => Type::$mixed,
        };
        $this->setTyp($idx, $t);
        return $t->idx();
    }

    // ═══════════════════════════════════════════════════════════════
    // 顶层结构 check 方法
    // ═══════════════════════════════════════════════════════════════

    private function checkProgram(int $idx): void
    {
        $extra = $this->ast->nodes[$idx]['extra'];
        $offset = 0;
        $hasMainClass = $extra['hasMainClass'] ?? false;
        if ($hasMainClass) {
            $this->checkClass($this->ast->child($idx, $offset));
            $offset++;
        }
        $extraClassCount = $extra['extraClassCount'] ?? 0;
        for ($i = 0; $i < $extraClassCount; $i++) {
            $this->checkClass($this->ast->child($idx, $offset));
            $offset++;
        }
        $functionCount = $extra['functionCount'] ?? 0;
        for ($i = 0; $i < $functionCount; $i++) {
            $this->checkFunction($this->ast->child($idx, $offset));
            $offset++;
        }
        // constants/enums 不在此检查（无变量作用域相关内容）
    }

    private function checkFunction(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        if ($extra['isCDeclaration'] ?? false) return;

        $savedReturn = $this->currentReturn;
        $this->currentReturn = $this->resolveTypeFromString($extra['returnType'] ?? '');

        $this->pushScope();

        $attributeCount = $extra['attributeCount'] ?? 0;
        $paramCount     = $extra['paramCount'] ?? 0;
        // 声明参数到作用域
        for ($i = 0; $i < $paramCount; $i++) {
            $this->checkParam($this->ast->child($idx, $attributeCount + $i));
        }
        // 函数体（参数之后的子节点）
        $bodyStart = $attributeCount + $paramCount;
        $bodyEnd   = $this->ast->childCount($idx);
        for ($i = $bodyStart; $i < $bodyEnd; $i++) {
            $this->checkNode($this->ast->child($idx, $i));
        }

        $this->popScope();
        $this->currentReturn = $savedReturn;
    }

    private function checkClass(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $savedClass  = $this->currentClassCName;
        $savedStatic = $this->inStaticContext;

        $this->currentClassCName = $this->classCNameFromName((string)($node['value'] ?? ''));
        $this->inStaticContext   = false;

        $attributeCount = $extra['attributeCount'] ?? 0;
        $propertyCount  = $extra['propertyCount'] ?? 0;
        $methodCount    = $extra['methodCount'] ?? 0;

        $offset = $attributeCount;
        // 属性默认值检查
        for ($i = 0; $i < $propertyCount; $i++) {
            $this->checkPropertyDecl($this->ast->child($idx, $offset + $i));
        }
        $offset += $propertyCount;
        // 方法检查
        for ($i = 0; $i < $methodCount; $i++) {
            $this->checkMethod($this->ast->child($idx, $offset + $i));
        }

        $this->currentClassCName = $savedClass;
        $this->inStaticContext   = $savedStatic;
    }

    private function checkMethod(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $savedStatic  = $this->inStaticContext;
        $savedReturn  = $this->currentReturn;

        $this->inStaticContext = $extra['isStatic'] ?? false;
        $this->currentReturn   = $this->resolveTypeFromString($extra['returnType'] ?? '');

        $this->pushScope();

        // 非静态方法声明 $this
        if (!($extra['isStatic'] ?? false) && $this->currentClassCName !== null) {
            $this->declareVar('this', Type::$object);
        }

        $attributeCount = $extra['attributeCount'] ?? 0;
        $paramCount     = $extra['paramCount'] ?? 0;
        $promotedCount  = $extra['promotedCount'] ?? 0;

        for ($i = 0; $i < $paramCount; $i++) {
            $this->checkParam($this->ast->child($idx, $attributeCount + $i));
        }
        // promoted 参数（构造器提升），跳过 promoted 段后是 body
        $bodyStart = $attributeCount + $paramCount + $promotedCount;
        $bodyEnd   = $this->ast->childCount($idx);

        // 无 body 的方法（abstract / interface）
        if ($extra['hasBody'] ?? true) {
            for ($i = $bodyStart; $i < $bodyEnd; $i++) {
                $this->checkNode($this->ast->child($idx, $i));
            }
        }

        $this->popScope();
        $this->inStaticContext = $savedStatic;
        $this->currentReturn   = $savedReturn;
    }

    private function checkParam(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $name = ltrim((string)($node['value'] ?? ''), '$');

        if ($extra['isVariadic'] ?? false) {
            $this->declareVar($name, Type::array(Type::$mixed));
            // 默认值检查
            if ($extra['hasDefault'] ?? false) {
                $this->inferType($this->ast->child($idx, 0));
            }
            return;
        }

        $typeStr = $extra['type'] ?? '';
        $type = $this->resolveTypeFromString($typeStr);
        $this->declareVar($name, $type);

        // 默认值检查
        if ($extra['hasDefault'] ?? false) {
            $this->inferType($this->ast->child($idx, 0));
        }
    }

    private function checkPropertyDecl(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        // 属性默认值
        if ($extra['hasDefault'] ?? false) {
            $this->inferType($this->ast->child($idx, 0));
        }
        // hooks 不深入检查（PropertyHook 子节点）
    }

    private function checkConstNode(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        if ($extra['hasValue'] ?? false) {
            $this->inferType($this->ast->child($idx, 0));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 语句 check 方法
    // ═══════════════════════════════════════════════════════════════

    private function checkEcho(int $idx): void
    {
        $count = $this->ast->childCount($idx);
        for ($i = 0; $i < $count; $i++) {
            $this->inferType($this->ast->child($idx, $i));
        }
    }

    private function checkReturn(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        if (!($extra['hasExpr'] ?? false)) {
            // return; — 检查当前函数声明是否为 void/null
            if ($this->currentReturn !== null
                && !$this->currentReturn->isVoid()
                && !$this->currentReturn->equals(Type::$null)
                && !$this->currentReturn->isMixed()) {
                $this->error("Empty return in function declared to return {$this->currentReturn}", $idx);
            }
            return;
        }

        $exprIdx = $this->ast->child($idx, 0);
        $t = $this->inferType($exprIdx);
        $exprType = $this->typeFromIdx($t);

        // 类型检查：返回类型必须与函数声明的返回类型匹配
        if ($this->currentReturn !== null
            && !$this->currentReturn->isMixed()
            && !$this->currentReturn->isVoid()
            && !$exprType->equals($this->currentReturn)) {
            // 允许 int 返回 float 函数？暂不放宽，按严格匹配
            $this->error(
                "Return type mismatch: declared {$this->currentReturn}, got {$exprType}",
                $idx,
            );
        }
    }

    private function checkAssign(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $varName = ltrim((string)($node['value'] ?? ''), '$');
        $extra = $node['extra'];
        $declTypeStr = $extra['type'] ?? null;

        $rhsIdx = $this->ast->child($idx, 0);
        $rhsTypeIdx = $this->inferType($rhsIdx);
        $rhsType = $this->typeFromIdx($rhsTypeIdx);

        // 有显式类型标注
        if ($declTypeStr !== null && $declTypeStr !== '') {
            $declType = $this->resolveTypeFromString($declTypeStr);
            // 类型不匹配检查（mixed 标注或 mixed 右式跳过）
            if (!$declType->isMixed()
                && !$rhsType->isMixed()
                && !$rhsType->equals($declType)) {
                $this->error(
                    "Cannot assign {$rhsType} to {$declType} variable \${$varName}",
                    $idx,
                );
            }
            $varType = $declType;
        } else {
            $varType = $rhsType;
        }

        $this->declareVar($varName, $varType);

        // 跟踪 new X() 赋值 → 类 C 名
        $rhsNode = $this->ast->nodes[$rhsIdx];
        if ($rhsNode['kind'] === NodeKind::NewExpr) {
            $className = (string)($rhsNode['value'] ?? '');
            $cName = $this->classCNameFromName($className);
            if ($this->symbols->hasClass($cName)) {
                $this->varClassCNames[$varName] = $cName;
            } else {
                unset($this->varClassCNames[$varName]);
            }
        } else {
            unset($this->varClassCNames[$varName]);
        }
    }

    private function checkAssignProp(int $idx): void
    {
        $targetIdx = $this->ast->child($idx, 0);
        $valueIdx  = $this->ast->child($idx, 1);
        $this->inferType($targetIdx);
        $this->inferType($valueIdx);
    }

    private function checkAssignArray(int $idx): void
    {
        $targetIdx = $this->ast->child($idx, 0);
        $valueIdx  = $this->ast->child($idx, 1);
        $this->inferType($targetIdx);
        $this->inferType($valueIdx);
    }

    private function checkAssignArrayPush(int $idx): void
    {
        $targetIdx = $this->ast->child($idx, 0);
        $valueIdx  = $this->ast->child($idx, 1);
        $this->inferType($targetIdx);
        $this->inferType($valueIdx);

        // 如果 target 是未声明的 VariableExpr，声明为 array
        $targetNode = $this->ast->nodes[$targetIdx];
        if ($targetNode['kind'] === NodeKind::VariableExpr) {
            $vn = ltrim((string)($targetNode['value'] ?? ''), '$');
            if ($this->lookupVar($vn) === null) {
                $this->declareVar($vn, Type::$array);
            }
        }
    }

    private function checkIf(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $thenBodyCount = $extra['thenBodyCount'] ?? 0;
        $elseifCount   = $extra['elseifCount'] ?? 0;
        $elseBodyCount = $extra['elseBodyCount'] ?? 0;

        // children: [condition, ...thenBody, ...elseifs, ...elseBody]
        $this->inferType($this->ast->child($idx, 0));

        $offset = 1;
        $this->pushScope();
        for ($i = 0; $i < $thenBodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, $offset + $i));
        }
        $this->popScope();
        $offset += $thenBodyCount;

        for ($i = 0; $i < $elseifCount; $i++) {
            $this->checkElseIf($this->ast->child($idx, $offset + $i));
        }
        $offset += $elseifCount;

        if ($elseBodyCount > 0) {
            $this->pushScope();
            for ($i = 0; $i < $elseBodyCount; $i++) {
                $this->checkNode($this->ast->child($idx, $offset + $i));
            }
            $this->popScope();
        }
    }

    private function checkElseIf(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $bodyCount = $node['extra']['bodyCount'] ?? 0;
        // children: [condition, ...body]
        $this->inferType($this->ast->child($idx, 0));
        $this->pushScope();
        for ($i = 0; $i < $bodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, 1 + $i));
        }
        $this->popScope();
    }

    private function checkWhile(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $bodyCount = $node['extra']['bodyCount'] ?? 0;
        $this->inferType($this->ast->child($idx, 0));
        $this->pushScope();
        for ($i = 0; $i < $bodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, 1 + $i));
        }
        $this->popScope();
    }

    private function checkDoWhile(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $bodyCount = $node['extra']['bodyCount'] ?? 0;
        $this->pushScope();
        for ($i = 0; $i < $bodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, 1 + $i));
        }
        $this->popScope();
        $this->inferType($this->ast->child($idx, 0));
    }

    private function checkFor(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $hasInit      = $extra['hasInit'] ?? false;
        $hasCondition = $extra['hasCondition'] ?? false;
        $hasStep      = $extra['hasStep'] ?? false;
        $bodyCount    = $extra['bodyCount'] ?? 0;

        $offset = 0;
        if ($hasInit) {
            $initIdx = $this->ast->child($idx, $offset);
            $this->inferType($initIdx);
            // for ($i = 0; ...) init 是 BinaryExpr($i, '=', 0)，需声明 $i
            $this->declareFromForInit($initIdx);
            $offset++;
        }
        if ($hasCondition) {
            $this->inferType($this->ast->child($idx, $offset));
            $offset++;
        }
        if ($hasStep) {
            $this->inferType($this->ast->child($idx, $offset));
            $offset++;
        }
        $this->pushScope();
        for ($i = 0; $i < $bodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, $offset + $i));
        }
        $this->popScope();
    }

    /** for-init 赋值：$i = expr → 声明 $i 类型 */
    private function declareFromForInit(int $initIdx): void
    {
        $node = $this->ast->nodes[$initIdx];
        if ($node['kind'] !== NodeKind::BinaryExpr) return;
        if ($node['value'] !== '=') return;
        $leftIdx = $this->ast->child($initIdx, 0);
        $leftNode = $this->ast->nodes[$leftIdx];
        if ($leftNode['kind'] !== NodeKind::VariableExpr) return;
        $vn = ltrim((string)($leftNode['value'] ?? ''), '$');
        $rightType = $this->typeFromIdx($this->ast->nodes[$this->ast->child($initIdx, 1)]['typ'] ?? 0);
        $this->declareVar($vn, $rightType);
    }

    private function checkForeach(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $valueVar = ltrim((string)($extra['valueVar'] ?? ''), '$');
        $keyVar   = ltrim((string)($extra['keyVar'] ?? ''), '$');
        $bodyCount = $extra['bodyCount'] ?? 0;

        $arrIdx = $this->ast->child($idx, 0);
        $arrTypeIdx = $this->inferType($arrIdx);
        $arrType = $this->typeFromIdx($arrTypeIdx);
        $elemType = ($arrType->isArray() && $arrType->elemType() !== null)
            ? $arrType->elemType()
            : Type::$mixed;

        $this->pushScope();
        if ($keyVar !== '') {
            $this->declareVar($keyVar, Type::$mixed);
        }
        if ($valueVar !== '') {
            $this->declareVar($valueVar, $elemType);
        }
        for ($i = 0; $i < $bodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, 1 + $i));
        }
        $this->popScope();
    }

    private function checkSwitch(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $caseCount = $node['extra']['caseCount'] ?? 0;
        $this->inferType($this->ast->child($idx, 0));
        for ($i = 0; $i < $caseCount; $i++) {
            $this->checkCaseBranch($this->ast->child($idx, 1 + $i));
        }
    }

    private function checkCaseBranch(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $hasValue  = $extra['hasValue'] ?? false;
        $bodyCount = $extra['bodyCount'] ?? 0;

        $offset = 0;
        if ($hasValue) {
            $this->inferType($this->ast->child($idx, 0));
            $offset = 1;
        }
        $this->pushScope();
        for ($i = 0; $i < $bodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, $offset + $i));
        }
        $this->popScope();
    }

    private function checkBlock(int $idx): void
    {
        $count = $this->ast->childCount($idx);
        $this->pushScope();
        for ($i = 0; $i < $count; $i++) {
            $this->checkNode($this->ast->child($idx, $i));
        }
        $this->popScope();
    }

    private function checkExprStmt(int $idx): void
    {
        $this->inferType($this->ast->child($idx, 0));
    }

    private function checkConstStmt(int $idx): void
    {
        $this->inferType($this->ast->child($idx, 0));
    }

    private function checkStatic(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $varName = ltrim((string)($node['value'] ?? ''), '$');
        $typeStr = $extra['type'] ?? '';
        $varType = $this->resolveTypeFromString($typeStr);

        if ($extra['hasInit'] ?? false) {
            $initIdx = $this->ast->child($idx, 0);
            $initTypeIdx = $this->inferType($initIdx);
            if ($typeStr === '' || $typeStr === 'mixed') {
                $varType = $this->typeFromIdx($initTypeIdx);
            }
        }
        $this->declareVar($varName, $varType);
    }

    private function checkTry(int $idx): void
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $tryBodyCount = $extra['tryBodyCount'] ?? 0;
        $finallyBodyCount = $extra['finallyBodyCount'] ?? 0;

        // children: [...tryBody, ...finallyBody]
        $this->pushScope();
        for ($i = 0; $i < $tryBodyCount; $i++) {
            $this->checkNode($this->ast->child($idx, $i));
        }
        $this->popScope();
        // catchClauses 的 body 索引在 extra['catches'][k]['bodyIndices'] 中
        $catches = $extra['catches'] ?? [];
        foreach ($catches as $clause) {
            $this->pushScope();
            if (!empty($clause['var'])) {
                $cv = ltrim((string)$clause['var'], '$');
                $this->declareVar($cv, Type::$object);
            }
            foreach ($clause['bodyIndices'] as $bodyIdx) {
                $this->checkNode($bodyIdx);
            }
            $this->popScope();
        }
        if ($finallyBodyCount > 0) {
            $offset = $tryBodyCount;
            $this->pushScope();
            for ($i = 0; $i < $finallyBodyCount; $i++) {
                $this->checkNode($this->ast->child($idx, $offset + $i));
            }
            $this->popScope();
        }
    }

    private function checkThrow(int $idx): void
    {
        $this->inferType($this->ast->child($idx, 0));
    }

    private function checkDefer(int $idx): void
    {
        $count = $this->ast->childCount($idx);
        for ($i = 0; $i < $count; $i++) {
            $this->checkNode($this->ast->child($idx, $i));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 表达式 infer 方法
    // ═══════════════════════════════════════════════════════════════

    private function inferVariable(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $name = ltrim((string)($node['value'] ?? ''), '$');

        if ($name === 'this') {
            return Type::$object;
        }

        $t = $this->lookupVar($name);
        if ($t !== null) {
            return $t;
        }

        if ($this->strictUndefinedVar) {
            $this->error("Undefined variable \${$name}", $idx);
        }
        return Type::$mixed;
    }

    private function inferBinary(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $op = (string)($node['value'] ?? '');

        $leftIdx  = $this->ast->child($idx, 0);
        $rightIdx = $this->ast->child($idx, 1);
        $lt = $this->typeFromIdx($this->inferType($leftIdx));
        $rt = $this->typeFromIdx($this->inferType($rightIdx));

        // for-init 赋值: $i = expr → 声明循环变量
        if ($op === '=') {
            $leftNode = $this->ast->nodes[$leftIdx];
            if ($leftNode['kind'] === NodeKind::VariableExpr) {
                $vn = ltrim((string)($leftNode['value'] ?? ''), '$');
                $this->declareVar($vn, $rt);
            }
            return $rt;
        }

        if ($op === '.') {
            return Type::$string;
        }
        if ($op === '<=>') {
            return Type::$int;
        }
        if ($op === '**') {
            return $lt;
        }
        if (in_array($op, ['<', '>', '<=', '>=', '==', '!=', '===', '!==', '&&', '||', 'instanceof'], true)) {
            return Type::$bool;
        }

        // 算术/位运算：int+int=int, int+float=float, mixed → 另一侧类型
        $lMixed = $lt->isMixed();
        $rMixed = $rt->isMixed();
        if ($lMixed && $rMixed) return Type::$int;
        if ($lMixed) return $rt;
        if ($rMixed) return $lt;
        // int + float = float（取更宽的类型）
        if ($lt->idx() === Type::IDX_INT && $rt->idx() === Type::IDX_FLOAT) return Type::$float;
        if ($rt->idx() === Type::IDX_INT && $lt->idx() === Type::IDX_FLOAT) return Type::$float;
        return $lt;
    }

    private function inferUnary(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $op = (string)($node['value'] ?? '');
        $operandIdx = $this->ast->child($idx, 0);
        $t = $this->typeFromIdx($this->inferType($operandIdx));

        // ! → bool, -/+ → 保持原类型（int/float）, ~ → int
        if ($op === '!') return Type::$bool;
        return $t;
    }

    private function inferPostfix(int $idx): Type
    {
        // $x++ / $x-- → 原变量类型（int 默认）
        $operandIdx = $this->ast->child($idx, 0);
        $t = $this->typeFromIdx($this->inferType($operandIdx));
        return $t->isMixed() ? Type::$int : $t;
    }

    private function inferCompoundAssign(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $op = (string)($node['value'] ?? '');
        $leftIdx  = $this->ast->child($idx, 0);
        $rightIdx = $this->ast->child($idx, 1);
        $lt = $this->typeFromIdx($this->inferType($leftIdx));
        $rt = $this->typeFromIdx($this->inferType($rightIdx));

        // .= → string, 其他算术复合赋值 → 算术规则
        if ($op === '.=') return Type::$string;
        if ($lt->isMixed()) return $rt;
        if ($rt->isMixed()) return $lt;
        if ($lt->idx() === Type::IDX_INT && $rt->idx() === Type::IDX_FLOAT) return Type::$float;
        if ($rt->idx() === Type::IDX_INT && $lt->idx() === Type::IDX_FLOAT) return Type::$float;
        return $lt;
    }

    private function inferTernary(int $idx): Type
    {
        $this->inferType($this->ast->child($idx, 0));
        $thenType = $this->typeFromIdx($this->inferType($this->ast->child($idx, 1)));
        $elseType = $this->typeFromIdx($this->inferType($this->ast->child($idx, 2)));
        return $this->commonType($thenType, $elseType);
    }

    private function inferNullCoalesce(int $idx): Type
    {
        $lt = $this->typeFromIdx($this->inferType($this->ast->child($idx, 0)));
        $rt = $this->typeFromIdx($this->inferType($this->ast->child($idx, 1)));
        $common = $this->commonType($lt, $rt);
        return $common->isMixed() && !$rt->isMixed() ? $rt : $common;
    }

    private function inferCall(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];

        // 先 infer callee 和 args
        $childCount = $this->ast->childCount($idx);
        $argStart = 0;
        if ($extra['hasCallee'] ?? false) {
            $this->inferType($this->ast->child($idx, 0));
            $argStart = 1;
        }
        for ($i = $argStart; $i < $childCount; $i++) {
            $this->inferType($this->ast->child($idx, $i));
        }

        // C 函数调用
        if ($extra['isRawC'] ?? false) {
            $name = (string)($node['value'] ?? '');
            $cFunc = $this->symbols->getCFunction($name);
            if ($cFunc !== null) {
                return $this->resolveCTypeToType($cFunc->retType);
            }
            return Type::$mixed;
        }

        // 方法调用（callee !== null）
        if ($extra['hasCallee'] ?? false) {
            return $this->inferMethodCallReturnType($idx);
        }

        // 全局函数调用
        return $this->inferGlobalCallReturnType($idx);
    }

    private function inferMethodCallReturnType(int $callIdx): Type
    {
        $callNode = $this->ast->nodes[$callIdx];
        $methodName = (string)($callNode['value'] ?? '');

        $calleeIdx = $this->ast->child($callIdx, 0);
        $calleeNode = $this->ast->nodes[$calleeIdx];

        // 仅处理 $obj->method() 形式
        if ($calleeNode['kind'] === NodeKind::VariableExpr) {
            $varName = ltrim((string)($calleeNode['value'] ?? ''), '$');
            $cName = $this->varClassCNames[$varName] ?? null;
            if ($cName !== null && $this->symbols->hasClass($cName)) {
                $m = $this->symbols->getClassMethod($cName, $methodName);
                if ($m !== null) {
                    return $this->resolveCTypeToType($m->retType);
                }
            }
        } elseif ($calleeNode['kind'] === NodeKind::PropertyAccessExpr) {
            // $obj->prop->method() — 简化处理，按属性类型查方法
            $propType = $this->typeFromIdx($this->inferType($calleeIdx));
            if ($propType->idx() === Type::IDX_OBJECT) {
                // 不深入追踪属性类名，回退 mixed
            }
        }

        return Type::$mixed;
    }

    private function inferGlobalCallReturnType(int $callIdx): Type
    {
        $callNode = $this->ast->nodes[$callIdx];
        $name = (string)($callNode['value'] ?? '');

        // 前缀规则
        if (str_starts_with($name, 'is_') || str_starts_with($name, 'ctype_')) {
            return Type::$bool;
        }

        // 查符号表
        $fnCName = 'tphp_fn_' . $name;
        $ret = $this->symbols->getFuncRet($fnCName);
        if ($ret !== null) {
            return $this->resolveCTypeToType($ret);
        }

        // 命名空间 fallback
        if (($pos = strrpos($name, '\\')) !== false) {
            $baseName = substr($name, $pos + 1);
            if (str_starts_with($baseName, 'is_') || str_starts_with($baseName, 'ctype_')) {
                return Type::$bool;
            }
            $globalCName = 'tphp_fn_' . $baseName;
            $ret2 = $this->symbols->getFuncRet($globalCName);
            if ($ret2 !== null) {
                return $this->resolveCTypeToType($ret2);
            }
        }

        return Type::$mixed;
    }

    private function inferCast(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $ct = (string)($node['value'] ?? '');
        $this->inferType($this->ast->child($idx, 0));
        return match (true) {
            in_array($ct, ['int', 'integer'], true)            => Type::$int,
            in_array($ct, ['float', 'double'], true)           => Type::$float,
            in_array($ct, ['string', 'binary'], true)          => Type::$string,
            in_array($ct, ['bool', 'boolean'], true)           => Type::$bool,
            $ct === 'array'                                    => Type::$array,
            $ct === 'object'                                   => Type::$object,
            $ct === 'unset'                                    => Type::$null,
            default                                             => Type::$mixed,
        };
    }

    private function inferNew(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $className = (string)($node['value'] ?? '');

        // 接口/抽象类不可实例化（简单检查）
        $cName = $this->classCNameFromName($className);
        if ($this->symbols->hasClass($cName)) {
            if ($this->symbols->isClassInterface($cName)) {
                $this->error("Cannot instantiate interface {$className}", $idx);
            } elseif ($this->symbols->isClassAbstract($cName)) {
                $this->error("Cannot instantiate abstract class {$className}", $idx);
            }
        }

        // 推导参数
        $count = $this->ast->childCount($idx);
        for ($i = 0; $i < $count; $i++) {
            $this->inferType($this->ast->child($idx, $i));
        }

        return Type::$object;
    }

    private function inferClosure(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $extra = $node['extra'];
        $paramCount = $extra['paramCount'] ?? 0;

        $this->pushScope();
        for ($i = 0; $i < $paramCount; $i++) {
            $this->checkParam($this->ast->child($idx, $i));
        }
        $bodyStart = $paramCount;
        $bodyEnd   = $this->ast->childCount($idx);
        for ($i = $bodyStart; $i < $bodyEnd; $i++) {
            $this->checkNode($this->ast->child($idx, $i));
        }
        $this->popScope();

        return Type::$callback;
    }

    private function inferArrayLiteral(int $idx): Type
    {
        $count = $this->ast->childCount($idx);
        $elemTypes = [];
        for ($i = 0; $i < $count; $i++) {
            $childIdx = $this->ast->child($idx, $i);
            $childNode = $this->ast->nodes[$childIdx];
            if ($childNode['kind'] === NodeKind::ArrayEntryNode) {
                // children: [key?, value]
                $valueOffset = ($childNode['extra']['hasKey'] ?? false) ? 1 : 0;
                $elemTypes[] = $this->typeFromIdx($this->inferType($this->ast->child($childIdx, $valueOffset)));
            } else {
                $elemTypes[] = $this->typeFromIdx($this->inferType($childIdx));
            }
        }
        // 简化：默认 array<mixed>；所有元素类型一致时用 array<T>
        $elemType = Type::$mixed;
        if (!empty($elemTypes)) {
            $first = $elemTypes[0];
            $allSame = true;
            foreach ($elemTypes as $et) {
                if (!$et->equals($first)) { $allSame = false; break; }
            }
            if ($allSame) $elemType = $first;
        }
        return Type::array($elemType);
    }

    private function inferArrayEntry(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $hasKey = $node['extra']['hasKey'] ?? false;
        $offset = $hasKey ? 1 : 0;
        if ($hasKey) {
            $this->inferType($this->ast->child($idx, 0));
        }
        $valueType = $this->typeFromIdx($this->inferType($this->ast->child($idx, $offset)));
        return $valueType;
    }

    private function inferArrayAccess(int $idx): Type
    {
        // children: [array, index]
        $arrType = $this->typeFromIdx($this->inferType($this->ast->child($idx, 0)));
        $this->inferType($this->ast->child($idx, 1));
        if ($arrType->isArray() && $arrType->elemType() !== null) {
            return $arrType->elemType();
        }
        return Type::$mixed;
    }

    private function inferPropertyAccess(int $idx): Type
    {
        $node = $this->ast->nodes[$idx];
        $propName = (string)($node['value'] ?? '');
        $objIdx = $this->ast->child($idx, 0);
        $objNode = $this->ast->nodes[$objIdx];

        // $this->prop → 查当前类的属性类型
        if ($objNode['kind'] === NodeKind::VariableExpr) {
            $varName = ltrim((string)($objNode['value'] ?? ''), '$');
            if ($varName === 'this' && $this->currentClassCName !== null) {
                $ct = $this->symbols->getClassPropType($this->currentClassCName, $propName);
                if ($ct !== null) {
                    return $this->resolveCTypeToType($ct);
                }
            }
            // 其他变量 → 查 varClassCNames
            $cName = $this->varClassCNames[$varName] ?? null;
            if ($cName !== null) {
                $ct = $this->symbols->getClassPropType($cName, $propName);
                if ($ct !== null) {
                    return $this->resolveCTypeToType($ct);
                }
            }
        }

        // 先 infer 对象表达式（副作用：填充 typ）
        $this->inferType($objIdx);
        return Type::$mixed;
    }

    private function inferMatch(int $idx): Type
    {
        $this->inferType($this->ast->child($idx, 0));
        $armTypes = [];
        $armCount = $this->ast->childCount($idx) - 1;
        for ($i = 0; $i < $armCount; $i++) {
            $armIdx = $this->ast->child($idx, 1 + $i);
            $armNode = $this->ast->nodes[$armIdx];
            $valueCount = $armNode['extra']['valueCount'] ?? 0;
            // children: [...values, body]
            for ($j = 0; $j < $valueCount; $j++) {
                $this->inferType($this->ast->child($armIdx, $j));
            }
            $bodyType = $this->typeFromIdx($this->inferType($this->ast->child($armIdx, $valueCount)));
            $armTypes[] = $bodyType;
        }
        return $this->commonTypeAll($armTypes);
    }

    // ═══════════════════════════════════════════════════════════════
    // 作用域管理
    // ═══════════════════════════════════════════════════════════════

    private function pushScope(): void
    {
        $this->scopeStack[] = $this->scope;
    }

    private function popScope(): void
    {
        $this->scope = array_pop($this->scopeStack) ?? [];
    }

    private function declareVar(string $name, Type $type): void
    {
        $this->scope[$name] = $type;
    }

    private function lookupVar(string $name): ?Type
    {
        return $this->scope[$name] ?? null;
    }

    // ═══════════════════════════════════════════════════════════════
    // 类型解析辅助
    // ═══════════════════════════════════════════════════════════════

    private function setTyp(int $idx, Type $t): void
    {
        $this->ast->nodes[$idx]['typ'] = $t->idx();
    }

    private function typeFromIdx(int $idx): Type
    {
        // 内置 idx 直接构造
        if ($idx === Type::IDX_INT)    return Type::$int;
        if ($idx === Type::IDX_FLOAT)  return Type::$float;
        if ($idx === Type::IDX_STRING) return Type::$string;
        if ($idx === Type::IDX_BOOL)   return Type::$bool;
        if ($idx === Type::IDX_NULL)   return Type::$null;
        if ($idx === Type::IDX_ARRAY)  return Type::$array;
        if ($idx === Type::IDX_MIXED)  return Type::$mixed;
        if ($idx === Type::IDX_OBJECT) return Type::$object;
        if ($idx === Type::IDX_CALLBACK) return Type::$callback;
        if ($idx === Type::IDX_NEVER)  return Type::$never;
        if ($idx === Type::IDX_VOID)   return Type::$void;
        // 复合数组 idx (>= 0x10000) — 查 TypeTable
        $sym = $this->symbols->typeTable()->getByIdx($idx);
        if ($sym !== null && $sym->elemType !== null) {
            return Type::array($sym->elemType);
        }
        if ($sym !== null) {
            // 用户类型（类、枚举）→ object
            return Type::$object;
        }
        return Type::$mixed;
    }

    /**
     * 根据类型名字符串解析为 Type 对象。
     * 支持：?T, array<T>, T|Exception, mixed, int, string, ...
     */
    private function resolveTypeFromString(string $name): Type
    {
        $name = trim($name);
        if ($name === '' || $name === 'mixed') {
            return Type::$mixed;
        }

        // T|Exception 结果类型
        $pipePos = strpos($name, '|Exception');
        if ($pipePos !== false) {
            $base = $this->resolveTypeFromString(substr($name, 0, $pipePos));
            return Type::result($base);
        }

        // ?T 可选类型
        if (str_starts_with($name, '?')) {
            $base = $this->resolveTypeFromString(substr($name, 1));
            return Type::option($base);
        }

        // array<T> 泛型数组
        if (str_starts_with($name, 'array<') && str_ends_with($name, '>')) {
            $elemTypeName = substr($name, 6, -1);
            $elemType = $this->resolveTypeFromString($elemTypeName);
            return Type::array($elemType);
        }

        // 通用 array
        if ($name === 'array') {
            return Type::array(Type::$mixed);
        }

        // 内置/用户类型查询
        $t = $this->symbols->lookupType($name);
        if ($t !== null) {
            return $t;
        }

        // 尝试作为类名查询
        $cName = $this->symbols->resolveClass($name);
        if ($cName !== null) {
            return Type::$object;
        }

        return Type::$mixed;
    }

    /**
     * 将 C 类型字符串转换为 Type 对象。
     * 同时支持 C 类型名（t_int, t_string）和 PHP 类型简写（int, string）。
     */
    private function resolveCTypeToType(string $cType): Type
    {
        $cType = trim($cType);
        if ($cType === '') return Type::$mixed;

        $isPointer = str_ends_with($cType, '*');
        $base = trim(rtrim($cType, '* '));

        if ($base === '') return Type::$mixed;

        $type = match (true) {
            $base === 'void' => Type::$void,
            in_array($base, [
                'int', 't_int', 'int8_t', 'int16_t', 'int32_t', 'int64_t',
                'uint8_t', 'uint16_t', 'uint32_t', 'uint64_t',
                'size_t', 'ssize_t', 'long', 'short', 'char',
            ], true) => Type::$int,
            in_array($base, ['float', 't_float', 'double'], true) => Type::$float,
            in_array($base, ['bool', 't_bool'], true) => Type::$bool,
            in_array($base, ['string', 't_string'], true) => Type::$string,
            in_array($base, ['array', 't_array'], true) => Type::array(Type::$mixed),
            in_array($base, ['mixed', 't_var'], true) => Type::$mixed,
            in_array($base, ['callback', 't_callback'], true) => Type::$callback,
            $base === 'null' => Type::$null,
            in_array($base, ['object', 't_object'], true) => Type::$object,
            $base === 'resource' => Type::$mixed,
            $base === 'never' => Type::$never,
            default => null,
        };

        if ($type === null) {
            // tphp_class_* / tphp_enum_* → object
            if (str_starts_with($base, 'tphp_class_') || str_starts_with($base, 'tphp_enum_')) {
                return Type::$object;
            }
            $t = $this->symbols->lookupType($base);
            if ($t !== null) {
                return $t;
            }
            $cName = $this->symbols->resolveClass($base);
            if ($cName !== null) {
                return Type::$object;
            }
            return Type::$object;
        }
        return $type;
    }

    /** PHP 类型字符串 → C 类型字符串（与 TypeChecker.phpTypeToCType 一致） */
    private function phpTypeToCType(string $phpType): string
    {
        $phpType = trim($phpType);
        if ($phpType === '' || $phpType === 'mixed') return 't_var';
        if (str_starts_with($phpType, '?')) {
            $phpType = substr($phpType, 1);
        }
        $phpType = preg_replace('/\|Exception$/', '', $phpType);

        return match ($phpType) {
            'void'                  => 'void',
            'int', 'integer'        => 't_int',
            'float', 'double'       => 't_float',
            'string'                => 't_string',
            'bool', 'boolean'       => 't_bool',
            'array'                 => 't_array*',
            'object'                => 'void*',
            'callable', 'callback'  => 't_callback*',
            'null'                  => 'null',
            'resource'              => 'void*',
            'never'                 => 'void',
            'self', 'static'        => $this->currentClassCName !== null ? $this->currentClassCName . '*' : 'void*',
            default                 => $this->resolveClassNameToCType($phpType),
        };
    }

    private function resolveClassNameToCType(string $name): string
    {
        $cName = $this->symbols->resolveClass($name);
        if ($cName !== null) {
            return $cName . '*';
        }
        return 'tphp_class_' . ltrim(str_replace('\\', '_', $name), '\\') . '*';
    }

    private function classCNameFromName(string $name): string
    {
        $clean = ltrim(str_replace('\\', '_', $name), '\\');
        return 'tphp_class_' . $clean;
    }

    private function commonType(?Type $a, ?Type $b): Type
    {
        if ($a === null && $b === null) return Type::$mixed;
        if ($a === null) return $b;
        if ($b === null) return $a;
        if ($a->equals($b)) return $a;
        return Type::$mixed;
    }

    private function commonTypeAll(array $types): Type
    {
        if (empty($types)) return Type::$mixed;
        $first = $types[0];
        foreach ($types as $t) {
            if (!$t->equals($first)) return Type::$mixed;
        }
        return $first;
    }

    private function error(string $msg, int $idx): void
    {
        $pos = $this->ast->nodes[$idx]['pos'] ?? [0, 0];
        $this->errors[] = ['msg' => $msg, 'pos' => $pos];
    }
}
