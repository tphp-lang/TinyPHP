<?php
// array<T> 特化数组上的内置函数测试
// 验证 array<int>/array<string>/array<float> 上的所有数组函数行为正确
// 设计要点：
//   - array<T> 通过 arrayArgCode 自动协变转换为 t_array* 传入 C 函数
//   - 原地修改函数（array_push/pop/shift/unshift）拒绝 array<T>，需用 $arr[] = $val
//   - 返回数组元素类型追踪：array_keys → t_int，array_values/array_slice/array_unique/array_merge → 跟随源数组元素类型
//   - sort/rsort 对 array<T> 调用特化排序函数 tphp_fn_arr_{int|str|float|bool}_sort/rsort 原地排序

#debug ========== 1. array<int> 只读函数 ==========
#debug count: 5
#debug in_array(30): true
#debug in_array(99): false
#debug array_key_exists(2): true
#debug array_key_exists(9): false
#debug array_keys count: 5
#debug array_keys[0]: 0
#debug array_keys[4]: 4
#debug array_values[0]: 10
#debug array_values[4]: 50
#debug array_sum: 150
#debug array_product: 12000000
#debug implode: 10,20,30,40,50
#debug array_slice(1,3) count: 3
#debug array_slice[0]: 20
#debug array_unique count: 3
#debug array_flip[10]: 0
#debug array_flip[50]: 4
#debug array_merge count: 5
#debug merged[3]: 4
#debug sort[0]: 10 [1]: 20 [2]: 30
#debug rsort[0]: 30 [1]: 20 [2]: 10
#debug array_map[0]: 20 [4]: 100
#debug array_filter count: 5
#debug array_reduce: 150
#debug
#debug ========== 2. array<string> 只读函数 ==========
#debug count: 4
#debug in_array(bar): true
#debug in_array(zzz): false
#debug array_keys count: 4
#debug array_keys[0]: 0
#debug array_keys[3]: 3
#debug array_values[0]: foo
#debug array_values[3]: qux
#debug implode: foo|bar|baz|qux
#debug array_slice(1,2) count: 2
#debug array_slice[0]: bar
#debug array_unique count: 3
#debug array_flip[foo]: 0
#debug array_flip[qux]: 3
#debug array_merge count: 3
#debug merged[2]: z
#debug sort[0]: apple [1]: banana [2]: cherry
#debug array_map[0]: FOO [1]: BAR
#debug array_filter(>3) count: 0
#debug array_filter(>=3) count: 4
#debug array_reduce: foobarbazqux
#debug
#debug ========== 3. array<float> 只读函数 ==========
#debug count: 3
#debug array_sum: 7.5
#debug array_product: 13.125
#debug array_map[0]: 3 [2]: 7
#debug array_reduce: 7.5
#debug
#debug ========== 4. array<T> 原地修改用原生语法 ==========
#debug stack count: 3
#debug stack[0]: 100 [2]: 300
#debug stack[1] after modify: 999
#debug
#debug ========== 5. 嵌套 array<array<int>> ==========
#debug outer count: 3
#debug inner[0] count: 3
#debug inner[0] sum: 6
#debug inner[2] sum: 24
#debug
#debug ========== 6. array<T> 与 array<mixed> 互操作 ==========
#debug sumArray(array<int>): 150
#debug joinStrings(array<string>): foo|bar|baz|qux
#debug
#debug ========== 7. 链式调用 ==========
#debug chain (map² filter>500 reduce): 5000
#debug
#debug ========== 8. 其他数组函数（array<string> 元素类型追踪）==========
#debug array_reverse[0]: qux [3]: foo
#debug array_pad count: 6
#debug array_pad[0]: foo [4]:
#debug array_diff[0]: foo [1]: bar
#debug array_intersect[0]: foo [1]: bar
#debug int array_reverse[0]: 50 [4]: 10
#debug
#debug ========== 9. 原地修改函数 shuffle（array<T> 特化）==========
#debug shuffle: ok=true sum=150
#debug shuffle(str): ok=true count=4
#debug
#debug ========== 10. asort/arsort 在 array<mixed> 上（array<T> 应拒绝）==========
#debug asort(mixed) foreach: 10 20 30
#debug arsort(mixed) foreach: 30 20 10
#debug
#debug ========== 11. 返回数组函数的元素类型追踪 ==========
#debug fill(int)[0]: 99 [2]: 99
#debug fill(str)[0]: hi [2]: hi
#debug fill(float)[0]: 1.5 [2]: 1.5
#debug combine[str]: x y
#debug combine[int]: 10 20
#debug chunk count: 3
#debug chunk[0] count: 2
#debug chunk[0][0]: 1
#debug chunk[0][1]: 2
#debug chunk[2][0]: 5
#debug column[0]: alice
#debug column[1]: bob
#debug count_values[a]: 2
#debug count_values[b]: 1
#debug
#debug ========== all done ==========
#debug OK

class Main
{
    public function main(): void
    {
        echo "========== 1. array<int> 只读函数 ==========\n";
        array<int> $ints = [10, 20, 30, 40, 50];

        // count / in_array / array_key_exists
        echo "count: " . count($ints) . "\n";                    // 5
        echo "in_array(30): " . (in_array(30, $ints) ? "true" : "false") . "\n";  // true
        echo "in_array(99): " . (in_array(99, $ints) ? "true" : "false") . "\n";  // false
        echo "array_key_exists(2): " . (array_key_exists(2, $ints) ? "true" : "false") . "\n";  // true
        echo "array_key_exists(9): " . (array_key_exists(9, $ints) ? "true" : "false") . "\n";  // false

        // array_keys / array_values (返回新数组，元素为 int)
        $keys = array_keys($ints);
        $vals = array_values($ints);
        echo "array_keys count: " . count($keys) . "\n";         // 5
        echo "array_keys[0]: " . $keys[0] . "\n";                // 0
        echo "array_keys[4]: " . $keys[4] . "\n";                // 4
        echo "array_values[0]: " . $vals[0] . "\n";              // 10
        echo "array_values[4]: " . $vals[4] . "\n";              // 50

        // array_sum / array_product (聚合)
        echo "array_sum: " . array_sum($ints) . "\n";            // 150
        echo "array_product: " . array_product($ints) . "\n";    // 12000000

        // implode
        echo "implode: " . implode(",", $ints) . "\n";           // 10,20,30,40,50

        // array_slice
        $slice = array_slice($ints, 1, 3);
        echo "array_slice(1,3) count: " . count($slice) . "\n";  // 3
        echo "array_slice[0]: " . $slice[0] . "\n";              // 20

        // array_unique
        array<int> $dups = [10, 20, 20, 30, 30, 30];
        $unique = array_unique($dups);
        echo "array_unique count: " . count($unique) . "\n";     // 3

        // array_flip (int→int: 值变 key, key 变 value)
        $flipped = array_flip($ints);
        echo "array_flip[10]: " . $flipped[10] . "\n";           // 0
        echo "array_flip[50]: " . $flipped[50] . "\n";           // 4

        // array_merge
        array<int> $a = [1, 2, 3];
        array<int> $b = [4, 5];
        $merged = array_merge($a, $b);
        echo "array_merge count: " . count($merged) . "\n";      // 5
        echo "merged[3]: " . $merged[3] . "\n";                  // 4

        // sort / rsort (原地)
        array<int> $toSort = [30, 10, 20];
        sort($toSort);
        echo "sort[0]: " . $toSort[0] . " [1]: " . $toSort[1] . " [2]: " . $toSort[2] . "\n";  // 10 20 30
        rsort($toSort);
        echo "rsort[0]: " . $toSort[0] . " [1]: " . $toSort[1] . " [2]: " . $toSort[2] . "\n"; // 30 20 10

        // 高阶函数
        $doubled = array_map(function (int $x): int { return $x * 2; }, $ints);
        echo "array_map[0]: " . $doubled[0] . " [4]: " . $doubled[4] . "\n";  // 20 100

        $evens = array_filter($ints, function (int $x): bool { return $x % 2 === 0; });
        echo "array_filter count: " . count($evens) . "\n";      // 5 (all even)

        $sum = array_reduce($ints, function (int $carry, int $x): int { return $carry + $x; }, 0);
        echo "array_reduce: " . $sum . "\n";                     // 150

        echo "\n========== 2. array<string> 只读函数 ==========\n";
        array<string> $strs = ["foo", "bar", "baz", "qux"];

        echo "count: " . count($strs) . "\n";                    // 4
        echo "in_array(bar): " . (in_array("bar", $strs) ? "true" : "false") . "\n";  // true
        echo "in_array(zzz): " . (in_array("zzz", $strs) ? "true" : "false") . "\n";  // false

        // array_keys (元素为 int 索引)
        $skeys = array_keys($strs);
        echo "array_keys count: " . count($skeys) . "\n";        // 4
        echo "array_keys[0]: " . $skeys[0] . "\n";               // 0
        echo "array_keys[3]: " . $skeys[3] . "\n";               // 3

        // array_values
        $svals = array_values($strs);
        echo "array_values[0]: " . $svals[0] . "\n";             // foo
        echo "array_values[3]: " . $svals[3] . "\n";             // qux

        // implode
        echo "implode: " . implode("|", $strs) . "\n";           // foo|bar|baz|qux

        // array_slice
        $sslice = array_slice($strs, 1, 2);
        echo "array_slice(1,2) count: " . count($sslice) . "\n"; // 2
        echo "array_slice[0]: " . $sslice[0] . "\n";             // bar

        // array_unique
        array<string> $sdups = ["a", "b", "a", "c", "b"];
        $sunique = array_unique($sdups);
        echo "array_unique count: " . count($sunique) . "\n";    // 3

        // array_flip (string→int: string 值变 key, int key 变 value)
        $sflipped = array_flip($strs);
        echo "array_flip[foo]: " . $sflipped["foo"] . "\n";      // 0
        echo "array_flip[qux]: " . $sflipped["qux"] . "\n";      // 3

        // array_merge
        array<string> $s1 = ["x", "y"];
        array<string> $s2 = ["z"];
        $smerged = array_merge($s1, $s2);
        echo "array_merge count: " . count($smerged) . "\n";     // 3
        echo "merged[2]: " . $smerged[2] . "\n";                 // z

        // sort
        array<string> $toSortS = ["banana", "apple", "cherry"];
        sort($toSortS);
        echo "sort[0]: " . $toSortS[0] . " [1]: " . $toSortS[1] . " [2]: " . $toSortS[2] . "\n";  // apple banana cherry

        // 高阶函数
        $upper = array_map(function (string $s): string { return strtoupper($s); }, $strs);
        echo "array_map[0]: " . $upper[0] . " [1]: " . $upper[1] . "\n";  // FOO BAR

        $longStrs = array_filter($strs, function (string $s): bool { return strlen($s) > 3; });
        echo "array_filter(>3) count: " . count($longStrs) . "\n";  // 0 (所有元素都是 3 字符)
        $ge3 = array_filter($strs, function (string $s): bool { return strlen($s) >= 3; });
        echo "array_filter(>=3) count: " . count($ge3) . "\n";   // 4

        $concat = array_reduce($strs, function (string $carry, string $s): string { return $carry . $s; }, "");
        echo "array_reduce: " . $concat . "\n";                  // foobarbazqux

        echo "\n========== 3. array<float> 只读函数 ==========\n";
        array<float> $floats = [1.5, 2.5, 3.5];

        echo "count: " . count($floats) . "\n";                  // 3
        echo "array_sum: " . array_sum($floats) . "\n";          // 7.5
        echo "array_product: " . array_product($floats) . "\n";  // 13.125

        $fdoubled = array_map(function (float $x): float { return $x * 2; }, $floats);
        echo "array_map[0]: " . $fdoubled[0] . " [2]: " . $fdoubled[2] . "\n";  // 3 7

        $fsum = array_reduce($floats, function (float $carry, float $x): float { return $carry + $x; }, 0.0);
        echo "array_reduce: " . $fsum . "\n";                    // 7.5

        echo "\n========== 4. array<T> 原地修改用原生语法 ==========\n";
        // $arr[] = $val 是 array<T> 推荐的原地追加方式（走特化快路径）
        array<int> $stack = [];
        $stack[] = 100;
        $stack[] = 200;
        $stack[] = 300;
        echo "stack count: " . count($stack) . "\n";             // 3
        echo "stack[0]: " . $stack[0] . " [2]: " . $stack[2] . "\n";  // 100 300

        // 通过索引修改
        $stack[1] = 999;
        echo "stack[1] after modify: " . $stack[1] . "\n";       // 999

        echo "\n========== 5. 嵌套 array<array<int>> ==========\n";
        // 注意：array<array<int>> 是 t_arr_ptr*，无法自动转换为 t_array*
        // 需先内层访问取出 array<int>，再调用函数
        $nested = [[1, 2, 3], [4, 5, 6], [7, 8, 9]];
        echo "outer count: " . count($nested) . "\n";            // 3
        echo "inner[0] count: " . count($nested[0]) . "\n";      // 3
        echo "inner[0] sum: " . array_sum($nested[0]) . "\n";    // 6
        echo "inner[2] sum: " . array_sum($nested[2]) . "\n";    // 24

        echo "\n========== 6. array<T> 与 array<mixed> 互操作 ==========\n";
        // array<T> 可以传给接收 array<mixed> 的用户函数
        $total = $this->sumArray($ints);
        echo "sumArray(array<int>): " . $total . "\n";           // 150

        $strTotal = $this->joinStrings($strs);
        echo "joinStrings(array<string>): " . $strTotal . "\n";  // foo|bar|baz|qux

        echo "\n========== 7. 链式调用 ==========\n";
        // array_map → array_filter → array_reduce
        $chainResult = array_reduce(
            array_filter(
                array_map(function (int $x): int { return $x * $x; }, $ints),
                function (int $x): bool { return $x > 500; }
            ),
            function (int $carry, int $x): int { return $carry + $x; },
            0
        );
        echo "chain (map² filter>500 reduce): " . $chainResult . "\n";
        // 10²=100, 20²=400, 30²=900, 40²=1600, 50²=2500 → >500: 900+1600+2500=5000

        echo "\n========== 8. 其他数组函数（array<string> 元素类型追踪）==========\n";
        // array_reverse: 元素类型应跟随源数组
        $rev = array_reverse($strs);
        echo "array_reverse[0]: " . $rev[0] . " [3]: " . $rev[3] . "\n";  // qux foo

        // array_pad: 元素类型应跟随源数组（补 "" 到 6 个）
        $pad = array_pad($strs, 6, "");
        echo "array_pad count: " . count($pad) . "\n";                   // 6
        echo "array_pad[0]: " . $pad[0] . " [4]: " . $pad[4] . "\n";     // foo ""

        // array_diff: 元素类型跟随第一个数组
        $diff = array_diff($strs, ["baz", "qux"]);
        echo "array_diff[0]: " . $diff[0] . " [1]: " . $diff[1] . "\n";  // foo bar

        // array_intersect: 元素类型跟随第一个数组
        $inter = array_intersect($strs, ["foo", "bar", "zzz"]);
        echo "array_intersect[0]: " . $inter[0] . " [1]: " . $inter[1] . "\n";  // foo bar

        // array<int> 上 array_reverse
        $irev = array_reverse($ints);
        echo "int array_reverse[0]: " . $irev[0] . " [4]: " . $irev[4] . "\n";  // 50 10

        echo "\n========== 9. 原地修改函数 shuffle（array<T> 特化）==========\n";
        // shuffle 对 array<T> 调用特化函数 tphp_fn_arr_{suffix}_shuffle 原地打乱
        //   返回 bool（true=成功），原数组元素集合不变（仅顺序改变）
        array<int> $shuffled = [10, 20, 30, 40, 50];
        $shOk = shuffle($shuffled);
        $shSum = 0;
        foreach ($shuffled as $v) { $shSum += $v; }
        echo "shuffle: ok=" . ($shOk ? "true" : "false") . " sum=" . $shSum . "\n";  // ok=true sum=150

        array<string> $shStrs = ["a", "b", "c", "d"];
        $shOk2 = shuffle($shStrs);
        // shuffle 是随机的，只验证 ok=true 和元素个数不变（不断言顺序）
        echo "shuffle(str): ok=" . ($shOk2 ? "true" : "false") . " count=" . count($shStrs) . "\n";  // ok=true count=4

        echo "\n========== 10. asort/arsort 在 array<mixed> 上（array<T> 应拒绝）==========\n";
        // asort/arsort 保持 key-value 关联，不适用于 array<T>（编译期拒绝）
        // 在 array<mixed> 上用 foreach 遍历验证排序结果（key 关联保持）
        $mixAsc = [30, 10, 20];
        asort($mixAsc);
        $ascOut = "";
        foreach ($mixAsc as $v) { $ascOut .= $v . " "; }
        echo "asort(mixed) foreach: " . trim($ascOut) . "\n";  // 10 20 30

        $mixDesc = [10, 30, 20];
        arsort($mixDesc);
        $descOut = "";
        foreach ($mixDesc as $v) { $descOut .= $v . " "; }
        echo "arsort(mixed) foreach: " . trim($descOut) . "\n";  // 30 20 10

        echo "\n========== 11. 返回数组函数的元素类型追踪 ==========\n";
        // array_fill: value 通过 wraparr 包装为 t_var 存入数组，元素统一为 t_var
        //   未追踪元素类型时默认 t_int，访问字符串/浮点值会调用 typed getter 返回 0
        $fillInt = array_fill(0, 3, 99);
        echo "fill(int)[0]: " . $fillInt[0] . " [2]: " . $fillInt[2] . "\n";  // 99 99

        $fillStr = array_fill(0, 3, "hi");
        echo "fill(str)[0]: " . $fillStr[0] . " [2]: " . $fillStr[2] . "\n";  // hi hi

        $fillFloat = array_fill(0, 3, 1.5);
        echo "fill(float)[0]: " . $fillFloat[0] . " [2]: " . $fillFloat[2] . "\n";  // 1.5 1.5

        // array_combine: 元素类型 = values 数组元素类型（从第二参数推导）
        //   未追踪时默认 t_int，访问字符串值返回 0
        $combStr = array_combine(["a", "b"], ["x", "y"]);
        echo "combine[str]: " . $combStr["a"] . " " . $combStr["b"] . "\n";  // x y

        $combInt = array_combine(["a", "b"], [10, 20]);
        echo "combine[int]: " . $combInt["a"] . " " . $combInt["b"] . "\n";  // 10 20

        // array_chunk: 外层元素类型 = t_array*，内层元素类型 = 源数组元素类型
        //   未追踪时外层默认 t_int，访问 $chunks[0] 调用 typed getter 返回 0
        $chunks = array_chunk([1, 2, 3, 4, 5], 2);
        echo "chunk count: " . count($chunks) . "\n";              // 3
        echo "chunk[0] count: " . count($chunks[0]) . "\n";        // 2
        echo "chunk[0][0]: " . $chunks[0][0] . "\n";              // 1
        echo "chunk[0][1]: " . $chunks[0][1] . "\n";              // 2
        echo "chunk[2][0]: " . $chunks[2][0] . "\n";              // 5

        // array_column: 列值通过 tphp_fn_arr_push(out, val) 存入，元素统一 t_var
        //   未追踪时默认 t_int，访问字符串列值返回 0
        $rows = [
            ["name" => "alice", "age" => 30],
            ["name" => "bob", "age" => 25],
        ];
        $names = array_column($rows, "name");
        echo "column[0]: " . $names[0] . "\n";                     // alice
        echo "column[1]: " . $names[1] . "\n";                     // bob

        // array_count_values: 值是出现次数（int），键是原值
        //   元素类型 t_int 显式注册（不依赖默认值）
        $cv = array_count_values(["a", "b", "a"]);
        echo "count_values[a]: " . $cv["a"] . "\n";                // 2
        echo "count_values[b]: " . $cv["b"] . "\n";                // 1

        echo "\n========== all done ==========\n";
        echo "OK\n";
    }

    // 接收 array<mixed> 的用户函数（array<T> 会自动协变转换）
    public function sumArray(array $arr): int
    {
        $sum = 0;
        foreach ($arr as $v) {
            $sum += $v;
        }
        return $sum;
    }

    public function joinStrings(array $arr): string
    {
        $result = "";
        $first = true;
        foreach ($arr as $s) {
            if (!$first) {
                $result .= "|";
            }
            $result .= $s;
            $first = false;
        }
        return $result;
    }
}
