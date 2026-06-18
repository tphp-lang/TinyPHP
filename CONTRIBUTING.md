# TinyPHP 开发指南

> 面向 AI 及开发者：项目架构、扩展点、代码生成模式、安全规范。

---

## 1. 架构总览

```
tphp.php                        入口 CLI（参数解析、文件收集、AST 合并、调用编译器）
  └─ src/
       ├── TokenType.php         Token 枚举（PHP 8.1 enum）
       ├── Token.php             Token 值对象 (type, lexeme, line, column, literal)
       ├── AST/Node.php          AST 节点 + Visitor 接口
       ├── Lexer.php             词法分析 → Token[]
       ├── Parser.php            递归下降解析 → AST
       ├── CodeGenerator.php     访问者模式遍历 AST → C 代码
       └── Compiler.php          独立 API（逻辑在 tphp.php）

include/                         C 运行时头文件（静态 inline 库）
  ├── common.h                   总入口
  ├── types.h                    类型定义 (t_int, t_string, t_array, t_var, …)
  ├── val.h                      便捷宏 (VAR_INT, STR_LIT, …)
  ├── array.h                    PHP 风格数组实现（引用计数 + 嵌套安全）
  ├── runtime.h                  内部辅助（字符串拼/插/比较、对象析构、初始化）
  └── builtin.h                  公开内置（echo, var_dump, exit）

tcc/                            内置 TCC 编译器源码
  └── win32/build-tcc.bat       Windows 构建脚本
```

---

## 2. 编译流水线详解

### 2.1 Lexer（词法分析）

**文件**: `src/Lexer.php`

```php
$lexer  = new Lexer($source);
$tokens = $lexer->tokenize();   // → Token[]
```

**令牌扫描顺序**（优先级从高到低）：
1. 空白 / 换行（CRLF → 一次 line++）
2. `/` → `//` 行注释 / `/* */` 块注释 / `/=` 复合赋值 / `/` 除号
3. **多字符运算符**（`->` `=>` `==` `!=` `<=` `>=` `&&` `||` `++` `--` `+=` `-=` `*=` `/=` `.=`）
4. **单字符运算符**（`+` `-` `*` `<` `>` `!`）
5. 字符串（`"` 双引号含插值 / `'` 单引号）
6. `\` → 命名空间分隔符 `NS_SEP`
7. **`::` 双冒号**（必须在单字符 `:` 之前）
8. 单字符 `:` `;` `,` `=` `.` `(` `)` `{` `}` `[` `]`
9. `$` → 变量名
10. 数字 → int / float
11. 标识符 / 关键字

**如何扩展**：
- 新增关键字 → `$keywords` 数组 + `TokenType` 枚举
- 新增多字符运算符 → `$multiOps` 数组
- 新增字符 → 注意扫描顺序（例如 `::` 必须在 `:` 之前，`<<<` heredoc 在 `<` 之前）
- 新增字符串语法 → `scanString()` / `scanHeredoc()` 方法

### 2.2 Parser（语法分析）

**文件**: `src/Parser.php`

递归下降解析器。当前语法：

```
program      → PHP_OPEN namespace_decl? use_decl* const_decl* enum_decl* decl* EOF
decl         → class_decl | function_decl
enum_decl    → ENUM_KW IDENTIFIER COLON type LBRACE case* RBRACE
case         → CASE_KW IDENTIFIER EQUALS literal SEMICOLON
class_decl   → CLASS_KW IDENTIFIER LBRACE (property|method)* RBRACE
property     → visibility type IDENTIFIER (EQUALS literal)? SEMICOLON
method       → visibility FUNCTION name LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
function     → FUNCTION IDENTIFIER LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
stmt         → echo_stmt | return_stmt | assign_stmt | if_stmt | while_stmt | for_stmt
             | foreach_stmt | switch_stmt | break_stmt | continue_stmt | expr_stmt
expr         → logical_or
logical_or   → logical_and (|| logical_and)*
logical_and  → equality (&& equality)*
equality     → comparison (== != comparison)*
comparison   → additive (< > <= >= additive)*
additive     → multiplicative (+ - . multiplicative)*
multiplicative → unary (* / unary)*
unary        → ! primary | - primary | ++ primary | -- primary
postfix      → primary (-> method(args) | -> prop | [index] | ++ | --)*
primary      → literal | variable | call | new | cast | closure | array_literal | enum_access
```

**运算符优先级**（从低到高）：
`||` < `&&` < `==` `!=` < `<` `>` `<=` `>=` < `+` `-` `.` < `*` `/` < `!` `-` `++` `--` (一元) < 后缀链

**如何添加新语句**（以 `if` 为例）：

1. `TokenType` 添加 `IF_KW`, `ELSE_KW`
2. `Lexer::$keywords` 添加 `'if' => TokenType::IF_KW`
3. `AST/Node.php` 添加 `IfStmtNode extends StmtNode`
4. `ASTVisitor` 添加 `visitIfStmt(IfStmtNode): string`
5. `Parser::parseStmt()` 添加匹配分发
6. `CodeGenerator` 添加 `visitIfStmt()`

### 2.3 AST（抽象语法树）

**文件**: `src/AST/Node.php`

节点层次（所有 `ExprNode` 携带 `line`/`column` 用于错误定位）：

```
ASTNode（抽象）
├── ProgramNode          根节点 (mainClass? + extraClasses + functions + constants + enums)
├── ClassNode            类 (name, methods, properties, namespace)
├── FunctionNode         独立函数 (name, params, returnType, body, namespace)
├── MethodNode           方法 (name, visibility, params, returnType, body)
├── EnumNode             枚举 (name, backingType, cases, namespace)
├── EnumCaseNode         枚举条目 (name, value)
├── ParamNode            参数 (type, name)
├── ConstNode            常量 (name, value, namespace)
├── StmtNode（抽象）
│   ├── EchoStmtNode
│   ├── ReturnStmtNode
│   ├── AssignStmtNode
│   ├── AssignPropStmtNode
│   ├── ExprStmtNode
│   ├── IfStmtNode        (condition, thenBody, elseifs[], elseBody)
│   ├── WhileStmtNode
│   ├── ForStmtNode
│   ├── ForeachStmtNode   ($key=>$val 支持)
│   ├── SwitchStmtNode
│   ├── BreakStmtNode
│   └── ContinueStmtNode
├── ElseIfBranch          辅助类 (condition, body)
├── CaseBranch            辅助类 (value?, body)  — null=default
└── ExprNode（抽象，含 line/column）
    ├── StringLiteralExpr / IntLiteralExpr / FloatLiteralExpr
    ├── BoolLiteralExpr / NullLiteralExpr
    ├── VariableExpr
    ├── UnaryExpr          一元运算 (-, !, ++, --)
    ├── PostfixExpr        后缀运算 ($var++, $var--)
    ├── BinaryExpr         二元运算 (+, -, *, /, ., ==, !=, <, >, <=, >=, &&, ||)
    ├── CompoundAssignExpr 复合赋值 (+=, -=, *=, /=, .=)
    ├── CallExpr           函数/方法调用 (callee? + name + args)
    ├── CastExpr           类型转换 (castType + expr)
    ├── NewExpr            new ClassName(args)
    ├── ArrayLiteralExpr   [1, 2, 3] 或 ["key"=>val]
    ├── ArrayAccessExpr    $arr[0]
    ├── PropertyAccessExpr $obj->prop
    ├── EnumAccessExpr     Color::RED
    └── ClosureExpr        匿名函数
```

**访问者模式**：每个 `ASTNode::accept(ASTVisitor)` → visitor 对应方法。`CodeGenerator` 是唯一的 `ASTVisitor` 实现。

### 2.4 CodeGenerator（代码生成）

**文件**: `src/CodeGenerator.php`

**生成结构**（`visitProgram` 输出顺序）：

```c
#include "common.h"

// 常量（#define 宏）
// 枚举（struct 定义 + static 实例）

// Phase 1 — 前置声明（所有类的 struct + 方法声明 + allocator）
typedef struct { t_object _base; ... } tphp_class_Main;
void tphp_class_Main_main(tphp_class_Main* self);
...

// Phase 2 — 实现（VTable + 构造/析构 + 用户方法 + allocator）
// 独立函数实现
// 闭包实现（文件作用域 static 函数）

// C 入口
int main(int argc, char* argv[]) { tphp_rt_init(); ... }
```

**关键内部状态**：

| 属性 | 说明 |
|------|------|
| `$this->className` | 当前类的 C 名（如 `tphp_class_Main`） |
| `$this->varTypes` | `varName → C 类型` 映射（含 enum/object 类型） |
| `$this->declaredVars` | 已声明变量集合 |
| `$this->scopeObjects` | 作用域内对象列表（方法结尾自动 `tphp_rt_object_free`） |
| `$this->classPropTypes` | 类属性类型：`className → [propName → C type]` |
| `$this->classMethodRetTypes` | 类方法返回类型：`className → [methodName → C type]` |
| `$this->enumBackingTypes` | 枚举 backing 类型：`enumName → 'int'\|'string'` |
| `$this->enumCTypes` | 枚举 C 类型：`enumName → 'tphp_enum_X*'` |
| `$this->closureImpls` | 闭包实现数组 |
| `$this->indent` | 当前缩进级别 |

**命名规则**：

| PHP | C | 前缀 |
|-----|---|------|
| `class Main` | `tphp_class_Main` | `tphp_class_` |
| `namespace Demo; class Demo` | `tphp_class_Demo_Demo` | `tphp_class_` |
| `Main::hello()` | `tphp_class_Main_hello` | `tphp_class_` |
| `new Demo()` | `new_tphp_class_Demo()` | `new_` |
| `function foo()` | `tphp_fn_foo` | `tphp_fn_` |
| `namespace Demo; function fn()` | `tphp_fn_Demo_fn` | `tphp_fn_` |
| `enum Color: string` | `tphp_enum_Color` | `tphp_enum_` |
| `Color::RED` | `&_e_Color_RED`（static 实例指针） | `_e_` |
| `$c->value` / `$c->name` | 结构体字段直接访问 | — |
| `var_dump($x)` | `tphp_fn_var_dump(VAR_INT(x))` | `tphp_fn_` |
| `exit(0)` | `tphp_fn_exit(0)` | `tphp_fn_` |
| `$f = function() use($x) {…}` | `({ t_int _closure_1(t_int, void*); _cap_1 _env={.x=x}; (t_callback){…, .env=&_env} })` | `_closure_`, `_cap_` |
| `$arr[0]` | `tphp_arr_item_int(arr, 0)` | `tphp_` |
| `$a . $b` | `tphp_rt_str_concat(a, b)` | `tphp_rt_` |
| `"hello $name"` | Lexer 自动拆为 `tphp_rt_str_concat("hello ", name)` | `tphp_rt_` |
| `const APP_NAME = "x"` | `#define TPHP_CONST_APP_NAME STR_LIT("x")` | `TPHP_CONST_` |

**枚举代码生成**：

```
PHP:  enum Color: string { case RED = "red"; }
C:    typedef struct {
          t_string name;
          t_string value;
      } tphp_enum_Color;

      static tphp_enum_Color _e_Color_RED = {
          .name = STR_LIT("RED"),
          .value = STR_LIT("red"),
      };

      // Color::RED   → &_e_Color_RED（指针）
      // $c->value    → c->value（t_string）
      // $c->name     → c->name（t_string）
      // $c == Color::RED → 指针同一性比较
```

**类型转换代码生成套路**：
- `visitCast` 分发到 `castToInt` / `castToFloat` / `castToBool` / `castToStr` / `castToArray`
- `castToStr` 有 `$strict` 参数：true 时数组/对象报错，false 时静默转 "Array"/"Object"
- `wrapVar`（var_dump 用）根据表达式类型选择 `VAR_*` 宏，含枚举自动解引用

**字符串 switch 降级**：
C switch 不支持字符串，TinyPHP 自动将 string-switch 转为 if-elseif 链，并跳过 case body 中的 break 语句。

### 2.5 tphp.php（入口 CLI）

**两阶段解析**：
1. 先解析辅助文件（非 Main），收集枚举名/类名
2. 再解析 Main 入口文件（此时 `setKnownEnums` 已注入所有枚举名）

**多文件合并**：主类 → `mainClass`，其他类 → `extraClasses`，函数/常量/枚举全部合并。

---

## 3. C 运行时

### 3.1 头文件职责

| 文件 | 前缀 | 职责 |
|------|------|------|
| `common.h` | — | 总入口，`#include` 所有头文件 |
| `types.h` | — | 类型系统：`t_int` `t_float` `t_string` `t_bool` `t_var` `t_array` `t_object` `t_callback` `ClassVTable` `type_t` |
| `val.h` | `VAR_*` `STR_LIT` | 便捷包装宏 |
| `array.h` | `tphp_fn_arr_` | PHP 数组 API（引用计数 + 嵌套安全释放） |
| `runtime.h` | `tphp_rt_` | 内部辅助：初始化、字符串拼/拆/比较、对象析构、内存安全 |
| `builtin.h` | `tphp_fn_` | 公开内置：echo、var_dump、exit |

### 3.2 字符串比较与拼接

```c
// 比较（字典序，二进制安全）
tphp_rt_str_eq(a, b)   // ==
tphp_rt_str_ne(a, b)   // !=
tphp_rt_str_lt(a, b)   // <
tphp_rt_str_gt(a, b)   // >
tphp_rt_str_le(a, b)   // <=
tphp_rt_str_ge(a, b)   // >=

// 拼接（堆分配，返回的 data 需手动 free）
tphp_rt_str_concat(a, b)

// 深拷贝到堆（对象属性赋值用，防止栈/临时内存悬空）
tphp_rt_str_dup(s)

// 安全释放
tphp_rt_str_free(&s)
```

### 3.3 类型包装宏（`val.h`）

```c
VAR_INT(10)       // → (t_var){.type=TYPE_INT, .value._int=10}
VAR_FLOAT(3.14)   VAR_BOOL(true)    VAR_STRING(s)
VAR_ARRAY(a)      VAR_CALLBACK(c)   VAR_NULL()
STR_LIT("hello")  // → (t_string){.data="hello", .length=5}  编译期计算
```

### 3.4 isset / empty（`builtin.h`）

```c
// isset — 非 null 检测（非指针类型始终 true）
static inline bool tphp_fn_isset(void* p)      { return p != NULL; }

// empty — 按类型分发
static inline bool tphp_fn_empty_int(t_int v)   { return v == 0; }
static inline bool tphp_fn_empty_float(t_float v) { return v == 0.0; }
static inline bool tphp_fn_empty_bool(t_bool v)  { return !v; }
static inline bool tphp_fn_empty_str(t_string s) { return tphp_rt_str_is_falsy(s); }
static inline bool tphp_fn_empty_null(void* p)   { (void)p; return true; }
```

CodeGenerator 按 `inferType()` 分发到对应函数，isset 对 int/float/bool/string 直接返回 `true`。

---

## 4. 扩展指南

### 添加新运算符

1. `TokenType`：添加枚举值
2. `Lexer`：多字符加入 `$multiOps`，单字符加入 `$singleChars`（注意扫描顺序）
3. `Parser`：在对应优先级方法中添加匹配
4. `AST/Node.php`：添加 `XxxExpr extends ExprNode` + `ASTVisitor` 接口方法
5. `CodeGenerator`：实现 `visitXxx()` + `inferType()` 推导返回类型

### 添加新语句

1. `TokenType` + `Lexer`：添加关键字
2. `AST/Node.php`：添加 `XxxStmtNode extends StmtNode` + Visitor 方法
3. `Parser::parseStmt()`：添加匹配分发 + 实现 `parseXxxStmt()`
4. `CodeGenerator`：实现 `visitXxxStmt()`

### 添加内置函数

1. `TokenType` + `Lexer::$keywords` 添加关键词
2. `Parser`：在 `parsePrimary()` 的标识符检查中加入 token，在函数调用路径中跳过命名空间解析
3. `CodeGenerator::visitCall()`：添加特殊处理分支
4. `include/builtin.h`：添加对应 C 函数实现

### 添加枚举

枚举核心流程已完整，扩展点：
- 纯枚举（无 backing type）：`enum Name { case A; }` — 需 `EnumNode` 调整 + 生成 name-only struct
- 枚举方法：`enum Name { case A; public function label(): string { … } }` — 需类化枚举

### 添加新类型转换

1. `CodeGenerator::castToXxx(ExprNode)`：按字面量/变量/枚举等类型推导生成转换代码
2. `visitCast`：分发到对应方法
3. C 运行时：如需解析函数添加到 `runtime.h`
4. `wrapVar` + `inferType`：添加对应分支

---

## 5. 安全编码规范

### 内存安全

- **字符串深拷贝**：对象属性赋值字符串时先 `tphp_rt_str_free` 旧值再 `tphp_rt_str_dup` 新值
- **析构自动释放**：类 `__destruct` 中自动 `tphp_rt_str_free` 所有 `t_string` 属性
- **数组引用计数**：嵌套数组 `push/set` 时自动 `retain`，`free` 时递归释放
- **对象引用计数**：作用域结束自动 `tphp_rt_object_free`（减计数，归零调 dtor + free）
- **安全 malloc**：`tphp_rt_malloc` 用 `calloc` + OOM abort；`tphp_rt_free` 空指针检查
- **枚举零堆分配**：static 实例存放在 `.data` 段，`name`/`value` 指向 `.rodata`

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
| `_closure_` | 闭包函数 |
| `_arr_` / `_fi_` / `_fc_` | CodeGenerator 临时变量 |

### 代码质量

- **空指针检查**：方法/函数入口 `if (self == NULL) return;`
- **数组创建**：`if (_arr != NULL) { ... }` 包裹
- **var_dump**：entry 的 key/value 空指针检查，字符串长度负数保护
- **类型不可变**：变量一旦赋值类型就固定，`$x = 10; $x = "str";` 编译报错
- **跨命名空间常量**：不支持跨命名空间常量引用（编译报错，避免运行时空指针）
- **枚举跨命名空间**：需 `use` 导入，否则视为未定义常量报错

---

## 6. 文件索引

| 文件 | 行数 | 核心职责 |
|------|------|---------|
| `tphp.php` | ~300 | CLI 入口、两阶段解析、多文件收集、AST 合并、编译器调用 |
| `src/TokenType.php` | ~125 | Token 枚举（PHP 8.1 enum, 55+ token） |
| `src/Token.php` | ~20 | Token 值对象 |
| `src/AST/Node.php` | ~750 | 全部 AST 节点（35+） + Visitor 接口 |
| `src/Lexer.php` | ~650 | 词法分析（heredoc/nowdoc/插值/转义/运算符优先级） |
| `src/Parser.php` | ~1450 | 递归下降解析（namespace/use/mixed/union/表达式/控制流/match/enum/closure use/goto） |
| `src/CodeGenerator.php` | ~2100 | C 代码生成（类型推导/闭包捕获/t_var/mixed/match/枚举/switch/位运算） |
| `include/types.h` | ~105 | C 类型系统 |
| `include/val.h` | ~45 | 便捷宏（VAR_INT, STR_LIT, VAR_AS_* unwrap 等） |
| `include/array.h` | ~265 | PHP 数组（引用计数 + 嵌套安全释放） |
| `include/runtime.h` | ~195 | 内部辅助（字符串拼/拆/比较、对象析构、falsy 检测） |
| `include/builtin.h` | ~150 | 公开内置（echo, var_dump, exit, isset, empty, unset） |
| `include/common.h` | ~10 | 总入口，`#include` 所有头文件 |
