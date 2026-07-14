#pragma once

// builtin.h — TinyPHP 内置函数总入口
// 核心函数在 std/core.h (output/type/string/array_core/ctrl)
// 数学函数在 std/math.h
// Phase1/2 函数在 builtin_extra.h, CodeGenerator 按需引入

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include <ctype.h>
#include "types.h"

#include "std/core.h"
#include "std/math.h"
