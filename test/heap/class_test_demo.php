<?php

namespace Demo;

class MyDemo
{
    public function greet(): void
    {
        $prefix = "Hi, ";
        $name = "TPHP";
        $msg = $prefix . $name;  // class method 中的堆拼接 → HeapFree on return
        var_dump($msg);
    }
}