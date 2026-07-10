<?php
#debug int(1)
#debug int(2)
#debug int(3)
#debug int(1)
#debug int(2)
#debug int(4)
#debug int(5)
#debug int(1)
#debug int(2)
#debug int(3)
#debug int(1)
#debug int(2)
#debug int(4)
#debug int(6)

function gen1(): Generator {
    yield 1;
    yield 2;
}

function gen2(): Generator {
    yield from gen1();
    yield 3;
    yield from gen1();
    yield 4;
}

function gen3(): Generator {
    yield from gen2();
    yield 5;
    yield from gen2();
    yield 6;
}

class Main {
    public function main(): void {
        $g = gen3();
        foreach ($g as $v) {
            var_dump($v);
        }
    }
}
