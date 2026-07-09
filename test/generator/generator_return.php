<?php
#debug int(1)
#debug int(2)
#debug int(99)

function gen(): Generator {
    yield 1;
    yield 2;
    return 99;
}

class Main {
    public function main(): void {
        $g = gen();
        foreach ($g as $v) {
            var_dump($v);
        }
        var_dump($g->getReturn());
    }
}
