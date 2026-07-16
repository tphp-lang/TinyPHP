<?php
// 测试 phpc 自动内存管理
// 验证:phpc_auto() 注册 C 指针,phpc_arr_int/dbl 自动注册,无需手动 phpc_free
// 覆盖:自动注册 + 手动释放 + double-free 防护 + 多指针注册
#include "include/demo.h"

#debug === Auto Mem Test ===
#debug
#debug 1. phpc_auto copy: 6
#debug 2. arr_int no-free: 10
#debug 3. arr_dbl no-free: 6
#debug 4. manual free ok: 0
#debug
#debug === Double-Free Safety ===
#debug
#debug 5. free after free: 0
#debug 6. multi auto: 6
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== Auto Mem Test ===\n\n";

        // 1. phpc_auto: 包装 C->copy_ints 返回的 malloc 指针,自动注册
        //    无需手动 free,程序结束/异常时自动释放
        C.int* $src = phpc_arr_int([1, 2, 3]);
        C.int* $copy = phpc_auto(C->copy_ints($src, 3));
        $sum1 = php_int(C->sum_ints($copy, 3));
        echo "1. phpc_auto copy: " . $sum1 . "\n";

        // 2. phpc_arr_int 自动注册:不再需要手动 phpc_free
        //    (旧代码需 phpc_free($data),现在可省略)
        C.int* $data = phpc_arr_int([1, 2, 3, 4]);
        $sum2 = php_int(C->sum_ints($data, 4));
        echo "2. arr_int no-free: " . $sum2 . "\n";

        // 3. phpc_arr_dbl 同样自动注册
        C.double* $dbl = phpc_arr_dbl([1.0, 2.0, 3.0]);
        $sum3 = C->sum_dbls($dbl, 3);
        echo "3. arr_dbl no-free: " . (int)$sum3 . "\n";

        // 4. 仍可手动 phpc_free(会先注销注册,防 double-free)
        //    phpc_free 后变量自动置 NULL
        phpc_free($data);
        echo "4. manual free ok: " . ($data === null ? 0 : 1) . "\n";

        // ── double-free 防护 ──
        echo "\n=== Double-Free Safety ===\n\n";

        // 5. phpc_free 后再 phpc_free 不会崩溃(变量已置 NULL,free(NULL) 安全)
        //    且 tphp_rt_unregister 已注销,不会 double-free
        phpc_free($data);  // data 已是 NULL,free(NULL) 安全
        echo "5. free after free: " . ($data === null ? 0 : 1) . "\n";

        // 6. 多个 phpc_auto 注册不同指针,全部自动释放
        C.int* $a1 = phpc_auto(C->copy_ints(phpc_arr_int([1]), 1));
        C.int* $a2 = phpc_auto(C->copy_ints(phpc_arr_int([2]), 1));
        C.int* $a3 = phpc_auto(C->copy_ints(phpc_arr_int([3]), 1));
        $total = php_int(C->sum_ints($a1, 1))
               + php_int(C->sum_ints($a2, 1))
               + php_int(C->sum_ints($a3, 1));
        echo "6. multi auto: " . $total . "\n";

        echo "\n=== All passed ===\n";
    }
}
