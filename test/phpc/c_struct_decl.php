<?php
// 测试 struct C.Foo {} 声明语法 + C 类型推导 + cstr_to_string() 零拷贝
//   Task 3.4: vlang 风格 C 结构体声明语法（替代 #cstruct 指令）
//   Task 3.5: TypeChecker 字段访问类型推导（C.Point* -> x : C.double）
//   Task 3.6: cstr_to_string() 零拷贝字符串转换（vs php_str 深拷贝）
#include "include/demo.h"

#debug === Struct Decl Test (struct C.Foo {}) ===
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
#debug === cstr_to_string (zero-copy) ===
#debug
#debug 14. greet: Hello, TinyPHP!
#debug 15. greet stranger: Hello, stranger!
#debug 16. reverse: PHPyniT
#debug 17. php_str compare: Hello, TinyPHP!
#debug
#debug === All passed ===

// vlang 风格 C 结构体声明（struct C.Foo {} 语法，CodeGenerator 使用 cstructs 数组）
struct C.Point {
    C.double x;
    C.double y;
}

struct C.Rect {
    C.int id;
    C.double x;
    C.double y;
    C.double w;
    C.double h;
}

// 不透明结构体声明（无字段列表，仅作指针类型标记）
struct C.Opaque;

class Main {
    public function main(): void {
        echo "=== Struct Decl Test (struct C.Foo {}) ===\n\n";

        // ── Point: struct C.Point {} 声明 + 字段访问类型推导 ──
        //   $p 类型为 C.Point*，TypeChecker 查 cStructs 表推导 $p->x 为 C.double
        C.Point* $p = C->point_create(3.0, 4.0);
        echo "1. create: " . $p->x . "," . $p->y . "\n";
        echo "2. read x: " . $p->x . "\n";
        echo "3. read y: " . $p->y . "\n";

        // 字段参与运算（类型推导：C.double 算术 → float）
        float $norm = C->sqrt($p->x * $p->x + $p->y * $p->y);
        echo "4. norm: " . $norm . "\n";

        // 直接修改字段（无需 setter）
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
        float $area = C->rect_area($r);
        echo "9. rect area: " . $area . "\n";

        // 字段作为 C 函数参数
        int $in1 = C->rect_is_inside($r, 5.0, 5.0);
        echo "10. inside (5,5): " . $in1 . "\n";
        int $in2 = C->rect_is_inside($r, 15.0, 25.0);
        echo "11. inside (15,25): " . $in2 . "\n";

        // 修改字段后重新计算
        $r->w = 100.0;
        echo "12. modify w: " . $r->w . "\n";
        float $area2 = C->rect_area($r);
        echo "13. new area: " . $area2 . "\n";
        C->rect_free($r);

        // ── cstr_to_string: 零拷贝 C char* → PHP t_string ──
        //   greet() 返回 static char[] 静态缓冲区，适合 cstr_to_string 零拷贝
        //   与 php_str（深拷贝）对比：两者结果一致，cstr_to_string 无 strlen+dup 开销
        echo "\n=== cstr_to_string (zero-copy) ===\n\n";
        C.char* $greeting = C->greet(c_str("TinyPHP"));
        echo "14. greet: " . cstr_to_string($greeting) . "\n";

        // greet("stranger") 返回静态缓冲区 "Hello, stranger!"
        //   静态缓冲区适于 cstr_to_string（零拷贝，调用方不持有所有权）
        C.char* $strangerGreet = C->greet(c_str("stranger"));
        echo "15. greet stranger: " . cstr_to_string($strangerGreet) . "\n";

        // reverse_str() 返回 static char reverse_buf[1024]
        C.char* $rev = C->reverse_str(c_str("TinyPHP"));
        echo "16. reverse: " . cstr_to_string($rev) . "\n";

        // php_str 对比：深拷贝，结果一致
        C.char* $greeting2 = C->greet(c_str("TinyPHP"));
        echo "17. php_str compare: " . php_str($greeting2) . "\n";

        echo "\n=== All passed ===\n";
    }
}
