<?php
#debug int(1)
#debug int(2)
#debug int(3)

function gen(): Generator {
    yield 1;
    yield 2;
    yield 3;
}

class Main {
    public function main(): void {
        foreach (gen() as $v) {
            var_dump($v);
        }
    }
}
