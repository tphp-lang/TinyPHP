# TinyPHP 内置函数参考

> 按 PHP 扩展结构分类，含实现差异与性能说明。

---

## 总览

| PHP 扩展 | 对应 TinyPHP 文件 | 函数数 |
|----------|------------------|--------|
| `ext/standard` 输出 | `std/output.h` | 15 |
| `ext/standard` 类型 | `std/type.h` | 20 |
| `ext/standard` 字符串 | `std/string.h` | 32 |
| `ext/standard` HTML/Base64/URL | `std/html.h` | 6 |
| `ext/standard` 数组 | `array.h` + `std/array_extra.h` | 38 |
| `ext/standard` 数学 | `std/math.h` + `tphp_math.h` | 21 |
| `ext/standard` 进制转换 | `conv.h` | 8 |
| `ext/standard` 断言/随机 | `std/ctrl.h` | 5 |
| `ext/json` | `os/json.h` | 3 |
| `ext/hash` | `hash.h` | 5 |
| `ext/date` | `os/times.h` | 9 |
| `ext/ctype` | `std/ctrl.h` | 11 |
| `ext/mbstring` (UTF-8) | `std/utf8.h` | 3 |
| `ext/pcntl` | `ext/pcntl/` | 7 |
| `ext/posix` | `ext/posix/` | 14 |
| `ext/password` (bcrypt) | `os/password.h` | 2 |
| OOP / 异常 | `object/` | 14 |
| C 互操作 (PHPC) | `phpc.h` | 24 |
| **合计** | | **232+** |

---

## ext/standard — 输出函数

> 文件: `std/output.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `echo $x` | `fwrite(stdout)` | 二进制安全，不解析格式化符 |
| `var_dump($x)` | type switch → `fprintf` | 支持全部类型，对象输出 `{}` |
| `exit($code)` | `exit(code)` | — |
| `isset($var)` | 指针类型 → `ptr != NULL`；值类型 → 编译期 `true` | — |
| `empty($var)` | 按类型分发 | int→`==0`, string→`is_falsy`, float/bool 同 |

---

## ext/standard — 类型函数

> 文件: `std/type.h`

### 类型检测

| 函数 | AOT 优化 |
|------|---------|
| `is_int / is_float / is_string / is_bool` | 编译期静态类型 → 直接字面量 `true`/`false` |
| `is_array / is_null / is_object / is_callable` | 同上 |
| `is_numeric($s)` | null-terminated 副本 + `strtoll`/`strtod` 扫描 |
| `gettype($v)` | type switch → 字符串常量 (`"int"`/`"float"`...) |

### 类型转换

| 函数 | C 实现 | 性能 |
|------|--------|------|
| `intval($x)` | type switch → cast | O(1) |
| `floatval($x)` | type switch → cast | O(1) |
| `strval($x)` | `tphp_rt_str_from_int/float` | O(1) |
| `boolval($x)` | PHP 假值规则 | O(1) |

### 环境变量

| 函数 | C 实现 |
|------|--------|
| `getenv($k)` | libc `getenv()` + 静态缓冲 |
| `putenv($s)` | libc `putenv()` |

---

## ext/standard — 字符串函数

> 文件: `std/string.h`

字符串为 16 字节 SSO 值类型 `{ char* data; int length; bool is_local; }`。
≤512B 通过 128KB bump allocator 分配，零 `malloc`。
拼接优化：3+ 片段 `.` 链编译期展平为 ROPE，单次分配。

### 基础操作

| 函数 | C 实现 | 性能 | 差异 |
|------|--------|------|------|
| `strlen($s)` | `s.length` | O(1) | null → 0 |
| `trim($s)` | 首尾遍历 → 无空白时零分配 | O(n) | 仅 ASCII 空白 |
| `ltrim($s) / rtrim($s)` | 遍历 → 无空白时零分配 | O(n) | 同上 |
| `substr($s, $off, $len?)` | 偏移截取 → 全复制时零分配 | O(1) | 负 offset/len ✅ |
| `strpos($h, $n)` | `memcmp` 线性查找 | O(n) | 未找到 → -1 |
| `str_contains($h, $n)` | `strpos ≥ 0` | O(n) | — |
| `str_starts_with($h,$n)` | 单次 `memcmp` 前缀 | O(len(n)) | — |
| `str_ends_with($h,$n)` | 单次 `memcmp` 后缀 | O(len(n)) | — |
| `ord($s) / chr($n)` | `(unsigned char)s[0]` | O(1) | — |

### 转换 / 格式化

| 函数 | C 实现 | 性能 |
|------|--------|------|
| `strtolower($s)` | 逐字符 → 无大写时零分配 | O(n) |
| `strtoupper($s)` | 逐字符 → 无小写时零分配 | O(n) |
| `ucfirst($s) / lcfirst($s)` | 首字符 ±32 → 无变化时零分配 | O(1) |
| `sprintf($fmt, ...)` | `snprintf` 测大小 → `str_pool_alloc` | O(n) |
| `number_format($n, $d?)` | 自研千分位 + 四舍五入 | O(log n) |

### 搜索 / 替换

| 函数 | C 实现 | 性能 |
|------|--------|------|
| `str_replace($s, $r, $t)` | 两遍扫描 + `str_pool_alloc` | O(n) |
| `substr_count($h, $n)` | 线性 `memcmp` 计数 | O(n) |
| `strtr($s,$from,$to)` | 查表翻译 128 字符 | O(n) |

### 数组 ↔ 字符串

| 函数 | C 实现 | 性能 |
|------|--------|------|
| `implode($glue, $arr)` | 两遍扫描 + 一次 memcpy | O(n) |
| `explode($sep, $s)` | 先数分隔符→精确容量→零 realloc | O(n) |

### 工具函数

| 函数 | C 实现 | 性能 |
|------|--------|------|
| `str_repeat($s, $n)` | 一次分配 + 循环 memcpy | O(len×n) |
| `str_split($s, $n?)` | 逐段切片 → 数组 | O(n) |
| `str_pad($s, $len, $pad?, $type?)` | 计算填充 + memcpy | O(len) |
| `strrev($s)` | 倒序复制 | O(n) |
| `str_shuffle($s)` | Fisher-Yates 洗牌 | O(n) |
| `addslashes($s)` | 两遍扫描 → 无转义时零分配 | O(n) |
| `stripslashes($s)` | 两遍扫描 | O(n) |
| `bin2hex($s)` | 查表 `0-9a-f` → 双倍输出 | O(n) |
| `hex2bin($s)` | 每 2 字符解码为 1 字节 | O(n) |

---

## ext/standard — HTML / Base64 / URL

> 文件: `std/html.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `htmlspecialchars($s)` | 两趟法: 计长度→一次分配→memcpy | 转义 `& " ' < >` |
| `nl2br($s)` | 两趟法: 计换行数→一次分配 | `\n` → `<br>\n` |
| `base64_encode($s)` | 查找表法, 3→4 字符 | RFC 4648, 自动补 `=` |
| `base64_decode($s)` | 256B 逆查找表 | 跳过尾部 `=` |
| `urlencode($s)` | 非安全字符 → `%XX` | 全安全时零分配 |
| `urldecode($s)` | `%XX`→字符 + `+`→空格 | 无变换时零分配 |
| `parse_url($u)` | URL 解析 → 关联数组 | scheme/host/port/path/query |
| `parse_str($s)` | query string → 关联数组 | `%XX` 和 `+` 解码 |
| `http_build_query($arr)` | 遍历数组 + `urlencode` | key=value 用 `&` 连接 |

---

## ext/standard — 数组函数

> 文件: `array.h` + `std/array_extra.h`

数组为 `t_array*` 指针（128 槽 LIFO 复用池 + 1.5× 增长因子）。

### 增删 / 统计

| 函数 | C 实现 | 时间 |
|------|--------|------|
| `count($arr)` | `a->length` | O(1) |
| `array_push($arr,$v)` | 追加 entry + grow | O(1) |
| `array_pop($arr)` | 取最后一个 entry | O(1) |
| `array_shift($arr)` | `memmove` 左移 | O(n) |
| `array_unshift($arr,$v)` | `memmove` 右移 | O(n) |

### 查找 / 键操作

| 函数 | C 实现 | 时间 |
|------|--------|------|
| `in_array($v,$arr)` | 线性遍历比较 | O(n) |
| `array_search($v,$arr)` | 线性遍历比较 | O(n) |
| `array_key_exists($k,$arr)` | 遍历 key | O(n) |
| `array_keys($arr)` | 遍历提取 key → 新数组 | O(n) |
| `array_values($arr)` | 遍历提取 value → 新数组 | O(n) |
| `array_key_first($a)` | `len>0 ? 0 : -1` | O(1) |
| `array_key_last($a)` | `len>0 ? len-1 : -1` | O(1) |

### 合并 / 拆分

| 函数 | C 实现 | 时间 |
|------|--------|------|
| `array_merge($a,$b)` | 逐 entry 复制 | O(n+m) |
| `array_chunk($a,$size)` | 按 size 切片为子数组 | O(n) |
| `array_slice($a,$off,$len?)` | 截取复制 | O(k) |
| `array_combine($k,$v)` | keys+values → 新数组 | O(n) |

### 集合操作

| 函数 | C 实现 | 时间 |
|------|--------|------|
| `array_unique($a)` | ≤16 元素 O(n²)，>16 用开放寻址哈希 | O(n) |
| `array_diff($a1,$a2)` | 双重循环 int/string 值比较 | O(n×m) |
| `array_intersect($a1,$a2)` | 双重循环，取交集 | O(n×m) |
| `array_count_values($a)` | 遍历统计频次 | O(n) |
| `array_flip($a)` | key↔value 互换 | O(n) |

### 排序 / 随机

| 函数 | C 实现 | 时间 |
|------|--------|------|
| `sort($a) / rsort($a)` | libc `qsort` 原地 | O(n log n) |
| `ksort($a) / krsort($a)` | qsort 指针排序，按键 | O(n log n) |
| `asort($a) / arsort($a)` | qsort，按值保键 | O(n log n) |
| `shuffle($a)` | Fisher-Yates 原地洗牌 | O(n) |
| `array_rand($a,$n)` | Fisher-Yates 随机取键 | O(n) |

### 迭代器 / 填充 / 提取

| 函数 | C 实现 | 时间 |
|------|--------|------|
| `current($a) / key($a)` | `entries[cursor]` | O(1) |
| `next($a) / prev($a)` | `cursor++` / `cursor--` | O(1) |
| `end($a) / reset($a)` | `cursor = len-1` / `0` | O(1) |
| `range($lo,$hi,$step?)` | 预知长度一次分配 | O(n) |
| `array_fill($s,$c,$v)` | `set_int` 填充 | O(n) |
| `array_reverse($a)` | 倒序复制 | O(n) |
| `array_column($a,$k)` | 提取指定列 | O(n×m) |
| `max($a) / min($a)` | 遍历比较 | O(n) |
| `array_sum($a) / array_product($a)` | 遍历累加/乘 | O(n) |
| `array_is_list($a)` | 检查 key=0,1,2... | O(n) |

---

## ext/standard — 数学函数

> 文件: `std/math.h` + `tphp_math.h`

### 基础运算

| 函数 | C 等价 | 函数 | C 等价 |
|------|--------|------|--------|
| `abs($x)` | `llabs(x)` | `round($x)` | `round()` |
| `ceil($x)` | `ceil()` | `floor($x)` | `floor()` |
| `sqrt($x)` | `sqrt(x)` | `pow($b,$e)` | `pow()` |
| `pi()` | `M_PI` | `fmod($x,$y)` | `fmod()` |
| `deg2rad / rad2deg` | `* M_PI/180` | `intdiv($a,$b)` | `a/b` |

### 三角函数

| `sin($x)` | `cos($x)` | `tan($x)` |
| `asin($x)` | `acos($x)` | `atan($x)` |
| `sinh($x)` | `cosh($x)` | `tanh($x)` |

### 指数/对数

| `exp($x)` | `log($x)` | `log10($x)` |
| `is_finite($x)` | `is_infinite($x)` | `is_nan($x)` |

---

## ext/standard — 进制转换

> 文件: `conv.h` + `std/math.h`

| 函数 | C 实现 | 性能 |
|------|--------|------|
| `bindec($s) / hexdec($s) / octdec($s)` | `strtoll(s, NULL, base)` | O(1) |
| `decbin($n) / decoct($n) / dechex($n)` | `snprintf` | O(1) |
| `base_convert($n,$f,$t)` | 大整数数组算法 | O(log n) |

---

## ext/standard — 断言

> 文件: `std/ctrl.h`

| 函数 | 说明 |
|------|------|
| `assert_true($cond)` | 失败→`fprintf(stderr)`→`exit(2)` |
| `assert_false($cond)` | 同上 |
| `assert_eq_int($a,$b)` | int 相等断言 |
| `assert_eq_float($a,$b)` | float 相等断言 |
| `assert_eq_str($a,$b)` | string 相等断言 |

---

## ext/random — 随机数

> 文件: `rand.h`

全部统一走 CSPRNG（Windows → `rand_s`，Linux/macOS → `/dev/urandom`），零全局状态。

| 函数 | 算法 |
|------|------|
| `rand($min,$max)` | CSPRNG（代理到 `random_int`） |
| `mt_rand($min,$max)` | CSPRNG（代理到 `random_int`） |
| `random_int($min,$max)` | CSPRNG + 拒绝采样防模偏差 |
| `random_bytes($len)` | CSPRNG 原始二进制, ≤1MB |

---

## ext/password — 密码哈希

> 文件: `os/password.h`

基于 bcrypt 算法的 `password_hash` / `password_verify` 实现，参考 PHP 原生 `crypt_blowfish.c`（EksBlowfish 算法）。纯 C 静态实现，零外部依赖，兼容 AOT 编译。

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `password_hash($pw, PASSWORD_BCRYPT)` | `BF_crypt()` → 60 字符 `$2b$10$...` | cost=10，CSPRNG 盐值 |
| `password_verify($pw, $hash)` | `BF_crypt()` + 常量时间比较 | 防时序攻击 |

**实现细节**：
- 算法：EksBlowfish（bcrypt），与 PHP 原生 `password_hash` 完全兼容
- 盐值：优先使用 CSPRNG（`_tphp_random_bytes`），回退到基于时间的伪随机
- 常量：`PASSWORD_BCRYPT = 1`，`PASSWORD_BCRYPT_DEFAULT_COST = 10`
- 输出格式：`$2b$10$<22-char-base64-salt><31-char-base64-hash>`，共 60 字符
- 安全：`password_verify` 使用常量时间比较，防止时序攻击
- bcrypt 前缀支持：`$2a$`、`$2b$`、`$2x$`、`$2y$`（兼容所有 PHP bcrypt 变体）

---

## ext/ctype — 字符检测

> 文件: `std/ctrl.h`

11 个函数，直接映射 C `<ctype.h>`，零堆分配。空字符串返回 `false`。

| 函数 | C 实现 | 检测内容 |
|------|--------|---------|
| `ctype_alnum($s)` | `isalnum()` | 字母或数字 |
| `ctype_alpha($s)` | `isalpha()` | 纯字母 |
| `ctype_cntrl($s)` | `iscntrl()` | 控制字符 |
| `ctype_digit($s)` | `isdigit()` | 纯数字 |
| `ctype_graph($s)` | `isgraph()` | 可打印(除空格) |
| `ctype_lower($s)` | `islower()` | 小写字母 |
| `ctype_print($s)` | `isprint()` | 可打印(含空格) |
| `ctype_punct($s)` | `ispunct()` | 标点符号 |
| `ctype_space($s)` | `isspace()` | 空白字符 |
| `ctype_upper($s)` | `isupper()` | 大写字母 |
| `ctype_xdigit($s)` | `isxdigit()` | 十六进制字符 |

---

## ext/json

> 文件: `os/json.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `json_encode($var)` | 两趟法：计长→一次分配→写入，零 `str_concat` 开销 | 对象→`{}` |
| `json_decode($s)` | 递归下降解析 → `t_var` | 无效→NULL |
| `json_validate($s)` | 复用 `json_decode` | 有效→true |

---

## ext/hash

> 文件: `hash.h`

全部纯 C 算法（RFC 1321 / FIPS 180-4 / 查表法），零外部依赖。

| 函数 | 算法 | 输出 |
|------|------|------|
| `md5($s)` | RFC 1321 | 32 hex |
| `sha1($s)` | FIPS 180-4 | 40 hex |
| `sha256($s)` | FIPS 180-4 | 64 hex |
| `sha512($s)` | FIPS 180-4 | 128 hex |
| `crc32($s)` | 256 项查表法 | int |

---

## ext/date — 时间函数

> 文件: `os/times.h`

| 函数 | C 实现 |
|------|--------|
| `time()` | `time(NULL)` |
| `date($fmt)` | `strftime` + 64B 栈缓冲 |
| `sleep($s) / usleep($us)` | `sleep()` / `usleep()` |
| `hrtime()` | `QueryPerformanceCounter`(Win) / `clock_gettime`(Unix) |
| `microtime()` | 同上，返回 float 秒 |
| `mktime($h,$m,$s,$mo,$d,$y)` | 日历天数累加法 |
| `strtotime($s)` | 解析 `Y-m-d H:i:s` + mktime |
| `uniqid($prefix?)` | `sprintf "%08lx%05lx", time, rand` |

---

## ext/file — 文件 I/O

> 文件: `os/file.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `file_get_contents($path)` | `fopen("rb")` → 测大小 → 单次 `fread` → `fclose` | 不存在返回空 |
| `file_put_contents($path,$d)` | `fopen("wb")` → `fwrite` → `fclose` | 覆盖写入 |

---

## ext/mbstring (UTF-8)

> 文件: `std/utf8.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `mb_strlen($s)` | UTF-8 字节解码计数 | O(n) |
| `mb_substr($s,$start,$len)` | UTF-8 字符边界对齐 | O(n) |
| `mb_strpos($h,$n)` | 字节级搜索 (UTF-8 兼容) | O(n×m) |

---

## ext/pcntl — 进程控制

> 文件: `ext/pcntl/`，POSIX 专属，按需引入 `#import pcntl`

| 函数 | C 实现 |
|------|--------|
| `pcntl_fork()` | `fork()` |
| `pcntl_waitpid($pid,&$st)` | `waitpid()` |
| `pcntl_wait(&$st)` | `wait()` |
| `pcntl_exec($path)` | `execv()` |
| `pcntl_alarm($sec)` | `alarm()` |
| `pcntl_get_last_error()` | `errno` |
| `pcntl_strerror($no)` | `strerror()` |

---

## ext/posix — POSIX 系统

> 文件: `ext/posix/`，POSIX 专属，按需引入 `#import posix`

| 函数 | C 实现 |
|------|--------|
| `posix_getpid() / getppid()` | `getpid()` / `getppid()` |
| `posix_getuid() / geteuid()` | `getuid()` / `geteuid()` |
| `posix_getgid() / getegid()` | `getgid()` / `getegid()` |
| `posix_getcwd()` | `getcwd()` + 栈缓冲 |
| `posix_isatty($fd)` | `isatty()` |
| `posix_kill($pid,$sig)` | `kill()` |
| `posix_strerror($no)` | `strerror()` |
| `posix_get_last_error()` | `errno` |
| `posix_ttyname($fd)` | `ttyname()` |
| `posix_uname()` | ⬜ 未实现 |
| `posix_times()` | ⬜ 未实现 |

---

## 异常

> 文件: `object/try.h`

| 语法 | C 实现 | 内存安全 |
|------|--------|---------|
| `try { ... } catch (Exception $e)` | `setjmp/longjmp` | ✅ 先 `tphp_rt_free_all()` |
| `finally { ... }` | `TP_FINALLY` 宏 | ✅ 始终执行 |
| `throw new Exception("msg")` | 复制到 256B 栈缓冲 → `longjmp` | ✅ |
| `throw "string"` | `tp_throw` → `longjmp` | ✅ |

---

## OOP 语法

> 文件: `object/object.h`

| 语法 | 实现 | 说明 |
|------|------|------|
| `class B extends A` | COS struct 嵌套 `_parent` | — |
| `abstract class` | 禁止 `new` | 抽象方法无体 |
| `interface` | 纯抽象类 | 编译期类型标记 |
| `implements` | 编译期契约 | 不强制检查 |
| `trait` + `use TraitName` | 方法扁平化 | — |
| `instanceof` | `tp_obj_is_a(obj, &_class_X)` | 遍历类链 |
| `parent::method()` | `&self->_parent` + 父类函数名 | — |
| `__CLASS__ / __METHOD__` | 编译期字符串常量 | — |
| `__destruct` | 作用域结束自动 `tp_obj_release` | 池回收 |

---

## C 互操作 (PHPC)

> 文件: `phpc.h`

| 函数 | 方向 | 说明 |
|------|------|------|
| `c_int($x) / c_float($x) / c_str($s)` | PHP → C | → `int32_t` / `double` / `const char*` |
| `php_int($x) / php_float($x) / php_str($s)` | C → PHP | → `t_int` / `t_float` / `t_string` |
| `C->func(args)` | 直接 C 调用 | 无 name mangling |
| `#include "file.h"` | 预处理器 | 生成 `#include` |
| `#flag [CC] [OS] flags` | 预处理器 | 平台+编译器过滤 |
| `#callback type name(params)` | 预处理器 | 声明 C 回调签名 |
| `phpc_arr_int/dbl/str` | PHP→C | 严格类型检查，malloc |
| `phpc_new_arr_int/dbl/str` | C→PHP | 深拷贝 |
| `phpc_obj` / `phpc_fn` / `phpc_env` | 双向 | 对象/函数/环境指针 |
| `phpc_thunk('name', $fn)` | no-env 回调 | 按 #callback 生成 thunk |

---

## 内存安全

| 机制 | 说明 |
|------|------|
| 资源追踪链表 | `tphp_rt_register(ptr, type)` → `error()` 时遍历释放 |
| 64KB 字符串池 | bump allocator，≤512B 零 `malloc` |
| 128 槽数组池 | LIFO 复用，1.5× 增长因子 |
| 128 槽对象池 | LIFO 复用，`tp_obj_release` 回收到池 |
| COS refcount | `tp_obj_retain` / `tp_obj_release` |
| scope 自动析构 | 方法尾注入 `tp_obj_release(var)` |
| 异常安全 | `tp_throw` 先 `tphp_rt_free_all()` 再 `longjmp` |

---

## 编译器兼容

| 文件 | 说明 |
|------|------|
| `compat.h` | TCC：`round` fallback + `ceil/floor/sqrt/pow` 声明 |
| `json.h` | TCC：`isnan`/`isinf` 自研实现 |
| `conv.h` | TCC：`_tphp_pow10` 循环替代 `pow()` |
| `tphp.php` | Win GCC 自动 `-Wno-implicit-function-declaration` |
| **原则** | 所有 TCC 特殊处理用 `#ifdef __TINYC__` 隔离 |

---

## 暂缓（可做但低优先级）

| 功能 | 原因 |
|------|------|
| `serialize / unserialize` | PHP 序列化格式完整解析器 |
| `Date* OO API` (30+) | 需完整 DateTime 类 |
| `array_multisort / natsort` | 专用场景 |
| `usort / uasort / uksort` | 需闭包回调 |
| `array_filter / array_map / array_reduce` | 需闭包回调 |
| `filter_var` 完整版 | ~600行，延后 |
| `calendar` 全套 | ~1000行 sdncal，延后 |

---

## AOT 不可行

以下依赖运行时解释器、动态符号表或 VM 机制，**永久不支持**：

| 类别 | 函数/特性 |
|------|----------|
| 动态执行 | `eval()`, `create_function()`, `assert($str)` |
| 动态调用 | `call_user_func`, `$fn()`, `$obj->$method()` |
| 符号表 | `$$var`, `compact()`, `extract()`, `get_defined_vars()` |
| 反射 | `Reflection*`, `debug_backtrace()`, `func_get_args()` |
| 回调注册 | `set_error_handler`, `register_shutdown_function`, `ob_start($cb)` |
