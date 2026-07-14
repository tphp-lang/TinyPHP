<?php // @exit 1 — tests error() which triggers exit(1)
#debug === test 1: 简单 error ===
#debug 这条应该看不到
#debug 这条也不应该看到
#debug
#debug === test 2: error 时对象清理 ===
#debug Demo('obj2') destroyed
#debug Demo('obj2') destroyed
#debug Demo('obj1') destroyed
#debug Demo('obj1') destroyed
#debug
#debug ~ Fatal error: 对象 + 数组合并清理
#debug ~   in C:/project/php/TinyPHP/test/error/error_test.php on line 70
#debug

class Demo
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function __destruct()
    {
        echo "Demo('" . $this->name . "') destroyed\n";
        echo "Demo('{$this->name}') destroyed\n"; // 插值和上面是等效的，这个bug修复一下
    }
}

class Main
{
    public function main(): void
    {
        // === 基础测试 ===
        echo "=== test 1: 简单 error ===\n";
        $this->testSimple();

        // === 资源清理：对象 ===
        echo "\n=== test 2: error 时对象清理 ===\n";
        $this->testObjectCleanup();

        // === 资源清理：数组 ===
        echo "\n=== test 3: error 时数组清理 ===\n";
        $this->testArrayCleanup();

        // === 条件 error ===
        echo "\n=== test 4: 条件触发 error ===\n";
        $this->testConditional();

        // === 嵌套 error（多层 if） ===
        echo "\n=== test 5: 多层 if 中 error ===\n";
        $this->testNested();

        echo "\n=== done ===\n";
    }

    private function testSimple(): void
    {
        echo "这条应该看不到\n";
        echo "这条也不应该看到\n";
    }

    private function testObjectCleanup(): void|Exception
    {
        $d1 = new Demo("obj1");
        $d2 = new Demo("obj2");
        $arr = [1, 2, 3];

        error("对象 + 数组合并清理");
    }

    private function testArrayCleanup(): void|Exception
    {
        $nested = ["outer" => ["inner" => [1, 2, 3]]];
        $obj = new Demo("array_test");
        $s = "hello";

        error("嵌套数组合并清理");
    }

    private function testConditional(): void|Exception
    {
        $val = 10;
        if ($val > 5) {
            $obj = new Demo("conditional");
            error("条件触发: val={$val}");
        }
    }

    private function testNested(): void|Exception
    {
        $x = 42;
        if ($x > 0) {
            $obj = new Demo("nested_obj");
            if ($x > 10) {
                $arr = ["nested" => "array"];
                error("多层嵌套中触发");
            }
        }
    }
}
