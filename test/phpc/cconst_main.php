<?php
#debug int(0)
#debug int(1)
#debug int(2)
#debug int(1024)
#debug int(256)
#debug int(314)
#debug int(1)
#debug int(1026)

#include "include/cconst.h"

class Main {
    public function main(): void {
        // C->ENUM_VALUE — 直接引用 C 枚举值
        var_dump(C->COLOR_RED);
        var_dump(C->COLOR_GREEN);
        var_dump(C->COLOR_BLUE);

        // C->MACRO — 直接引用 C #define 宏
        var_dump(C->MAX_SIZE);
        var_dump(C->BUFFER_LEN);
        var_dump(C->PI_APPROX);

        // 在表达式中使用
        $red = C->COLOR_RED;
        $green = C->COLOR_GREEN;
        var_dump($red + $green);

        $max = C->MAX_SIZE;
        var_dump($max + 2);
    }
}
