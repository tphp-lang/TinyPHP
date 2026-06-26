# TinyPHP

> PHP → C 转译编译器，将 PHP 子集转为原生二进制。

## 快速开始

```bash
# 编译单文件
php tphp.php test/var/var.php

# 编译多文件（入口必须有全局 class Main）
php tphp.php main.php demo.php

# 带 C 源文件
php tphp.php main.php bridge.php lib.c

# 扫描当前目录所有 .php / .c
php tphp.php .

# 指定输出 / 编译器
php tphp.php main.php -o app -cc gcc

# 跨平台编译
php tphp.php main.php -os linux            # x86_64 Linux
php tphp.php main.php -os linux -arch aarch64  # ARM64 Linux
php tphp.php main.php -os windows          # Windows .exe
```

## 入口文件

```php
<?php 

class Main{

    public function main(): void
    {
        echo "hello world\n";
    }
}
```

### 独立 PHAR（推荐）

[GitHub Actions](https://github.com/KingBes/TinyPHP/actions) 自动构建全平台单文件：

```bash
tphp main.php    # include/ + tcc/ 自动解压到同级目录
```

## 性能

2026-06-26 实测，PHP 8.5.1 vs TinyPHP × 3 编译器 (bench_tphp, 100K loops)：

| 场景 | PHP 8.5 | TCC | GCC -O2 | Clang -O2 |
|---|---|---|---|---|
| foreach 1K ×100K | 1,482 ms | 508 ms (**2.9x**) | 50 ms ⚡ **29.4x** | 47 ms ⚡ **31.6x** |
| count+for ×100K | 175 ms | 32 ms (**5.5x**) | 4.9 ms ⚡ **35.4x** | 4.9 ms ⚡ **36.1x** |
| 嵌套数组读 ×100K | 3.3 ms | 0.50 ms (**6.7x**) | 0.12 ms ⚡ **27.0x** | 0.14 ms ⚡ **23.1x** |
| int key 读取 ×100K | 2.1 ms | 0.30 ms (**7.1x**) | 0.12 ms ⚡ **18.0x** | 0.16 ms ⚡ **13.2x** |
| array_pop ×100K | 2.9 ms | 1.4 ms (**2.0x**) | 0.38 ms ⚡ **7.6x** | 0.29 ms ⚡ **9.9x** |
| in_array ×100K | 48 ms | 97 ms (0.5x) | 18 ms ⚡ **2.6x** | 24 ms ⚡ **2.0x** |
| explode+implode ×10K | 2.9 ms | **9.8 ms** (0.3x) | **5.4 ms** (0.5x) | **5.8 ms** (0.5x) |
| array_push ×100K | 1.8 ms | 4.4 ms (0.4x) | 3.3 ms (0.5x) | 2.9 ms (0.6x) |

> ⚡ **GCC/Clang -O2 下 7/10 项反超 PHP，最高 36.1x。**  
> 🔧 explode+implode 经 O(N²)→O(N) 优化后 **2-3x 提速**（18.7→9.8ms TCC, 15.8→5.4ms GCC）。  
> 用法: `tphp main.php -cc gcc` 或 `tphp main.php -cc clang`

### 核心优化

| 优化 | 来源 | 效果 |
|------|------|------|
| **ROPE 多片段拼接** | PHP ROPE opcode | concat-4 从 14x 慢 → 6.1x 快 |
| **implode 两遍扫描** | 自研 (O(N²)→O(N)) | explode+implode **2-3x 提速** |
| **explode 精确容量** | 预数分隔符 | 消除全部 realloc |
| **JSON 位图+批量写入** | PHP `json_encoder.c` | json_encode 11x→1.2x 慢 |
| **数组池预热** | PHP zend_alloc bin | arr-create 12x→4.4x 快 |
| **CodeGen 作用域提升** | 自研 | 消除跨块未定义变量错误 |

详见 [BENCHMARK_RESULTS.md](BENCHMARK_RESULTS.md) 和 [ROADMAP.md](ROADMAP.md)。

## 编译流水线

```
PHP → Lexer → Token[] → Parser → AST → CodeGenerator → .c → 编译器 → 二进制
                                    include/  (C 运行时头文件)
```

- **Lexer**: 逐字符扫描，~75 种 Token，支持字符串插值/heredoc
- **Parser**: 递归下降，运算符优先级完整
- **CodeGenerator**: 访问者模式，生成类型安全的 C 代码
- **C 运行时**: COS 风格对象系统（16B 头 + struct 嵌套继承），setjmp/longjmp 异常，ROPE 多片段字符串拼接，256 位 JSON 转义位图，128 槽数组复用池+启动预热，64KB 字符串池
- **编译器**: 内置 TCC (mob 分支)，支持 GCC/Clang

## 支持特性

### 类型系统

| PHP | C |
|---|---|
| `int` | `int64_t` |
| `float` | `double` |
| `string` | `struct { char *data; int length; }` |
| `bool` | `bool` |
| `array` | `t_array*`（有序映射，int/string 键） |
| `callable` | `t_callback` |
| `Exception` | 内置类（`include/object/exception.h`） |
| `mixed` / `int\|string` | `t_var`（类型标签 union） |

### OOP（COS 风格对象系统）

对象头 16 字节（`cls` 指针 + `refcount`），继承用 struct 嵌套。对象离开作用域自动析构（`tp_obj_release`）：

```php
class Dog extends Animal { ... }     // extends ✅
abstract class Entity { ... }        // abstract ✅
interface Named { ... }              // interface ✅
class User implements Named { ... }  // implements ✅
trait Loggable { ... use... }        // trait + use ✅
__destruct() { ... }                 // 自动析构 ✅
```

### 异常处理（COS setjmp/longjmp）

```php
try { $this->validate(-1); }
catch (Exception $e) { echo $e; }
finally { cleanup(); }
throw new Exception('error');
```

### 运算符

`+` `-` `*` `/` `%` `**` `.` `=` `+=` `-=` `*=` `/=` `.=`
`==` `!=` `===` `!==` `<` `>` `<=` `>=` `<=>` `&&` `||` `!`
`&` `|` `^` `~` `<<` `>>` `++` `--` `?:` `??` `?->` `(int)` `(float)` `(string)` `(bool)`

### 语法

`if/elseif/else` · `while` · `do-while` · `for` · `foreach` · `switch/case/default` · `match`（多条件 `1,2=>...`）· `break/continue/goto` · `class/method/property` · `new` · `namespace/use`（含分组 `use A\{B,C}`）· `enum` · `function` · `closure/use` · `fn($x) => expr` · `const`（全局/命名空间/类）· `list()`/`[]` 解构（含键名 `"key"=>$v`）· `self::CONST`/`Class::CONST` · `?->` nullsafe · `never` 返回类型 · `__construct(public $x)` 属性提升 · `static`/`final`/`readonly` 修饰符 · `__LINE__`/`__FILE__`/`__DIR__`/`DIRECTORY_SEPARATOR` · `extends` · `interface` · `implements` · `abstract class`/`abstract method` · `trait` + `use TraitName` · `try`/`catch`/`finally` · `throw new Exception("msg")` · `__destruct` 自动析构

### 内置函数

| 类别 | 函数 |
|---|---|
| 输出 | `echo`, `var_dump` |
| 数组 | `count`, `array_push`, `array_pop`, `array_shift`, `array_unshift`, `in_array`, `array_key_exists`, `array_keys`, `array_values`, `array_merge`, `array_unique`, `array_reverse`, `array_slice`, `array_sum`, `array_product`, `array_fill`, `array_search`, `sort`, `rsort`, `shuffle` |
| 字符串 | `implode`, `explode`, `strlen`, `trim/ltrim/rtrim`, `substr`, `strpos`, `str_contains`, `str_replace`, `strtolower`, `strtoupper`, `sprintf` |
| 类型 | `is_int/float/string/bool/array/null/object/callable`, `isset`, `empty`, `unset` |
| 转换 | `intval`, `floatval`, `strval`, `boolval` |
| 数学 | `abs`, `round`, `ceil`, `floor`, `sqrt` |
| 通用 | `max`, `min`, `range`, `rand`, `mt_rand`, `exit/die`, `error` |
| 时间 | `time`, `date`, `sleep`, `usleep`, `hrtime`, `microtime` |
| JSON | `json_encode`, `json_decode` |

> 详见 [FUNCTIONS.md](FUNCTIONS.md) — 每个函数与 PHP 的差异对照。

### C 互操作（PHPC）

> 完整实现：`include/phpc.h`（~180 行），通过 `#include`/`#flag`/`C->call`/`c_*`/`php_*`/`phpc_*` 实现 PHP ↔ C 双向互操作。所有 phpc 函数为**全局函数**，不受命名空间 mangle。测试：`test/phpc/`。

#### 编译控制

```php
#include "include/demo.h"       // 项目头文件 → #include "include/demo.h"
#include <math.h>                 // 系统头文件 → #include <math.h>
#flag Linux -lm                  // Linux 链接数学库
#flag GCC -O2 -DNDEBUG           // 仅 GCC 优化
#flag Clang -Wall -Werror        // 仅 Clang 严格警告
```

| 指令 | 语法 | 编译器输出 | 去重 |
|------|------|-----------|------|
| `#include "path"` | 项目相对路径 | `#include "path"` | 按文件名去重 |
| `#include <name>` | 系统头文件 | `#include <name>` | 同上 |
| `#flag [CC] [OS] flags` | 编译器+平台过滤 | 仅匹配时追加到命令行 | 按标志串去重 |

`#flag` 过滤规则：不写 = 全平台+全编译器。`MacOS` 映射到 `Darwin`。

#### 基础类型桥接

```
PHP → C:                  C → PHP:
c_int($x)   → int32_t     php_int(v)   → t_int
c_float($x) → double      php_float(v) → t_float
c_str($s)   → const char*  php_str(s)  → t_string (深拷贝)
```

直接 C 调用：`C->function(args)` → 生成原生 `function(args)`，无 `tphp_fn_` 前缀。

#### 数组互操作

**严格 C 风格**：`phpc_arr_int($arr)` 要求所有元素为 `TYPE_INT`，否则 `error()` 退出。`phpc_arr_dbl` 接受 `int` 或 `float`。

```php
// 模式: 提取 → C 操作 → 释放
function sum_array(array $arr): int {
    $data = phpc_arr_int($arr);                      // → int32_t* (malloc)
    $result = C->sum_ints($data, c_int(count($arr))); // C 操作
    phpc_free($data);                                 // 释放！
    return php_int($result);
}
```

| PHP → C | 要求 | 返回 |
|----------|------|------|
| `phpc_arr_int($arr)` | 全部 TYPE_INT | `int32_t*` (malloc) |
| `phpc_arr_dbl($arr)` | TYPE_INT 或 TYPE_FLOAT | `double*` (malloc) |
| `phpc_arr_str($arr)` | 全部 TYPE_STRING | `char**` (malloc，每个字符串独立分配) |

| C → PHP | 说明 |
|----------|------|
| `phpc_new_arr_int(src, len)` | `int32_t[]` → `t_array*` |
| `phpc_new_arr_dbl(src, len)` | `double[]` → `t_array*` |
| `phpc_new_arr_str(src, len)` | `char*[]` → `t_array*` |
| `phpc_new_arr()` | 空数组 |

#### 对象互操作

TinyPHP 对象 = `t_object` 头部 + 字段，`phpc_obj` 提取底层 C 结构体指针：

```php
class MyPoint { public float $x; public float $y; }

function obj_read_x(MyPoint $p): float {
    $ptr = phpc_obj($p);                 // → void* (即 tphp_class_MyPoint*)
    return php_float(C->read_field($ptr, c_int(16))); // offsetof(x)
}
```

| 函数 | 方向 | 说明 |
|------|------|------|
| `phpc_obj($obj)` | PHP→C | 提取底层 C 结构体指针（`void*`） |
| `phpc_new_obj(ptr, vtable)` | C→PHP | 包裹 C 指针为 PHP 对象（vtable 管理析构） |

#### 回调互操作

**有 env 回调** — `phpc_fn_i32` + `phpc_env`：

```php
$square = function(int $x): int { return $x * $x; };
$result = C->apply_closure(
    phpc_fn_i32($square),   // → int32_t(*)(int32_t, void*)
    phpc_env($square),      // → void* (env)
    c_int(5)
);
// C 侧: int64_t apply_closure(int32_t (*fn)(int32_t, void*), void* env, int32_t val)
```

| 函数 | 返回类型 |
|------|---------|
| `phpc_fn_i32($cb)` | `int32_t(*)(int32_t, void*)` |
| `phpc_fn_i64($cb)` | `int64_t(*)(int64_t, void*)` |
| `phpc_fn_f64($cb)` | `double(*)(double, void*)` |
| `phpc_fn($cb)` / `phpc_env($cb)` | `void*`（通用） |

**无 env 回调** — `#callback` + `phpc_thunk()`（thunk 嵌入 env，任意签名）：

```php
#callback double fold_cb(int32_t idx, double val)  // 声明 C 回调签名

C->fold_dbl($data, $len, phpc_thunk('fold_cb', $fn));  // 按签名生成 thunk
// thunk: static double _thunk_N(int32_t idx, double val) { ... env嵌入 ... }
```

| 函数 | 说明 |
|------|------|
| `phpc_new_fn(func)` → `t_callback` | C 函数指针 → t_callback |
| `phpc_new_fn_env(func, env)` | 带环境版本 |

#### 内存安全

| 函数 | 说明 |
|------|------|
| `phpc_free(ptr)` | `free(ptr)`（NULL 安全） |
| `phpc_free_str_arr(strs, len)` | 先 `free` 每个字符串，再 `free` 指针数组 |

**关键规则**：`phpc_arr_*` 返回的指针必须通过 `phpc_free` 释放。`phpc_new_arr_*` 返回的 `t_array*` 由 TinyPHP 引用计数自动管理。

### 内存安全

- `error()` 退出前遍历资源链表释放所有对象/数组/字符串
- 数组 128 槽 LIFO 复用池 + 启动预热 + 1.5× 增长因子
- 64KB 小字符串池 bump allocator
- ROPE 多片段拼接：单次分配代替 N 次 pair-wise 分配
- 引用计数 + `__destruct` 自动释放
- JSON 无效输入 → `error()` 报错退出

## 独特机制

### COS 风格对象系统

| 特性 | 说明 |
|---|---|
| 对象头 | 16 字节（`cls` + `refcount`），比 PHP 的 zend_object（~80B）精简 5× |
| 继承 | struct 嵌套 `_parent`，父子强转零开销 |
| 析构 | `tp_obj_release` — 作用域结束时自动调用 `__destruct` + `free` |
| 拓扑排序 | 编译器自动保证父类 struct 先于子类生成 |

### 编译期 AOT

| 特性 | 说明 |
|---|---|
| 类型固定 | 变量类型在赋值时确定，后续不变 — 零运行时类型检查 |
| VTable 直接调用 | 无哈希表分发开销，方法调 = 间接函数指针 |
| 闭包 | 编译为 static C 函数 + env 参数 |
| 魔术常量 | `__LINE__`/`__FILE__`/`__DIR__`/`__CLASS__`/`__METHOD__` — 编译期替换 |

### 异常系统

| 特性 | 说明 |
|---|---|
| 实现 | `setjmp/longjmp`（COS 风格），零外部依赖 |
| 内存安全 | `tp_throw` 先 `tphp_rt_free_all()` 再跳转 |
| 消息缓冲 | 256 字节栈帧内缓冲，不依赖堆分配 |

### C 运行时

| 特性 | 说明 |
|---|---|
| 字符串池 | 64KB bump allocator，≤512B 零 `malloc` |
| 数组池 | 128 槽 LIFO 复用池 + 1.5× 增长因子 |
| 资源追踪 | 全局链表，`error()` 退出时遍历释放 |
| 分支预测 | `likely`/`unlikely` 标注热路径 |
| 异常安全 | 无效 JSON → `error()`，`tp_throw` → `tphp_rt_free_all()` |

### 多编译器 + 跨平台

| 编译器 | 状态 |
|---|---|
| TCC (mob) | ✅ 默认内置 |
| GCC | ✅ |
| Clang | ✅ |

| 平台 | 状态 |
|---|---|
| Windows x86_64 | ✅ |
| Linux x86_64 | ✅ |
| Linux aarch64 | ✅ |
| macOS aarch64 | ✅ |

## CLI 选项

| 选项 | 说明 |
|---|---|
| `-o <output>` | 输出文件路径 |
| `-cc <compiler>` | 指定 C 编译器（默认内置 TCC） |
| `-os <target>` | 跨编译目标：`windows`、`linux`、`macos` |
| `-arch <arch>` | 目标架构：`x86_64`、`aarch64`（Windows/Linux 默认 x86_64，macOS 默认 aarch64） |
| `-h, --help` | 显示帮助 |

## 测试

```bash
php tester.php                    # 全部测试
php tester.php test/var/string.php # 单个测试
```

测试注解：`// @skip` 跳过, `// @exit N` 期望退出码, `// @with a.php,b.php` 多文件编译。

CI (`tester.yml`) 在 Linux x86_64/aarch64、macOS aarch64、Windows x86_64 四个平台全量测试。

## 文档

| 文件 | 说明 |
|---|---|
| [FUNCTIONS.md](FUNCTIONS.md) | 每个函数的实现细节与 PHP 差异 |
| [GRAMMAR.md](GRAMMAR.md) | 完整语法参考（基于 PHP 8.5 parser，标注支持程度） |
| [CONTRIBUTING.md](CONTRIBUTING.md) | 架构、扩展指南、安全规范 |
| [ROADMAP.md](ROADMAP.md) | 性能优化路线图 |

## 许可证

MIT
