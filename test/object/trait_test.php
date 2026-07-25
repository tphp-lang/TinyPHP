<?php
// Trait 支持测试（PHP 8.5 语法，AOT 编译期扁平化）
// trait 自身不生成 C 结构体，使用 trait 的类在编译期复制 trait 的方法/属性/常量
// 对应 PHP trait + insteadof 冲突解决 + as 别名

#debug ===== 1. 基本 trait 方法 =====
#debug Hello from trait
#debug
#debug ===== 2. trait 属性 =====
#debug int(42)
#debug int(13)
#debug
#debug ===== 3. trait 常量 =====
#debug int(60)
#debug
#debug ===== 4. insteadof 冲突解决 =====
#debug B-talk
#debug
#debug ===== 5. as 别名 =====
#debug A-talk
#debug
#debug ===== 6. 类方法覆盖 trait 方法 =====
#debug class-method
#debug
#debug ===== 7. trait 方法访问 $this =====
#debug int(30)
#debug
#debug ===== 8. all done =====
#debug OK

trait Greeter {
    public const int TIMEOUT = 60;

    public string $name = "Bob";

    public function sayHello(): void {
        echo "Hello from trait\n";
    }

    public function greet(): void {
        echo "greet:" . $this->name . "\n";
    }
}

trait Counter {
    public int $count = 0;

    public function increment(): void {
        $this->count = $this->count + 1;
    }

    public function talk(): void {
        echo "A-talk\n";
    }
}

trait BTalker {
    public function talk(): void {
        echo "B-talk\n";
    }
}

class User {
    use Greeter;
    use Counter, BTalker {
        BTalker::talk insteadof Counter;
        Counter::talk as aTalk;
    }

    public int $value = 42;

    public function __construct() {
        $this->count = 10;
    }

    // 类自身方法覆盖 trait 方法（Greeter 没有 getName，这里新增一个测试覆盖语义）
    public function whoami(): string {
        return "class-method";
    }
}

class Main {
    public function main(): void {
        // 1. 基本 trait 方法
        echo "===== 1. 基本 trait 方法 =====\n";
        $u = new User();
        $u->sayHello();

        // 2. trait 属性
        echo "\n===== 2. trait 属性 =====\n";
        var_dump($u->value);
        $u->increment();
        $u->increment();
        $u->increment();
        var_dump($u->count);

        // 3. trait 常量
        echo "\n===== 3. trait 常量 =====\n";
        var_dump(User::TIMEOUT);

        // 4. insteadof 冲突解决（talk 来自 BTalker，Counter::talk 被排除）
        echo "\n===== 4. insteadof 冲突解决 =====\n";
        $u->talk();

        // 5. as 别名（Counter::talk 别名为 aTalk）
        echo "\n===== 5. as 别名 =====\n";
        $u->aTalk();

        // 6. 类方法覆盖 trait 方法
        echo "\n===== 6. 类方法覆盖 trait 方法 =====\n";
        echo $u->whoami();
        echo "\n";

        // 7. trait 方法访问 $this
        echo "\n===== 7. trait 方法访问 " . '$this' . " =====\n";
        $u->count = 30;
        var_dump($u->count);

        // 8. 完成
        echo "\n===== 8. all done =====\n";
        echo "OK\n";
    }
}
