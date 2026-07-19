<?php
// 验证 $obj->prop[] = value 与 $this->prop[] = value 语法
// 复现 workerman Worker::addServer 中 $this->servers[] = $server 的写法
// 覆盖：外部 push、方法内 push、混合类型 push、字符串键属性、连续 push 后计数

#debug === Array Push on Property ===
#debug
#debug 1. push-extern-count: cnt=3
#debug 2. push-inside-method-count: cnt=2
#debug 3. push-mixed-count: cnt=3
#debug 4. push-int-then-read: v1=20 v2=30
#debug 5. push-to-str-keyed-prop: cnt=2
#debug
#debug === OK ===

class Server {
    public string $address;
    public int $processCount;

    public function __construct(string $address, int $processCount) {
        $this->address = $address;
        $this->processCount = $processCount;
    }
}

class Worker {
    // 服务器列表: array<int, Server>
    public array $servers = [];

    // 用户列表（字符串元素）
    public array $users = [];

    // 混合类型元素
    public array $mixed = [];

    // 字符串键数组
    public array $keyed = [];

    // 整数元素数组（用于读回验证）
    public array $nums = [];

    // 添加服务器 — 复现 workerman 写法 $this->servers[] = $server
    public function addServer(Server $server): void {
        $this->servers[] = $server;
    }

    public function addUser(string $name): void {
        $this->users[] = $name;
    }
}

class Main {
    public function main(): void {
        echo "=== Array Push on Property ===\n\n";

        // 1. 外部 $obj->prop[] = value（push 对象元素）
        $w = new Worker();
        $w->servers[] = new Server("127.0.0.1:8000", 1);
        $w->servers[] = new Server("127.0.0.1:8001", 2);
        $w->servers[] = new Server("127.0.0.1:8002", 4);
        $cnt = count($w->servers);
        echo "1. push-extern-count: cnt={$cnt}\n";

        // 2. 方法内部 $this->prop[] = value（workerman 模式）
        $w2 = new Worker();
        $w2->addUser("Alice");
        $w2->addUser("Bob");
        $cnt2 = count($w2->users);
        echo "2. push-inside-method-count: cnt={$cnt2}\n";

        // 3. 推入混合类型
        $w3 = new Worker();
        $w3->mixed[] = 42;
        $w3->mixed[] = "hello";
        $w3->mixed[] = [1, 2, 3];
        $cnt3 = count($w3->mixed);
        echo "3. push-mixed-count: cnt={$cnt3}\n";

        // 4. 连续 push 整数后按下标读
        $w4 = new Worker();
        $w4->nums[] = 10;
        $w4->nums[] = 20;
        $w4->nums[] = 30;
        $v1 = $w4->nums[1];
        $v2 = $w4->nums[2];
        echo "4. push-int-then-read: v1={$v1} v2={$v2}\n";

        // 5. 字符串键属性数组（push 后再赋字符串键，验证 count）
        $w5 = new Worker();
        $w5->keyed[] = "default";
        $w5->keyed["name"] = "Alice";
        $cnt5 = count($w5->keyed);
        echo "5. push-to-str-keyed-prop: cnt={$cnt5}\n";

        echo "\n=== OK ===\n";
    }
}
