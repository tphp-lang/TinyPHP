<?php
// 测试 #cstruct 声明 + C 结构体字段原生访问
// 验证 $p->x / $p->y 直接访问 C 结构体字段，无需 C getter/setter
// 覆盖:单结构体(Point) + 多字段结构体(Rect) + 字段运算 + phpc_auto 自动释放
#include "include/demo.h"

#cstruct Point {
    C.double x;
    C.double y;
}

#cstruct Rect {
    C.int id;
    C.double x;
    C.double y;
    C.double w;
    C.double h;
}

#debug === CStruct Test ===
#debug
#debug 1. create: 3,4
#debug 2. read x: 3
#debug 3. read y: 4
#debug 4. norm: 5
#debug 5. modify x: 10
#debug
#debug === Rect Test ===
#debug
#debug 6. rect id: 42
#debug 7. rect pos: 1,2
#debug 8. rect size: 10,20
#debug 9. rect area: 200
#debug 10. inside (5,5): 1
#debug 11. inside (15,25): 0
#debug 12. modify w: 100
#debug 13. new area: 2000
#debug
#debug === Auto Free ===
#debug
#debug 14. auto point: 7,24
#debug 15. auto norm: 25
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== CStruct Test ===\n\n";

        // ── Point:基础 #cstruct 测试 ──
        // phpc C 指针类型必须显式声明（C.Point*），tphp 类型可自动推导
        C.Point* $p = C->point_create(3.0, 4.0);
        echo "1. create: " . $p->x . "," . $p->y . "\n";
        echo "2. read x: " . $p->x . "\n";
        echo "3. read y: " . $p->y . "\n";

        // 字段参与运算
        $norm = C->sqrt($p->x * $p->x + $p->y * $p->y);
        echo "4. norm: " . $norm . "\n";

        // 直接修改字段(无需 setter)
        $p->x = 10.0;
        echo "5. modify x: " . $p->x . "\n";
        C->point_free($p);

        // ── Rect:多字段结构体(int + double 混合) ──
        echo "\n=== Rect Test ===\n\n";
        C.Rect* $r = C->rect_create(42, 1.0, 2.0, 10.0, 20.0);
        echo "6. rect id: " . $r->id . "\n";
        echo "7. rect pos: " . $r->x . "," . $r->y . "\n";
        echo "8. rect size: " . $r->w . "," . $r->h . "\n";

        // 字段参与 C 函数运算
        $area = C->rect_area($r);
        echo "9. rect area: " . $area . "\n";

        // 字段作为 C 函数参数
        $in1 = C->rect_is_inside($r, 5.0, 5.0);
        echo "10. inside (5,5): " . $in1 . "\n";
        $in2 = C->rect_is_inside($r, 15.0, 25.0);
        echo "11. inside (15,25): " . $in2 . "\n";

        // 修改字段后重新计算
        $r->w = 100.0;
        echo "12. modify w: " . $r->w . "\n";
        $area2 = C->rect_area($r);
        echo "13. new area: " . $area2 . "\n";
        C->rect_free($r);

        // ── phpc_auto 配合 #cstruct 自动释放 ──
        echo "\n=== Auto Free ===\n\n";
        // phpc_auto 注册 Point 指针,程序结束自动 free(无需 point_free)
        // phpc_auto 返回 void*,需显式声明类型
        C.Point* $tmp = C->point_create(7.0, 24.0);
        C.void* $ap = phpc_auto($tmp);
        echo "14. auto point: " . $tmp->x . "," . $tmp->y . "\n";
        $anorm = C->sqrt($tmp->x * $tmp->x + $tmp->y * $tmp->y);
        echo "15. auto norm: " . $anorm . "\n";

        echo "\n=== All passed ===\n";
    }
}
