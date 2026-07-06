#pragma once
// cconst.h — C->CONST 测试用头文件
//   演示从 PHP 通过 C->NAME 语法访问 C 枚举值和宏常量

// C 枚举
typedef enum {
    COLOR_RED   = 0,
    COLOR_GREEN = 1,
    COLOR_BLUE  = 2,
} Color;

// C 宏常量
#define MAX_SIZE     1024
#define BUFFER_LEN  256
#define PI_APPROX    314
