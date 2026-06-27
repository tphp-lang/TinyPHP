#pragma once

// ============================================================
// common.h — 总入口
//     引入 include/ 下全部头文件，生成的 C 代码只需 #include "common.h"
// ============================================================

#include "types.h"
#include "val.h"
#include "compat.h"
#include <math.h>              // 系统 math.h，runtime.h/object.h 等需要 pow/ceil/floor
#include "runtime.h"           // tphp_fn_error 定义在此，必须在 array/math 之前
#include "object/object.h"
#include "array.h"
#include "builtin.h"
#include "math.h"              // TinyPHP math 扩展
#include "conv.h"
#include "hash.h"
#include "object/exception.h"
#include "object/try.h"
#include "os/times.h"
#include "os/json.h"
#include "os/file.h"
#include "os/pcntl.h"
#include "os/posix.h"
#include "rand.h"
#include "phpc.h"