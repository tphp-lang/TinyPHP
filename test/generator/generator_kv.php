<?php // @skip:macos+tcc
#debug string(1) "a"
#debug int(10)
#debug string(1) "b"
#debug int(20)
#debug int(0)
#debug int(100)
#debug int(1)
#debug int(200)

function gen(): Generator {
    yield "a" => 10;
    yield "b" => 20;
    yield 100;
    yield 200;
}

class Main {
    public function main(): void {
        foreach (gen() as $k => $v) {
            var_dump($k);
            var_dump($v);
        }
    }
}