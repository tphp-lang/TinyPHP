<?php 
#debug int(2)
#debug int(4)
#debug int(6)
#debug int(4)
#debug int(6)
#debug int(8)
#debug int(10)
#debug int(12)

function gen(int $start, int $end, callable $fn): Generator {
    for ($i = $start; $i <= $end; $i++) {
        yield $fn($i);
    }
}

class Main {
    public function main(): void {
        foreach (gen(1, 3, function(int $x): int { return $x * 2; }) as $v) {
            var_dump($v);
        }
        foreach (gen(2, 6, function(int $x): int { return $x * 2; }) as $v) {
            var_dump($v);
        }
    }
}