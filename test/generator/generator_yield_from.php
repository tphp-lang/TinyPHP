<?php
#debug int(0)
#debug int(1)
#debug int(2)
#debug int(100)
#debug int(3)

function inner(): Generator {
    yield 1;
    yield 2;
    return 100;
}

function outer(): Generator {
    yield 0;
    $ret = yield from inner();
    var_dump($ret);
    yield 3;
}

class Main {
    public function main(): void {
        $g = outer();
        foreach ($g as $v) {
            var_dump($v);
        }
    }
}
