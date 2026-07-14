<?php
// defer 语句测试：验证 LIFO 执行顺序 + return 路径 + fall-through 路径
// defer 为函数级作用域（PHP 无块作用域），在函数退出时 LIFO 执行
#debug === defer fall-through ===
#debug body
#debug defer 3
#debug defer 2
#debug defer 1
#debug
#debug === defer return ===
#debug defer return 2
#debug defer return 1
#debug got: 42
#debug
#debug === defer single ===
#debug defer expr
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== defer fall-through ===\n";

        $this->fallthrough();
        echo "\n=== defer return ===\n";
        $result = $this->withReturn();
        echo "got: " . $result . "\n";
        echo "\n=== defer single ===\n";
        $this->singleDefer();
        echo "\n";
        echo "=== All passed ===\n";
    }

    private function fallthrough(): void {
        defer echo "defer 1\n";
        defer echo "defer 2\n";
        defer echo "defer 3\n";
        echo "body\n";
    }

    private function withReturn(): int {
        defer echo "defer return 1\n";
        defer echo "defer return 2\n";
        return 42;
    }

    private function singleDefer(): void {
        defer echo "defer expr\n";
    }
}
