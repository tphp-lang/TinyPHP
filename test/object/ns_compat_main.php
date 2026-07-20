<?php // @multi @with ns_compat_lib.php,ns_compat_util_lib.php
// ns_compat_main.php — 命名空间兼容性测试入口
//   覆盖 4 个命名空间问题的修复验证
#debug === NS Compat Test ===
#debug
#debug -- 1. global fn fallback --
#debug upper=HELLO
#debug strlen=5
#debug urldec=?
#debug
#debug -- 2. extends short name --
#debug derived.doubled=10
#debug derived.tripled=15
#debug
#debug -- 3. short type annotations --
#debug holder.get.doubled=10
#debug holder.wrap=25
#debug
#debug -- 4. use alias as type --
#debug viaAlias=LOWER
#debug
#debug === OK ===

use NSCompat\Core\Base;
use NSCompat\Core\Derived;
use NSCompat\Core\Holder;
use NSCompat\Util\Helper;

class Main
{
    // 问题4: use 别名作为参数类型注解
    //   Helper 经 use NSCompat\Util\Helper 导入，作为参数类型注解
    //   修复前: parseType() 不查 use 导入表，类型解析失败
    //   修复后: parseType() 调用 resolveClassName() 先查 use 导入表
    public function toUpper(Helper $h): string
    {
        return $h->upper();
    }

    public function main(): void
    {
        echo "=== NS Compat Test ===\n\n";

        // ═══ 问题1: 命名空间内调用全局函数 ═══
        // Helper 类位于 NSCompat\Util 命名空间内，方法内部调用全局函数
        //   strtoupper / strlen / urldecode
        // 修复前: resolveFunctionName() 给函数名加命名空间前缀，
        //         inferCallReturnType() 在内置表中查不到 → "Unknown function return type"
        // 修复后: 命名空间前缀查不到时 fallback 到全局函数
        echo "-- 1. global fn fallback --\n";
        $h = new Helper('hello');
        echo 'upper=' . $h->upper() . "\n";        // HELLO
        echo 'strlen=' . $h->length() . "\n";      // 5

        $h2 = new Helper('%3F');
        echo 'urldec=' . $h2->decoded() . "\n";    // ?

        // ═══ 问题2: 命名空间内 extends 短类名 ═══
        // Derived extends Base 都在 NSCompat\Core 命名空间内
        // 修复前: parseQualifiedName() 返回短名 'Base'，
        //         classRefName() 生成 tphp_class_Base（全局类，错误）
        // 修复后: resolveClassName() 解析为 NSCompat\Core\Base
        //         → tphp_na_NSCompat_Core_tphp_class_Base
        echo "\n-- 2. extends short name --\n";
        $d = new Derived(5);
        echo 'derived.doubled=' . $d->doubled() . "\n";   // 10 (继承自 Base)
        echo 'derived.tripled=' . $d->tripled() . "\n";   // 15 (Derived 自己的方法)

        // ═══ 问题3: 类型注解中的短类名 ═══
        // Holder 类的属性/参数/返回类型都使用短类名 Base/Derived
        // 修复前: parseType() 直接返回短名，mapType 找不到类生成 fallback 类型 "Base*"
        // 修复后: parseType() 调用 resolveClassName() 解析为 FQ 名
        //         SymbolTable 同时注册短名和 FQ 名
        echo "\n-- 3. short type annotations --\n";
        $b = new Base(5);
        $holder = new Holder($b);
        $got = $holder->get();
        echo 'holder.get.doubled=' . $got->doubled() . "\n"; // 10
        $d2 = new Derived(5);
        echo 'holder.wrap=' . $holder->wrap($d2) . "\n";     // 25 (10+15)

        // ═══ 问题4: use 别名作为类型注解 ═══
        // Main 在全局命名空间，通过 use 导入 Helper 类
        // toUpper(Helper $h) 的参数类型通过 use 导入表解析
        echo "\n-- 4. use alias as type --\n";
        $helper = new Helper('lower');
        echo 'viaAlias=' . $this->toUpper($helper) . "\n";  // LOWER

        echo "\n=== OK ===\n";
    }
}
