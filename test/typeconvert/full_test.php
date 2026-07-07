<?php
#debug #1 Book 25
#debug #3 Bag 50
#debug 2. nullsafe=#2 Pen 5
#debug 3. price>0 OK
#debug 4. id===1 OK
#debug 5. id!==99 OK
#debug 6. discount 100=80
#debug 7. price 50=mid
#debug 8. sorted: 10 20 30 40 50
#debug 9. sale promo active
#debug 10. sum(1-10)=int(55)
#debug  min=int(1)
#debug
#debug 11. replaced=hi world hi
#debug 12. unique count=3
#debug 13. Hello World

class Order {
    public function __construct(
        public int $id,
        public string $item,
        public int $price,
    ) {}

    public function summary(): string {
        return '#' . $this->id . ' ' . $this->item . ' ' . $this->price;
    }
}

class Main {
    public function main(): void {
        // ── 1. 构造器属性提升 ──
        $o1 = new Order(1, 'Book', 25);
        $o2 = new Order(2, 'Pen', 5);
        $o3 = new Order(3, 'Bag', 50);
        echo $o1->summary() . "\n";
        echo $o3->summary() . "\n";

        // ── 2. nullsafe ?-> ──
        echo '2. nullsafe=' . $o2?->summary() . "\n";

        // ── 3. !== / === ──
        if ($o2->price !== 0) { echo "3. price>0 OK\n"; }
        if ($o1->id === 1)   { echo "4. id===1 OK\n"; }
        if ($o1->id !== 99)  { echo "5. id!==99 OK\n"; }

        // ── 6. fn 箭头函数 ──
        $discount = fn(float $p): float => ($p * 80) / 100;
        echo '6. discount 100=' . $discount(100) . "\n";

        // ── 7. match 多条件 ──
        $level = match($o3->price) {
            1, 2, 3, 4, 5 => 'cheap',
            25, 50 => 'mid',
            default => 'premium'
        };
        echo '7. price 50=' . $level . "\n";

        // ── 8. sort + implode ──
        $nums = [30, 10, 50, 20, 40];
        sort($nums);
        echo '8. sorted: ' . implode(' ', $nums) . "\n";

        // ── 9. str_contains + === true ──
        $title = 'summer mega sale 2024';
        if (str_contains($title, 'sale') === true) {
            echo "9. sale promo active\n";
        }

        // ── 10. range + array_sum + min + var_dump ──
        $r = range(1, 10);
        echo '10. sum(1-10)=';
        var_dump(array_sum($r));
        echo ' min=';
        var_dump(min($r));
        echo "\n";

        // ── 11. str_replace ──
        echo '11. replaced=' . str_replace('hello', 'hi', 'hello world hello') . "\n";

        // ── 12. array_unique + count ──
        $items = [1, 2, 2, 3, 3, 3];
        $unique = array_unique($items);
        echo '12. unique count=' . count($unique) . "\n";

        // ── 13. sprintf ──
        echo '13. ' . sprintf('Hello %s', 'World') . "\n";
    }
}
