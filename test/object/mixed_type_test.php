<?php
// mixed_type_test.php — Task 2.13 mixed 类型支持测试
//   覆盖场景：
//     - mixed 返回类型（不同分支返回不同类型）
//     - mixed 变量重赋值（t_var 容器动态切换类型）
//     - mixed 参数（接受任意类型实参）
//     - 闭包中的 mixed 返回
//     - mixed 动态语义（变量类型在运行时改变）
#debug ===== Mixed Variable Re-assignment =====
#debug int(42)
#debug string(5) "hello"
#debug float(3.14)
#debug bool(true)
#debug
#debug ===== Mixed Parameters =====
#debug int(100)
#debug string(5) "world"
#debug bool(true)
#debug
#debug ===== Mixed Return Type =====
#debug int(42)
#debug string(4) "text"
#debug bool(true)
#debug
#debug ===== Mixed in Closures =====
#debug int(123)
#debug string(10) "from mixed"
#debug
#debug ===== Mixed Dynamic Semantics =====
#debug int(42)
#debug string(3) "abc"
#debug int(20)
#debug
#debug all mixed type tests passed

class Main
{
    public function main(): void
    {
        echo "===== Mixed Variable Re-assignment =====\n";
        $this->testReassign();

        echo "===== Mixed Parameters =====\n";
        $this->testMixedParam();

        echo "===== Mixed Return Type =====\n";
        $this->testMixedReturn();

        echo "===== Mixed in Closures =====\n";
        $this->testClosures();

        echo "===== Mixed Dynamic Semantics =====\n";
        $this->testDynamic();

        echo "all mixed type tests passed\n";
    }

    // 辅助方法：返回 mixed 类型（t_var 容器）
    private function getMixed(): mixed
    {
        return 42;
    }

    // 1. mixed 变量重赋值：从 mixed 返回值获得 t_var，再重赋值为不同类型
    //    Task 2.13: t_var 变量重赋值时通过 wrapTvarAssign 包装为 VAR_XXX 宏
    private function testReassign(): void
    {
        $x = $this->getMixed();   // x 推导为 t_var（mixed 返回类型）
        var_dump($x);             // int(42)
        $x = "hello";             // 重赋值为 string → VAR_STRING
        var_dump($x);             // string(5) "hello"
        $x = 3.14;                // 重赋值为 float → VAR_FLOAT
        var_dump($x);             // float(3.14)
        $x = true;                // 重赋值为 bool → VAR_BOOL
        var_dump($x);             // bool(true)
        echo "\n";
    }

    // 2. mixed 参数：接受任意类型实参
    //    Task 2.13: t_var 参数通过 wrapTvarAssign 自动包装实参
    public function acceptMixed(mixed $val): void
    {
        var_dump($val);
    }

    private function testMixedParam(): void
    {
        $this->acceptMixed(100);
        $this->acceptMixed("world");
        $this->acceptMixed(true);
        echo "\n";
    }

    // 3. mixed 返回类型：不同分支返回不同类型
    //    Task 2.13: currentRetType === 't_var' 时 return 值通过 wrapTvarAssign 包装
    private function getValue(int $mode): mixed
    {
        if ($mode == 1) {
            return 42;
        } elseif ($mode == 2) {
            return "text";
        } else {
            return true;
        }
    }

    private function testMixedReturn(): void
    {
        var_dump($this->getValue(1));   // int(42)
        var_dump($this->getValue(2));   // string(4) "text"
        var_dump($this->getValue(3));   // bool(true)
        echo "\n";
    }

    // 4. 闭包中的 mixed 返回
    private function testClosures(): void
    {
        $mkInt = function(): mixed {
            return 123;
        };
        var_dump($mkInt());             // int(123)

        $mkStr = function(): mixed {
            return "from mixed";
        };
        var_dump($mkStr());             // string(10) "from mixed"
        echo "\n";
    }

    // 5. mixed 动态语义：变量类型在运行时通过重赋值改变
    //    Task 2.13: TypeChecker 的 declareVar 覆盖类型，CodeGenerator 的 isTVar 分支包装值
    private function testDynamic(): void
    {
        $d = $this->getValue(1);    // t_var, 持有 int
        var_dump($d);               // int(42)
        $d = "abc";                 // 切换为 string
        var_dump($d);               // string(3) "abc"
        $d = 20;                    // 切换回 int
        var_dump($d);               // int(20)
        echo "\n";
    }
}
