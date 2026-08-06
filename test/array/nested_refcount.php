<?php
#debug 4
#debug int(1)
#debug int(7)
#debug int(3)
#debug int(6)
#debug int(1)
#debug int(4)
#debug ok

class Main
{
    public function main(): void
    {
        // 测试嵌套数组的引用计数：pad/slice/reverse 后 unset 原数组不应崩溃
        $a = [[1, 2, 3], [4, 5, 6]];
        $b = array_pad($a, 4, [7, 8, 9]);
        unset($a);
        echo count($b) . "\n";          // 4
        var_dump($b[0][0]);             // int(1)
        var_dump($b[3][0]);             // int(7)

        $c = [[1, 2], [3, 4], [5, 6]];
        $d = array_slice($c, 1, 2);
        unset($c);
        var_dump($d[0][0]);             // int(3)
        var_dump($d[1][1]);             // int(6)

        $e = ["a" => [1, 2], "b" => [3, 4]];
        $f = array_reverse($e, true);
        unset($e);
        var_dump($f["a"][0]);           // int(1)
        var_dump($f["b"][1]);           // int(4)

        // 对象数组释放
        $g = [new stdClass(), new stdClass()];
        unset($g);
        echo "ok\n";
    }
}
