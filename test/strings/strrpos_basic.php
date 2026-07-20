<?php
// strrpos_basic.php — strrpos / stripos / strripos 基础测试
//   对应 PHP 内置字符串查找函数（从右往左 + 大小写不敏感版本）
//   tphp 差异：
//     1. 未找到返回 int(-1)（非 bool(false)）— 与 strpos 一致
//     2. 无 $offset 第三参数
//     3. stripos/strripos 仅 ASCII A-Z/a-z 折叠（非 Unicode case-folding）
//
// 字符位置索引参考（"hello world"）:
//   h=0 e=1 l=2 l=3 o=4 ' '=5 w=6 o=7 r=8 l=9 d=10
#debug === strrpos ===
#debug int(7)
#debug int(9)
#debug int(-1)
#debug int(6)
#debug int(0)
#debug int(2)
#debug int(1)
#debug int(5)
#debug int(0)
#debug int(-1)
#debug int(4)
#debug
#debug === stripos ===
#debug int(6)
#debug int(4)
#debug int(-1)
#debug int(0)
#debug int(0)
#debug int(0)
#debug int(-1)
#debug int(0)
#debug int(1)
#debug
#debug === strripos ===
#debug int(7)
#debug int(9)
#debug int(-1)
#debug int(2)
#debug int(5)
#debug int(0)
#debug int(2)
#debug int(3)
#debug
#debug === OK ===

class Main
{
    public function main(): void
    {
        // ═══ strrpos: 大小写敏感，从右往左 ═══
        echo "=== strrpos ===\n";
        var_dump(strrpos("hello world", "o"));      // 7 (last 'o')
        var_dump(strrpos("hello world", "l"));      // 9 (last 'l')
        var_dump(strrpos("hello world", "x"));      // -1 (not found)
        var_dump(strrpos("hello world", "world"));  // 6
        var_dump(strrpos("hello world", "hello"));  // 0
        var_dump(strrpos("aaa", "a"));              // 2 (last 'a')
        var_dump(strrpos("aaa", "aa"));             // 1 (last "aa" starts at 1)
        var_dump(strrpos("hello", ""));             // 5 (empty needle → haystack.length)
        var_dump(strrpos("", ""));                  // 0 (empty haystack + needle)
        var_dump(strrpos("", "a"));                 // -1 (haystack too short)
        var_dump(strrpos("ababab", "ab"));          // 4 (last "ab" starts at 4)

        // ═══ stripos: 大小写不敏感，从左往右 ═══
        echo "\n=== stripos ===\n";
        var_dump(stripos("Hello World", "world"));  // 6 (case-insensitive, "World" matches "world")
        var_dump(stripos("Hello World", "o"));      // 4 (first 'o' at index 4)
        var_dump(stripos("Hello World", "x"));      // -1
        var_dump(stripos("aaa", "A"));              // 0 (case-insensitive, first 'a')
        var_dump(stripos("Hello", ""));             // 0 (empty needle → 0)
        var_dump(stripos("", ""));                  // 0
        var_dump(stripos("", "a"));                 // -1
        var_dump(stripos("aAa", "A"));              // 0 (case-insensitive, first 'a' at 0)
        var_dump(stripos("xaBc", "AB"));            // 1 (case-insensitive, "aB" matches "AB")

        // ═══ strripos: 大小写不敏感，从右往左 ═══
        echo "\n=== strripos ===\n";
        var_dump(strripos("Hello World", "o"));     // 7 (last 'o')
        var_dump(strripos("Hello World", "l"));     // 9 (last 'l')
        var_dump(strripos("Hello World", "x"));     // -1
        var_dump(strripos("aaa", "A"));             // 2 (case-insensitive, last 'a')
        var_dump(strripos("hello", ""));            // 5 (empty needle → haystack.length)
        var_dump(strripos("", ""));                 // 0
        var_dump(strripos("aAa", "A"));             // 2 (case-insensitive, last 'a' at 2)
        var_dump(strripos("aBcAbC", "AB"));         // 3 (case-insensitive, "Ab" at index 3 matches "AB")

        echo "\n=== OK ===\n";
    }
}
