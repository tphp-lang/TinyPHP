<?php
// 测试 (C.XXX) cast 语法 — 全覆盖 C 类型转换
//   验证:值类型(int/float/double/bool/char) + 指针类型(void*/char*)
//   + 定宽整数(int32/int64/uint32/uint64)
#include <stdio.h>
#include <stdlib.h>
#include <stdint.h>

#debug === C Cast Full Test ===
#debug
#debug 1. void* cast: 1
#debug 2. char* cast: hello
#debug 3. int cast: 65
#debug 4. float cast: 3.14
#debug 5. double cast: 2.71828
#debug 6. bool cast: 1
#debug 7. char cast: 66
#debug 8. int32 cast: 1000
#debug 9. int64 cast: 5000000000
#debug 10. uint32 cast: 4000000000
#debug 11. cast in expr: 130
#debug 12. cast chain: 1
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== C Cast Full Test ===\n\n";

        // 1. (C.void*) cast: 将 malloc 返回的 void* 显式标记
        C.void* $buf = C->malloc(16);
        C.void* $ptr = (C.void*)$buf;
        echo "1. void* cast: " . ($ptr == null ? 0 : 1) . "\n";
        C->free($ptr);

        // 2. (C.char*) cast: void* → char*，可用 php_str 读取
        C.void* $raw = C->malloc(8);
        C->strcpy($raw, c_str("hello"));
        C.char* $cp = (C.char*)$raw;
        echo "2. char* cast: " . php_str($cp) . "\n";
        C->free($raw);

        // 3. (C.int) cast: 明确 int 类型
        $c = (C.int)65;
        echo "3. int cast: " . $c . "\n";

        // 4. (C.float) cast: float 类型(注意 PHP 层为 t_float)
        $f = (C.float)3.14;
        echo "4. float cast: " . $f . "\n";

        // 5. (C.double) cast: double 类型
        $d = (C.double)2.71828;
        echo "5. double cast: " . $d . "\n";

        // 6. (C.bool) cast: bool 类型
        $b = (C.bool)1;
        echo "6. bool cast: " . ($b ? 1 : 0) . "\n";

        // 7. (C.char) cast: char 类型(作为 int 返回)
        $ch = (C.char)66;
        echo "7. char cast: " . $ch . "\n";

        // 8. (C.int32) cast: int32_t 定宽整数
        $i32 = (C.int32)1000;
        echo "8. int32 cast: " . $i32 . "\n";

        // 9. (C.int64) cast: int64_t 定宽整数(大数)
        $i64 = (C.int64)5000000000;
        echo "9. int64 cast: " . $i64 . "\n";

        // 10. (C.uint32) cast: uint32_t 无符号定宽整数
        $u32 = (C.uint32)4000000000;
        echo "10. uint32 cast: " . $u32 . "\n";

        // 11. cast 后参与运算(c_int 宏 + cast 混用)
        $a = (C.int)50;
        $b2 = (C.int)80;
        echo "11. cast in expr: " . ($a + $b2) . "\n";

        // 12. cast 链:void* → char* → php_str
        C.void* $mem = C->malloc(4);
        C->strcpy($mem, c_str("hi"));
        C.void* $vp = (C.void*)$mem;
        C.char* $cp2 = (C.char*)$vp;
        echo "12. cast chain: " . (php_str($cp2) == "hi" ? 1 : 0) . "\n";
        C->free($mem);

        echo "\n=== All passed ===\n";
    }
}
