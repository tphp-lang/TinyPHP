<?php
// 可变参数测试（vlang 风格 ...$args / int ...$nums）
// 编译期打包为 t_array*，零运行时类型擦除
// 对应 PHP 7.0+ 可变参数语法 + func_get_args()/func_num_args()/func_get_arg()

#debug ===== 1. 基本可变参数（独立函数） =====
#debug int(6)
#debug
#debug ===== 2. 可变参数 + 固定参数 =====
#debug int(60)
#debug
#debug ===== 3. 类型化可变参数 int ...$nums =====
#debug int(100)
#debug
#debug ===== 4. 调用点展开数组 ...$arr =====
#debug int(10)
#debug
#debug ===== 5. func_get_args() =====
#debug int(3)
#debug int(1)
#debug int(2)
#debug int(3)
#debug
#debug ===== 6. func_num_args() =====
#debug int(4)
#debug
#debug ===== 7. func_get_arg($i) =====
#debug int(20)
#debug
#debug ===== 8. 可变参数方法（类方法） =====
#debug int(15)
#debug
#debug ===== 9. 可变参数为空（传 0 个实参） =====
#debug int(0)
#debug
#debug ===== 10. 可变参数方法 + func_get_args =====
#debug int(3)
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 基本可变参数（独立函数）
        // ============================================================
        echo "===== 1. 基本可变参数（独立函数） =====\n";
        $s = sum(1, 2, 3);
        var_dump($s);   // int(6)

        // ============================================================
        // 2. 可变参数 + 固定参数
        // ============================================================
        echo "\n===== 2. 可变参数 + 固定参数 =====\n";
        $s2 = sumWithBase(10, 20, 30);
        var_dump($s2);   // int(60)

        // ============================================================
        // 3. 类型化可变参数 int ...$nums（vlang 风格）
        // ============================================================
        echo "\n===== 3. 类型化可变参数 int ..." . '$nums' . " =====\n";
        $s3 = sumInts(10, 20, 30, 40);
        var_dump($s3);   // int(100)

        // ============================================================
        // 4. 调用点展开数组 ...$arr（零开销透传）
        // ============================================================
        echo "\n===== 4. 调用点展开数组 ..." . '$arr' . " =====\n";
        $arr = [1, 2, 3, 4];
        $s4 = sum(...$arr);
        var_dump($s4);   // int(10)

        // ============================================================
        // 5. func_get_args() — 在可变参数函数内可用
        // ============================================================
        echo "\n===== 5. func_get_args() =====\n";
        $args = getArgs(1, 2, 3);
        var_dump(count($args));   // int(3)
        var_dump($args[0]);       // int(1)
        var_dump($args[1]);       // int(2)
        var_dump($args[2]);       // int(3)

        // ============================================================
        // 6. func_num_args() — 可变参数实参数量
        // ============================================================
        echo "\n===== 6. func_num_args() =====\n";
        $n = countArgs(10, 20, 30, 40);
        var_dump($n);   // int(4)

        // ============================================================
        // 7. func_get_arg($i) — 按索引取可变参数
        // ============================================================
        echo "\n===== 7. func_get_arg(" . '$i' . ") =====\n";
        $v = getArgAt(10, 20, 30, 1);
        var_dump($v);   // int(20)

        // ============================================================
        // 8. 可变参数方法（类方法）
        // ============================================================
        echo "\n===== 8. 可变参数方法（类方法） =====\n";
        $c = new Calc();
        $s8 = $c->sum(5, 10);
        var_dump($s8);   // int(15)

        // ============================================================
        // 9. 可变参数为空（传 0 个实参）
        // ============================================================
        echo "\n===== 9. 可变参数为空（传 0 个实参） =====\n";
        $s9 = sum();
        var_dump($s9);   // int(0)

        // ============================================================
        // 10. 可变参数方法 + func_get_args
        // ============================================================
        echo "\n===== 10. 可变参数方法 + func_get_args =====\n";
        $r = $c->getArgsAsArray(10, 20, 30);
        var_dump(count($r));   // int(3)
        var_dump($r[0]);       // int(10)
        var_dump($r[1]);       // int(20)
        var_dump($r[2]);       // int(30)

        echo "\n=== done ===\n";
    }
}

// 基本可变参数：...$args（无类型约束）
function sum(int ...$nums): int
{
    $total = 0;
    foreach ($nums as $n) {
        $total += $n;
    }
    return $total;
}

// 固定参数 + 可变参数
function sumWithBase(int $base, int ...$nums): int
{
    $total = $base;
    foreach ($nums as $n) {
        $total += $n;
    }
    return $total;
}

// 类型化可变参数（vlang 风格）
function sumInts(int ...$nums): int
{
    $total = 0;
    foreach ($nums as $n) {
        $total += $n;
    }
    return $total;
}

// func_get_args() — 返回所有可变实参
function getArgs(int ...$args): array
{
    return func_get_args();
}

// func_num_args() — 可变实参数量
function countArgs(int ...$args): int
{
    return func_num_args();
}

// func_get_arg($i) — 按索引取可变实参
function getArgAt(int ...$args): int
{
    return func_get_arg(1);
}

class Calc
{
    public function sum(int ...$nums): int
    {
        $total = 0;
        foreach ($nums as $n) {
            $total += $n;
        }
        return $total;
    }

    public function getArgsAsArray(int ...$args): array
    {
        return func_get_args();
    }
}
