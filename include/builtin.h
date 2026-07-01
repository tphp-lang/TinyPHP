#pragma once

// builtin.h — TinyPHP 内置函数总入口
//   所有函数已按 PHP ext/ 结构拆分到 include/std/ 下
//   本文件仅作兼容层，逐个 include 各模块

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
