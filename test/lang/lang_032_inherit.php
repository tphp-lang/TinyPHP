<?php
// 对应 PHP 原生 tests/lang/032.phpt — Class method registration (继承与方法重写)
#debug OK

class A {
    public function foo(): void {}
}

class B extends A {
    public function foo(): void {}
}

class C extends B {
    public function foo(): void {}
}

class D extends A {
}

class F extends D {
    public function foo(): void {}
}

class Main {
    public function main(): void {
        echo "OK\n";
    }
}
