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
        var_dump(C->my_add(c_int(10), c_int(20)));
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
function make_point(): C.Point* { return C->point_create(); }
function get_x(C.Point* $p): C.double { return C->point_get_x($p); }
```

| C 类型注解 | 映射 |
|-----------|------|
| `C.int` | `int` |
| `C.int32` / `C.int64` | `int32_t` / `int64_t` |
| `C.uint32` / `C.uint64` | `uint32_t` / `uint64_t` |
| `C.float` / `C.double` | `double` |
| `C.char` | `char` |
| `C.bool` | `bool` |
| `C.void` | `void` |
| `C.void*` / `C.char*` / `C.int*` / `C.double*` | `void*` / `char*` / `int*` / `double*` |
| `C.XXX` | `XXX`（结构体值类型） |
| `C.XXX*` | `XXX*`（结构体指针，如 `C.Point*` → `Point*`） |

### 安全 API（防 UAF / double-free）

phpc 提供 4 个安全辅助函数，处理 C 指针生命周期边界问题：

```php
// 1. defer 是首选清理方式（编译期展开到所有退出路径，零运行时开销）
//    适用：C 库返回的 T* 指针、fopen 句柄、phpc_arr_str 等
C.void* $buf = C->malloc(64);
defer C->free($buf);               // 函数退出自动释放
C.FILE* $f = C->fopen(c_str("x.txt"), c_str("rb"));
defer C->fclose($f);               // 所有 return 路径自动关闭

// 2. phpc_arr_int/dbl 自动注册到运行时，无需手动释放
//    （仅循环内为避免内存堆积才需手动 phpc_free）
C.int32_t* $data = phpc_arr_int([1, 2, 3]);   // 自动注册，程序结束/异常自动释放
$result = C->sum_ints($data, c_int(3));

// phpc_arr_str 不自动注册，需用 defer 释放
C.char** $strs = phpc_arr_str(["a", "b"]);
defer phpc_free_str_arr($strs, c_int(2));

// 3. phpc_free 显式释放（释放后自动置零变量，防 use-after-free）
phpc_free($data);                  // $data 自动为 null

// 4. phpc_assert_ptr 断言指针非 NULL，失败时抛 tp_throw 异常（可 try-catch）
$ptr = C->maybe_returns_null();
try {
    phpc_assert_ptr($ptr, "ptr_name");
    // 安全使用 $ptr
} catch (\Exception $e) {
    // 捕获 NULL 指针错误
}

// 5. phpc_obj_steal 标记对象为"已分离"（refcount=-1），防 tp_obj_release double-free
$p = C->point_create(1.0, 2.0);
phpc_obj_steal($p);   // 标记分离
C->point_free($p);    // C 库释放，TinyPHP GC 不会再次释放

// 6. phpc_env_pin / phpc_env_unpin 钉住闭包 env，防异步回调 UAF
$fn = function(int $x) use ($captured): int { return $x * $captured; };
$env = phpc_env_pin($fn);     // 钉住 env，防闭包出作用域被回收
// ... C 库异步回调安全使用 $env ...
phpc_env_unpin($env);          // 用完释放
```

| API | 作用 | 防护对象 |
|-----|------|---------|
| `defer C->free($p)` / `defer C->fclose($f)` | 编译期展开到退出路径 | 资源泄漏（首选） |
| `defer phpc_free_str_arr($a, $n)` | 字符串数组释放 | 资源泄漏 |
| `phpc_free($var)` | 显式释放 + 自动置零 | use-after-free |
| `phpc_assert_ptr($ptr, $name)` | NULL 断言 → tp_throw | NULL 解引用 |
| `phpc_obj_steal($obj)` | refcount=-1 | double-free |
| `phpc_env_pin($cb)` / `phpc_env_unpin($env)` | 钉住闭包 env | 异步回调 UAF |

---

## 3. 多线程

TinyPHP 内置 `Thread`/`Mutex`/`CondVar`/`WaitGroup` 四个 OOP 线程类（基于 tinycthread）。采用 Thread-Local 运行时策略，每线程独立内存池，无锁竞争。

```php
<?php
#debug ret=42
#debug sync=1

class Main {
    public function main(): void {
        // Thread + join
        $t = new Thread(function(): int { return 42; });
        $t->start();
        echo "ret=" . $t->join() . "\n";   // 42

        // WaitGroup 跨线程同步
        $wg = new WaitGroup();
        $wg->add(1);
        $t2 = new Thread(function() use ($wg): int {
            $wg->done();
            return 0;
        });
        $t2->start();
        $wg->wait();
        $t2->join();
        echo "sync=1\n";
    }
}
```

```bash
php tphp.php thread_demo.php --debug
# [YES] ret=42
# [YES] sync=1
```

| 类 | 常用方法 |
|---|---|
| `Thread` | `start()` / `join()` / `detach()` + 静态 `yield()` / `sleep($s)` / `id()` |
| `Mutex` | `lock()` / `tryLock()` / `unlock()`（构造参数 `recursive=true` 用递归锁） |
| `CondVar` | `wait(Mutex $m)` / `signal()` / `broadcast()` |
| `WaitGroup` | `add(int $n)` / `done()` / `wait()` |

> 闭包须返回 `int` 作为线程退出码。详见 [FUNCTIONS.md](FUNCTIONS.md) 多线程章节。

---

## 4. 开发流程

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

## 5. 代码约定

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

## 6. 常见问题

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
