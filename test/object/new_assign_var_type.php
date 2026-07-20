<?php
// new_assign_var_type.php — 验证 $x = new ClassName() 后 varTypes 存储的类型带 *
//   Bug: L2523 存 $cn（无 *），导致 inferType($x) 返回无 * 类型，
//        generateClosureCall 在 callee 是 PropertyAccessExpr 时（如 $this->cb）
//        无法查闭包签名，只能用 inferType 推导实参类型 → cast 缺 *
//        C 编译错误：cannot convert 'tphp_class_X*' to 'tphp_class_X'
//   修复: L2523 改为 $cn . '*'，与 mapType/paramCTypeResolved 等其他入口一致
#debug === new assign var type ===
#debug
#debug [1] closure invoked with new-assign obj:
#debug int(42)
#debug [2] direct call with new-assign obj:
#debug int(100)
#debug [3] new-assign obj passed to typed param:
#debug int(200)
#debug [4] callable property invoked with new-assign obj:
#debug int(300)
#debug
#debug === OK ===

class Worker
{
    public int $id;
    public function __construct(int $id)
    {
        $this->id = $id;
    }
    public function getId(): int
    {
        return $this->id;
    }
}

class Main
{
    // callable 属性：调用时走 generateClosureCall 的 PropertyAccessExpr 分支
    //   修复前: inferType($obj) 返回 tphp_class_Worker（无 *）
    //           cast 生成 (t_int(*)(tphp_class_Worker, void*)) → C 编译错误
    //   修复后: inferType($obj) 返回 tphp_class_Worker* → cast 正确
    public callable $cb;

    public function main(): void
    {
        echo "=== new assign var type ===\n\n";

        // ── Test 1: $obj = new Worker(...) 然后传给闭包变量 ──
        //   闭包变量调用走 VariableExpr 分支，查闭包签名，不依赖 varTypes
        echo "[1] closure invoked with new-assign obj:\n";
        $obj = new Worker(42);
        $cb = function (Worker $w): int {
            return $w->getId();
        };
        var_dump($cb($obj));      // expected: int(42)

        // ── Test 2: new-assign obj 直接调用方法（非闭包路径，应一直工作）──
        echo "[2] direct call with new-assign obj:\n";
        $obj2 = new Worker(100);
        var_dump($obj2->getId()); // expected: int(100)

        // ── Test 3: new-assign obj 传给有类型注解的方法参数 ──
        echo "[3] new-assign obj passed to typed param:\n";
        $obj3 = new Worker(200);
        var_dump($this->processWorker($obj3)); // expected: int(200)

        // ── Test 4: callable 属性 + new-assign obj（触发 bug 的关键路径）──
        //   $this->cb 是 PropertyAccessExpr → generateClosureCall 不查闭包签名
        //   只能用 inferType($obj4) 推导 → 修复前返回无 * 类型 → cast 错误
        echo "[4] callable property invoked with new-assign obj:\n";
        $this->cb = function (Worker $w): int {
            return $w->getId() + 1;
        };
        $obj4 = new Worker(299);
        var_dump($this->cb->__invoke($obj4)); // expected: int(300)

        echo "\n=== OK ===\n";
    }

    public function processWorker(Worker $w): int
    {
        return $w->getId();
    }
}
