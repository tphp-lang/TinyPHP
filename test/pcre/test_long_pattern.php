<?php
// P3-5: pcre tp_cache key 长度限制 — 长模式（>255 字节）缓存验证
// 修复前: key[256] 固定缓冲，key_len 截断为 255，长模式无法命中缓存（每次重新编译）
// 修复后: char* key 动态分配，支持任意长度模式，长模式可正确缓存命中
#import pcre

#debug === Long Pattern Caching (P3-5) ===
#debug
#debug 1. long-literal: len=300
#debug 2. second-pattern: len=300
#debug 3. repeat-hit: ok
#debug 4. distinct: AX=1 AY=0 BY=1
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== Long Pattern Caching (P3-5) ===\n\n";

        // 1. 长字面量模式（>255 字节）：300 个 'a' 作为模式
        //    修复前: 编译仍用完整模式（正确），但缓存 key 截断为 255，无法命中
        //    修复后: 完整 key 存储，二次调用可命中缓存
        $longLit = "/" . str_repeat("a", 300) . "/";
        $target  = str_repeat("a", 300);
        $m1 = preg_match($longLit, $target);
        echo "1. long-literal: len=" . strlen($m1[0]) . "\n";

        // 2. 第二个不同长模式（300 个 'b'）：验证不同长模式在缓存中共存
        $longLit2 = "/" . str_repeat("b", 300) . "/";
        $target2  = str_repeat("b", 300);
        $m2 = preg_match($longLit2, $target2);
        echo "2. second-pattern: len=" . strlen($m2[0]) . "\n";

        // 3. 重复命中：再次用第一个长模式，验证缓存命中路径不返回错误结果
        $m3 = preg_match($longLit, $target);
        echo "3. repeat-hit: " . (strlen($m3[0]) == 300 ? "ok" : "FAIL") . "\n";

        // 4. 多个不同长模式不互相干扰（验证缓存 slot 隔离）
        //    AX 和 AY 共享前缀 300 个 'a'，但末尾不同；修复前 key 截断可能冲突
        $patAX = "/" . str_repeat("a", 300) . "X/";
        $patAY = "/" . str_repeat("a", 300) . "Y/";
        $patBY = "/" . str_repeat("b", 300) . "Y/";
        $rAX = count(preg_match($patAX, str_repeat("a", 300) . "X"));
        $rAY = count(preg_match($patAY, str_repeat("a", 300) . "X")); // 不应匹配
        $rBY = count(preg_match($patBY, str_repeat("b", 300) . "Y"));
        echo "4. distinct: AX=" . $rAX . " AY=" . $rAY . " BY=" . $rBY . "\n";

        echo "\n=== All passed ===\n";
    }
}
