# TinyPHP 内置函数参考

> 按 PHP 扩展结构分类，含实现差异与性能说明。

---

## 总览

| PHP 扩展 | 对应 TinyPHP 文件 | 函数数 |
|----------|------------------|--------|
| `include/standard` 输出/类型/字符串 | `std/core.h` (合并 output+type+string) | 67 |
| `include/standard` HTML/Base64/URL | `std/html.h` | 6 |
| `include/standard` 数组 | `array.h` + `std/array_extra.h` | 41 |
| `include/standard` 数学 | `std/math.h` + `tphp_math.h` | 21 |
| `include/standard` 进制转换 | `conv.h` | 8 |
| `include/standard` 断言/随机 | `std/ctrl.h` | 5 |
| `include/json` | `os/json.h` | 3 |
| `include/hash` | `hash.h` | 5 |
| `include/date` | `os/times.h` | 9 |
| `include/ctype` | `std/ctrl.h` | 11 |
| `include/mbstring` (UTF-8) | `std/utf8.h` | 3 |
| `include/iconv` (字符集转换) | `iconv.h` | 8 |
| `ext/pcntl` | `ext/pcntl/` | 7 |
| `ext/posix` | `ext/posix/` | 14 |
| `ext/pcre` | `ext/pcre/` | 8 |
| `include/filter` | `filter.h` | 3 |
| `include/password` (bcrypt) | `os/password.h` | 2 |
| OOP / 异常 / Resource | `object/` | 14 |
| Generator / yield | `object/generator.h` + `minicoro.h` | 7 |
| C 互操作 (PHPC) | `phpc.h` | 31 |
| **合计** | | **262+** |

---

## C 标识符命名规范

| 场景 | 格式 | 示例 |
|------|------|------|
| 全局类 | `tphp_class_Name` | `tphp_class_Main` |
| 全局函数 | `tphp_fn_name` | `tphp_fn_hello` |
| 全局枚举 | `tphp_enum_Name` | `tphp_enum_Color` |
| 命名空间类 | `tphp_na_Ns_tphp_class_Name` | `tphp_na_Demo_Hello_tphp_class_MyClass` |
| 命名空间函数 | `tphp_na_Ns_tphp_fn_name` | `tphp_na_Demo_Hello_tphp_fn_greet` |
| 命名空间枚举 | `tphp_na_Ns_tphp_enum_Name` | `tphp_na_Colors_tphp_enum_Status` |
| 常量 | `TPHP_CONST_NAME` | `TPHP_CONST_PI` |
| 重载函数 | `tphp_fn_name_N` | `tphp_fn_add_1` (缺少 1 个默认值参数) |

---

## 函数默认值参数

> TinyPHP 支持函数参数默认值，采用**编译时重载**策略，零运行时开销。

### 语法

```php
function add(int $a, int $b = 10): int {
    return $a + $b;
}
```

### 规则

- 有默认值的参数必须放在参数列表末尾（与 PHP 原生一致）
- 默认值支持所有基本类型：`int`、`float`、`string`、`bool`
- 支持负数和表达式作为默认值
- **不支持** `callable` 类型作为默认值（编译时无法将字符串函数名转换为函数指针）

### 编译策略

编译器为每个有默认值的函数生成重载版本：

```php
// PHP 源码
function add(int $a, int $b = 10): int {
    return $a + $b;
}
echo add(5);     // 使用默认值
echo add(5, 20); // 覆盖默认值
```

生成的 C 代码：

```c
// 重载版本：缺少 1 个参数
static t_int tphp_fn_add_1(t_int a) {
    return tphp_fn_add(a, 10);
}

// 完整版本
static t_int tphp_fn_add(t_int a, t_int b) {
    return (a + b);
}

// 调用时自动选择
tphp_fn_add_1(5);      // add(5)
tphp_fn_add(5, 20);    // add(5, 20)
```

### 示例

```php
// 单个默认值
function greet(string $name, string $greeting = "hello"): string {
    return $greeting . " " . $name;
}
greet("world");          // "hello world"
greet("world", "hi");   // "hi world"

// 多个默认值
function calc(int $a, int $b = 5, int $c = 10): int {
    return $a + $b + $c;
}
calc(100);       // 115 (100 + 5 + 10)
calc(100, 20);   // 130 (100 + 20 + 10)
calc(100, 20, 30); // 150 (100 + 20 + 30)
```

---

## ext/standard — 输出函数

> 文件: `std/core.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `echo $x` | `fwrite(stdout)` | 二进制安全，不解析格式化符 |
| `var_dump($x)` | type switch → `fprintf` | 支持全部类型，对象输出 `{}`；浮点用 `%.14g`（PHP 默认精度） |
| `print_r($x, $ret=false)` | 递归格式化 | 支持数组/对象/标量；`$ret=true` 返回字符串 |
| `exit($code)` | `exit(code)` | — |
| `isset($var)` | 指针类型 → `ptr != NULL`；值类型 → 编译期 `true` | — |
| `empty($var)` | 按类型分发 | int→`==0`, string→`is_falsy`, float/bool 同 |

---

## ext/standard — 类型函数

> 文件: `std/core.h`

### 类型检测

| 函数 | AOT 优化 |
|------|---------|
| `is_int / is_float / is_string / is_bool` | 编译期静态类型 → 直接字面量 `true`/`false` |
| `is_array / is_null / is_object / is_callable` | 同上 |
| `is_resource($v)` | 编译期检查 `tphp_class_` 类型 → `true`；运行时 `tp_obj_is_a` 检查继承链 |
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

> 文件: `std/core.h`

字符串为 16 字节 SSO 值类型 `{ char* data; int length; bool is_local; }`。
≤23 字节内联存储（SSO），≤512B 通过 128KB bump allocator 分配，零 `malloc`。
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
| `str_replace($s, $r, $t)` | 两遍扫描 + `str_pool_alloc` | O(n)；支持数组参数（`$s`/`$r` 为数组时逐键替换） |
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
| `array_map($cb, $a)` | 编译期内联展开回调循环 | O(n) |
| `array_filter($a, $cb?)` | 编译期内联展开 + null 过滤 | O(n) |
| `array_reduce($a, $cb, $init)` | 编译期内联累加器循环 | O(n) |

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
| `unlink($path)` | `remove()` | 删除文件，成功返回 `true` |

---

## ext/mbstring (UTF-8)

> 文件: `std/utf8.h`

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `mb_strlen($s)` | UTF-8 字节解码计数 | O(n) |
| `mb_substr($s,$start,$len)` | UTF-8 字符边界对齐 | O(n) |
| `mb_strpos($h,$n)` | 字节级搜索 (UTF-8 兼容) | O(n×m) |

---

## ext/iconv — 字符集转换

> 文件: `include/iconv.h`（内置，非 `#import` 按需引入）
>
> 跨平台: POSIX 用系统 `<iconv.h>`（TCC 下改用手动前向声明，避开 macOS stdarg.h 缺失问题）；Windows 用 Win32 `MultiByteToWideChar`/`WideCharToMultiByte`。macOS 链接自动添加 `-liconv`。
> AOT 单返回类型: 失败统一 `tp_throw`（不返回 `false`）；`iconv_strpos` 未找到返回 `-1`。

**常量**

| 常量 | 值 | 说明 |
|------|----|------|
| `ICONV_IMPL` | `"iconv"` | 实现名称 |
| `ICONV_VERSION` | `"1.0"` | 版本号 |

**函数**

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `iconv($from,$to,$str)` | 系统 iconv / Win32 API | 核心编码转换，失败 tp_throw |
| `iconv_strlen($str,$charset="UTF-8")` | UTF-8 快路径 + 转换 | 返回字符数 |
| `iconv_strpos($h,$n,$offset=0,$charset="UTF-8")` | UTF-8 字符偏移搜索 | 未找到返回 -1 |
| `iconv_substr($str,$offset,$length=0,$charset="UTF-8")` | UTF-8 字符边界截取 | length=0 表示到末尾 |
| `iconv_get_encoding($type="all")` | 返回关联数组 | 始终返回 3 元素: input/output/internal_encoding |
| `iconv_set_encoding($type,$encoding)` | 修改内部编码状态 | 返回 bool，未知 type 时 tp_throw |
| `iconv_mime_encode($field,$value,$prefs=[])` | base64 + MIME 头 | 生成 `Name: =?charset?B?...?=` |
| `iconv_mime_decode($str,$mode=0,$charset="UTF-8")` | 解析 MIME 编码段 | 支持 B/Q 编码，失败 tp_throw |

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

## ext/pcre — 正则表达式

> 文件: `ext/pcre/`，NFA VM 引擎（移植自 vlang `vlib/regex/pcre/regex.v`），按需引入 `#import pcre`

纯 C NFA VM 正则引擎（Russ Cox 模型，12 条指令），不依赖外部 PCRE2 库。128 位 bitset ASCII 字符类、Boyer-Moore 前缀跳过、32 槽 LRU 编译缓存。

**与 PHP 差异**：`preg_match` / `preg_match_all` 返回匹配数组（空=无匹配）而非 `int + byRef $matches`；所有参数必须显式传入（AOT 不支持默认参数值 / byRef 输出参数）；不支持 `preg_replace_callback`；`\a`=`[a-z]`（PHP 为 BEL 0x07）、`\A`=`[A-Z]`（PHP 为字符串起始）；`i` 标志仅 ASCII 大小写折叠；不支持 lookahead / lookbehind / 原子组 `(?>)` / 占有量词 `*+` / Unicode 属性类 `\p{}`。

| 函数 | C 实现 | 说明 |
|------|--------|------|
| `preg_match($pat, $subj)` | NFA VM → t_array* | 空=无匹配 |
| `preg_match_all($pat, $subj)` | 循环匹配 → 二维数组 | 固定 `PREG_PATTERN_ORDER` |
| `preg_replace($pat, $repl, $subj, $limit)` | 两趟法：计长→写入 | `$0`-`$9` 反向引用 |
| `preg_split($pat, $subj, $limit, $flags)` | 循环分割 → t_array* | `PREG_SPLIT_NO_EMPTY` |
| `preg_grep($pat, $arr, $flags)` | 遍历匹配 → t_array* | `PREG_GREP_INVERT` |
| `preg_quote($str, $delim)` | 两趟法转义元字符 | `$delim` 传空串则只转义元字符 |
| `preg_last_error()` | 全局错误码 | `PREG_NO_ERROR`=0 |
| `preg_last_error_msg()` | 错误码 → 字符串 | — |

### 支持的正则语法

| 类别 | 语法 |
|------|------|
| 预定义类 | `\d \D \w \W \s \S \b \B` |
| 字母类 | `\a`(=[a-z]) `\A`(=[A-Z]) |
| 字符类 | `[...]` `[^...]` 范围 `a-z` |
| 量词 | `* + ? {n} {n,} {n,m}` + 懒惰 `?` |
| 分组 | `(...)` `(?:...)` `(?P<name>...)` |
| 标志 | `i m s`（分隔符后或内联 `(?i)`） |
| 锚点 / 选项 | `^ $` / `\|` |
| 转义 | `\n \r \t \v \f \0 \xHH` |

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `PREG_PATTERN_ORDER` | 1 | `preg_match_all` 默认顺序 |
| `PREG_SET_ORDER` | 2 | 定义但未实现（固定 PATTERN_ORDER） |
| `PREG_SPLIT_NO_EMPTY` | 1 | `preg_split` 去空片段 |
| `PREG_SPLIT_DELIM_CAPTURE` | 2 | `preg_split` 保留分隔符捕获组 |
| `PREG_GREP_INVERT` | 1 | `preg_grep` 反转结果 |
| `PREG_NO_ERROR` | 0 | 无错误 |
| `PREG_INTERNAL_ERROR` | 1 | 内部错误 |
| `PREG_BACKTRACK_LIMIT_ERROR` | 2 | 回溯限制（未启用） |
| `PREG_RECURSION_LIMIT_ERROR` | 3 | 递归限制（未启用） |

---

## ext/filter — 过滤器

> 文件: `include/filter.h`（内置功能，非 ext/ 扩展）

### 函数

| 函数 | 说明 |
|------|------|
| `filter_var(mixed $value, int $filter, array\|int $options = 0): mixed` | 用指定过滤器验证/净化单个变量 |
| `filter_list(): array` | 返回所有支持的过滤器名称列表（string 数组） |
| `filter_id(string $name): int` | 根据过滤器名称返回 ID，未知名称返回 -1 |

### 验证过滤器（FILTER_VALIDATE_*）

验证失败返回 `NULL`，成功返回原值或类型转换后的值。

| 常量 | 值 | 说明 |
|------|-----|------|
| `FILTER_VALIDATE_INT` | 257 | 验证整数（支持 `FILTER_FLAG_ALLOW_OCTAL` / `FILTER_FLAG_ALLOW_HEX`） |
| `FILTER_VALIDATE_BOOL` | 258 | 验证布尔值（"1"/"true"/"on"/"yes" → true，"0"/"false"/"off"/"no" → false） |
| `FILTER_VALIDATE_FLOAT` | 259 | 验证浮点数（支持 `FILTER_FLAG_ALLOW_THOUSAND` / `FILTER_FLAG_ALLOW_SCIENTIFIC`） |
| `FILTER_VALIDATE_REGEXP` | 272 | 正则验证（需用 `preg_*` 代替） |
| `FILTER_VALIDATE_URL` | 273 | 验证 URL（要求 scheme://host 格式） |
| `FILTER_VALIDATE_EMAIL` | 274 | 验证 Email（RFC 5321 简化版，ASCII only） |
| `FILTER_VALIDATE_IP` | 275 | 验证 IP（IPv4 / IPv6） |
| `FILTER_VALIDATE_MAC` | 276 | 验证 MAC 地址（xx:xx:xx:xx:xx:xx 或 - 分隔） |
| `FILTER_VALIDATE_DOMAIN` | 277 | 验证域名 |

### 净化过滤器（FILTER_SANITIZE_*）

返回处理后的字符串。

| 常量 | 值 | 说明 |
|------|-----|------|
| `FILTER_SANITIZE_STRING` | 513 | 去除 HTML 标签 |
| `FILTER_SANITIZE_ENCODED` | 514 | URL 编码（rawurlencode 规则） |
| `FILTER_SANITIZE_SPECIAL_CHARS` | 515 | HTML 转义 `<>"'&` |
| `FILTER_SANITIZE_EMAIL` | 517 | 去除 email 非法字符 |
| `FILTER_SANITIZE_URL` | 518 | 去除 URL 非法字符 |
| `FILTER_SANITIZE_NUMBER_INT` | 519 | 仅保留数字和 `+-` |
| `FILTER_SANITIZE_NUMBER_FLOAT` | 520 | 仅保留数字和 `+-.,eE` |
| `FILTER_SANITIZE_ADD_SLASHES` | 523 | addslashes |
| `FILTER_SANITIZE_FULL_SPECIAL_CHARS` | 522 | 完整 HTML 实体转义 |

### 标志位（FILTER_FLAG_*）

| 常量 | 值 | 适用过滤器 |
|------|-----|----------|
| `FILTER_FLAG_ALLOW_OCTAL` | 1 | INT |
| `FILTER_FLAG_ALLOW_HEX` | 2 | INT |
| `FILTER_FLAG_STRIP_LOW` | 4 | STRING |
| `FILTER_FLAG_STRIP_HIGH` | 8 | STRING |
| `FILTER_FLAG_ENCODE_LOW` | 16 | STRING |
| `FILTER_FLAG_ENCODE_HIGH` | 32 | STRING |
| `FILTER_FLAG_ENCODE_AMP` | 64 | STRING |
| `FILTER_FLAG_NO_ENCODE_QUOTES` | 128 | STRING / SPECIAL_CHARS |
| `FILTER_FLAG_EMPTY_STRING_NULL` | 256 | STRING |
| `FILTER_FLAG_ALLOW_THOUSAND` | 8192 | FLOAT |
| `FILTER_FLAG_ALLOW_SCIENTIFIC` | 16384 | FLOAT |
| `FILTER_FLAG_PATH_REQUIRED` | 0x100000 | URL |
| `FILTER_FLAG_QUERY_REQUIRED` | 0x200000 | URL |
| `FILTER_FLAG_IPV4` | 0x100000 | IP |
| `FILTER_FLAG_IPV6` | 0x200000 | IP |

### options 数组

`filter_var` 第三参数可传关联数组，支持以下键：

| 键 | 适用过滤器 | 说明 |
|----|----------|------|
| `"flags"` | 所有 | 标志位组合（等价于 int 形式的第三参数） |
| `"min_range"` | INT | 最小值（含） |
| `"max_range"` | INT | 最大值（含） |

### 示例

```php
filter_var("42", FILTER_VALIDATE_INT);                     // int(42)
filter_var("abc", FILTER_VALIDATE_INT);                    // NULL
filter_var("user@example.com", FILTER_VALIDATE_EMAIL);     // "user@example.com"
filter_var("127.0.0.1", FILTER_VALIDATE_IP);              // "127.0.0.1"
filter_var("<b>hi</b>", FILTER_SANITIZE_SPECIAL_CHARS);    // "&lt;b&gt;hi&lt;/b&gt;"

// INT 范围验证
$opts = ["min_range" => 10, "max_range" => 100];
filter_var("50", FILTER_VALIDATE_INT, $opts);              // int(50)
filter_var("5", FILTER_VALIDATE_INT, $opts);               // NULL

// 八进制/十六进制
filter_var("077", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL);  // int(63)
filter_var("0xff", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX);   // int(255)
```

---

## 异常

> 文件: `object/try.h`

| 语法 | C 实现 | 内存安全 |
|------|--------|---------|
| `try { ... } catch (Exception $e)` | `setjmp/longjmp` | ✅ 先 `tphp_rt_free_all()` |
| 多 catch 子句 `catch (A $e) ... catch (B $e)` | 类型匹配表 + `exception_offset` 计算子类→Exception 偏移 | ✅ 子类异常被父类 catch 正确匹配 |
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

### Resource 类型

> 文件: `object/resource.h`

| 特性 | 实现 | 说明 |
|------|------|------|
| `Resource` 基类 | `tphp_class_Resource` | 模拟 PHP `zend_resource`，含 `handle`/`type`/`ptr` 字段 |
| `File` 子类 | `tphp_class_File extends Resource` | 替代 PHP `fopen()` resource，含 `FILE* fp` |
| `is_resource($v)` | `tp_obj_is_a` 检查继承链 | 编译期静态类型直接返回 `true`/`false` |
| `$f->getType()` | 返回资源类型 ID | `RSRC_TYPE_FILE=0` 等 |
| `$f->isOpen()` | 检查文件是否打开 | `fp != NULL` |
| `$f->close()` | 幂等关闭 | 重复调用安全 |
| 资源列表 | LIFO 空闲槽复用池 | O(1) 插入/删除，最多 2048 活跃资源 |
| RAII 自动释放 | `tp_obj_release` → `__destruct` → `fclose` | 作用域结束自动关闭 |
| `tphp_rt_free_all_resources()` | 异常路径释放所有资源 | 防内存泄漏 |

---

## Generator / yield

> 文件: `object/generator.h` + `include/minicoro.h`
>
> 基于 minicoro 协程库实现。生成器函数编译为**双函数**：协程入口 `tphp_gen_<name>_entry(mco_coro* co)` + 包装器 `tphp_fn_<name>(...)`。
> **不使用 yield 的函数零开销**——编译为普通函数，不引入协程。

### yield 语法

| 语法 | 说明 | 示例 |
|------|------|------|
| `yield $v` | 产出值 | `yield 42;` |
| `yield $k => $v` | 产出键值对 | `yield "a" => 10;` |
| `return $v;` | 生成器返回值（配合 `getReturn()`） | `return 99;` |
| `$g->send($v)` | 向 yield 表达式发送值，返回下一个 yield 值 | `$g->send(100)` |

### Generator 类方法

| 方法 | 返回 | 说明 |
|------|------|------|
| `current()` | `t_var` | 当前 yield 的值；未启动时先 `rewind()` |
| `key()` | `t_var` | 当前 yield 的 key |
| `next()` | `t_var` | 推进到下一个 yield，返回新值 |
| `send($v)` | `t_var` | 发送值到 yield 表达式，返回下一个 yield 值 |
| `valid()` | `t_int` | 是否仍有可迭代的值（1/0） |
| `getReturn()` | `t_var` | 生成器的 return 值 |
| `rewind()` | `void` | 首次 resume，推进到第一个 yield |

### foreach 迭代

```php
function gen(): Generator {
    yield 1;
    yield "a" => 10;
    yield 2;
    return 99;
}

$g = gen();
foreach ($g as $k => $v) {
    var_dump($k);   // 0, "a", 1
    var_dump($v);   // 1, 10, 2
}
var_dump($g->getReturn());  // 99
```

### send() 双向传值

```php
function gen(): Generator {
    $x = yield 1;   // 接收 send() 传入的值
    yield $x + 1;
}

$gen = gen();
var_dump($gen->current());   // 1
var_dump($gen->send(100));   // 101
```

### AOT 约束

| 约束 | 说明 |
|------|------|
| `callable` 参数须用闭包 | `gen(1, 3, "apply")` 不可行——字符串是运行时数据，编译期无法解析为函数符号。须用 `gen(1, 3, fn($x) => apply($x))` |
| macOS + TCC | **不支持**，编译时报错。TCC 的 `ucontext_t` 布局与 Apple Silicon 不匹配，请使用 `-cc gcc` 或 `-cc clang` |

### 平台支持

| 平台 | TCC | GCC / Clang |
|------|-----|-------------|
| Windows x86_64 | Win32 Fiber | ASM |
| Linux x86_64 | ucontext | ASM |
| Linux aarch64 | ucontext | ASM |
| macOS aarch64 + TCC | **不支持**（编译报错） | ASM |

---

## C 互操作 (PHPC)

> 文件: `phpc.h`

| 函数 | 方向 | 说明 |
|------|------|------|
| `c_int($x) / c_float($x) / c_str($s)` | PHP → C | → `int32_t` / `double` / `const char*` |
| `php_int($x) / php_float($x) / php_str($s)` | C → PHP | → `t_int` / `t_float` / `t_string` (深拷贝) |
| `php_str_clone($s)` | C → PHP | → `t_string` (深拷贝，明确克隆语义) |
| `C->func(args)` | 直接 C 调用 | 无 name mangling |
| `C->CONST` | 直接 C 常量/枚举/宏访问 | 无括号形式，按 `t_int` 推断 |
| `C.Type` | C 类型注解 | 函数参数/返回值用 C 类型（如 `C.Point` → `Point*`） |
| `#include "file.h"` | 预处理器 | 生成 `#include` |
| `#flag [CC] [OS] flags` | 预处理器 | 平台+编译器过滤 |
| `#callback type name(params)` | 预处理器 | 声明 C 回调签名 |
| `phpc_arr_int/dbl/str` | PHP→C | 严格类型检查，**类型不匹配抛 tp_throw 异常**，malloc |
| `phpc_new_arr_int/dbl/str` | C→PHP | 深拷贝 |
| `phpc_obj` / `phpc_fn` / `phpc_env` | 双向 | 对象/函数/环境指针（借用语义） |
| `phpc_new_obj` | C→PHP | 包裹 C 指针为 PHP 对象（接管语义） |
| `phpc_unregister_obj` | 双向 | 解除对象注册（C 库自行 free 时调用，防 double-free） |
| `phpc_obj_steal` | 双向 | 标记对象"已分离"（refcount=-1），C 库可安全 free（防 double-free） |
| `phpc_assert_ptr` | 安全 | 断言指针非 NULL，NULL 时抛 tp_throw 异常（可 try-catch） |
| `phpc_env_pin` / `phpc_env_unpin` | 安全 | 固定/解除固定闭包 env（异步回调安全） |
| `phpc_free` | 释放 | free + **自动置零变量**防 UAF |
| `phpc_free_str_arr` | 释放 | 释放字符串数组 + **自动置零** |
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
