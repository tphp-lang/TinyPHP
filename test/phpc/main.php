<?php

use function Phpc\{
    calc_distance,
    calc_factorial,
    sum_array_int,
    sum_array_dbl,
    double_each_value,
    obj_is_valid,
    obj_read_x,
    obj_read_y,
    apply_square,
    map_with_closure,
    map_ints_noenv,
    fold_double,
    create_origin,
    get_point_x,
    greet_name,
    square_int,
    create_null_point,
    join_string_array,
    create_and_free_point,
    steal_and_free_point,
    test_assert_null_ptr,
    test_arr_type_mismatch,
    test_free_zeroes_var,
    test_env_pin
};

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

        // ═══════════════════════════════════════
        // Part 5: C 类型参数/返回值
        // ═══════════════════════════════════════
        echo "\n-- Part 5: C Types --\n";

        // 5a: C.Point 返回类型
        $origin = create_origin();
        echo "17. origin_x="; var_dump(get_point_x($origin)); echo "\n";
        phpc_unregister_obj($origin);
        C->point_free($origin);

        // 5b: C.char_ptr 字符串
        $greeting = greet_name("TinyPHP");
        echo "18. greet="; var_dump($greeting); echo "\n";

        // 5c: C.int 整数
        $sq = square_int(12);
        echo "19. square(12)="; var_dump($sq); echo "\n";

        // 5d: 错误路径 — NULL 返回
        $null_pt = create_null_point();
        echo "20. null_point="; var_dump($null_pt == null ? 0 : 1); echo "\n";

        // 5e: 字符串数组互操作
        $joined = join_string_array(["apple", "banana", "cherry"]);
        echo "21. join_strs="; var_dump($joined); echo "\n";

        // 5f: phpc_unregister_obj — C 库自行释放对象
        $freed_ok = create_and_free_point(5.0, 6.0);
        echo "22. create_and_free="; var_dump($freed_ok); echo "\n";

        // ═══════════════════════════════════════
        // Part 6: 安全 API
        // ═══════════════════════════════════════
        echo "\n-- Part 6: Safety API --\n";

        // 6a: phpc_obj_steal 防止 double-free
        $steal_ok = steal_and_free_point(7.0, 8.0);
        echo "23. steal_and_free="; var_dump($steal_ok); echo "\n";

        // 6b: phpc_assert_ptr 捕获 NULL 指针
        $assert_ok = test_assert_null_ptr();
        echo "24. assert_null_caught="; var_dump($assert_ok); echo "\n";

        // 6c: phpc_arr_int 类型不匹配抛异常
        $mismatch_ok = test_arr_type_mismatch();
        echo "25. arr_type_mismatch_caught="; var_dump($mismatch_ok); echo "\n";

        // 6d: phpc_free 自动置零
        $zero_ok = test_free_zeroes_var();
        echo "26. free_zeroes_var="; var_dump($zero_ok); echo "\n";

        // 6e: phpc_env_pin / phpc_env_unpin（有捕获闭包才有 env）
        $pin_ok = test_env_pin(42);
        echo "27. env_pin_unpin="; var_dump($pin_ok); echo "\n";

        echo "\n=== All PHPC tests passed! ===\n";
    }
}
