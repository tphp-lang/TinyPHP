# TinyPHP 开发指南

> 面向开发者：项目架构、扩展点、代码生成模式、安全规范。

---

## 1. 架构总览

```
tphp.php                         CLI 入口（参数解析、文件收集、AST 合并、编译器调用）
tphp / tphp.cmd                  Linux/macOS / Windows 快捷入口
build.sh / build.cmd             TCC 构建脚本

  └─ src/
       ├── TokenType.php         Token 枚举（~90 token）
       ├── Token.php             Token 值对象
       ├── AST/Node.php          AST 节点 + Visitor 接口
       ├── Lexer.php             词法分析 → Token[]
       ├── Parser.php            递归下降解析 → AST
       ├── CodeGenerator.php     访问者模式遍历 AST → C 代码
       └── Compiler.php          独立 API

include/                         C 运行时头文件（全 static inline）
  ├── common.h                   总入口
  ├── compat.h                   三编译器兼容（TCC/GCC/Clang）
  ├── types.h                    类型系统 + likely/unlikely + SSO 字符串
  ├── val.h                      便捷宏 (VAR_INT, STR_LIT, …)
  ├── array.h                    PHP 数组（128 槽复用池 + 1.5× 增长 + sort/shuffle/search）
  ├── runtime.h                  运行时（128KB 字符串池 + Arena、对象/数组/字符串池、资源追踪）
  ├── builtin.h                  公开内置（178 个函数）
  ├── rand.h                     MT19937 随机数
  ├── phpc.h                     C 互操作（类型桥/数组/对象/回调/thunk）
  ├── tphp_math.h                扩展数学（pi/deg2rad/intdiv/pow/三角函数）
  ├── conv.h                     进制转换 + number_format
  ├── hash.h                     MD5/SHA1/CRC32
  ├── object/
  │   ├── object.h               COS 对象系统（16B 头 + struct 嵌套继承 + refcount + 对象复用池）
  │   ├── exception.h            内置 Exception 类
  │   └── try.h                  setjmp/longjmp 异常（TP_TRY/TP_CATCH/TP_THROW）
  └── os/
      ├── times.h                时间（time/date/sleep/hrtime/microtime/strtotime/mktime）
      ├── json.h                 JSON 编解码（位图转义 + 批量安全字符写入）
      ├── file.h                 文件 I/O（file_get/put_contents）
      ├── pcntl.h                进程控制（POSIX）
      └── posix.h                系统函数（POSIX）
```

> TCC 不在仓库中——通过 `build.sh`/`build.cmd` 从 `https://repo.or.cz/tinycc.git` (mob 分支) clone 并编译。

---

## 2. 编译流水线详解

### 2.1 Lexer（词法分析）

**文件**: `src/Lexer.php`（~770 行）

**令牌扫描顺序**（优先级从高到低）：
1. 空白 / 换行
2. `#include` / `#flag` / `#callback` 预处理指令
3. `/` → `//` 行注释 / `/* */` 块注释 / `/=` 复合赋值 / `/` 除号
4. **三字符运算符**（`<=>` 必须在 `<=` 之前）
5. **多字符运算符**（`->` `=>` `==` `!=` `<=` `>=` `**` `&&` `||` `++` `--` `+=` `-=` `*=` `/=` `.=` `<<` `>>` `??`）
6. **单字符运算符**（`+` `-` `*` `<` `>` `!` `&` `|` `^` `~` `?`）
7. 字符串（`"` 双引号含插值 / `'` 单引号 / heredoc / nowdoc）
8. `\` → 命名空间分隔符 `NS_SEP`
9. **`::` 双冒号**（必须在单字符 `:` 之前）
10. 单字符 `:` `;` `,` `=` `.` `(` `)` `{` `}` `[` `]`
11. `$` → 变量名
12. 数字 → int / float
13. 标识符 / 关键字

**字符串插值**：双引号和 heredoc 内 `{$var->prop}` 支持链式属性访问。

### 2.2 Parser（语法分析）

**文件**: `src/Parser.php`（~1720 行）

**运算符优先级**（从低到高）：

`??` < `?:` < `||` < `&&` < `==` `!=` `<=>` < `<` `>` `<=` `>=` < `<<` `>>` < `+` `-` `.` < `*` `/` `%` < `**`（右结合）< `!` `-` `++` `--` `~`（一元）< 后缀链

**两阶段解析**：
1. 先解析辅助文件（非 Main），收集枚举名/类名、`#include` 头文件、`#flag` 编译器标志
2. 再解析 Main 入口文件（此时 `setKnownEnums` 已注入所有枚举名）

### 2.3 AST（抽象语法树）

**文件**: `src/AST/Node.php`（~890 行）

节点层次（重点）：

```
StmtNode（抽象）
├── EchoStmtNode          # echo
├── ReturnStmtNode        # return
├── AssignStmtNode        # $var = expr
├── AssignPropStmtNode    # $this->prop = expr
├── AssignArrayStmtNode   # $arr[$i] = expr
├── AssignArrayPushStmtNode  # $arr[] = expr
├── ExprStmtNode          # expr;
├── IfStmtNode            # if / elseif / else
├── WhileStmtNode / DoWhileStmtNode / ForStmtNode / ForeachStmtNode
├── SwitchStmtNode / MatchExpr / BreakStmtNode / ContinueStmtNode
└── GotoStmtNode

ExprNode（抽象，含 line/column）
├── StringLiteralExpr / IntLiteralExpr / FloatLiteralExpr
├── BoolLiteralExpr / NullLiteralExpr
├── VariableExpr / UnaryExpr / PostfixExpr
├── BinaryExpr            # 含 ** （幂）和 <=> （太空船）
├── CompoundAssignExpr    # += -= *= /= .=
├── CallExpr              # 函数 / 方法调用
├── CastExpr / NewExpr / ArrayLiteralExpr
├── ArrayAccessExpr / PropertyAccessExpr
├── EnumAccessExpr / ClosureExpr
├── TernaryExpr / NullCoalesceExpr
└── MatchArm / MatchExpr
```

**访问者模式**：每个 `ASTNode::accept(ASTVisitor)` → visitor 对应方法。

### 2.4 CodeGenerator（代码生成）

**文件**: `src/CodeGenerator.php`（~4000 行）

**关键内部状态**：

| 属性 | 说明 |
|------|------|
| `$this->varTypes` | `varName → C 类型` 映射 |
| `$this->declaredVars` | 已声明变量集合 |
| `$this->funcScopeDecls` | 变量提升到函数作用域的声明 |
| `$this->scopeObjects` | 作用域对象列表（方法结尾自动析构） |
| `$this->arrElementTypes` | 数组元素类型追踪 |
| `$this->arrValueTypes` | 数组 per-key 类型追踪 |
| `$this->arrNestedTypes` | 嵌套数组元素类型追踪（2 层） |
| `$this->classPropTypes` | 类属性类型表 |
| `$this->classMethodRetTypes` | 类方法返回类型表 |
| `$this->currentRetType` | 当前方法返回类型（zeroReturn 用） |

**命名规则**：

| PHP | C | 前缀 |
|-----|---|------|
| `class Main` | `tphp_class_Main` | `tphp_class_` |
| `namespace Demo; class Demo` | `tphp_class_Demo_Demo` | `tphp_class_` |
| `new Demo()` | `new_tphp_class_Demo()` | `new_` |
| `function foo()` | `tphp_fn_foo` | `tphp_fn_` |
| `enum Color: string` | `tphp_enum_Color` | `tphp_enum_` |
| `Color::RED` | `&_e_Color_RED` | `_e_` |
| `$a . $b` | `tphp_rt_str_concat(a, b)` | `tphp_rt_` |
| `const APP_NAME` | `#define TPHP_CONST_APP_NAME` | `TPHP_CONST_` |

**AOT 类型固定**：变量类型在首次赋值时确定，后续不可变。CodeGen 自动推导并追踪。同一变量切换类型（如先 string 后 array）会生成类型冲突的 C 代码，由 C 编译器报错。

**自动释放**：对象/t_string 重赋值时自动注入 `tp_obj_release` / `tphp_rt_str_free`。`zeroReturn()` 方法根据返回类型生成正确零值 `return`（兼容 TCC/GCC/Clang）。

---

## 3. C 运行时

### 3.1 头文件职责

| 文件 | 前缀 | 职责 |
|------|------|------|
| `common.h` | — | 总入口，按依赖顺序引入所有头文件 |
| `compat.h` | — | TCC/GCC/Clang 三编译器兼容层 |
| `types.h` | — | 类型系统 + SSO 字符串 + `likely`/`unlikely` |
| `val.h` | `VAR_*` `STR_LIT` | 便捷宏 |
| `array.h` | `tphp_fn_arr_` | PHP 数组（128 槽复用池 + sort + 1.5× 增长） |
| `runtime.h` | `tphp_rt_` | 运行时（128KB 字符串池 + Arena、资源追踪、error） |
| `builtin.h` | `tphp_fn_` | 公开内置：178 个函数 |
| `phpc.h` | `phpc_` `c_` `php_` | PHP↔C 互操作 |
| `tphp_math.h` | `tphp_fn_` | 扩展数学函数 |
| `conv.h` | `tphp_fn_` | 进制转换 |
| `hash.h` | `tphp_fn_` | MD5/SHA1/CRC32 |
| `rand.h` | `tphp_fn_` | MT19937 随机数 |
| `os/*.h` | `tphp_fn_` | 系统函数（时间/JSON/文件/进程/POSIX） |

### 3.2 内存管理层次

| 层 | 机制 | 说明 |
|----|------|------|
| **SSO 小字符串** | 24B 内联缓冲区 | ≤23 字节零堆分配 |
| **字符串池 + Arena** | 128KB bump allocator + 溢出块链表 | O(1) 分配，批量释放 |
| **数组复用池** | 128 槽 LIFO | 热路径零 malloc |
| **对象复用池** | 128 槽 LIFO | new+unset 提速 36-52% |
| **资源追踪链表** | `tphp_rt_register` / `unregister` | `error()` / `tp_throw` 时遍历释放 |
| **引用计数** | `tp_obj_retain` / `tp_obj_release` | 归零 → `__destruct` → 回池 |

### 3.3 ROPE 多片段拼接

编译期展平 `"a"."b"."c"` 链为单次 `tphp_rt_str_concat_multi(N, parts)` 调用：第一遍计算总长度 → 一次分配 → 第二遍逐片 `memcpy`。3+ 片段自动触发。

### 3.4 异常系统

`setjmp/longjmp`（COS 风格），零外部依赖。`tp_throw` 先 `tphp_rt_free_all()` 再跳转，确保内存安全。异常消息 256B 栈帧内缓冲，不依赖堆分配。

---

## 4. 测试框架（#debug）

### 4.1 --debug 模式

`tphp.php --debug` 编译并运行程序，逐行比对标准输出和 `#debug` 预期值：

```bash
php tphp.php test/var/var.php --debug
```

### 4.2 #debug 指令

在 PHP 测试文件顶部用 `#debug` 声明预期输出：

```php
<?php
#debug === test ===
#debug int(42)
#debug string(5) "hello"
#debug ~ Fatal error: ...    // ~ 前缀 = 只参考不报错（跨平台/可变内容）

class Main {
    public function main(): void {
        echo "=== test ===\n";
        var_dump(42);
        var_dump("hello");
        error("test");
    }
}
```

### 4.3 输出标记

```
[YES] expected    — 完全匹配
[NO]  expected... — 不匹配（expected vs got）
[REF] expected... — ~ 前缀，仅参考（actual: ...）
```

### 4.4 注解

| 注解 | 说明 | 示例 |
|------|------|------|
| `// @skip` | 跳过该测试（辅助文件） | `// @skip — companion file` |
| `// @exit N` | 期望退出码（默认 0） | `// @exit 1` |
| `// @multi @with a.php,b.php` | 多文件编译 | `// @multi @with models.php` |

### 4.5 CI

GitHub Actions 工作流在 push/PR 时自动在 Linux x86_64/aarch64、macOS aarch64、Windows x86_64 四平台运行全量 `--debug` 测试。

---

## 5. 扩展指南

### 添加内置函数

**原则：通用回退，C 编译器兜底。** CodeGenerator **不需要**为每个函数写 `if` 分支。

1. C 运行时 — 在对应 `include/*.h` 添加 `static inline` 实现，函数名必须是 `tphp_fn_{PHP函数名}`
2. CodeGenerator — **无需修改 `visitCall()`**（除非需要特殊处理）
3. 测试 — 添加测试文件并写 `#debug` 预期输出
4. 文档 — 更新 `FUNCTIONS.md`

**为什么不需要写 if 分支？** CodeGenerator 末尾有通用回退：
```php
// 自动生成 tphp_fn_{函数名}(参数列表) — C 编译器校验函数是否存在
if (empty($a)) return "tphp_fn_{$n}()";
return "tphp_fn_{$n}(" . implode(', ', $a) . ")";
```

**何时需要写 if 分支？** 仅以下三种情况：

| 情况 | 示例 | 说明 |
|------|------|------|
| 类型转换 | `deg2rad` `number_format` | 参数需要 `(t_float)` cast |
| 默认参数 | `str_split($s, $chunk=1)` `str_pad` | PHP 默认值不能直接传给 C |
| 非标准 C 名 | `crc32`→`crc32_str` `array_is_list`→`array_is_list_int` | C 函数名与 PHP 名不同 |

**错误做法（禁止）：**
```php
if ($n === 'md5')   return "tphp_fn_md5({$c})";    // ❌ 冗余！通用回退已覆盖
if ($n === 'sha1')  return "tphp_fn_sha1({$c})";   // ❌ 同上
if ($n === 'asort') return "tphp_fn_asort({$c})";  // ❌ 同上
```

### 添加新语句/语法

1. `TokenType` + `Lexer` — 添加 token/关键字
2. `AST/Node.php` — 添加节点类 + `ASTVisitor` 方法
3. `Parser::parseStmt()` — 添加匹配分发 + 实现解析方法
4. `CodeGenerator` — 实现 `visitXxx()` + `inferType()` 返回类型

### 添加 C 运行时头文件

参照 `include/os/` 下现有文件模式：`static inline` 函数 + `common.h` 按依赖顺序引入。

### 函数命名规范

**所有公开内置函数必须使用 `tphp_fn_` 前缀**，这是强制规则，CodeGenerator 据此生成 C 调用。

| 前缀 | 用途 | 示例 |
|------|------|------|
| `tphp_fn_` | **公开内置函数（PHP 可调用）** | `tphp_fn_json_encode`、`tphp_fn_posix_getpid`、`tphp_fn_sort` |
| `tphp_fn_arr_` | 数组操作函数 | `tphp_fn_arr_push`、`tphp_fn_arr_item_int` |
| `tphp_rt_` | 运行时内部辅助 | `tphp_rt_str_concat`、`tphp_rt_free_all` |
| `tp_` | 对象系统内部 | `tp_obj_alloc`、`tp_obj_release`、`tp_throw` |
| `phpc_` / `c_` / `php_` | C 互操作 | `phpc_arr_int`、`c_int`、`php_str` |
| `_` 前缀 | 文件内部辅助 | `_is_leap`、`_cmp_key`、`_md5_init` |

**严禁**以下错误前缀：

| 错误 | 原因 | 正确写法 |
|------|------|---------|
| `posix_getpid` | 缺少 `tphp_fn_` | `tphp_fn_posix_getpid` |
| `pcntl_fork` | 缺少 `tphp_fn_` | `tphp_fn_pcntl_fork` |
| `tphp_arr_item_int` | 缺少 `fn_` | `tphp_fn_arr_item_int` |

**CodeGenerator 端**：PHP 调用 `posix_getpid()` 时，CodeGen 自动拼接 `tphp_fn_` 前缀生成 `tphp_fn_posix_getpid()`。手动写的函数调用（如 `pcntl_fork`）需在 CodeGen 的 `visitCall` 中显式写出完整 C 函数名。

---

## 6. 安全编码规范

### 空指针保护

方法入口生成零值 return（`zeroReturn()` 按返回类型生成 `return 0;` / `return NULL;` / `return (t_string){0};` 等），兼容 TCC/GCC/Clang。

### 命名防冲突

详见 §5「函数命名规范」。速查：

| 前缀 | 用途 |
|------|------|
| `tphp_fn_` | **所有公开内置函数强制前缀** |
| `tphp_fn_arr_` | 数组操作函数 |
| `tphp_class_` | 用户类 |
| `tphp_enum_` | 枚举 struct |
| `_e_` | 枚举 static 实例 |
| `tphp_rt_` | 运行时内部 |
| `tp_` | 对象系统 |
| `TPHP_CONST_` | 常量宏 |
| `phpc_` / `c_` / `php_` | C 互操作 |
| `_arr_` / `_tmp_` | CodeGen 临时变量 |
| `str_pool_` / `arr_pool_` / `_obj_pool_` | 池内部 |

### TCC 兼容

- 所有 TCC 特殊处理用 `#ifdef __TINYC__` 隔离
- 避免 for 循环内声明变量（`for (int i=...)`）
- `static inline` 函数间不要交叉引用（必要时加前置声明）

### 分支预测

`likely(x)` 标记热路径，`unlikely(x)` 标记错误/边界。

---

## 7. 文件索引

| 文件 | 行数 | 核心职责 |
|------|------|---------|
| `tphp.php` | ~680 | CLI 入口、多文件合并、`#flag`/`#callback` 过滤、`--debug` 测试、PHAR 自解压、跨编译(-os/-arch) |
| `src/TokenType.php` | ~155 | Token 枚举（~90 token） |
| `src/Token.php` | ~20 | Token 值对象 |
| `src/AST/Node.php` | ~890 | AST 节点 + Visitor 接口 + AssignArrayPushStmtNode |
| `src/Lexer.php` | ~774 | 词法分析（#include/#flag/#callback/heredoc/插值） |
| `src/Parser.php` | ~1815 | 递归下降解析（两阶段） |
| `src/CodeGenerator.php` | ~4000 | C 代码生成（COS OOP/zeroReturn/自动释放/ROPE/178 内置函数） |
| `include/types.h` | ~130 | C 类型系统 + SSO t_string + likely/unlikely |
| `include/compat.h` | ~55 | 三编译器兼容（TCC round fallback/math 声明） |
| `include/array.h` | ~869 | PHP 数组（128 槽复用池/sort/1.5× 增长） |
| `include/runtime.h` | ~402 | 运行时（128KB 字符串池+Arena/资源追踪/error/str_free SSO 感知） |
| `include/builtin.h` | ~1193 | 公开内置（178 个函数） |
| `include/phpc.h` | ~200 | PHPC 互操作（类型/数组/对象/回调/thunk/内存释放） |
| `include/object/object.h` | ~100 | COS 对象系统 + 128 槽对象复用池 |
| `include/object/try.h` | ~92 | setjmp/longjmp 异常 |
| `include/os/json.h` | ~385 | JSON 编解码（位图转义+批量安全字符写入） |
| `include/os/times.h` | ~215 | 时间函数 |
| `include/hash.h` | ~134 | MD5/SHA1/CRC32 |
| `include/conv.h` | ~125 | 进制转换 |
| `include/tphp_math.h` | ~55 | 扩展数学 |
| `test/` | 90+ 文件 | 全部 `#debug` 标注，四平台 CI |
