<?php
// 对应 PHP ext/standard/tests/array/array_keys_variation_001.phpt
// array_keys($arr, $search): 返回所有值为 $search 的键

#debug int(6)
#debug int(3)
#debug int(1)

class Main
{
    public function main(): void
    {
        $a = [1, 2, 3, 2, 4, 2];

        // 无 search 参数：返回所有键
        $k1 = array_keys($a);
        var_dump(count($k1));   // int(6)

        // search = 2：返回 [0, 3, 5]
        $k2 = array_keys($a, 2);
        var_dump(count($k2));   // int(3)

        // search = 4：返回 [4]
        $k3 = array_keys($a, 4);
        var_dump(count($k3));   // int(1)
    }
}
