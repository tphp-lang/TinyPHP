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

### P2-1. Generator 支持方法/闭包

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) ~line 1075（方法抛错）、~line 2069（闭包抛错）
- **影响**: 当前仅独立函数支持 Generator，方法和闭包显式抛 `"Generator methods/closures not yet supported"`。
- **方案**:
  1. 方法：entry 函数需传递 `self` 指针到参数 struct，`mco_get_user_data` 解包后调用
  2. 闭包：use vars 与参数一起打包到 struct，entry 函数解包后重建闭包环境
  3. 注意 `this` 指针的 refcount 管理（entry 内 retain，wrapper 返回前 release）

### P2-2. enum 支持方法/常量/`implements`

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) `parseEnumDecl`、CodeGenerator enum 发射逻辑
- **影响**: PHP 8.1 enum 的 `Color::cases()` / `Color::from(int)` / `Color::tryFrom(int)` / enum 方法 / `implements` 接口约束全部缺失。
- **方案**:
  1. Parser: enum body 允许 `case` + `method` + `const` + `implements`
  2. CodeGenerator: 为每个 enum 自动生成 `cases()`/`from()`/`tryFrom()` 静态方法
  3. enum 实现 interface 时，方法走正常 vtable（与 class 一致）

### P2-3. pcre 支持 lookahead（无 lookbehind）

- **位置**: [ext/pcre/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/pcre.c) 编译器与 VM
- **影响**: `(?=...)` / `(?!...)` 是 PHP PCRE2 常用特性，当前缺失。lookbehind 需要变长回溯，复杂度高，暂不做。
- **方案**:
  1. 编译器：`(?=expr)` 编译为 `SPLIT L1, L2; L1: <expr>; ASSERT_SUCCESS; L2:`，VM 在 L1 失败时整体失败
  2. `(?!expr)` 类似，但 L1 成功时整体失败
  3. 注意 lookahead 内的 group captures 语义（PHP 保留 lookahead 内捕获）

### P2-4. 链式赋值 `$a = $b = 1`

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) parseStmt、[src/AST/Node.php](file:///c:/project/php/TinyPHP/src/AST/Node.php)
- **影响**: 赋值是 StmtNode 不是 ExprNode，无法出现在表达式位置，`$a = $b = 1` 解析失败。
- **方案**:
  1. 新增 `AssignmentExpr` 节点（继承 ExprNode）
  2. Parser: 赋值表达式优先级低于三元，解析为 AssignmentExpr
  3. CodeGenerator: `visitAssignmentExpr` 生成 `b = 1; a = b;`（或 `a = b = 1`）
  4. 现有 AssignStmtNode 保留，作为表达式语句的语法糖

### P2-5. `hash_hmac` 支持

- **位置**: [include/hash.h](file:///c:/project/php/TinyPHP/include/hash.h)
- **影响**: PHP 常用的 `hash_hmac('sha256', $data, $key)` 缺失，JWT/Webhook 签名场景必需。
- **方案**: 实现 HMAC RFC 2104（`H(K XOR opad, H(K XOR ipad, text))`），复用现有 SHA-256/SHA-512 block 函数。新增 `tphp_fn_hash_hmac`/`tphp_fn_hash_hmac_algos`。

### P2-6. `__FUNCTION__` / `__NAMESPACE__` 魔术常量

- **位置**: [src/Lexer.php](file:///c:/project/php/TinyPHP/src/Lexer.php) Token 识别、[src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `visitMagicConst`
- **影响**: `__FUNCTION__` 标注 ⬜，`__NAMESPACE__` 未实现。调试与日志场景常用。
- **方案**:
  1. Lexer: 新增 `T_FUNCTION_MAGIC`/`T_NAMESPACE_MAGIC` token
  2. CodeGenerator: `visitMagicConst` 根据 `currentClassName`/`currentMethodName`/`currentNamespace` 生成字符串字面量

---

## P3 — 性能与细节优化

### P3-1. `array_diff`/`array_intersect` 改哈希集优化

- **位置**: [include/std/array_extra.h](file:///c:/project/php/TinyPHP/include/std/array_extra.h)
- **影响**: 当前双重循环 O(n×m)，大数组慢。
- **方案**: 第二个数组建哈希集（khash），第一个数组遍历查找，降到 O(n+m)。

### P3-2. 整数键数组可选哈希索引

- **位置**: [include/array.h](file:///c:/project/php/TinyPHP/include/array.h)
- **影响**: 当前仅字符串键触发 arr_stridx，大整数键数组（如 ID→对象映射）查询仍是 O(n)。
- **方案**: 新增 `arr_intidx`（hash→entry_index，key 为 int64），阈值同样 8。触发条件：数组键全部为 int 且长度 ≥8。
- **注意**: 仅稀疏整数键（非 0,1,2... 连续）才需要，连续整数键直接 `entries[i]` 即可。

### P3-3. `resolveMethodClass` 缓存

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `resolveMethodClass`
- **影响**: 线性扫描父类链 O(depth) per call，无缓存，热路径性能隐患。
- **方案**: 新增 `self::$methodClassCache[cname][methodName] = resolvedClass`，编译期一次性建立。

### P3-4. 类型推断缓存

- **位置**: [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `inferType`
- **影响**: 每次 `inferType` 都重走 AST，大型项目编译慢。
- **方案**: 给 ExprNode 加 `inferredType` 字段，首次推断后缓存。注意 AST 节点可能在多函数中共享（闭包），需验证缓存失效场景。

### P3-5. pcre `tp_cache` key 长度限制

- **位置**: [ext/pcre/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre.c) `tp_cache` 结构
- **影响**: `key[256]` 截断长模式（>255 字节无法缓存）。
- **方案**: 改为 `char* key` + 动态分配，LRU 淘汰时 `free`。或改用哈希值作为 key（碰撞时回退到线性扫描）。

### P3-6. pcre `preg_replace` 反向引用性能

- **位置**: [ext/pcre/pcre.c](file:///c:/project/php/TinyPHP/ext/pcre/pcre.c) `preg_replace`
- **影响**: `$N` 反向引用重跑 `tp_find_from` 获取 captures，性能 O(n×matches)。
- **方案**: 替换前一次性收集所有 captures，存入 `captures[N][start,end]` 数组，替换时直接查表。降到 O(n+matches)。

### P3-7. static buf 线程安全

- **位置**: [include/conv.h](file:///c:/project/php/TinyPHP/include/conv.h) `decbin/decoct/dechex`、[ext/posix/posix.c](file:///c:/project/php/TinyPHP/ext/posix/posix.c) `getcwd`
- **影响**: 使用 static buf 非线程安全。当前 TinyPHP 无多线程，但未来扩展可能引入。
- **方案**: 改为调用方传入缓冲区，或返回 t_string（SSO + str_pool_alloc）。

### P3-8. `try.h` msg_buf 固定长度

- **位置**: [include/object/try.h](file:///c:/project/php/TinyPHP/include/object/try.h) `tp_ex_frame.msg_buf[256]`
- **影响**: 长异常消息（>255 字节）截断。
- **方案**: 改为 `char* msg` + 动态分配（str_pool_alloc），或用 `t_string` 字段。注意异常 frame 在栈上，需避免悬垂指针。

### P3-9. `parsePrimary` 巨型 if-else 重构

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) ~line 1633
- **影响**: 检查 ~25 个 TokenType 的巨型 if-else，新增 token 易遗漏，脆弱。
- **方案**: 改为 `match ($token->type)`（PHP 8 match）或分发表 `self::$primaryHandlers`。

### P3-10. `blockHasReturn()` 浅扫描

- **位置**: [src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) `blockHasReturn`
- **影响**: 仅检查顶层 return，不递归 if/for，可能误判函数是否返回（导致 non-void 函数漏 return 检查失效）。
- **方案**: 递归检查 if/else/for/while/try 的所有分支是否都有 return（PHP 语义）。

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
