<?php
// ext/ui/src/ui_widget.php — UI 扩展控件系统
//
// 定义 Widget 接口和具体控件类（Button/Label/TextBox/CheckBox/Slider）。
// 所有控件使用 namespace UI，通过 use UI\Button; 等引入。
//
// 设计说明：
//   - Widget 接口定义控件生命周期：init → proposeSize → setPos → draw → cleanup
//   - 每个控件有 public Rect $bounds 属性，布局系统直接读写
//   - draw() 在未初始化时抛 Exception("widget not initialized")
//   - 回调存储为 mixed = null（TinyPHP 不支持 nullable，用 null 默认值）
//   - 值夹紧（Slider value、TextBox cursor）完整实现
//   - 绘图调用 Graphics 静态方法（底层 C 函数，骨架阶段部分为 TODO）
//   - 不使用 union 返回类型，不使用 nullable 类型

namespace UI;

use Exception;

// ════════════════════════════════════════════════════════════
// Widget 抽象基类 — 所有控件的基类
//
// 生命周期：
//   1. init()              — 初始化控件状态
//   2. proposeSize(aw, ah) — 根据可用空间提议尺寸，返回 [w, h]
//   3. setPos(x, y)        — 设置位置（由布局系统调用）
//   4. draw()              — 渲染控件（在 frame 回调内调用）
//   5. cleanup()           — 释放资源
//
// 命中测试：
//   pointInside(x, y) — 判断坐标是否在控件内（用于事件分发）
//
// 注：TinyPHP CodeGenerator 对 interface 不生成方法实现，直接调用
//   会产生链接错误。改为 abstract class + stub 方法确保方法实现存在。
//   子类覆盖 stub 方法（直接调用，非 vtable 分发）。
// ════════════════════════════════════════════════════════════

abstract class Widget
{
    public Rect $bounds;

    public function __construct()
    {
        $this->bounds = new Rect(0, 0, 0, 0);
    }

    public function init(): void {}
    public function draw(): void {}
    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
    }
    public function proposeSize(int $availableW, int $availableH): array
    {
        return [0, 0];
    }
    public function size(): array
    {
        return [$this->bounds->width, $this->bounds->height];
    }
    public function pointInside(int $x, int $y): bool
    {
        return $this->bounds->contains($x, $y);
    }
    public function cleanup(): void {}
    public function onMouseDown(int $x, int $y): void {}
    public function onMouseUp(int $x, int $y): void {}
    public function onMouseMove(int $x, int $y): void {}
    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}
}

// ════════════════════════════════════════════════════════════
// WidgetContainer — 控件容器，管理子控件列表和事件分发
//
// 功能：
//   - addChild(Widget)      — 添加子控件（z-index = 插入顺序，后加 = 上层）
//   - removeChild(Widget)   — 移除子控件
//   - drawAll()             — 按 z-index 升序绘制所有子控件
//   - dispatchMouseDown(x,y)— 从上层到下层命中测试，触发点击
//   - dispatchKeyDown(key)  — 向焦点控件分发键盘事件
//   - dispatchChar(cp)      — 向焦点控件分发字符输入
// ════════════════════════════════════════════════════════════

class WidgetContainer
{
    public array $children = [];       // Widget 列表（按 z-index 升序）
    public int $focusedIdx = -1;       // 当前焦点控件索引（-1 = 无焦点）

    public function addChild(Widget $w): void
    {
        $this->children[] = $w;
    }

    public function removeChild(Widget $w): void
    {
        $result = [];
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $child = $this->children[$i];
            if ($child !== $w) {
                $result[] = $child;
            }
        }
        $this->children = $result;
        $this->focusedIdx = -1;
    }

    public function childCount(): int
    {
        return count($this->children);
    }

    // 按插入顺序绘制（先插入 = 底层，后插入 = 上层）
    public function drawAll(): void
    {
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->draw();
        }
    }

    // 从上层到下层命中测试，返回第一个包含坐标的控件索引（-1 = 未命中）
    public function hitTestIndex(int $x, int $y): int
    {
        $count = count($this->children);
        for ($i = $count - 1; $i >= 0; $i--) {
            if ($this->children[$i]->pointInside($x, $y)) {
                return $i;
            }
        }
        return -1;
    }

    // 分发鼠标按下事件（多态分发，由各控件的 onMouseDown 处理具体行为）
    public function dispatchMouseDown(int $x, int $y): void
    {
        $idx = $this->hitTestIndex($x, $y);
        if ($idx >= 0) {
            $this->children[$idx]->onMouseDown($x, $y);
            $this->focusedIdx = $idx;
        } else {
            $this->focusedIdx = -1;
        }
    }

    // 分发鼠标释放事件（向所有子控件广播，各控件自行判断是否处理）
    public function dispatchMouseUp(int $x, int $y): void
    {
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->onMouseUp($x, $y);
        }
    }

    // 分发鼠标移动事件（向所有子控件广播）
    public function dispatchMouseMove(int $x, int $y): void
    {
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->onMouseMove($x, $y);
        }
    }

    // 分发键盘按下事件（仅向焦点控件分发）
    public function dispatchKeyDown(int $key): void
    {
        if ($this->focusedIdx >= 0) {
            $this->children[$this->focusedIdx]->onKeyDown($key);
        }
    }

    // 分发字符输入事件（仅向焦点控件分发）
    public function dispatchChar(int $codepoint): void
    {
        if ($this->focusedIdx >= 0) {
            $this->children[$this->focusedIdx]->onChar($codepoint);
        }
    }

    // 清理所有子控件
    public function cleanupAll(): void
    {
        $count = count($this->children);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i]->cleanup();
        }
        $this->children = [];
        $this->focusedIdx = -1;
    }
}

// ════════════════════════════════════════════════════════════
// Button — 按钮控件
//
// 属性：text, bgColor, textColor, onClick 回调, bounds, state
// 交互：press/release/click，点击时触发 onClick 回调
// ════════════════════════════════════════════════════════════

class Button extends Widget
{
    public string $text = "";
    public Color $bgColor;
    public Color $textColor;
    public mixed $onClick = null;       // 回调：void function()
    public bool $initialized = false;
    public int $state = 0;              // WidgetState: Normal=0

    public function __construct(string $text = "")
    {
        parent::__construct();
        $this->text = $text;
        $this->bgColor = new Color(60, 120, 200, 255);
        $this->textColor = Color::white();
    }

    public function init(): void
    {
        $this->initialized = true;
        $this->state = WidgetState::Normal->value;
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        // TODO: 根据 state 选择高亮色（Pressed 时加深）
        Graphics::fillRect($this->bounds, $this->bgColor);
        // TODO: 居中绘制文本（需要文本测量）
        if ($this->text !== "") {
            $tx = $this->bounds->x + 4;
            $ty = $this->bounds->y + 4;
            Graphics::drawText($tx, $ty, $this->text, $this->textColor);
        }
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        // Compact 模式：根据文本长度估算
        $w = strlen($this->text) * 8 + 16;
        if ($w < 40) { $w = 40; }
        $h = 24;
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
        $this->initialized = false;
    }

    // ── 事件处理（多态分发）──

    public function onMouseDown(int $x, int $y): void
    {
        $this->press();
    }

    public function onMouseUp(int $x, int $y): void
    {
        $this->release();
    }

    public function onMouseMove(int $x, int $y): void {}
    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}

    // ── 交互处理 ──

    public function press(): void
    {
        $this->state = WidgetState::Pressed->value;
    }

    public function release(): void
    {
        if ($this->state === WidgetState::Pressed->value) {
            $this->state = WidgetState::Normal->value;
            $this->click();
        }
    }

    public function click(): void
    {
        if ($this->onClick !== null) {
            $cb = $this->onClick;
            $cb();
        }
    }
}

// ════════════════════════════════════════════════════════════
// Label — 文本标签控件
//
// 属性：text, color, fontSize, bounds
// 无交互（仅显示）
// ════════════════════════════════════════════════════════════

class Label extends Widget
{
    public string $text = "";
    public Color $color;
    public int $fontSize = 14;
    public bool $initialized = false;

    public function __construct(string $text = "")
    {
        parent::__construct();
        $this->text = $text;
        $this->color = Color::white();
    }

    public function init(): void
    {
        $this->initialized = true;
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        if ($this->text !== "") {
            Graphics::drawText($this->bounds->x, $this->bounds->y, $this->text, $this->color);
        }
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        $w = strlen($this->text) * ($this->fontSize / 2);
        $h = $this->fontSize + 4;
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
        $this->initialized = false;
    }

    // ── 事件处理（多态分发，Label 无交互）──

    public function onMouseDown(int $x, int $y): void {}
    public function onMouseUp(int $x, int $y): void {}
    public function onMouseMove(int $x, int $y): void {}
    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}
}

// ════════════════════════════════════════════════════════════
// TextBox — 文本输入框控件
//
// 属性：text, cursorPos, focused, bgColor, textColor, bounds
// 交互：focus/handleKeyDown/handleChar，支持文本编辑
// ════════════════════════════════════════════════════════════

class TextBox extends Widget
{
    public string $text = "";
    public int $cursorPos = 0;
    public bool $focused = false;
    public Color $bgColor;
    public Color $textColor;
    public Color $cursorColor;
    public bool $initialized = false;

    public function __construct(string $text = "")
    {
        parent::__construct();
        $this->text = $text;
        $this->cursorPos = strlen($text);
        $this->bgColor = new Color(40, 40, 40, 255);
        $this->textColor = Color::white();
        $this->cursorColor = Color::white();
    }

    public function init(): void
    {
        $this->initialized = true;
        $this->clampCursor();
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        Graphics::fillRect($this->bounds, $this->bgColor);
        // TODO: 绘制边框（focused 时高亮）
        if ($this->text !== "") {
            Graphics::drawText($this->bounds->x + 4, $this->bounds->y + 4, $this->text, $this->textColor);
        }
        // TODO: 绘制光标（focused 时在 cursorPos 位置画竖线）
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        $w = 120;
        if ($availableW > 0 && $availableW < $w) {
            $w = $availableW;
        }
        $h = 24;
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
        $this->initialized = false;
        $this->focused = false;
    }

    // ── 事件处理（多态分发）──

    public function onMouseDown(int $x, int $y): void
    {
        $this->focus();
    }

    public function onMouseUp(int $x, int $y): void {}
    public function onMouseMove(int $x, int $y): void {}

    public function onKeyDown(int $key): void
    {
        $this->handleKeyDown($key);
    }

    public function onChar(int $codepoint): void
    {
        $this->handleChar($codepoint);
    }

    // ── 焦点管理 ──

    public function focus(): void
    {
        $this->focused = true;
    }

    public function blur(): void
    {
        $this->focused = false;
    }

    // ── 键盘输入处理 ──

    public function handleKeyDown(int $key): void
    {
        if (!$this->focused) return;

        if ($key === Key::Backspace->value) {
            if ($this->cursorPos > 0) {
                // 注意：TinyPHP substr 中 length=0 表示"到末尾"，故 cursorPos-1==0 时
                // 不能用 substr(text,0,0)（会返回整个串），需用空串字面量
                $prefix = ($this->cursorPos > 1) ? substr($this->text, 0, $this->cursorPos - 1) : "";
                $this->text = $prefix . substr($this->text, $this->cursorPos);
                $this->cursorPos = $this->cursorPos - 1;
            }
        } elseif ($key === Key::Delete->value) {
            if ($this->cursorPos < strlen($this->text)) {
                // 同上：cursorPos==0 时前缀为空串，避免 substr(text,0,0) 返回整个串
                $prefix = ($this->cursorPos > 0) ? substr($this->text, 0, $this->cursorPos) : "";
                $this->text = $prefix . substr($this->text, $this->cursorPos + 1);
            }
        } elseif ($key === Key::Left->value) {
            if ($this->cursorPos > 0) {
                $this->cursorPos = $this->cursorPos - 1;
            }
        } elseif ($key === Key::Right->value) {
            if ($this->cursorPos < strlen($this->text)) {
                $this->cursorPos = $this->cursorPos + 1;
            }
        } elseif ($key === Key::Home->value) {
            $this->cursorPos = 0;
        } elseif ($key === Key::End->value) {
            $this->cursorPos = strlen($this->text);
        }
    }

    public function handleChar(int $codepoint): void
    {
        if (!$this->focused) return;
        // 只接受可打印 ASCII 字符（32-126）
        if ($codepoint < 32 || $codepoint > 126) {
            return;
        }
        $char = chr($codepoint);
        $this->text = substr($this->text, 0, $this->cursorPos) . $char .
                      substr($this->text, $this->cursorPos);
        $this->cursorPos = $this->cursorPos + 1;
    }

    // 光标位置夹紧到 [0, strlen(text)]
    private function clampCursor(): void
    {
        $len = strlen($this->text);
        if ($this->cursorPos < 0) {
            $this->cursorPos = 0;
        } elseif ($this->cursorPos > $len) {
            $this->cursorPos = $len;
        }
    }
}

// ════════════════════════════════════════════════════════════
// CheckBox — 复选框控件
//
// 属性：checked, onChange 回调, color, bounds
// 交互：toggle 切换选中状态，触发 onChange 回调
// ════════════════════════════════════════════════════════════

class CheckBox extends Widget
{
    public bool $checked = false;
    public mixed $onChange = null;      // 回调：void function(bool $checked)
    public Color $color;
    public Color $checkColor;
    public bool $initialized = false;

    public function __construct(bool $checked = false)
    {
        parent::__construct();
        $this->checked = $checked;
        $this->color = Color::white();
        $this->checkColor = new Color(60, 120, 200, 255);
    }

    public function init(): void
    {
        $this->initialized = true;
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        // TODO: 绘制方框
        Graphics::drawRect($this->bounds, $this->color);
        if ($this->checked) {
            // TODO: 绘制对勾（简化为填充内框）
            $inner = new Rect(
                $this->bounds->x + 3,
                $this->bounds->y + 3,
                $this->bounds->width - 6,
                $this->bounds->height - 6
            );
            Graphics::fillRect($inner, $this->checkColor);
        }
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        $w = 16;
        $h = 16;
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
        $this->initialized = false;
    }

    // ── 事件处理（多态分发）──

    public function onMouseDown(int $x, int $y): void
    {
        $this->toggle();
    }

    public function onMouseUp(int $x, int $y): void {}
    public function onMouseMove(int $x, int $y): void {}
    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}

    // ── 交互处理 ──

    public function toggle(): void
    {
        $this->checked = !$this->checked;
        if ($this->onChange !== null) {
            $cb = $this->onChange;
            $cb($this->checked);
        }
    }

    public function setChecked(bool $checked): void
    {
        $this->checked = $checked;
    }
}

// ════════════════════════════════════════════════════════════
// Slider — 滑块控件
//
// 属性：min, max, value, onChange 回调, color, bounds, dragging
// 交互：beginDrag/drag/endDrag，拖动改变 value，触发 onChange 回调
// 值夹紧：value 始终在 [min, max] 范围内
// ════════════════════════════════════════════════════════════

class Slider extends Widget
{
    public int $min = 0;
    public int $max = 100;
    public int $value = 0;
    public mixed $onChange = null;      // 回调：void function(int $value)
    public Color $trackColor;
    public Color $handleColor;
    public bool $initialized = false;
    public bool $dragging = false;

    public function __construct(int $min = 0, int $max = 100, int $value = 0)
    {
        parent::__construct();
        $this->min = $min;
        $this->max = $max;
        $this->value = $value;
        $this->clampValue();
        $this->trackColor = new Color(60, 60, 60, 255);
        $this->handleColor = new Color(60, 120, 200, 255);
    }

    public function init(): void
    {
        $this->initialized = true;
        $this->clampValue();
    }

    public function draw(): void|Exception
    {
        if (!$this->initialized) {
            throw new Exception("widget not initialized");
        }
        // 绘制轨道
        $trackH = 4;
        $trackY = $this->bounds->y + ($this->bounds->height - $trackH) / 2;
        $trackRect = new Rect($this->bounds->x, $trackY, $this->bounds->width, $trackH);
        Graphics::fillRect($trackRect, $this->trackColor);

        // 绘制手柄
        $handleW = 12;
        $handleH = 12;
        $range = $this->max - $this->min;
        $ratio = 0;
        if ($range > 0) {
            $ratio = ($this->value - $this->min) / $range;
        }
        $handleX = $this->bounds->x + (int)($ratio * ($this->bounds->width - $handleW));
        $handleY = $this->bounds->y + ($this->bounds->height - $handleH) / 2;
        $handleRect = new Rect($handleX, $handleY, $handleW, $handleH);
        Graphics::fillRect($handleRect, $this->handleColor);
    }

    public function setPos(int $x, int $y): void
    {
        $this->bounds->x = $x;
        $this->bounds->y = $y;
    }

    public function proposeSize(int $availableW, int $availableH): array
    {
        $w = $availableW;
        if ($w <= 0) { $w = 100; }
        $h = 16;
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
        $this->initialized = false;
        $this->dragging = false;
    }

    // ── 事件处理（多态分发）──

    public function onMouseDown(int $x, int $y): void
    {
        $this->beginDrag($x, $y);
    }

    public function onMouseUp(int $x, int $y): void
    {
        $this->endDrag();
    }

    public function onMouseMove(int $x, int $y): void
    {
        $this->drag($x, $y);
    }

    public function onKeyDown(int $key): void {}
    public function onChar(int $codepoint): void {}

    // ── 交互处理 ──

    public function beginDrag(int $x, int $y): void
    {
        $this->dragging = true;
        $this->updateValueFromX($x);
    }

    public function drag(int $x, int $y): void
    {
        if ($this->dragging) {
            $this->updateValueFromX($x);
        }
    }

    public function endDrag(): void
    {
        $this->dragging = false;
    }

    public function setValue(int $value): void
    {
        $this->value = $value;
        $this->clampValue();
    }

    // 根据 x 坐标更新 value（值夹紧完整实现）
    private function updateValueFromX(int $x): void
    {
        $range = $this->max - $this->min;
        if ($range <= 0) {
            $this->value = $this->min;
            return;
        }
        $w = $this->bounds->width;
        if ($w <= 0) {
            $this->value = $this->min;
            return;
        }
        $relX = $x - $this->bounds->x;
        if ($relX < 0) { $relX = 0; }
        if ($relX > $w) { $relX = $w; }
        $ratio = (float)$relX / (float)$w;
        $this->value = $this->min + (int)($ratio * $range);
        $this->clampValue();
        if ($this->onChange !== null) {
            $cb = $this->onChange;
            $cb($this->value);
        }
    }

    // 值夹紧到 [min, max]（完整实现）
    private function clampValue(): void
    {
        if ($this->value < $this->min) {
            $this->value = $this->min;
        } elseif ($this->value > $this->max) {
            $this->value = $this->max;
        }
    }
}
