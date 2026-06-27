#pragma once

// ============================================================
// common.h — 总入口
//     引入 include/ 下全部头文件，生成的 C 代码只需 #include "common.h"
// ============================================================

#include "types.h"
#include "val.h"
#include "compat.h"
#include "object/object.h"
#include "array.h"
#include "runtime.h"
#include "builtin.h"
#include "math.h"
#include "conv.h"
#include "object/exception.h"
#include "object/try.h"
#include "os/times.h"
#include "os/json.h"
#include "os/file.h"
#include "rand.h"
#include "phpc.h"