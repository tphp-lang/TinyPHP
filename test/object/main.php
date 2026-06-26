<?php // @multi @with models.php,services.php

class Main
{
    public function main(): void
    {
        echo "=== Auto-Destruct Test ===\n\n";

        // ═══ 1. 创建对象 ═══
        echo "-- 1. Create --\n";
        $p1 = new Product('SKU-A', 1, 'Alpha', 10.0, 5, true);
        $p2 = new Product('SKU-B', 2, 'Beta',  20.0, 3, true);
        echo 'p1=' . $p1->name . ' p2=' . $p2->name . "\n";

        // ═══ 2. 使用对象 ═══
        echo "\n-- 2. Use --\n";
        echo 'p1 total=$' . $p1->getTotal() . "\n";
        $p1->applyDiscount(10.0);
        echo 'p1 -10%=$' . $p1->price . "\n";

        // ═══ 3. 显式 unset (触发 destruct) ═══
        echo "\n-- 3. Unset p2 --\n";
        unset($p2);

        // ═══ 4. p1 继续使用 ═══
        echo "\n-- 4. p1 still alive --\n";
        echo $p1->sku . "\n";

        // p1 将在 main() 结束时自动析构
        echo "\n-- main() ends, p1 auto-destroy follows --\n";
    }
}
