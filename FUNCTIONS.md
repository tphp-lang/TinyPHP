# TinyPHP 内置函数参考

> 与 PHP 标准库的对照及实现差异说明。

---

## 输出 / 调试

### `echo` — 输出字符串

```
echo "hello";
echo "a", "b", "c";        // 多参数逗号分隔
```

| | PHP | TinyPHP |
|---|---|---|
| 多参数 | ✅ | ✅ |
| 表达式 | ✅ | ✅ |
| 无返回值 | ✅ | ✅ |

**差异**：无。直接 `fwrite` 输出。

---

### `var_dump` — 递归调试输出

```
var_dump($a);
```

| | PHP | TinyPHP |
|---|---|---|
| 多参数 | ✅ | ❌（仅单参数） |
| 递归打印嵌套数组/对象 | ✅ | ✅ |
| 输出类型+值 | ✅ | ✅ |

**差异**：仅支持单参数。格式为 `type(value)` 而非 PHP 的冗长格式。

---

## 数组

### `count` — 数组元素个数

```
count($arr);
```

| | PHP | TinyPHP |
|---|---|---|
| 递归计数 `COUNT_RECURSIVE` | ✅ | ❌ |
| null/非数组 | 警告 | 未定义行为 |

**差异**：仅支持数组，无递归模式。返回 `int`。

---

### `array_push($arr, $val)` — 尾部追加

```
array_push($arr, 42);
```

| | PHP | TinyPHP |
|---|---|---|
| 单参数追加 | ✅ | ✅ |
| 返回值（新长度） | ✅ | ✅ |
| 多值追加 | ✅ | ❌（仅单值） |

**差异**：仅支持一次 push 单个值。返回新数组长度。

---

### `array_pop($arr)` — 尾部弹出

```
$val = array_pop($arr);
```

| | PHP | TinyPHP |
|---|---|---|
| 空数组返回 null | ✅ | ✅ |
| 返回弹出值 | ✅ | ✅（t_var） |
| 自动缩减长度 | ✅ | ✅ |

**差异**：返回值是 `t_var`（带类型标签），通过 `var_dump` 可正确显示类型。

---

### `in_array($needle, $haystack)` — 值是否存在

```
in_array(42, $arr);
```

| | PHP | TinyPHP |
|---|---|---|
| 严格模式 `===` | ✅ (第三个参数) | ❌ |
| int/string/bool/null 比较 | ✅ | ✅ |
| O(n) 线性扫描 | ✅ | ✅ |

**差异**：不支持严格模式。仅比较 int/string/bool/null 类型。

---

### `array_key_exists($key, $arr)` — 键是否存在

```
array_key_exists(0, $arr);
```

| | PHP | TinyPHP |
|---|---|---|
| int key | ✅ | ✅ |
| string key | ✅ | ✅ |
| null key | 转空字符串 | 未定义行为 |

**差异**：int 键和 string 键分发到不同 C 函数（`_int` / `_str`）。

---

### `array_keys($arr)` — 取所有键

```
$keys = array_keys($arr);
```

| | PHP | TinyPHP |
|---|---|---|
| 返回 int 键数组 | ✅ | ✅ |
| 返回 string 键（堆拷贝） | ✅ | ✅ |
| 支持搜索值过滤 | ✅ | ❌ |

**差异**：仅基础形式。返回新数组，内存通过资源追踪管理。

---

### `array_values($arr)` — 取所有值

```
$vals = array_values($arr);
```

| | PHP | TinyPHP |
|---|---|---|
| 返回新数组 | ✅ | ✅ |
| 重排索引 | ✅ | ✅（int key 从 0 开始） |

**差异**：无。返回新数组。

---

### `array_merge($a, $b)` — 合并两个数组

```
$merged = array_merge([1,2], [3,4]);
```

| | PHP | TinyPHP |
|---|---|---|
| 两个参数 | ✅ | ✅ |
| int key 重新索引 | ✅ | ✅ |
| string key 保留 | ✅ | ✅ |
| 多参数 | ✅ | ❌（仅两个） |

**差异**：仅支持两个参数。原数组不变，返回新数组。

---

### `implode($glue, $arr)` / `join($glue, $arr)` — 连接为字符串

```
echo implode(",", [1, 2, 3]);  // "1,2,3"
```

| | PHP | TinyPHP |
|---|---|---|
| 分隔符 | ✅ | ✅ |
| int 元素自动转字符串 | ✅ | ✅ |
| float 元素 | ✅ | ✅（`%g` 格式） |

**差异**：返回堆分配 `t_string`（≤512 字节使用字符串池）。

---

### `explode($delim, $str)` — 切分为数组

```
$parts = explode(",", "a,b,c");
```

| | PHP | TinyPHP |
|---|---|---|
| 单字符分隔符 | ✅ | ✅ |
| 多字符分隔符 | ✅ | ✅ |
| 空分隔符 | 按字符切分 | 整个字符串为一个元素 |
| 限制数量参数 | ✅ | ❌ |

**差异**：片段通过字符串池分配（≤512 字节），减少 `malloc`。

---

## 控制流程

### `exit($code)` / `die($code)` — 终止程序

```
exit(0);
exit(1);
die("error");
```

| | PHP | TinyPHP |
|---|---|---|
| 整数参数 | ✅ | ✅ |
| 字符串参数 | ✅（输出后退出） | ✅（仅退出码，不输出） |
| 无参数 | ✅ (`exit()` = `exit(0)`) | ✅ |

**差异**：TinyPHP `exit("msg")` 不打印消息，只取退出码。

---

### `error($msg)` — 报错并安全退出

```
error("something went wrong");
```

| | PHP | TinyPHP |
|---|---|---|
| 存在 | ❌（无此函数） | ✅ |
| 输出格式 | — | `Fatal error: msg\n  in file.php on line N` |
| 资源清理 | — | ✅ 释放所有对象/数组/字符串 |
| 退出码 | — | 1 |

**差异**：这是 TinyPHP 专属函数，PHP 无等价物。用于替代 `throw` 实现致命错误处理。

---

## 变量检测

### `isset($x)` — 变量非 null

```
isset($x);
```

| | PHP | TinyPHP |
|---|---|---|
| 未定义变量 | false | 编译错误 |
| null | false | false |
| 0 / "" / false | true | true |
| 多参数 | ✅ | ❌ |

**差异**：TinyPHP 变量必须声明后才能 `isset`。对 int/float/bool/string 栈值始终 `true`。

---

### `empty($x)` — PHP 假值检测

```
empty($x);
```

| | PHP | TinyPHP |
|---|---|---|
| null | true | true |
| 0 | true | true |
| "" | true | true |
| "0" | true | ❌（视为非空字符串） |
| false | true | true |
| [] | true | true |

**差异**：`empty("0")` 在 TinyPHP 返回 `false`（"0" 是长度为 1 的字符串，不是假值）。

---

### `unset($x)` — 释放变量

```
unset($x);
```

| | PHP | TinyPHP |
|---|---|---|
| int | 变 0 | 变 0 |
| string | 变空 | 变 `{NULL, 0}` |
| array | 释放 | 回收到数组池 + 设 NULL |
| object | 释放 | `__destruct` + `free` |

**差异**：数组回收到复用池而非直接 `free`。

---

### 类型检测系列：`is_int` / `is_float` / `is_string` / `is_bool` / `is_array` / `is_null` / `is_object` / `is_callable`

```
is_int($x);
is_array($arr);
is_object($obj);
```

| | PHP | TinyPHP |
|---|---|---|
| 静态类型 | 运行时判断 | **编译期常量折叠**（如 `is_int(42)` → `true`） |
| `t_var` (mixed/union) | — | 运行时 `tphp_fn_is_*` 检查标签 |
| 对象类型 | ✅ | ✅（类名不在基本类型列表 → true） |

**差异**：静态类型变量在编译期直接返回 `true`/`false`，零运行时开销。

---

## 解构

### `list($a, $b)` — 数组解构赋值

```
list($a, $b) = [1, 2];
list(, $b) = [1, 2];          // 跳过元素
list($a, list($b)) = ...;     // 嵌套解构
[$a, $b] = [1, 2];            // 短语法 (PHP 7.1+)
```

| | PHP | TinyPHP |
|---|---|---|
| 基础解构 | ✅ | ✅ |
| 跳过 `list(,$b)` | ✅ | ✅ |
| 多余元素忽略 | ✅ | ✅ |
| 嵌套 `list()` | ✅ | ✅ 支持 3 层 |
| 短语法 `[$a,$b]` | ✅ | ✅ |
| 键名解构 `["key" => $v]` | ✅ (7.1+) | ❌ |

**差异**：不支持键名解构。内存安全：生成临时数组指针，读取后立即释放。

---

## 时间 / 日期

### `time()` — 当前 Unix 时间戳

```
$ts = time();
```

| | PHP | TinyPHP |
|---|---|---|
| 返回类型 | int | `t_int` (int64_t) |
| 精度 | 秒 | 秒 |

**差异**：无。直接 `time(NULL)`，零堆分配。

---

### `date($format, $timestamp?)` — 格式化时间

```
echo date("Y-m-d H:i:s", 1717200000);
echo date("Y");                    // 当前时间
```

| | PHP | TinyPHP |
|---|---|---|
| 格式字符 | 30+ | Y y m n d j H G i s（8 个） |
| 默认时间戳 | 当前时间 | 当前时间 |
| 时区 | php.ini | 系统本地时区 |
| 内存分配 | 堆 | 静态缓冲区（零分配） |

**差异**：仅支持 8 个格式字符。手写解析，跨平台一致。

---

### `sleep($seconds)` — 休眠

```
sleep(1);      // 1 秒
sleep(0);      // 无操作
```

| | PHP | TinyPHP |
|---|---|---|
| 负数 | 报错 | 静默忽略 |
| 精度 | 秒（int） | 秒（int） |

**差异**：负数不报错，静默返回。

---

### `usleep($microseconds)` — 微秒休眠

```
usleep(500000);   // 0.5 秒
usleep(1000);     // 1 毫秒
```

| | PHP | TinyPHP |
|---|---|---|
| 精度 | 微秒 | 微秒 |
| Windows 实现 | `usleep` 模拟 | `Sleep(ms)`（毫秒精度） |

**差异**：Windows 上精度退化为毫秒。

---

### `hrtime()` — 高精度时间（纳秒）

```
$ns = hrtime();
$start = hrtime();
// ... do work ...
$elapsed = hrtime() - $start;
```

| | PHP | TinyPHP |
|---|---|---|
| 参数 `true` 返回数组 | ✅ | ❌（无参数） |
| 返回值 | int/array | `t_int`（纳秒） |
| Windows 实现 | `QueryPerformanceCounter` | 同 |
| Linux/macOS 实现 | `clock_gettime(CLOCK_MONOTONIC)` | 同 |

**差异**：仅支持无参形式，返回纳秒整数。零堆分配。

---

## 实现特性总结

| 特性 | 说明 |
|---|---|
| **数组对象池** | `t_array` 128 槽 LIFO 复用池，减少 `malloc/free` 抖动 |
| **小字符串池** | 64KB bump allocator，≤512 字节字符串零 `malloc` |
| **1.5× 增长因子** | 数组 `push` 扩容时按 `cap + (cap>>1)` 而非 `2×` |
| **分支预测** | `likely`/`unlikely` 标注所有热路径 |
| **编译期类型折叠** | `is_int(42)` 等静态类型在编译期求值为 `true`/`false` |
| **嵌套类型追踪** | 2 层数组自动追踪元素类型（`$arr[0][0]`） |
| **零堆分配函数** | `time` `date` `hrtime` 使用静态缓冲区 |
| **error 安全退出** | 遍历全局资源链表释放所有对象/数组/字符串 |
| **跨平台** | `#ifdef _WIN32` 适配 Windows/Linux/macOS |
| **TCC 兼容** | 避免 TCC 不支持的 C99 特性 |

---

## 后续建议实现

按优先级和难度排序。

### 低难度（⭐ ~30 行/个）

| 函数 | 说明 | C 实现 |
|---|---|---|
| `intval($x)` | 转整数 | `(t_int)x` |
| `floatval($x)` | 转浮点 | `(t_float)x` |
| `strval($x)` | 转字符串 | `tphp_rt_str_from_int/float` |
| `boolval($x)` | 转布尔 | `(t_bool)x` |
| `rand($min, $max)` | 随机整数 | `rand() % (max-min+1) + min` |
| `mt_rand($min, $max)` | Mersenne Twister 随机数 | `rand()` 或 mt19937 |
| `defined("CONST")` | 常量是否定义 | 编译期可知 |

### 中等难度（⭐⭐ ~60 行/个）

| 函数 | 说明 | C 实现 |
|---|---|---|
| `strlen($s)` | 字符串长度 | `s.length` |
| `substr($s, $start, $len?)` | 子串 | `memcpy` 栈缓冲区 |
| `strpos($haystack, $needle)` | 查找子串位置 | `strstr` / 手写 |
| `trim($s)` / `ltrim` / `rtrim` | 去空白 | 手写循环 |
| `sprintf($fmt, ...)` | 格式化 | `snprintf` 栈缓冲区 |

### 较高难度（⭐⭐⭐ ~100 行/个）

| 函数 | 说明 | C 实现 |
|---|---|---|
| `array_shift($arr)` | 头部弹出 | 取首元素 + memmove |
| `file_get_contents($path)` | 读文件 | `fopen/fread/fclose` + `t_string` |
| `file_put_contents($path, $data)` | 写文件 | `fopen/fwrite/fclose` |
| `json_encode($val)` | JSON 序列化 | 手写递归（基本类型即可） |
| `json_decode($str)` | JSON 解析 | 手写 parser（复杂） |

### 设计限制（暂不实现）

| 函数 | 原因 |
|---|---|
| `try / catch / throw` | 需 `setjmp/longjmp` + 资源追踪重构 |
| `yield` 生成器 | 需状态机 + 协程支持 |
| `include / require / eval` | AOT 编译不支持 |
| `preg_match / preg_replace` | 需嵌入 PCRE，体积巨大 |
| `PDO / mysqli` | 需链接外部库 |
