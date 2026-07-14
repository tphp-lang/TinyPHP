<?php
#debug ===== 1. nested objects =====
#debug string(5) "apple"
#debug int(100)
#debug string(6) "banana"
#debug int(200)
#debug string(6) "cherry"
#debug apple
#debug string(5) "apple"
#debug int(200)
#debug int(600)
#debug
#debug ===== 2. foreach objects =====
#debug int(600)
#debug apple
#debug banana
#debug cherry
#debug
#debug ===== 3. callback array =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 4. callback compute =====
#debug int(60)
#debug
#debug ===== 5. foreach callbacks =====
#debug int(60)
#debug
#debug ===== 6. 2D objects =====
#debug string(5) "alpha"
#debug int(20)
#debug string(5) "gamma"
#debug int(40)
#debug
#debug ===== 7. object and array =====
#debug string(6) "widget"
#debug int(99)
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 8. is_* in nested context =====
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug
#debug ===== 9. closure with capture =====
#debug int(150)
#debug
#debug ===== 10. array ops + objects =====
#debug int(300)
#debug int(3)
#debug string(6) "cherry"
#debug
#debug === all nested obj/cb tests done ===

class Item
{
    public string $title;
    public int $price;

    public function __construct(string $title, int $price)
    {
        $this->title = $title;
        $this->price = $price;
    }
}

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 嵌套对象数组 (2D object array)
        // ============================================================
        echo "===== 1. nested objects =====\n";

        $i1 = new Item("apple",  100);
        $i2 = new Item("banana", 200);
        $i3 = new Item("cherry", 300);

        $catalog = [[$i1, $i2], [$i3]];
        var_dump($catalog[0][0]->title);  // "apple"
        var_dump($catalog[0][0]->price);  // 100
        var_dump($catalog[0][1]->title);  // "banana"
        var_dump($catalog[0][1]->price);  // 200
        var_dump($catalog[1][0]->title);  // "cherry"

        echo $catalog[0][0]->title . "\n";  // apple

        // 类型传播：$row = $catalog[0]
        $row = $catalog[0];
        var_dump($row[0]->title);  // "apple"
        var_dump($row[1]->price);  // 200

        // 链式访问 + 运算
        $ptotal = $catalog[0][0]->price + $catalog[0][1]->price + $catalog[1][0]->price;
        var_dump($ptotal);  // int(600)

        // ============================================================
        // 2. 对象数组 foreach 遍历
        // ============================================================
        echo "\n===== 2. foreach objects =====\n";

        $items = [$i1, $i2, $i3];
        $sum = 0;
        foreach ($items as $item) {
            $sum = $sum + $item->price;
        }
        var_dump($sum);  // int(600) = 100+200+300

        // foreach 取属性
        foreach ($items as $it) {
            echo $it->title . "\n";
        }

        // ============================================================
        // 3. 回调函数数组
        // ============================================================
        echo "\n===== 3. callback array =====\n";

        $f1 = function(): int {
            return 10;
        };
        $f2 = function(): int {
            return 20;
        };
        $f3 = function(): int {
            return 30;
        };

        $funcs = [$f1, $f2, $f3];

        $fn0 = $funcs[0];
        var_dump($fn0());  // int(10)
        $fn1 = $funcs[1];
        var_dump($fn1());  // int(20)
        $fn2 = $funcs[2];
        var_dump($fn2());  // int(30)

        // ============================================================
        // 4. 回调数组中闭包调用参与运算
        // ============================================================
        echo "\n===== 4. callback compute =====\n";

        $c1 = $funcs[0];
        $c2 = $funcs[1];
        $c3 = $funcs[2];
        $r1 = $c1();
        $r2 = $c2();
        $r3 = $c3();
        $ctotal = $r1 + $r2 + $r3;
        var_dump($ctotal);  // int(60)

        // ============================================================
        // 5. foreach 遍历回调数组
        // ============================================================
        echo "\n===== 5. foreach callbacks =====\n";

        $acc = 0;
        foreach ($funcs as $fn) {
            $acc = $acc + $fn();
        }
        var_dump($acc);  // int(60)

        // ============================================================
        // 6. 嵌套数组对象数组 (2D objects + type propagation)
        // ============================================================
        echo "\n===== 6. 2D objects =====\n";

        $a = new Item("alpha", 10);
        $b = new Item("beta",  20);
        $c = new Item("gamma", 30);
        $d = new Item("delta", 40);

        $groups = [[$a, $b], [$c, $d]];

        $g0 = $groups[0];
        var_dump($g0[0]->title);  // "alpha"
        var_dump($g0[1]->price);  // 20

        $g1 = $groups[1];
        var_dump($g1[0]->title);  // "gamma"
        var_dump($g1[1]->price);  // 40

        // ============================================================
        // 7. 对象 + 数组分离存储
        // ============================================================
        echo "\n===== 7. object and array =====\n";

        $x = new Item("widget", 99);
        $y = [10, 20, 30];

        var_dump($x->title);   // "widget"
        var_dump($x->price);   // 99

        var_dump($y[0]);       // 10
        var_dump($y[1]);       // 20
        var_dump($y[2]);       // 30

        // ============================================================
        // 8. is_* 类型检测在嵌套数组场景
        // ============================================================
        echo "\n===== 8. is_* in nested context =====\n";

        var_dump(is_object($catalog[0][0]));  // bool(true)
        var_dump(is_array($catalog[0]));      // bool(true)
        var_dump(is_int($catalog[0][0]->price)); // bool(true)
        var_dump(is_string($catalog[0][0]->title)); // bool(true)

        // ============================================================
        // 9. 回调带捕获变量（closure with use）
        // ============================================================
        echo "\n===== 9. closure with capture =====\n";

        $basePrice = 50;
        $calc = function(int $qty) use ($basePrice): int {
            return $basePrice * $qty;
        };
        var_dump($calc(3));  // int(150)

        // ============================================================
        // 10. 数组操作 + 对象方法调用组合
        // ============================================================
        echo "\n===== 10. array ops + objects =====\n";

        $bag = [$i1, $i2];
        $bagTotal = $bag[0]->price + $bag[1]->price;
        var_dump($bagTotal);  // int(300) = 100+200

        array_push($bag, $i3);
        var_dump(count($bag));  // int(3)

        // 检查 push 后访问正确
        var_dump($bag[2]->title);  // "cherry"

        echo "\n=== all nested obj/cb tests done ===\n";
    }
}
