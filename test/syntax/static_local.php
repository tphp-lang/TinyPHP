<?php
#debug 1. counter=1
#debug 2. counter=2
#debug 3. counter=3
#debug 4. msg=hello
#debug 5. msg=updated
#debug 6. pi=3.14
#debug 7. total=100
#debug 8. flag=1
#debug 9. all done

class Main {
    public function main(): void {
        // 1-3. static int 计数器 — 跨调用保持值
        echo "1. counter=" . $this->counter() . "\n";
        echo "2. counter=" . $this->counter() . "\n";
        echo "3. counter=" . $this->counter() . "\n";

        // 4-5. static string — 同一函数内跨调用保持值，可修改
        echo "4. msg=" . $this->msg("get") . "\n";
        echo "5. msg=" . $this->msg("updated") . "\n";

        // 6. static float
        echo "6. pi=" . $this->getPi() . "\n";

        // 7. static int 带类型标记
        echo "7. total=" . $this->total100() . "\n";

        // 8. static bool
        echo "8. flag=" . (int)$this->getFlag() . "\n";

        echo "9. all done\n";
    }

    public function counter(): int {
        static int $n = 0;
        $n = $n + 1;
        return $n;
    }

    // get/set 二合一: 传入 "get" 返回当前值，传入其他值则更新并返回新值
    public function msg(string $action): string {
        static string $m = "hello";
        if ($action === "get") {
            return $m;
        }
        $m = $action;
        return $m;
    }

    public function getPi(): float {
        static float $pi = 3.14;
        return $pi;
    }

    public function total100(): int {
        static int $total = 100;
        return $total;
    }

    public function getFlag(): bool {
        static bool $flag = true;
        return $flag;
    }
}
