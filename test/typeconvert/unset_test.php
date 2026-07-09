<?php
#debug ===== unset int =====
#debug int(42)
#debug int(0)
#debug ===== unset string =====
#debug string(11) "hello world"
#debug string(0) ""
#debug ===== unset array =====
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug array(1) {
#debug   [0]=>
#debug   int(10)
#debug }
#debug array(3) {
#debug   [0]=>
#debug   int(10)
#debug   [1]=>
#debug   array(2) {
#debug     [0]=>
#debug     int(20)
#debug     [1]=>
#debug     int(30)
#debug   }
#debug   [2]=>
#debug   int(40)
#debug }
#debug array(0) {
#debug }
#debug ===== unset object =====
#debug string(4) "test"
#debug string(3) "new"
#debug string(1) "a"
#debug string(1) "b"
#debug string(5) "after"
#debug ===== unset in loop =====
#debug string(4) "loop"
#debug string(4) "loop"
#debug string(4) "loop"
#debug string(6) "iter_0"
#debug string(6) "iter_1"
#debug all unset tests passed

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
