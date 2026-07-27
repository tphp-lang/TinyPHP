<?php

declare(strict_types=1);

// ============================================================
// FlatAst — 扁平化 AST 数据结构
//
// 设计目标：
//   - 所有节点存储在单个 $nodes 数组中（cache 友好的连续内存）
//   - 子节点关系通过独立的 $children 索引数组 + (children_start, children_count) 引用
//   - 节点访问 O(1)：child(i) = $children[$nodes[$i]['children_start'] + offset]
//   - 与现有 src/AST/Node.php 类继承式 AST 并存，后续 Task 2-4 切换 Parser/TypeChecker/CodeGenerator
//
// 节点结构（关联数组）：
//   [
//     'kind'            => NodeKind,       // 节点类型枚举
//     'value'           => mixed,          // 字面量/标识符等标量数据
//     'typ'             => int,            // 类型索引（TypeChecker 填充，0=未推导）
//     'children_start'  => int,            // 在 $children 数组中的起始位置
//     'children_count'  => int,            // 子节点数量
//     'pos'             => [line, col],    // 源码位置
//     'extra'           => array,          // 额外元数据（attributes、flags 等）
//   ]
// ============================================================

// ═══════════════════════════════════════════════════════════════
// NodeKind — AST 节点类型枚举
//
// 覆盖 src/AST/Node.php 中所有节点类（包括辅助结构如 ElseIfBranch、
// CaseBranch、ArrayEntryNode、MatchArm、EnumCaseNode、PropertyHook）。
// 使用 int 回退类型，便于紧凑存储与快速比较。
// 命名与现有类名一一对应（保留 Node/Expr 后缀以避免与 PHP 关键字冲突）。
// ═══════════════════════════════════════════════════════════════
enum NodeKind: int
{
    // === 顶层结构 ===
    case ProgramNode       = 1;
    case FunctionNode      = 2;
    case ClassNode         = 3;
    case MethodNode        = 4;
    case PropertyDeclNode  = 5;
    case PropertyHook      = 6;
    case ParamNode         = 7;
    case ConstNode         = 8;
    case EnumNode          = 9;
    case EnumCaseNode      = 10;
    case AttributeDeclNode = 11;
    case AttributeUseNode  = 12;

    // === 语句 (StmtNode 子类) ===
    case EchoStmtNode           = 13;
    case ReturnStmtNode         = 14;
    case AssignStmtNode         = 15;
    case ListStmtNode           = 16;
    case AssignPropStmtNode     = 17;
    case AssignArrayStmtNode    = 18;
    case AssignArrayPushStmtNode = 19;
    case IfStmtNode             = 20;
    case ElseIfBranch           = 21;
    case WhileStmtNode          = 22;
    case DoWhileStmtNode        = 23;
    case ForStmtNode            = 24;
    case ForeachStmtNode        = 25;
    case SwitchStmtNode         = 26;
    case CaseBranch             = 27;
    case BreakStmtNode          = 28;
    case GotoStmtNode           = 29;
    case TryStmtNode            = 30;
    case ThrowStmtNode          = 31;
    case LabelStmtNode          = 32;
    case ContinueStmtNode       = 33;
    case ExprStmtNode           = 34;
    case NopStmtNode            = 35;
    case StaticStmtNode         = 36;
    case ConstStmtNode          = 37;
    case BlockStmtNode          = 38;
    case DeferStmtNode          = 39;

    // === 表达式 (ExprNode 子类) ===
    case StringLiteralExpr     = 40;
    case IntLiteralExpr        = 41;
    case FloatLiteralExpr      = 42;
    case BoolLiteralExpr       = 43;
    case NullLiteralExpr       = 44;
    case MagicConstExpr        = 45;
    case ArrayEntryNode        = 46;
    case ArrayLiteralExpr      = 47;
    case ArrayAccessExpr       = 48;
    case ArrayAppendExpr       = 49;
    case PropertyAccessExpr    = 50;
    case EnumAccessExpr        = 51;
    case ClosureExpr           = 52;
    case YieldExpr             = 53;
    case YieldFromExpr         = 54;
    case PipeExpr              = 55;
    case PlaceholderExpr       = 56;
    case CallableConvertExpr   = 57;
    case VariableExpr          = 58;
    case UnaryExpr             = 59;
    case PostfixExpr           = 60;
    case CompoundAssignExpr    = 61;
    case BinaryExpr            = 62;
    case TernaryExpr           = 63;
    case NullCoalesceExpr      = 64;
    case MatchArm              = 65;
    case MatchExpr             = 66;
    case CallExpr              = 67;
    case CastExpr              = 68;
    case NewExpr               = 69;
    case ThrowExprNode         = 70;
    case OrBlockExpr           = 71;
}

// ═══════════════════════════════════════════════════════════════
// FlatAst — 扁平化 AST 容器
//
// 内存布局：
//   $nodes[k]      = 第 k 个节点（关联数组）
//   $children[j]   = 子节点索引（指向 $nodes 的下标）
//   节点 k 的第 i 个子节点索引 = $children[$nodes[k]['children_start'] + i]
//
// 典型用法（自底向上构建）：
//   $ast = new FlatAst();
//   $lit1 = $ast->makeNode(NodeKind::IntLiteralExpr, 1);
//   $lit2 = $ast->makeNode(NodeKind::IntLiteralExpr, 2);
//   $add  = $ast->makeNode(NodeKind::BinaryExpr, '+', [$lit1, $lit2]);
//   $root = $ast->makeNode(NodeKind::ExprStmtNode, null, [$add]);
//   $ast->root = $root;
// ═══════════════════════════════════════════════════════════════
class FlatAst
{
    /** @var array<int, array> 所有 AST 节点（按创建顺序连续存储） */
    public array $nodes = [];

    /** @var array<int, int> 子节点索引数组（节点通过 children_start + children_count 引用一段） */
    public array $children = [];

    /** 源文件路径（元数据） */
    public string $sourceFile = '';

    /** 根节点索引（-1 = 未设置） */
    public int $root = -1;

    /** 已创建节点数（等于 count($nodes)，缓存以避免反复计数） */
    public int $nodeCount = 0;

    public function __construct(string $sourceFile = '')
    {
        $this->sourceFile = $sourceFile;
    }

    // ─────────────────────────────────────────────────────────────
    // 节点构建
    // ─────────────────────────────────────────────────────────────

    /**
     * 创建节点并返回其索引。
     *
     * @param NodeKind      $kind     节点类型
     * @param mixed         $value    标量数据（字面量值、标识符名、操作符等）
     * @param array<int>    $children 子节点索引数组（按顺序追加到 $children 末尾）
     * @param array{int,int}|int $pos 源码位置 [line, col] 或 int 偏移
     * @param array         $extra    额外元数据（attributes、flags 等）
     * @return int 节点索引（在 $nodes 中的下标）
     */
    public function makeNode(
        NodeKind $kind,
        mixed $value = null,
        array $children = [],
        array|int $pos = [0, 0],
        array $extra = [],
    ): int {
        $start = count($this->children);
        $count = count($children);
        // 把传入的子节点索引追加到 $children 数组末尾
        foreach ($children as $childIdx) {
            $this->children[] = $childIdx;
        }

        $idx = $this->nodeCount;
        $this->nodes[$idx] = [
            'kind'            => $kind,
            'value'           => $value,
            'typ'             => 0,
            'children_start'  => $start,
            'children_count'  => $count,
            'pos'             => $pos,
            'extra'           => $extra,
        ];
        $this->nodeCount++;
        return $idx;
    }

    /**
     * 向父节点追加一个子节点。
     *
     * 若父节点的子节点段正好位于 $children 数组末尾，直接追加（O(1)）。
     * 否则将现有子节点重定位到末尾再追加（O(n)，n=现有子节点数，分摊 O(1)）。
     *
     * @param int $parentIdx 父节点索引
     * @param int $childIdx  子节点索引
     */
    public function appendChild(int $parentIdx, int $childIdx): void
    {
        $node = &$this->nodes[$parentIdx];
        $start = $node['children_start'];
        $count = $node['children_count'];
        $tail  = count($this->children);

        // 父节点的子节点段已位于末尾：直接追加
        if ($start + $count === $tail) {
            $this->children[] = $childIdx;
            $node['children_count'] = $count + 1;
            return;
        }

        // 否则：把现有子节点复制到末尾，然后追加新子节点
        if ($count > 0) {
            for ($i = 0; $i < $count; $i++) {
                $this->children[] = $this->children[$start + $i];
            }
        }
        $this->children[] = $childIdx;
        $node['children_start'] = $tail;
        $node['children_count'] = $count + 1;
    }

    // ─────────────────────────────────────────────────────────────
    // 节点访问（均 O(1)）
    // ─────────────────────────────────────────────────────────────

    /**
     * 返回父节点的第 N 个子节点索引。
     *
     * @param int $nodeIdx     父节点索引
     * @param int $childOffset 子节点偏移（从 0 开始）
     * @return int 子节点在 $nodes 中的索引
     */
    public function child(int $nodeIdx, int $childOffset): int
    {
        $node = $this->nodes[$nodeIdx];
        return $this->children[$node['children_start'] + $childOffset];
    }

    /**
     * 返回父节点的第 N 个子节点本身（关联数组引用）。
     *
     * @param int $nodeIdx     父节点索引
     * @param int $childOffset 子节点偏移
     * @return array 子节点数组
     */
    public function childNode(int $nodeIdx, int $childOffset): array
    {
        return $this->nodes[$this->child($nodeIdx, $childOffset)];
    }

    /**
     * 返回父节点的所有子节点索引数组（切片副本）。
     *
     * @param int $nodeIdx 父节点索引
     * @return array<int, int> 子节点索引列表
     */
    public function children(int $nodeIdx): array
    {
        $node = $this->nodes[$nodeIdx];
        $start = $node['children_start'];
        $count = $node['children_count'];
        if ($count === 0) {
            return [];
        }
        return array_slice($this->children, $start, $count);
    }

    /**
     * 返回父节点的子节点数量（O(1)）。
     */
    public function childCount(int $nodeIdx): int
    {
        return $this->nodes[$nodeIdx]['children_count'];
    }

    // ─────────────────────────────────────────────────────────────
    // 克隆 / 切片
    // ─────────────────────────────────────────────────────────────

    /**
     * 克隆整棵 AST（深拷贝 $nodes 与 $children，但保留节点内 value 引用）。
     * 用于快照或并行变换。
     */
    public function clone(): FlatAst
    {
        $copy = new self($this->sourceFile);
        // 数组赋值是值拷贝（PHP 数组语义）
        $copy->nodes    = $this->nodes;
        $copy->children = $this->children;
        $copy->nodeCount = $this->nodeCount;
        $copy->root     = $this->root;
        return $copy;
    }

    /**
     * 切片：返回从指定节点开始的子树构成的新 FlatAst。
     * 节点索引会重新映射到从 0 开始；子节点索引同步重映射。
     *
     * @param int $rootIdx 子树根节点索引
     * @return FlatAst 新的 FlatAst（仅包含子树节点）
     */
    public function slice(int $rootIdx): FlatAst
    {
        $copy = new self($this->sourceFile);
        // 旧索引 => 新索引 映射表
        $map = [];
        $queue = [$rootIdx];
        $visited = [];
        while ($queue !== []) {
            $old = array_shift($queue);
            if (isset($visited[$old])) continue;
            $visited[$old] = true;
            $newIdx = $copy->nodeCount;
            $map[$old] = $newIdx;
            $oldNode = $this->nodes[$old];
            // 把子节点（旧索引）入队，等待 BFS 遍历
            for ($i = 0; $i < $oldNode['children_count']; $i++) {
                $queue[] = $this->children[$oldNode['children_start'] + $i];
            }
            // 先占位（children_start 等到子节点都创建后再修正）
            $copy->nodes[$newIdx] = [
                'kind'            => $oldNode['kind'],
                'value'           => $oldNode['value'],
                'typ'             => $oldNode['typ'],
                'children_start'  => 0,
                'children_count'  => $oldNode['children_count'],
                'pos'             => $oldNode['pos'],
                'extra'           => $oldNode['extra'],
            ];
            $copy->nodeCount++;
        }
        // 第二遍：为每个新节点填充 children 段
        foreach ($map as $old => $new) {
            $oldNode = $this->nodes[$old];
            $start = count($copy->children);
            for ($i = 0; $i < $oldNode['children_count']; $i++) {
                $oldChild = $this->children[$oldNode['children_start'] + $i];
                $copy->children[] = $map[$oldChild];
            }
            $copy->nodes[$new]['children_start'] = $start;
        }
        $copy->root = $map[$rootIdx] ?? -1;
        return $copy;
    }

    // ─────────────────────────────────────────────────────────────
    // 遍历辅助
    // ─────────────────────────────────────────────────────────────

    /**
     * 从指定节点开始深度优先遍历，对每个节点调用回调。
     *
     * @param int        $nodeIdx 起始节点
     * @param callable(int $idx, array $node): void $cb 回调
     */
    public function traverse(int $nodeIdx, callable $cb): void
    {
        $stack = [$nodeIdx];
        while ($stack !== []) {
            $idx = array_pop($stack);
            $node = $this->nodes[$idx];
            $cb($idx, $node);
            // 逆序压栈，保证左-to-右访问顺序
            for ($i = $node['children_count'] - 1; $i >= 0; $i--) {
                $stack[] = $this->children[$node['children_start'] + $i];
            }
        }
    }
}
