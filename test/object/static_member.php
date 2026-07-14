<?php
#debug 1. count=0
#debug 2. count=1
#debug 3. count=2
#debug 4. version=v1.0
#debug 5. pi=3.14
#debug 6. name=Counter
#debug 7. add(10,20)=30
#debug 8. name=Renamed
#debug 9. count=100
#debug 10. all done

class Counter {
    public static int $count = 0;
    public static string $name = "Counter";
    public static float $pi = 3.14;

    public static function inc(): void {
        self::$count = self::$count + 1;
    }

    public static function version(): string {
        return "v1.0";
    }

    public static function add(int $a, int $b): int {
        return $a + $b;
    }

    public function bump(): void {
        Counter::inc();
    }
}

class Main {
    public function main(): void {
        // 1. 静态属性默认值读取
        echo "1. count=" . Counter::$count . "\n";

        // 2. 静态方法调用 — 修改静态属性
        Counter::inc();
        echo "2. count=" . Counter::$count . "\n";

        // 3. 实例方法内通过 Class:: 调用静态方法
        $c = new Counter();
        $c->bump();
        echo "3. count=" . Counter::$count . "\n";

        // 4. 静态方法返回字符串
        echo "4. version=" . Counter::version() . "\n";

        // 5. 静态属性 float 读取
        echo "5. pi=" . Counter::$pi . "\n";

        // 6. 静态属性 string 读取
        echo "6. name=" . Counter::$name . "\n";

        // 7. 静态方法带参数
        echo "7. add(10,20)=" . Counter::add(10, 20) . "\n";

        // 8. 静态属性赋值
        Counter::$name = "Renamed";
        echo "8. name=" . Counter::$name . "\n";

        // 9. 静态属性赋值 int
        Counter::$count = 100;
        echo "9. count=" . Counter::$count . "\n";

        echo "10. all done\n";
    }
}
