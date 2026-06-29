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
<?php
#debug 30

class Main {
    public function main(): void {
        var_dump(C->my_add(10, 20));
    }
}
```

编译时把 `.c` 一起带上：

```bash
php tphp.php main.php my_func.c --debug
# [YES] 30
```

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
| `include/*.h` | C 运行时（全部 `static inline`） |
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
