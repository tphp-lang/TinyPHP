<?php

declare(strict_types=1);

// ============================================================
// FlatAstConverter — 将现有 Node 树 AST 转换为 FlatAst
//
// 设计目标：
//   - 适配器模式：不修改 Parser.php 的现有 parse* 方法
//   - 递归遍历 Node 树（src/AST/Node.php），为每个 ASTNode 创建对应的 FlatAst 节点
//   - 覆盖 NodeKind 枚举的全部 71 个值（含辅助结构：ElseIfBranch、CaseBranch、
//     ArrayEntryNode、MatchArm、EnumCaseNode、PropertyHook）
//
// 字段映射约定：
//   - kind       → 对应 NodeKind 枚举值
//   - value      → 节点的标量数据（字面量值、变量名、函数名、操作符等）
//   - children   → 子节点索引数组（params、body、condition 等嵌套 ASTNode）
//   - pos        → [line, col]（ExprNode 有 line/column 字段，其他节点为 [0,0]）
//   - extra      → 标量配置（visibility、isStatic、namespace、returnType 等）
//                  以及非 ASTNode 的混合数组（如 ListStmtNode.vars、catchClauses）
//                  对于混合数组中嵌套的 ASTNode，仍会转换为 FlatAst 节点，
//                  其索引保存在 extra 中以保留完整结构
// ============================================================

class FlatAstConverter
{
    private FlatAst $ast;

    /**
     * 将 ProgramNode 树转换为 FlatAst。
     *
     * @param ProgramNode $program Parser 产出的根节点
     * @return FlatAst 扁平化 AST（root 已设置）
     */
    public function convert(ProgramNode $program): FlatAst
    {
        $this->ast = new FlatAst();
        $this->ast->root = $this->visit($program);
        return $this->ast;
    }

    // ─────────────────────────────────────────────────────────────
    // 通用分发：根据节点具体类型派发到对应 visit 方法
    // ─────────────────────────────────────────────────────────────
    private function visit(ASTNode $node): int
    {
        // === 顶层结构 ===
        if ($node instanceof ProgramNode)       return $this->visitProgram($node);
        if ($node instanceof FunctionNode)      return $this->visitFunction($node);
        if ($node instanceof ClassNode)         return $this->visitClass($node);
        if ($node instanceof MethodNode)        return $this->visitMethod($node);
        if ($node instanceof PropertyDeclNode)  return $this->visitPropertyDecl($node);
        if ($node instanceof ParamNode)         return $this->visitParam($node);
        if ($node instanceof ConstNode)         return $this->visitConst($node);
        if ($node instanceof EnumNode)          return $this->visitEnum($node);
        if ($node instanceof AttributeDeclNode) return $this->visitAttributeDecl($node);
        if ($node instanceof AttributeUseNode)  return $this->visitAttributeUse($node);

        // === 语句 ===
        if ($node instanceof EchoStmtNode)              return $this->visitEchoStmt($node);
        if ($node instanceof ReturnStmtNode)            return $this->visitReturnStmt($node);
        if ($node instanceof AssignStmtNode)            return $this->visitAssignStmt($node);
        if ($node instanceof ListStmtNode)              return $this->visitListStmt($node);
        if ($node instanceof AssignPropStmtNode)        return $this->visitAssignPropStmt($node);
        if ($node instanceof AssignArrayStmtNode)       return $this->visitAssignArrayStmt($node);
        if ($node instanceof AssignArrayPushStmtNode)   return $this->visitAssignArrayPushStmt($node);
        if ($node instanceof IfStmtNode)                return $this->visitIfStmt($node);
        if ($node instanceof WhileStmtNode)             return $this->visitWhileStmt($node);
        if ($node instanceof DoWhileStmtNode)           return $this->visitDoWhileStmt($node);
        if ($node instanceof ForStmtNode)               return $this->visitForStmt($node);
        if ($node instanceof ForeachStmtNode)           return $this->visitForeachStmt($node);
        if ($node instanceof SwitchStmtNode)            return $this->visitSwitchStmt($node);
        if ($node instanceof BreakStmtNode)             return $this->visitBreakStmt($node);
        if ($node instanceof GotoStmtNode)              return $this->visitGotoStmt($node);
        if ($node instanceof TryStmtNode)               return $this->visitTryStmt($node);
        if ($node instanceof ThrowStmtNode)             return $this->visitThrowStmt($node);
        if ($node instanceof LabelStmtNode)             return $this->visitLabelStmt($node);
        if ($node instanceof ContinueStmtNode)          return $this->visitContinueStmt($node);
        if ($node instanceof ExprStmtNode)              return $this->visitExprStmt($node);
        if ($node instanceof NopStmtNode)               return $this->visitNopStmt($node);
        if ($node instanceof StaticStmtNode)            return $this->visitStaticStmt($node);
        if ($node instanceof ConstStmtNode)             return $this->visitConstStmt($node);
        if ($node instanceof BlockStmtNode)             return $this->visitBlockStmt($node);
        if ($node instanceof DeferStmtNode)             return $this->visitDeferStmt($node);

        // === 表达式 ===
        if ($node instanceof StringLiteralExpr)     return $this->visitStringLiteral($node);
        if ($node instanceof IntLiteralExpr)        return $this->visitIntLiteral($node);
        if ($node instanceof FloatLiteralExpr)      return $this->visitFloatLiteral($node);
        if ($node instanceof BoolLiteralExpr)       return $this->visitBoolLiteral($node);
        if ($node instanceof NullLiteralExpr)       return $this->visitNullLiteral($node);
        if ($node instanceof MagicConstExpr)        return $this->visitMagicConst($node);
        if ($node instanceof ArrayLiteralExpr)      return $this->visitArrayLiteral($node);
        if ($node instanceof ArrayAccessExpr)       return $this->visitArrayAccess($node);
        if ($node instanceof ArrayAppendExpr)       return $this->visitArrayAppend($node);
        if ($node instanceof PropertyAccessExpr)    return $this->visitPropertyAccess($node);
        if ($node instanceof EnumAccessExpr)        return $this->visitEnumAccess($node);
        if ($node instanceof ClosureExpr)           return $this->visitClosure($node);
        if ($node instanceof YieldExpr)             return $this->visitYieldExpr($node);
        if ($node instanceof YieldFromExpr)         return $this->visitYieldFromExpr($node);
        if ($node instanceof PipeExpr)              return $this->visitPipeExpr($node);
        if ($node instanceof PlaceholderExpr)       return $this->visitPlaceholderExpr($node);
        if ($node instanceof CallableConvertExpr)   return $this->visitCallableConvert($node);
        if ($node instanceof VariableExpr)          return $this->visitVariable($node);
        if ($node instanceof UnaryExpr)             return $this->visitUnary($node);
        if ($node instanceof PostfixExpr)           return $this->visitPostfix($node);
        if ($node instanceof CompoundAssignExpr)    return $this->visitCompoundAssign($node);
        if ($node instanceof BinaryExpr)            return $this->visitBinary($node);
        if ($node instanceof TernaryExpr)           return $this->visitTernary($node);
        if ($node instanceof NullCoalesceExpr)      return $this->visitNullCoalesce($node);
        if ($node instanceof MatchExpr)             return $this->visitMatchExpr($node);
        if ($node instanceof CallExpr)              return $this->visitCall($node);
        if ($node instanceof CastExpr)              return $this->visitCast($node);
        if ($node instanceof NewExpr)               return $this->visitNew($node);
        if ($node instanceof ThrowExprNode)         return $this->visitThrowExpr($node);
        if ($node instanceof OrBlockExpr)           return $this->visitOrBlock($node);

        throw new InvalidArgumentException('Unknown ASTNode type: ' . get_class($node));
    }

    /**
     * 提取节点的源码位置 [line, col]。
     * ExprNode 子类有 line/column 字段；其他节点无位置信息，返回 [0,0]。
     */
    private function posOf(ASTNode $node): array
    {
        if ($node instanceof ExprNode) {
            return [$node->line, $node->column];
        }
        return [0, 0];
    }

    /**
     * 批量访问 ASTNode 数组，返回 FlatAst 索引数组。
     *
     * @param ASTNode[] $nodes
     * @return int[]
     */
    private function visitAll(array $nodes): array
    {
        $indices = [];
        foreach ($nodes as $n) {
            if ($n instanceof ASTNode) {
                $indices[] = $this->visit($n);
            }
        }
        return $indices;
    }

    // ═══════════════════════════════════════════════════════════════
    // 顶层结构
    // ═══════════════════════════════════════════════════════════════

    private function visitProgram(ProgramNode $node): int
    {
        $children = [];
        if ($node->mainClass !== null) {
            $children[] = $this->visit($node->mainClass);
        }
        $extraClassCount = 0;
        foreach ($node->extraClasses as $cls) {
            $children[] = $this->visit($cls);
            $extraClassCount++;
        }
        $functionCount = 0;
        foreach ($node->functions as $fn) {
            $children[] = $this->visit($fn);
            $functionCount++;
        }
        $constantCount = 0;
        foreach ($node->constants as $cn) {
            $children[] = $this->visit($cn);
            $constantCount++;
        }
        $enumCount = 0;
        foreach ($node->enums as $en) {
            $children[] = $this->visit($en);
            $enumCount++;
        }
        return $this->ast->makeNode(
            NodeKind::ProgramNode,
            null,
            $children,
            [0, 0],
            [
                'hasMainClass'    => $node->mainClass !== null,
                'extraClassCount' => $extraClassCount,
                'functionCount'   => $functionCount,
                'constantCount'   => $constantCount,
                'enumCount'       => $enumCount,
                'includes'        => $node->includes,
                'ccFlags'         => $node->ccFlags,
                'callbacks'       => $node->callbacks,
                'debugs'          => $node->debugs,
                'cstructs'        => $node->cstructs,
                'useImports'      => $node->useImports,
            ],
        );
    }

    private function visitFunction(FunctionNode $node): int
    {
        $children = [];
        // 属性注解 → params → body
        $attributeCount = 0;
        foreach ($node->attributes as $attr) {
            $children[] = $this->visit($attr);
            $attributeCount++;
        }
        $paramCount = 0;
        foreach ($node->params as $p) {
            $children[] = $this->visit($p);
            $paramCount++;
        }
        foreach ($node->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::FunctionNode,
            $node->name,
            $children,
            [0, 0],
            [
                'returnType'      => $node->returnType,
                'namespace'       => $node->namespace,
                'isGenerator'     => $node->isGenerator,
                'isCDeclaration'  => $node->isCDeclaration,
                'attributeCount'  => $attributeCount,
                'paramCount'      => $paramCount,
            ],
        );
    }

    private function visitClass(ClassNode $node): int
    {
        $children = [];
        // 属性 → 方法 → 类常量
        $attributeCount = 0;
        foreach ($node->attributes as $attr) {
            $children[] = $this->visit($attr);
            $attributeCount++;
        }
        $propertyCount = 0;
        foreach ($node->properties as $prop) {
            $children[] = $this->visit($prop);
            $propertyCount++;
        }
        $methodCount = 0;
        foreach ($node->methods as $m) {
            $children[] = $this->visit($m);
            $methodCount++;
        }
        $classConstCount = 0;
        foreach ($node->classConsts as $cc) {
            $children[] = $this->visit($cc);
            $classConstCount++;
        }
        return $this->ast->makeNode(
            NodeKind::ClassNode,
            $node->name,
            $children,
            [0, 0],
            [
                'namespace'         => $node->namespace,
                'parentName'        => $node->parentName,
                'isAbstract'        => $node->isAbstract,
                'implements'        => $node->implements,
                'traits'            => $node->traits,
                'isReadonly'        => $node->isReadonly,
                'isFinal'           => $node->isFinal,
                'isInterface'       => $node->isInterface,
                'isTrait'           => $node->isTrait,
                'traitAdaptations'  => $node->traitAdaptations,
                'attributeCount'    => $attributeCount,
                'propertyCount'     => $propertyCount,
                'methodCount'       => $methodCount,
                'classConstCount'   => $classConstCount,
            ],
        );
    }

    private function visitMethod(MethodNode $node): int
    {
        $children = [];
        // 属性 → params → promoted → body
        $attributeCount = 0;
        foreach ($node->attributes as $attr) {
            $children[] = $this->visit($attr);
            $attributeCount++;
        }
        $paramCount = 0;
        foreach ($node->params as $p) {
            $children[] = $this->visit($p);
            $paramCount++;
        }
        $promotedCount = 0;
        foreach ($node->promoted as $p) {
            $children[] = $this->visit($p);
            $promotedCount++;
        }
        $hasBody = $node->body !== null;
        if ($hasBody) {
            foreach ($node->body as $stmt) {
                $children[] = $this->visit($stmt);
            }
        }
        return $this->ast->makeNode(
            NodeKind::MethodNode,
            $node->name,
            $children,
            [0, 0],
            [
                'visibility'        => $node->visibility,
                'returnType'        => $node->returnType,
                'isGenerator'       => $node->isGenerator,
                'isStatic'          => $node->isStatic,
                'isFinal'           => $node->isFinal,
                'isStaticDispatch'  => $node->isStaticDispatch,
                'hasBody'           => $hasBody,
                'attributeCount'    => $attributeCount,
                'paramCount'        => $paramCount,
                'promotedCount'     => $promotedCount,
            ],
        );
    }

    private function visitPropertyDecl(PropertyDeclNode $node): int
    {
        $children = [];
        if ($node->default !== null) {
            $children[] = $this->visit($node->default);
        }
        $hookCount = 0;
        foreach ($node->hooks as $hook) {
            $children[] = $this->visitPropertyHook($hook);
            $hookCount++;
        }
        return $this->ast->makeNode(
            NodeKind::PropertyDeclNode,
            $node->name,
            $children,
            [0, 0],
            [
                'type'           => $node->type,
                'visibility'     => $node->visibility,
                'isStatic'       => $node->isStatic,
                'isReadonly'     => $node->isReadonly,
                'setVisibility'  => $node->setVisibility,
                'hasDefault'     => $node->default !== null,
                'hookCount'      => $hookCount,
            ],
        );
    }

    /**
     * PropertyHook 不是 ASTNode，但需转换为 FlatAst 节点（NodeKind::PropertyHook）。
     */
    private function visitPropertyHook(PropertyHook $hook): int
    {
        $children = [];
        if ($hook->expr !== null) {
            $children[] = $this->visit($hook->expr);
        }
        foreach ($hook->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::PropertyHook,
            $hook->kind,
            $children,
            [0, 0],
            [
                'hasExpr' => $hook->expr !== null,
            ],
        );
    }

    private function visitParam(ParamNode $node): int
    {
        $children = [];
        if ($node->default !== null) {
            $children[] = $this->visit($node->default);
        }
        return $this->ast->makeNode(
            NodeKind::ParamNode,
            $node->name,
            $children,
            [0, 0],
            [
                'type'         => $node->type,
                'byRef'        => $node->byRef,
                'isReadonly'   => $node->isReadonly,
                'isVariadic'   => $node->isVariadic,
                'hasDefault'   => $node->default !== null,
            ],
        );
    }

    private function visitConst(ConstNode $node): int
    {
        $children = [];
        if ($node->value !== null) {
            $children[] = $this->visit($node->value);
        }
        if ($node->attributeDecl !== null) {
            $children[] = $this->visit($node->attributeDecl);
        }
        return $this->ast->makeNode(
            NodeKind::ConstNode,
            $node->name,
            $children,
            [0, 0],
            [
                'namespace'         => $node->namespace,
                'type'              => $node->type,
                'visibility'        => $node->visibility,
                'className'         => $node->className,
                'hasValue'          => $node->value !== null,
                'hasAttributeDecl'  => $node->attributeDecl !== null,
            ],
        );
    }

    private function visitEnum(EnumNode $node): int
    {
        $children = [];
        $caseCount = 0;
        foreach ($node->cases as $case) {
            $children[] = $this->visitEnumCase($case);
            $caseCount++;
        }
        $methodCount = 0;
        foreach ($node->methods as $m) {
            $children[] = $this->visit($m);
            $methodCount++;
        }
        $classConstCount = 0;
        foreach ($node->classConsts as $cc) {
            $children[] = $this->visit($cc);
            $classConstCount++;
        }
        return $this->ast->makeNode(
            NodeKind::EnumNode,
            $node->name,
            $children,
            [0, 0],
            [
                'backingType'     => $node->backingType,
                'namespace'       => $node->namespace,
                'implements'      => $node->implements,
                'caseCount'       => $caseCount,
                'methodCount'     => $methodCount,
                'classConstCount' => $classConstCount,
            ],
        );
    }

    /**
     * EnumCaseNode 不是 ASTNode，但需转换为 FlatAst 节点（NodeKind::EnumCaseNode）。
     */
    private function visitEnumCase(EnumCaseNode $case): int
    {
        $children = [$this->visit($case->value)];
        return $this->ast->makeNode(
            NodeKind::EnumCaseNode,
            $case->name,
            $children,
            [0, 0],
            [],
        );
    }

    private function visitAttributeDecl(AttributeDeclNode $node): int
    {
        // params 是 array[]，非 ASTNode，全部放入 extra
        return $this->ast->makeNode(
            NodeKind::AttributeDeclNode,
            null,
            [],
            [0, 0],
            [
                'params' => $node->params,
            ],
        );
    }

    private function visitAttributeUse(AttributeUseNode $node): int
    {
        $children = [];
        foreach ($node->args as $arg) {
            $children[] = $this->visit($arg);
        }
        return $this->ast->makeNode(
            NodeKind::AttributeUseNode,
            $node->name,
            $children,
            [0, 0],
            [
                'argCount' => count($node->args),
            ],
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 语句
    // ═══════════════════════════════════════════════════════════════

    private function visitEchoStmt(EchoStmtNode $node): int
    {
        $children = $this->visitAll($node->exprs);
        return $this->ast->makeNode(
            NodeKind::EchoStmtNode,
            null,
            $children,
            [0, 0],
            ['exprCount' => count($node->exprs)],
        );
    }

    private function visitReturnStmt(ReturnStmtNode $node): int
    {
        $children = [];
        if ($node->expr !== null) {
            $children[] = $this->visit($node->expr);
        }
        return $this->ast->makeNode(
            NodeKind::ReturnStmtNode,
            null,
            $children,
            [0, 0],
            ['hasExpr' => $node->expr !== null],
        );
    }

    private function visitAssignStmt(AssignStmtNode $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::AssignStmtNode,
            $node->varName,
            $children,
            [0, 0],
            ['type' => $node->type],
        );
    }

    private function visitListStmt(ListStmtNode $node): int
    {
        // rhs expr 作为子节点
        $children = [$this->visit($node->expr)];
        // vars 是混合数组（null | string | ListStmtNode），嵌套 ListStmtNode 递归转换为 FlatAst 节点，
        // 索引保存在 extra['vars'] 中
        $varsSerialized = [];
        foreach ($node->vars as $var) {
            if ($var === null) {
                $varsSerialized[] = null;
            } elseif ($var instanceof ListStmtNode) {
                $varsSerialized[] = ['__listIdx' => $this->visitListStmt($var)];
            } else {
                $varsSerialized[] = $var;
            }
        }
        return $this->ast->makeNode(
            NodeKind::ListStmtNode,
            null,
            $children,
            [0, 0],
            [
                'vars'         => $varsSerialized,
                'short'        => $node->short,
                'keyedEntries' => $node->keyedEntries,
            ],
        );
    }

    private function visitAssignPropStmt(AssignPropStmtNode $node): int
    {
        $children = [
            $this->visit($node->target),
            $this->visit($node->value),
        ];
        return $this->ast->makeNode(
            NodeKind::AssignPropStmtNode,
            null,
            $children,
            [0, 0],
            [],
        );
    }

    private function visitAssignArrayStmt(AssignArrayStmtNode $node): int
    {
        $children = [
            $this->visit($node->target),
            $this->visit($node->value),
        ];
        return $this->ast->makeNode(
            NodeKind::AssignArrayStmtNode,
            null,
            $children,
            [0, 0],
            [],
        );
    }

    private function visitAssignArrayPushStmt(AssignArrayPushStmtNode $node): int
    {
        $children = [
            $this->visit($node->target),
            $this->visit($node->value),
        ];
        return $this->ast->makeNode(
            NodeKind::AssignArrayPushStmtNode,
            null,
            $children,
            [0, 0],
            [],
        );
    }

    private function visitIfStmt(IfStmtNode $node): int
    {
        $children = [];
        $children[] = $this->visit($node->condition);
        foreach ($node->thenBody as $stmt) {
            $children[] = $this->visit($stmt);
        }
        $thenBodyCount = count($node->thenBody);
        $elseifCount = 0;
        foreach ($node->elseifs as $elif) {
            $children[] = $this->visitElseIfBranch($elif);
            $elseifCount++;
        }
        foreach ($node->elseBody as $stmt) {
            $children[] = $this->visit($stmt);
        }
        $elseBodyCount = count($node->elseBody);
        return $this->ast->makeNode(
            NodeKind::IfStmtNode,
            null,
            $children,
            [0, 0],
            [
                'thenBodyCount' => $thenBodyCount,
                'elseifCount'   => $elseifCount,
                'elseBodyCount' => $elseBodyCount,
            ],
        );
    }

    /**
     * ElseIfBranch 不是 ASTNode，但需转换为 FlatAst 节点（NodeKind::ElseIfBranch）。
     */
    private function visitElseIfBranch(ElseIfBranch $branch): int
    {
        $children = [$this->visit($branch->condition)];
        foreach ($branch->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::ElseIfBranch,
            null,
            $children,
            [0, 0],
            ['bodyCount' => count($branch->body)],
        );
    }

    private function visitWhileStmt(WhileStmtNode $node): int
    {
        $children = [$this->visit($node->condition)];
        foreach ($node->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::WhileStmtNode,
            null,
            $children,
            [0, 0],
            ['bodyCount' => count($node->body)],
        );
    }

    private function visitDoWhileStmt(DoWhileStmtNode $node): int
    {
        $children = [$this->visit($node->condition)];
        foreach ($node->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::DoWhileStmtNode,
            null,
            $children,
            [0, 0],
            ['bodyCount' => count($node->body)],
        );
    }

    private function visitForStmt(ForStmtNode $node): int
    {
        $children = [];
        if ($node->init !== null)      $children[] = $this->visit($node->init);
        if ($node->condition !== null) $children[] = $this->visit($node->condition);
        if ($node->step !== null)      $children[] = $this->visit($node->step);
        foreach ($node->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::ForStmtNode,
            null,
            $children,
            [0, 0],
            [
                'hasInit'      => $node->init !== null,
                'hasCondition' => $node->condition !== null,
                'hasStep'      => $node->step !== null,
                'bodyCount'    => count($node->body),
            ],
        );
    }

    private function visitForeachStmt(ForeachStmtNode $node): int
    {
        $children = [$this->visit($node->array)];
        foreach ($node->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::ForeachStmtNode,
            null,
            $children,
            [0, 0],
            [
                'valueVar'  => $node->valueVar,
                'keyVar'    => $node->keyVar,
                'bodyCount' => count($node->body),
            ],
        );
    }

    private function visitSwitchStmt(SwitchStmtNode $node): int
    {
        $children = [$this->visit($node->condition)];
        $caseCount = 0;
        foreach ($node->cases as $case) {
            $children[] = $this->visitCaseBranch($case);
            $caseCount++;
        }
        return $this->ast->makeNode(
            NodeKind::SwitchStmtNode,
            null,
            $children,
            [0, 0],
            ['caseCount' => $caseCount],
        );
    }

    /**
     * CaseBranch 不是 ASTNode，但需转换为 FlatAst 节点（NodeKind::CaseBranch）。
     */
    private function visitCaseBranch(CaseBranch $branch): int
    {
        $children = [];
        if ($branch->value !== null) {
            $children[] = $this->visit($branch->value);
        }
        foreach ($branch->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::CaseBranch,
            null,
            $children,
            [0, 0],
            [
                'hasValue'  => $branch->value !== null,
                'bodyCount' => count($branch->body),
            ],
        );
    }

    private function visitBreakStmt(BreakStmtNode $node): int
    {
        return $this->ast->makeNode(
            NodeKind::BreakStmtNode,
            $node->level,
            [],
            [0, 0],
            [],
        );
    }

    private function visitGotoStmt(GotoStmtNode $node): int
    {
        return $this->ast->makeNode(
            NodeKind::GotoStmtNode,
            $node->label,
            [],
            [0, 0],
            [],
        );
    }

    private function visitTryStmt(TryStmtNode $node): int
    {
        $children = [];
        foreach ($node->tryBody as $stmt) {
            $children[] = $this->visit($stmt);
        }
        $tryBodyCount = count($node->tryBody);
        // catchClauses 是 array<array{type, var, body:StmtNode[]}>，将 body 的语句转换为 FlatAst 节点，
        // 索引保存在 extra 中
        $catchesSerialized = [];
        foreach ($node->catchClauses as $clause) {
            $bodyIndices = [];
            foreach ($clause['body'] as $stmt) {
                $bodyIndices[] = $this->visit($stmt);
            }
            $catchesSerialized[] = [
                'type'        => $clause['type'],
                'var'         => $clause['var'],
                'bodyIndices' => $bodyIndices,
            ];
        }
        foreach ($node->finallyBody as $stmt) {
            $children[] = $this->visit($stmt);
        }
        $finallyBodyCount = count($node->finallyBody);
        return $this->ast->makeNode(
            NodeKind::TryStmtNode,
            null,
            $children,
            [0, 0],
            [
                'tryBodyCount'    => $tryBodyCount,
                'catches'         => $catchesSerialized,
                'finallyBodyCount' => $finallyBodyCount,
            ],
        );
    }

    private function visitThrowStmt(ThrowStmtNode $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::ThrowStmtNode,
            null,
            $children,
            [0, 0],
            [],
        );
    }

    private function visitLabelStmt(LabelStmtNode $node): int
    {
        return $this->ast->makeNode(
            NodeKind::LabelStmtNode,
            $node->name,
            [],
            [0, 0],
            [],
        );
    }

    private function visitContinueStmt(ContinueStmtNode $node): int
    {
        return $this->ast->makeNode(
            NodeKind::ContinueStmtNode,
            $node->level,
            [],
            [0, 0],
            [],
        );
    }

    private function visitExprStmt(ExprStmtNode $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::ExprStmtNode,
            null,
            $children,
            [0, 0],
            [],
        );
    }

    private function visitNopStmt(NopStmtNode $node): int
    {
        return $this->ast->makeNode(
            NodeKind::NopStmtNode,
            null,
            [],
            [0, 0],
            [],
        );
    }

    private function visitStaticStmt(StaticStmtNode $node): int
    {
        $children = [];
        if ($node->init !== null) {
            $children[] = $this->visit($node->init);
        }
        return $this->ast->makeNode(
            NodeKind::StaticStmtNode,
            $node->varName,
            $children,
            [0, 0],
            [
                'type'     => $node->type,
                'hasInit'  => $node->init !== null,
            ],
        );
    }

    private function visitConstStmt(ConstStmtNode $node): int
    {
        $children = [$this->visit($node->value)];
        return $this->ast->makeNode(
            NodeKind::ConstStmtNode,
            $node->name,
            $children,
            [0, 0],
            ['type' => $node->type],
        );
    }

    private function visitBlockStmt(BlockStmtNode $node): int
    {
        $children = $this->visitAll($node->stmts);
        return $this->ast->makeNode(
            NodeKind::BlockStmtNode,
            null,
            $children,
            [0, 0],
            ['stmtCount' => count($node->stmts)],
        );
    }

    private function visitDeferStmt(DeferStmtNode $node): int
    {
        $children = $this->visitAll($node->body);
        return $this->ast->makeNode(
            NodeKind::DeferStmtNode,
            null,
            $children,
            [0, 0],
            ['bodyCount' => count($node->body)],
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 表达式
    // ═══════════════════════════════════════════════════════════════

    private function visitStringLiteral(StringLiteralExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::StringLiteralExpr,
            $node->value,
            [],
            $this->posOf($node),
        );
    }

    private function visitIntLiteral(IntLiteralExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::IntLiteralExpr,
            $node->value,
            [],
            $this->posOf($node),
        );
    }

    private function visitFloatLiteral(FloatLiteralExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::FloatLiteralExpr,
            $node->value,
            [],
            $this->posOf($node),
        );
    }

    private function visitBoolLiteral(BoolLiteralExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::BoolLiteralExpr,
            $node->value,
            [],
            $this->posOf($node),
        );
    }

    private function visitNullLiteral(NullLiteralExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::NullLiteralExpr,
            null,
            [],
            $this->posOf($node),
        );
    }

    private function visitMagicConst(MagicConstExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::MagicConstExpr,
            $node->name,
            [],
            [$node->line, 0],
        );
    }

    private function visitArrayLiteral(ArrayLiteralExpr $node): int
    {
        $children = [];
        $entryCount = 0;
        foreach ($node->entries as $entry) {
            $children[] = $this->visitArrayEntry($entry);
            $entryCount++;
        }
        return $this->ast->makeNode(
            NodeKind::ArrayLiteralExpr,
            null,
            $children,
            $this->posOf($node),
            ['entryCount' => $entryCount],
        );
    }

    /**
     * ArrayEntryNode 不是 ASTNode，但需转换为 FlatAst 节点（NodeKind::ArrayEntryNode）。
     */
    private function visitArrayEntry(ArrayEntryNode $entry): int
    {
        $children = [];
        if ($entry->key !== null) {
            $children[] = $this->visit($entry->key);
        }
        $children[] = $this->visit($entry->value);
        return $this->ast->makeNode(
            NodeKind::ArrayEntryNode,
            null,
            $children,
            [0, 0],
            [
                'hasKey'  => $entry->key !== null,
                'isSpread' => $entry->isSpread,
            ],
        );
    }

    private function visitArrayAccess(ArrayAccessExpr $node): int
    {
        $children = [
            $this->visit($node->array),
            $this->visit($node->index),
        ];
        return $this->ast->makeNode(
            NodeKind::ArrayAccessExpr,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitArrayAppend(ArrayAppendExpr $node): int
    {
        $children = [$this->visit($node->target)];
        return $this->ast->makeNode(
            NodeKind::ArrayAppendExpr,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitPropertyAccess(PropertyAccessExpr $node): int
    {
        $children = [$this->visit($node->object)];
        return $this->ast->makeNode(
            NodeKind::PropertyAccessExpr,
            $node->property,
            $children,
            $this->posOf($node),
            ['isNullsafe' => $node->isNullsafe],
        );
    }

    private function visitEnumAccess(EnumAccessExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::EnumAccessExpr,
            [$node->enumName, $node->caseName],
            [],
            $this->posOf($node),
            [
                'enumName' => $node->enumName,
                'caseName' => $node->caseName,
            ],
        );
    }

    private function visitClosure(ClosureExpr $node): int
    {
        $children = [];
        foreach ($node->params as $p) {
            $children[] = $this->visit($p);
        }
        foreach ($node->body as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::ClosureExpr,
            null,
            $children,
            $this->posOf($node),
            [
                'returnType'  => $node->returnType,
                'useVars'     => $node->useVars,
                'isGenerator' => $node->isGenerator,
                'isArrow'     => $node->isArrow,
                'paramCount'  => count($node->params),
            ],
        );
    }

    private function visitYieldExpr(YieldExpr $node): int
    {
        $children = [];
        if ($node->key !== null) {
            $children[] = $this->visit($node->key);
        }
        if ($node->value !== null) {
            $children[] = $this->visit($node->value);
        }
        return $this->ast->makeNode(
            NodeKind::YieldExpr,
            null,
            $children,
            $this->posOf($node),
            [
                'hasKey'   => $node->key !== null,
                'hasValue' => $node->value !== null,
            ],
        );
    }

    private function visitYieldFromExpr(YieldFromExpr $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::YieldFromExpr,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitPipeExpr(PipeExpr $node): int
    {
        $children = [
            $this->visit($node->left),
            $this->visit($node->right),
        ];
        return $this->ast->makeNode(
            NodeKind::PipeExpr,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitPlaceholderExpr(PlaceholderExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::PlaceholderExpr,
            null,
            [],
            $this->posOf($node),
            [],
        );
    }

    private function visitCallableConvert(CallableConvertExpr $node): int
    {
        $children = [];
        if ($node->callee !== null) {
            $children[] = $this->visit($node->callee);
        }
        return $this->ast->makeNode(
            NodeKind::CallableConvertExpr,
            $node->name,
            $children,
            $this->posOf($node),
            [
                'isRawC'   => $node->isRawC,
                'hasCallee' => $node->callee !== null,
            ],
        );
    }

    private function visitVariable(VariableExpr $node): int
    {
        return $this->ast->makeNode(
            NodeKind::VariableExpr,
            $node->name,
            [],
            $this->posOf($node),
        );
    }

    private function visitUnary(UnaryExpr $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::UnaryExpr,
            $node->operator,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitPostfix(PostfixExpr $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::PostfixExpr,
            $node->operator,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitCompoundAssign(CompoundAssignExpr $node): int
    {
        $children = [
            $this->visit($node->target),
            $this->visit($node->value),
        ];
        return $this->ast->makeNode(
            NodeKind::CompoundAssignExpr,
            $node->operator,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitBinary(BinaryExpr $node): int
    {
        $children = [
            $this->visit($node->left),
            $this->visit($node->right),
        ];
        return $this->ast->makeNode(
            NodeKind::BinaryExpr,
            $node->operator,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitTernary(TernaryExpr $node): int
    {
        $children = [
            $this->visit($node->condition),
            $this->visit($node->thenExpr),
            $this->visit($node->elseExpr),
        ];
        return $this->ast->makeNode(
            NodeKind::TernaryExpr,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitNullCoalesce(NullCoalesceExpr $node): int
    {
        $children = [
            $this->visit($node->left),
            $this->visit($node->right),
        ];
        return $this->ast->makeNode(
            NodeKind::NullCoalesceExpr,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitMatchExpr(MatchExpr $node): int
    {
        $children = [$this->visit($node->condition)];
        $armCount = 0;
        foreach ($node->arms as $arm) {
            $children[] = $this->visitMatchArm($arm);
            $armCount++;
        }
        return $this->ast->makeNode(
            NodeKind::MatchExpr,
            null,
            $children,
            $this->posOf($node),
            ['armCount' => $armCount],
        );
    }

    /**
     * MatchArm 不是 ASTNode，但需转换为 FlatAst 节点（NodeKind::MatchArm）。
     */
    private function visitMatchArm(MatchArm $arm): int
    {
        $children = [];
        foreach ($arm->values as $v) {
            $children[] = $this->visit($v);
        }
        $children[] = $this->visit($arm->body);
        return $this->ast->makeNode(
            NodeKind::MatchArm,
            null,
            $children,
            [0, 0],
            ['valueCount' => count($arm->values)],
        );
    }

    private function visitCall(CallExpr $node): int
    {
        $children = [];
        if ($node->callee !== null) {
            $children[] = $this->visit($node->callee);
        }
        foreach ($node->args as $arg) {
            $children[] = $this->visit($arg);
        }
        return $this->ast->makeNode(
            NodeKind::CallExpr,
            $node->name,
            $children,
            $this->posOf($node),
            [
                'isNullsafe' => $node->isNullsafe,
                'isRawC'     => $node->isRawC,
                'hasCallee'  => $node->callee !== null,
                'argNames'   => $node->argNames,
                'spreads'    => $node->spreads,
                'argCount'   => count($node->args),
            ],
        );
    }

    private function visitCast(CastExpr $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::CastExpr,
            $node->castType,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitNew(NewExpr $node): int
    {
        $children = [];
        foreach ($node->args as $arg) {
            $children[] = $this->visit($arg);
        }
        return $this->ast->makeNode(
            NodeKind::NewExpr,
            $node->className,
            $children,
            $this->posOf($node),
            [
                'argNames' => $node->argNames,
                'argCount' => count($node->args),
            ],
        );
    }

    private function visitThrowExpr(ThrowExprNode $node): int
    {
        $children = [$this->visit($node->expr)];
        return $this->ast->makeNode(
            NodeKind::ThrowExprNode,
            null,
            $children,
            $this->posOf($node),
            [],
        );
    }

    private function visitOrBlock(OrBlockExpr $node): int
    {
        $children = [$this->visit($node->expr)];
        foreach ($node->orBody as $stmt) {
            $children[] = $this->visit($stmt);
        }
        return $this->ast->makeNode(
            NodeKind::OrBlockExpr,
            null,
            $children,
            $this->posOf($node),
            ['orBodyCount' => count($node->orBody)],
        );
    }
}
