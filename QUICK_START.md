# Quick Start

> 5 分钟上手 TinyPHP 开发。

---

## 1. 跑通第一个测试

```bash
# 编译并运行
php tphp.php test/main/min.php
./min                          # Linux/macOS
.\min.exe                      # Windows

# 带 #debug 的测试（自动比对预期输出）
php tphp.php test/var/var.php --debug

# CI 批量测试
php .github/scripts/run_tests.php
```

---

## 2. 调用自定义 C 函数

写一个 `my_func.c`：

```c
#include <stdio.h>

int my_add(int a, int b) {
    return a + b;
}
```

PHP 中通过 `C->` 直接调用：

```php
// (<?php 前缀可选)
#debug 30

class Main {
    public function main(): void {
        var_dump(C->my_add(c_int(10), c_int(20));
    }
}
```

编译时把 `.c` 一起带上：

```bash
php tphp.php main.php my_func.c --debug
# [YES] 30
```

### C 类型注解（可选）

借鉴 vlang 的 `C.Type` 设计，函数参数和返回值可直接使用 C 类型，编译器自动映射：

```php
#include "my_func.h"

function add(int $a, int $b): C.int {     // 返回 C int
    return C->my_add(c_int($a), c_int($b));
}

// C 结构体指针作为参数和返回值
function make_point(): C.Point { return C->point_create(); }
function get_x(C.Point $p): C.double { return C->point_get_x($p); }
```

| C 类型注解 | 映射 |
|-----------|------|
| `C.int` / `C.int32` / `C.int64` | `int` / `int32_t` / `int64_t` |
| `C.float` / `C.double` | `double` |
| `C.char_ptr` / `C.void_ptr` | `char*` / `void*` |
| `C.XXX`（结构体） | `XXX*` |

### 安全 API（防 UAF / double-free）

phpc 提供 4 个安全辅助函数，处理 C 指针生命周期边界问题：

```php
// 1. phpc_free 释放后自动置零变量（CodeGenerator 自动改写为逗号表达式）
$data = phpc_arr_int([1, 2, 3]);
phpc_free($data);
// $data 现在自动为 null，防 use-after-free

// 2. phpc_assert_ptr 断言指针非 NULL，失败时抛 tp_throw 异常（可 try-catch）
$ptr = C->maybe_returns_null();
try {
    phpc_assert_ptr($ptr, "ptr_name");
    // 安全使用 $ptr
} catch (\Throwable $e) {
    // 捕获 NULL 指针错误
}

// 3. phpc_obj_steal 标记对象为"已分离"（refcount=-1），防 tp_obj_release double-free
$p = C->point_create(1.0, 2.0);
phpc_obj_steal($p);   // 标记分离
C->point_free($p);    // C 库释放，TinyPHP GC 不会再次释放

// 4. phpc_env_pin / phpc_env_unpin 钉住闭包 env，防异步回调 UAF
$fn = function(int $x) use ($captured): int { return $x * $captured; };
$env = phpc_env_pin($fn);     // 钉住 env，防闭包出作用域被回收
// ... C 库异步回调安全使用 $env ...
phpc_env_unpin($env);          // 用完释放
```

| API | 作用 | 防护对象 |
|-----|------|---------|
| `phpc_free($var)` | 释放 + 自动置零 | use-after-free |
| `phpc_assert_ptr($ptr, $name)` | NULL 断言 → tp_throw | NULL 解引用 |
| `phpc_obj_steal($obj)` | refcount=-1 | double-free |
| `phpc_env_pin($cb)` / `phpc_env_unpin($env)` | 钉住闭包 env | 异步回调 UAF |

---

## 3. 开发流程

```
改代码 → 跑相关测试 → 全量 CI → PR
         ↓                ↓
    php tphp.php       php .github/scripts/run_tests.php
    test/xxx.php --debug
```

| 命令 | 用途 |
|---|---|
| `php tphp.php test/foo.php` | 编译单个测试 |
| `php tphp.php test/foo.php --debug` | 编译 + 运行 + 比对输出 |
| `php tphp.php test/foo.php -cc gcc` | 指定编译器 |
| `php .github/scripts/run_tests.php` | 跑全部测试 |
| `php bench/run_bench.php gcc` | 跑基准 |

---

## 4. 代码约定

| 文件 | 职责 |
|---|---|
| `src/Lexer.php` | 词法分析 → Token |
| `src/Parser.php` | 语法分析 → AST |
| `src/CodeGenerator.php` | AST → C 代码 |
| `include/` + `include/std/*.h` | C 运行时（全部 `static inline`, 8个分类子文件） |
| `tphp.php` | CLI 入口、路径安全、合并 AST |

- C 函数统一前缀 `tphp_fn_`
- 跨编译器兼容用 `compat.h`，不要直接 `#include <math.h>`
- 测试用 `var_dump` + `#debug` 验证类型和值

---

## 5. 常见问题

**Q: 编译报 `macro used with too many args`？**

A: 宏参数含逗号（如 `VAR_STRING((t_string){a,b})`）会被预处理器误拆分。用临时变量：

```c
t_string _tmp = {a, (int)strlen(a)};
VAR_STRING(_tmp);  // OK
```

**Q: TCC / GCC / Clang 行为不一致？**

A: `compat.h` 处理差异。TCC 不报隐式声明，GCC/Clang 报。用 `-cc gcc` 或 `-cc clang` 额外验证。

**Q: `#debug` 怎么写？**

```
#debug text      → 精确匹配
#debug           → 预期空行
#debug ~ text    → 近似值（时间/时区相关），不判错
```

---

详细架构见 [CONTRIBUTING.md](CONTRIBUTING.md)。
