<?php
// closure_this_generator.php — 验证生成器闭包内使用 $this 时正确捕获 self 指针
//   Bug: 生成器闭包内 $this->prop 编译为 self->prop，但协程入口函数中 self 未定义
//   修复: 生成器闭包也把 self 作为隐式捕获变量
#debug === closure this generator ===
#debug
#debug [1] generator writes via $this:
#debug val=99
#debug [2] generator reads via $this:
#debug read=42
#debug
#debug === OK ===

class Counter
{
    public int $val;

    public function __construct()
    {
        $this->val = 0;
    }

    public function genWriter(): Generator
    {
        // 生成器闭包内使用 $this 写入属性
        $g = function (): Generator {
            $this->val = 99;
            yield 1;
        };
        return $g();
    }

    public function genReader(): Generator
    {
        // 生成器闭包内使用 $this 读取属性
        $this->val = 42;
        $g = function (): Generator {
            $v = $this->val;
            yield $v;
        };
        return $g();
    }
}

class Main
{
    public function main(): void
    {
        echo "=== closure this generator ===\n\n";

        echo '[1] generator writes via $this:' . "\n";
        $c1 = new Counter();
        $gen1 = $c1->genWriter();
        $gen1->current(); // 触发执行到第一个 yield（此时 $this->val 已被设置）
        echo 'val=' . $c1->val . "\n";

        echo '[2] generator reads via $this:' . "\n";
        $c2 = new Counter();
        $gen2 = $c2->genReader();
        $read = intval($gen2->current());
        echo 'read=' . $read . "\n";

        echo "\n=== OK ===\n";
    }
}
