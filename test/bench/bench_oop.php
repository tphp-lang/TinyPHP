<?php

// ============================================================
// TinyPHP OOP Benchmark — 10 项面向对象操作性能测试
// ============================================================

class Animal
{
    public string $name;
    public int $age;
    public string $species;

    public function __construct(string $species)
    {
        $this->species = $species;
    }

    public function speak(): string
    {
        return $this->name;
    }

    public function ageInMonths(): int
    {
        return $this->age * 12;
    }

    public function describe(): string
    {
        return $this->species;
    }
}

class Dog extends Animal
{
    public string $breed;

    public function __construct(string $species)
    {
        $this->species = $species;
    }

    public function speak(): string
    {
        return $this->name;
    }

    public function bark(): string
    {
        return $this->breed;
    }
}

interface Identifiable
{
    public function id(): int;
}

class User implements Identifiable
{
    public int $uid;
    public string $username;

    public function id(): int
    {
        return $this->uid;
    }
}

class Point
{
    public float $x;
    public float $y;

    public function distance(Point $other): float
    {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        return sqrt($dx * $dx + $dy * $dy);
    }
}

class Main
{
    public int $t0;

    public function main(): void
    {
        echo "=== TinyPHP OOP Benchmark ===\n\n";
        $N = 500000;

        // ═══ 1. 对象创建+释放 ×N ═══
        echo "-- 1. new+unset Dog() x" . $N . " --\n";
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) {
            $d = new Dog('canine');
            unset($d);
        }
        echo 'create: ' . (hrtime() - $this->t0) . " ns\n";

        // ═══ 2. 属性写入 ×N ═══
        echo "\n-- 2. property write x" . $N . " --\n";
        $dog = new Dog('canine');
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) {
            $dog->name = 'Buddy';
            $dog->age = 3;
            $dog->breed = 'Golden';
        }
        echo 'prop write: ' . (hrtime() - $this->t0) . " ns\n";

        // ═══ 3. 属性读取 ×N ═══
        echo "\n-- 3. property read x" . $N . " --\n";
        $dog->name = 'Max';
        $dog->age = 5;
        $this->t0 = hrtime();
        $sum = 0;
        for ($i = 0; $i < $N; $i++) {
            $sum = $sum + $dog->age;
        }
        echo 'prop read: ' . (hrtime() - $this->t0) . " ns  (sum=" . $sum . ")\n";

        // ═══ 4. 方法调用 (无参) ×N ═══
        echo "\n-- 4. method call (no arg) x" . $N . " --\n";
        $dog->name = 'Rex';
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = $dog->speak(); }
        echo 'method(0): ' . (hrtime() - $this->t0) . " ns\n";

        // ═══ 5. 方法调用 (有参+返回) ×N ═══
        echo "\n-- 5. method call (with arg) x" . $N . " --\n";
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $m = $dog->ageInMonths(); }
        echo 'method(1): ' . (hrtime() - $this->t0) . " ns\n";

        // ═══ 6. 继承方法调用 (父类方法) ×N ═══
        echo "\n-- 6. inherited method x" . $N . " --\n";
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $s = $dog->describe(); }
        echo 'inherited: ' . (hrtime() - $this->t0) . " ns\n";

        // ═══ 7. 方法链调用 (dog->ageInMonths) ×N ═══
        echo "\n-- 7. method chaining x" . $N . " --\n";
        $this->t0 = hrtime();
        $totalM = 0;
        for ($i = 0; $i < $N; $i++) { $totalM = $dog->ageInMonths(); }
        echo 'chain: ' . (hrtime() - $this->t0) . " ns  (months=" . $totalM . ")\n";

        // ═══ 8. 构造器调用+释放 ×N ═══
        echo "\n-- 8. constructor+unset x" . $N . " --\n";
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) {
            $a = new Animal('mammal');
            unset($a);
        }
        echo 'construct: ' . (hrtime() - $this->t0) . " ns\n";

        // ═══ 9. 接口实现类调用 ×N ═══
        echo "\n-- 9. interface impl x" . $N . " --\n";
        $user = new User();
        $user->uid = 42;
        $this->t0 = hrtime();
        $uidSum = 0;
        $i9 = 0;
        while ($i9 < $N) {
            $uidSum = $user->id();
            $i9 = $i9 + 1;
        }
        echo 'impl: ' . (hrtime() - $this->t0) . " ns  (uid=" . $uidSum . ")\n";

        // ═══ 10. 对象间方法调用 (Point.distance) ×N/10 ═══
        echo "\n-- 10. inter-object call x" . ($N/10) . " --\n";
        $M4 = $N / 10;
        $p1 = new Point();
        $p1->x = 0.0;
        $p1->y = 0.0;
        $p2 = new Point();
        $p2->x = 3.0;
        $p2->y = 4.0;
        $this->t0 = hrtime();
        $dist = 0.0;
        for ($i = 0; $i < $M4; $i++) { $dist = $p1->distance($p2); }
        echo 'inter-obj: ' . (hrtime() - $this->t0) . " ns  (dist=" . $dist . ")\n";

        echo "\n=== done ===\n";
    }
}
