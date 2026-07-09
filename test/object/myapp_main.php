<?php // @multi @with myapp_models.php,myapp_services.php
// myapp_main.php — 命名空间 OOP 入口
#debug === Namespace OOP Test ===
#debug
#debug -- 1. Create --
#debug u1=Alice <alice@tphp.dev>
#debug u2=Bob <bob@tphp.dev> active=0
#debug
#debug -- 2. Process --
#debug r1=OK: Order#100 $150 [approved] by Alice <alice@tphp.dev>
#debug r2=FAIL: user inactive
#debug r3=OK: Order#101 $250 [approved] by Alice <alice@tphp.dev>
#debug
#debug -- 3. States --
#debug o1=approved
#debug o3=pending
#debug
#debug -- 4. Report --
#debug ok=2 fail=1 total=$400
#debug
#debug -- 5. Nullsafe --
#debug   (OK)
#debug
#debug -- 6. Strict --
#debug o1===100:1
#debug u1===true:1
#debug
#debug === OK ===

use MyApp\Models\User;
use MyApp\Models\Order;
use MyApp\Services\OrderProcessor;
use MyApp\Services\ReportBuilder;

class Main
{
    public function main(): void
    {
        echo "=== Namespace OOP Test ===\n\n";

        // ═══ 1. 跨命名空间创建对象 ═══
        echo "-- 1. Create --\n";
        $u1 = new User(1, 'Alice', 'alice@tphp.dev', true);
        $u2 = new User(2, 'Bob',   'bob@tphp.dev',   false);
        $o1 = new Order(100, 1, 150.0);
        $o2 = new Order(101, 1, 250.0);
        $o3 = new Order(102, 2, 99999.0);

        echo 'u1=' . $u1->displayName() . "\n";
        echo 'u2=' . $u2->displayName() . ' active=0' . "\n";

        // ═══ 2. 跨命名空间服务调用 ═══
        echo "\n-- 2. Process --\n";
        $proc = new OrderProcessor();
        $rpt  = new ReportBuilder();

        // u1 active, o1=150 → OK
        $r1 = $proc->process($u1, $o1);
        echo 'r1=' . $r1 . "\n";
        $rpt->addProcessed($o1);

        // u2 inactive → FAIL
        $r2 = $proc->process($u2, $o3);
        echo 'r2=' . $r2 . "\n";
        $rpt->addRejected();

        // u1 active, o2=250 → OK
        $r3 = $proc->process($u1, $o2);
        echo 'r3=' . $r3 . "\n";
        $rpt->addProcessed($o2);

        // ═══ 3. 对象状态 ═══
        echo "\n-- 3. States --\n";
        echo 'o1=' . $o1->status . "\n";
        echo 'o3=' . $o3->status . "\n";

        // ═══ 4. 报告 ═══
        echo "\n-- 4. Report --\n";
        echo $rpt->summary() . "\n";

        // ═══ 5. nullsafe ═══
        echo "\n-- 5. Nullsafe --\n";
        $u1?->displayName();
        echo "  (OK)\n";

        // ═══ 6. 严格比较 ═══
        echo "\n-- 6. Strict --\n";
        echo 'o1===100:' . ($o1->id === 100 ? 1 : 0) . "\n";
        echo 'u1===true:' . ($u1->active === true ? 1 : 0) . "\n";

        echo "\n=== OK ===\n";
    }
}
