<?php
// 测试宏优化的 phpc 纯透传函数
// 验证:c_int/c_void_ptr/php_int 等转为 #define 宏后功能正常
//   参考 vlang:简单表达式用宏,复杂逻辑保持 static inline
#include "include/demo.h"
#include <string.h>

#debug === Macro Bridge Test ===
#debug
#debug 1. c_int(42): 42
#debug 2. php_int(100): 100
#debug 3. c_void_ptr: 1
#debug 4. macro in expr: 50
#debug 5. c_str + php_str: hello
#debug 6. c_int as C arg: 14400
#debug 7. nested macros: 15
#debug 8. const fold: 100
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== Macro Bridge Test ===\n\n";

        // 1. c_int 宏:PHP t_int → C int32_t
        $i = c_int(42);
        echo "1. c_int(42): " . php_int($i) . "\n";

        // 2. php_int 宏:C int32_t → PHP t_int
        $pi = php_int(100);
        echo "2. php_int(100): " . $pi . "\n";

        // 3. c_void_ptr 宏:透传 void*
        //    用 defer 注册清理，函数退出时自动 free（零运行时开销，编译期展开）
        C.void* $buf = C->malloc(8);
        defer C->free($buf);
        C.void* $vp = c_void_ptr($buf);
        $nonNull = 0;
        if ($vp != null) { $nonNull = 1; }
        echo "3. c_void_ptr: " . $nonNull . "\n";

        // 4. 宏在表达式中(多次求值安全性)
        $x = 10;
        $y = 40;
        echo "4. macro in expr: " . (c_int($x) + php_int(c_int($y))) . "\n";

        // 5. c_str(保持 inline,返回 const char*) + php_str(保持 inline)
        //    c_str 主要用于传给 C 函数,不存储为变量
        $s = "hello";
        C->strcpy($buf, c_str($s));
        $back = php_str($buf);
        echo "5. c_str + php_str: " . $back . "\n";

        // 6. c_int 作为 C 函数参数
        $n = 120;
        $sq = php_int(C->int_square(c_int($n)));
        echo "6. c_int as C arg: " . $sq . "\n";

        // 7. 嵌套宏调用(内层宏结果作为外层参数)
        $nested = php_int(c_int(php_int(c_int(15))));
        echo "7. nested macros: " . $nested . "\n";

        // 8. 宏与常量折叠(TCC 应能在编译期计算)
        $fold = c_int(50) + c_int(50);
        echo "8. const fold: " . php_int($fold) . "\n";

        // $buf 由 defer C->free($buf) 在函数退出时自动释放，无需手动 free

        echo "\n=== All passed ===\n";
    }
}
