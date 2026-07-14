<?php
#debug === 1. 3层嵌套 ===
#debug l0[0]=0 l0[1]=0
#debug sub[0]=10 sub[1]=20 sub[2][0]=0
#debug
#debug === 2. 累加+条件 ===
#debug sum=50 cnt=4 flag=1
#debug
#debug === 3. 混合key数组 ===
#debug mix[0]=first mix[key]=second mix[2]=third
#debug
#debug === 4. 嵌套字典 ===
#debug Alice: age=25 city=NY
#debug Bob:   age=30 city=LA
#debug
#debug === 5. 条件分支 ===
#debug mode=2 name=test
#debug
#debug === 6. 数据表遍历 ===
#debug   id=1 val=100
#debug   id=2 val=200
#debug   id=3 val=300
#debug
#debug === 7. while+数组 ===
#debug   q[0]=100
#debug   q[1]=200
#debug   q[2]=300
#debug
#debug === 8. count判断 ===
#debug empty OK
#debug nonempty OK
#debug
#debug === 9. 数组值比较 ===
#debug x.a == 100 OK
#debug x.b == 200 OK
#debug === all passed ===

class Main
{
    public function main(): void
    {
        $i = 0; $v = 0;

        // === 1. 3层嵌套 ===
        echo "=== 1. 3层嵌套 ===\n";
        $l2 = [30, 40];
        $l1 = [10, 20, $l2];
        $l0 = [1, 2, $l1];
        echo "l0[0]=" . $l0[0] . " l0[1]=" . $l0[1] . "\n";
        $sub = $l0[2];
        echo "sub[0]=" . $sub[0] . " sub[1]=" . $sub[1] . " sub[2][0]=" . $sub[2][0] . "\n";

        // === 2. 累加+条件+覆盖 ===
        echo "\n=== 2. 累加+条件 ===\n";
        $acc = [];
        $acc["sum"]   = 0;
        $acc["count"] = 0;
        $acc["flag"]  = 0;
        $vals = [5, 10, 15, 20];
        for ($i = 0; $i < 4; $i++) {
            $v = $vals[$i];
            $acc["sum"]   = $acc["sum"] + $v;
            $acc["count"] = $acc["count"] + 1;
            if ($v > 10) { $acc["flag"] = 1; }
        }
        echo "sum=" . $acc["sum"] . " cnt=" . $acc["count"] . " flag=" . $acc["flag"] . "\n";

        // === 3. 混合 int+str key ===
        echo "\n=== 3. 混合key数组 ===\n";
        $mix = [];
        $mix[0]     = "first";
        $mix["key"] = "second";
        $mix[2]     = "third";
        echo "mix[0]=" . $mix[0] . " mix[key]=" . $mix["key"] . " mix[2]=" . $mix[2] . "\n";

        // === 4. 嵌套字典 (逐层赋值) ===
        echo "\n=== 4. 嵌套字典 ===\n";
        $alice = [];
        $alice["age"]  = 25;
        $alice["city"] = "NY";
        $bob = [];
        $bob["age"]  = 30;
        $bob["city"] = "LA";
        $db_users = [];
        $db_users["alice"] = $alice;
        $db_users["bob"]   = $bob;
        $db = [];
        $db["users"] = $db_users;
        $users = $db["users"];
        $a = $users["alice"];
        $b = $users["bob"];
        echo "Alice: age=" . $a["age"] . " city=" . $a["city"] . "\n";
        echo "Bob:   age=" . $b["age"] . " city=" . $b["city"] . "\n";

        // === 5. 条件分支 ===
        echo "\n=== 5. 条件分支 ===\n";
        $cfg = [];
        $cfg["mode"] = 2;
        $cfg["name"] = "test";
        if ($cfg["mode"] == 1) {
            echo "mode 1\n";
        } else {
            echo "mode=" . $cfg["mode"] . " name=" . $cfg["name"] . "\n";
        }

        // === 6. 数据表遍历 ===
        echo "\n=== 6. 数据表遍历 ===\n";
        $rows = [];
        $r0 = ["id" => 1, "val" => 100];
        $r1 = ["id" => 2, "val" => 200];
        $r2 = ["id" => 3, "val" => 300];
        $rows[0] = $r0;
        $rows[1] = $r1;
        $rows[2] = $r2;
        for ($i = 0; $i < 3; $i++) {
            $r = $rows[$i];
            echo "  id=" . $r["id"] . " val=" . $r["val"] . "\n";
        }

        // === 7. while+数组 ===
        echo "\n=== 7. while+数组 ===\n";
        $q = [100, 200, 300];
        $idx = 0;
        while ($idx < 3) {
            echo "  q[" . $idx . "]=" . $q[$idx] . "\n";
            $idx++;
        }

        // === 8. 数组判断 ===
        echo "\n=== 8. count判断 ===\n";
        $empty = [];
        if (count($empty) == 0) { echo "empty OK\n"; }
        $nonempty = [1];
        if (count($nonempty) > 0) { echo "nonempty OK\n"; }

        // === 9. 数组比较 (==) ===
        echo "\n=== 9. 数组值比较 ===\n";
        $x = [];
        $x["a"] = 100;
        $x["b"] = 200;
        if ($x["a"] == 100) { echo "x.a == 100 OK\n"; }
        if ($x["b"] == 200) { echo "x.b == 200 OK\n"; }

        echo "=== all passed ===\n";
    }
}
