<?php
// upcast_param.php — 子类实例作为父类参数传递时属性偏移正确性
//   Bug: 子类结构体中 _obj 和 _parent._obj 重复，导致 Child* cast 为 Parent*
//        时，parent->prop 读取到错误内存位置（_parent._obj 而非 _parent.prop）
//   修复: 子类不再重复声明 t_object _obj，_parent 作为第一个成员
#debug === Upcast Param ===
#debug
#debug 1. address=0.0.0.0:8080
#debug 2. processCount=4
#debug 3. childProp=child
#debug 4. grandchild=gc
#debug 5. modified=42
#debug
#debug === OK ===

class TcpServer
{
    public string $address;
    public int $processCount;

    public function __construct(string $address, int $processCount)
    {
        $this->address = $address;
        $this->processCount = $processCount;
    }
}

class HttpServer extends TcpServer
{
    public string $childProp;

    public function __construct(string $address, int $processCount)
    {
        parent::__construct($address, $processCount);
        $this->childProp = 'child';
    }
}

// 孙类：测试多层继承的指针 cast
class SslServer extends HttpServer
{
    public string $grandProp;

    public function __construct(string $address, int $processCount)
    {
        parent::__construct($address, $processCount);
        $this->grandProp = 'gc';
    }
}

class Worker
{
    public int $maxProcessCount;
    public string $registeredAddress;

    public function __construct()
    {
        $this->maxProcessCount = 1;
        $this->registeredAddress = '';
    }

    // 接收父类指针 — 子类实例隐式 upcast
    public function addServer(TcpServer $server): void
    {
        // 读取父类属性 — Bug 触发点：偏移错误时读到空/0
        if ($server->processCount > $this->maxProcessCount) {
            $this->maxProcessCount = $server->processCount;
        }
        $this->registeredAddress = $server->address;
    }

    // 接收父类指针，修改属性 — 验证写入偏移也正确
    public function modifyServer(TcpServer $server): void
    {
        $server->processCount = 42;
    }
}

class Main
{
    public function main(): void
    {
        echo "=== Upcast Param ===\n\n";

        // 1. 子类实例传给期望父类参数的函数
        $w = new Worker();
        $s = new HttpServer("0.0.0.0:8080", 4);
        $w->addServer($s);
        echo '1. address=' . $w->registeredAddress . "\n";       // 0.0.0.0:8080
        echo '2. processCount=' . $w->maxProcessCount . "\n";    // 4

        // 3. 子类自身属性不受影响
        echo '3. childProp=' . $s->childProp . "\n";             // child

        // 4. 孙类实例传给父类参数 — 多层继承
        $ssl = new SslServer("127.0.0.1:443", 8);
        $w->addServer($ssl);
        echo '4. grandchild=' . $ssl->grandProp . "\n";          // gc

        // 5. 通过 upcast 后的指针修改属性，验证写入偏移也正确
        $w->modifyServer($s);
        echo '5. modified=' . $s->processCount . "\n";           // 42

        echo "\n=== OK ===\n";
    }
}
