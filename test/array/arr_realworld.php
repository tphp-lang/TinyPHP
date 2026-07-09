<?php
#debug === 1. 配置数组 (str key mix) ===
#debug host: localhost
#debug port: int(8080)
#debug debug on
#debug port OK
#debug
#debug === 2. 统计数组 (累加+比较) ===
#debug total: 225
#debug max:   78
#debug
#debug === 3. 数字数组求和 ===
#debug sum: 21
#debug
#debug === 4. 覆盖+条件 ===
#debug error 500 handled
#debug === all passed ===

class Main
{
    public function main(): void
    {
        $i = 0; $v = 0;  // for 循环变量（TinyPHP C99 作用域需提前声明）

        echo "=== 1. 配置数组 (str key mix) ===\n";
        $cfg = [];
        $cfg["debug"] = 1;
        $cfg["host"]  = "localhost";
        $cfg["port"]  = 8080;

        echo "host: " . $cfg["host"] . "\n";
        echo "port: ";
        var_dump($cfg["port"]);
        if ($cfg["debug"] == 1) { echo "debug on\n"; }
        if ($cfg["port"] > 0)   { echo "port OK\n"; }

        echo "\n=== 2. 统计数组 (累加+比较) ===\n";
        $s = [];
        $s["total"] = 0;
        $s["max"]   = 0;
        $data = [45, 12, 78, 34, 56];
        for ($i = 0; $i < 5; $i++) {
            $v = $data[$i];
            $s["total"] = $s["total"] + $v;
            if ($v > $s["max"]) { $s["max"] = $v; }
        }
        echo "total: " . $s["total"] . "\n";
        echo "max:   " . $s["max"]   . "\n";

        echo "\n=== 3. 数字数组求和 ===\n";
        $a = [1, 2, 3];
        $b = [4, 5, 6];
        $sum = 0;
        for ($i = 0; $i < 3; $i++) {
            $sum = $sum + $a[$i] + $b[$i];
        }
        echo "sum: " . $sum . "\n";

        echo "\n=== 4. 覆盖+条件 ===\n";
        $r = [];
        $r["code"] = 200;
        if ($r["code"] != 200) { error("bad code"); }
        $r["code"] = 500;
        if ($r["code"] == 500) { echo "error 500 handled\n"; }

        echo "=== all passed ===\n";
    }
}
