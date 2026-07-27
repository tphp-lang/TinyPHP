<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// ScopeTree — 作用域树
//   以树形结构管理作用域，支持按源码位置二分查找最内层作用域。
//   与 SymbolTable 的扁平/栈式作用域管理并存，供 FlatTypeChecker
//   等组件按需选用。
//
// 设计要点：
//   - children 数组始终保持按 startPos 升序（enterScope 时二分插入）
//   - innermost(pos) 每层二分查找，整体 O(log² n)
//   - 变量/smartcast 查找沿 parent 链向上递归
// ═══════════════════════════════════════════════════════════════

/**
 * 单个作用域节点。
 */
class Scope
{
    /** @var array<string,mixed> 变量名 => 类型信息 */
    public array $objects = [];

    /** 父作用域（根为 null） */
    public ?Scope $parent = null;

    /** @var Scope[] 子作用域数组（按 startPos 升序） */
    public array $children = [];

    /** 起始源码位置（字符偏移或行号） */
    public int $startPos = 0;

    /** 结束源码位置 */
    public int $endPos = 0;

    /** @var array<string,mixed> 变量名 => 缩窄类型（用于 instanceof smartcast） */
    public array $smartcasts = [];

    /** 嵌套深度（根 = 0） */
    public int $depth = 0;

    public function __construct(int $startPos = 0, int $endPos = 0, ?Scope $parent = null, int $depth = 0)
    {
        $this->startPos = $startPos;
        $this->endPos   = $endPos;
        $this->parent   = $parent;
        $this->depth    = $depth;
    }
}

/**
 * 作用域树。
 */
class ScopeTree
{
    /** 根作用域 */
    private Scope $root;

    /** 当前作用域（指向树中某节点） */
    private ?Scope $current;

    public function __construct()
    {
        $this->root    = new Scope(0, PHP_INT_MAX, null, 0);
        $this->current = $this->root;
    }

    /** 返回根作用域。 */
    public function getRoot(): Scope
    {
        return $this->root;
    }

    /** 返回当前作用域。 */
    public function getCurrent(): Scope
    {
        return $this->current;
    }

    /**
     * 进入新子作用域：创建 Scope，按 startPos 升序插入到 current->children，
     * 并将 current 指向新作用域。
     *
     * @return Scope 新创建的作用域
     */
    public function enterScope(int $startPos, int $endPos): Scope
    {
        $scope = new Scope($startPos, $endPos, $this->current, $this->current->depth + 1);
        $this->insertChildSorted($this->current, $scope);
        $this->current = $scope;
        return $scope;
    }

    /** 离开当前作用域，回到父作用域。 */
    public function leaveScope(): void
    {
        if ($this->current->parent !== null) {
            $this->current = $this->current->parent;
        }
    }

    /** 在当前作用域声明变量。 */
    public function declareVar(string $name, mixed $type): void
    {
        $this->current->objects[$name] = $type;
    }

    /**
     * 沿 current->parent 链查找变量。
     *
     * @return mixed 找到的类型；未找到返回 null
     */
    public function lookupVar(string $name): mixed
    {
        $scope = $this->current;
        while ($scope !== null) {
            if (array_key_exists($name, $scope->objects)) {
                return $scope->objects[$name];
            }
            $scope = $scope->parent;
        }
        return null;
    }

    /** 在当前作用域添加 smartcast（变量名 => 缩窄类型）。 */
    public function addSmartcast(string $varName, mixed $narrowedType): void
    {
        $this->current->smartcasts[$varName] = $narrowedType;
    }

    /**
     * 沿 parent 链查找 smartcast。
     *
     * @return mixed 找到的缩窄类型；未找到返回 null
     */
    public function lookupSmartcast(string $varName): mixed
    {
        $scope = $this->current;
        while ($scope !== null) {
            if (array_key_exists($varName, $scope->smartcasts)) {
                return $scope->smartcasts[$varName];
            }
            $scope = $scope->parent;
        }
        return null;
    }

    /**
     * 二分查找定位最内层包含 pos 的 Scope。
     *
     * 算法：从 root 开始，在当前 Scope 的 children（按 startPos 升序）中
     * 二分查找满足 child.startPos <= pos <= child.endPos 的 child；
     * 找到则递归进入该 child 继续；未找到则返回当前 Scope。
     *
     * @param int $pos 源码位置
     * @return Scope 最内层包含 pos 的 Scope
     */
    public function innermost(int $pos): Scope
    {
        $scope = $this->root;
        while (true) {
            $children = $scope->children;
            $n = count($children);
            if ($n === 0) {
                break;
            }
            $found = null;
            $lo = 0;
            $hi = $n - 1;
            while ($lo <= $hi) {
                $mid = ($lo + $hi) >> 1;
                $child = $children[$mid];
                if ($child->startPos <= $pos) {
                    if ($pos <= $child->endPos) {
                        $found = $child;
                        break;
                    }
                    $lo = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }
            if ($found === null) {
                break;
            }
            $scope = $found;
        }
        return $scope;
    }

    /**
     * 可视化作用域树（缩进表示层级）。
     * 示例：
     *   Scope[0-1000] vars={a:int, b:string}
     *     Scope[10-50] vars={x:int}
     *     Scope[60-100] vars={y:string} smartcasts={x:Foo}
     */
    public function show(): string
    {
        $lines = [];
        $this->render($this->root, 0, $lines);
        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────────────────
    // 内部辅助
    // ──────────────────────────────────────────────────────────

    /**
     * 将 child 按 startPos 升序插入到 parent->children 中（二分定位 + array_splice）。
     * 若 startPos 相同，新作用域追加到同 startPos 节点之后（保持稳定）。
     */
    private function insertChildSorted(Scope $parent, Scope $child): void
    {
        $children = $parent->children;
        $n = count($children);
        if ($n === 0) {
            $parent->children[] = $child;
            return;
        }
        $lo = 0;
        $hi = $n - 1;
        // 二分查找第一个 startPos > child.startPos 的位置
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($children[$mid]->startPos <= $child->startPos) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        // $lo 即为插入位置
        array_splice($parent->children, $lo, 0, [$child]);
    }

    /** 递归渲染作用域树。 */
    private function render(Scope $scope, int $indent, array &$lines): void
    {
        $prefix = str_repeat('  ', $indent);
        $vars = [];
        foreach ($scope->objects as $name => $type) {
            $vars[] = $name . ':' . self::formatType($type);
        }
        $varsStr = implode(', ', $vars);
        $line = $prefix . "Scope[{$scope->startPos}-{$scope->endPos}] vars={" . $varsStr . "}";
        if (!empty($scope->smartcasts)) {
            $sc = [];
            foreach ($scope->smartcasts as $name => $type) {
                $sc[] = $name . ':' . self::formatType($type);
            }
            $line .= " smartcasts={" . implode(', ', $sc) . "}";
        }
        $lines[] = $line;
        foreach ($scope->children as $child) {
            $this->render($child, $indent + 1, $lines);
        }
    }

    /** 将类型值格式化为字符串。 */
    private static function formatType(mixed $type): string
    {
        if (is_string($type)) {
            return $type;
        }
        if (is_object($type) && method_exists($type, '__toString')) {
            return (string)$type;
        }
        if (is_object($type)) {
            return get_class($type);
        }
        if (is_array($type)) {
            return 'array';
        }
        if ($type === null) {
            return 'null';
        }
        if (is_bool($type)) {
            return $type ? 'true' : 'false';
        }
        if (is_int($type) || is_float($type)) {
            return (string)$type;
        }
        return 'mixed';
    }
}
