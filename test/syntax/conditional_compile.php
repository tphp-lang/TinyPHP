<?php
// 条件编译测试：验证 #if/#elseif/#else/#endif 在顶层和函数体内的行为
//   测试用例本身使用 #if 条件编译生成环境相关的 #debug 预期值，
//   因此可在 Windows/Linux/macOS + TCC/GCC/Clang 任意组合下通过。
#debug === conditional compile ===

#if Windows
    #debug top_level=Windows
#elseif Linux
    #debug top_level=Linux
#else
    #debug top_level=Other
#endif

#if TCC
    #debug in_func=TCC
#elseif GCC
    #debug in_func=GCC
#else
    #debug in_func=Other
#endif

#if Windows
    #if TCC
        #debug nested=win_tcc
    #else
        #debug nested=win_other
    #endif
#else
    #debug nested=not_windows
#endif

#if Linux
    #debug not_hit=1
#else
    #debug not_hit=0
#endif

#if Windows && TCC
    #debug compound=1
#else
    #debug compound=0
#endif

#if !Linux
    #debug negation=1
#else
    #debug negation=0
#endif

#debug
#debug === done ===

class Main {
    public function main(): void {
        echo "=== conditional compile ===\n";

        // 顶层条件编译结果（通过函数体内 echo 输出）
        #if Windows
            echo "top_level=Windows\n";
        #elseif Linux
            echo "top_level=Linux\n";
        #else
            echo "top_level=Other\n";
        #endif

        // 函数体内条件编译
        #if TCC
            echo "in_func=TCC\n";
        #elseif GCC
            echo "in_func=GCC\n";
        #else
            echo "in_func=Other\n";
        #endif

        // 嵌套条件编译
        #if Windows
            #if TCC
                echo "nested=win_tcc\n";
            #else
                echo "nested=win_other\n";
            #endif
        #else
            echo "nested=not_windows\n";
        #endif

        // 非命中分支不执行
        $x = 0;
        #if Linux
            $x = 1;
        #endif
        echo "not_hit=" . $x . "\n";

        // 复合条件: Windows && TCC
        #if Windows && TCC
            echo "compound=1\n";
        #else
            echo "compound=0\n";
        #endif

        // 取反: !Linux
        #if !Linux
            echo "negation=1\n";
        #else
            echo "negation=0\n";
        #endif

        echo "\n=== done ===\n";
    }
}
