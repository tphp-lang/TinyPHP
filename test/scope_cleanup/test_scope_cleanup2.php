<?php
#debug hello
#debug world
#debug second
#debug 6
#debug inner
#debug helper result
#debug end

class Main {
    public function main(): void {
        // 测试1: 多个字符串变量
        $a = "hello";
        $b = "world";
        echo $a . "\n";
        echo $b . "\n";

        // 测试2: 字符串重新赋值
        $c = "first";
        $c = "second";
        echo $c . "\n";

        // 测试3: 数组操作
        $arr1 = [1, 2, 3];
        $arr2 = [4, 5, 6];
        $sum = count($arr1) + count($arr2);
        echo $sum . "\n";

        // 测试4: 条件作用域
        $x = "outer";
        if (true) {
            $y = "inner";
            echo $y . "\n";
        }

        // 测试5: 循环中的变量
        for ($i = 0; $i < 3; $i++) {
            $loopVar = "loop" . $i;
        }

        // 测试6: 函数调用后变量仍在
        $fn = $this->helper();
        echo $fn . "\n";

        echo "end\n";
    }

    public function helper(): string {
        $temp = "helper result";
        return $temp;
    }
}
