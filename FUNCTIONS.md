# TinyPHP 内置函数参考

> 与 PHP 标准库对照，含实现差异、性能说明。

---

## 快速定位

| 类别 | 函数数 | 跳转 |
|---|---|---|
| 输出/调试 | 4 | [↓](#输出--调试) |
| 类型检测 | 12 | [↓](#类型检测) |
| 类型转换 | 10 | [↓](#类型转换) |
| 数组 | 44 | [↓](#数组) |
| 字符串 | 38 | [↓](#字符串) |
| 数学 | 13 | [↓](#数学) |
| 进制转换 | 7 | [↓](#进制转换) |
| 哈希 | 3 | [↓](#哈希) |
| 文件 I/O | 2 | [↓](#文件-io) |
| 时间 | 9 | [↓](#时间) |
| JSON | 2 | [↓](#json) |
| 随机数 | 2 | [↓](#随机数) |
| 环境/类型 | 3 | [↓](#环境--类型) |
| 进程控制 | 7 | [↓](#进程控制-pcntl) |
| POSIX 系统 | 14 | [↓](#posix-系统) |
| 异常 | 4 | [↓](#异常) |
| OOP 语法 | 10 | [↓](#oop-语法) |
| C 互操作 | 24 | [↓](#c-互操作-phpc) |
| 断言 | 5 | [↓](#断言测试框架用) |
| **合计** | **180** | |
| | | [↓](#待实现--暂缓) |

---

## 输出 / 调试

| 函数 | C 实现 | 性能 | 差异 |
|---|---|---|---|
| `echo $x` | `tphp_fn_echo(t_string)` → `printf` | ≈PHP | — |
| `var_dump($x)` | type switch → `printf` | ≈PHP | 对象输出 `{}`，不递归属性 |

---

## 类型检测

所有检测编译期静态类型时为**直接字面量**（`true`/`false`），零运行时开销。

| 函数 | AOT 优化 |
|---|---|
| `is_int($x)` | `$x` 类型固定为 `t_int` → 编译期 `true` |
| `is_float/is_string/is_bool/is_array/is_null/is_object` | 同上 |
| `is_callable($x)` | 闭包类型 → `true` |
| `is_numeric($s)` | null-terminated 副本 + `strtoll`/`strtod` 扫描 |
| `isset($var)` | 非指针类型 → `true`；指针 → `ptr != NULL` |
| `empty($var)` | 按类型分发：int→`==0`，string→`strlen==0`，float/bool 同 |
| `unset($var)` | 对象 → `tp_obj_release`；数组 → `free` |

---

## 类型转换

| 函数 | C 实现 | 性能 |
|---|---|---|
| `intval($x)` | type switch → cast | O(1) |
| `floatval($x)` | type switch → cast | O(1) |
| `strval($x)` | `tphp_rt_str_from_int/float` | O(1) |
| `boolval($x)` | PHP 假值规则 | O(1) |
| `c_int/c_float/c_str` | PHP → C 类型（PHPC 桥接） | O(1) |
| `php_int/php_float/php_str` | C → PHP 类型（PHPC 桥接） | O(1) |

---

## 数组

所有数组函数通过 `include/array.h` 实现。数组为 `t_array*` 指针（128 槽 LIFO 复用池 + 1.5× 增长因子）。新增 `cursor` 字段（4 字节）支持内部指针操作。

| 函数 | C 实现 | 时间 | 差异 |
|---|---|---|---|
| `count($arr)` | `a->length` | O(1) | — |
| `array_push($arr, $v)` | 追加 entry + grow | O(1) amort. | — |
| `array_pop($arr)` | 取最后一个 entry | O(1) | — |
| `array_shift($arr)` | `memmove` 左移 | O(n) | — |
| `array_unshift($arr, $v)` | `memmove` 右移 + re-key | O(n) | 仅单值 |
| `in_array($v, $arr)` | 线性遍历比较 | O(n) | — |
| `array_search($v, $arr)` | 线性遍历比较 | O(n) | 未找到返回 -1 |
| `array_key_exists($k, $arr)` | 遍历 key | O(n) | — |
| `array_keys($arr)` | 遍历提取 key → 新数组 | O(n) | — |
| `array_values($arr)` | 遍历提取 value → 新数组 | O(n) | — |
| `array_merge($a, $b)` | 逐 entry 复制 | O(n+m) | — |
| `array_sum($arr)` | 遍历累加，int+float 自动提升 | O(n) | — |
| `array_product($arr)` | 遍历累乘，混合提升 | O(n) | — |
| `array_unique($arr)` | ≤16 元素 O(n²)；>16 用开放寻址哈希表 | O(n) 大数组 | — |
| `array_reverse($arr, $pk?)` | 倒序复制 | O(n) | — |
| `array_slice($arr, $off, $len?, $pk?)` | 截取复制 | O(k) | 不支持负 length |
| `array_fill($start, $count, $v)` | `set_int` 填充 | O(n) | — |
| `sort($arr)` | libc `qsort` 原地升序 | O(n log n) | 仅 int/float |
| `rsort($arr)` | `qsort` 降序 | O(n log n) | 仅 int/float |
| `shuffle($arr)` | Fisher-Yates 原地洗牌 | O(n) | — |
| `range($start, $end, $step?)` | 预知长度一次分配 | O(n) | step=0 → error |
| `max($arr)` / `min($arr)` | 遍历比较 | O(n) | 空数组 → error |
| `array_key_first($a)` | `len>0 ? 0 : -1` | O(1) | — |
| `array_key_last($a)` | `len>0 ? len-1 : -1` | O(1) | — |
| `array_rand($a)` | `rand(0, len-1)` | O(1) | — |
| `array_is_list($a)` | 遍历检查 key=0,1,2... | O(n) | — |
| `current($a)` | `entries[cursor].val` | O(1) | — |
| `key($a)` | `entries[cursor].key` | O(1) | — |
| `next($a)` / `prev($a)` | `cursor++` / `cursor--` | O(1) | 越界返回 null |
| `end($a)` / `reset($a)` | `cursor=len-1` / `cursor=0` | O(1) | — |
| `array_chunk($a, $size)` | 按 size 切片为子数组 | O(n) | — |
| `array_combine($k, $v)` | keys + values → 新数组 | O(n) | 长度不等→error |
| `array_flip($a)` | key↔value 互换 | O(n) | 重复值覆盖 |
| `array_column($a, $col)` | 提取指定 key 的列 | O(n×m) | 仅支持 string 列名 |
| `ksort($a)` / `krsort($a)` | qsort 指针排序，按键 | O(n log n) | 仅 int key |
| `asort($a)` / `arsort($a)` | qsort 指针排序，按值保键 | O(n log n) | 仅 int val |

---

## 字符串

字符串为 16 字节值类型 `{ char* data; int length; }`。≤512B 通过 64KB bump allocator 分配，零 `malloc`。
**拼接优化**：3+ 片段 `.` 链编译期展平为 ROPE，单次分配替代 N 次 pair-wise。

| 函数 | C 实现 | 性能 | 差异 |
|---|---|---|---|
| `implode($glue, $arr)` | 两遍扫描 + 一次 memcpy | O(n) | 防溢出 8MB 上限 |
| `explode($sep, $s)` | 先数分隔符→精确容量 + 零 realloc | O(n) | — |
| `strlen($s)` | `s.length` | O(1) | null → 0 |
| `trim($s)` | 首尾遍历 → 无空白时零分配返回原串 | O(n) | 仅 ASCII 空白 |
| `ltrim($s)` / `rtrim($s)` | 遍历 → 无空白时零分配 | O(n) | 同上 |
| `substr($s, $off, $len?)` | 偏移截取 → 全复制时零分配返回原串 | O(1) | 负 offset ✅ / 负 length ✅ |
| `strpos($h, $n)` | `memcmp` 线性查找 | O(n) | 未找到 → -1 |
| `str_contains($h, $n)` | `strpos ≥ 0` | O(n) | — |
| `str_replace($s, $r, $t)` | 两遍扫描 + `str_pool_alloc` | O(n) | 仅字符串参数 |
| `sprintf($fmt, ...)` | `snprintf(NULL,0,...)` 测大小 → `str_pool_alloc` | O(n) | 无长度上限，全 C 格式符 |
| `strtolower($s)` | 逐字符检测 → 无大写时零分配返回原串 | O(n) | 仅 ASCII |
| `strtoupper($s)` | 逐字符检测 → 无小写时零分配返回原串 | O(n) | 仅 ASCII |
| `ord($s)` / `chr($n)` | `(unsigned char)s[0]` / 单字符 string | O(1) | — |
| `str_starts_with($h,$n)` | 单次 `memcmp` 前缀 | O(len(n)) | — |
| `str_ends_with($h,$n)` | 单次 `memcmp` 后缀 | O(len(n)) | — |
| `is_numeric($s)` | null-terminated 副本 + `strtoll/strtod` 扫描 | O(n) | — |
| `ucfirst($s)` | 首字符 toupper → 无变化时零分配 | O(1) | 仅 ASCII |
| `lcfirst($s)` | 首字符 tolower → 无变化时零分配 | O(1) | 仅 ASCII |
| `strrev($s)` | 倒序复制到新字符串 | O(n) | — |
| `str_repeat($s, $n)` | 一次分配 + 循环 memcpy | O(len×n) | 上限 4MB |
| `str_split($s, $n?)` | 逐段切片 → 数组 | O(n) | 默认 chunk=1 |
| `str_pad($s, $len, $pad?, $type?)` | 计算填充 + memcpy | O(len) | type: 0=RIGHT 1=LEFT 2=BOTH |
| `substr_count($h, $n)` | 线性遍历 memcmp 计数 | O(n) | 不重叠统计 |
| `str_shuffle($s)` | Fisher-Yates 洗牌 | O(n) | — |
| `addslashes($s)` | 两遍扫描：数转义 → 一次分配 + memcpy | O(n) | 无转义时零分配 |
| `stripslashes($s)` | 两遍扫描：解析转义 | O(n) | 无转义时零分配 |
| `bin2hex($s)` | 查表 `0-9a-f` → 双倍输出 | O(n) | — |
| `hex2bin($s)` | 每 2 字符解码为 1 字节 | O(n) | 奇数长度忽略 |
| `urlencode($s)` | 非安全字符 → `%XX` | O(n) | 全安全时零分配 |
| `urldecode($s)` | `%XX`→字符 + `+`→空格 | O(n) | 无变换时零分配 |
| `strtr($s,$from,$to)` | 查表翻译（128 字符） | O(n) | 仅 3 参形式 |
| `parse_url($u)` | URL 解析 → 关联数组 | O(n) | scheme/host/port/path/query |
| `parse_str($s)` | query string → 关联数组 | O(n) | `%XX` 和 `+` 解码 |

---

## 数学

| 函数 | C 实现 | 性能 |
|---|---|---|
| `abs($x)` | `llabs(x)` | O(1) |
| `round($x)` | libc `round()` / TCC 自研 fallback | O(1) |
| `ceil($x)` | libc `ceil()` | O(1) |
| `floor($x)` | libc `floor()` | O(1) |
| `sqrt($x)` | libc `sqrt(x)`，x<0 → 0 | O(1) |
| `pow($base, $exp)`（`**` 运算符） | `tphp_rt_pow_int` 循环 / `pow()` 浮点 | O(log n) |
| `pi()` | `M_PI` 常量 | O(1) |
| `deg2rad($d)` / `rad2deg($r)` | `d * M_PI / 180` | O(1) |
| `intdiv($a, $b)` | `a / b`，零除→error | O(1) |
| `rand($min, $max)` | libc `rand()` LCG | O(1) |
| `mt_rand($min, $max)` | **MT19937** 真 Mersenne Twister | O(1) |

---

## 进制转换

全栈分配，零堆分配。进制转换直接调用 libc `strtoll`/`snprintf`。

| 函数 | C 实现 | 性能 |
|---|---|---|
| `bindec($s)` | `strtoll(s, NULL, 2)` | O(1) |
| `hexdec($s)` | `strtoll(s, NULL, 16)` | O(1) |
| `octdec($s)` | `strtoll(s, NULL, 8)` | O(1) |
| `decbin($n)` | 位反转 + 栈缓冲 | O(log n) |
| `decoct($n)` | `snprintf "%llo"` | O(1) |
| `dechex($n)` | `snprintf "%llx"` | O(1) |
| `number_format($n, $d?)` | 自研千分位分组 + 四舍五入 | O(log n) |

---

## 哈希

全部零堆分配，纯 C 算法实现（RFC 1321 / FIPS 180-4 / 查表法）。

| 函数 | C 实现 | 性能 |
|---|---|---|
| `md5($s)` | RFC 1321，输出 32 位 hex 字符串 | O(n) |
| `sha1($s)` | FIPS 180-4，输出 40 位 hex 字符串 | O(n) |
| `crc32($s)` | 256 项查表法，返回 int | O(n) |

---

## 文件 I/O

| 函数 | C 实现 | 内存安全 | 差异 |
|---|---|---|---|
| `file_get_contents($path)` | `fopen("rb")` → `fseek/ftell` → `str_pool_alloc` → `fread` → `fclose` | ✅ 配对 | 静态路径，不存在返回空 |
| `file_put_contents($path, $data)` | `fopen("wb")` → `fwrite` → `fclose` | ✅ 配对 | 覆盖写入 |

---

## 时间

| 函数 | C 实现 | 性能 |
|---|---|---|
| `time()` | `time(NULL)` | O(1) |
| `date($fmt)` | `strftime` + 64B 栈缓冲 | O(1) |
| `sleep($s)` | `sleep(s)` | O(s) |
| `usleep($us)` | `usleep(us)` | O(us) |
| `hrtime()` | `QueryPerformanceCounter`(Win) / `clock_gettime`(Unix) | O(1) |
| `microtime()` | 同上，返回 float 秒 | O(1) |
| `mktime($h,$m,$s,$mo,$d,$y)` | 日历天数累加法，零外部依赖 | O(year-1970) |
| `strtotime($s)` | 解析 `Y-m-d H:i:s` + mktime 计算 | O(n) |
| `uniqid($prefix?)` | `sprintf "%08lx%05lx", time, rand` | O(1) |

---

## JSON

| 函数 | C 实现 | 内存安全 | 差异 |
|---|---|---|---|
| `json_encode($var)` | 位图转义(256bit O(1)) + 批量安全字符 memcpy → `str_pool_alloc` | ✅ | 对象 → `{}`，无递归保护 |
| `json_decode($s)` | 递归下降解析 → `t_var` | ✅ 无效→error | 无 `assoc` 参数 |

---

## 随机数

| 函数 | 算法 | 周期 | 线程安全 |
|---|---|---|---|
| `rand($min, $max)` | libc LCG | 2^31 | ❌ |
| `mt_rand($min, $max)` | **MT19937** | 2^19937-1 | ❌ |

---

## 环境 / 类型

| 函数 | C 实现 | 说明 |
|---|---|---|
| `gettype($v)` | type switch → 字符串常 | 类型名为 PHP 风格 (`"int"/"float"/...`) |
| `getenv($k)` | libc `getenv()` + 静态缓冲 | Windows/Linux 通用 |
| `putenv($s)` | libc `putenv()` | `KEY=VALUE` 格式 |

---

## 进程控制 (pcntl)

POSIX 专属（Windows 调用触发 `tphp_fn_error()` 退出）。按需引入：`#import pcntl`。

| 函数 | C 实现 | 说明 |
|---|---|---|
| `pcntl_fork()` | `fork()` | 子进程返回 0，父进程返回 PID，失败 -1 |
| `pcntl_waitpid($pid,&$st,$opt?)` | `waitpid()` | 等待指定子进程 |
| `pcntl_wait(&$st)` | `wait()` | 等待任意子进程 |
| `pcntl_exec($path)` | `execv()` | 执行新程序替换当前进程 |
| `pcntl_alarm($sec)` | `alarm()` | SIGALRM 闹钟 |
| `pcntl_get_last_error()` | `errno` | 获取 errno |
| `pcntl_strerror($no)` | `strerror()` | errno→错误消息 |

---

## POSIX 系统

POSIX 专属（Windows 调用触发 `tphp_fn_error()` 退出）。按需引入：`#import posix`。14 个常用函数。

| 函数 | C 实现 | 说明 |
|---|---|---|
| `posix_getpid()` / `getppid()` | `getpid()` / `getppid()` | 进程 ID |
| `posix_getuid()` / `geteuid()` | `getuid()` / `geteuid()` | 用户 ID |
| `posix_getgid()` / `getegid()` | `getgid()` / `getegid()` | 组 ID |
| `posix_getcwd()` | `getcwd()` + 栈缓冲 | 当前目录 |
| `posix_isatty($fd)` | `isatty()` | 是否终端 |
| `posix_kill($pid,$sig)` | `kill()` | 发信号 |
| `posix_strerror($no)` | `strerror()` | errno→消息 |
| `posix_get_last_error()` | `errno` | 获取 errno |
| `posix_ttyname($fd)` | `ttyname()` | 终端名 |
| `posix_uname()` | `uname()` → 关联数组 | sysname/nodename/release/version/machine |
| `posix_times()` | `times()` → 关联数组 | ticks/utime/stime/cutime/cstime |

---

## 异常

| 语法 | C 实现 | 内存安全 |
|---|---|---|
| `try { ... } catch (Exception $e) { ... }` | `setjmp/longjmp` | ✅ `tp_throw` 先 `tphp_rt_free_all()` |
| `finally { ... }` | `TP_FINALLY` 宏 | ✅ 始终执行 |
| `throw new Exception("msg")` | `tp_throw_ex` → 复制消息到 256B 栈缓冲 → `longjmp` | ✅ |
| `throw "string"` | `tp_throw` → `longjmp` | ✅ |

---

## OOP 语法

| 语法 | 实现 | 说明 |
|---|---|---|
| `class B extends A` | COS struct 嵌套 `_parent` | 属性/方法通过父类链解析 |
| `abstract class` | 禁止 `new`，抽象方法无体 | — |
| `interface` | 纯抽象类，不生成 struct | 编译期类型标记 |
| `implements` | 编译期契约 | 不强制检查方法实现 |
| `trait` + `use TraitName` | 方法扁平化 | — |
| `instanceof` | `tp_obj_is_a(obj, &_class_X)` | 遍历类链 |
| `parent::method()` | `&self->_parent` + 父类函数名 | — |
| `__CLASS__` | 编译期字符串常量 | `$this->className` |
| `__METHOD__` | 编译期字符串常量 | `ClassName::methodName` |
| `__destruct` | 作用域结束自动 `tp_obj_release` | 池回收，零 free |

---

## C 互操作 (PHPC)

| 函数 | 方向 | 说明 |
|---|---|---|
| `c_int($x)` | PHP → C | → `int32_t` |
| `c_float($x)` | PHP → C | → `double` |
| `c_str($s)` | PHP → C | → `const char*` |
| `php_int($x)` | C → PHP | → `t_int` |
| `php_float($x)` | C → PHP | → `t_float` |
| `php_str($s)` | C → PHP | → `t_string`（深拷贝） |
| `C->func(args)` | 直接 C 调用 | 无 name mangling |
| `#include "file.h"` | 预处理器 | 生成 `#include` |
| `#flag [CC] [OS] flags` | 预处理器 | 平台+编译器过滤 |
| `#callback type name(params)` | 预处理器 | 声明 C 回调签名 |
| `phpc_arr_int/dbl/str` | PHP→C | 严格类型检查，malloc |
| `phpc_new_arr_int/dbl/str` | C→PHP | 深拷贝 |
| `phpc_obj` | PHP→C | `void*` 对象指针 |
| `phpc_fn($cb)` | 提取 | `cb.func` → `void*` |
| `phpc_env($cb)` | 提取 | `cb.env` → `void*` |
| `phpc_fn_i32/i64/f64` | 类型化 cast | → C 函数指针 |
| `phpc_thunk('name', $fn)` | no-env 回调 | 按 #callback 生成 thunk |
| `phpc_free(ptr)` | 释放 | `free(ptr)` |

---

## 内存安全总览

| 机制 | 说明 |
|---|---|
| 资源追踪链表 | `tphp_rt_register(ptr, type)` 注册 → `error()` 时遍历释放 |
| 64KB 字符串池 | bump allocator，≤512B 零 `malloc`，非池化走 `malloc` |
| 128 槽数组池 | LIFO 复用，1.5× 增长因子，cursor 自动重置 |
| 128 槽对象池 | LIFO 复用，`tp_obj_release` 回收到池 |
| COS refcount | `tp_obj_retain` / `tp_obj_release`，归零 → `__destruct` → pool |
| scope 自动析构 | `visitMethod` 尾注入 `tp_obj_release(var)` |
| 异常安全 | `tp_throw` 先 `tphp_rt_free_all()` 再 `longjmp` |
| 分支预测 | `likely(x)` 热路径，`unlikely(x)` 错误边界 |
| JSON 安全 | 无效 JSON → `error()` → `tphp_rt_free_all()` → `exit(1)` |

---

## 编译器兼容

| 文件 | 说明 |
|---|---|
| `include/compat.h` | TCC 专属：`ceil/floor/sqrt/pow` extern 声明 + `round` fallback 实现 |
| `include/os/json.h` | TCC 专属：`isnan`/`isinf` 自研实现 |
| `include/conv.h` | TCC 专属：`_tphp_pow10` 循环替代 `pow()` |
| `include/val.h` | `typeof` 检查已 `#if defined(__GNUC__) \|\| defined(__clang__)` 守卫 |
| `tphp.php` | Win GCC `-Wno-implicit-function-declaration` 自动添加 |
| **设计原则** | 所有 TCC 特殊处理用 `#ifdef __TINYC__` 隔离，GCC/Clang 路径不受影响 |

---

## AOT 不可行的函数

以下 PHP 内置函数/特性依赖运行时解释器、动态符号表或 VM 机制，TinyPHP AOT 模式**永久不支持**：

### 动态代码执行

| 函数 | 原因 |
|------|------|
| `eval($code)` | 没有运行时解释器 |
| `assert($assertion)` | 字符串断言模式需要 eval |
| `create_function($args, $code)` | 内部调用 eval |

### 动态函数/方法调用

| 函数 | 原因 |
|------|------|
| `call_user_func($fn, ...)` | 编译时不知道函数名 |
| `call_user_func_array($fn, $args)` | 同上 |
| `forward_static_call($fn, ...)` | 动态静态调用 |
| `forward_static_call_array($fn, $args)` | 同上 |
| `$fn()` 可变函数调用 | 编译时不知道函数名 |
| `$obj->$method()` 可变方法调用 | 同上 |

### 运行时回调注册

| 函数 | 原因 |
|------|------|
| `register_shutdown_function($cb)` | 运行时动态注册 |
| `register_tick_function($cb)` | 依赖 VM tick 机制 |
| `set_error_handler($cb)` | 运行时动态回调 |
| `set_exception_handler($cb)` | 同上 |
| `restore_error_handler()` / `restore_exception_handler()` | 同上 |
| `pcntl_signal($sig, $cb)` | 依赖 PHP 回调 + VM tick |
| `pcntl_signal_dispatch()` / `pcntl_async_signals()` | 同上 |
| `ob_start($cb)` 输出缓冲 | 运行时状态机 |

### 变量变量 / 符号表内省

| 函数 | 原因 |
|------|------|
| `$$var` / `${expr}` | 编译时不知道变量名 |
| `compact($varname, ...)` | 依赖运行时符号表 |
| `extract($arr)` | 运行时创建变量 |
| `get_defined_vars()` | 运行时符号表遍历 |
| `get_object_vars($obj)` | 运行时内省 |
| `define($name, $value)` | 运行时常量定义 |

### 调用栈 / 反射

| 函数 | 原因 |
|------|------|
| `debug_backtrace()` | 运行时调用栈内省 |
| `debug_print_backtrace()` | 同上 |
| `func_get_args()` / `func_get_arg($n)` / `func_num_args()` | 编译时参数量固定 |
| `ReflectionClass` / `ReflectionMethod` 等 | 全系列运行时内省 |
| `method_exists($obj, $method)` | 运行时方法查找 |
| `property_exists($obj, $prop)` | 运行时属性查找 |
| `get_class_methods($class)` | 运行时内省 |
| `get_class_vars($class)` | 同上 |

---

## 暂缓（低频 / 可做但低优先级）

| 函数 | 原因 |
|------|------|
| `serialize` / `unserialize` | PHP 序列化格式完整解析器 |
| `Date* OO API` (30+) | 需完整 DateTime 类 |
| `array_intersect*` / `array_diff*` (14 个) | 使用频率极低 |
| `array_multisort` / `natsort` / `natcasesort` | 专用场景 |
| `usort` / `uasort` / `uksort` | 需闭包回调节省 |
| `array_filter` / `array_map` / `array_reduce` | 需闭包回调节省 |
| `sin/cos/tan` 等三角函数 | 直接 libc 调用，低优先级 |
| `sin/cos/tan` 等三角函数 | 直接 libc 调用，低优先级 |
