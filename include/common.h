#pragma once

// ============================================================
// common.h — 总入口
//     引入 include/ 下全部头文件，生成的 C 代码只需 #include "common.h"
// ============================================================

#include "types.h"
#include "val.h"
#include "compat.h"            // math.h + 跨编译器 math 声明
#include "object/object.h"     // tp_obj_alloc/release — runtime.h 需要
#include "array.h"             // arr_freelist/tphp_fn_arr_* — runtime.h 需要
#include "runtime.h"           // tphp_fn_error 定义在此
#include "rand.h"               // CSPRNG (builtin.h 需要)
#include "builtin.h"
#include "tphp_math.h"         // TinyPHP math 扩展
#include "conv.h"
#include "hash.h"
#include "object/exception.h"
#include "object/try.h"
#include "os/times.h"
#include "os/json.h"
#include "os/file.h"
#include "os/password.h"        // password_hash / password_verify
#include "phpc.h"