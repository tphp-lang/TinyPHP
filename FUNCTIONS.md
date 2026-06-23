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
| 键名解构 `["key" => $v]` | ✅ (7.1+) | ✅ |

**差异**：不支持键名解构。内存安全：生成临时数组指针，读取后立即释放。

---

## JSON

### `json_encode($val)` — JSON 序列化

```
$s = json_encode([1, 2, 3]);   // "[1,2,3]"
$s = json_encode(true);        // "true"
$s = json_encode("hello");     // "\"hello\""
```

| | PHP | TinyPHP |
|---|---|---|
| null / bool / int / float / string | ✅ | ✅ |
| 数组（纯 int key） | ✅ | ✅（`[1,2,3]`） |
| 数组（含 string key） | ✅ | ✅（自动检测 → `{"k":"v"}`） |
| 嵌套数组 | ✅ | ✅（递归，受 C 栈限制，实测数千层） |
| 字符串转义 `" \n \t \\` | ✅ | ✅ |
| 对象 | ✅ | ❌（输出 `{}`） |
| JSON 美化/选项 | ✅ | ❌ |

**差异**：float 使用 `%.14g` 格式避免 `3.1400000000000001` 精度尾巴。对象序列化不支持（输出 `{}`）。

---

### `json_decode($str)` — JSON 解析

```
$v = json_decode("[1,2,3]");       // mixed (t_var)
$v = json_decode('{"x":1}');       // t_var → is_array($v) = true
$v = json_decode("not json");      // Fatal error（无效格式 abort）
```

| | PHP | TinyPHP |
|---|---|---|
| null / bool / int / float / string | ✅ | ✅ |
| 数组 `[...]` | ✅ | ✅ |
| 对象 `{...}` | ✅ | ✅（存为 t_array with string keys） |
| 嵌套 | ✅ | ✅（递归，无硬编码深度限制） |
| 字符串转义 `\n \t \\ \" \uXXXX` | ✅ | ✅（`\u` → `?`） |
| 格式错误安全返回 | ✅（返回 null） | ✅（完全无效 abort，部分解析返回 NULL + 释放内存） |
| 截断输入 | ✅ | ✅（`[1,2,` → NULL） |

**差异**：返回类型为 `t_var`（mixed），需 `is_array`/`is_int` 等运行时检测。`\uXXXX` 简单映射为 `?`。

---

## 常量

### `const` — 三种作用域常量

**全局常量**（任意 `.php` 文件顶层）：
```
const APP_NAME = "MyApp";
const MAX     = 100;
```

**命名空间常量**（`namespace Foo;` 内）：
```
namespace Lib;
const VERSION = "1.0";
```

**类常量**（`class` 体内）：
```
class Demo {
    const string AAA = "hello";           // 隐式 public
    public const int TIMEOUT = 30;
    private const bool DEBUG = false;
    private const array TAGS = ["web"];
}
```

| | PHP | TinyPHP |
|---|---|---|
| 全局 `const X = val` | ✅ | ✅（`#define`） |
| 命名空间 `const X = val` | ✅ | ✅ |
| 类 `const TYPE X = val` | ✅ | ✅ |
| `public/private const` | ✅ | ✅ |
| `string/int/float/bool/array` 类型 | ✅ | ✅ |
| `self::CONST` 类内访问 | ✅ | ✅ |
| `ClassName::CONST` 外部访问 | ✅ | ✅（public 允许，private 报错） |
| 跨文件常量引用 | ✅ | ✅（`constTypes` 记录类型） |
| `const` 无类型标注 | ✅ | ✅（全局） |

**差异**：全局/命名空间常量无类型标注（`const X = 1`），类常量需类型标注（`const int X = 1`）。

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
| **数组对象池** | 128 槽 LIFO 复用池 + 1.5× 增长因子 |
| **小字符串池** | 64KB bump allocator，≤512B 零 `malloc` |
| **分支预测** | `likely`/`unlikely` 标注所有热路径 |
| **编译期类型折叠** | `is_int(42)` 等静态类型编译期求值 |
| **嵌套类型追踪** | 2 层数组自动追踪元素类型 |
| **JSON 编解码** | 基本类型+数组+对象+转义，无效 JSON → `error()` |
| **常量三作用域** | 全局/命名空间/类常量，`self::` / `Class::` 访问 |
| **键名解构** | `["key" => $v]` 支持（PHP 7.1+） |
| **sort/rsort** | libc `qsort` 原地排序 |
| **内置函数 40+** | 数组 18 个、字符串 8 个、类型 8 个、时间 5 个、JSON 2 个 |
| **error 安全退出** | 遍历资源链表释放所有对象/数组/字符串 |
| **跨平台** | TCC/GCC/Clang 编译通过，`#ifdef _WIN32` |
| **PHAR 自包含** | `tphp.phar` 内嵌 TCC + 头文件，单文件分发 |

---

## 字符串

### `strlen($s)` — 字符串长度

```
$len = strlen("hello");   // 5
```

| | PHP | TinyPHP |
|---|---|---|
| 返回类型 | int | `t_int` |
| null 输入 | 报错 | 返回 0 |
| 空字符串 | 0 | 0 |

**差异**：直接返回 `s.length`，零开销。

---

### `trim($s)` / `ltrim($s)` / `rtrim($s)` — 去除空白

```
trim("  hi  ");    // "hi"
ltrim("  hi  ");   // "hi  "
rtrim("  hi  ");   // "  hi"
```

| | PHP | TinyPHP |
|---|---|---|
| 空白字符 | ` \t\n\r\0\x0B` | `<= 0x20`（ASCII 控制字符） |
| 自定义字符集 | ✅ | ❌ |
| 内存 | 堆 | `str_pool_alloc` |

**差异**：仅去除 ASCII 空白控制字符（`<= ' '`），不支持自定义字符集。

---

### `substr($s, $offset, $length?)` — 子串

```
substr("hello", 1, 3);   // "ell"
substr("hello", -2, 0);  // "lo"
substr("hello", 0, -1);  // "hell"
```

| | PHP | TinyPHP |
|---|---|---|
| 负数 offset | ✅ | ✅ |
| 负数 length | ✅（从末尾去掉 N 字符） | ✅ |
| length=0 | ✅（到末尾） | ✅ |
| 越界 | 空字符串 | 空字符串 |

**差异**：负数 length 行为与 PHP 一致。内存通过 `str_pool_alloc` 分配。

---

### `strpos($haystack, $needle)` — 查找子串位置

```
strpos("hello", "ll");   // 2
strpos("hello", "xx");   // -1 (PHP: false)
```

| | PHP | TinyPHP |
|---|---|---|
| 未找到 | `false` | `-1` |
| 空 needle | 报错 | 返回 0 |
| 偏移参数 | ✅ | ❌ |

---

### `str_contains($haystack, $needle)` — 是否包含子串

```
str_contains("hello", "ll");   // true
str_contains("hello", "xx");   // false
```

调用 `strpos ≥ 0` 实现，返回 `t_bool`。

---

### `str_replace($search, $replace, $subject)` — 子串替换

```
str_replace("a", "X", "abcabc");  // "XbcXbc"
```

| | PHP | TinyPHP |
|---|---|---|
| 全部替换 | ✅ | ✅ |
| 数组参数 | ✅ | ❌ |
| 计数参数 | ✅ | ❌ |

**实现**：两遍扫描（计数 + 构建），`str_pool_alloc` 分配新 buffer。

---

## 数组（续）

### `array_shift($arr)` — 头部弹出

```
$val = array_shift($arr);
```

| | PHP | TinyPHP |
|---|---|---|
| 空数组 | null | NULL（`VAR_NULL()`） |
| 返回类型 | mixed | `t_var` |
| 性能 | O(1) | O(n) memmove |

**内存安全**：释放弹出 entry 的 string key，memmove 左移剩余元素。

---

### `array_unshift($arr, $val)` — 头部追加

```
$len = array_unshift($arr, 99);
```

| | PHP | TinyPHP |
|---|---|---|
| 返回值 | 新长度 | 新长度 |
| 多值追加 | ✅ | ❌（仅单值） |

memmove 右移 + 重编号 int key。

---

### `array_sum($arr)` / `array_product($arr)` — 求和/求积

```
$sum = array_sum([1, 2, 3]);       // int(6)
$prod = array_product([1, 2, 3]);   // int(6)
```

| | PHP | TinyPHP |
|---|---|---|
| int 元素 | ✅ | ✅ |
| float 元素 | 自动提升为 float | ✅ |
| 空数组 | sum=0, product=1 | 同 |
| 返回类型 | int/float | `t_var` |

**差异**：只要有一个 float 元素，结果自动提升为 float。

---

### `array_unique($arr)` — 去重

```
$u = array_unique([1, 2, 2, 3]);  // [1, 2, 3]
```

| | PHP | TinyPHP |
|---|---|---|
| 保留首次出现 | ✅ | ✅ |
| 比较类型 | = 松散比较 | 严格类型+值比较 |
| SORT 选项 | ✅ | ❌ |

O(n²) 双重循环，新建数组，不修改原数组。

---

### `array_reverse($arr, $preserve_keys?)` — 反转

```
$r = array_reverse([1, 2, 3]);  // [3, 2, 1]
```

倒序遍历新建数组。`preserve_keys=true` 时 string key 深拷贝。

---

### `array_slice($arr, $offset, $length?, $preserve_keys?)` — 切片

```
array_slice([10,20,30,40], 1, 2);  // [20, 30]
array_slice([10,20,30,40], -2, 0); // [30, 40]
```

| | PHP | TinyPHP |
|---|---|---|
| 负数 offset | ✅ | ✅ |
| length=0 | 到末尾 | ✅ |
| preserve_keys | ✅ | ✅ |

---

## 通用

### `max($arr)` / `min($arr)` — 最值

```
$mx = max([5, 99, 3]);   // 99
$mn = min([5, 99, 3]);   // 3
```

| | PHP | TinyPHP |
|---|---|---|
| 空数组 | Warning | `error()` 退出 |
| 非数值 | 跳过 | 跳过 |
| 多参数 | ✅ | ❌（仅数组） |
| 返回类型 | mixed | `t_var` |

---

### `range($start, $end, $step?)` — 范围数组

```
range(1, 5);       // [1, 2, 3, 4, 5]
range(10, 1, -3);  // [10, 7, 4, 1]
```

| | PHP | TinyPHP |
|---|---|---|
| step=0 | ValueError | `error()` 退出 |
| 返回类型 | array | `t_array*` |

预知长度一次分配全部 entry，零 realloc。

---

### `array_fill($start_index, $count, $value)` — 填充数组

```
array_fill(0, 3, 99);  // [99, 99, 99]
```

`count < 0` → `error()` 退出。通过 `set_int` 设置指定 key。

---

### `sort($arr)` / `rsort($arr)` — 原地排序

```
sort([30, 10, 20]);   // [10, 20, 30]
rsort([30, 10, 20]);  // [30, 20, 10]
```

libc `qsort` 原地排序，重编号 int key。比较 int/float 值，忽略非数值类型。

---

### `sprintf($fmt, ...$args)` — 格式化字符串

```
sprintf("Hi %s", "Alice");          // "Hi Alice"
sprintf("Age: %d", 30);             // "Age: 30"
sprintf("%s is %d", "Bob", 25);    // "Bob is 25"
```

| | PHP | TinyPHP |
|---|---|---|
| `%s` `%d` `%f` | ✅ | ✅ |
| 可变参数 | ✅ | ✅（snprintf 256B 栈缓冲） |
| `%02d` 等格式标记 | ✅ | ✅ |
| 完整 `printf` 语法 | ✅ | ✅（委托 snprintf） |

**内存安全**：256 字节栈缓冲区，通过 `str_pool_alloc` 分配结果。

---

## 魔术常量

### `__LINE__` / `__FILE__` / `__DIR__` — 编译期常量

```
echo __LINE__;    // 当前行号
echo __FILE__;    // 完整文件路径
echo __DIR__;     // 文件所在目录
```

| | PHP | TinyPHP |
|---|---|---|
| 编译期替换 | runtime | **编译期常量**（AOT 天然支持） |
| 跨文件引用 | ✅ | ✅ |
| `__DIR__` 等价 `dirname(__FILE__)` | ✅ | ✅ |

### `DIRECTORY_SEPARATOR` — 目录分隔符

```
echo DIRECTORY_SEPARATOR;  // "/" (Linux/macOS) 或 "\" (Windows)
```

编译期替换为 `STR_LIT("/")` 或 `STR_LIT("\\")`。

---

## C 互操作

### `#include "file.h"` — 引入 C 头文件

在 PHP 中直接写 `#include "demo.h"`，转译后在生成的 C 代码顶部输出 `#include "demo.h"`。编译时自动添加所在目录为 `-I` 路径。

### `C->function()` — 直接 C 调用

`C->calc_distance(...)` 生成原生 C 函数调用 `calc_distance(...)`（无 `tphp_` 前缀）。

### 类型桥接 (`p2c.h`)

| PHP → C | C → PHP |
|---|---|
| `c_int($x)` → `(int32_t)` | `php_int($x)` → `(t_int)` |
| `c_float($x)` → `(double)` | `php_float($x)` → `(t_float)` |
| `c_str($x)` → `$x.data` | `php_str($x)` → `tphp_rt_str_dup(...)` |

所有函数默认引入（`common.h` 已包含 `p2c.h`）。

---

## 后续建议实现

| 函数 | 说明 |
|---|---|
| `file_get_contents/file_put_contents` | 文件 I/O |

### AOT 不可行

| 特性 | 原因 |
|---|---|
| `eval($code)` | 需要运行时 PHP 解析器 |
| `include $dynamicPath` / `require` | 编译期路径不可知 |
| `$$var` 可变变量 | 编译期无法确定符号 |
| `$obj->$prop` / `new $class()` | 运行时动态解析 |
| `extract()` / `compact()` | 动态符号表操作 |
| `Reflection*` / `get_class_methods()` | 需运行时元数据 |
| `serialize` / `unserialize` | 需类型反射 |
| `__call` / `__get` / `__set` 魔术方法 | 运行时分发 |
| `Closure::bind()` / `Closure::fromCallable()` | 运行时作用域绑定 |
| `preg_match` / PCRE 正则 | 需嵌入 ~200KB 库 |
| `PDO` / `mysqli` | 需外部链接 |
