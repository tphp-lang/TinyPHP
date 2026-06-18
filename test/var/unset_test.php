<?php

class Main
{
    public function main(): void
    {
        echo "===== unset int =====\n";
        $this->testUnsetInt();

        echo "===== unset string =====\n";
        $this->testUnsetStr();

        echo "===== unset array =====\n";
        $this->testUnsetArr();

        echo "===== unset object =====\n";
        $this->testUnsetObj();

        echo "===== unset in loop =====\n";
        $this->testUnsetLoop();

        echo "all unset tests passed\n";
    }

    private function testUnsetInt(): void
    {
        $x = 42;
        var_dump($x);
        unset($x);
        $x = 0;
        var_dump($x);
    }

    private function testUnsetStr(): void
    {
        $s = "hello world";
        var_dump($s);
        unset($s);
        $s = "";
        var_dump($s);
    }

    private function testUnsetArr(): void
    {
        $arr = [1, 2, 3];
        var_dump($arr);
        unset($arr);
        $arr = [10];
        var_dump($arr);

        $nested = [10, [20, 30], 40];
        var_dump($nested);
        unset($nested);
        $nested = [];
        var_dump($nested);
    }

    private function testUnsetObj(): void
    {
        $d = new Demo("test");
        var_dump($d->name);
        unset($d);
        $d = new Demo("new");
        var_dump($d->name);

        $a = new Demo("a");
        $b = new Demo("b");
        var_dump($a->name);
        var_dump($b->name);
        unset($a);
        unset($b);
        $a = new Demo("after");
        var_dump($a->name);
    }

    private function testUnsetLoop(): void
    {
        for ($i = 0; $i < 3; $i = $i + 1) {
            $d = new Demo("loop");
            var_dump($d->name);
            unset($d);
        }

        for ($j = 0; $j < 2; $j = $j + 1) {
            $s = "iter_" . $j;
            var_dump($s);
            unset($s);
        }
    }
}

class Demo
{
    public string $name;

    public function __construct(string $n)
    {
        $this->name = $n;
    }
}
