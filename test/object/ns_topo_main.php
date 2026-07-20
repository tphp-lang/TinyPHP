<?php // @multi @with ns_topo_lib.php
// ns_topo_main.php — 命名空间下多层继承拓扑排序测试
//   复现拓扑排序使用错误类名格式的 bug
//   修复前: $byRefName key 用 classRefName($c->name)（短名）
//             → 命名空间类 key 是 tphp_class_X（全局类格式）
//             → 与父类 C 名 tphp_na_TopoNS_tphp_class_X 不匹配
//             → isset($byRefName[$pcn]) 失败 → 拓扑排序失效
//             → C 编译报 field '_parent' has incomplete type
//   修复后: key 用 classCName($c)（含命名空间），拓扑排序正确工作
//   测试重点: 编译通过即说明拓扑排序正确（struct 顺序正确）
#debug === NS Topological Sort ===
#debug
#debug base=10
#debug grand=30
#debug greeting=Hello, Alice!
#debug
#debug === OK ===

use TopoNS\ParentBase;
use TopoNS\Child;
use TopoNS\GrandChild;
use TopoNS\OtherParent;
use TopoNS\OtherChild;

class Main
{
    public function main(): void
    {
        echo "=== NS Topological Sort ===\n\n";

        // ═══ 单条继承链: GrandChild → Child → ParentBase ═══
        //   父类在 ns_topo_lib.php 中按字母序排列（ParentBase → Child → GrandChild）
        //   修复前拓扑排序失效，C 编译报 field '_parent' has incomplete type
        //   注：只测直接实例化各层类的方法，避免触发 COS 多层方法分派的独立 bug
        $pb = new ParentBase();
        $pb->val = 10;
        echo 'base=' . $pb->baseMethod() . "\n";      // 10 (ParentBase)

        $gc = new GrandChild();
        $gc->val = 10;
        echo 'grand=' . $gc->grandMethod() . "\n";    // 30 (GrandChild)

        // ═══ 跨继承链: OtherChild → OtherParent ═══
        $oc = new OtherChild();
        $oc->name = 'Alice';
        echo 'greeting=' . $oc->greeting() . "\n";    // Hello, Alice! (OtherChild)

        echo "\n=== OK ===\n";
    }
}
