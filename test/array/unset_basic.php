<?php
#debug === 1. unset int key ===
#debug cnt=3
#debug cnt=2
#debug val1=b
#debug
#debug === 2. unset str key ===
#debug name=Alice
#debug age=30
#debug name=Alice
#debug
#debug === 3. unset 后重新赋值 ===
#debug x=1
#debug x=99
#debug
#debug === 4. 连续 unset 多个 ===
#debug cnt=5
#debug cnt=2
#debug
#debug === 5. unset 不存在的 key ===
#debug cnt=3
#debug cnt=3
#debug
#debug === 6. 混合 key unset ===
#debug mix[0]=a mix[one]=b mix[2]=c
#debug mix[one]=b mix[2]=c
#debug
#debug === 7. unset 嵌套数组元素 ===
#debug outer cnt=2
#debug inner0=10
#debug outer cnt=1
#debug
#debug === 8. unset 变量 key ===
#debug v=10 v=20 v=30
#debug v=10 v=30
#debug
#debug === 9. foreach 中 unset ===
#debug kept: 1 3
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // === 1. unset int key ===
        echo "=== 1. unset int key ===\n";
        $a = [];
        $a[] = "a";
        $a[] = "b";
        $a[] = "c";
        echo "cnt=" . count($a) . "\n";
        unset($a[0]);
        echo "cnt=" . count($a) . "\n";
        echo "val1=" . $a[1] . "\n";

        // === 2. unset str key ===
        echo "\n=== 2. unset str key ===\n";
        $u = [];
        $u["name"] = "Alice";
        $u["age"] = 30;
        $u["city"] = "SZ";
        echo "name=" . $u["name"] . "\n";
        echo "age=" . $u["age"] . "\n";
        unset($u["age"]);
        unset($u["city"]);
        echo "name=" . $u["name"] . "\n";

        // === 3. unset 后重新赋值 ===
        echo "\n=== 3. unset 后重新赋值 ===\n";
        $r = [];
        $r["x"] = 1;
        echo "x=" . $r["x"] . "\n";
        unset($r["x"]);
        $r["x"] = 99;
        echo "x=" . $r["x"] . "\n";

        // === 4. 连续 unset 多个 ===
        echo "\n=== 4. 连续 unset 多个 ===\n";
        $m = [];
        $m["a"] = 1;
        $m["b"] = 2;
        $m["c"] = 3;
        $m["d"] = 4;
        $m["e"] = 5;
        echo "cnt=" . count($m) . "\n";
        unset($m["a"], $m["b"], $m["c"]);
        echo "cnt=" . count($m) . "\n";

        // === 5. unset 不存在的 key ===
        echo "\n=== 5. unset 不存在的 key ===\n";
        $n = [10, 20, 30];
        echo "cnt=" . count($n) . "\n";
        unset($n[99]);
        echo "cnt=" . count($n) . "\n";

        // === 6. 混合 key unset ===
        echo "\n=== 6. 混合 key unset ===\n";
        $mix = [];
        $mix[0] = "a";
        $mix["one"] = "b";
        $mix[2] = "c";
        echo "mix[0]=" . $mix[0] . " mix[one]=" . $mix["one"] . " mix[2]=" . $mix[2] . "\n";
        unset($mix[0]);
        echo "mix[one]=" . $mix["one"] . " mix[2]=" . $mix[2] . "\n";

        // === 7. unset 嵌套数组元素 ===
        echo "\n=== 7. unset 嵌套数组元素 ===\n";
        $outer = [];
        $outer[0] = [10, 20];
        $outer[1] = [30, 40];
        echo "outer cnt=" . count($outer) . "\n";
        echo "inner0=" . $outer[0][0] . "\n";
        unset($outer[1]);
        echo "outer cnt=" . count($outer) . "\n";

        // === 8. unset 变量 key ===
        echo "\n=== 8. unset 变量 key ===\n";
        $v = [];
        $v["a"] = 10;
        $v["b"] = 20;
        $v["c"] = 30;
        echo "v=" . $v["a"] . " v=" . $v["b"] . " v=" . $v["c"] . "\n";
        $key = "b";
        unset($v[$key]);
        echo "v=" . $v["a"] . " v=" . $v["c"] . "\n";

        // === 9. foreach 中 unset ===
        echo "\n=== 9. foreach 中 unset ===\n";
        $nums = [1, 2, 3, 4, 5];
        $kept = [];
        foreach ($nums as $i => $val) {
            if ($val % 2 == 0) {
                unset($nums[$i]);
            } else {
                $kept[] = $val;
            }
        }
        echo "kept: " . $kept[0] . " " . $kept[1] . "\n";

        echo "\n=== done ===\n";
    }
}
