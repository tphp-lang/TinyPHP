<?php
#debug 1. final.point.x=10
#debug 2. final.point.y=20
#debug 3. final.area=200
#debug 4. final.scale.x=30
#debug 5. final.scale.y=60
#debug 6. final.scale.area=1800
#debug 7. final.static.factory.x=5
#debug 8. final.static.factory.y=5
#debug 9. final.chain.result=42
#debug 10. done

// final class — all methods are statically dispatched (no vtable indirection)
final class Point {
    public int $x;
    public int $y;

    public function __construct(int $x, int $y) {
        $this->x = $x;
        $this->y = $y;
    }

    public function area(): int {
        return $this->x * $this->y;
    }

    // final method on final class (redundant but valid)
    final public function scale(int $factor): Point {
        return new Point($this->x * $factor, $this->y * $factor);
    }

    // static factory — static methods also use static dispatch
    public static function origin(): Point {
        return new Point(5, 5);
    }

    // method returning int, used for chain test
    public function extract(): int {
        return $this->x + $this->y;
    }
}

class Main {
    public function main(): void {
        $p = new Point(10, 20);
        echo '1. final.point.x=' . $p->x . "\n";
        echo '2. final.point.y=' . $p->y . "\n";
        echo '3. final.area=' . $p->area() . "\n";

        // method call returning new final class instance (chain dispatch)
        $s = $p->scale(3);
        echo '4. final.scale.x=' . $s->x . "\n";
        echo '5. final.scale.y=' . $s->y . "\n";
        echo '6. final.scale.area=' . $s->area() . "\n";

        // static method call on final class
        $o = Point::origin();
        echo '7. final.static.factory.x=' . $o->x . "\n";
        echo '8. final.static.factory.y=' . $o->y . "\n";

        // method call on final class instance: extract() returns x+y = 20+22 = 42
        $e = new Point(20, 22);
        echo '9. final.chain.result=' . $e->extract() . "\n";

        echo "10. done\n";
    }
}
