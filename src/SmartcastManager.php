<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// SmartcastManager — instanceof 类型缩窄管理器
//   检测 if 条件中的 instanceof 模式，在对应分支的作用域内
//   添加 smartcast，使变量类型在分支内被缩窄。
//
// 依赖：FlatAst（读取 if/instanceof 节点）、ScopeTree（管理 smartcast 作用域）
//
// 模式识别：
//   - $x instanceof Foo        → BinaryExpr('instanceof', [VariableExpr('$x'), VariableExpr('Foo')])
//   - !($x instanceof Foo)     → UnaryExpr('!', [BinaryExpr('instanceof', ...)])
//   - $x instanceof Foo && $y instanceof Bar → BinaryExpr('&&', [...])
//
// FlatAst 中 IfStmtNode 子节点布局：
//   child 0                       : condition
//   child 1 .. thenBodyCount      : then body 语句
//   接下来 elseifCount 个          : ElseIfBranch 节点（child 0=condition, 其余=body）
//   最后 elseBodyCount 个          : else body 语句
//   extra: thenBodyCount / elseifCount / elseBodyCount
// ═══════════════════════════════════════════════════════════════

class SmartcastManager
{
    private FlatAst $ast;
    private ScopeTree $scopeTree;

    public function __construct(FlatAst $ast, ScopeTree $scopeTree)
    {
        $this->ast = $ast;
        $this->scopeTree = $scopeTree;
    }

    // ──────────────────────────────────────────────────────────
    // 模式检测
    // ──────────────────────────────────────────────────────────

    /**
     * 检测 if 语句条件是否为 instanceof 模式。
     *
     * @param int $ifNodeIdx IfStmtNode 节点索引
     * @return array{var:string,class:string,negated:bool}|null
     *   返回第一个检测到的模式 ['var'=>'$x','class'=>'Foo','negated'=>bool]；未匹配返回 null
     */
    public function detectInstanceofPattern(int $ifNodeIdx): ?array
    {
        if (!isset($this->ast->nodes[$ifNodeIdx])) return null;
        $ifNode = $this->ast->nodes[$ifNodeIdx];
        if ($ifNode['kind'] !== NodeKind::IfStmtNode) return null;
        if ($ifNode['children_count'] < 1) return null;

        $condIdx = $this->ast->child($ifNodeIdx, 0);
        $patterns = $this->collectPatterns($condIdx);
        return $patterns[0] ?? null;
    }

    /**
     * 从条件表达式节点收集所有 instanceof 模式。
     *
     * - BinaryExpr 'instanceof' → 单个模式
     * - BinaryExpr '&&'         → 合并两侧模式（then 分支中均成立）
     * - UnaryExpr '!'           → 递归内部并翻转 negated 标志
     * - BinaryExpr '||' 或其他   → 保守返回空（无法确定缩窄）
     *
     * @param int $condIdx 条件表达式节点索引
     * @return array<int, array{var:string,class:string,negated:bool}>
     */
    private function collectPatterns(int $condIdx): array
    {
        if (!isset($this->ast->nodes[$condIdx])) return [];
        $node = $this->ast->nodes[$condIdx];

        if ($node['kind'] === NodeKind::BinaryExpr) {
            $op = $node['value'];
            if ($op === 'instanceof') {
                $pat = $this->matchInstanceof($condIdx);
                return $pat !== null ? [$pat] : [];
            }
            if ($op === '&&') {
                if ($node['children_count'] < 2) return [];
                $left  = $this->ast->child($condIdx, 0);
                $right = $this->ast->child($condIdx, 1);
                return array_merge(
                    $this->collectPatterns($left),
                    $this->collectPatterns($right),
                );
            }
            // '||' 或其他运算符：保守不缩窄
            return [];
        }

        if ($node['kind'] === NodeKind::UnaryExpr) {
            if ($node['value'] === '!') {
                if ($node['children_count'] < 1) return [];
                $inner = $this->ast->child($condIdx, 0);
                $innerPats = $this->collectPatterns($inner);
                $result = [];
                foreach ($innerPats as $p) {
                    $p['negated'] = !$p['negated'];
                    $result[] = $p;
                }
                return $result;
            }
            return [];
        }

        return [];
    }

    /**
     * 匹配单个 instanceof BinaryExpr 节点。
     * left 须为 VariableExpr（以 $ 开头），right 须为表示类名的节点
     * （VariableExpr 且 value 不以 $ 开头，即 bare identifier 类名）。
     *
     * @return array{var:string,class:string,negated:bool}|null
     */
    private function matchInstanceof(int $binIdx): ?array
    {
        if ($this->ast->childCount($binIdx) < 2) return null;
        $leftIdx  = $this->ast->child($binIdx, 0);
        $rightIdx = $this->ast->child($binIdx, 1);
        if (!isset($this->ast->nodes[$leftIdx]) || !isset($this->ast->nodes[$rightIdx])) return null;

        $left  = $this->ast->nodes[$leftIdx];
        $right = $this->ast->nodes[$rightIdx];

        if ($left['kind'] !== NodeKind::VariableExpr) return null;
        $varName = $left['value'];
        if (!is_string($varName) || $varName === '' || !str_starts_with($varName, '$')) return null;

        // right: 类名。通常是 VariableExpr 且 value 不以 $ 开头（bare identifier）
        if ($right['kind'] === NodeKind::VariableExpr) {
            $className = $right['value'];
            if (is_string($className) && $className !== '' && !str_starts_with($className, '$')) {
                return ['var' => $varName, 'class' => $className, 'negated' => false];
            }
        }
        // 其他形式（PropertyAccessExpr 等动态类名）→ 无法静态确定
        return null;
    }

    // ──────────────────────────────────────────────────────────
    // 缩窄逻辑
    // ──────────────────────────────────────────────────────────

    /**
     * 在当前作用域为变量添加 smartcast（缩窄为指定类名）。
     *
     * @param string $varName   变量名（含 $ 前缀）
     * @param string $className 类名
     */
    public function applySmartcast(string $varName, string $className): void
    {
        $this->scopeTree->addSmartcast($varName, $className);
    }

    /**
     * 沿作用域链查找变量的 smartcast 类名。
     *
     * @param string $varName 变量名（含 $ 前缀）
     * @return string|null 缩窄类名；无则返回 null
     */
    public function lookupSmartcast(string $varName): ?string
    {
        $result = $this->scopeTree->lookupSmartcast($varName);
        return $result === null ? null : (string)$result;
    }

    /**
     * 返回变量在当前作用域的有效类型：优先 smartcast，无则返回原类型。
     *
     * @param string $varName      变量名（含 $ 前缀）
     * @param mixed  $originalType 原始类型（字符串类名、Type 对象等）
     * @return mixed smartcast 类名（字符串）或 $originalType
     */
    public function getEffectiveType(string $varName, mixed $originalType): mixed
    {
        $smartcast = $this->scopeTree->lookupSmartcast($varName);
        return $smartcast !== null ? $smartcast : $originalType;
    }

    // ──────────────────────────────────────────────────────────
    // if 分支处理
    // ──────────────────────────────────────────────────────────

    /**
     * 处理 if 语句：按 instanceof 模式在各分支作用域内添加 smartcast。
     *
     * 正向 if ($x instanceof Foo)：
     *   - then 分支：$x 缩窄为 Foo
     *   - else 分支：不缩窄
     * 否定 if (!($x instanceof Foo))：
     *   - then 分支：不缩窄
     *   - else 分支：$x 缩窄为 Foo
     * elseif 链：每个 elseif 分支按自身 instanceof 缩窄。
     * 多条件 &&：then 分支应用所有正向模式。
     *
     * @param int           $ifNodeIdx IfStmtNode 节点索引
     * @param callable|null $onBranch  分支回调，签名为 (string $branch): void，
     *        在分支 smartcast 应用后、处理分支语句前调用；$branch 为 'then'/'elseif'/'else'。
     *        用于在分支上下文中查询 smartcast 状态。
     */
    public function processIfStmt(int $ifNodeIdx, ?callable $onBranch = null): void
    {
        if (!isset($this->ast->nodes[$ifNodeIdx])) return;
        $ifNode = $this->ast->nodes[$ifNodeIdx];
        if ($ifNode['kind'] !== NodeKind::IfStmtNode) return;

        $extra        = $ifNode['extra'];
        $thenCount    = $extra['thenBodyCount'] ?? 0;
        $elseifCount  = $extra['elseifCount']   ?? 0;
        $elseCount    = $extra['elseBodyCount'] ?? 0;

        $condIdx   = $this->ast->child($ifNodeIdx, 0);
        $patterns  = $this->collectPatterns($condIdx);
        $positivePats = array_filter($patterns, fn($p) => !$p['negated']);
        $negativePats = array_filter($patterns, fn($p) => $p['negated']);

        // ── then 分支：正向模式缩窄 ──
        $childOffset = 1;
        $this->scopeTree->enterScope(0, 0);
        foreach ($positivePats as $pat) {
            $this->scopeTree->addSmartcast($pat['var'], $pat['class']);
        }
        if ($onBranch !== null) $onBranch('then');
        for ($i = 0; $i < $thenCount; $i++) {
            $this->processStmt($this->ast->child($ifNodeIdx, $childOffset + $i), $onBranch);
        }
        $this->scopeTree->leaveScope();
        $childOffset += $thenCount;

        // ── elseif 分支：按各自条件的正向模式缩窄 ──
        for ($e = 0; $e < $elseifCount; $e++) {
            $elifIdx       = $this->ast->child($ifNodeIdx, $childOffset + $e);
            $elifNode      = $this->ast->nodes[$elifIdx];
            $elifBodyCount = $elifNode['extra']['bodyCount'] ?? 0;
            $elifCondIdx   = $this->ast->child($elifIdx, 0);
            $elifPats      = $this->collectPatterns($elifCondIdx);
            $elifPositive  = array_filter($elifPats, fn($p) => !$p['negated']);

            $this->scopeTree->enterScope(0, 0);
            foreach ($elifPositive as $pat) {
                $this->scopeTree->addSmartcast($pat['var'], $pat['class']);
            }
            if ($onBranch !== null) $onBranch('elseif');
            for ($i = 0; $i < $elifBodyCount; $i++) {
                $this->processStmt($this->ast->child($elifIdx, 1 + $i), $onBranch);
            }
            $this->scopeTree->leaveScope();
        }
        $childOffset += $elseifCount;

        // ── else 分支：否定模式的反义缩窄 ──
        //   !($x instanceof Foo) 在 else 中为假 → $x instanceof Foo 成立 → 缩窄为 Foo
        if ($elseCount > 0) {
            $this->scopeTree->enterScope(0, 0);
            foreach ($negativePats as $pat) {
                $this->scopeTree->addSmartcast($pat['var'], $pat['class']);
            }
            if ($onBranch !== null) $onBranch('else');
            for ($i = 0; $i < $elseCount; $i++) {
                $this->processStmt($this->ast->child($ifNodeIdx, $childOffset + $i), $onBranch);
            }
            $this->scopeTree->leaveScope();
        }
    }

    /**
     * 处理单条语句：若为 if 语句则递归处理（支持嵌套 instanceof 缩窄）。
     */
    private function processStmt(int $stmtIdx, ?callable $onBranch = null): void
    {
        if (!isset($this->ast->nodes[$stmtIdx])) return;
        $node = $this->ast->nodes[$stmtIdx];
        if ($node['kind'] === NodeKind::IfStmtNode) {
            $this->processIfStmt($stmtIdx, $onBranch);
        }
        // 其他语句类型暂不递归（smartcast 主要针对 if 分支）
    }
}
