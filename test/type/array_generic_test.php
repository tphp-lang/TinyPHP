<?php
// array<T> 泛型数组类型注解测试（Task 5.4）
// 编译期记录元素类型到 arrElementTypes，运行时仍为 t_array*
// 优化：标量数组访问跳过 t_var 包装（Task 5.3 机制复用）

#debug ===== 1. array<int> 参数 =====
#debug int(1)
#debug int(2)
#debug int(3)
#debug int(6)
#debug
#debug ===== 2. array<string> 参数 =====
#debug string(3) "foo"
#debug string(3) "bar"
#debug string(6) "foobar"
#debug
#debug ===== 3. array<int> 局部变量 =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 4. array<float> 求和 =====
#debug float(6.6)
#debug
#debug ===== 5. 嵌套 array<array<int>> =====
#debug int(1)
#debug int(10)
#debug
#debug ===== 6. array<int> 作为方法参数 =====
#debug int(100)
#debug
#debug ===== 7. array<int> 返回值 =====
#debug int(1)
#debug int(2)
#debug int(3)
#debug
#debug ===== 8. all done =====
#debug OK

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. array<int> 参数
        // ============================================================
        echo "===== 1. array<int> 参数 =====\n";
        $nums = [1, 2, 3];
        foreach ($nums as $n) {
            var_dump($n);
        }
        var_dump(sumInts($nums));

        // ============================================================
        // 2. array<string> 参数
        // ============================================================
        echo "\n===== 2. array<string> 参数 =====\n";
        $strs = ["foo", "bar"];
        foreach ($strs as $s) {
            var_dump($s);
        }
        var_dump(concatStrs($strs));

        // ============================================================
        // 3. array<int> 局部变量
        // ============================================================
        echo "\n===== 3. array<int> 局部变量 =====\n";
        array<int> $arr = [10, 20, 30];
        foreach ($arr as $v) {
            var_dump($v);
        }

        // ============================================================
        // 4. array<float> 求和
        // ============================================================
        echo "\n===== 4. array<float> 求和 =====\n";
        $floats = [1.1, 2.2, 3.3];
        var_dump(sumFloats($floats));

        // ============================================================
        // 5. 嵌套 array<array<int>>
        // ============================================================
        echo "\n===== 5. 嵌套 array<array<int>> =====\n";
        $nested = [[1, 2], [3, 4]];
        var_dump($nested[0][0]);
        var_dump(sumNested($nested));

        // ============================================================
        // 6. array<int> 作为方法参数
        // ============================================================
        echo "\n===== 6. array<int> 作为方法参数 =====\n";
        $c = new Calc();
        var_dump($c->sum([10, 20, 30, 40]));

        // ============================================================
        // 7. array<int> 返回值
        // ============================================================
        echo "\n===== 7. array<int> 返回值 =====\n";
        $r = makeRange(1, 3);
        foreach ($r as $v) {
            var_dump($v);
        }

        // ============================================================
        // 8. 完成
        // ============================================================
        echo "\n===== 8. all done =====\n";
        echo "OK\n";
    }
}

function sumInts(array<int> $nums): int
{
    $total = 0;
    foreach ($nums as $n) {
        $total += $n;
    }
    return $total;
}

function concatStrs(array<string> $strs): string
{
    $r = '';
    foreach ($strs as $s) {
        $r .= $s;
    }
    return $r;
}

function sumFloats(array<float> $floats): float
{
    $total = 0.0;
    foreach ($floats as $f) {
        $total += $f;
    }
    return $total;
}

function sumNested(array<array<int>> $nested): int
{
    $total = 0;
    foreach ($nested as $sub) {
        foreach ($sub as $v) {
            $total += $v;
        }
    }
    return $total;
}

function makeRange(int $start, int $end): array<int>
{
    $r = [];
    for ($i = $start; $i <= $end; $i++) {
        $r[] = $i;
    }
    return $r;
}

class Calc
{
    public function sum(array<int> $nums): int
    {
        $total = 0;
        foreach ($nums as $n) {
            $total += $n;
        }
        return $total;
    }
}
