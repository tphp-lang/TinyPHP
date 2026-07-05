<?php
#debug string(5) "hello"
#debug float(3.14)
#debug int(42)
#debug NULL
#debug string(5) "world"

function gen(): Generator {
    yield "hello";
    yield 3.14;
    yield 42;
    yield null;
    yield "world";
}

class Main {
    public function main(): void {
        foreach (gen() as $v) {
            var_dump($v);
        }
    }
}