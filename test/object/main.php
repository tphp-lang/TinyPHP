<?php

use Other\Other;
// use Other\IS_OTHER; // 不可调用 不同命名空间的常量,报错

const VERSION = "1.0.0"; // 全局常量,可全局调用

class Main
{
    public function main(): void
    {
        var_dump(VERSION);
        $demo = new Demo("world");
        var_dump($demo->a);
        var_dump($demo->b);
        var_dump($demo->getC());
        var_dump($demo->getGetD());
        echo "==============\n";
        $other = new Other();
        $other->hello()->world(); // 链式调用
        // var_dump(IS_OTHER); // 不可调用 不同命名空间的常量
    }
}

class Demo
{
    // 公共的对象变量
    public int $a = 10;
    public string $b;
    // 私有的对象变量->作用域只能对象内部 $this->x 调用
    private int $c = 10;
    private string $d;

    public function __construct(string $bb)
    {
        $this->b = $bb;
        $this->d = "world";
    }

    public function getC(): int
    {
        return $this->c;
    }

    public function setC(int $c): void
    {
        $this->c = $c;
    }

    // 私有对象函数，只能对象内部 $this->getD() 调用
    private function getD(): string
    {
        return $this->d;
    }

    public function getGetD(): string
    {
        return $this->getD() . " " . $this->b;
    }
}
