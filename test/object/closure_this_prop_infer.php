<?php
// closure_this_prop_infer.php — 验证闭包内 $this->prop 的类型推断
//   Bug: 闭包内 $this->signalHandler->method() 报
//        "Call to undefined method t_int::method()"
//   根因: inferType 对闭包内 PropertyAccessExpr($this, prop) 的处理
//        没有正确使用闭包所属类的属性表
#debug === closure this prop infer ===
#debug
#debug [1] closure access $this->prop:
#debug called=1
#debug [2] closure nested call $this->a->b():
#debug nested=42
#debug
#debug === OK ===

class Helper
{
    public function doWork(): int
    {
        return 1;
    }
}

class Container
{
    public Helper $h;
    public int $val;

    public function __construct()
    {
        $this->val = 0;
    }

    public function runClosure(): void
    {
        // 闭包内访问 $this->h（类类型属性），然后调用其方法
        // Bug: inferType($this->h) 返回 t_int，导致 doWork() 调用报错
        $cb = function (): void {
            $this->val = $this->h->doWork();
        };
        $cb();
    }

    public function runNestedClosure(): void
    {
        // 闭包内链式调用 $this->h->doWork()
        $cb = function (): int {
            return $this->h->doWork() + 41;
        };
        $this->val = $cb();
    }
}

class Main
{
    public function main(): void
    {
        echo "=== closure this prop infer ===\n\n";

        echo '[1] closure access $this->prop:' . "\n";
        $c = new Container();
        $c->h = new Helper();
        $c->runClosure();
        echo 'called=' . $c->val . "\n";

        echo '[2] closure nested call $this->a->b():' . "\n";
        $c2 = new Container();
        $c2->h = new Helper();
        $c2->runNestedClosure();
        echo 'nested=' . $c2->val . "\n";

        echo "\n=== OK ===\n";
    }
}
