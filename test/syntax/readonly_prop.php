<?php
#debug 1. x=10
#debug 2. py=200
#debug 3. name=hello
#debug 4. ver=3.14
#debug 5. px=100
#debug 6. done

// readonly 属性测试 — PHP 8.1/8.2 语法
class Point {
    // readonly 属性：只能在 __construct 内赋值一次
    public readonly int $x;
    public readonly int $y;

    // readonly 属性提升（PHP 8.2 语法）
    public function __construct(
        public readonly int $px,
        public readonly int $py,
    ) {
        $this->x = 10;
        $this->y = 20;
    }
}

// readonly class — 所有属性自动 readonly（PHP 8.2 语法）
readonly class Config {
    public string $name;
    public float $version;

    public function __construct(string $name, float $version) {
        $this->name = $name;
        $this->version = $version;
    }
}

class Main {
    public function main(): void {
        $p = new Point(100, 200);

        // readonly 属性可读
        echo "1. x=" . $p->x . "\n";

        // readonly 属性提升可读
        echo "2. py=" . $p->py . "\n";

        // readonly class 属性可读
        $c = new Config("hello", 3.14);
        echo "3. name=" . $c->name . "\n";
        echo "4. ver=" . $c->version . "\n";

        // readonly 属性提升可读
        echo "5. px=" . $p->px . "\n";

        echo "6. done\n";
    }
}
