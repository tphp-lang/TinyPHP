<?php
#debug 1. MAX=100
#debug 2. PI=3.14
#debug 3. GREETING=hello
#debug 4. FLAG=1
#debug 5. area=314
#debug 6. lower=hello
#debug 7. done

class Main {
    public function main(): void {
        // 1-4. 各种类型的函数内 const（带类型标记）
        echo "1. MAX=" . $this->typed() . "\n";
        echo "2. PI=" . $this->pi() . "\n";
        echo "3. GREETING=" . $this->greeting() . "\n";
        echo "4. FLAG=" . (int)$this->flag() . "\n";

        // 5. const 参与计算
        echo "5. area=" . $this->area() . "\n";

        // 6. 无类型标记的 const（类型从字面量推导）
        echo "6. lower=" . $this->lower() . "\n";

        echo "7. done\n";
    }

    public function typed(): int {
        const int MAX = 100;
        return MAX;
    }

    public function pi(): float {
        const float PI = 3.14;
        return PI;
    }

    public function greeting(): string {
        const string GREETING = "hello";
        return GREETING;
    }

    public function flag(): bool {
        const bool FLAG = true;
        return FLAG;
    }

    public function area(): float {
        const float PI = 3.14;
        const int RADIUS = 100;
        // const 参与算术运算
        return PI * RADIUS;
    }

    public function lower(): string {
        // 无类型标记，从字面量推导为 string
        const MSG = "hello";
        return MSG;
    }
}
