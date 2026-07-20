<?php
// inherited_prop_chain.php — 子类方法内链式调用继承属性的方法
//   复现 inferType 中 PropertyAccessExpr 缺少父类链查找的 bug
//   修复前: HttpServer 中 $this->pool->remove() → inferType($this->pool)
//             只查当前类 HttpServer，找不到 pool 属性 → 返回 t_int
//             → 后续方法调用报 Call to undefined method t_int::remove()
//   修复后: inferType 沿父类链查找，找到 TcpServer::$pool 的类型 Pool*
#debug === Inherited Prop Chain ===
#debug
#debug 1. size=0
#debug 2. add=a
#debug 3. add=b
#debug 4. size=2
#debug 5. remove=a
#debug 6. size=1
#debug 7. childOwn=child
#debug 8. parentProp=parent
#debug 9. grandparent=gp
#debug
#debug === OK ===

// 模拟 workerman 中 TcpServer 持有 Pool 的场景
class Pool
{
    public int $count;

    public function __construct()
    {
        $this->count = 0;
    }

    public function add(string $x): string
    {
        $this->count = $this->count + 1;
        return $x;
    }

    public function remove(string $x): string
    {
        if ($this->count > 0) {
            $this->count = $this->count - 1;
        }
        return $x;
    }

    public function size(): int
    {
        return $this->count;
    }
}

// 祖父类：测试多层继承
class Grandparent
{
    public string $gpVal;

    public function __construct(string $v)
    {
        $this->gpVal = $v;
    }
}

// 父类：定义 pool 属性，子类将继承
class TcpServer extends Grandparent
{
    public Pool $pool;
    public string $parentVal;

    public function __construct()
    {
        parent::__construct('gp');
        $this->pool = new Pool();
        $this->parentVal = 'parent';
    }
}

// 子类：在自身方法中访问继承的 $this->pool 属性并链式调用其方法
class HttpServer extends TcpServer
{
    public string $childVal;

    public function __construct()
    {
        parent::__construct();
        $this->childVal = 'child';
    }

    public function handle(string $data): string
    {
        // Bug 触发点：$this->pool 是父类 TcpServer 的属性
        //   修复前: inferType($this->pool) 只查 HttpServer，返回 t_int
        //           → t_int::add() 报 undefined method
        //   修复后: 沿父类链找到 TcpServer::$pool: Pool* → 正确调用
        return $this->pool->add($data);
    }

    public function drop(string $data): string
    {
        return $this->pool->remove($data);
    }

    public function count(): int
    {
        return $this->pool->size();
    }

    public function childOwn(): string
    {
        return $this->childVal;
    }

    // 访问祖父类属性 — 测试多层继承链
    public function grandparentVal(): string
    {
        return $this->gpVal;
    }
}

class Main
{
    public function main(): void
    {
        echo "=== Inherited Prop Chain ===\n\n";

        $s = new HttpServer();

        // 1. 初始 size=0
        echo '1. size=' . $s->count() . "\n";          // 0

        // 2-3. 链式调用继承属性的方法 — 直接触发 inferType 父类链查找
        $a = $s->handle('a');
        echo '2. add=' . $a . "\n";                    // a
        $b = $s->handle('b');
        echo '3. add=' . $b . "\n";                    // b

        // 4. size=2
        echo '4. size=' . $s->count() . "\n";          // 2

        // 5. remove=a
        $rm = $s->drop('a');
        echo '5. remove=' . $rm . "\n";                // a

        // 6. size=1
        echo '6. size=' . $s->count() . "\n";          // 1

        // 7. 子类自身属性
        echo '7. childOwn=' . $s->childOwn() . "\n";   // child

        // 8. 父类属性直接访问
        echo '8. parentProp=' . $s->parentVal . "\n";  // parent

        // 9. 祖父类属性访问 — 多层继承
        echo '9. grandparent=' . $s->grandparentVal() . "\n"; // gp

        echo "\n=== OK ===\n";
    }
}
