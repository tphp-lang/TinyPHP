# TinyPHP 优化待办清单

> 基于 2026-07-08 项目全量分析整理。按优先级分层，每项含位置/影响/建议方案。
> 完成后请将对应条目标记为 ✅ 并注明 commit hash，不要直接删除条目。

---

## P0 — 影响正确性/稳定性

### ✅ P0-1. `SymbolTable::reset()` 漏清作用域状态

- **位置**: [src/SymbolTable.php](file:///c:/project/php/TinyPHP/src/SymbolTable.php) `reset()` 方法
- **影响**: `reset()` 清理 classes/nameMap/enums/consts/funcs/closureSigs/varClosureMap/scopeObjects，但**未清理** `scopeStrings`/`scopeArrays`/`returnedVars`。跨文件 `generate()` 调用间作用域字符串/数组状态泄漏，可能导致错误的自动释放或变量误标记。
- **方案**: 在 `reset()` 末尾补上 `$this->scopeStrings = []; $this->scopeArrays = []; $this->returnedVars = [];`
- **状态**: ✅ 已完成（2026-07-08）

### ✅ P0-2. pcntl/posix Windows 路径用 `exit(1)` 而非 `tp_throw`

- **位置**: [ext/pcntl/src/pcntl.c](file:///c:/project/php/TinyPHP/ext/pcntl/src/pcntl.c)、[ext/posix/src/posix.c](file:///c:/project/php/TinyPHP/ext/posix/src/posix.c) 的 `PCNTL_ERR`/`POSIX_ERR` 宏
- **影响**: 与项目约定不一致（[include/phpc.h](file:///c:/project/php/TinyPHP/include/phpc.h) 已确立 `tp_throw` 可被 try-catch 捕获的规范）。Windows 调用直接 `exit(1)` 无法被业务层处理。
- **方案**: 将 `PCNTL_ERR`/`POSIX_ERR` 宏改为调用 `tp_throw("...not available on Windows")`，函数返回类型默认值（int 返回 0，string 返回空 t_string 等）。
- **状态**: ✅ 已完成（2026-07-08）

### ✅ P0-3. pcre 无 backtrack limit（ReDoS 风险）

- **位置**: [ext/pcre/src/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/src/pcre.c) `tp_vm_match` 函数
- **影响**: `PREG_BACKTRACK_LIMIT_ERROR` 常量已定义但未使用。恶意模式（如 `(a+)+$`）可导致指数级回溯，阻塞进程。
- **方案**: 在 `tp_vm_match` 增加 `int backtrack_count` 计数器，超限（`TP_BACKTRACK_LIMIT=1000000`）设置 `m->backtrack_limit_exceeded` 标志并返回 -1。`tp_find_from` 检测标志后提前退出。5 个 preg_* 函数检测标志后设置 `g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR`。
- **状态**: ✅ 已完成（2026-07-08）

### ✅ P0-4. 完成 SymbolTable 迁移，删除 16 个 legacy 数组

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php)、[src/SymbolTable.php](file:///c:/project/php/TinyPHP/src/SymbolTable.php)
- **影响**: `classPropTypes`/`classOwnProps`/`classParentName`/`classMethodRetTypes`/`classNames`/`constTypes`/`constVis`/`enumBackingTypes`/`enumCTypes`/`methodParamTypes`/`funcRetTypes`/`funcParamTypes`/`funcDefaultCounts`/`funcIsGenerator`/`closureSigs`/`varClosureMap` 共 16 个数组仍作 write-back，读取走 SymbolTable。注释明确"待全部 READ 迁移完成后删除"。技术债，维护成本高。
- **方案**: 分 4 批迁移（简单类/func类/enum类/closure类），每批先补写入镜像再替换读取。迁移中发现并修复 14 处遗漏读取（classMethodRetTypes 10处、constTypes 2处、closureSigs/varClosureMap 各 1 处 write-then-read）。SymbolTable 扩展：FunctionInfo 加 `isGenerator` 字段、新增 `allEnums()` 方法。最终删除全部 16 个数组声明 + 43 处 write-back 写入。
- **状态**: ✅ 已完成（2026-07-08），99/99 测试通过

---

## P1 — 影响开发体验（全部完成 ✅）

### ✅ P1-1. `visitCall` 740 行硬编码分发

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `visitCall` 方法
- **影响**: 所有内置函数分发写死在巨型 if-else 链中，新增函数需改这一处，脆弱且难维护。
- **方案**: 抽取策略表 `self::$builtinCallHandlers`（函数名 → 处理器闭包），每个内置函数独立处理器方法。`visitCall` 主流程只做查表分发。
- **状态**: ✅ 已完成（2026-07-08）。P1-1a 清理死代码（is_numeric/合并 phpcFns）；P1-1b 抽取 55+ 简单转发函数到 `$simpleFnMap` + `generateSimpleForward()` 通用处理器。

### ✅ P1-2. `inferCallReturnType` 注册表化补全

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `inferCallReturnType`（~line 1701）
- **影响**: 未注册的 C-only `tphp_fn_*` 函数默认 fallback 为 `t_int`，返回指针会被静默截断（project_memory 已记录此 bug 模式）。
- **方案**:
  1. 已有 `self::$builtinRetTypes`（~140 条目）作为注册表基础
  2. 新增 C-only 函数时强制在注册表登记返回类型
  3. 默认 fallback 改为**编译错误**（"Unknown function return type: foo, please register in builtinRetTypes"）而非静默 `t_int`
- **状态**: ✅ 已完成（2026-07-08）。未注册函数抛 `LogicException` 编译错误。

### ✅ P1-3. Lexer `scanNumber` 不支持 hex/binary/科学计数

- **位置**: [src/Lexer.php](file:///c:/project/php/TinyPHP/src/Lexer.php) `scanNumber` 方法
- **影响**: `0x1F` / `0b101` / `1e10` / `1_000` 全部解析失败，与 PHP 不兼容。
- **方案**: `scanNumber` 增加前缀分支：
  - `0x`/`0X` → 十六进制（`[0-9a-fA-F]` + 可选 `_` 分隔）
  - `0b`/`0B` → 二进制（`[01]` + 可选 `_`）
  - `0o`/`0O` → 八进制（PHP 8.1+）
  - 数字中允许 `_` 分隔（移除后转换）
  - `e`/`E` 后接可选 `+-` + 数字 → 科学计数（float）
- **状态**: ✅ 已完成（2026-07-08）。重构为分发器 + 4 个专用扫描器，测试 `test/lexer/number_literals.php`（13 tests）。

### ✅ P1-4. 抽离 `_mk_str` 公共头

- **位置**: [ext/pcntl/pcntl.c](file:///c:/project/php/TinyPHP/ext/pcntl/pcntl.c)、[ext/posix/posix.c](file:///c:/project/php/TinyPHP/ext/posix/posix.c)、[ext/pcre/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/pcre.c)
- **影响**: `_mk_str` 辅助函数（SSO ≤23B 内联 / 否则 str_pool_alloc）在 3 个扩展重复定义，目前各扩展独立 TU 尚可，但无法在同一编译单元共存。
- **方案**: 新建 [ext/common/ext_str.h](file:///c:/project/php/TinyPHP/ext/common/ext_str.h)，定义 `static inline t_string _mk_str(const char* s, int len)`，3 个扩展改为 `#include` 该头。
- **状态**: ✅ 已完成（2026-07-08）。公共头位于 [include/ext_str.h](file:///c:/project/php/TinyPHP/include/ext_str.h)（`ext_mk_str`/`ext_mk_substr`），3 个扩展通过宏映射引用。

### ✅ P1-5. core.h 与 std/ 独立文件内容重复

- **位置**: [include/std/core.h](file:///c:/project/php/TinyPHP/include/std/core.h) vs `output.h`/`type.h`/`string.h`/`array_core.h`/`array_extra.h`
- **影响**: core.h 是合并版（TCC 单 TU 友好），独立文件供其他编译器按需引入。改一处易漏另一处，维护需双向同步。
- **方案**:
  - 方案 A（推荐）：core.h 改为 `#include` 各独立文件，保留单 TU 入口语义
  - 方案 B：写生成脚本从 core.h 拆分到独立文件
  - 方案 C：只保留 core.h，删除独立文件（牺牲 GCC/Clang 按需引入能力）
- **状态**: ✅ 已完成（2026-07-08）。采用方案 C，删除 4 个孤儿文件（output.h/type.h/string.h/array_core.h），core.h 是更新版本。

### ✅ P1-6. 默认值仅支持 `parsePrimary`（字面量）

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) 参数默认值解析处
- **影响**: `function foo(int $x = 1 + 2)` 或 `= new Foo()` 失败。PHP 8.2+ 允许表达式默认值。
- **方案**: 默认值解析改用 `parseExpr`（或限制为 `parseAdditive` 避免复杂表达式），CodeGenerator 在重载函数中生成对应 C 表达式。
- **状态**: ✅ 已完成（2026-07-08）。`parseParam`/`parsePropertyDecl` 改用 `parseExpr`；`MethodInfo` 添加 `defaultCount`/`totalParams` 字段；方法调用路径添加重载版本选择；`emitClassForward` 补充方法重载前置声明。测试 `test/default_params/test_default_expr.php`（10 tests）。

---

## P2 — 功能扩展

### ✅ P2-1. Generator 支持方法/闭包

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) ~line 1075（方法抛错）、~line 2069（闭包抛错）
- **影响**: 当前仅独立函数支持 Generator，方法和闭包显式抛 `"Generator methods/closures not yet supported"`。
- **方案**:
  1. 方法：entry 函数需传递 `self` 指针到参数 struct，`mco_get_user_data` 解包后调用
  2. 闭包：use vars 与参数一起打包到 struct，entry 函数解包后重建闭包环境
  3. 注意 `this` 指针的 refcount 管理（entry 内 retain，wrapper 返回前 release）
- **状态**: ✅ 已完成（2026-07-09）。新增 `emitGeneratorMethod`（模式与 `emitGeneratorFunction` 一致，params struct 含 `self` 指针作为首字段，包装方法签名与普通方法一致返回 `tphp_class_Generator*`）和 `emitGeneratorClosure`（use vars + params 打包进 generator params struct，包装函数签名与普通闭包一致接收 `void* _env`，返回 `t_callback`）。visitMethod/visitClosure 中的 throw 替换为分发调用。修复返回类型注册：方法第一遍预扫描（line 659）和 enum 方法注册（line 3895）对 `isGenerator` 使用 `tphp_class_Generator*`；`generateMethodOverloads`/`inferCallbackSig` 同步修复。修复 params struct typedef 段位置：从 SEC_FWDDECLS 移至 SEC_CLSIMPL（方法）/SEC_CLOSURES（闭包），确保在类结构体 typedef 定义之后。移除 enum 生成器方法的 throw（enum 方法经 `emitEnumImpl` → `visitMethod` 统一路径）。`self` 指针借用调用方引用（与独立生成器函数的对象参数一致，不做额外 retain/release——已知限制：generator 被放弃时 entry 尾部释放不执行，与字符串/数组一致）。测试 `test/object/gen_method.php`（基本生成器方法/访问 $this->property/带参数生成器方法/genRange while 循环）+ `test/object/gen_closure.php`（无捕获/带 use 捕获/带参数+捕获），109/109 通过。

### ✅ P2-2. enum 支持方法/常量/`implements`

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) `parseEnumDecl`、CodeGenerator enum 发射逻辑
- **影响**: PHP 8.1 enum 的 `Color::cases()` / `Color::from(int)` / `Color::tryFrom(int)` / enum 方法 / `implements` 接口约束全部缺失。
- **方案**:
  1. Parser: enum body 允许 `case` + `method` + `const` + `implements`
  2. CodeGenerator: 为每个 enum 自动生成 `cases()`/`from()`/`tryFrom()` 静态方法
  3. enum 实现 interface 时，方法走正常 vtable（与 class 一致）
- **状态**: ✅ 已完成（2026-07-09）。EnumNode AST 扩展 `methods`/`classConsts`/`implements` 字段；parseEnumDecl body 循环接受 case/method/const + implements 解析；主声明循环新增 enum 分支允许与 class/interface 交错声明；parsePrimary 中 `Color::method(` 走 CallExpr、`Color::CASE` 走 EnumAccessExpr。SymbolTable enum 条目扩展 cases/consts/methods + 双向查找（枚举名 ↔ C 结构体名 via `resolveEnumCName`）。CodeGenerator 两阶段发射：visitEnum (SEC_ENUMS: struct + 静态实例 + `#define TPHP_CONST_<CN>_<NAME>` + 方法前置声明 + 注册 cases/consts/methods 到 SymbolTable) + emitEnumImpl (SEC_CLSIMPL: 用户实例方法实现 + 自动 cases() 返回 `t_array*`、from() 线性查找找不到 `tp_throw`、tryFrom() 找不到返回 NULL)。emitEnumMethodCall 处理静态/实例方法调用 + 重载版本选择；inferCallReturnType/inferCallChainClass/inferType 支持 enum 静态调用 (callee=VariableExpr) 和实例调用 (callee=EnumAccessExpr)；castToStr 处理 enum 方法返回值、enum 常量按声明类型转换、TernaryExpr。implements 仅记录不强制 vtable（与 PHP enum 接口约束语义一致，方法直接定义在 enum struct 上）。测试 `test/object/enum_methods.php`（用户实例方法访问 $this->value/$this->name、枚举常量、cases() count、from/tryFrom with null 检查 if/else、链式 Color::from(1)->label()、string backing Direction），107/107 通过。

### ✅ P2-3. pcre 支持 lookahead（无 lookbehind）

- **位置**: [ext/pcre/src/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/src/pcre.c) 编译器与 VM
- **影响**: `(?=...)` / `(?!...)` 是 PHP PCRE2 常用特性，当前缺失。lookbehind 需要变长回溯，复杂度高，暂不做。
- **方案**:
  1. 编译器：`(?=expr)` 编译为 `SPLIT L1, L2; L1: <expr>; ASSERT_SUCCESS; L2:`，VM 在 L1 失败时整体失败
  2. `(?!expr)` 类似，但 L1 成功时整体失败
  3. 注意 lookahead 内的 group captures 语义（PHP 保留 lookahead 内捕获）
- **状态**: ✅ 已完成（2026-07-09）。新增 3 个 opcode（`TP_LOOK_START`/`TP_LOOK_END`/`TP_LOOK_FAIL`）和 1 个 AST 节点（`TP_NODE_LOOKAHEAD`）。parser 在 `(?` 分支新增 `=`/`!` 检测，解析后复用 group body 解析。tp_machine 新增 `look_sp[16]`/`look_off[16]`/`look_ptr` 用于保存 lookahead 入口的 sp 和 stack_ptr 检查点。编译布局：`TP_LOOK_START → TP_LOOK_FAIL → <body> → TP_LOOK_END → L_after`，START 压回溯帧（pc=FAIL），body 成功时 END 恢复 sp 并丢弃 body 内部帧（零宽+不回溯入 body），body 失败时回溯到 FAIL 处理。正/负向通过 `inverted` 标志区分。优化器已更新，对 3 个新 opcode 的 target_x/target_y 正确标记和重映射。`tp_vm_match` 入口重置 `look_ptr=0`。支持正/负向、嵌套（max 16）、alternation body、lookahead 内捕获保留（positive）。测试 `test/pcre/test_lookahead.php`（8 组场景），106/106 通过。

### ✅ P2-4. 链式赋值 `$a = $b = 1`

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) parseStmt、[src/AST/Node.php](file:///c:/project/php/TinyPHP/src/AST/Node.php)
- **影响**: 赋值是 StmtNode 不是 ExprNode，无法出现在表达式位置，`$a = $b = 1` 解析失败。
- **方案**:
  1. 新增 `AssignmentExpr` 节点（继承 ExprNode）
  2. Parser: 赋值表达式优先级低于三元，解析为 AssignmentExpr
  3. CodeGenerator: `visitAssignmentExpr` 生成 `b = 1; a = b;`（或 `a = b = 1`）
  4. 现有 AssignStmtNode 保留，作为表达式语句的语法糖
- **状态**: ✅ 已完成（2026-07-09）。采用语句级展开方案（非 ExprNode 方案）：`parseAssignStmt` 检测 `$var = $var = ...` 链式模式，递归解析后展开为 `BlockStmtNode([inner, outer])`。新增 `BlockStmtNode`（语句序列包装）+ `visitBlockStmt`。语义：`$a = $b = 1` → `$b = 1; $a = $b;`（值语义，与 PHP 一致）。支持 N 级链式（`$a = $b = $c = ...`）和类型标记（`int $a = $b = 42`，类型仅作用于首个变量）。测试 `test/math/chain_assign.php`（2-way int / 3-way string / typed / 表达式 RHS），105/105 通过。

### ✅ P2-5. `hash_hmac` 支持

- **位置**: [include/hash.h](file:///c:/project/php/TinyPHP/include/hash.h)
- **影响**: PHP 常用的 `hash_hmac('sha256', $data, $key)` 缺失，JWT/Webhook 签名场景必需。
- **方案**: 实现 HMAC RFC 2104（`H(K XOR opad, H(K XOR ipad, text))`），复用现有 SHA-256/SHA-512 block 函数。新增 `tphp_fn_hash_hmac`/`tphp_fn_hash_hmac_algos`。
- **状态**: ✅ 已完成（2026-07-09）。`tphp_fn_hash_hmac(t_string algo, t_string data, t_string key, t_bool binary)` 实现于 hash.h 末尾，支持 sha256/sha512，binary=true 返回原始摘要否则小写 hex。CodeGenerator `$simpleFnMap` 注册（4 参，binary 默认 false）+ `$builtinRetTypes` 注册 `hash_hmac => t_string`。修复 `_sha512_update` 长度双重计数 bug（循环内 `len[0]+=128` 与循环后 `len[0]+=len` 重复，仅对 ≥128 字节输入触发；改为循环前一次性累加，与 `_sha256_update` 一致）。测试 `test/builtin/hash_hmac_test.php`（RFC 4231 Test Case 2 向量 + binary 长度 + 不支持算法空串），104/104 通过。`hash_hmac_algos` 暂未实现（返回 array 需额外基础设施，核心场景仅需 hash_hmac）。

### ✅ P2-6. `__FUNCTION__` / `__NAMESPACE__` 魔术常量

- **位置**: [src/Lexer.php](file:///c:/project/php/TinyPHP/src/Lexer.php) Token 识别、[src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `visitMagicConst`
- **影响**: `__FUNCTION__` 标注 ⬜，`__NAMESPACE__` 未实现。调试与日志场景常用。
- **方案**:
  1. Lexer: 新增 `T_FUNCTION_MAGIC`/`T_NAMESPACE_MAGIC` token
  2. CodeGenerator: `visitMagicConst` 根据 `currentClassName`/`currentMethodName`/`currentNamespace` 生成字符串字面量
- **状态**: ✅ 已完成（2026-07-09）。TokenType 加 `MAGIC_FUNCTION`/`MAGIC_NAMESPACE`；Lexer 关键字表注册；Parser `$magicTokens` 数组补全；CodeGenerator 加 `currentFuncName`/`inMethod`/`currentNamespace` 字段并在 `emitClassImpl`/`visitMethod`/`visitFunction` 设置上下文。PHP 语义对齐：方法内 `__FUNCTION__`=方法名、`__METHOD__`=Class::method；全局函数内两者均=函数名；`__NAMESPACE__` 返回 PHP 命名空间名。测试 `test/object/magic_func_ns.php`（全局命名空间）+ `test/object/magic_ns_main.php`+`magic_ns_lib.php`（Demo\Sub 命名空间），103/103 通过。

---

## P3 — 性能与细节优化

### ✅ P3-1. `array_diff`/`array_intersect` 改哈希集优化

- **位置**: [include/std/array_extra.h](file:///c:/project/php/TinyPHP/include/std/array_extra.h)
- **影响**: 当前双重循环 O(n×m)，大数组慢。
- **方案**: 第二个数组建哈希集（khash），第一个数组遍历查找，降到 O(n+m)。
- **状态**: ✅ 已完成（2026-07-09）。阈值分治：`a2->length < 16` 走原双循环（缓存友好，小数组无哈希开销），`≥ 16` 走哈希集 O(n+m)。哈希集利用现有 `str_index`（string-keyed O(1) 查找）：INT 值 snprintf 转十进制字符串后存入 `intSet`，STRING 值直接存入 `strSet`，类型分离保持原语义（INT 1 ≠ STRING "1"）。抽出 `_arr_diff_build_sets`/`_arr_diff_lookup` 共享辅助函数。测试 `test/array/test_diff_intersect.php`（6 场景：小数组 diff、大数组 diff、小数组 intersect、大数组 intersect、INT/STRING 类型分离、大字符串数组 diff）。112/112 测试通过。

### ✅ P3-2. 整数键数组可选哈希索引

- **位置**: [include/array.h](file:///c:/project/php/TinyPHP/include/array.h)、[include/types.h](file:///c:/project/php/TinyPHP/include/types.h)
- **影响**: 当前仅字符串键触发 arr_stridx，大整数键数组（如 ID→对象映射）查询仍是 O(n)。
- **方案**: 新增 `arr_intidx`（hash→entry_index，key 为 int64），阈值同样 8。触发条件：数组键全部为 int 且长度 ≥8。
- **注意**: 仅稀疏整数键（非 0,1,2... 连续）才需要，连续整数键直接 `entries[i]` 即可。
- **状态**: ✅ 已完成（2026-07-09）。`struct _t_array` 新增 `void *int_index` 字段（parallel to `str_index`）。新增 `arr_intidx` 结构 + 6 个函数（free/build/ensure/lookup/insert/delete），mirror `arr_stridx` 布局（open-addressing，slot=0 empty/-1 tomb/>0 entry_idx+1），哈希用 splitmix64 finalizer（`_arr_int_hash`）。`tphp_fn_arr_get_int`/`tphp_fn_arr_set_int` 三级路径：(1) 连续键直接下标 `entries[key]` O(1)（检查 `entries[key].key._int == key`），(2) 哈希索引 O(1)（≥8 惰性建/查），(3) 小数组线性扫描 O(n)。所有位置变更操作（pop/shift/unshift/shuffle/sort/rsort/asort/arsort/ksort/krsort）添加 `arr_intidx_free(a)`；pop 额外 `arr_intidx_delete` 弹出的 int 键；`arr_pool_put` 释放两套索引。测试 `test/array/test_int_index.php`（11 场景：连续键 get/set、小数组稀疏键、大数组构建/查找/覆盖/插入、pop/shift/sort 索引失效+重建、混合 int/string 键）。113/113 测试通过。

### ✅ P3-3. `resolveMethodClass` 缓存

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `resolveMethodClass`
- **影响**: 线性扫描父类链 O(depth) per call，无缓存，热路径性能隐患。
- **方案**: 新增 `self::$methodClassCache[cname][methodName] = resolvedClass`，编译期一次性建立。
- **状态**: ✅ 已完成（2026-07-09）。加 `private array $methodClassCache` 字段（key=`cn\0method`），`resetState()` 中清空。命中直接返回缓存值，未命中走原线性扫描后写入缓存。101/101 测试通过。

### ⏸️ P3-4. 类型推断缓存（评估后搁置）

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `inferType`
- **影响**: 每次 `inferType` 都重走 AST，大型项目编译慢。
- **方案**: 给 ExprNode 加 `inferredType` 字段，首次推断后缓存。注意 AST 节点可能在多函数中共享（闭包），需验证缓存失效场景。
- **状态**: ⏸️ 评估后搁置（2026-07-09）。经分析 61 处 `inferType` 调用，大多在 visitor 方法中每节点调用一次，收益低。`inferType` 强依赖可变作用域状态（`$this->varTypes`、`$this->arrValueTypes`、`$this->arrElementTypes`、`$this->arrNestedTypes`、`$this->className`），进入不同方法/函数时这些状态会变化，缓存失效逻辑复杂且风险高。AST 节点在闭包 use vars 捕获等场景可能跨作用域引用，一旦缓存未失效会导致隐蔽的类型推断 bug。属过早优化，编译器已足够快。

### ✅ P3-5. pcre `tp_cache` key 长度限制

- **位置**: [ext/pcre/src/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/src/pcre.c) `tp_cache` 结构
- **影响**: `key[256]` 截断长模式（>255 字节无法缓存）。
- **方案**: 改为 `char* key` + 动态分配，LRU 淘汰时 `free`。或改用哈希值作为 key（碰撞时回退到线性扫描）。
- **状态**: ✅ 已完成（2026-07-09）。`tp_cache_entry.key` 从 `char key[256]` 改为 `char* key`（malloc 动态分配）。修复前 `key_len` 被截断为 255，导致长模式缓存 lookup 时 `key_len == key_len` 永远为 false，长模式每次重新编译（性能损失）。修复后完整 key_len 存储，长模式可正常命中缓存。LRU 淘汰路径增加 `free(tp_cache[slot].key)`；lookup 增加 `key != NULL` 守卫防止 malloc 失败时 memcmp(NULL) UB。测试 `test/pcre/test_long_pattern.php`（4 场景：300 字节字面量匹配、第二个不同长模式、重复命中缓存、共享前缀的不同长模式不冲突）。111/111 测试通过。

### ✅ P3-6. pcre `preg_replace` 反向引用性能

- **位置**: [ext/pcre/src/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/src/pcre.c) `preg_replace`
- **影响**: `$N` 反向引用重跑 `tp_find_from` 获取 captures，性能 O(n×matches)。
- **方案**: 替换前一次性收集所有 captures，存入 `captures[N][start,end]` 数组，替换时直接查表。降到 O(n+matches)。
- **状态**: ✅ 已完成（2026-07-09）。首次匹配循环中增加 `match_caps` 数组（`match_count × cap_size`），每次 `tp_find_from` 后 `memcpy` 保存完整 captures。替换阶段直接查表 `caps = match_caps + mi * cap_sz`，`caps[g*2]`/`caps[g*2+1]` 获取 group 边界，消除每个 `$N` 反向引用的 `tp_find_from` 重跑 + `tp_machine` 分配/释放开销。复杂度从 O(n×matches×backrefs) 降到 O(n+matches)。无分组模式（`cap_size=0`）跳过 captures 分配。所有 `free` 路径（正常/early-return/match_count=0）均补齐 `free(match_caps)`。112/112 测试通过，含现有 `backref=World!` 回归测试。

### ⏸️ P3-7. static buf 线程安全（暂缓）

- **位置**: [include/conv.h](file:///c:/project/php/TinyPHP/include/conv.h) `decbin/decoct/dechex`、[ext/posix/posix.c](file:///c:/project/php/TinyPHP/ext/posix/posix.c) `getcwd`
- **影响**: 使用 static buf 非线程安全。当前 TinyPHP 无多线程，但未来扩展可能引入。
- **方案**: 改为调用方传入缓冲区，或返回 t_string（SSO + str_pool_alloc）。
- **状态**: ⏸️ 暂缓（2026-07-09）。当前 TinyPHP 运行时单线程，无实际收益。修改会改变公共 API（调用方需传缓冲区或处理 t_string 返回），连锁影响大，待多线程支持落地后再评估。

### ✅ P3-8. `try.h` msg_buf 固定长度

- **位置**: [include/object/try.h](file:///c:/project/php/TinyPHP/include/object/try.h) `tp_ex_frame.msg_buf[256]`
- **影响**: 长异常消息（>255 字节）截断。
- **方案**: 改为 `char* msg` + 动态分配（str_pool_alloc），或用 `t_string` 字段。注意异常 frame 在栈上，需避免悬垂指针。
- **状态**: ✅ 已完成（2026-07-09）。`tp_ex_frame` 字段从 `char msg_buf[256]` 改为 `char* msg`（malloc 动态分配）。关键约束：`tphp_rt_free_all()` 在 `longjmp` 前调用，str_pool 内存会被释放，因此必须用 `malloc`（非 `str_pool_alloc`）分配 msg，使其在 catch 处理器运行时仍有效。新增 `_tp_dup_msg`/`_tp_dup_msg_n` 辅助函数；TP_CATCH/TP_CATCH_EX/TP_CATCH_ANY/TP_END_TRY 各自在使用后 `free(_tp_f.msg)`；TP_END_TRY re-throw 路径用 `_tp_dup_msg` 复制到父帧。附带修复 `tp_throw` 宏参数名与结构体字段 `msg` 的命名冲突（改名为 `_tp_msg`）；CodeGenerator 的 `throw $strVar` 生成从 `.data` 改为 `STR_PTR_V()`（正确处理 SSO + 避免 TCC 匿名 union 兼容性问题）。测试 `test/error/long_message.php`（4 场景：Exception 长消息、短消息回归、纯字符串长消息、嵌套 re-throw）。110/110 测试通过。

### ✅ P3-9. `parsePrimary` 巨型 if-else 重构

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) ~line 1601
- **影响**: 检查 ~25 个 TokenType 的巨型 if-else，新增 token 易遗漏，脆弱。
- **方案**: 改为 `match ($token->type)`（PHP 8 match）或分发表 `self::$primaryHandlers`。
- **状态**: ✅ 已完成（2026-07-09）。两项重构：(1) 提取 23 个标识符类 token（IDENTIFIER/SELF_KW/VAR_DUMP/COUNT/EXIT/...IS_CALLABLE）到 `self::$identifierLikeTokens` 静态集，替代原 22 个 `||` 的巨型 `check()` 链；(2) 15 个简单情况（STRING_LIT/INT_LIT/FLOAT_LIT/TRUE_KW/FALSE_KW/NULL_KW/PARENT_KW + 8 个魔术常量 + YIELD_KW/FN_KW/FUNCTION/LBRACKET/NEW_KW/MATCH）从 if-else 改为 `match ($tok)` 分发，`default => null` 穿透到复杂情况（LPAREN 转换/分组、标识符类方法/属性/数组访问）。80 行 if-else 缩减为 ~25 行 match + 简化 if-else。新增 token 只需在 match 或静态集中添加一行。112/112 测试通过，纯重构零行为变更。

### ✅ P3-10. `blockHasReturn()` 浅扫描

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) `blockHasReturn`
- **影响**: 仅检查顶层 return，不递归 if/for，可能误判函数是否返回（导致 non-void 函数漏 return 检查失效）。
- **方案**: 递归检查 if/else/for/while/try 的所有分支是否都有 return（PHP 语义）。
- **状态**: ✅ 已完成（2026-07-09）。`blockHasReturn` 从浅扫描改为递归搜索：进入 `IfStmtNode`（thenBody/elseifs/elseBody）、`WhileStmtNode`/`DoWhileStmtNode`（body）、`ForStmtNode`/`ForeachStmtNode`（body）、`SwitchStmtNode`（cases[].body）、`TryStmtNode`（tryBody/catchClauses/finallyBody）、`BlockStmtNode`（stmts）查找 `ReturnStmtNode`。语义保持"包含至少一个 return"（匹配错误消息 "must contain a return statement"），非"所有路径都 return"。修复前：箭头函数 `fn(): int => { if ($x) { return 1; } else { return 2; } }` 因无顶层 return 被误报错误。测试在 `test/type/arrow_block.php` 新增 "Arrow fn block with nested return" 场景（if/else 内 return + for 循环内 return）。112/112 测试通过。

---

## P4 — 多线程支持（规划中，未开始实施）

> 基于 2026-07-09 对 klib / vlang / tinycthread 三个线程库的对比分析。
> 目标：为 TinyPHP 提供 `tphp_class_Thread` / `tphp_class_Mutex` / `tphp_class_CondVar` 的 OOP 线程 API。
> 核心约束：TCC 兼容、零运行时依赖、COS 封装风格、4 平台覆盖（Win x64 / Linux x64+aarch64 / macOS aarch64）。

### P4-0. 三个线程库对比分析（选型决策记录）

#### 候选库对比

| 维度 | klib (kt_for) | vlang (sync) | tinycthread |
|------|--------------|--------------|-------------|
| **设计目标** | 数据并行计算 | 语言级并发（spawn/lock/shared/chan） | C11 threads API 跨平台封装 |
| **代码量** | ~260 行 C | 数千行 V + 1800 行 C 头 | ~590 行 C（1 头 + 1 实现） |
| **API 模型** | `kt_for`/`kt_pipeline`（函数指针） | `spawn`/`shared`/`chan`（编译器代码生成） | `thrd_create`/`mtx_t`/`cnd_t`/`tss_t`（C11 子集） |
| **平台抽象** | 无（纯 POSIX） | 5+ 平台分支 | `_TTHREAD_WIN32_` vs `_TTHREAD_POSIX_`（仅 2 分支） |
| **原子操作** | `__sync_fetch_and_add`（TCC 不支持 ❌） | 完整 stdatomic 兼容层（FFmpeg 衍生） | 不使用原子操作 ✅ |
| **运行时依赖** | 零（仅 libc） | 重（V GC/string/panic） | 零（仅 libc + pthread/Win32）✅ |
| **TCC 兼容** | ❌ `__sync_*` 不支持 | ⚠️ 需 libatomic（路径搜索复杂） | ✅ 完全兼容 |
| **License** | MIT | MIT | zlib（最宽松）✅ |

#### 选型结论

**选定 tinycthread 作为基础**，原因：
1. **TCC 兼容性最佳**：完全不用原子操作（mutex 内部由 OS 保证原子性），避免 klib 的 `__sync_*` 硬伤和 vlang 的 libatomic 路径搜索复杂度
2. **零运行时依赖**：仅 libc + pthread/Win32，不依赖任何 GC/string/panic，可直接嵌入 TinyPHP
3. **平台分支简洁**：仅 Win32/POSIX 两分支，TinyPHP 的 4 平台全覆盖
4. **C11 标准命名**：`thrd_t`/`mtx_t`/`cnd_t`/`tss_t` 与 C11 `<threads.h>` 一致，未来可平滑替换
5. **zlib License 最宽松**：允许商用、修改、重分发

**参考 vlang 的部分**：
- stdatomic 兼容头文件（`thirdparty/stdatomic/`）— 若后续需要 SpinLock（CAS 操作）可借用
- Windows SRWLOCK + CONDITION_VARIABLE 优化方案（替代 tinycthread 的 CRITICAL_SECTION + Event）
- WaitGroup 的「高32位任务数 + 低32位等待数」单 u64 state 设计

**不采用 klib 的原因**：`__sync_fetch_and_add` TCC 不支持，且数据并行模型（kt_for）不适合通用线程 API

**不采用 vlang 的原因**：运行时依赖重（V GC/string/panic），spawn/shared/lock 强绑定 V 编译器代码生成

### P4-1. tinycthread 集成 + 优化（前置任务）

- **位置**: 新建 `include/compat/tinycthread.h` + `include/compat/tinycthread.c`（或合并为单头 `tinycthread.h`）
- **来源**: [tinycthread v1.1](file:///C:/Users/28249/Desktop/tinycthread-1.1/source/tinycthread.h)（zlib license，Marcus Geelnard）
- **影响**: 为 TinyPHP 提供跨平台线程原语（thrd_t/mtx_t/cnd_t/tss_t），是 P4-2/P4-3 的基础
- **方案**: 拷贝 tinycthread 到 `include/compat/`，保留 zlib license 声明，然后修复以下不足

#### tinycthread 的已知不足 + 优化方案

| # | 不足 | 优化方案 | 优先级 |
|---|------|---------|--------|
| 1 | **Windows mutex 用 CRITICAL_SECTION**（重量级，~24 字节） | 改用 **SRWLOCK**（轻量，指针大小，Vista+ 内置） | 高 |
| 2 | **Windows 非递归 mutex 用 `Sleep(1000)` 模拟死锁检测**（[tinycthread.c:86](file:///C:/Users/28249/Desktop/tinycthread-1.1/source/tinycthread.c#L86)，有竞态） | SRWLOCK 本身非递归，移除 `mAlreadyLocked`/`mRecursive` 字段和 Sleep 循环；递归需求走单独的 `mtx_recursive` 实现（可用 CRITICAL_SECTION 保留） | 高 |
| 3 | **Windows condvar 用 2 Event + CRITICAL_SECTION 模拟**（复杂、低效） | 改用 **CONDITION_VARIABLE**（Vista+ 内置，`SleepConditionVariableSRW`） | 高 |
| 4 | **`thrd_detach` 未实现**（FIXME，返回 `thrd_error`） | POSIX: `pthread_detach`；Windows: `CloseHandle`（detach 语义） | 中 |
| 5 | **`mtx_timedlock` 未实现**（FIXME，返回 `thrd_error`） | POSIX: `pthread_mutex_timedlock`；Windows: SRWLOCK 无超时，用 `TryAcquireSRWLockExclusive` 轮询 | 低 |
| 6 | **Windows `tss_create` 不支持析构函数** | 用 `FLS`（Fiber-Local Storage，`FlsAlloc`/`FlsSetCallback`）替代 `TlsAlloc`，支持析构 | 低 |
| 7 | **Windows `thrd_create` 用 `_beginthreadex`**（需 `<process.h>`） | 可保留，或改用 `CreateThread`（更标准，但 `_beginthreadex` 对 CRT 初始化更安全） | 无需改 |
| 8 | **`thrd_sleep` 用 `usleep`/`Sleep`**（粗粒度） | POSIX 保留 `usleep`；Windows 可用 `WaitForSingleObject` 配合 `CreateWaitableTimer` 精确到 100ns | 低 |
| 9 | **`cnd_broadcast` POSIX 实现有 bug**（[tinycthread.c:226](file:///C:/Users/28249/Desktop/tinycthread-1.1/source/tinycthread.c#L226) 用 `pthread_cond_signal` 而非 `pthread_cond_broadcast`） | 改为 `pthread_cond_broadcast` | 高（正确性） |
| 10 | **无 SpinLock**（短临界区用 mutex 开销大） | 参考 vlang SpinLock（CAS + 指数退避），需引入原子操作（借用 vlang stdatomic 头文件） | 中 |
| 11 | **无 WaitGroup**（等待 N 个线程完成的常用模式） | 参考 vlang WaitGroup（单 u64 state：高32位任务数 + 低32位等待数 + Semaphore） | 中 |
| 12 | **Windows 平台检测用 `_WIN32`/`__WIN32__`/`__WINDOWS__`** | 与 TinyPHP 现有平台检测宏统一（`_WIN32`） | 低 |

#### 优化后的预期 API

```c
// Thread（补 thrd_detach 实现）
int thrd_create(thrd_t *thr, thrd_start_t func, void *arg);
int thrd_detach(thrd_t thr);          // 补实现
int thrd_join(thrd_t thr, int *res);
thrd_t thrd_current(void);
int thrd_equal(thrd_t thr0, thrd_t thr1);
void thrd_exit(int res);
void thrd_yield(void);
int thrd_sleep(const struct timespec *time_point, struct timespec *remaining);

// Mutex（Windows 改 SRWLOCK，保留 recursive 选项走 CRITICAL_SECTION）
int mtx_init(mtx_t *mtx, int type);   // type: mtx_plain | mtx_timed | mtx_try | mtx_recursive
int mtx_lock(mtx_t *mtx);
int mtx_trylock(mtx_t *mtx);
int mtx_unlock(mtx_t *mtx);
void mtx_destroy(mtx_t *mtx);

// CondVar（Windows 改 CONDITION_VARIABLE）
int cnd_init(cnd_t *cond);
int cnd_wait(cnd_t *cond, mtx_t *mtx);
int cnd_timedwait(cnd_t *cond, mtx_t *mtx, const struct timespec *ts);
int cnd_signal(cnd_t *cond);
int cnd_broadcast(cnd_t *cond);       // 修复 POSIX 的 pthread_cond_signal bug
void cnd_destroy(cnd_t *cond);

// TLS（Windows 可选改 FLS 支持析构）
int tss_create(tss_t *key, tss_dtor_t dtor);
void tss_delete(tss_t key);
void *tss_get(tss_t key);
int tss_set(tss_t key, void *val);
```

### P4-2. 运行时线程安全改造（前置任务）

- **位置**: [include/runtime.h](file:///c:/project/php/TinyPHP/include/runtime.h)、[include/array.h](file:///c:/project/php/TinyPHP/include/array.h)、[include/object/object.h](file:///c:/project/php/TinyPHP/include/object/object.h)、[ext/pcre/src/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/src/pcre.c)
- **影响**: 引入多线程后，以下全局状态非线程安全，会导致堆损坏/双重释放/竞态
- **方案**: 两种策略二选一（见下），建议先 A 后 B 渐进实施

#### 需要改造的全局状态

| 全局状态 | 位置 | 风险 | 改造方案 |
|---------|------|------|---------|
| `str_pool`（128KB Arena） | runtime.h | 多线程同时分配 → 堆损坏 | mutex 包装或 thread-local |
| `arr_freelist`（128 槽池） | array.h | 同时回收 → 双重释放 | mutex 或 thread-local |
| `obj_freelist`（128 槽池） | object.h | 同上 | 同上 |
| `tphp_rt_registry`（GC 表） | runtime.h | 同时注册 → 竞态 | mutex |
| `tp_cache`（pcre 编译缓存） | pcre.c | 同时编译 → 重复编译 | mutex 或 double-checked |

#### 策略 A：Thread-Local 运行时（推荐先行）

- 每个线程独立 str_pool/arr_pool/obj_pool
- 线程间只能传递**值类型**（int/float/string 的只读指针）
- 优点：无锁，性能好
- 缺点：线程无法直接操作主线程的对象/数组，需通过 Mutex/Channel 显式同步
- 适用：Thread 函数限制为纯计算（策略 A 限制模式）

#### 策略 B：全局 mutex（简单但慢）

- 所有运行时 API 加细粒度锁
- 优点：线程可自由操作任何数据
- 缺点：锁竞争大，多线程可能比单线程还慢
- 适用：需要在线程内自由操作共享数据的场景

### P4-3. `tphp_class_Thread` OOP 封装

- **位置**: 新建 `include/object/thread.h`（或 `include/os/thread.h`）
- **影响**: PHP 层提供 `Thread`/`Mutex`/`CondVar` 类，符合 TinyPHP COS 封装风格
- **依赖**: P4-1（tinycthread）、P4-2（运行时线程安全）
- **方案**: 参考 [tphp_class_File](file:///c:/project/php/TinyPHP/include/os/file_obj.h) 的封装模式，将 tinycthread 的 C API 包装成 COS 类

#### 类设计

```php
// Thread 类（基于 thrd_t）
class Thread {
    public function __construct(callable $fn): void;
    public function start(): bool;           // thrd_create
    public function join(): int;             // thrd_join
    public function detach(): bool;          // thrd_detach
    public static function yield(): void;    // thrd_yield
    public static function id(): int;        // thrd_current
}

// Mutex 类（基于 mtx_t）
class Mutex {
    public function __construct(bool $recursive = false): void;
    public function lock(): bool;            // mtx_lock
    public function tryLock(): bool;         // mtx_trylock
    public function unlock(): bool;          // mtx_unlock
}

// CondVar 类（基于 cnd_t）
class CondVar {
    public function __construct(): void;
    public function wait(Mutex $m): bool;            // cnd_wait
    public function signal(): bool;                  // cnd_signal
    public function broadcast(): bool;               // cnd_broadcast
    public function timedWait(Mutex $m, int $ms): bool;  // cnd_timedwait
}
```

#### COS 封装规范（遵循 [tphp_class_File](file:///c:/project/php/TinyPHP/include/os/file_obj.h) 模式）

```c
// 1. Struct（t_object _obj 必须第一位）
typedef struct {
    t_object _obj;
    thrd_t   handle;    // tinycthread 线程句柄
    int      state;     // 0=未启动, 1=运行中, 2=已结束
    // ...
} tphp_class_Thread;

// 2. 类描述符（静态常量，dtor 指向 __destruct）
static const t_class _class_tphp_class_Thread = {
    .name = "Thread",
    .parent = NULL,
    .instance_size = sizeof(tphp_class_Thread),
    .dtor = (void*)tphp_class_Thread___destruct,
    // ...
};

// 3. 方法签名（self 第一参数，热路径 static inline）
void tphp_class_Thread___construct(tphp_class_Thread* self, /* callable wrapper */);
void tphp_class_Thread___destruct(tphp_class_Thread* self);
t_bool tphp_class_Thread_start(tphp_class_Thread* self);
t_int  tphp_class_Thread_join(tphp_class_Thread* self);
// ...
```

#### CodeGenerator 登记（必需，否则 PHP 无法引用）

在 [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) 的 `registerBuiltinClasses()` 中登记：
```php
$this->symbols->addClass('tphp_class_Thread');
$this->symbols->addClassName('Thread', 'tphp_class_Thread');
$this->symbols->getClass('tphp_class_Thread')->methods['__construct'] = new MethodInfo('void', ['callable']);
$this->symbols->getClass('tphp_class_Thread')->methods['start'] = new MethodInfo('t_bool');
$this->symbols->getClass('tphp_class_Thread')->methods['join']  = new MethodInfo('t_int');
// ...
```

#### callable 跨线程传递的关键问题

PHP 的 `callable` 在 TinyPHP 中编译为闭包结构体指针，包含捕获的变量。跨线程传递需注意：
- **值捕获**：深拷贝捕获的变量（避免共享指针）
- **use 引用捕获**：禁止跨线程传递（或强制要求 Mutex 保护）
- **闭包内分配**：若策略 A（thread-local 运行时），线程内有独立 str_pool，可自由分配；结果通过 join 的 int 返回值或共享 Mutex 保护的数组传回

### P4-4. `Parallel` 数据并行 API（可选，高层封装）

- **位置**: 新建 `include/sync/parallel.h`
- **依赖**: P4-3
- **方案**: 参考 klib `kt_for` 的 work-stealing 模型，提供 `Parallel::map`/`Parallel::for` 静态方法
- **限制**: 回调必须为纯函数（只用参数，不访问外部变量），编译期或运行时约束

```php
class Parallel {
    // 纯函数并行：回调只能用参数，不能访问外部变量
    public static function map(array $data, callable $fn, int $threads = 0): array;
    public static function for(int $n, callable $fn, int $threads = 0): void;
}
```

### P4 实施路线建议

| 阶段 | 内容 | 风险 | 前置依赖 |
|------|------|------|---------|
| **P4-1a** | 拷贝 tinycthread 到 `include/compat/`，修复 9 个不足中的高优先级项（#1/#2/#3/#9） | 低 | 无 |
| **P4-1b** | 补中优先级项（#4 thrd_detach / #10 SpinLock / #11 WaitGroup） | 低 | P4-1a |
| **P4-2a** | 运行时 thread-local 改造（str_pool/arr_pool/obj_pool 独立） | 中 | P4-1a |
| **P4-3a** | `tphp_class_Thread`/`Mutex`/`CondVar` COS 封装 + CodeGenerator 登记 | 中 | P4-1a + P4-2a |
| **P4-3b** | callable 跨线程传递（值捕获深拷贝） | 中 | P4-3a |
| **P4-4** | `Parallel::map`/`for` 数据并行（可选） | 低 | P4-3a |

### P4 参考资源

- tinycthread 源码: `C:\Users\28249\Desktop\tinycthread-1.1\source\`（zlib license）
- vlang stdatomic 兼容头: `C:\Users\28249\Desktop\v-master\thirdparty\stdatomic\{nix,win}\atomic.h`（FFmpeg 衍生，LGPL 2.1+）
- vlang sync 模块: `C:\Users\28249\Desktop\v-master\vlib\sync\`（Mutex/RwMutex/SpinLock/Semaphore/WaitGroup/Channel）
- klib kthread: `C:\Users\28249\Desktop\klib-master\kthread.c`（kt_for/kt_pipeline work-stealing）
- TinyPHP COS 封装蓝本: [include/os/file_obj.h](file:///c:/project/php/TinyPHP/include/os/file_obj.h)（tphp_class_File）
- TinyPHP 已有 pthread 用法: [include/object/generator.h](file:///c:/project/php/TinyPHP/include/object/generator.h)（macOS+TCC 线程模拟协程）

---

## 测试覆盖待补强

| 目录 | 现状 | 待补 |
|------|------|------|
| `test/pcre/` | 仅 1 个文件 | 补充：lookahead（P2-3 完成后）、命名组、UTF-8、ReDoS 防护、长模式（>256 字节）、`preg_replace_callback` |
| `test/iconv/` | 仅 1 个文件 | 补充：MIME encode/decode、Win32 codepage 覆盖、UTF-8 快路径、失败 tp_throw 捕获 |
| `test/filter/` | 仅 1 个文件 | 补充：min_range/max_range 选项、所有 sanitize 过滤器、MAC/domain/regexp 验证 |
| `test/phpc/` | 无 | 新建：回调签名、env_pin 固定、steal 语义、`phpc_arr_*` 类型不匹配 tp_throw、`phpc_new_obj` refcount |
| `test/security/` | 无 | 新建：`#flag` 危险 flag 黑名单、`#include <system.h>` 白名单越界、`#import` 路径穿越 |
| `test/generator/` | 6 个文件 | P2-1 完成后补充：Generator 方法、Generator 闭包、use vars 捕获 |

---

## 已确认无需模仿原生 PHP 的决策（参考记录）

以下决策经评估认为正确，**不要回退或重新实现**：

| 特性 | 决策 | 理由 |
|------|------|------|
| `resource` 弱类型句柄 | 用 Resource 对象化替代 | PHP 8 已逐步废弃，强类型对象更安全 |
| `===` 严格比较 | 与 `==` 等价 | 类型固定，编译期已知类型 |
| Zend EG(exception) 双轨 | 用 setjmp/longjmp 单轨 | AOT 转换成本高，单轨更简洁 |
| `eval()`/`include`/`require` | 永久拒绝 | 无运行时解释器 |
| `$$var`/`compact()`/`extract()` | 永久拒绝 | 无运行时符号表 |
| `__call`/`__get`/`__set` | 永久拒绝 | 无动态分发 |
| `Reflection*`/`debug_backtrace()` | 永久拒绝 | 无运行时内省 |
| `?int` 可空类型 | 不做 | 破坏类型固定优势，引导用 mixed 或重载 |
| `...$args` 可变参数 | 不做 | AOT 无意义，传 array 更显式 |
| 命名参数 `foo(x: 1)` | 不做 | AOT 无意义 |
| first-class callable `strlen(...)` | 不做 | 场景有限 |
| 属性 `#[Attr]` | 不做 | 依赖运行时反射，AOT 无用 |
| PCRE2 完整兼容 | 子集即可 | lookbehind/backref 复杂度高，当前子集覆盖 ~90% Web 场景 |
| `date()` 时区数据库 | 不做 | 时区库体积过大，业务层处理 |
| libevent 扩展 | 已放弃 (2026-07-07) | 库符号不完整，TCC/GCC 都无法链接 |

---

## 变更记录

| 日期 | 内容 |
|------|------|
| 2026-07-08 | 初始创建，基于项目全量分析整理 P0-P3 共 23 项 + 测试覆盖 + 无需模仿清单 |
