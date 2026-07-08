<?php // @multi @with demo.php,other/demo.php,all/aa.php,other/other.php
#debug Hello, main!
#debug Hello, Class Demo!
#debug Hello, Function Demo!
#debug Hello, Function Demo2!
#debug Hello, Class Myaa!
#debug Hello, Function!

/* use Demo\Demo; // 导入命名空间的 Demo 类
use function Demo\myDemoFn; // 导入命名空间的 myDemoFn 函数
use function Demo\myDemoFn2; // 同理 */

use Demo\{
    Demo,
    function myDemoFn,
    function myDemoFn2,
}; // 组合语法

use Myaa\Myaa\Myaa; // 导入命名空间的 Myaa 类

class Main
{
    public function main(): void
    {
        echo "Hello, main!\n";
        $this->test(); // 调用内部函数

        // 全局调用
        allfn();
    }

    public function test(): void
    {
        $d = new Demo();
        $d->hello();
        myDemoFn();
        myDemoFn2();
        $m = new Myaa();
        $m->hello();
    }
}
