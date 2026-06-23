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
```

### 独立 PHAR（推荐）

[GitHub Actions](https://github.com/KingBes/TinyPHP/actions) 自动构建全平台单文件：

```bash
tphp main.php    # include/ + tcc/ 自动解压到同级目录
```

## 性能

读/遍历总体 **2~5×** 快于 PHP 8.x：

| 场景 | TinyPHP | PHP 8.x | 比率 |
|---|---|---|---|
| foreach 1K ×100K | 581 ms | 1,885 ms | **3.2×** |
| count + for ×100K | 42 ms | 228 ms | **5.4×** |
| 嵌套数组读 ×100K | 1.2 ms | 3.9 ms | **3.2×** |
| array_pop ×100K | 2.3 ms | 4.3 ms | **1.8×** |
| 数组创建（push 1000×100）| 4.1 ms | 1.8 ms | 2.3× 慢* |

\* 创建开销来自 C `malloc`，GCC/Clang 编译可改善。详见 [ROADMAP.md](ROADMAP.md)。

## 编译流水线

```
PHP → Lexer → Token[] → Parser → AST → CodeGenerator → .c → 编译器 → 二进制
                                    include/  (C 运行时头文件)
```

- **Lexer**: 逐字符扫描，~75 种 Token，支持字符串插值/heredoc
- **Parser**: 递归下降，运算符优先级完整
- **CodeGenerator**: 访问者模式，生成类型安全的 C 代码
- **C 运行时**: 静态 inline 头文件库，128 槽数组复用池，64KB 字符串池
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
| `mixed` / `int\|string` | `t_var`（类型标签 union） |

### 运算符

`+` `-` `*` `/` `%` `**` `.` `=` `+=` `-=` `*=` `/=` `.=`
`==` `!=` `===` `!==` `<` `>` `<=` `>=` `<=>` `&&` `||` `!`
`&` `|` `^` `~` `<<` `>>` `++` `--` `?:` `??` `?->` `(int)` `(float)` `(string)` `(bool)`

### 语法

`if/elseif/else` · `while` · `do-while` · `for` · `foreach` · `switch/case/default` · `match`（多条件 `1,2=>...`）· `break/continue/goto` · `class/method/property` · `new` · `namespace/use` · `enum` · `function` · `closure/use` · `fn($x) => expr` · `const`（全局/命名空间/类）· `list()`/`[]` 解构（含键名 `"key"=>$v`）· `self::CONST`/`Class::CONST` · `?->` nullsafe · `never` 返回类型 · `__construct(public $x)` 属性提升 · `static`/`final`/`readonly` 修饰符 · `#include "file.h"` C 互操作 · `C->function()` 直接 C 调用 · `__LINE__`/`__FILE__`/`__DIR__`/`DIRECTORY_SEPARATOR`

### 内置函数

| 类别 | 函数 |
|---|---|
| 输出 | `echo`, `var_dump` |
| 数组 | `count`, `array_push`, `array_pop`, `array_shift`, `array_unshift`, `in_array`, `array_key_exists`, `array_keys`, `array_values`, `array_merge`, `array_unique`, `array_reverse`, `array_slice`, `array_sum`, `array_product`, `array_fill`, `sort`, `rsort` |
| 字符串 | `implode`, `explode`, `strlen`, `trim/ltrim/rtrim`, `substr`, `strpos`, `str_contains`, `str_replace`, `sprintf` |
| 类型 | `is_int/float/string/bool/array/null/object/callable`, `isset`, `empty`, `unset` |
| 转换 | `intval`, `floatval`, `strval`, `boolval` |
| 通用 | `max`, `min`, `range`, `rand`, `mt_rand`, `exit/die`, `error` |
| 时间 | `time`, `date`, `sleep`, `usleep`, `hrtime` |
| JSON | `json_encode`, `json_decode` |

> 详见 [FUNCTIONS.md](FUNCTIONS.md) — 每个函数与 PHP 的差异对照。

### C 互操作

```php
#include "mylib.h"                          // 引入 C 头文件

$dist = php_float(C->calc_distance(        // C-> 直接调用
    c_float($x1), c_float($y1),
    c_float($x2), c_float($y2)
));
$rev = php_str(C->reverse_str(c_str($s)));  // c_str → php_str 类型桥接
```

| 函数 | 说明 |
|---|---|
| `c_int/c_float/c_str` | PHP → C 类型转换 |
| `php_int/php_float/php_str` | C → PHP 类型转换 |
| `C->function()` | 直接 C 调用，无 name mangling |
| `#include "file.h"` | 生成 `#include` 到 C 输出 |

### 内存安全

- `error()` 退出前遍历资源链表释放所有对象/数组/字符串
- 数组 128 槽 LIFO 复用池 + 1.5× 增长因子
- 64KB 小字符串池 bump allocator
- 引用计数 + `__destruct` 自动释放
- JSON 无效输入 → `error()` 报错退出

## CLI 选项

| 选项 | 说明 |
|---|---|
| `-o <output>` | 输出文件路径 |
| `-cc <compiler>` | 指定 C 编译器（默认内置 TCC） |
| `-h, --help` | 显示帮助 |

## 文档

| 文件 | 说明 |
|---|---|
| [FUNCTIONS.md](FUNCTIONS.md) | 每个函数的实现细节与 PHP 差异 |
| [CONTRIBUTING.md](CONTRIBUTING.md) | 架构、扩展指南、安全规范 |
| [ROADMAP.md](ROADMAP.md) | 性能优化路线图 |

## 许可证

MIT
