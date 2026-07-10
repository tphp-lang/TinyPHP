<?php
#debug string(1) "a"
#debug string(1) "b"
#debug string(1) "c"

function genFromArray(array $arr): Generator {
    yield from $arr;
}

class Main {
    public function main(): void {
        $g = genFromArray(["a", "b", "c"]);
        foreach ($g as $v) {
            var_dump($v);
        }
    }
}
