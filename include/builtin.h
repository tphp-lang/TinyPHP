#pragma once

// builtin.h — TinyPHP 内置函数总入口
// 恢复到拆分 std/ 前的状态 (原 1500 行, 68/69 TCC 通过)
// Phase1/2 新增函数在 builtin_extra.h, CodeGenerator 按需引入

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include <ctype.h>
#include "types.h"

#include "std/core.h"
