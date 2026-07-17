<?php

#include __EXT__ . "demo/demo.h"

#flag __EXT__ . "demo/src/demo.c"

// 展示了当c函数不是tphp的内部封装前缀函数时的用法，
// 这样的话就可以把c函数再封装一层php对象给到用户使用

function php_demo_hello(): void
{
    C->demo_hello();
}

class DemoA
{
    public int $c;
    public function __construct(int $a, int $b)
    {
        $this->c = C->create_class_a(c_int($a), c_int($b));
    }

    public function add(int $d): int
    {
        int $c = C->class_a_add($this->c, c_int($d));
        return php_int($c);
    }
}
