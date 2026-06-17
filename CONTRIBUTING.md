# TinyPHP 开发指南

> 面向 AI 及开发者：项目架构、扩展点、代码生成模式。

---

## 1. 架构总览

```
tphp.php                        入口 CLI（参数解析、文件收集、AST 合并、调用 TCC）
  └─ src/
       ├─ TokenType.php          枚举：所有 Token 类型
       ├─ Token.php              值对象：(type, lexeme, line, column, literal)
       ├─ AST/Node.php           AST 节点 + Visitor 接口
       ├─ Lexer.php              词法分析 → Token[]
       ├─ Parser.php             递归下降解析 → AST
       ├─ CodeGenerator.php      访问者模式遍历 AST → C 代码字符串
       └─ Compiler.php           独立 API（当前未使用，逻辑在 tphp.php）

include/                         C 运行时头文件（生成的 .c 会 #include "common.h"）
  ├─ common.h                    总入口
  ├─ types.h                    类型定义（t_int, t_string, t_array, t_var, ...）
  ├─ val.h                      便捷宏（VAR_INT, STR_LIT, ...）
  ├─ array.h                    PHP 风格数组实现
  └─ function.h                 运行时函数（echo, var_dump, 内存管理）

tcc/                            内置 TCC 编译器源码
  └─ win32/build-tcc.bat        Windows 构建脚本
```

---

## 2. 编译流水线详解

### 2.1 Lexer（词法分析）

**文件**: `src/Lexer.php`

```php
$lexer  = new Lexer($source);   // 输入 PHP 源码字符串
$tokens = $lexer->tokenize();   // 输出 Token[]
```

**如何扩展**：
- 新增关键字 → `$keywords` 数组 + `TokenType` 枚举
- 新增运算符/符号 → `$singleChars` 数组或手动处理
- 块注释 `/* */` → `skipBlockComment()`
- 行注释 `//` → `skipLineComment()`

**关键词映射**（`$keywords` 数组）：

```php
'class'       => TokenType::CLASS_KW,
'namespace'   => TokenType::NAMESPACE,
'use'         => TokenType::USE,
'var_dump'    => TokenType::VAR_DUMP,
// ...
```

### 2.2 Parser（语法分析）

**文件**: `src/Parser.php`

递归下降解析器，每个非终结符一个方法。当前语法：

```
program     → PHP_OPEN namespace_decl? use_decl* decl* EOF
decl        → class_decl | function_decl
class_decl  → CLASS_KW IDENTIFIER LBRACE method* RBRACE
method      → visibility FUNCTION name LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
function    → FUNCTION IDENTIFIER LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
stmt        → echo_stmt | return_stmt | assign_stmt | expr_stmt
assign_stmt → IDENTIFIER EQUALS expr SEMICOLON
expr        → additive ((PLUS|MINUS) additive)*
additive    → multiplicative ((PLUS|MINUS) multiplicative)*
multiplicative → primary ((STAR|SLASH) primary)*
primary     → literal | variable | call | new | cast | closure | array_literal
```

**如何添加新语句**（以 `if` 为例）：

1. `TokenType` 添加 `IF_KW`, `ELSE_KW`
2. `Lexer::$keywords` 添加 `'if' => TokenType::IF_KW`
3. `AST/Node.php` 添加 `IfStmtNode extends StmtNode`
4. `ASTVisitor` 添加 `visitIfStmt(IfStmtNode): string`
5. `Parser::parseStmt()` 添加 `if ($this->match(TokenType::IF_KW)) return $this->parseIfStmt();`
6. 编写 `parseIfStmt()` 解析条件表达式和方法体
7. `CodeGenerator` 添加 `visitIfStmt()` 生成 C 的 `if (...) { ... }`

### 2.3 AST（抽象语法树）

**文件**: `src/AST/Node.php`

节点层次：

```
ASTNode（抽象）
├── ProgramNode          根节点
│     ├── mainClass      ?ClassNode   // 全局 class Main（必须）
│     ├── extraClasses   ClassNode[]
│     └── functions      FunctionNode[]
├── ClassNode            类定义
│     ├── name           string
│     ├── methods        MethodNode[]
│     └── namespace      string       // 如 "Demo" 或 ""
├── FunctionNode         独立函数
│     └── namespace      string
├── MethodNode           方法
├── ParamNode            参数
├── StmtNode（抽象）
│     ├── EchoStmtNode
│     ├── ReturnStmtNode
│     ├── AssignStmtNode
│     └── ExprStmtNode   表达式语句
└── ExprNode（抽象）
      ├── StringLiteralExpr
      ├── IntLiteralExpr
      ├── FloatLiteralExpr
      ├── BoolLiteralExpr
      ├── NullLiteralExpr
      ├── VariableExpr
      ├── BinaryExpr        左 op 右
      ├── CallExpr          callee? + name + args
      ├── CastExpr          (type) expr
      ├── NewExpr           new ClassName(args)
      ├── ArrayLiteralExpr  [1, 2, 3]
      └── ClosureExpr       匿名函数
```

**访问者模式**：每个 `ASTNode::accept(ASTVisitor)` 调用 visitor 对应方法。`CodeGenerator` 是唯一的 `ASTVisitor` 实现。

### 2.4 CodeGenerator（代码生成）

**文件**: `src/CodeGenerator.php`

实现 `ASTVisitor` 接口，遍历 AST 生成 C 代码字符串。

**生成结构**（`visitProgram` 输出顺序）：

```c
#include "common.h"

// Phase 1 — 前置声明
typedef struct { t_object _base; } tphp_Main;         // 所有类 struct
void tphp_Main_main(tphp_Main* self);                 // 方法声明
tphp_Main* new_tphp_Main(...);                         // allocator 声明
static void tphp_myFn();                              // 独立函数声明

// Phase 2 — 实现
void tphp_Main_main(tphp_Main* self) { ... }          // 方法实现
tphp_Main* new_tphp_Main(...) { ... }                  // allocator 实现
static void tphp_myFn() { ... }                        // 独立函数实现

// 闭包实现（文件作用域）
t_int _closure_1() { ... }

// C 入口
int main(int argc, char* argv[]) {
    t_array* _argv = tphp_build_argv(argc, argv);
    tphp_Main* _main = new_tphp_Main((t_int)argc, _argv);
    ...
}
```

**关键内部状态**：

| 属性 | 说明 |
|------|------|
| `$this->className` | 当前正在生成的类 C 名（如 `tphp_Main`） |
| `$this->varTypes` | `varName → C 类型` 映射（如 `'d' → 'tphp_Demo_Demo'`） |
| `$this->declaredVars` | 已声明变量集合 |
| `$this->scopeObjects` | 作用域内对象列表（方法结束时自动插入析构） |
| `$this->closureImpls` | 积累的闭包实现字符串数组 |
| `$this->indent` | 当前缩进级别 |

**命名规则**：

| PHP | C 标识符 |
|-----|---------|
| `class Main` | `tphp_Main` |
| `Main::hello()` | `tphp_Main_hello` |
| `namespace Demo; class Demo` | `tphp_Demo_Demo` |
| `new Demo()` | `new_tphp_Demo_Demo(...)` |
| `function myFn()` | `tphp_myFn` |
| `namespace Demo; function myFn()` | `tphp_Demo_myFn` |
| `var_dump($x)` | `tphp_var_dump(VAR_INT(x))` |
| `$f = function(): int { ... }` | `({ t_int _closure_1(); (t_callback){...} })` |
| `$h()` （闭包调用） | `((t_int(*)(void))h.func)()` |

### 2.5 tphp.php（入口 CLI）

1. 解析参数 → 收集 `.php` 文件
2. 依次 Lexer → Parser 解析每个文件
3. 合并 AST（`class Main` 为主节点，其余归入 extraClasses）
4. 清理 `build/` → CodeGenerator 生成 `.c`
5. 调用 TCC 编译 → `.exe`

---

## 3. C 运行时

### 3.1 类型系统（`types.h`）

```c
typedef enum { TYPE_NULL=0, TYPE_INT=1, TYPE_FLOAT=2, TYPE_BOOL=3,
               TYPE_STRING=4, TYPE_ARRAY=5, TYPE_OBJECT=6, TYPE_CALLBACK=7 } type_t;

typedef int64_t t_int;       typedef double  t_float;       typedef bool t_bool;
typedef struct { char *data; int length; } t_string;
typedef struct { void *func; void *env; } t_callback;

// t_var — 带标签的值联合体
struct _t_var { type_t type; t_value value; };
typedef union { t_int _int; t_float _float; ... } t_value;

// t_array — PHP 万能数组（引用计数、有序键值对）
struct _t_array { t_entry *entries; int length, capacity, refcount; };

// t_object — 对象基类
typedef struct { const ClassVTable *vtable; int refcount; } t_object;
```

### 3.2 数组 API（`array.h`）

```c
t_array* tphp_arr_create(void);                          // 创建空数组
void     tphp_arr_push(t_array *a, t_var val);           // 追加（$a[] = val）
void     tphp_arr_set_str(t_array *a, t_string key, t_var val);  // 字符串键
void     tphp_arr_set_int(t_array *a, t_int key, t_var val);     // 整数键
t_var*   tphp_arr_get_str(t_array *a, t_string key);     // 查找
int      tphp_arr_count(t_array *a);                     // 元素个数
void     tphp_arr_free(t_array *a);                      // 递归释放
```

### 3.3 类型包装宏（`val.h`）

```c
VAR_INT(10)         // → (t_var){.type=TYPE_INT, .value._int=10}
VAR_FLOAT(3.14)     // → (t_var){...}
VAR_BOOL(true)      // → (t_var){...}
VAR_STRING(s)       // → (t_var){...}
VAR_ARRAY(a)        // → (t_var){...}
VAR_CALLBACK(c)     // → (t_var){...}
VAR_NULL()          // → (t_var){.type=TYPE_NULL}
STR_LIT("hello")    // → (t_string){.data="hello", .length=5}  编译期计算
```

---

## 4. 扩展特性指南

### 添加新表达式（如 `true` / `false` 以外的其他常量）

1. `TokenType`：添加 token
2. `Lexer::$keywords`：映射关键词
3. `AST/Node.php`：添加 `XxxExpr extends ExprNode`
4. `ASTVisitor`：添加 `visitXxx(XxxExpr): string`
5. `Parser::parsePrimary()`：在对应位置添加匹配
6. `CodeGenerator`：实现 `visitXxx()`

### 添加控制流语句（如 `if`）

1. Token + Lexer + AST 节点 + Visitor 接口（同上面 1-4）
2. `Parser::parseStmt()` 添加分发 + 编写 `parseIfStmt()`
3. `CodeGenerator::visitIfStmt()` 生成 C 代码
4. 如需条件表达式（如 `>`），还要扩展 `parsePrimary` 或 `parseAdditive`

### 添加新的内置函数（如 `count`, `strlen`）

1. `include/function.h` 实现 C 函数
2. `Lexer::$keywords` 映射关键词（可选，不影响词法）
3. `CodeGenerator::visitCall()` 特殊处理（参照 `var_dump` 的处理方式）

### 添加新的 `var_dump` 类型支持

1. `include/val.h` 添加 `VAR_XXX` 宏
2. `include/function.h` `tphp_var_dump_rec` 添加 `case TYPE_XXX`
3. `CodeGenerator::wrapVar()` 添加对应 match 分支
4. `CodeGenerator::inferType()` 返回对应类型

---

## 5. 安全编码规范

- **所有 C 标识符**以 `tphp_` 开头，避免与标准库冲突
- **空指针检查**：方法入口 `if (self == NULL) return;`
- **数组创建**：`if (_arr != NULL) { ... }` 包裹 push 操作
- **var_dump**：entry 的 `key`/`value` 空指针检查，字符串长度负数保护
- **内存管理**：`tphp_arr_free` 递归释放引用计数对象
- **编译器报错**：多文件场景拒绝游离代码，明确报错文件名+行号

---

## 6. 文件索引

| 文件 | 行数 | 核心职责 |
|------|------|---------|
| `tphp.php` | ~220 | CLI 入口、多文件收集、AST 合并 |
| `src/TokenType.php` | ~65 | Token 枚举 |
| `src/Token.php` | ~20 | Token 值对象 |
| `src/AST/Node.php` | ~370 | 全部 AST 节点 + Visitor 接口 |
| `src/Lexer.php` | ~300 | 词法分析 |
| `src/Parser.php` | ~500 | 递归下降解析（namespace/use/表达式/语句） |
| `src/CodeGenerator.php` | ~700 | C 代码生成（类型推导/闭包/命名空间） |
| `include/types.h` | ~105 | C 类型系统 |
| `include/val.h` | ~35 | 便捷宏 |
| `include/array.h` | ~240 | PHP 数组实现 |
| `include/function.h` | ~175 | 运行时函数 |
| `include/common.h` | ~10 | 总入口 |
