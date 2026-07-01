#pragma once

// builtin.h — TinyPHP 内置函数总入口
//   所有函数定义在 include/std/ 下（按 PHP ext/ 结构分类）
//   独立 include 给 GCC/Clang 最佳编译体验, TCC 也能处理

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include <ctype.h>
#include "types.h"

#include "std/output.h"
#include "std/type.h"
#include "std/string.h"
#include "std/html.h"
#include "std/array_core.h"
#include "std/array_extra.h"
#include "std/math.h"
#include "std/utf8.h"
#include "std/ctrl.h"
