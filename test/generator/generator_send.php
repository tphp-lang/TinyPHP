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
        $gen = gen();
        var_dump($gen->current());
        var_dump($gen->send(100));
        var_dump($gen->send(200));
    }
}
