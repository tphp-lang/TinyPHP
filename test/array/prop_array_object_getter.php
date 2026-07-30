<?php
#debug === Property Array Object Getter Test ===
#debug
#debug === 1. Store and retrieve object from untyped array property ===
#debug conn[0]: conn0 fd=100
#debug conn[1]: conn1 fd=200
#debug
#debug === 2. Overwrite and retrieve ===
#debug overwritten: conn2 fd=300
#debug
#debug === 3. Retrieve in closure ===
#debug closure: conn0 fd=100
#debug
#debug === 4. Pass to function ===
#debug fn: conn1 fd=200
#debug
#debug === 5. Multiple properties ===
#debug multi: conn0 fd=100
#debug
#debug === All tests passed ===

class Conn
{
    public int $fd = 0;
    public string $name = "";

    public function __construct(string $name, int $fd)
    {
        $this->name = $name;
        $this->fd   = $fd;
    }
}

class Holder
{
    // untyped array property — CodeGenerator must use object getter, not int getter
    public array $conns = [];
    public array $extra = [];

    public function add(int $key, Conn $conn): void
    {
        $this->conns[$key] = $conn;
    }

    public function get(int $key): Conn
    {
        return $this->conns[$key];
    }

    public function useInClosure(int $key): void
    {
        $cb = function(int $k) {
            $c = $this->conns[$k];
            echo "closure: " . $c->name . " fd=" . $c->fd . "\n";
        };
        $cb->__invoke($key);
    }

    public function passToFn(int $key): void
    {
        $this->printConn($this->conns[$key]);
    }

    public function printConn(Conn $c): void
    {
        echo "fn: " . $c->name . " fd=" . $c->fd . "\n";
    }

    // 先通过赋值注册 extra 属性的元素类型（tphp_class_Conn*），
    //   后续 printExtra 才能用 object getter 正确提取
    public function addExtra(int $key, Conn $conn): void
    {
        $this->extra[$key] = $conn;
    }

    public function printExtra(int $key): void
    {
        // 直接访问未类型化 array 属性的元素
        //   验证 CodeGenerator 在 Holder 类内部能正确选择 object getter
        $c = $this->extra[$key];
        echo "multi: " . $c->name . " fd=" . $c->fd . "\n";
    }
}

class Main
{
    public function main(): void
    {
        echo "=== Property Array Object Getter Test ===\n\n";

        // === 1. Store and retrieve ===
        echo "=== 1. Store and retrieve object from untyped array property ===\n";
        $h = new Holder();
        $h->add(0, new Conn("conn0", 100));
        $h->add(1, new Conn("conn1", 200));

        $c0 = $h->get(0);
        echo "conn[0]: " . $c0->name . " fd=" . $c0->fd . "\n";

        $c1 = $h->get(1);
        echo "conn[1]: " . $c1->name . " fd=" . $c1->fd . "\n";

        // === 2. Overwrite ===
        echo "\n=== 2. Overwrite and retrieve ===\n";
        $h->add(0, new Conn("conn2", 300));
        $c = $h->get(0);
        echo "overwritten: " . $c->name . " fd=" . $c->fd . "\n";

        // === 3. Retrieve in closure ===
        echo "\n=== 3. Retrieve in closure ===\n";
        // Re-add conn0 for closure test
        $h->add(0, new Conn("conn0", 100));
        $h->useInClosure(0);

        // === 4. Pass to function ===
        echo "\n=== 4. Pass to function ===\n";
        $h->passToFn(1);

        // === 5. Multiple properties ===
        echo "\n=== 5. Multiple properties ===\n";
        $h->addExtra(0, new Conn("conn0", 100));
        $h->printExtra(0);

        echo "\n=== All tests passed ===\n";
    }
}
