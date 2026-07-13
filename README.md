<p align="center">
  <img src="./favicon.svg" width="300" height="300" alt="TinyPHP logo">
</p>

[![zread](https://img.shields.io/badge/Ask_Zread-_.svg?style=flat&color=00b0aa&labelColor=000000&logo=data%3Aimage%2Fsvg%2Bxml%3Bbase64%2CPHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHZpZXdCb3g9IjAgMCAxNiAxNiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQuOTYxNTYgMS42MDAxSDIuMjQxNTZDMS44ODgxIDEuNjAwMSAxLjYwMTU2IDEuODg2NjQgMS42MDE1NiAyLjI0MDFWNC45NjAxQzEuNjAxNTYgNS4zMTM1NiAxLjg4ODEgNS42MDAxIDIuMjQxNTYgNS42MDAxSDQuOTYxNTZDNS4zMTUwMiA1LjYwMDEgNS42MDE1NiA1LjMxMzU2IDUuNjAxNTYgNC45NjAxVjIuMjQwMUM1LjYwMTU2IDEuODg2NjQgNS4zMTUwMiAxLjYwMDEgNC45NjE1NiAxLjYwMDFaIiBmaWxsPSIjZmZmIi8%2BCjxwYXRoIGQ9Ik00Ljk2MTU2IDEwLjM5OTlIMi4yNDE1NkMxLjg4ODEgMTAuMzk5OSAxLjYwMTU2IDEwLjY4NjQgMS42MDE1NiAxMS4wMzk5VjEzLjc1OTlDMS42MDE1NiAxNC4xMTM0IDEuODg4MSAxNC4zOTk5IDIuMjQxNTYgMTQuMzk5OUg0Ljk2MTU2QzUuMzE1MDIgMTQuMzk5OSA1LjYwMTU2IDE0LjExMzQgNS42MDE1NiAxMy43NTk5VjExLjAzOTlDNS42MDE1NiAxMC42ODY0IDUuMzE1MDIgMTAuMzk5OSA0Ljk2MTU2IDEwLjM5OTlaIiBmaWxsPSIjZmZmIi8%2BCjxwYXRoIGQ9Ik0xMy43NTg0IDEuNjAwMUgxMS4wMzg0QzEwLjY4NSAxLjYwMDEgMTAuMzk4NCAxLjg4NjY0IDEwLjM5ODQgMi4yNDAxVjQuOTYwMUMxMC4zOTg0IDUuMzEzNTYgMTAuNjg1IDUuNjAwMSAxMS4wMzg0IDUuNjAwMUgxMy43NTg0QzE0LjExMTkgNS42MDAxIDE0LjM5ODQgNS4zMTM1NiAxNC4zOTg0IDQuOTYwMVYyLjI0MDFDMTQuMzk4NCAxLjg4NjY0IDE0LjExMTkgMS42MDAxIDEzLjc1ODQgMS42MDAxWiIgZmlsbD0iI2ZmZiIvPgo8cGF0aCBkPSJNNCAxMkwxMiA0TDQgMTJaIiBmaWxsPSIjZmZmIi8%2BCjxwYXRoIGQ9Ik00IDEyTDEyIDQiIHN0cm9rZT0iI2ZmZiIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgo8L3N2Zz4K&logoColor=ffffff)](https://zread.ai/tphp-lang/TinyPHP)

# TinyPHP

> **PHP → C AOT 编译器** — 用 PHP 语法写原生二进制，零运行时依赖，性能飙 300-500 倍。

TinyPHP **不是** PHP 解释器或运行时替代品。它把 PHP 代码（强类型子集）编译成安全的 C，再由 GCC/Clang/TCC 编译为原生可执行文件。没有 Zend VM、没有 OPCache、不需要 PHP 环境。

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

### CLI 选项

| 选项 | 说明 |
|---|---|
| `-o <output>` | 输出文件路径 |
| `-cc <compiler>` | 指定 C 编译器（默认内置 TCC） |
| `-os <target>` | 跨编译目标：`windows`、`linux`、`macos` |
| `-arch <arch>` | 目标架构：`x86_64`、`aarch64`（Windows/Linux 默认 x86_64，macOS 默认 aarch64） |
| `--debug` | 打印编译命令，运行二进制并与 `#debug` 预期输出逐行比对 |
| `-h, --help` | 显示帮助 |

### 测试与调试（`#debug`）

在源码任意位置插入 `#debug` 指令声明预期输出，配合 `--debug` 自动编译运行并比对：

```php
<?php
#debug int(42)
#debug string(5) "hello"
#debug bool(true)

class Main {
    public function main(): void {
        var_dump(42);
        var_dump("hello");
        var_dump(true);
    }
}
```

```bash
php tphp.php test.php --debug
# [YES] int(42)
# [YES] string(5) "hello"
# [YES] bool(true)
```

| 写法 | 含义 |
|------|------|
| `#debug text` | 预期该行输出为 `text`（精确匹配） |
| `#debug` | 预期该行为空行 |
| `#debug ~ text` | 预期近似值（如时间/时区相关），`[REF]` 只展示不判错 |

**文件级测试注解**（放在 `<?php` 同一行或下一行）：

| 注解 | 位置 | 含义 |
|------|------|------|
| `// @skip` (文件头注释) | `<?php` 同行 | CI 自动跳过该文件（如 OS 限定、需要外部环境） |
| `// @multi @with x,y` | `<?php` 同行 | 多文件编译提示 |

CI 测试自动发现：`php .github/scripts/run_tests.php` 递归扫描 `test/` 下所有含 `#debug` 且不含 `@skip` 的文件。


## 入口逻辑

TinyPHP 要求入口文件必须有一个**全局命名空间**（无 `namespace` 声明）的 `class Main`：

```php
<?php

class Main
{
    // 构造函数 — 接收命令行参数（可选，默认可省略）
    public function __construct(int $argc, array $argv)
    {
        // $argc — 参数个数，$argv — 参数数组
    }

    // 入口函数 — 必须为 public function main(): void
    public function main(): void
    {
        echo "hello world\n";
    }

    // 析构函数 — 程序退出前自动调用（可选）
    public function __destruct() {}
}
```

| 方法 | 签名 | 必须 | 说明 |
|------|------|------|------|
| `__construct` | `(int $argc, array $argv)` | 否 | 接收命令行参数；可省略 |
| `main` | `(): void` | **是** | 程序入口，必须强类型声明 |
| `__destruct` | `()` | 否 | 退出前自动调用，可省略 |

### 独立 PHAR（推荐）

[GitHub Actions](https://github.com/KingBes/TinyPHP/actions) 自动构建全平台单文件：

```bash
tphp main.php    # include/ + tcc/ 自动解压到同级目录
```

### 多文件编译

TinyPHP 支持 `@multi` 注解声明多文件入口（辅助文件用 `@skip` 标记无 Main 类）：

```php
// main.php
<?php // @multi @with models.php,services.php
use MyApp\Models\User;
// ...
```

## PHP 兼容度

基于 PHP 8.5 强类型语法，兼容度约 **80%**。核心约束：**AOT 编译不兼容动态特性**。

### ✅ 完全支持

| 类别 | 特性 |
|------|------|
| 控制流 | `if/elseif/else`、`while`、`do-while`、`for`、`foreach`、`switch`（含字符串 switch，全部支持 fall-through 穿透语义）、`match`、`break/continue/goto` |
| OOP | `class`、`extends`、`abstract`、`interface`、`implements`、`trait+use`、`enum`、`__construct(public $x)`、`__destruct`、`static/final/readonly`、`instanceof`、`self::`、`$this`、链式调用、`?->` 空安全 |
| 闭包 | `function() use($x) {}`、`fn($x): T => expr` 单表达式、`fn($x): T => { stmts; return expr; }` 块体、多捕获、嵌套闭包 |
| 异常 | `try/catch(Exception $e)/finally`、`throw new Exception()`、`throw` 表达式、`error($msg)` 抛出可捕获异常、`Type|Exception` 返回类型语法、`never` 返回类型 |
| 类型 | `int` `float` `string` `bool` `array` `callable` `void` `mixed` `self` 类类型、局部变量/全局常量可选类型标记（类属性/类常量必填） |
| 运算符 | 完整 15 级优先级：算术/比较/逻辑/位/三元 `?:`/空合并 `??`/太空船 `<=>`/自增自减/类型转换 |
| 命名空间 | `namespace A\B`、`use A\{B,C}` 分组导入、`use function A\{f1,f2}` 组合式函数导入、`use const A\{C1,C2}` 组合式常量导入、`use A\{B, function f, const C}` 混合导入 |
| 语法糖 | `list()/$a[] =` 解构、`$a[] = ` push、`[...$arr1, ...$arr2]` 数组展开（spread）、`int &$x` 引用传参（全类型）、`int $x = 10` 默认值参数（编译时重载）、`int $x = 42;` 局部变量可选类型标记、`const int MAX = 100;` 全局常量可选类型标记、字符串插值、heredoc、魔术常量 (`__LINE__` `__FILE__` `__DIR__`) |
| Generator | `yield`、`yield $k => $v`、`send()`、`getReturn()`、`return`、foreach 迭代（基于 minicoro 协程，不使用 yield 时零开销） |
| 多线程 | `Thread`/`Mutex`/`CondVar`/`WaitGroup` OOP 线程 API（基于 tinycthread，Thread-Local 运行时无锁竞争） |
| 注解 | `#[Attribute(p: type, ...)] const NAME = [];` 声明 + `#[NAME(args)]` 使用（仅位置参数），`ROUTE[0]->call()/newInstance()` 编译期展开为零开销直接调用，详见 [GRAMMAR.md §14](GRAMMAR.md) |

### ❌ 不支持（AOT 物理不可行）

| 特性 | 原因 | 替代方案 |
|------|------|---------|
| `eval()` | 没有运行时解释器 | `switch`/`match` 分支调度，或回调分发 |
| `$$var` 可变变量 | 编译时不知道变量名 | `array` 映射：`$map[$key]` 替代 `$$key` |
| `include/require` | 没有运行时文件加载 | `#include` 引入 C 头文件，或多文件编译 |
| `__call` `__get` `__set` | 没有运行时分发 | 显式定义方法，或用 `switch` 在单个方法内分发 |
| `$obj->{$method}()` | 编译时不知道方法名 | 回调 map：`$fn = $map[$name]; $fn($args);` |

### ⬜ 不做（权衡决定）

| 特性 | 原因 | 替代方案 |
|------|------|---------|
| `?int` 可空类型 | AOT 下 null 分支需要运行时分发 | 用 `mixed` 替代，或拆分为两个重载函数 |
| `...$args` 可变参数 | 需要动态栈构造 | 传 `array` 替代 |
| `callable $fn = "func"` 默认值 | 编译时无法将字符串函数名转换为函数指针 | 每次调用时显式传入闭包 |

> `int|string` 联合类型和 `mixed` 已支持。

### ⚠️ 与原生 PHP 的差异（Generator）

基于 minicoro 协程库实现，核心语义与 PHP 一致，但有以下 AOT 约束导致的差异：

| 差异点 | 原生 PHP | TinyPHP | 原因 |
|--------|----------|---------|------|
| `callable` 参数传字符串函数名 | `gen(1, 3, "apply")` 可行 | 不可行，须用闭包 `gen(1, 3, fn($x) => apply($x))` | AOT 编译期无法将运行时字符串解析为函数符号 |
| 实现机制 | Zend VM 内部 Generator 对象 | minicoro 协程（ASM/ucontext/Fiber） | AOT 无运行时 VM，需独立协程库 |
| macOS + TCC | 正常 | **pthread 线程模拟**（性能略低于协程） | TCC 的 ucontext/ASM 在 Apple Silicon 上不兼容，改用 pthread 线程模拟协程语义 |
| 不使用 yield 的函数 | 无差异 | 零开销，编译为普通函数 | 双函数变换：生成器函数 → 协程入口 + 包装器，普通函数不受影响 |

支持的 yield 形态：`yield $v`、`yield $k => $v`、`return $v`（配合 `getReturn()`）、`send($v)` 双向传值。详见 [FUNCTIONS.md](FUNCTIONS.md)。

### 🔢 内置函数

已实现 **281+ 个**内置函数，覆盖 PHP 标准库的常用子集，覆盖数组/字符串/数学/时间/JSON/哈希/password(bcrypt)/进程控制/CSPRNG/ctype/正则表达式(PCRE NFA VM)/字符集转换(iconv)/过滤器(filter_var)/多线程(Thread/Mutex/CondVar/WaitGroup) 等。详见 [FUNCTIONS.md](FUNCTIONS.md)。

## 独有特性

### AOT 类型固定

变量类型在首次赋值时确定，之后不可变。`===` 和 `==` 等价——编译期已知类型，**零运行时类型检查**。

### COS 风格对象系统

对象头仅 16 字节（比 PHP 的 ~80B 精简 5 倍），struct 嵌套继承无开销，VTable 直接函数指针调用。作用域结束时自动调用 `__destruct` + 释放。

### 多层内存优化

| 层 | 机制 | 效果 |
|----|------|------|
| SSO 小字符串 | 24B 内联缓冲区 | ≤23 字节零堆分配 |
| 128KB 字符串池 | bump allocator + Arena 溢出块 | O(1) 分配，批量释放 |
| 128 槽数组复用池 | LIFO + 1.5× 增长 | 热路径零 malloc |
| 128 槽对象复用池 | LIFO | new+unset 提速 36-52% |
| ROPE 多片段拼接 | 编译期展平为单次分配 | concat-4 提速 6 倍 |
| Thread-Local 运行时 | 每线程独立 str_pool/arr_pool/obj_pool | 多线程无锁竞争 |

### 三编译器 + 四平台

| | TCC | GCC | Clang |
|---|---|---|---|
| **Windows x86_64** | ✅ 默认内置 | ✅ | ✅ |
| **Linux x86_64** | ✅ | ✅ | ✅ |
| **Linux aarch64** | ✅ | ✅ | ✅ |
| **macOS aarch64** | ✅ | ✅ | ✅ |

TCC 亚秒编译，GCC/Clang -O2 带来 3-10 倍额外提速。`compat.h` 统一处理三编译器差异。

### 多线程支持

基于 tinycthread（zlib license）跨平台线程库，提供 OOP 风格线程 API。采用 **Thread-Local 运行时**策略：每个线程拥有独立的 `str_pool`/`arr_pool`/`obj_pool`，无锁竞争。

```php
// Thread + join
$thread = new Thread(function(): int {
    return 42;
});
$thread->start();
echo $thread->join();  // 42

// WaitGroup 跨线程同步
$wg = new WaitGroup();
$wg->add(1);
$t = new Thread(function() use ($wg): int {
    $wg->done();
    return 0;
});
$t->start();
$wg->wait();
$t->join();

// Mutex 互斥
$mutex = new Mutex(false);
$mutex->lock();
// ... 临界区 ...
$mutex->unlock();

// 静态方法
Thread::yield();
Thread::sleep(0.5);
$tid = Thread::id();
```

| 类 | 方法 | 说明 |
|---|---|---|
| `Thread` | `start/join/detach` + 静态 `yield/sleep/id` | 闭包跨线程传递（堆分配副本） |
| `Mutex` | `lock/tryLock/unlock` | `recursive` 选项：SRWLOCK（轻量）/ CRITICAL_SECTION（递归） |
| `CondVar` | `wait/signal/broadcast` | Windows CONDITION_VARIABLE / POSIX pthread_cond_t |
| `WaitGroup` | `add/done/wait` | 单 u64 state + Semaphore，等待 N 个线程完成 |

> TCC+Windows 通过 `compat/tls.h` 用 Windows TLS API 实现真正的线程隔离（TCC 不支持 `_Thread_local`）。详见 [FUNCTIONS.md](FUNCTIONS.md)。

### C 互操作（PHPC）

完整的 PHP ↔ C 双向互操作：`C->function(args)` 直接调用 C 函数，`C->CONST` 直接访问 C 枚举/宏常量，`C.Type` 类型注解支持 C 类型作为函数参数/返回值，`c_int/c_str` 类型桥接，数组/对象/回调互操作。详见下方 PHPC 章节。

### 扩展系统

对标 PHP extension，`#import` 按需引入。已内置 `pcntl`、`posix` 扩展：

```php
<?php
#import pcntl            // 引入进程控制扩展

class Main {
    public function main(): void {
        $pid = pcntl_fork();
    }
}
```

---

## C 互操作（PHPC）

> 完整实现：`include/phpc.h`（~240 行），通过 `#include`/`#flag`/`C->call`/`C->CONST`/`C.Type`/`c_*`/`php_*`/`phpc_*` 实现 PHP ↔ C 双向互操作。所有 phpc 函数为**全局函数**，不受命名空间 mangle。测试：`test/phpc/`。

### 编译控制

```php
#include "include/demo.h"                      // 项目头文件 → #include "include/demo.h"
#include Linux "linux_only.h"                  // 仅 Linux 引入
#include Windows <windows.h>                   // 仅 Windows 引入
#include __DIR__ . "/../demo.h"                // 相对源文件目录向上
#include __EXT__ . "/demo/src/demo.h"          // ext/ 目录引用
#include __INC__ . "/common.h"                 // include/ 目录引用
#include __CMD__ . "/local_lib.h"              // 当前工作目录引用
#include __DIR__ . DIRECTORY_SEPARATOR . "x.h" // 跨平台路径分隔
#include <math.h>                              // 系统头文件 → #include <math.h>
#flag Linux -lm                  // Linux 链接数学库
#flag GCC -O2 -DNDEBUG           // 仅 GCC 优化
#flag Clang -Wall -Werror        // 仅 Clang 严格警告
```

`#include` 路径中支持 PHP 魔术常量展开：

| 常量 | 展开为 | 示例 |
|------|--------|------|
| `__DIR__` | 源文件所在目录（绝对路径） | `__DIR__ . "/../demo.h"` |
| `__EXT__` | 编译器 `ext/` 目录 | `__EXT__ . "/pcntl/src/pcntl.h"` |
| `__INC__` | 编译器 `include/` 目录 | `__INC__ . "/common.h"` |
| `__CMD__` | 执行 `tphp` 的当前工作目录 | `__CMD__ . "/my_lib.h"`（`tphp .` 时有用） |
| `DIRECTORY_SEPARATOR` | `/`（Linux/macOS）或 `\`（Windows） | 跨平台路径拼接 |

> 展开后经 `realpath()` 解析并校验在项目根目录内。`#include "path"` 和 `#include <name>` 的引号/尖括号格式不受影响，原样通过。

| 指令 | 语法 | 编译器输出 | 去重 |
|------|------|-----------|------|
| `#include [OS] "path"` | 项目相对路径，可选 OS 过滤 | `#include "path"` | 按文件名去重 |
| `#include [OS] <name>` | 系统头文件，可选 OS 过滤 | `#include <name>` | 同上 |
| `#flag [CC] [OS] flags` | 编译器+平台过滤 | 仅匹配时追加到命令行 | 按标志串去重 |

> `[OS]` 可选：`Windows`/`Linux`/`MacOS`。不写 = 全平台。`#include` 和 `#flag` 共用同一过滤规则，`MacOS` 映射到 `Darwin`。

#### `#flag` / `#include` / `#import` 安全模型

为防止注入攻击，所有预处理指令受以下安全约束：

| 指令 | 机制 | 说明 |
|------|------|------|
| `#flag` | **Shell 元字符阻断** | `` ` `` `$` `\|` `;` `&` `>` `<` `\n` `\\` 直接报错 |
| | **Flag 前缀白名单** | 仅 `-I` `-L` `-l` `-D` `-U` `-O` `-W` `-std` `-m` `-f` `-g` `-pthread` `-static` `-shared` `-B` |
| | **危险 Flag 黑名单** | `-fplugin` / `-specs` / `-wrapper` / `-ld=` 直接报错（防 GCC 插件注入） |
| | **路径规范化** | `-I`/`-L` 路径经 `realpath()` 消解 `../` |
| `#include` | **realpath + 边界校验** | 项目头路径经 `realpath()` 解析后验证在项目根目录内 |
| | **系统头白名单** | `#include <...>` 仅允许标准 C 库 + 常见 POSIX/Windows 头（防任意引入系统 API） |
| `#import` | **扩展名白名单** | 正则 `\w[\w\-]*` 仅接受字母/数字/下划线/连字符 |
| | **工作区边界校验** | `realpath()` 后验证路径在 `ext/` 目录内 |

> 对标 Vlang 的 `@DIR` 变量，TinyPHP 的 `__DIR__` 魔术常量在编译时展开，可在 `#include` 中用于跨目录引用。

### 基础类型桥接

```
PHP → C:                   C → PHP:
c_int($x)   → int32_t      php_int(v)      → t_int
c_float($x) → double       php_float(v)    → t_float
c_str($s)   → const char*  php_str(s)      → t_string (深拷贝，复用 C 内存)
                            php_str_clone(s) → t_string (深拷贝，明确克隆语义)
```

### C 类型注解

借鉴 vlang 的 `C.Type` 命名空间设计，函数参数和返回值可直接使用 C 类型注解，编译期映射为对应 C 类型：

```php
#include "include/demo.h"

// C.Point* 返回类型 → Point*
function create_origin(): C.Point* {
    return C->point_origin();
}

// C.Point* 参数 + C.double 返回 → Point* / double
function get_point_x(C.Point* $p): C.double {
    return C->point_get_x($p);
}

// C.char* 参数 + C.int 返回 → char* / int
function square_name(string $name): int {
    return php_int(C->int_square(c_int(42)));
}
```

| C 类型注解 | 映射为 C 类型 | 说明 |
|-----------|--------------|------|
| `C.int` | `int` | C int |
| `C.int32` / `C.int64` | `int32_t` / `int64_t` | 固定宽度整数 |
| `C.uint32` / `C.uint64` | `uint32_t` / `uint64_t` | 无符号整数 |
| `C.float` / `C.double` | `double` | 浮点数 |
| `C.char` | `char` | 单字符 |
| `C.bool` | `bool` | 布尔值 |
| `C.void` | `void` | 无返回值 |
| `C.void*` | `void*` | 通用指针 |
| `C.char*` | `char*` | C 字符串指针 |
| `C.int*` / `C.double*` | `int*` / `double*` | 整数/浮点指针 |
| `C.XXX` | `XXX` | 结构体值类型 |
| `C.XXX*` | `XXX*` | 结构体指针（如 `C.Point*` → `Point*`） |

> `C.Type` 注解让 C 边界在函数签名中一目了然，编译器自动处理类型映射，无需手动 `c_int` / `php_int` 转换。

直接 C 调用：`C->function(args)` → 生成原生 `function(args)`，无 `tphp_fn_` 前缀。

直接 C 常量/枚举/宏访问：`C->CONST`（无括号）→ 生成原生 C 标识符，按 `t_int` 推断类型。需配合 `#include "header.h"` 引入定义。

```php
#include "include/cconst.h"

class Main {
    public function main(): void {
        var_dump(C->COLOR_RED);    // C 枚举值 → int(0)
        var_dump(C->MAX_SIZE);     // C #define 宏 → int(1024)
        $total = C->COLOR_RED + C->COLOR_GREEN;  // 表达式中使用
    }
}
```

### 数组互操作

**严格 C 风格**：`phpc_arr_int($arr)` 要求所有元素为 `TYPE_INT`，否则抛 `tp_throw` 异常（可被 try-catch 捕获）。`phpc_arr_dbl` 接受 `int` 或 `float`。

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

### 对象互操作

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
| `phpc_obj($obj)` | PHP→C | 提取底层 C 结构体指针（`void*`，借用语义） |
| `phpc_new_obj(ptr, vtable)` | C→PHP | 包裹 C 指针为 PHP 对象（vtable 管理析构，接管语义） |
| `phpc_unregister_obj($ptr)` | 双向 | 解除对象注册（C 库自行 free 时调用，防 double-free） |

### 回调互操作

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

### 所有权与内存安全

所有 PHPC 函数按所有权分三类，**搞错会导致 double-free 或内存泄漏**。

#### 错误处理

`phpc_arr_*` 类型不匹配时抛 `tp_throw` 异常（基于 setjmp/longjmp），可被 TinyPHP 的 `try-catch` 捕获，不再 `exit(1)` 强制退出：

```php
try {
    $data = phpc_arr_int([1, "two", 3]);  // 元素 "two" 不是 int
} catch (\Throwable $e) {
    echo "Caught: " . $e->getMessage();   // 捕获异常
}
```

> `C->func()` 段错误不可恢复，仍会导致进程崩溃。C 函数返回 NULL/错误码时需调用方手动检查，无统一约定。

#### PHP → C（调用方负责释放）

| 函数 | 返回类型 | 所有权 |
|------|---------|--------|
| `c_int($x)` | `int32_t` | 值拷贝，无所有权 |
| `c_float($x)` | `double` | 值拷贝，无所有权 |
| `c_str($s)` | `const char*` | **借用指针** ❌ 不可 `free` |
| `phpc_arr_int($arr)` | `int32_t*` | **malloc** ⚠ 必须 `phpc_free()` |
| `phpc_arr_dbl($arr)` | `double*` | **malloc** ⚠ 必须 `phpc_free()` |
| `phpc_arr_str($arr)` | `char**` | **malloc** ⚠ 必须 `phpc_free_str_arr()` |
| `phpc_obj($obj)` | `void*` | **借用指针** ❌ 不可 `free` |
| `phpc_fn($cb)` / `phpc_env($cb)` | `void*` | 借用，无所有权 |
| `phpc_fn_i32/i64/f64($cb)` | 函数指针 | 借用，无所有权 |

#### C → PHP（TinyPHP 自动管理）

| 函数 | 返回类型 | 所有权 |
|------|---------|--------|
| `php_int(v)` | `t_int` | 值拷贝 |
| `php_float(v)` | `t_float` | 值拷贝 |
| `php_str(s)` | `t_string` | **深拷贝**，字符串池自动释放 |
| `php_str_clone(s)` | `t_string` | **深拷贝**，明确克隆语义（与 `c_str` 对照） |
| `phpc_new_arr_*(src, n)` | `t_array*` | 引用计数，自动 GC |
| `phpc_new_arr()` | `t_array*` | 引用计数，自动 GC |
| `phpc_new_obj(ptr, cls)` | `void*` | TinyPHP 析构链管理 |
| `phpc_unregister_obj(ptr)` | `void` | 解除注册（C 库自行 free 后调用） |
| `phpc_obj_steal(obj)` | `void` | 标记对象"已分离"，C 库可安全 free（防 double-free） |
| `phpc_new_fn(func)` | `t_callback` | 值拷贝 |
| `phpc_new_fn_env(fn, env)` | `t_callback` | 值拷贝 |
| `phpc_env_pin(cb)` | `void*` | 固定闭包 env（异步回调安全） |
| `phpc_env_unpin(env)` | `void` | 解除固定 |

#### 释放函数

| 函数 | 说明 |
|------|------|
| `phpc_free(ptr)` | `free(ptr)`，NULL 安全，**自动置零变量**防 UAF |
| `phpc_free_str_arr(strs, len)` | 先 `free` 每个字符串，再 `free` 指针数组，**自动置零** |

#### 安全辅助 API

| 函数 | 说明 |
|------|------|
| `phpc_assert_ptr(ptr, name)` | 断言指针非 NULL，NULL 时抛 `tp_throw` 异常（可 try-catch） |
| `phpc_obj_steal(obj)` | 标记对象"已分离"（refcount=-1），C 库可安全 free 防 double-free |
| `phpc_env_pin(cb)` | 固定闭包 env 防止 PHP 侧释放（异步回调安全） |
| `phpc_env_unpin(env)` | 解除固定（C 库不再需要回调时调用） |

> ⚠ **记忆口诀**：`phpc_arr_*`（提取）→ malloc → **你必须 phpc_free**。`phpc_new_*`（创建）→ TinyPHP GC → **你别管**。`c_str` / `phpc_obj` → 借用 → **别 free**。C 库要 free 借用指针 → **先 `phpc_obj_steal`**。异步回调 → **先 `phpc_env_pin`**。

## 性能

PHP 8.5.1 vs TinyPHP（GCC -O2），详见 [BENCHMARK_RESULTS.md](BENCHMARK_RESULTS.md)：

- **数组遍历/读取**: 18-36x PHP，方法调用近乎 0ns
- **OOP 创建/写入**: SSO + 对象池使 new+unset 反超 2.1x，prop write 反超 2.6x
- **字符串拼接**: ROPE 多片段展平，concat-4 快 6 倍
- **编译器差距**: GCC/Clang -O2 比 TCC 再快 3-10x，`tphp -cc gcc` 即可获得

已落地优化：SSO 小字符串、Arena Allocator、对象复用池、ROPE 拼接、implode O(N²)→O(N)、CodeGen 自动释放、str/int 键双哈希索引（O(n)→O(1)）。

## 编译流水线

```
PHP → Lexer → Token[] → Parser → AST → CodeGenerator → .c → 编译器 → 二进制
                                    include/  (C 运行时头文件)
```

- **Lexer**: 逐字符扫描，~75 种 Token，支持字符串插值/heredoc
- **Parser**: 递归下降，运算符优先级完整
- **CodeGenerator**: 访问者模式，生成类型安全的 C 代码
- **C 运行时**: COS 风格对象系统（16B 头 + struct 嵌套继承），setjmp/longjmp 异常，ROPE 多片段字符串拼接，256 位 JSON 转义位图，128 槽数组/对象复用池，64KB 字符串池，`compat.h` TCC/GCC/Clang 三编译器兼容层
- **编译器**: 内置 TCC (mob 分支)，支持 GCC/Clang

## 文档

| 文件 | 说明 |
|---|---|
| [FUNCTIONS.md](FUNCTIONS.md) | 每个函数的实现细节与 PHP 差异 |
| [GRAMMAR.md](GRAMMAR.md) | 完整语法参考（基于 PHP 8.5 parser，标注支持程度） |
| [BENCHMARK_RESULTS.md](BENCHMARK_RESULTS.md) | 性能基准数据 |
| [CONTRIBUTING.md](CONTRIBUTING.md) | 架构、扩展指南、安全规范 |
| [QUICK_START.md](QUICK_START.md) | 5 分钟快速上手：加函数、跑测试、提 PR |

### 运行基准

```bash
php bench/run_bench.php         # 默认 TCC
php bench/run_bench.php gcc     # GCC -O2
php bench/run_bench.php clang   # Clang -O2
php bench/run_bench.php gcc php # 同时对比原生 PHP
```

## 许可证

MIT
