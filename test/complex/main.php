<?php // @multi @with enums.php

use Complex\Enums\Status;
use Complex\Enums\Role;

const APP_NAME = "TinyPHP Complex Test";
const MAX_RETRY = 3;
const PI = 3.14159;

enum Color: string
{
    case RED = "red";
    case YELLOW = "yellow";
    case GREEN = "green";
}

enum Num: int
{
    case ONE = 1;
    case TWO = 2;
    case THREE = 3;
}

class Main
{
    public function main(): void
    {
        echo "========================================\n";
        echo APP_NAME;
        echo "\n";
        echo "========================================\n";

        // 1. 数学计算 + 条件分支 + 循环
        echo "===== Math & Logic =====\n";
        $this->testMath();

        // 2. 字符串处理 + 插值 + 拼接
        echo "===== String Ops =====\n";
        $this->testStrings();

        // 3. 对象系统 + 链式调用 + 析构
        echo "===== Objects =====\n";
        $this->testObjects();

        // 4. 数组操作 + foreach + 嵌套
        echo "===== Arrays =====\n";
        $this->testArrays();

        // 5. 闭包 + 类型转换
        echo "===== Closures & Casts =====\n";
        $this->testClosures();

        // 6. 控制流综合
        echo "===== Control Flow =====\n";
        $this->testControlFlow();

        // 7. switch/case/default
        echo "===== Switch =====\n";
        $this->testSwitch();

        // 7.4. 闭包 use 捕获变量
        echo "===== Closure Use =====\n";
        $this->testClosureUse();

        // 7.5. 新运算符 & 控制流 (%, ?:, ??, do-while, &|^~<<>>, die, isset, empty, list)
        echo "===== New Ops =====\n";
        $this->testHighPriority();

        // 8. 枚举
        echo "===== Enum =====\n";
        $this->testEnum();

        // 9. 内存安全：大量对象创建与自动释放
        echo "===== Memory Stress =====\n";
        $this->testMemoryStress();

        // 10. exit 测试（在最后，正常退出不会影响上面测试）
        echo "===== Exit Test =====\n";
        $this->testExit();

        echo "========================================\n";
        echo "All tests completed!\n";
    }

    private function testMath(): void
    {
        // 基本运算
        $a = 100;
        $b = 7;
        $sum = $a + $b;
        $diff = $a - $b;
        $prod = $a * $b;
        $quot = $a / $b;
        $mod = $a - ($a / $b) * $b;

        var_dump($sum);
        var_dump($diff);
        var_dump($prod);
        var_dump($quot);

        // 浮点运算
        $x = 3.14;
        $y = 2.0;
        $area = $x * $y * $y;
        var_dump($area);

        // 负数 + 运算符优先级
        $v1 = -10 + 5 * 3;
        $v2 = (-10 + 5) * 3;
        $v3 = 100 - 20 / 4;
        var_dump($v1);
        var_dump($v2);
        var_dump($v3);

        // 复合赋值
        $counter = 0;
        $counter += 10;
        $counter -= 3;
        $counter *= 2;
        $counter /= 7;
        var_dump($counter);

        // 自增自减
        $idx = 5;
        $idx++;
        ++$idx;
        var_dump($idx);
        $idx--;
        --$idx;
        var_dump($idx);

        // 比较运算
        $c1 = $a > $b;
        $c2 = $a == 100;
        $c3 = $b != 10;
        $c4 = $a <= 100;
        $c5 = $b >= 5;
        var_dump($c1);
        var_dump($c2);
        var_dump($c3);
        var_dump($c4);
        var_dump($c5);

        // 逻辑运算
        $logic1 = $c1 && $c2;
        $logic2 = $c1 || $c4;
        $logic3 = !$c5;
        var_dump($logic1);
        var_dump($logic2);
        var_dump($logic3);

        // 复杂逻辑组合
        if (($a > 50 && $b < 10) || $a == 200) {
            echo "complex logic: true\n";
        } else {
            echo "complex logic: false\n";
        }

        // 字符串比较
        $s1 = "apple";
        $s2 = "banana";
        if ($s1 < $s2) {
            echo "$s1 < $s2 (lexicographic)\n";
        }

        // null 比较
        $n = null;
        if ($n == null) {
            echo "null check ok\n";
        }
    }

    private function testStrings(): void
    {
        // 字符串拼接
        $greeting = "Hello";
        $target = "World";
        $full = $greeting . " " . $target . "!";
        echo $full;
        echo "\n";

        // 字符串插值
        $name = "TinyPHP";
        $version = 1;
        echo "Welcome to $name v$version\n";

        // 多级拼接
        $a = "one";
        $b = "two";
        $c = "three";
        $combined = $a . "-" . $b . "-" . $c;
        var_dump($combined);

        // echo 多参数
        echo "Part1 ", "Part2 ", "Part3\n";

        // 类型转字符串 + 拼接
        $num = 42;
        $pi = 3.14;
        $flag = true;
        echo "num=" . $num . " pi=" . $pi . " flag=" . $flag . "\n";
    }

    private function testObjects(): void
    {
        // 创建计算器
        $calc = new Calculator(10);
        var_dump($calc->getValue());

        // 链式调用 — add multiply subtract all return $this
        $calc->add(5)->multiply(3)->subtract(10);
        $result = $calc->getValue();
        var_dump($result);

        // 带构造参数的 logger
        $logger = new Logger("test-log");
        $logger->log("first message");
        $logger->log("second message");

        // DataStore 存储不同类型数据
        $store = new DataStore("my-store");
        $store->setInt(42);
        $store->setFloat(3.14);
        $store->setName("hello world");
        var_dump($store->getInt());
        var_dump($store->getFloat());
        var_dump($store->getName());

        // 对象属性比较
        $v1 = $store->getInt();
        $v2 = $calc->getValue();
        if ($v1 > $v2) {
            echo "store int > calc value\n";
        } else {
            echo "store int <= calc value\n";
        }
    }

    private function testArrays(): void
    {
        // 简单数组
        $nums = [1, 2, 3, 4, 5];
        $sum = 0;
        foreach ($nums as $v) {
            $sum += $v;
        }
        var_dump($sum);

        // 关联数组 + foreach key=>val
        $user = ["name" => "Alice", "age" => 30, "score" => 95];
        foreach ($user as $k2 => $v2) {
            echo $k2;
            echo ": ";
            var_dump($v2);
        }

        // 嵌套数组
        $matrix = [
            [1, 2, 3],
            [4, 5, 6],
            [7, 8, 9]
        ];
        $matrixSum = 0;
        foreach ($matrix as $rowIdx => $row) {
            $matrixSum += $rowIdx;
        }
        var_dump($matrixSum);

        // 混合类型数组
        $mixed = ["items" => [10, 20, 30], "count" => 3, "label" => "scores"];
        var_dump($mixed);

        // for 循环遍历数组
        $total = 0;
        for ($i = 0; $i < 5; $i = $i + 1) {
            $total += $i * 2;
        }
        var_dump($total);

        // while 循环
        $cnt = 5;
        $fact = 1;
        while ($cnt > 0) {
            $fact *= $cnt;
            $cnt -= 1;
        }
        var_dump($fact);

        // break 在嵌套循环中
        $found = -1;
        $search = [10, 20, 30, 40, 50];
        for ($j = 0; $j < 5; $j = $j + 1) {
            if ($search[$j] == 30) {
                $found = $j;
                break;
            }
        }
        var_dump($found);

        // continue — count even numbers
        $evenCount = 0;
        for ($k = 0; $k < 10; $k = $k + 1) {
            if (($k - ($k / 2) * 2) != 0) {
                continue;
            }
            $evenCount += 1;
        }
        var_dump($evenCount);
    }

    private function testClosures(): void
    {
        // 简单闭包
        $doubler = function (int $x): int {
            return $x * 2;
        };
        var_dump($doubler(21));

        // 多参数闭包
        $add = function (int $a, int $b): int {
            return $a + $b;
        };
        var_dump($add(10, 20));

        // 无参闭包
        $getConst = function (): int {
            return 42;
        };
        var_dump($getConst());

        // 类型转换
        $intVal = 42;
        $strVal = (string)$intVal;
        var_dump($strVal);

        $floatVal = 3.14;
        $intFromFloat = (int)$floatVal;
        var_dump($intFromFloat);

        $boolVal = (bool)1;
        var_dump($boolVal);

        $floatFromBool = (float)true;
        var_dump($floatFromBool);

        $fromStr = (int)"123";
        var_dump($fromStr);

        // bool 转换
        $b1 = (bool)"";
        $b2 = (bool)"hello";
        $b3 = (bool)0;
        $b4 = (bool)100;
        var_dump($b1);
        var_dump($b2);
        var_dump($b3);
        var_dump($b4);

        // array 转换
        $arrFromInt = (array)42;
        var_dump($arrFromInt);

        $arrFromNull = (array)null;
        var_dump($arrFromNull);
    }

    private function testControlFlow(): void
    {
        // if/elseif/else 多分支
        $score = 85;
        $grade = "F";
        if ($score >= 90) {
            $grade = "A";
        } elseif ($score >= 80) {
            $grade = "B";
        } elseif ($score >= 70) {
            $grade = "C";
        } elseif ($score >= 60) {
            $grade = "D";
        } else {
            $grade = "F";
        }
        var_dump($grade);

        // else if 分开写
        $x = 42;
        if ($x > 100) {
            echo ">100\n";
        } else if ($x > 50) {
            echo ">50\n";
        } else if ($x > 0) {
            echo ">0\n";
        } else {
            echo "<=0\n";
        }

        // 嵌套 if-else
        $a = 10;
        $b = 20;
        if ($a > 0) {
            if ($b > 10) {
                if ($a + $b > 25) {
                    echo "deep nested: all pass\n";
                }
            }
        }

        // for 递减
        $countdown = "";
        for ($i = 5; $i > 0; $i = $i - 1) {
            $countdown = $countdown . $i . " ";
        }
        var_dump($countdown);

        // while + 条件
        $n = 10;
        $bits = 0;
        while ($n > 0) {
            $bits += 1;
            $n = $n / 2;
        }
        var_dump($bits);

        // foreach 内嵌 if-break
        $items = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $sumUntil = 0;
        foreach ($items as $item) {
            $sumUntil += $item;
            if ($sumUntil > 10) {
                break;
            }
        }
        var_dump($sumUntil);
    }

    private function testSwitch(): void
    {
        // 基本 int switch
        $x = 2;
        $result = 0;
        switch ($x) {
            case 1:
                $result = 10;
                break;
            case 2:
                $result = 20;
                break;
            case 3:
                $result = 30;
                break;
            default:
                $result = 0;
        }
        var_dump($result);

        // switch fall-through (无 break)
        $y = 1;
        $acc = 0;
        switch ($y) {
            case 1:
                $acc += 10;
            case 2:
                $acc += 20;
                break;
            case 3:
                $acc += 30;
                break;
            default:
                $acc += 100;
        }
        var_dump($acc);

        // switch with default only
        $z = 99;
        $msg = "none";
        switch ($z) {
            case 1:
                $msg = "one";
                break;
            case 2:
                $msg = "two";
                break;
            default:
                $msg = "other";
        }
        var_dump($msg);

        // 字符串 switch（内部转为 if-elseif 链）
        $color = "green";
        $code = 0;
        switch ($color) {
            case "red":
                $code = 1;
                break;
            case "green":
                $code = 2;
                break;
            case "blue":
                $code = 3;
                break;
            default:
                $code = 99;
        }
        var_dump($code);

        // bool switch
        $flag = true;
        $val = 0;
        switch ($flag) {
            case true:
                $val = 42;
                break;
            case false:
                $val = -1;
                break;
        }
        var_dump($val);

        // 嵌套 switch + if
        $outer = 1;
        $inner = 2;
        $nested = 0;
        switch ($outer) {
            case 1:
                if ($inner == 2) {
                    $nested = 100;
                } else {
                    $nested = 200;
                }
                break;
            default:
                $nested = 0;
        }
        var_dump($nested);
    }

    private function testEnum(): void
    {
        // ── 1. int enum: ->value 拿值 ──
        $one = Num::ONE;
        $two = Num::TWO;
        $three = Num::THREE;
        var_dump($one->value);
        var_dump($two->value);
        var_dump($three->value);
        // ->name
        var_dump($one->name);

        // ── 2. string enum: ->value 拿值 ──
        $red = Color::RED;
        $yellow = Color::YELLOW;
        $green = Color::GREEN;
        var_dump($red->value);
        var_dump($yellow->value);
        var_dump($green->value);
        var_dump($red->name);

        // ── 3. int enum switch (用 ->value) ──
        $val = Num::TWO;
        $label = "?";
        switch ($val->value) {
            case 1:
                $label = "one";
                break;
            case 2:
                $label = "two";
                break;
            case 3:
                $label = "three";
                break;
            default:
                $label = "unknown";
        }
        var_dump($label);

        // ── 4. string enum switch (用 ->value) ──
        $current = Color::GREEN;
        $signal = "";
        switch ($current->value) {
            case "red":
                $signal = "stop";
                break;
            case "yellow":
                $signal = "caution";
                break;
            case "green":
                $signal = "go";
                break;
            default:
                $signal = "error";
        }
        var_dump($signal);

        // ── 5. enum 指针同一性比较 (== 比较指针) ──
        if ($one == Num::ONE) {
            echo "int enum identity == ok\n";
        }
        if ($two != Num::ONE) {
            echo "int enum identity != ok\n";
        }

        // ── 6. string enum 指针同一性比较 ──
        if ($red == Color::RED) {
            echo "string enum identity == ok\n";
        }
        if ($red != Color::GREEN) {
            echo "string enum identity != ok\n";
        }

        // ── 7. enum->value 算术 ──
        $sum = $one->value + $two->value;
        var_dump($sum);
        $diff = $three->value - $one->value;
        var_dump($diff);

        // ── 8. 对象属性存储 enum->value（内存安全）──
        $store = new DataStore("enum-test");
        $store->setName(Color::RED->value);
        var_dump($store->getName());
        $store->setName(Color::GREEN->value);
        var_dump($store->getName());

        // ── 9. echo enum->value ──
        echo Color::RED->value;
        echo "\n";
        echo Color::GREEN->value;
        echo "\n";

        // ── 10. 逻辑 + enum->value 比较 ──
        $x = Num::THREE;
        if ($x->value == 3 && $x->value > 1) {
            echo "enum logical && ok\n";
        }
        if ($x->value == 1 || $x->value == 3) {
            echo "enum logical || ok\n";
        }

        // ── 11. for 循环 enum->value ──
        $total = 0;
        for ($i = 1; $i <= 3; $i = $i + 1) {
            $total += $i;
        }
        var_dump($total);

        // ── 12. while 循环 enum->value ──
        $n = 3;
        $cnt = 0;
        while ($n > 0) {
            $cnt += 1;
            $n = $n - 1;
        }
        var_dump($cnt);

        // ── 13. 嵌套 if + enum 指针比较 ──
        $priority = Num::ONE;
        $msg = "low";
        if ($priority == Num::ONE) {
            $msg = "high";
        } elseif ($priority == Num::TWO) {
            $msg = "medium";
        } else {
            $msg = "low";
        }
        var_dump($msg);

        // ── 14. 条件表达式 + enum 指针比较 ──
        $flag = Num::ONE;
        $result = 0;
        if ($flag == Num::ONE) {
            $result = 100;
        }
        var_dump($result);

        // ── 15. 多 string enum 拼接 (用 ->value) ──
        $a = Color::RED->value;
        $b = Color::YELLOW->value;
        $cVal = Color::GREEN->value;
        $concat = $a . "-" . $b . "-" . $cVal;
        var_dump($concat);

        // ── 16. 跨命名空间枚举 ──
        echo "=== cross-namespace enum ===\n";
        $active = Status::ACTIVE;
        $banned = Status::BANNED;
        var_dump($active->value);
        var_dump($banned->value);
        var_dump($active->name);

        $admin = Role::ADMIN;
        $guest = Role::GUEST;
        var_dump($admin->value);
        var_dump($guest->value);
        var_dump($admin->name);

        // 跨命名空间 int enum switch (用 ->value)
        $st = Status::INACTIVE;
        $desc = "?";
        switch ($st->value) {
            case 1:
                $desc = "active";
                break;
            case 2:
                $desc = "inactive";
                break;
            case 3:
                $desc = "banned";
                break;
            default:
                $desc = "unknown";
        }
        var_dump($desc);

        // 跨命名空间 string enum 指针同一性
        if ($admin == Role::ADMIN) {
            echo "cross-ns identity == ok\n";
        }
        if ($admin != Role::GUEST) {
            echo "cross-ns identity != ok\n";
        }

        // 跨命名空间 enum->value 比较
        if ($active->value == 1) {
            echo "cross-ns value == ok\n";
        }
        if ($active->value < $banned->value) {
            echo "cross-ns value < ok\n";
        }

        // 跨命名空间 enum->value 算术
        $nsSum = $active->value + $banned->value;
        var_dump($nsSum);

        // string enum ->value 直接输出
        echo Role::ADMIN->value;
        echo "\n";
        echo Role::GUEST->value;
        echo "\n";
    }

    private function testMemoryStress(): void
    {
        // 创建多个对象 —— 确保所有都在作用域结束时自动析构
        $c1 = new Calculator(1);
        $c1->add(10);
        $c1->multiply(3);
        var_dump($c1->getValue());

        $c2 = new Calculator(5);
        $c2->add(2);
        var_dump($c2->getValue());

        // 多个字符串拼接 —— 确保 tphp_rt_str_concat 返回值在拼接链中正确传递
        $longStr = "";
        for ($j = 0; $j < 3; $j = $j + 1) {
            $longStr = $longStr . $j;
        }
        var_dump($longStr);

        // 嵌套创建 DataStore 对象 —— 验证多个对象同时存在时的内存安全
        $ds1 = new DataStore("store-1");
        $ds2 = new DataStore("store-2");
        $ds1->setInt(111);
        $ds2->setInt(222);
        var_dump($ds1->getInt());
        var_dump($ds2->getInt());
        // ds1, ds2, c1, c2 在方法结束时自动释放
    }

    private function testClosureUse(): void
    {
        // ── 1. 基本 use 捕获 int ──
        $multiplier = 10;
        $doubler = function (int $x) use ($multiplier): int {
            return $x * $multiplier;
        };
        $r1 = $doubler(5);
        var_dump($r1);

        // ── 2. 捕获多个变量 ──
        $a = 3;
        $b = 7;
        $sumFn = function () use ($a, $b): int {
            return $a + $b;
        };
        var_dump($sumFn());

        // ── 3. 捕获 string ──
        $prefix = "[LOG]";
        $log = function (string $msg) use ($prefix): void {
            echo $prefix;
            echo " ";
            echo $msg;
            echo "\n";
        };
        $log("hello world");

        // ── 4. 捕获变量不影响外部 ──
        $counter = 5;
        $inc = function () use ($counter): int {
            return $counter + 1;
        };
        var_dump($inc());
        var_dump($counter);

        // ── 5. 链式捕获 + 组合 ──
        $base = 100;
        $adder = function (int $v) use ($base): int {
            return $base + $v;
        };
        $mul = function (int $v) use ($adder): int {
            return $adder($v) * 2;
        };
        var_dump($mul(10));

        // ── 6. 嵌套闭包 + use ──
        $outer = 1;
        $innerFn = function (int $x) use ($outer): int {
            $inner = function (int $y) use ($outer, $x): int {
                return $outer + $x + $y;
            };
            return $inner(3);
        };
        var_dump($innerFn(2));
    }

    private function testHighPriority(): void
    {
        // ── 1. 取模 % ──
        $m1 = 10 % 3;
        $m2 = 17 % 5;
        var_dump($m1);
        var_dump($m2);

        // ── 2. 三元 ?: ──
        $score = 85;
        $grade = ($score >= 60) ? "pass" : "fail";
        var_dump($grade);
        $nested = ($score >= 90) ? "A" : (($score >= 80) ? "B" : "C");
        var_dump($nested);

        // ── 3. null 合并 ?? ──
        $maybeNull = null;
        $fallback = $maybeNull ?? "default";
        var_dump($fallback);

        $val = 42;
        $keep = $val ?? 0;
        var_dump($keep);

        // ── 4. do-while ──
        $i = 0;
        $acc = 0;
        do {
            $acc += $i;
            $i = $i + 1;
        } while ($i < 5);
        var_dump($acc);

        // ── 5. die() ──
        $shouldDie = 0;
        if ($shouldDie) {
            die(0);
            echo "NEVER\n";
        }
        echo "die test ok\n";

        // ── 6. isset() / empty() ──
        $defined = 42;
        $isSet = isset($defined) ? 1 : 0;
        var_dump($isSet);

        $zeroVal = 0;
        $isEmpty = empty($zeroVal) ? 1 : 0;
        var_dump($isEmpty);

        $notEmpty = empty($defined) ? 1 : 0;
        var_dump($notEmpty);

        // ── 7. list() 解构 ──
        $arr = [10, 20, 30];
        list($a, $b, $c) = $arr;
        var_dump($a);
        var_dump($b);
        var_dump($c);

        // ── 8. 位运算 & | ^ ~ << >> ──
        $and = 6 & 3;      // 110 & 011 = 010 = 2
        $or  = 6 | 3;      // 110 | 011 = 111 = 7
        $xor = 6 ^ 3;      // 110 ^ 011 = 101 = 5
        $not = ~0;          // -1
        $shl = 1 << 4;     // 16
        $shr = 16 >> 2;    // 4
        var_dump($and);
        var_dump($or);
        var_dump($xor);
        var_dump($not);
        var_dump($shl);
        var_dump($shr);

        // ── 9. 位运算组合 ──
        $flags = 0;
        $flags = $flags | 1;   // set bit 0
        $flags = $flags | 4;   // set bit 2
        $hasBit0 = ($flags & 1) ? 1 : 0;
        $hasBit2 = ($flags & 4) ? 1 : 0;
        var_dump($flags);
        var_dump($hasBit0);
        var_dump($hasBit2);

        // ── 10. ?? 链式 ──
        $a = null;
        $b = null;
        $c = $a ?? $b ?? 99;
        var_dump($c);
    }

    private function testExit(): void
    {
        // exit() 中断程序，后面代码不执行
        // 这里用条件控制：NEVER_EXIT=0 时不退出，走完整个测试
        $code = 0;
        if ($code == 0) {
            echo "exit test: calling exit(0) now\n";
            exit(0);
            // 下面的代码永远不会执行
            echo ">>> BUG: this should not print!\n";
        }
        echo "this should also not print\n";
    }
}

// ============================================================
// Calculator — 支持链式调用的计算器
// ============================================================

class Calculator
{
    private int $value;
    private string $label;

    public function __construct(int $initial)
    {
        $this->value = $initial;
        $this->label = "calc";
    }

    public function add(int $n): self
    {
        $this->value += $n;
        return $this;
    }

    public function subtract(int $n): self
    {
        $this->value -= $n;
        return $this;
    }

    public function multiply(int $n): self
    {
        $this->value *= $n;
        return $this;
    }

    public function getValue(): int
    {
        return $this->value;
    }
}

// ============================================================
// Logger — 带字符串属性的日志记录器
// ============================================================

class Logger
{
    public string $prefix;
    private int $count;

    public function __construct(string $p)
    {
        $this->prefix = $p;
        $this->count = 0;
    }

    public function log(string $msg): void
    {
        $this->count += 1;
        echo "[";
        echo $this->prefix;
        echo " #";
        echo $this->count;
        echo "] ";
        echo $msg;
        echo "\n";
    }
}

// ============================================================
// DataStore — 存储多种类型数据的容器
// ============================================================

class DataStore
{
    public string $name;
    private int $intVal;
    private float $floatVal;
    private string $strVal;

    public function __construct(string $n)
    {
        $this->name = $n;
        $this->intVal = 0;
        $this->floatVal = 0.0;
        $this->strVal = "";
    }

    public function setInt(int $v): void
    {
        $this->intVal = $v;
    }

    public function getInt(): int
    {
        return $this->intVal;
    }

    public function setFloat(float $v): void
    {
        $this->floatVal = $v;
    }

    public function getFloat(): float
    {
        return $this->floatVal;
    }

    public function setName(string $v): void
    {
        $this->strVal = $v;
    }

    public function getName(): string
    {
        return $this->strVal;
    }
}
