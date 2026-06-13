# TinyPHP 开发指南

> 本文档旨在帮助开发者（或 AI）快速理解项目架构与实现细节，以便继续完成开发。

---

## 项目文件结构

```
tphp/
├── tphp.php                     # CLI 入口：参数解析 → 多文件扩展 → Compiler::compile()
├── src/
│   ├── AST/                     # AST 节点定义（按类别拆分，命名空间 Tphp\AST）
│   │   ├── Node.php             #   ASTNode 基类 + TphpType 类型枚举 + ExprNode/StmtNode 抽象类
│   │   ├── Decl.php             #   声明节点：ProgramNode, FunctionDecl, ClassDecl, EnumDecl, ExternFunc 等
│   │   ├── Stmt.php             #   语句节点：VarDecl, ExprStmt, PrintStmt, If/While/For/Switch/Break 等
│   │   └── Expr.php             #   表达式节点：字面量, BinaryOp, FuncCall, Closure, MethodCall 等
│   ├── CodeGen/                 # 代码生成 traits（按功能域拆分，消除两套生成器重复代码）
│   │   ├── BaseGenerator.php    #   共享状态 + 类型推断 + 变量收集 + 内存 helpers（两平台共用）
│   │   ├── Linux/
│   │   │   ├── Helpers.php      #     itoa/ftoa/atoi 机器码例程（Linux syscall 调用约定）
│   │   │   ├── Output.php       #     print/echo/var_dump 实现
│   │   │   ├── ControlFlow.php  #     if/while/for/switch/break/return 控制流
│   │   │   └── ArrayOps.php     #     数组初始化/索引/追加/unset + 字符串 range/index
│   │   └── Windows/
│   │       ├── Helpers.php      #     堆清理 epilogue + itoa/ftoa/atoi（Win32 API 调用约定）
│   │       ├── Output.php       #     print/echo/var_dump 实现（WriteFile API）
│   │       ├── ControlFlow.php  #     if/while/for/switch/break/return 控制流
│   │       ├── ArrayOps.php     #     数组初始化/索引/追加/unset + 字符串 range/index
│   │       └── FFI.php          #     LoadLibrary/GetProcAddress/C类型转换/extern 函数调用
│   ├── Token.php                # Token 数据结构 + TokenType 枚举（合并，命名空间 Tphp）
│   ├── TokenType.php            # TokenType 兼容 stub（autoload 兼容）
│   ├── Lexer.php                # 词法分析器
│   ├── Parser.php               # 语法分析器 (Pratt parser)
│   ├── X64Builder.php           # x86-64 机器码构建器（标签/补丁/IAT/字符串池）
│   ├── CodeGenerator.php        # 代码生成 (Linux / syscall) — 使用 CodeGen 4 traits
│   ├── CodeGeneratorWindows.php # 代码生成 (Windows / Win32 API) — 使用 CodeGen 5 traits
│   ├── Compiler.php             # 编译流程编排：多文件合并 → Lexer→Parser→CodeGen→Writer
│   ├── Validator.php            # 语义验证：main() 检查、use 导入规则、函数调用验证
│   ├── ELFWriter.php            # ELF64 二进制写入器
│   └── PEWriter.php             # PE32+ 二进制写入器
└── test/
    ├── main/main.php            # 最小示例
    ├── main/function.php        # 多函数定义与调用
    ├── var/var.php              # 综合功能演示
    ├── var/array.php            # 数组功能演示
    ├── var/float.php            # 浮点数输出
    ├── var/const.php            # 常量测试
    ├── var/type_conversion.php  # 类型转换测试
    ├── files/                   # 多文件/命名空间/OOP 测试
    │   ├── main.php             # 入口：use 导入 + new + 方法调用
    │   ├── demo.php             # Demo 命名空间 + class MyDemo
    │   ├── name.php             # MyAdmin\Name 命名空间函数
    │   ├── other2.php           # Other 命名空间（同命名空间跨文件)
    │   └── other/other.php      # Other 命名空间函数
    ├── emun/                    # 枚举类型测试
    │   ├── main.php             # 入口：组导入、enum 参数、var_dump 格式
    │   └── my_emun.php          # MyEmun 命名空间 enum + class
    ├── heap/                    # 堆内存管理测试
    ├── control_flow/            # 控制流测试
    └── ext/                     # FFI 外部库测试
```

**命名空间**：所有源码在 `Tphp\` 命名空间下，AST 节点类在 `Tphp\AST\` 下，CodeGen traits 在 `Tphp\CodeGen\*` 下。`tphp.php` 使用 `spl_autoload_register` 自动加载。

**入口流程**：`tphp.php` 解析 CLI 参数（支持多文件/目录）→ `new Compiler(inputFiles[], outputFile, target)` → `$compiler->compile()` → 输出可执行文件。

---

## 项目概览

tphp 是一个用纯 PHP 编写的 AOT 编译器，将类 PHP 源码直接编译为 x86-64 原生可执行文件。不依赖 LLVM、GCC 或任何链接器。

**编译流水线**：

```
多文件输入 → Compiler.mergePrograms() → 合并 AST
  → Lexer → Parser → CodeGenerator → X64Builder → PEWriter/ELFWriter → 可执行文件
```

**多文件编译流程**：
1. 每个 `.php` 文件独立 Lexer → Parser → 生成 `ProgramNode`（含 namespace、imports、consts、enums、externs、functions、classes）
2. `Compiler.mergePrograms()` 合并所有 ProgramNode：
   - Main 命名空间函数保持原名
   - 非 Main 命名空间函数添加 FQN 前缀（如 `Demo\myDemo`）
   - 类/枚举添加 FQN 前缀（如 `Demo\MyDemo`/`MyEmun\MyInt`）
   - 收集所有 `use` 导入语句
3. 合并后的 ProgramNode 传递给 CodeGenerator

---

## 核心模块详解

### 1. Lexer (`src/Lexer.php`)

**职责**：将源码字符流切分为 Token 流。

**关键点**：
- 支持 `<?php` 开头标记
- 双引号字符串中的 `$var` 变量插值
- 单行 `//` 和多行 `/* */` 注释
- 运算符包括 `==`, `===`, `!=`, `!==`, `<=`, `>=`, `->`, `::`, `\`, `++`, `--`
- 关键字包括 `use`, `namespace`, `class`, `public`, `private`, `new`, `function`, `return`, `enum`, `case`, `switch`, `break`, `default`, `const`, `#extern`

### 2. Parser (`src/Parser.php`)

**职责**：Pratt Parser 风格的语法分析器，将 Token 流转为 AST。

**关键实现**：
- **Pratt Parser**：使用 `nud`（前缀）和 `led`（中缀）分派，通过绑定力（binding power）控制运算符优先级
- **命名空间**：`namespace Name;`
- **use 导入**：`use function NS\func;` / `use NS\Class;` / `use NS\{A, B, C}` 组导入
- **qualified name**：`parseQualifiedName()` 解析 `A\B\C` 形式的名称
- **类声明**：`class Name { methods }` → `ClassDeclNode`
- **方法声明**：`public/private function name(): type { body }` → `MethodDeclNode`
- **枚举声明**：`enum Name: int/string { case A = 1; }` → `EnumDeclNode` + `EnumCaseNode`
- **枚举访问**：`Name::Case` → `EnumAccessNode`（在 Identifier handler 中检查 `::` DoubleColon）
- **new 表达式**：`new ClassName()` → `NewExprNode`
- **方法调用**：`$obj->method(args)` / `$this->method(args)` → `MethodCallNode`
- **属性访问**：`$obj->prop` / `$i->value` → 返回 VarRefNode（typedef）
- **$this**：→ `ThisExprNode`
- **闭包/数组/索引**：同前

### 3. AST (`src/AST/`)

AST 节点已按语义类别拆分到 4 个文件：

| 文件 | 包含节点 |
|------|---------|
| `AST/Node.php` | `ASTNode` 基类、`TphpType` 枚举、`ExprNode`/`StmtNode` 抽象类 |
| `AST/Decl.php` | `ProgramNode`, `FunctionDeclNode`, `ClassDeclNode`, `MethodDeclNode`, `EnumDeclNode`, `EnumCaseNode`, `ExternFuncNode`, `UseImportNode`, `ConstDeclNode`, `ParamNode` |
| `AST/Stmt.php` | `VarDeclNode`, `ExprStmtNode`, `PrintStmtNode`, `ReturnStmtNode`, `IfStmtNode`, `WhileStmtNode`, `ForStmtNode`, `SwitchStmtNode`, `SwitchCaseNode`, `BreakStmtNode`, `UnsetStmtNode`, `ArrayAppendStmtNode` |
| `AST/Expr.php` | `IntegerLiteralNode`, `FloatLiteralNode`, `StringLiteralNode`, `BoolLiteralNode`, `NullLiteralNode`, `VarRefNode`, `ConstRefNode`, `BinaryOpNode`, `FuncCallNode`, `IndexAccessNode`, `StringRangeNode`, `ArrayLiteralNode`, `ExprCallNode`, `PostIncrementNode`, `ClosureNode`, `ThisExprNode`, `MethodCallNode`, `NewExprNode`, `CastExprNode`, `CFuncCallNode`, `EnumAccessNode` |

**核心节点类型**：

| 节点 | 文件 | 用途 |
|------|------|------|
| `ProgramNode` | Decl | 顶层：namespace, imports, consts, enums, externs, functions, classes |
| `FunctionDeclNode` | Decl | 函数声明 |
| `ClassDeclNode` | Decl | 类声明 |
| `MethodDeclNode` | Decl | 方法声明（含 visibility） |
| `EnumDeclNode` | Decl | 枚举声明（name, backingType, cases） |
| `EnumCaseNode` | Decl | 枚举 case（name, value） |
| `EnumAccessNode` | Expr | 枚举访问 `Name::Case` |
| `UseImportNode` | Decl | use 导入 |
| `ParamNode` | Decl | 参数（含 typeName 用于枚举/类类型提示） |
| `NewExprNode` | Expr | `new ClassName()` |
| `MethodCallNode` | Expr | `$obj->method()` / `$this->method()` |
| `ThisExprNode` | Expr | `$this` |
| `CastExprNode` | Expr | `(int)$x` 类型转换 |
| `ExternFuncNode` | Decl | `#extern` FFI 函数声明 |
| `CFuncCallNode` | Expr | `C->func()` FFI 调用 |
| `ConstDeclNode` / `ConstRefNode` | Decl/Expr | 常量声明/引用 |
| `ForStmtNode` / `SwitchStmtNode` / `BreakStmtNode` | Stmt | 控制流 |
| `PostIncrementNode` | Expr | `$i++` / `$i--` |

### 4. CodeGenerator / CodeGeneratorWindows

两套代码生成器现已通过 **traits** 消除大量重复代码。

**共享层** (`src/CodeGen/BaseGenerator.php`)：
- 类型推断：`inferType()`, `inferBinType()`, `inferIndexType()`, `inferEnumType()`, `inferConstType()`
- 变量管理：`collectVariables()`, `inferTypeInfo()`, `typeAllocSize()`
- 内存 helpers：`loadStringAtAddr()`, `storeStringAt()`, `loadFloatAtAddr()`, `storeFloatAt()`
- 标签生成：`newLabel()`
- 常量引用：`genConstRef()`, `inferConstType()`, `inferEnumType()`

**平台专用 traits**：

| Trait | Linux | Windows | 用途 |
|-------|-------|---------|------|
| `Helpers` | `Linux/Helpers.php` | `Windows/Helpers.php` | itoa/ftoa/atoi 机器码例程（Windows 含堆清理） |
| `Output` | `Linux/Output.php` | `Windows/Output.php` | print/echo/var_dump 实现 |
| `ControlFlow` | `Linux/ControlFlow.php` | `Windows/ControlFlow.php` | if/while/for/switch/break/postIncrement/return |
| `ArrayOps` | `Linux/ArrayOps.php` | `Windows/ArrayOps.php` | 数组初始化/索引/append/unset + 字符串 range/index |
| `FFI` | — | `Windows/FFI.php` | LoadLibrary/GetProcAddress/C类型转换/extern 调用 |

#### 4.1 多文件 & 导入解析
- `functionNodes`：所有函数（FQN → FunctionDeclNode），包括类方法（`Class::method`）
- `importMap`：`use function` 别名 → FQN
- `classImportMap`：`use ClassName` 别名 → FQN
- `enums`：枚举 FQN → `[caseName → value]`

#### 4.2 类与对象
- **`new ClassName()`**：解析类 FQN → 调 `__construct` → 存储 class name 到 var info
- **`$obj->method()`**：从 var info 找 className → 构建 `Class::method` → 调 `genUserFuncCall`
- **`$this->method()`**：使用 `currentClassName` 上下文解析
- **析构函数**：`pendingDestructors` 列表在 `main()` 末尾清空

#### 4.3 枚举
- **`Name::Case`**：`genEnumAccess()` 在编译期解析 case 值。int 用 `mov RI32`，string 用栈分配字节
- **枚举参数追踪**：`generateFunctionBody2` 中检查 `ParamNode.typeName` → `classImportMap` → `enums` → 存储 `enumName` 到 `calleeVars`
- **`(enum)` var_dump**：`printEnumCase()` 运行时比较 RAX 与 case 值 → 输出 `(enum) Name::Case`
- **`$i->value`**：parser 中属性访问返回 VarRefNode → codegen 直接读取变量值

#### 4.4 FFI（仅 Windows）
- **`emitExternInit()`**：栈上构建 DLL 路径字符串 → `LoadLibraryA` → `GetProcAddress`
- **`genCFuncCall()`**：加载函数指针 → `call r11`
- 类型转换：`CStr`, `CInt`, `CFloat`, `CBool`, `TInt`, `TFloat`, `TBool`, `TStr`
- 所有 FFI 代码位于 `src/CodeGen/Windows/FFI.php` trait

#### 4.5 内存管理（Windows）
- **字符串拼接**：`HeapAlloc` → 作用域结束自动 `HeapFree`
- **heapVarOffsets**：跟踪每个堆变量的栈偏移，在函数 epilogue 前生成 `HeapFree` 调用

### 5. Compiler (`src/Compiler.php`) + Validator (`src/Validator.php`)

**Compiler** 负责：编排编译流程、多文件合并、命名空间/导入解析。

**Validator**（独立）负责：语义验证 —— main() 存在检查、use 导入规则、函数调用验证。

**多文件编译流程**：
1. 遍历所有输入文件，每个文件独立 Lexer → Parser
2. `mergePrograms()` 合并：Main 函数短名，其他命名空间 FQN；枚举/类/常量/外部函数统一处理
3. `Validator::validateFile()` 逐文件验证函数调用合法性
4. `Validator::validateEntryPoint()` 验证 main() 存在且返回 void
5. 代码生成 → PE/ELF 写入

### 7. PEWriter (`src/PEWriter.php`)

**IAT 布局**：`IAT_RVA = 0x3070`（因 `.text` 代码段可超过 0x1000 字节，`.rdata` 从 0x2000 移至 0x3000 避免虚拟地址重叠），8 个 IAT 函数：LoadLibraryA, GetProcAddress, GetStdHandle, WriteFile, ExitProcess, SetConsoleOutputCP, HeapAlloc, GetProcessHeap。

`CodeGeneratorWindows` 中 IAT 常量必须与 `PEWriter::IAT_RVA` 保持同步。

### 8. 类型转换 (`genCast`)

`genCast` 统一处理三种目标类型：

| 目标 | 实现 |
|------|------|
| `(int)` | Float→int (cvttsd2si), String→int (atoi), Array→int (0/1), Bool/Null→int (直通) |
| `(float)` | Int/Bool→float (cvtsi2sd), String→float (编译期 PHP `(float)` 解析), Null→0 (xorps), Array→0/1 |
| `(string)` | Int/Bool→itoa, Float→ftoa, Null→空串, String→直通, Array→编译错误 |

**`(float)` 字符串编译期解析**：对 `StringLiteralNode` 在编译时调用 `PHP (float)$s` → `pack('d', $f)` → IEEE 754 位模式 → `mov rax, imm64` (0 值用 xorps 避免 10 字节指令)。

**`(string)` 栈缓冲**：itoa/ftoa 写入 `[RBP + itoaBufOffset]` 栈缓冲区，通过 `mov rax, rsi` 返回指针。栈缓冲在同一函数内不会被后续操作覆盖（除非再次调用 itoa/ftoa）。

### 9. 字符串插值 (`"$var"`)

**Lexer**：双引号字符串中 `$var` 始终 emit `StringLiteral("")` + `Concat`，确保 Parser 生成 `BinaryOpNode('.', "", VarRef)`。

**genVarDecl 拦截**：检测 `BinaryOpNode('.', "", VarRef)` 模式 → 转为 `CastExprNode(String, VarRef)` → genCast 路径，绕过 genBinOp（避免子函数 IAT 调用问题）。

**printString 处理**：`CastExprNode` → genExpr(genCast) → 直接 WriteFile，不再经 printByType（避免递归）。

### 10. 子函数支持

- **stdout 初始化**：子函数 prologue 调用 `GetStdHandle(-11)` 并存入 `[RBP + stdoutStackOffset]`
- **itoa(0) 修复**：zero 路径写 `'0'` 到 `[RDI]` 而非 `[RSP+0x10]`，避免 `leave` 后缓冲区失效

---

## 已知问题与限制

### 语法限制

| 特性 | 状态 | 说明 |
|------|------|------|
| `foreach` | 未实现 | |
| `continue` | 未实现 | |
| `do-while` | 未实现 | |
| 类属性/字段 | 未实现 | 类实例无属性 |
| 类继承 | 未实现 | |
| string 枚举参数 | ⚠️ 部分 | typeName 追踪待完善 |

### 运行时限制

| 问题 | 说明 |
|------|------|
| 数组越界 | 无运行时检查 |
| 除零 | 无检查 |
| 数组容量 | 最大 64 元素（`MAX_ARRAY_CAP`） |

### 架构问题

| 问题 | 说明 |
|------|------|
| IAT RVA 硬编码 | IAT 常量与 PEWriter 布局耦合；已从 0x2070 移至 0x3070（因 .text > 0x1000） |
| 两套 CodeGenerator | 已通过 traits 大幅消除重复（`BaseGenerator` + 平台专用 traits） |
| 闭包仅支持 calleeVars | 不能捕获外部变量 |
| RIP-relative 字符串 | FFI/枚举字符串值需栈上构建躲避 resolveStringPatches |
| HeapFree IAT 未注册 | `IAT_HEAPFREE` 常量已定义但未加入 IAT 导入表 |
| `.rdata` 段固定 RVA | `RDATA_RVA = 0x3000` 硬编码，`.text` 段超过 0x2000 时需手动调整 |
| genBinOp 子函数问题 | 子函数中调用 `GetProcessHeap`/`HeapAlloc` IAT 会 crash（预存），`"$var"` 通过 genVarDecl 拦截绕过 |

---

## 开发约定

### 新增语法特性的步骤

1. **TokenType**：在 `src/Token.php` 的 `TokenType` 枚举中添加
2. **Lexer**：在 `src/Lexer.php` 添加词法规则
3. **AST**：在 `src/AST/` 对应文件中添加节点类（Decl/Stmt/Expr 按类别）
4. **Parser**：在 `src/Parser.php` 添加语法规则
5. **CodeGenerator + CodeGeneratorWindows**：分别在对应的 trait 或核心类中添加代码生成逻辑
6. **测试**：在 `test/` 目录添加测试用例

### 枚举开发注意事项

- **解析**：`parseEnumDeclaration()` 在 Parser 中处理 `enum Name: int/string { case A = v; }`
- **合并**：`mergePrograms` 中枚举从非 Main 命名空间添加 FQN 前缀
- **初始化**：`generate()` 中构建 `$this->enums[FQN] = [caseName => value]`
- **访问**：`Identifier` + `::` → `EnumAccessNode`；`genEnumAccess` 编译期解析值
- **参数**：`ParamNode.typeName` 存储原始类型名；`generateFunctionBody2` 中检查是否为枚举 → 存储 `enumName`
- **var_dump**：`printEnumCase()` 运行时比较值 → 输出 `(enum) Name::Case` 格式

### FFI 开发注意事项

- DLL 路径和函数名字符串必须在栈上构建（`sub rsp + mov byte [rsp+i]`），不能使用 `addString`/`rel32`（Windows 下 resolveStringPatches 有问题）
- LoadLibraryA 和 GetProcAddress 必须在 IAT 列表中排在前面（低 RVA 槽位工作正常）
- 每次 API 调用需要独立的 `sub rsp, 0x28`/`add rsp, 0x28` shadow space

---

## 测试

```bash
# 基础功能
php tphp.php test/main/main.php && ./main.exe
php tphp.php test/main/function.php && ./function.exe
php tphp.php test/var/var.php && ./var.exe

# 多文件 / 命名空间 / OOP
php tphp.php test/files/main.php test/files/demo.php test/files/name.php test/files/other/other.php test/files/other2.php && ./main.exe

# 枚举
cd test/emun && php ../../tphp.php . && ./main.exe

# FFI
cd test/ext && php ../../tphp.php main.php -lib libhello.dll && ./main.exe
```

---

## 关键常量速查

| 常量 | 值 | 文件 |
|------|----|------|
| `MAX_ARRAY_CAP` | 64 | `CodeGen/BaseGenerator.php`（共享） |
| `IAT_RVA` | 0x3070 | `PEWriter` (`RDATA_RVA + IAT_OFFSET`) |
| `IAT_BASE_RVA` | 0x3070 | `CodeGeneratorWindows` |
| `IAT_HEAPFREE` | 0x30B0 | `CodeGeneratorWindows`（已定义，但未加入 IAT 表） |
| `IMAGE_BASE` | 0x140000000 | `PEWriter` |
| `TEXT_RVA` | 0x1000 | `PEWriter` |
| `RDATA_RVA` | 0x3000 | `PEWriter`（从 0x2000 上调，避免 .text > 0x1000 时重叠） |
| `IAT_OFFSET` | 0x70 | `PEWriter` |
| `SECTION_ALIGN` | 0x1000 | `PEWriter` |
| `FILE_ALIGN` | 0x200 | `PEWriter` |
