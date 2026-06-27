# TinyPHP 开发指南

> 面向 AI 及开发者：项目架构、扩展点、代码生成模式、安全规范。

---

## 1. 架构总览

```
tphp.php                        入口 CLI（参数解析、文件收集、AST 合并、调用编译器）
tphp / tphp.cmd                  Linux/macOS / Windows 快捷入口
build.sh / build.cmd             TCC 构建脚本

  └─ src/
       ├── TokenType.php         Token 枚举（PHP 8.1 enum, ~75 token）
       ├── Token.php             Token 值对象
       ├── AST/Node.php          AST 节点 + Visitor 接口
       ├── Lexer.php             词法分析 → Token[]
       ├── Parser.php            递归下降解析 → AST
       ├── CodeGenerator.php     访问者模式遍历 AST → C 代码
       └── Compiler.php          独立 API

include/                         C 运行时头文件（静态 inline 库）
  ├── common.h                   总入口
  ├── types.h                    类型定义 + likely/unlikely 分支预测
  ├── val.h                      便捷宏 (VAR_INT, STR_LIT, …)
  ├── array.h                    PHP 数组（128 槽复用池 + 1.5× 增长 + sort/shuffle/search）
  ├── runtime.h                  运行时（64KB 字符串池、ROPE 多片段拼接、数组池预热、资源追踪）
  ├── builtin.h                  公开内置（70+ 函数：类型/字符串/数学/转换）
  ├── rand.h                     MT19937 随机数
  ├── phpc.h                     C 互操作（类型桥/数组/对象/回调/thunk）
  ├── object/
  │   ├── object.h               COS 对象系统（16B 头 + struct 嵌套继承 + refcount）
  │   ├── exception.h            内置 Exception 类
  │   └── try.h                  setjmp/longjmp 异常处理（TP_TRY/TP_CATCH/TP_THROW）
  └── os/
      ├── times.h                时间（time/date/sleep/hrtime/microtime）
      ├── json.h                 JSON 编解码（位图转义 + 批量安全字符写入）
      └── file.h                 文件 I/O（file_get/put_contents）
```

> TCC 不在仓库中——通过 `build.sh`/`build.cmd` 从 `https://repo.or.cz/tinycc.git` (mob 分支) clone 并编译。

---

## 2. 编译流水线详解

### 2.1 Lexer（词法分析）

**文件**: `src/Lexer.php`（~680 行）

**令牌扫描顺序**（优先级从高到低）：
1. 空白 / 换行（CRLF → 一次 line++）
2. `/` → `//` 行注释 / `/* */` 块注释 / `/=` 复合赋值 / `/` 除号
3. **三字符运算符**（`<=>` 必须在 `<=` 之前）
4. **多字符运算符**（`->` `=>` `==` `!=` `<=` `>=` `**` `&&` `||` `++` `--` `+=` `-=` `*=` `/=` `.=` `<<` `>>` `??`）
5. **单字符运算符**（`+` `-` `*` `<` `>` `!` `&` `|` `^` `~` `?`）
6. 字符串（`"` 双引号含插值 / `'` 单引号 / heredoc / nowdoc）
7. `\` → 命名空间分隔符 `NS_SEP`
8. **`::` 双冒号**（必须在单字符 `:` 之前）
9. 单字符 `:` `;` `,` `=` `.` `(` `)` `{` `}` `[` `]`
10. `$` → 变量名
11. 数字 → int / float
12. 标识符 / 关键字

**字符串插值**：双引号和 heredoc 内 `{$var->prop}` 支持链式属性访问。

### 2.2 Parser（语法分析）

**文件**: `src/Parser.php`（~1380 行）

**运算符优先级**（从低到高）：

`??` < `?:` < `\|\|` < `&&` < `==` `!=` `<=>` < `<` `>` `<=` `>=` < `<<` `>>` < `+` `-` `.` < `*` `/` `%` < `**`（右结合）< `!` `-` `++` `--` `~`（一元）< 后缀链

### 2.3 AST（抽象语法树）

**文件**: `src/AST/Node.php`（~820 行）

节点层次（重点）：

```
StmtNode（抽象）
├── EchoStmtNode          # echo
├── ReturnStmtNode        # return
├── AssignStmtNode        # $var = expr
├── AssignPropStmtNode    # $this->prop = expr
├── AssignArrayStmtNode   # $arr[$i] = expr
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

**文件**: `src/CodeGenerator.php`（~3260 行）

**关键内部状态**：

| 属性 | 说明 |
|------|------|
| `$this->className` | 当前类的 C 名 |
| `$this->varTypes` | `varName → C 类型` 映射 |
| `$this->declaredVars` | 已声明变量集合 |
| `$this->funcScopeDecls` | for-init 变量提升到函数作用域的声明（`varName → cType`） |
| `$this->scopeObjects` | 作用域对象列表（方法结尾自动析构） |
| `$this->arrElementTypes` | 数组元素类型追踪 |
| `$this->arrValueTypes` | 数组 per-key 类型追踪（foreach string key + phpc 数组字面量） |
| `$this->arrNestedTypes` | 嵌套数组元素类型追踪（2 层） |
| `$this->classPropTypes` | 类属性类型表 |
| `$this->classMethodRetTypes` | 类方法返回类型表 |
| `$this->classNames` | PHP 类名 → C 类名映射（`mapType` 用） |
| `$this->enumBackingTypes` | 枚举 backing 类型 |
| `$this->closureSigs` | 闭包签名 |
| `$this->phpFile` | 当前 PHP 源文件路径（error 用） |
| `$this->indent` | 当前缩进级别 |

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

### 2.5 tphp.php（入口 CLI）

**两阶段解析**：
1. 先解析辅助文件（非 Main），收集枚举名/类名、`#include` 头文件、`#flag` 编译器标志
2. 再解析 Main 入口文件（此时 `setKnownEnums` 已注入所有枚举名）

**Main 类合并**：扫描所有类中名为 `Main` 且全局命名空间的类作为入口。

**编译标志处理**：
- `#flag` 按平台（`PHP_OS_FAMILY`）+ 编译器（自动检测 TCC/GCC/Clang）过滤
- `#include` 同名文件自动去重

---

## 3. C 运行时

### 3.1 头文件职责

| 文件 | 前缀 | 职责 |
|------|------|------|
| `common.h` | — | 总入口 |
| `types.h` | — | 类型系统 + `likely`/`unlikely` |
| `val.h` | `VAR_*` `STR_LIT` | 便捷宏 |
| `array.h` | `tphp_fn_arr_` | PHP 数组（128 槽 LIFO 复用池 + sort/qsort + 1.5× 增长因子） |
| `runtime.h` | `tphp_rt_` | 内部辅助、64KB 字符串池、资源追踪、error |
| `builtin.h` | `tphp_fn_` | 公开内置：50+ 函数 |
| `phpc.h` | `phpc_` `c_` `php_` | PHP↔C 互操作（基础类型/数组/对象/回调/内存释放） |
| `rand.h` | `tphp_fn_` | MT19937 随机数 |
| `os/times.h` | `tphp_fn_` | 系统函数（跨平台） |
| `os/json.h` | `tphp_fn_` | JSON 编解码（递归+递归下降） |

### 3.2 资源追踪 & error()

`error($msg)` 调用 `tphp_fn_error()`：先遍历全局资源链表释放所有对象/数组/字符串，再打印错误消息（含 PHP 源文件和行号）并 `exit(1)`。

`tphp_rt_register(ptr, type)` 在 `new` 和数组创建时自动注册，`unset` 时注销。

### 3.3 数组对象池

`t_array` 使用 128 槽 LIFO 复用池：
- `arr_pool_put(a)` — 释放时回收（重置 `length=0, refcount=1`，`memset` entry 区域）
- `arr_pool_get(cap)` — 创建时优先从池取（匹配容量 ≥ 需求的最近归还项）
- 池满时回退 `free(a)` / `malloc`

### 3.4 小字符串池

64KB bump allocator（`str_pool_alloc`）：
- ≤512 字节字符串从池分配（指针移动，零 `malloc`）
- 超限回退 `malloc`
- `tphp_rt_str_free` 检查指针范围，池内指针跳过不 `free`

---

## 4. 扩展指南

### 添加新运算符

1. `TokenType`：添加枚举值
2. `Lexer`：多字符加入 `$multiOps`，三字符特殊处理
3. `Parser`：在对应优先级方法中添加匹配
4. `AST/Node.php`：添加节点类 + Visitor 方法
5. `CodeGenerator`：实现 `visitXxx()` + `inferType()` 返回类型

### 添加内置函数

1. `CodeGenerator::visitCall()`：添加处理分支
2. `CodeGenerator::inferCallReturnType()`：添加返回类型
3. C 运行时：在 `include/` 下添加实现
4. 测试：添加测试文件到 `test/var/`
5. 文档：更新 `FUNCTIONS.md` 和 `README.md`

### 添加系统函数

参照 `os/times.h` 模式：单独头文件 + `common.h` 引用。注意跨平台兼容（`#ifdef _WIN32`）。

### 添加新语句

1. `TokenType` + `Lexer`：添加关键字
2. `AST/Node.php`：添加 `XxxStmtNode extends StmtNode` + Visitor 方法
3. `Parser::parseStmt()`：添加匹配分发 + 实现 `parseXxxStmt()`
4. `CodeGenerator`：实现 `visitXxxStmt()`

---

## 5. 测试框架

### tester.php

项目根目录的 `tester.php` 是自动化测试工具，遍历 `test/` 目录下所有 `.php` 文件，编译并运行，检查退出码。

```bash
php tester.php                    # 全部测试
php tester.php test/var/string.php # 单个测试
php tester.php --debug             # 调试模式（显示编译输出）
```

### 注解

测试文件前 10 行支持以下注解：

| 注解 | 说明 | 示例 |
|------|------|------|
| `// @skip` | 跳过该测试 | `// @skip` |
| `// @exit N` | 期望退出码（默认 0） | `// @exit 1` |
| `// @with a.php,b.php` | 多文件编译 | `// @with models.php,services.php` |

```php
<?php

// @with enums.php
// @exit 0

class Main {
    public function main(): void {
        echo "hello\n";
    }
}
```

### 测试结果

```
PASS          — 编译成功且退出码匹配
FAIL          — 编译成功但退出码不匹配 / 运行崩溃
COMPILE ERR   — 编译失败（语法错误 / C 编译错误）
SKIPPED       — @skip 或空文件
```

### CI

GitHub Actions 工作流 `tester.yml` 在 push/PR 时自动在 Linux x86_64、Linux aarch64、macOS aarch64、Windows x86_64 四个平台运行全量测试。

---

## 6. 安全编码规范

### 内存安全

- **error() 全局清理**：退出前遍历资源链释放所有对象/数组/字符串（type=0,1,2,3）
- **数组对象池**：归还时 `memset(0)` 防止脏数据，容量不足时 `free` 回退
- **字符串池**：`tphp_rt_str_free` 检查指针范围，池内指针跳过不 `free`
- **字符串深拷贝**：对象属性赋值时先 `tphp_rt_str_free` 再 `tphp_rt_str_dup`
- **闭包堆捕获**：`use` 变量 `calloc` 到堆，`tphp_rt_register(type=3)`，`unset` 安全释放
- **析构自动释放**：类 `__destruct` 中自动释放所有 `t_string` 属性和对象
- **phpc 内存模式**：`phpc_arr_*` → C 操作 → `phpc_free`，确保 malloc/free 配对
- **数组引用计数**：嵌套数组 `push/set` 自动 `retain`
- **零堆分配函数**：`time()` `date()` `sleep()` `usleep()` `hrtime()` 全静态缓冲区

### 命名防冲突

| 前缀 | 用途 |
|------|------|
| `tphp_class_` | 用户类 |
| `tphp_fn_` | 函数（内置 + 用户） |
| `tphp_rt_` | 运行时内部 |
| `tphp_enum_` | 枚举结构体 |
| `_e_` | 枚举 static 实例 |
| `_cap_` | 闭包捕获 struct |
| `TPHP_CONST_` | 常量宏 |
| `phpc_` | C 互操作（数组/对象/回调/内存） |
| `c_` / `php_` | C ↔ PHP 类型桥接 |
| `_arr_` / `_tmp_` | CodeGenerator 临时变量 |
| `str_pool_` | 字符串池内部 |
| `arr_pool_` | 数组池内部 |

### 代码质量

- **空指针检查**：方法入口 `if (self == NULL) return;`
- **数组创建**：`if (arr != NULL) { ... }` 包裹
- **类型不可变**：变量一旦赋值类型固定
- **TCC 兼容**：避免 C99 特性 TCC 不支持
- **分支预测**：`likely(x)` 标记热路径，`unlikely(x)` 标记错误/边界

---

## 7. 文件索引

| 文件 | 行数~ | 核心职责 |
|------|------|---------|
| `tphp.php` | ~500 | CLI 入口、多文件合并、`#flag`/`#callback` 过滤、PHAR 自解压、跨编译(-os/-arch)、编译器调用 |
| `tester.php` | ~165 | 自动化测试框架，注解驱动的编译+运行测试，多文件组合测试支持 |
| `src/TokenType.php` | ~155 | Token 枚举（~90 token，含 `INTERFACE_KW`/`TRAIT_KW`/`TRY_KW`/`EXTENDS_KW`） |
| `src/Token.php` | ~20 | Token 值对象 |
| `src/AST/Node.php` | ~890 | AST 节点 + Visitor 接口 + `callbacks`/`implements`/`traits`/`TryStmtNode` |
| `src/Lexer.php` | ~740 | 词法分析（`#include`/`#flag`/`#callback`/heredoc/插值/运算符） |
| `src/Parser.php` | ~1720 | 递归下降解析（extends/implements/interface/trait/abstract/try/catch） |
| `src/CodeGenerator.php` | ~3650 | C 代码生成（COS OOP/继承/topo排序/异常/thunk/50+ 内置函数） |
| `include/types.h` | ~130 | C 类型系统 + likely/unlikely 宏 |
| `include/val.h` | ~45 | VAR_*/STR_LIT 便捷宏 |
| `include/array.h` | ~440 | PHP 数组（128 槽复用池/sort/qsort/1.5× 增长因子） |
| `include/runtime.h` | ~300 | 运行时（64KB 字符串池/资源追踪/type=3 通用堆清理） |
| `include/builtin.h` | ~430 | 公开内置（50+ 函数） |
| `include/phpc.h` | ~200 | PHPC 互操作（类型/数组/对象/回调/thunk 适配/内存释放） |
| `include/rand.h` | ~60 | MT19937 随机数 |
| `include/object/` | 3 文件 | COS 对象系统（object.h/try.h/exception.h） |
| `include/p2c.h` | ~170 | PHP↔C 类型桥（已合并到 phpc.h） |
| `include/math.h` | ~55 | 扩展数学函数（pi/deg2rad/rad2deg/intdiv/pow） |
| `include/conv.h` | ~125 | 进制转换 + number_format（TCC pow10 fallback） |
| `include/compat.h` | ~25 | 编译器兼容层（TCC ceil/floor/sqrt/pow/round fallback） |
| `include/os/times.h` | ~95 | 系统函数（跨平台） |
| `include/os/json.h` | ~340 | JSON 编解码 |
| `include/common.h` | ~16 | 总入口 |
| `test/var/` | 50+ 文件 | 测试用例（builtin/control_flow/match/enum/closure/operators等） |
| `test/phpc/` | 4 文件 | C 互操作测试（基础/数组/对象/回调） |
| `test/files/` | 6+ 文件 | 多文件测试（const/命名空间） |
| `test/main/` | 2 文件 | 最小完整用例 |
| `test/object/` | 10+ 文件 | OOP 特性测试（extends/namespace/multi-file） |
| `test/complex/` | 2 文件 | 复杂场景（枚举交叉引用） |
| `.github/workflows/` | 2 文件 | CI：`build.yml`（构建发版）、`tester.yml`（全平台测试） |
