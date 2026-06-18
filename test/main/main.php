<?php // 开头必须是 <?php 否则报错

// 不接受任何游离代码，会在解析的时候报出错误

// 必须写入口 class Main
class Main
{
    // 构造函数
    public function __construct(int $argc, array $argv)
    {
        var_dump($argc);
        var_dump($argv);
    } // 和php一样可以不写，但是默认存在

    // 入口函数必须是 main, 且返回值必须是 void, 必须是php 强类型声明。
    public function main(): void
    {
        echo "hello world\n"; // 输出 hello world
        test();
        echo test2();
        $c = new Demo();
        $c->hello();
        // 当使用完后默认自动清理的

    }

    // 析构函数
    public function __destruct() {} // 和php一样可以不写，但是默认存在
}


// 其他函数
function test(): void // 必须是 php 强类型声明 ，否则报错
{
    echo "test\n";
    // 在函数作用域中，当用完后c会自动清理保证内存安全的
}

function test2(): int
{
    $a = 10;
    $b = 20;
    $c = $a + $b;

    // 在函数作用域中，当用完后c会自动清理保证内存安全的
    // free(a);
    // free(b);

    return $c;
}

function test3(string $str): string
{
    $a = "hello";

    $c = "$a $str\n";

    // 在函数作用域中，当用完后c会自动清理保证内存安全的
    // free(a);

    return $c;
}

// 其他类
class Demo
{
    public function __construct()
    {
        echo "new Demo\n";
    }

    public function hello(): void // 必须是 php 强类型声明
    {
        echo "hello\n";
        // 在函数作用域中，当用完后c会自动清理保证内存安全的
    }

    public function __destruct()
    {
        echo "delete Demo\n";
        // 在c中 当使用完后默认自动清理的
    }
}
