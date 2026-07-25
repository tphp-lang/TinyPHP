<?php // @multi @with ns_type_lib.php
// ns_type_main.php — 命名空间感知类型解析入口（Task 2.8）
//   覆盖以下场景：
//     1. use 导入短名作为类型/构造引用: use Lib\Geometry\Point
//     2. use 导入另一个类: use Lib\Geometry\Box
//     3. 跨命名空间类型注解的属性访问与方法调用
//
//   注意：Parser 的 parseNewExpr 仅支持单 IDENTIFIER（如 new Point(...)），
//        不支持 new \Lib\Geometry\Point(...) 这种 FQCN 形式 — 这是 Parser 既有局限，
//        超出 Task 2.8 范围。
//        TypeChecker 的 resolveTypeRef() 已支持 \FQCN 解析（去前导 \），
//        SymbolTable::resolveClass() 也已规范化前导 \。
#debug === NS Type Resolution ===
#debug
#debug -- 1. use import --
#debug p1=(1,2)
#debug p2=(3,4)
#debug p1+p2=(4,6)
#debug dist=4
#debug
#debug -- 2. cross-ns type annotation --
#debug box.area=4
#debug
#debug === OK ===

use Lib\Geometry\Point;
use Lib\Geometry\Box;

class Main
{
    public function main(): void
    {
        echo "=== NS Type Resolution ===\n\n";

        // ═══ 1. use 导入：跨命名空间类型引用 ═══
        // Point 经 use Lib\Geometry\Point 导入，
        // new Point(...) 由 Parser resolveClassName() 解析为 Lib\Geometry\Point
        echo "-- 1. use import --\n";
        $p1 = new Point(1, 2);
        $p2 = new Point(3, 4);
        echo 'p1=' . $p1->label() . "\n";        // (1,2)
        echo 'p2=' . $p2->label() . "\n";        // (3,4)

        // add() 返回 Point，赋值给变量后访问属性（验证跨命名空间类型链）
        $sum = $p1->add($p2);
        echo 'p1+p2=' . $sum->label() . "\n";    // (4,6)

        // distance() 跨实例方法调用
        echo 'dist=' . $p1->distance($p2) . "\n"; // |1-3|+|2-4| = 2+2 = 4

        // ═══ 2. 跨命名空间类型注解 ═══
        // Box 的属性类型为 Point（命名空间内短名），
        // 在 ns_type_lib.php 内由 Parser 解析为 Lib\Geometry\Point
        echo "\n-- 2. cross-ns type annotation --\n";
        $tl = new Point(0, 0);
        $br = new Point(2, 2);
        $box = new Box($tl, $br);
        echo 'box.area=' . $box->area() . "\n";   // 2*2 = 4

        echo "\n=== OK ===\n";
    }
}
