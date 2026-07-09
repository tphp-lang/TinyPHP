<?php
#debug int(1)
#debug int(2)
#debug int(3)
#debug hello world
#debug int(100)
#debug int(200)
#debug int(2)
#debug === done ===

class Main
{
    public function main(): void
    {
        // 基础 push
        $a = [1, 2, 3];
        var_dump($a[0]);
        var_dump($a[1]);
        var_dump($a[2]);

        // string value in array
        $b = ["hello", "world"];
        echo $b[0] . " " . $b[1] . "\n";

        // int key set
        $c = [];
        $c[0] = 100;
        $c[1] = 200;
        var_dump($c[0]);
        var_dump($c[1]);

        // string key set with int value
        $d = [];
        $d["vers"] = 2;
        var_dump($d["vers"]);

        echo "=== done ===\n";
    }
}
