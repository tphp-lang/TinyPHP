<?php
// 测试 (object) 和 (array) 转换
class Main {
    public function main(): void {
        $arr = ['name' => 'Alice', 'age' => 30, 'city' => 'Beijing'];
        $obj = (object) $arr;
        echo $obj->name . "\n";
        echo $obj->age . "\n";
        echo $obj->city . "\n";

        // (array) stdClass
        $obj2 = new stdClass();
        $obj2->x = 1;
        $obj2->y = "hello";
        $obj2->z = true;
        $back = (array) $obj2;
        echo count($back) . "\n";
        foreach ($back as $k => $v) {
            echo "$k=" . (string)$v . "\n";
        }

        // get_object_vars
        $obj3 = new stdClass();
        $obj3->a = 10;
        $obj3->b = 20;
        $obj3->c = 30;
        $vars = get_object_vars($obj3);
        echo count($vars) . "\n";
        echo $vars['a'] . "\n";
        echo $vars['b'] . "\n";
        echo $vars['c'] . "\n";
    }
}
