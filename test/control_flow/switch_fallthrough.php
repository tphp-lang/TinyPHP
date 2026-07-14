<?php
#debug ===== Int Switch (fall-through) =====
#debug int(3)
#debug
#debug ===== String Switch (fall-through) =====
#debug int(10)
#debug
#debug ===== Int Switch (normal break) =====
#debug string(3) "two"
#debug
#debug ===== Multiple cases fall-through =====
#debug string(7) "weekend"
#debug
#debug ===== String switch fall-through =====
#debug string(7) "weekend"
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // int switch 穿透测试：case 1 无 break，应穿透到 case 2
        echo "===== Int Switch (fall-through) =====\n";
        int $n = 1;
        int $count = 0;
        switch ($n) {
            case 1:
                $count = $count + 1;
                // 无 break，穿透到 case 2
            case 2:
                $count = $count + 2;
                break;
            case 3:
                $count = $count + 3;
                break;
        }
        var_dump($count);  // 1 + 2 = 3
        echo "\n";

        // string switch 穿透测试（现已支持，通过 if-goto 标签链实现）
        echo "===== String Switch (fall-through) =====\n";
        string $s = "a";
        int $sc = 0;
        switch ($s) {
            case "a":
                $sc = $sc + 1;
                // 无 break，穿透到 case "b"
            case "b":
                $sc = $sc + 9;
                break;
        }
        var_dump($sc);  // 1 + 9 = 10 (穿透发生)
        echo "\n";

        // int switch 正常 break
        echo "===== Int Switch (normal break) =====\n";
        int $m = 2;
        string $result = "";
        switch ($m) {
            case 1:
                $result = "one";
                break;
            case 2:
                $result = "two";
                break;
            case 3:
                $result = "three";
                break;
        }
        var_dump($result);
        echo "\n";

        // 多个 case 共享代码块（标准穿透写法）
        echo "===== Multiple cases fall-through =====\n";
        int $day = 6;
        string $type = "";
        switch ($day) {
            case 1:
            case 2:
            case 3:
            case 4:
            case 5:
                $type = "weekday";
                break;
            case 6:
            case 7:
                $type = "weekend";
                break;
        }
        var_dump($type);
        echo "\n";

        // 字符串 switch 穿透测试：case "Sat" 无 break，穿透到 case "Sun"
        echo "===== String switch fall-through =====\n";
        string $day2 = "Sat";
        string $type2 = "";
        switch ($day2) {
            case "Mon":
            case "Tue":
            case "Wed":
            case "Thu":
            case "Fri":
                $type2 = "weekday";
                break;
            case "Sat":
                // 无 break，穿透到 case "Sun"
            case "Sun":
                $type2 = "weekend";
                break;
        }
        var_dump($type2);
        echo "\n";

        echo "=== done ===\n";
    }
}
