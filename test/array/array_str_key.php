<?php
#debug === test 1: 基础 str key ===
#debug name: TinyPHP
#debug vers: int(2)
#debug
#debug === test 2: 多键读写 ===
#debug Alice is 30 from SZ
#debug
#debug === test 3: 覆盖 ===
#debug int(1)
#debug int(99)
#debug
#debug === test 4: 变量 key ===
#debug pal[color]: blue
#debug
#debug === test 5: 条件中读取 ===
#debug debug on, level=3
#debug
#debug === test 6: 混合 int/str key ===
#debug mix[0]: zero
#debug mix[one]: 1
#debug mix[2]: two
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // === 基础字符串键读写 ===
        echo "=== test 1: 基础 str key ===\n";
        $m = [];
        $m["name"] = "TinyPHP";
        $m["vers"] = 2;
        echo "name: " . $m["name"] . "\n";
        echo "vers: ";
        var_dump($m["vers"]);

        // === 多键 ===
        echo "\n=== test 2: 多键读写 ===\n";
        $user = [];
        $user["name"] = "Alice";
        $user["age"]  = 30;
        $user["city"] = "SZ";
        echo $user["name"] . " is " . $user["age"] . " from " . $user["city"] . "\n";

        // === 覆盖写入 ===
        echo "\n=== test 3: 覆盖 ===\n";
        $u = [];
        $u["x"] = 1;
        var_dump($u["x"]);
        $u["x"] = 99;
        var_dump($u["x"]);

        // === 变量 key ===
        echo "\n=== test 4: 变量 key ===\n";
        $k = "color";
        $pal = [];
        $pal[$k] = "blue";
        echo "pal[color]: " . $pal["color"] . "\n";

        // === 表达式 ===
        echo "\n=== test 5: 条件中读取 ===\n";
        $cfg = [];
        $cfg["debug"] = 1;
        $cfg["level"] = 3;
        if ($cfg["debug"] == 1) {
            echo "debug on, level=" . $cfg["level"] . "\n";
        }

        // === 数值混合 ===
        echo "\n=== test 6: 混合 int/str key ===\n";
        $mix = [];
        $mix[0]     = "zero";
        $mix["one"] = "1";
        $mix[2]     = "two";
        echo "mix[0]: " . $mix[0] . "\n";
        echo "mix[one]: " . $mix["one"] . "\n";
        echo "mix[2]: " . $mix[2] . "\n";

        echo "\n=== done ===\n";
    }
}
