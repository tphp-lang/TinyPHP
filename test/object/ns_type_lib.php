<?php // @skip — companion file, no class Main

// ns_type_lib.php — 命名空间感知类型解析测试辅助文件（Lib\Geometry 命名空间）
//   覆盖 Task 2.8 的核心场景：
//     - 命名空间内类的属性/参数/返回类型使用短类名（Point）
//     - 命名空间内自引用（self / 短名 Point）
//   注意：Parser 在 parse 阶段已通过 resolveClassName() 把短类名解析为 FQCN，
//        此处主要验证 Parser 解析 + CodeGenerator 生成的端到端流程；
//        TypeChecker 在 Compiler.php 路径下使用 resolveTypeRef() 兜底解析。

namespace Lib\Geometry;

class Point
{
    public int $x;
    public int $y;

    public function __construct(int $x, int $y)
    {
        $this->x = $x;
        $this->y = $y;
    }

    // 短名 Point 作为参数类型 → Parser 解析为 Lib\Geometry\Point
    public function add(Point $other): Point
    {
        return new Point($this->x + $other->x, $this->y + $other->y);
    }

    public function distance(Point $other): int
    {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        // 简化：用绝对值和（避免 sqrt 浮点比较）
        $ax = $dx < 0 ? -$dx : $dx;
        $ay = $dy < 0 ? -$dy : $dy;
        return $ax + $ay;
    }

    public function label(): string
    {
        return '(' . $this->x . ',' . $this->y . ')';
    }
}

// 命名空间内的另一个类，使用 FQCN 形式引用同命名空间的 Point
class Box
{
    public Point $tl;
    public Point $br;

    public function __construct(Point $tl, Point $br)
    {
        $this->tl = $tl;
        $this->br = $br;
    }

    public function area(): int
    {
        $w = $this->br->x - $this->tl->x;
        $h = $this->br->y - $this->tl->y;
        if ($w < 0) $w = -$w;
        if ($h < 0) $h = -$h;
        return $w * $h;
    }
}
