<?php

namespace Demo; // 一个php只能定义一次命名空间，而且必须定义

function myDemo(): void
{
    var_dump("hello function myDemo");
}

// 这里定义class，和PHP原生一样, 但是要保持强类型写法。类似c++
// __construct()和__destruct()和PHP一样开发的时候不一定要写
class MyDemo
{
    /**
     * 构造函数, 和PHP原生一样，new使用时必须运行的函数。不用写 : void的
     */
    public function __construct() // 这里必须是public的，否则会报错
    {
        var_dump("hello class MyDemo");
    }

    /**
     * 公共函数和PHP一样，但是还要保持强类型写法
     *
     * @return void
     */
    public function hello(): void
    {
        $this->hello2(); // 调用私有函数
        var_dump("hello class MyDemo hello");
    }

    /**
     * 私有函数和PHP一样，但是还要保持强类型写法。
     * 只能class内部调用
     *
     * @return void
     */
    private function hello2(): void
    {
        var_dump("hello class MyDemo hello2");
    }

    /**
     * 析构函数, 和PHP原生一样，当使用完后自动运行。不用写 : void的
     */
    public function __destruct() // 这里必须是public的，否则会报错
    {
        var_dump("hello class MyDemo __destruct");
    }
}
