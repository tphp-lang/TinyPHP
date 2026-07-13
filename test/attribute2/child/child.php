<?php

namespace Child;

// ROUTE 是全局常量，无需 use const 导入（PHP 全局常量回退规则）
// GET_NAME 在本命名空间 Child 中声明，直接使用短名

#[Attribute(name: string)]
const GET_NAME = [];

#[ROUTE("/MyChild")]
class MyChild
{
    #[GET_NAME("isHello")]
    public function hello(): void
    {
        echo "Hello, MyChild!\n";
    }
}
