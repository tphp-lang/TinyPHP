<?php
// ext/ui/src/ui_layout.php — UI 扩展布局系统
//
// 定义 Layout 接口和具体布局类（Stack/CanvasLayout）。
// 所有布局类使用 namespace UI，通过 use UI\Stack; 等引入。
//
// 设计说明：
//   - Layout 接口扩展 Widget，布局本身也是控件（可嵌套）
//   - Stack：flex 风格布局，支持 Row/Column 方向、compact/stretch/fixed 尺寸模式
//   - CanvasLayout：绝对定位布局，子控件位置由用户指定
//   - 布局算法完整实现（spacing/padding 正确应用，值夹紧）
//   - 不使用 nullable，不使用 union 返回类型

namespace UI;

use Exception;

// ════════════════════════════════════════════════════════════
// Layout 抽象基类 — 布局控件基类
//
// 扩展 Widget，增加布局管理方法：
//   addWidget(w, a, b, c, d) — 添加子控件（参数语义由具体布局定义）
//   updateLayout()           — 重新计算子控件位置和尺寸
//   asWidget()               — 返回自身作为 Widget（用于嵌套）
//
// 参数语义：
//   Stack:         a=sizeMode(ChildSize), b=fixedDimension
//   CanvasLayout:  a=x, b=y, c=width, d=height
// ════════════════════════════════════════════════════════════

abstract class Layout extends Widget
{
    public function addWidget(Widget $w, int $a = 0, int $b = 0, int $c = 0, int $d = 0): void {}
    public function updateLayout(): void {}
    public function asWidget(): Widget { return $this; }
}

// ════════════════════════════════════════════════════════════
// Stack — flex 风格线性布局
//
// 支持：
//   - 方向：Row（水平排列）/ Column（垂直排列）
//   - 尺寸模式：Compact（自然尺寸）/ Stretch（均分剩余空间）/ Fixed（指定像素）
//   - spacing：子控件间距
//   - padding：容器内边距
//
// 用法：
//   $row = Stack::row($btn1, $btn2, $label);
//   $row->setPos(10, 10);
//   $row->updateLayout();
//   $row->draw();
//
// 布局算法（完整实现）：
//   1. 计算可用主轴空间 = bounds.size - 2*padding - (childCount-1)*spacing
//   2. 第一遍：获取 Compact 子项的自然尺寸，累计 Fixed 子项的指定尺寸
//   3. 计算 Stretch 子项的尺寸 = 剩余空间 / stretchCount
//   4. 第二遍：按方向排列子项，设置位置和尺寸
//   5. 交叉轴：子项尺寸 = 可用交叉轴空间（bounds 交叉尺寸 - 2*padding）
// ════════════════════════════════════════════════════════════

class Stack extends Layout
{
    public int $direction = 0;         // Direction: Row=0, Column=1
    public int $spacing = 4;           // 子控件间距
    public int $padding = 0;           // 容器内边距
    public bool $initialized = false;

    // 子控件列表（平行数组）
    public array $children = [];       // Widget 列表
    public array $sizeModes = [];      // ChildSize 值列表
    public array $dimensions = [];     // Fixed 模式的指定尺寸

    public function __construct(int $direction = 0)
    {
        parent::__construct();
        $this->direction = $direction;
    }

    // ── 便捷构造 ──

    public static function column(...$children): Stack
    {
        $s = new Stack(1);  // Direction::Column
        $count = count($children);
        for ($i = 0; $i < $count; $i++) {
            $s->addWidget($children[$i], 0, 0);  // Compact 模式
        }
        return $s;
    }

    public static function row(...$children): Stack
    {
        $s = new Stack(0);  // Direction::Row
        $count = count($children);
        for ($i = 0; $i < $count; $i++) {
            $s->addWidget($children[$i], 0, 0);  // Compact 模式
        }
        return $s;
    }

    // ── Layout 接口实现 ──

    public function addWidget(Widget $w, int $a = 0, int $b = 0, int $c = 0, int $d = 0): void
    {
        $this->children[] = $w;
        $this->sizeModes[] = $a;       // a = sizeMode (ChildSize)
        $this->dimensions[] = $b;      // b = fixedDimension
    }

    public function asWidget(): Widget
    {
        return $this;
    }

    // ── 布局算法（完整实现）──

    public function updateLayout(): void
    {
        $count = count($this->children);
        if ($count === 0) return;

        $isRow = ($this->direction === 0);  // Row=0

        // 可用空间（主轴和交叉轴）
        $availMain = ($isRow ? $this->bounds->width : $this->bounds->height) - 2 * $this->padding;
        $availCross = ($isRow ? $this->bounds->height : $this->bounds->width) - 2 * $this->padding;
        $availMain = $availMain - ($count - 1) * $this->spacing;
        if ($availMain < 0) { $availMain = 0; }
        if ($availCross < 0) { $availCross = 0; }

        // 第一遍：计算 Compact 和 Fixed 子项尺寸，统计 Stretch 数量
        // 注：TinyPHP 使用静态分派（无 vtable），$this->children[$i]->proposeSize()
        // 会调用 Widget 基类方法（返回 [0,0]）而非子类覆盖。因此 Compact 模式
        // 直接读取预先计算的 bounds（用户在 addWidget 前应先调用 proposeSize）。
        $stretchCount = 0;
        $usedMain = 0;
        array<int> $childSizes = [];  // 每个子项的主轴尺寸

        for ($i = 0; $i < $count; $i++) {
            $mode = $this->sizeModes[$i];
            if ($mode === 0) {
                // Compact：读取子项预计算的 bounds（主轴尺寸）
                $ms = $isRow ? $this->children[$i]->bounds->width : $this->children[$i]->bounds->height;
                $childSizes[] = $ms;
                $usedMain = $usedMain + $ms;
            } elseif ($mode === 1) {
                // Stretch：稍后计算
                $stretchCount = $stretchCount + 1;
                $childSizes[] = 0;
            } else {
                // Fixed：使用指定尺寸（+ 0 将 t_var 转换为 t_int，匹配 array<int> 元素类型）
                $childSizes[] = $this->dimensions[$i] + 0;
                $usedMain = $usedMain + $this->dimensions[$i];
            }
        }

        // 计算 Stretch 子项的尺寸
        $stretchSize = 0;
        if ($stretchCount > 0) {
            $remain = $availMain - $usedMain;
            if ($remain < 0) { $remain = 0; }
            $stretchSize = (int)($remain / $stretchCount);
        }

        // 填充 Stretch 子项的尺寸
        for ($i = 0; $i < $count; $i++) {
            if ($this->sizeModes[$i] === 1) {
                $childSizes[$i] = $stretchSize;
            }
        }

        // 第二遍：设置每个子项的位置和尺寸
        $startMain = $this->padding;
        $startCross = $this->padding;
        $pos = $startMain;

        for ($i = 0; $i < $count; $i++) {
            $ms = $childSizes[$i];

            if ($isRow) {
                // Row：x 递增，y = padding，width = 主轴尺寸，height = 交叉轴尺寸
                $this->children[$i]->bounds->x = $this->bounds->x + $pos;
                $this->children[$i]->bounds->y = $this->bounds->y + $startCross;
                $this->children[$i]->bounds->width = $ms;
                $this->children[$i]->bounds->height = $availCross;
            } else {
                // Column：y 递增，x = padding，height = 主轴尺寸，width = 交叉轴尺寸
                $this->children[$i]->bounds->x = $this->bounds->x + $startCross;
                $this->children[$i]->bounds->y = $this->bounds->y + $pos;
                $this->children[$i]->bounds->width = $availCross;
                $this->children[$i]->bounds->height = $ms;
            }

            $pos = $pos + $ms + $this->spacing;
        }
    }

    // ── Widget 接口实现 ──

    public function init(): void
    {
        $this->initialized = true;
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->init();
        }
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->draw();
        }
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
        $this->updateLayout();
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        $count = count($this->children);
        if ($count === 0) {
            $this->bounds->width = 0;
            $this->bounds->height = 0;
            return [0, 0];
        }

        $isRow = ($this->direction === 0);
        $totalMain = 0;
        $maxCross = 0;

        // 注：静态分派限制，读取子项预计算的 bounds 而非调用 proposeSize
        for ($i = 0; $i < $count; $i++) {
            $cw = $this->children[$i]->bounds->width;
            $ch = $this->children[$i]->bounds->height;
            if ($isRow) {
                $totalMain = $totalMain + $cw;
                if ($ch > $maxCross) { $maxCross = $ch; }
            } else {
                $totalMain = $totalMain + $ch;
                if ($cw > $maxCross) { $maxCross = $cw; }
            }
        }

        $totalMain = $totalMain + ($count - 1) * $this->spacing;
        $totalMain = $totalMain + 2 * $this->padding;
        $maxCross = $maxCross + 2 * $this->padding;

        if ($isRow) {
            $this->bounds->width = $totalMain;
            $this->bounds->height = $maxCross;
            return [$totalMain, $maxCross];
        } else {
            $this->bounds->width = $maxCross;
            $this->bounds->height = $totalMain;
            return [$maxCross, $totalMain];
        }
    }

    public function size(): array
    {
        return [$this->bounds->width, $this->bounds->height];
    }

    public function pointInside(int $x, int $y): bool
    {
        return $this->bounds->contains($x, $y);
    }

    public function cleanup(): void
    {
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->cleanup();
        }
        $this->children = [];
        $this->sizeModes = [];
        $this->dimensions = [];
        $this->initialized = false;
    }

    // ── 事件处理（多态分发，布局本身不处理事件，由 WidgetContainer 分发）──

    public function onMouseDown(int $x, int $y): void {}
    public function onMouseUp(int $x, int $y): void {}
    public function onMouseMove(int $x, int $y): void {}
    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}
}

// ════════════════════════════════════════════════════════════
// CanvasLayout — 绝对定位布局
//
// 子控件位置由用户在 addWidget 时指定，布局不做自动排列。
// 适用于需要精确控制控件位置的场景。
//
// 用法：
//   $canvas = new CanvasLayout();
//   $canvas->addWidget($btn, 10, 10, 80, 24);
//   $canvas->addWidget($label, 100, 10, 200, 20);
//   $canvas->setPos(0, 0);
//   $canvas->draw();
// ════════════════════════════════════════════════════════════

class CanvasLayout extends Layout
{
    public bool $initialized = false;

    // 子控件列表（平行数组）
    public array $children = [];       // Widget 列表
    public array $childX = [];         // x 坐标
    public array $childY = [];         // y 坐标
    public array $childW = [];         // 宽度
    public array $childH = [];         // 高度

    public function __construct()
    {
        parent::__construct();
    }

    // ── Layout 接口实现 ──

    // a=x, b=y, c=width, d=height
    public function addWidget(Widget $w, int $a = 0, int $b = 0, int $c = 0, int $d = 0): void
    {
        $this->children[] = $w;
        $this->childX[] = $a;
        $this->childY[] = $b;
        $this->childW[] = $c;
        $this->childH[] = $d;

        // 设置子控件的边界
        $w->bounds->x = $a;
        $w->bounds->y = $b;
        $w->bounds->width = $c;
        $w->bounds->height = $d;
    }

    public function asWidget(): Widget
    {
        return $this;
    }

    public function updateLayout(): void
    {
        // 绝对定位：位置在 addWidget 时已设置，无需重新计算
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            // + 0 将 t_var（array 属性元素）转换为 t_int（匹配 bounds 的 int 属性）
            $this->children[$i]->bounds->x = $this->childX[$i] + 0;
            $this->children[$i]->bounds->y = $this->childY[$i] + 0;
            $this->children[$i]->bounds->width = $this->childW[$i] + 0;
            $this->children[$i]->bounds->height = $this->childH[$i] + 0;
        }
    }

    // ── Widget 接口实现 ──

    public function init(): void
    {
        $this->initialized = true;
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->init();
        }
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->draw();
        }
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
        // 绝对定位布局不移动子控件（位置相对于容器边界）
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        $w = $availableW;
        $h = $availableH;
        $this->bounds->width = $w;
        $this->bounds->height = $h;
        return [$w, $h];
    }

    public function size(): array
    {
        return [$this->bounds->width, $this->bounds->height];
    }

    public function pointInside(int $x, int $y): bool
    {
        return $this->bounds->contains($x, $y);
    }

    public function cleanup(): void
    {
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->cleanup();
        }
        $this->children = [];
        $this->childX = [];
        $this->childY = [];
        $this->childW = [];
        $this->childH = [];
        $this->initialized = false;
    }

    // ── 事件处理（多态分发，布局本身不处理事件，由 WidgetContainer 分发）──

    public function onMouseDown(int $x, int $y): void {}
    public function onMouseUp(int $x, int $y): void {}
    public function onMouseMove(int $x, int $y): void {}
    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}
}
