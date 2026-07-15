# Changelog

本文件记录 TinyPHP 的版本变更历史。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/)。

---

## [Unreleased]

### 新增

- **zlib 扩展对标 PHP 原生补全**（新增 22 个函数）：
  - **gz 文件流 API**（15 个）：`gzopen`/`gzclose`/`gzread`/`gzwrite`/`gzputs`/`gzeof`/`gzgets`/`gzgetc`/`gzrewind`/`gzseek`/`gztell`/`gzpassthru`/`gzflush`/`gzfile`/`readgzfile`，统一以 `Resource` 封装 `gzFile`
  - **增量上下文 API**（6 个）：`deflate_init`/`deflate_add`/`inflate_init`/`inflate_add`/`inflate_get_status`/`inflate_get_read_len`，上下文封装为 Resource
  - 通用接口 `zlib_encode`/`zlib_decode`
  - 完整常量集（编码格式/压缩级别/flush 模式/压缩策略/状态码/版本）
- **zip 扩展对标补全**（新增 5 个函数）：`zip_locate`、`zip_entry_name`、`zip_entry_filesize`、`zip_entry_compressedsize`、`zip_entry_compressionmethod`
- 完整常量集（ZIP 打开模式/标志位/压缩方法）

### 变更

- **zlib 依赖架构重构**：从"系统 zlib 动态发现（MSYS2 路径 + PATH 扫描 + DLL 复制）"改为**内置 zlib 1.3.2 源码静态编译**：
  - 源码置于 `include/os/zlib_src/`（15 个 `.c` + 11 个 `.h`，约 332KB）
  - `tphp.php` 检测生成的 C 代码引用 `os/zlib.h` 后，自动将 zlib 源码 `.c` 加入编译列表
  - 移除 `tphp.php` 中写死的 MSYS2 路径（`C:\msys64\...`/`C:\env\msys2\...`）
  - 删除 `tcc/win32/lib/zlib1.dll`，零运行时依赖
  - **确保纯 TCC 环境（无 MSYS2/GCC/Clang）也能使用 zlib/zip 扩展**
- **AOT 异常契约统一**：zlib/zip 全部 API 失败时抛 `Exception`（可 try-catch），不返回 `false`
- `include/os/zlib.h` 简化为直接包含内置 `zlib_src/zlib.h`，移除 TCC 手动声明块
- zip 不支持修改已有归档：`zip_delete`/`zip_rename` 抛异常

### 修复

- `gzfile()` 返回数组元素类型推断：在 `CodeGenerator.php` 的 `$builtinArrElemTypes` 注册表添加 `'gzfile' => 't_string'`，否则 `$lines[0]` 错误推断为 `t_int`
- `gzeof()` 行为说明：仅在读取超出末尾后才返回 `true`（与 PHP 原生一致），修正测试预期
- `zlib_decode()` 不支持自动检测 RAW DEFLATE 格式，改用 `gzinflate()` 解码
- 测试运行器文件名冲突：不同目录下同名 `basic.php` 生成相同 `test_basic.exe`，改用相对路径生成唯一名称（如 `test_zip_basic.exe`/`test_zlib_basic.exe`）
- Windows + TCC 编译 zlib 源码时 `EWOULDBLOCK` 未定义：在 `zlib_src/zlib.h` 顶部 `#define EWOULDBLOCK EAGAIN`
- `tphp.php` 中 `$extraSrcs` 重建时机：追加 zlib 源码后必须重建 `$extraSrcs`，否则编译命令不包含这些 `.c` 文件

### 文档

- [FUNCTIONS.md](FUNCTIONS.md) — 补全 zlib（29 函数 + 完整常量表）和 zip（18 函数 + 完整常量表）章节，版本号修正为 `1.3.2`/`0x1320`
- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — zlib（§4）和 zip（§12）章节完整重写：
  - API 返回类型从 `string|false`/`ZipArchive|false` 改为 AOT 异常契约
  - 设计说明更新为"内置 zlib 1.3.2 源码静态编译"
  - 目录表函数数修正：zlib 6→29、zip 12→18
- [README.md](README.md) — 内置函数数量从 `281+` 更新为 `306+`，描述加入 zlib/zip

### 测试

- `test/zlib/basic.php` 重写：覆盖基础压缩/解压、gz 文件流、增量上下文、gzeof 双状态演示、RAW DEFLATE 解码
- `test/zip/basic.php` 扩展：覆盖归档创建/读取/条目信息查询/locate
- 全部 166 个测试通过，含纯 TCC 环境（无 MSYS2）验证

---

## [0.2.0-beta.1] — 2026-07-14

首个公开测试版。PHP → C AOT 转译器，支持 Windows / Linux / macOS × x86_64 / aarch64，可选用 TCC / GCC / Clang 三种编译器。

### 核心特性

- **AOT 转译**：PHP 源码 → C 源码 → 原生机器码，无运行时解释器，零启动开销
- **强类型**：编译期类型固定，所有类型转换在编译期确定（无 PHP 弱类型运行时开销）
- **跨平台编译**：单一工具链支持 4 平台交叉编译（`-os`/`-arch` 参数）
- **C 互操作**：`phpc` 桥接层，可直接调用 C 库函数、声明 C 结构体、传递 C 指针
- **281+ 内置函数**：覆盖标准库（字符串/数组/数学/JSON/Hash/Date/ctype）、PCRE、iconv、exif、password、posix、pcntl、filter 等
- **多线程**：Thread/Mutex/CondVar/WaitGroup + Parallel::for/map 数据并行 API
- **动态库导出**：`#[Export]` 注解 + `-shared` 选项，将 PHP 函数导出为 C 符号

### 语法支持

#### 完全兼容 PHP 原生（✅）

- 控制流：`if/elseif/else`、`while`、`do-while`、`for`、`foreach`、`switch`、`match`、`break N`、`continue N`、`goto`
- OOP：`class`、`extends`、`interface`、`implements`、`trait+use`、`abstract class`、`final class`、`readonly`、`self::`、`parent::`、`parent::__construct()`
- 异常：`try/catch/finally`、`throw`（含表达式形式）、自定义 Exception 子类
- 函数：默认参数、命名参数（部分）、箭头函数（单表达式 + 块体）、闭包
- 类型：`int/float/string/bool/array/void`、`?T` 可选标记（局部变量/常量）、`Type|Exception` 返回类型
- 运算符：`**`、`<=>`、`??`、`|>` 管道、`?->` nullsafe、赋值复合运算符
- 字符串：heredoc/nowdoc、`{$var}` 花括号插值、转义序列
- 其他：`instanceof`、`isset()`、`empty()`、`enum`、`fn` 箭头函数、`yield`/Generator、属性 Hook、`#[Attribute]` 注解

#### 不支持（AOT 物理不可行）

- `eval()` / `assert($str)` / `create_function()` — 无运行时解释器
- `include` / `require` — 无运行时加载
- `$$var` / `compact()` / `extract()` / `get_defined_vars()` — 依赖运行时符号表
- `$fn()` / `call_user_func()` — 编译时不知函数名
- 魔术方法 `__call`/`__get`/`__set`/`__toString`/`__invoke`/`__clone` 等 — 需动态分发
- `Reflection*` 全系列、`debug_backtrace()`、`$GLOBALS` — 运行时内省

#### 不做（权衡）

- `?T` 可空类型 / `int|string` 联合类型 — 破坏类型固定优势
- `...$args` 可变参数、命名参数（完整版）— AOT 无意义/需动态栈
- `clone` / `declare(strict_types=1)` / `\u{}` Unicode 转义 / 返回引用 `function &f()`
- `protected` 可见性（仅 `public`/`private`）、`final` 方法修饰符（仅类级别）
- `catch (\Throwable $e)` — 无接口 vtable，用 `catch (Exception $e)` 替代

### 已知限制

- **`static` 属性修饰符**：语法上接受但当前标志会丢失（编译为实例属性）；仅内置类（Thread/Parallel/Enum）支持真正静态调用
- **macOS + TCC**：Generator 通过 pthread 线程模拟实现（替代 minicoro ASM/ucontext），其他平台使用原 minicoro
- **TCC + Windows**：使用 ELF 目标格式，与 MinGW/CMake 构建的 COFF 格式 `.a` 库不兼容

### 工具链

- 内置 TCC 编译器（无需安装 C 编译器即可使用）
- 支持 `-cc` 切换 GCC / Clang
- `--debug` 模式：打印编译命令 + `#debug` 预期输出比对
- CI 矩阵：4 平台 × 3 编译器 = 12 矩阵全量测试

### 测试

- 191 个测试文件，161 个可执行测试全部通过
- 含 `test/lang/` 目录 23 个对标 PHP 原生 `tests/lang/` 的语言基础测试

### 文档

- [README.md](README.md) — 快速上手 + CLI 用法
- [GRAMMAR.md](GRAMMAR.md) — 完整语法参考（含支持/不支持矩阵）
- [FUNCTIONS.md](FUNCTIONS.md) — 281+ 内置函数参考
- [QUICK_START.md](QUICK_START.md) — 5 分钟入门
- [CONTRIBUTING.md](CONTRIBUTING.md) — 架构与开发指南

---

## [0.1.0] — 内部开发版

初始内部版本，未公开发布。包含基础转译功能、OOP、控制流、标准库函数、PCRE 扩展。
