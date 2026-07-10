<?php
#debug string(5) "HELLO"
#debug string(5) "WORLD"
#debug string(3) "abc"
#debug int(50)
#debug int(100)
#debug int(84)
#debug string(5) "HELLO"
#debug int(200)
#debug int(1)

// Property Hook 测试 — PHP 8.4 语法
class User {
    // 带 get + set hook 的字符串属性（短形式）
    public string $name {
        get => strtoupper($this->name);
        set => strtolower($value);
    }

    // 只有 get hook 的属性（虚拟计算属性）
    public int $doubled {
        get => $this->value * 2;
    }

    // 带 block 形式 hook 的属性
    public string $email {
        get {
            return strtolower($this->email);
        }
        set {
            $this->email = $value;
        }
    }

    // 普通属性（无 hook）
    public int $value = 42;

    public function __construct(string $name, string $email) {
        $this->name = $name;
        $this->email = $email;
        $this->value = 50;
    }
}

class Product {
    // 带 hook 的属性 — set 验证值范围
    public int $price {
        get => $this->price;
        set => $value > 0 ? $value : 1;
    }

    public function __construct(int $price) {
        $this->price = $price;
    }
}

class Main {
    public function main(): void {
        $u = new User("hello", "WORLD@example.com");

        // get hook: backing="hello" → strtoupper → "HELLO"
        var_dump($u->name);

        // set hook: "WORLD" → strtolower → backing="world"
        // get hook: backing="world" → strtoupper → "WORLD"
        $u->name = "WORLD";
        var_dump($u->name);

        // set hook (block): "ABC" → direct write → backing="ABC"
        // get hook (block): backing="ABC" → strtolower → "abc"
        $u->email = "ABC";
        var_dump($u->email);

        // 普通属性访问（无 hook）
        var_dump($u->value);

        // 虚拟属性: get hook only → $this->value * 2 = 50 * 2 = 100
        var_dump($u->doubled);

        // 修改普通属性后虚拟属性变化
        $u->value = 42;
        var_dump($u->doubled);

        // 再次测试 name hook: "HeLLo" → strtolower → "hello" → strtoupper → "HELLO"
        $u->name = "HeLLo";
        var_dump($u->name);

        // Product: price hook — set 200 > 0 → 200
        $p = new Product(200);
        var_dump($p->price);

        // Product: set -50 → hook returns 1
        $p->price = -50;
        var_dump($p->price);
    }
}
