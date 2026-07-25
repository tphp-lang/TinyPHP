<?php
// 非对称可见性 private(set) 测试 — PHP 8.4 语法
#debug 1. read=100
#debug 2. after-setter=200
#debug 3. promoted-read=hello
#debug 4. promoted-after-setter=world
#debug 5. hook-read=42
#debug 6. hook-after-setter=0
#debug 7. done

class Counter {
    // 直接声明的 public private(set) 属性
    //   类外可读，但只能在 Counter 内写入
    public private(set) int $count;

    public function __construct(int $initial) {
        $this->count = $initial;
    }

    // 声明类内的方法可以写入 private(set) 属性
    public function increment(): void {
        $this->count = $this->count + 100;
    }

    public function getCount(): int {
        return $this->count;
    }
}

class User {
    // 属性提升 + 非对称可见性：public private(set) string $name
    public function __construct(
        public private(set) string $name,
    ) {}

    public function setName(string $newName): void {
        $this->name = $newName;
    }
}

class Validator {
    // private(set) 与 property hook 组合：
    //   set hook 校验值非负（声明类内写入触发 hook），private(set) 禁止类外写入
    public private(set) int $value {
        get => $this->value;
        set => $value >= 0 ? $value : 0;
    }

    public function __construct(int $v) {
        $this->value = $v;
    }

    // 声明类内方法写入 → 触发 set hook
    public function update(int $v): void {
        $this->value = $v;
    }
}

class Main {
    public function main(): void {
        // 1. 类外读取 public private(set) 属性
        $c = new Counter(100);
        echo "1. read=" . $c->count . "\n";

        // 2. 声明类内方法写入 private(set) 属性
        $c->increment();
        echo "2. after-setter=" . $c->getCount() . "\n";

        // 3. 属性提升的 private(set) 读取
        $u = new User("hello");
        echo "3. promoted-read=" . $u->name . "\n";

        // 4. 声明类内方法写入提升的 private(set) 属性
        $u->setName("world");
        echo "4. promoted-after-setter=" . $u->name . "\n";

        // 5. private(set) + property hook: 构造函数写入 42 (>=0) → hook 存 42
        $v = new Validator(42);
        echo "5. hook-read=" . $v->value . "\n";

        // 6. 声明类内方法写入 -5 → hook 校验返回 0
        $v->update(-5);
        echo "6. hook-after-setter=" . $v->value . "\n";

        echo "7. done\n";
    }
}
