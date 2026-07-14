<?php
// P2-1: Generator 闭包测试 — yield 在闭包中
//   - 基本生成器闭包（无 use vars）
//   - 带捕获变量的生成器闭包
//   - foreach 迭代生成器闭包

#debug int(1)
#debug int(2)
#debug int(3)
#debug int(10)
#debug int(11)
#debug int(12)
#debug int(100)
#debug int(200)
#debug int(300)

class Main
{
    public function main(): void
    {
        // 基本生成器闭包（无捕获）
        $gen1 = function (): Generator {
            yield 1;
            yield 2;
            yield 3;
        };
        foreach ($gen1() as $v) {
            var_dump($v);
        }

        // 带捕获变量的生成器闭包
        $base = 10;
        $gen2 = function () use ($base): Generator {
            yield $base;
            yield $base + 1;
            yield $base + 2;
        };
        foreach ($gen2() as $v) {
            var_dump($v);
        }

        // 带参数和捕获的生成器闭包
        $factor = 100;
        $gen3 = function (int $n) use ($factor): Generator {
            $i = 0;
            while ($i < $n) {
                yield $factor * (1 + $i);
                $i++;
            }
        };
        foreach ($gen3(3) as $v) {
            var_dump($v);
        }
    }
}
