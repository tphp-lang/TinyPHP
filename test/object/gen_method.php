<?php
// P2-1: Generator 方法测试 — yield 在类方法中
//   - 基本生成器方法
//   - 访问 $this->property
//   - 带参数的生成器方法
//   - foreach 迭代生成器方法

#debug int(1)
#debug int(2)
#debug int(3)
#debug int(10)
#debug int(20)
#debug int(30)
#debug int(0)
#debug int(1)
#debug int(2)

class Counter
{
    private int $base;

    public function __construct(int $base)
    {
        $this->base = $base;
    }

    public function gen(): Generator
    {
        yield 1;
        yield 2;
        yield 3;
    }

    public function genFromBase(): Generator
    {
        yield $this->base;
        yield $this->base + 10;
        yield $this->base + 20;
    }

    public function genRange(int $start, int $end): Generator
    {
        $i = $start;
        while ($i < $end) {
            yield $i;
            $i++;
        }
    }
}

class Main
{
    public function main(): void
    {
        $c = new Counter(10);

        // 基本生成器方法
        foreach ($c->gen() as $v) {
            var_dump($v);
        }

        // 访问 $this->base
        foreach ($c->genFromBase() as $v) {
            var_dump($v);
        }

        // 带参数的生成器方法
        foreach ($c->genRange(0, 3) as $v) {
            var_dump($v);
        }
    }
}
