<?php // @skip — companion file, no class Main


namespace Demo; // 命名空间,一个只能一个否则报错

class Demo
{

    public function hello(): void
    {
        echo "Hello, Class Demo!\n";
    }
}

function myDemoFn(): void
{
    echo "Hello, Function Demo!\n";
}
