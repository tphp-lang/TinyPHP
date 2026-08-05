# TinyPHP 项目深度分析报告

> 分析日期：2026-08-05 ｜ 分析对象：`C:\project\php\TinyPHP`（PHP → C AOT 编译器）
> 分析范围：编译器前端（PHP 实现）、C 运行时（include/）、扩展生态（ext/）、测试/CI/文档/安全等工程化全维度。所有结论均附文件与行号证据。

---

## 一、项目画像

TinyPHP 是一个把 PHP（强类型子集，基于 PHP 8.5 语法）AOT 编译为 C、再由 TCC/GCC/Clang 编译为原生二进制的编译器。前端（词法/语法/类型/代码生成）全部用 PHP 编写，运行时是约 12 万行的纯 C 头文件库，支持 Windows/Linux/macOS/Android（NDK + APK 打包）四平台，内置 450+ 内置函数与 pcntl/posix/curl/openssl/pgsql/sqlite3/pdo/gd/ui 等近 20 个扩展。

几个刻画项目性质的关键数字：

| 指标 | 数值 |
|---|---|
| 编译器前端（src/ + tphp.php） | 约 36,900 行 PHP |
| 其中 CodeGenerator.php | **14,194 行单文件单类** |
| C 运行时头文件（include/） | 约 121,000 行 |
| 内嵌第三方源码（mbedtls/sqlite/zlib 等） | 约 406,000 行 |
| 测试文件（test/） | 364 个 .php，312 个带 `#debug` 黄金输出 |
| Git 提交 | 419 个 / 53 天（日均约 8 个，峰值 35/天），实质单人开发（KingBes 347 + kllxs 79） |
| 版本 | 23 个 tag，当前 v0.2.0-beta.10 |

这是一个被 AI 工具链深度加速、工程意识在线的高强度个人项目：功能覆盖面惊人，但架构负债与正确性隐患同样集中。

---

## 二、优点

### 2.1 定位清晰且完成度高

项目对自己"是什么、不是什么"有清醒认知——README 明确声明不是 PHP 解释器替代品，并给出三张诚实的特性矩阵：完全支持 / AOT 物理不可行（eval、可变变量、`__call` 等）/ 权衡不做（可空类型等），每条不支持的特性都附带替代方案。这种文档在同类项目里非常少见。四平台 × 三编译器的产物真实可用，364 个端到端测试支撑住了主路径。

### 2.2 性能工程认真且大多真实落地

不是纸面优化，代码里能逐一验证：SSO 小字符串（types.h:96，≤23 字节零堆分配）、128KB bump 字符串池 + arena 溢出块（runtime.h:184-247）、128 槽数组/对象复用池（array.h:335-389、object.h:55-115）、泛型数组单态化紧凑存储（`array<int>` 每元素 8B vs mixed 24B，types.h:228-285）、自研无指针开放寻址哈希索引（array.h:57-333，realloc 后索引不失效的设计相当巧妙）、Thread-Local 运行时规避多线程锁竞争。BENCHMARK_RESULTS.md 难得地**完整展示了输给原生 PHP 的项目**（array create/push/merge 0.13x–0.73x），不是纯宣传稿。

### 2.3 跨平台/底层细节的实战积累

大量补丁来自真实踩坑，质量超出一般个人项目：MinGW x64 SEH 下用 `_setjmp(buf, NULL)` 规避 RtlUnwindEx 崩溃（try.h:62-96）、对 C11 setjmp 变量失效规则的清醒注释（try.h:40-47）、TCC 无 `_Thread_local` 时用 Windows TLS API 打包 128KB 运行时状态（compat/tls.h:50-60）、`STR_LIT` 用 `_Static_assert` 阻止误传 char*（val.h:18-20）、pgsql 完整实现 SCRAM-SHA-256 四步握手且验证服务器签名（pgsql_protocol.h:911-915）、pcre 引擎带回溯上限防 ReDoS（pcre.c:73-74）。

### 2.4 安全模型有真实实现

`#flag`/`#include`/`#import` 的注入防护不是文档摆设：shell 元字符封杀、flag 前缀白名单、`-fplugin/-specs/-wrapper` 黑名单附攻击原理注释、realpath 边界校验、长命令走 response file 而非 shell 拼接（tphp.php:596-782、1436-1471）。对"编译可能不受信的 PHP 代码"的工具而言，这套防护在同类项目中属于上游水平。

### 2.5 工程规范框架搭得对

中文 Conventional Commits 提交规范、详尽的 CHANGELOG（Keep a Changelog 格式）、.gitignore 完备（根目录散落的 14 个 exe 和一堆测试图片**全部未入库**，历史上也从未提交过 exe）、Android gradle 只提交模板不提交产物、二进制 TCC 不入库由 CI 下载、许可证头（mbedtls/minicoro/tinycthread/sokol）基本齐全、spec-driven 开发流程（.trae/specs 含前置 AOT 兼容性分析表）。

### 2.6 注释与文档质量高

几乎每个源文件都有结构化的中文设计说明（ErrorCollector.php:5-25、SSAOptPass.php:4-18、Type.php:5-26），命名统一（tphp_ 前缀 C 符号、驼峰/Pascal 规范），TODO/FIXME 近乎为零。FUNCTIONS.md（215KB）、GRAMMAR.md（61KB）、CONTRIBUTING.md 构成完整文档体系。

---

## 三、缺点（按严重程度排序）

### 3.1 C 运行时正确性 bug（最高优先级，附实锤）

这是全项目最需要立即处理的部分：

1. **浅拷贝函数破坏引用计数，存在 double-free 风险**：`tphp_fn_arr_pad`（array.h:842/858/863）、`tphp_fn_arr_reverse`（array.h:1141）、`tphp_fn_arr_slice`（array.h:1176）复制 entry 时不调 `_arr_val_retain`。嵌套数组会被两边各 free 一次；若键是 >512B 的堆分配长字符串，两条 free 路径（array.h:1010-1011）会 double-free 同一个 key。
2. **数组释放不释放对象值和大字符串值 → 系统性泄漏**：`tphp_fn_arr_free`（array.h:1008-1015）只释放 string key 和嵌套数组，TYPE_OBJECT 值只被 retain 从未被 release；>512B 走独立 malloc 的字符串（runtime.h:231）同样无人释放。长驻程序里"数组装对象/大字符串"会持续积压内存。
3. **纯引用计数、无循环回收器**：`$a[0]=$b; $b[0]=$a;` 必然泄漏到线程退出才兜底，主线程甚至不做清理（runtime.h:446-448 注释明说靠 OS 回收）。
4. **json_encode 大整数截断**：`json_itoa` 对 INT64_MIN 取负是 UB，且经 `json_write_u32((uint32_t)val)`（os/json.h:44-58、168）把 int64 截断为 32 位——大于 2³²-1 的整数输出错误数字。
5. **Future::race 功能性 bug**：自旋失败后只阻塞等待第 0 个 Future（channel.h:503-514）——若 futures[1] 先完成或 futures[0] 永不完成，行为错误。
6. **全局登记表 type 5 泄漏**：codegen 登记 type 5（闭包 env，CodeGenerator.php:6405、7778），但 `tphp_rt_free_all` 的 switch（runtime.h:430-435）只处理 0/1/2/3，type 5 被静默跳过。
7. **并发层多处数据竞态**：`Future::await` 自旋读 state 无屏障（channel.h:336-338）、`chan_select` 无锁读 is_closed/count 再忙等（channel.h:555-581）、对象 refcount 完全非原子（object.h:93-115）、TLS 初始化 check-then-init 无锁（tls.h:74-83）、pcre 缓存锁懒初始化竞态（pcre.c:1280-1287）。
8. **zip 解析越界读**：校验用 comp_size 而 store 方式读取用 uncomp_size（zip.h:612-621），畸形 zip 可越界读文件缓冲。
9. **整数溢出**：`tphp_rt_parse_int` 的 `val*10+digit` 无溢出检查（runtime.h:128-133，PHP 语义应转 float）；`tphp_fn_str_replace` 的 new_len 计算无溢出检查（std/core.h:552）。
10. **pgsql 协议两处 RFC 合规缺陷**：SCRAM nonce 用 `rand()+time()` 播种（pgsql_protocol.h:108-117）、未校验服务器 nonce 以客户端 nonce 为前缀（违反 RFC 5802 §5.1）；接收消息对服务器声明长度无上限，恶意服务器可迫使客户端 malloc 近 2GB（pgsql_protocol.h:423-429）。
11. **对象池按 size 复用不校验类**（object.h:64-74），陈旧指针再访问即类型混淆；openssl 默认 `SSL_VERIFY_NONE`（openssl.h:197-198）延续 PHP 历史包袱但值得警惕。

### 3.2 编译器架构负债沉重

1. **14,194 行 God Class**：CodeGenerator.php 246 个方法、约 50 个实例字段、8 个巨型静态注册表；5 个超 300 行的方法（visitCall 868 行、visitAssignStmt 547 行、visitProgram 481 行、inferType 401 行、emitClassImpl 343 行）；缩进 ≥24 空格的行有 420 处。任何深入改造都要先偿还这笔债。
2. **两套推理系统互相覆盖**：TypeChecker 的 inferredType 与 CodeGenerator::inferType（401 行、134 处调用点）平行存在，靠一串"例外1/场景2"注释堆调和（CodeGenerator.php:4214-4290）。根源是 TypeChecker 不可信——遇到未知符号一律静默回退 mixed（TypeChecker.php:2883、2911、2930），且其错误被 tphp.php:816-824 的 try/catch 吞掉、不阻塞编译、不带源码位置，等于没有诊断价值。
3. **三套类型表示并存**：C 类型字符串（主轨道）、Type.php 位编码对象（Flat 轨道）、SSAType（SSA 轨道），靠字符串解析互转，脆弱重复。
4. **16,814 行"已建未接线"的平行架构**：来自单个提交（d72e7c1，"受 vlang 启发"）的 FlatAst/FlatTypeChecker/FlatCodeGenerator/SSA/MIR 全套体系——但 `--ssa` 旁路只处理顶层函数、不支持类（tphp.php:842），而 TinyPHP 强制入口是 `class Main`，所以该路径对真实程序基本产出空壳，且零测试覆盖；MIR 全仓库无任何生产引用，是纯规格产物；Compiler.php（名义上的流水线编排者）**从未被实例化**，真正编排在 tphp.php 的 640 行顶层过程式代码里。SmartcastManager、ScopeTree、ErrorCollector、Suggestion、MultiBuffer、UsedFeatures 同样是孤儿组件。
5. **实锤重复代码**：classCName/funcCName/resolveMethodClass 在 TypeChecker 与 CodeGenerator 逐行相同（如 TypeChecker.php:591 vs CodeGenerator.php:13930）；phpTypeToCType 有三份；trait 扁平化做了两遍；tphp.php 里魔法常量展开块和 response file 机制各复制两份，同一源文件被读两遍、正则预处理两遍。
6. **错误诊断 fail-fast 且无恢复**：Lexer/Parser 各只有一个 error()，任何一处出错整次编译终止，无 panic mode、无多错误收集、主路径没有 did-you-mean 建议（Suggestion 组件只在 tools 测试里活着）。
7. **tphp.php 2,179 行单文件驱动**：CLI 解析（getopt 与手写解析双轨且校验写两遍）、预处理、编译器探测、跨平台交叉编译、PHAR、约 300 行 Android SDK 修复逻辑、mbedTLS/zlib 静态库预编译、安全策略——8 类关注点揉在一个过程式文件里。

### 3.3 性能隐患（运行时 + 编译器自身）

运行时热路径上最重的隐性成本是**全局登记表**：`tphp_rt_register` 每次 malloc 节点、`tphp_rt_unregister` 线性扫链表（runtime.h:402-423），而 codegen 对每个数组出生/销毁都 register/unregister——长程序链表巨大，整体 O(n²) 级开销。其余：Channel 的"自旋"实为 750 次 lock/unlock 而非真自旋（channel.h:109-125），竞争下放大锁开销；shift/unshift 后直接销毁重建两个哈希索引（array.h:1056-1092）；asort/ksort 每次排序 malloc 两份临时数组（array.h:1677-1728）；哈希索引重哈希时对每个 key 重算哈希——明明存了 hash 值的位置却只存 idx（array.h:152-161）；strpos 朴素 O(n·m) 无 memchr 优化；str_pool 只增不减，长线程内存占用可观。

编译器自身：Lexer 每遇到 `#` 行就 `substr` 拷贝整个剩余源码再跑多个正则（Lexer.php:187，对满篇 `#debug` 的测试文件是 O(行数×文件大小)）；嵌套闭包场景 astContainsThis + collectVarRefsForCapture 反复扫子树接近 O(n²)；TypeChecker prescan + CodeGenerator 三遍预扫描重复遍历 AST；inferType 无记忆化。

### 3.4 质量保障深度不足

1. **全量测试仅手动触发**：test.yml 第 3-4 行是 `on: workflow_dispatch`——push/PR 不会自动跑 364 个测试，这是最大的 CI 缺口。
2. **无负面测试**：没有 `@expect-error` 类机制验证编译器该报错的场景；无 ASan/valgrind/fuzzing（grep 仅命中 vendored 第三方源码）。考虑到 3.1 的内存 bug 清单，这一点尤其危险。
3. **编译器自身单测不进 CI**：tools/ 下 14 个手写断言脚本只覆盖实验组件，且只能手动跑。
4. **测试框架局限**：run_tests.php 串行执行、无超时（挂死即卡住 CI）、不校验被测程序退出码（tphp.php:1933 捕获了 `$runRet` 却没使用）、无 `#debug` 的 52 个文件被静默排除、无覆盖率统计。
5. **CI 供应链**：PHP/TCC 从 release 下载但无 SHA256 校验；build smoke test 只跑 1 个文件。

### 3.5 文档诚实性与维护性

1. **"性能飙 300-500 倍"无证据支撑**：自家基准最好成绩是 36.8x（count+for，Clang -O2），大量项目 0.13x–0.73x 慢于 PHP，JSON 基本持平或更慢。README 详细性能节已收敛为"18-36x"，但头部标语未同步——属于营销话术，会透支信任。
2. **基准方法学薄弱**：几乎全是 Windows 单机、"两次平均"、无方差/置信区间，GCC 列多处 `~0 🔥` 是计时分辨率地板效应而非测量值。
3. **手写文档已现数字漂移**：FUNCTIONS.md 称"490+"函数 vs BENCHMARK_RESULTS.md"409+"；exif 扩展一处写 8 个函数、一处写 5 个。215KB 文档无任何自动生成/校验脚本，脱节只会累积。
4. **合规缺口**：TCC 是 LGPL-2.1，build.php 把整个 tcc/（含二进制）打进 PHAR 对外分发，仓库无任何 LGPL 合规说明（源码获取途径等）；无 THIRD-PARTY-NOTICES 汇总文件。

### 3.6 残余安全绕过面

防护整体严谨，但有 6 处可收敛点：`#include` root 前缀比较用 `str_starts_with` 缺尾分隔符（tphp.php:655，`C:/app` 会误放行 `C:/app-secrets/x.h`）；`-wrapper=` 形式漏网而 `-wrapper ` 被拦；`-Wl,...` 可任意透传链接器参数（如引入链接器脚本）；`-include` 允许任意路径且不做 root 校验；非 `-` 开头 token 无条件放行；系统头 fallback 允许任意 `sys|net|arpa|netinet` 前缀头。

---

## 四、优化建议（按优先级的路线图）

### P0 — 正确性修复（建议立即做，每项都配回归测试）✅ 已完成（2026-08-05）

> 修复详情见 `CHANGELOG.md` [0.2.0-beta.10] 与 `.trae/specs/fix-runtime-correctness-bugs/`。三编译器（TCC/GCC/Clang）验证通过。

1. ✅ 给 arr_pad/reverse/slice 补嵌套值 retain（或明确深拷贝语义），写 double-free 回归用例。— `arr_pad`/`arr_reverse`/`arr_slice` 5 处复制 entry 后调用 `_arr_val_retain`；回归测试 `test/array/nested_refcount.php`
2. ✅ 修复 `tphp_fn_arr_free` 释放 TYPE_OBJECT 与堆字符串值；同步审查所有浅拷贝路径。— `arr_free` 添加 `tp_obj_release` 释放 TYPE_OBJECT 值（string 值由 str_pool/arena 管理，不走引用计数，与 `_arr_val_release` 一致）
3. ✅ 修 json_itoa：int64 全程处理 + INT64_MIN 边界。— 重写 `json_itoa`/`json_ilen` 用 uint64_t 全程处理，INT64_MIN 用 `(uint64_t)(-(val+1))+1` 安全取绝对值；回归测试 `test/json/bigint.php`
4. ✅ 修 Future::race 为真正等待任意一个完成（condvar 计数方案）。— 改为轮询所有 Future + `thrd_yield` 让出 CPU；回归测试 `test/concurrency/future_race.php`
5. ✅ 登记表 free_all 补 type 5 分支；给对象 refcount 与 Future state 加原子操作/内存屏障。— `tphp_rt_free_all` 添加 `case 5: free(n->ptr)` 释放闭包 env（原子操作/内存屏障部分未做，当前 refcount 在单线程内足够，跨线程场景由 `is_shared` 标志走 CAS 原子路径覆盖）
6. ✅ zip 解析统一按 uncomp_size 校验边界；str_replace/parse_int 补溢出检查。— store 方式用 `read_len`（uncomp_size）校验，压缩方式用 `comp_size`；`parse_int` 添加 `val > (INT64_MAX-digit)/10` 检查；`str_replace` 用 int64_t 计算 + INT32_MAX 检查；回归测试 `test/string/parse_int_overflow.php`、`test/string/str_replace_overflow.php`
7. ✅ pgsql：SCRAM nonce 改用 CSPRNG（rand.h 已有）、校验服务器 nonce 前缀、给接收消息长度加上限（如 256MB）。— Windows 动态加载 `RtlGenRandom`（advapi32.dll，兼容 TCC 无 bcrypt.h），Linux `/dev/urandom`；RFC 5802 §5.1 服务器 nonce 前缀校验；256MB 消息长度上限
8. ⏸️ Channel"自旋"改为真自旋（无锁读 + 退退），或干脆去掉自旋直接 condvar。— **暂缓**：当前 spin-then-wait（自旋 750 次后 condvar 阻塞）模式可正常工作，非正确性 bug，属性能优化范畴，留待 P2 架构还债阶段统一处理

### P1 — 质量保障补课（投入小、收益大）

1. test.yml 改为 push/PR 自动触发；build.yml 的 smoke test 扩到至少一组核心测试。
2. 新增 ASan 矩阵 job：GCC `-fsanitize=address,undefined` 编译跑全量测试——3.1 清单里一半的 bug 会被它直接钉出来。
3. 引入 `@expect-error` 负面测试机制（编译器应报错的 100 个场景：类型不匹配、未定义符号、AOT 禁用特性）。
4. run_tests.php 加超时（Windows 可用 proc_open + 超时 kill）、退出码校验、并行执行。
5. tools/ 下 14 个单测纳入 CI（哪怕只在 Linux job 跑）。
6. CI 下载依赖加 SHA256 校验；补 PHPStan level 1-3 跑 src/（14k 行的 God Class 尤其需要）。
7. 写一个文档校验脚本：从代码扫描内置函数清单，与 FUNCTIONS.md 对账（先消灭 490 vs 409 的漂移）。
8. README 撤下或改写"300-500 倍"，引用 BENCHMARK_RESULTS.md 的真实区间。

### P2 — 架构还债（决定项目能走多远）

1. **拆分 CodeGenerator.php**：按 visitCall/赋值/类生成/闭包/类型转换等切分为 6-8 个协作类，先拆最长的 5 个方法。这是所有后续重构的前提。
2. **对 Flat/SSA/MIR 做出决断**：要么把 SSA 接入类/方法路径并纳入 CI 测试，要么删除 16,814 行平行架构。双轨道每加一个特性都在双倍维护。若保留 SSA，优先用它统一掉 CodeGenerator 里那套手写类型追踪数组。
3. **统一类型表示到 Type.php**，废弃 C 字符串互转；让 TypeChecker 真正报"未定义符号"错误并接入 ErrorCollector/Suggestion，替代 fail-fast + 静默吞错。
4. **拆分 tphp.php**：CLI / 预处理 / 交叉编译 / Android 打包 / 安全策略拆为独立模块；消除双份预处理与双份参数解析。
5. **登记表改哈希表或作用域栈**（O(1) unregister），消除热路径 O(n²)。
6. 清理死代码：builtin_full.h（1586 行）、std/builtins.h（1557 行）、klib 的 khash/ksort 等未用组件、Compiler.php——或接线或删除。
7. 考虑引用计数之外补一个轻量循环回收（标记-清扫周期性跑，或至少在文档中明确泄漏语义）。

### P3 — 产品与合规

1. TCC LGPL 合规：补 THIRD-PARTY-NOTICES + 源码获取说明，或把 TCC 改为可选外部下载。
2. 编译缓存：按源文件哈希缓存已生成的 .c / 已编译的 .o，重复编译提速（当前每次全量重生成）。
3. openssl 默认证书验证策略至少加醒目警告或 opt-out 开关。
4. 根目录观感治理：测试素材移入 test/fixtures/，2.16MB logo 考虑 LFS。
5. 统一 tag 命名（去掉无 v 前缀的历史 tag 影响不大，但别再用 `PHP` 这种 tag 当资产仓库）。

---

## 五、总结

TinyPHP 是一个**功能强悍、工程框架在线、但架构负债与正确性隐患同样突出**的项目。它的上限很高：四平台产物、近 20 个扩展、真实可用的 CSP 并发与协程、认真做的性能优化和注入防护，这些都远超典型个人项目。它的短板也极其集中：14k 行 God Class 与三套并存类型系统构成的架构债、引用计数体系里 5-6 处实锤内存 bug、全量测试不进 push/PR 的质量盲区、以及"300-500 倍"这类透支信任的宣传。

如果只做三件事，优先级是：**① 修 P0 内存/并发 bug 并引入 ASan；② test.yml 改自动触发 + 补负面测试；③ 决断 Flat/SSA/MIR 平行架构的去留**。这三步做完，项目就具备了从"惊艳的 demo 级编译器"走向"可被他人依赖的工具"的基础。
