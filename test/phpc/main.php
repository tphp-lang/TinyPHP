<?php // @multi @with phpc.php

use function Phpc\calc_distance;
use function Phpc\calc_factorial;
use function Phpc\sum_array_int;
use function Phpc\sum_array_dbl;
use function Phpc\double_each_value;
use function Phpc\obj_is_valid;
use function Phpc\obj_read_x;
use function Phpc\obj_read_y;
use function Phpc\apply_square;
use function Phpc\map_with_closure;
use function Phpc\map_ints_noenv;
use function Phpc\fold_double;

class MyPoint
{
    public float $x;
    public float $y;
}

class Main
{
    public function main(): void
    {
        echo "=== PHPC Full Test ===\n\n";

        // ═══════════════════════════════════════
        // Part 1: 基础类型
        // ═══════════════════════════════════════
        echo "-- Part 1: Basic Types --\n";
        $d = calc_distance(0.0, 0.0, 3.0, 4.0);
        echo "1. dist="; var_dump($d); echo "\n";
        $f = calc_factorial(10);
        echo "2. factorial="; var_dump($f); echo "\n";

        // ═══════════════════════════════════════
        // Part 2: 数组
        // ═══════════════════════════════════════
        echo "\n-- Part 2: Arrays --\n";
        $s = sum_array_int([10, 20, 30, 40, 50]);
        echo "3. sum="; var_dump($s); echo "\n";

        $doubled = double_each_value([1, 2, 3, 4, 5]);
        echo "4. doubled[0]="; var_dump($doubled[0]);
        echo "  doubled[4]="; var_dump($doubled[4]); echo "\n";

        $fs = sum_array_dbl([1.5, 2.5, 3.0]);
        echo "5. float_sum="; var_dump($fs); echo "\n";

        // ═══════════════════════════════════════
        // Part 3: 对象互操作
        // ═══════════════════════════════════════
        echo "\n-- Part 3: Objects --\n";

        // 3a: 创建 PHP 对象 → phpc_obj 提取 C 指针 → C 验证有效
        $p1 = new MyPoint();
        $p1->x = 3.0;
        $p1->y = 4.0;
        $valid = obj_is_valid($p1);
        echo "6. obj_is_valid="; var_dump($valid); echo "\n";

        // 3b: phpc_obj 提取指针 → C 读取字段值
        $x = obj_read_x($p1);
        $y = obj_read_y($p1);
        echo "7. x="; var_dump($x);
        echo "  y="; var_dump($y); echo "\n";

        // 3c: 另一个对象
        $p2 = new MyPoint();
        $p2->x = 1.5;
        $p2->y = 2.5;
        $x2 = obj_read_x($p2);
        $y2 = obj_read_y($p2);
        echo "8. x2="; var_dump($x2);
        echo "  y2="; var_dump($y2); echo "\n";

        // ═══════════════════════════════════════
        // Part 4: 回调互操作
        // ═══════════════════════════════════════
        echo "\n-- Part 4: Callbacks --\n";

        // 4a: PHP 闭包 → phpc_fn/phpc_env 提取指针 → C 调用
        $sq = apply_square(5);
        echo "9. apply_square(5)="; var_dump($sq); echo "\n";

        // 4b: 多次调用
        $sq2 = apply_square(7);
        echo "10. apply_square(7)="; var_dump($sq2); echo "\n";

        // 4c: 数组 map 操作 — 用闭包变换数组
        $mapped = map_with_closure(
            [1, 2, 3, 4, 5],
            function(int $x): int { return $x * 10; }
        );
        echo "11. map([1..5],×10)=[";
        echo $mapped[0] . "," . $mapped[1] . "," . $mapped[2] . "," . $mapped[3] . "," . $mapped[4] . "]\n";

        // 4d: 用捕获变量的闭包做 map
        $offset = 100;
        $mapped2 = map_with_closure(
            [1, 2, 3],
            function(int $x) use ($offset): int { return $x + $offset; }
        );
        echo "12. map([1,2,3],+100)=[";
        echo $mapped2[0] . "," . $mapped2[1] . "," . $mapped2[2] . "]\n";

        // 4e: 无 env 回调 — thunk 机制测试
        $mapped3 = map_ints_noenv([5, 10, 15], function(int $x): int { return $x * 3; });
        echo "13. map_ne([5,10,15],×3)=[";
        echo $mapped3[0] . "," . $mapped3[1] . "," . $mapped3[2] . "]\n";

        // 4f: 无 env 回调 + 闭包捕获变量
        $base = 1000;
        $mapped4 = map_ints_noenv([1, 2], function(int $x) use ($base): int { return $x + $base; });
        echo "14. map_ne([1,2],+1000)=[";
        echo $mapped4[0] . "," . $mapped4[1] . "]\n";

        // 4g: 多参数多类型回调 — #callback + phpc_thunk
        $weighted = fold_double([2.0, 4.0, 6.0],
            function(int $idx, float $val): float { return (float)($idx + 1) * $val; }
        );
        echo "15. fold([2,4,6],idx+1*val)="; var_dump($weighted); echo "\n";

        // 4h: 多参数回调 + 捕获变量
        $factor = 0.5;
        $halved = fold_double([10.0, 20.0, 30.0],
            function(int $idx, float $val) use ($factor): float { return $val * (float)$factor; }
        );
        echo "16. fold([10,20,30],×0.5)="; var_dump($halved); echo "\n";

        echo "\n=== All PHPC tests passed! ===\n";
    }
}
