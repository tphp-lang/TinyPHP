<?php
// 匿名类测试（PHP 8.3 语法，AOT 编译期转译）
// new class { ... } 编译期转译为合成类 _AnonClass${N} + new _AnonClass${N}()
// 借鉴 vlang _VAnonStruct${counter} 命名约定

#debug ===== 1. 简单匿名类 =====
#debug hello from anon
#debug
#debug ===== 2. 匿名类带属性 =====
#debug int(42)
#debug int(84)
#debug
#debug ===== 3. 匿名类带构造参数 =====
#debug int(99)
#debug
#debug ===== 4. 匿名类继承父类 =====
#debug base-hello
#debug derived-hello
#debug int(10)
#debug
#debug ===== 5. 多个匿名类实例 =====
#debug int(1)
#debug int(2)
#debug
#debug ===== 6. all done =====
#debug OK

class Base
{
    public int $baseVal = 10;

    public function baseHello(): void
    {
        echo "base-hello\n";
    }
}

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 简单匿名类
        // ============================================================
        echo "===== 1. 简单匿名类 =====\n";
        $obj = new class {
            public function greet(): void {
                echo "hello from anon\n";
            }
        };
        $obj->greet();

        // ============================================================
        // 2. 匿名类带属性
        // ============================================================
        echo "\n===== 2. 匿名类带属性 =====\n";
        $obj2 = new class {
            public int $x = 42;

            public function double(): int {
                return $this->x * 2;
            }
        };
        var_dump($obj2->x);
        var_dump($obj2->double());

        // ============================================================
        // 3. 匿名类带构造参数
        // ============================================================
        echo "\n===== 3. 匿名类带构造参数 =====\n";
        $obj3 = new class(99) {
            public int $v;

            public function __construct(int $v) {
                $this->v = $v;
            }
        };
        var_dump($obj3->v);

        // ============================================================
        // 4. 匿名类继承父类
        // ============================================================
        echo "\n===== 4. 匿名类继承父类 =====\n";
        $obj4 = new class extends Base {
            public function derivedHello(): void {
                echo "derived-hello\n";
            }
        };
        $obj4->baseHello();
        $obj4->derivedHello();
        var_dump($obj4->baseVal);

        // ============================================================
        // 5. 多个匿名类实例
        // ============================================================
        echo "\n===== 5. 多个匿名类实例 =====\n";
        $a = new class { public int $id = 1; };
        $b = new class { public int $id = 2; };
        var_dump($a->id);
        var_dump($b->id);

        // ============================================================
        // 6. 完成
        // ============================================================
        echo "\n===== 6. all done =====\n";
        echo "OK\n";
    }
}
