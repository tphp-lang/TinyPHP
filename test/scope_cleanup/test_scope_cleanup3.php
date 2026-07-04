<?php
#debug hello
#debug 3
#debug nested
#debug hello
#debug end

class Main {
    public function main(): void {
        // 测试1: 基本字符串自动释放
        $s1 = "hello";
        echo $s1 . "\n";

        // 测试2: 数组自动释放
        $arr = [1, 2, 3];
        echo count($arr) . "\n";

        // 测试3: 嵌套作用域
        if (true) {
            $inner = "nested";
            echo $inner . "\n";
        }

        // 测试4: 返回值变量不应该被释放
        $result = $this->getString();
        echo $result . "\n";

        // 测试5: 循环中的字符串
        $arr2 = [1, 2, 3];
        for ($i = 0; $i < count($arr2); $i++) {
            $loopStr = "item";
        }

        echo "end\n";
    }

    public function getString(): string {
        $temp = "hello";
        return $temp;
    }
}
