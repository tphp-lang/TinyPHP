# TinyPHP 性能优化路线图

> 目标：用"好 C"逼近 Rust/Go 性能。关键原则：**我们管数据结构，编译器管指令级优化。**

---

## 1. 免费午餐 — 换编译器

| 编译器 | 循环性能 | 说明 |
|---|---|---|
| TCC（当前） | 1x 基准 | 零优化，寄存器分配极简 |
| GCC -O2 | **3-8x** | 向量化 + 循环展开 + 内联 |
| Clang/LLVM -O2 | **3-10x** | 同上 + LTO |

**行动**：CI 增加 `-cc gcc` / `-cc clang` 构建产物。本地用户默认 TCC（快编译），生产用 GCC/Clang（快运行）。

---

## 2. 我们能做到的 — 编译器无能为力的

### 2.1 ✅ 数组预分配容量 + 复用池（已完成）

**做法**：
- 数组字面量 `[1,2,3,4,5]` 创建时预分配 `max(4, len)` 个槽，消除 push 触发 realloc
- 128 槽 LIFO 复用池：`tphp_fn_arr_free` 回收到池，`tphp_fn_arr_create` 优先从池取
- 2× → 1.5× 增长因子（`nc = cap + (cap >> 1)`），减少 25% 内存浪费

**实际收益**：`array_pop` 1.8× 加速；临时数组减少 `malloc/free` 抖动。

### 2.2 ✅ 小字符串池（已完成）

**做法**：64KB bump allocator（`str_pool_alloc`），≤512 字节字符串零 `malloc`。`str_concat`、`str_dup`、`explode` 片段优先走池。

**实际收益**：`implode`/`explode` 减少 `malloc` 调用。

### 2.3 ✅ 分支预测优化（已完成）

**做法**：`likely`/`unlikely` 宏标注所有热路径（`arr_item_*`、`arr_index`、`arr_count`、`arr_push`）。
TCC/GCC/Clang 均支持 `__builtin_expect`。

### 2.4 t_var 对象池

**现状**：每次 `push` 新元素创建 `t_var` 栈变量（无 `malloc`），已无此瓶颈。

**状态**：✅ 无需优化（`t_var` 始终栈分配）。

### 2.5 t_string 小字符串优化 (SSO)

**现状**：已有 64KB 字符串池覆盖短字符串。但池满后仍 `malloc`。

**目标**：`t_string` 内置 24 字节缓冲区，短于 24 字节的字符串不堆分配也不占池。

```c
typedef struct {
    char *data;
    int   length;
    char  local[24];  // SSO 缓冲区
    bool  is_local;
} t_string;
```

**预估收益**：字符串拼接密集场景 **2-3x 加速**，零 `malloc`。

### 2.6 ✅ 批量数组构建（已完成）

**做法**：字面量数组 `[1,2,3,4,5]` → `tphp_fn_arr_create(5)` 一次 `calloc`。

**实际收益**：已知长度数组零 `realloc`。

### 2.7 for 循环作用域提升

**现状**：`for ($i=0; ...)` 声明的 `$i` 出循环体即失效，跨循环需额外声明。

**目标**：CodeGen 自动将 for-init 变量提升到函数作用域（C 兼容）。

**预估收益**：消除编译错误，减少用户心智负担。

---

## 3. 编译器能做的 — 不用管

| 优化 | 谁做 | 说明 |
|---|---|---|
| 寄存器分配 | GCC/Clang | 自动最优 |
| 循环展开 | GCC/Clang | `-funroll-loops` |
| 向量化 (SIMD) | GCC/Clang | `-ftree-vectorize` |
| 函数内联 | GCC/Clang | `-finline-functions` |
| 常量传播/折叠 | GCC/Clang | 编译期计算 |
| 死代码消除 | GCC/Clang | 自动清理 |
| 分支预测优化 | GCC/Clang | `-fprofile-use` |
| LTO 跨文件优化 | GCC/Clang | `-flto` |

---

## 优先级排序

| 优化 | 难度 | 收益 | 工作量 | 状态 |
|---|---|---|---|---|
| Slab/池分配器 | ⭐⭐⭐ | 巨大 (10-20x) | ~200 行 C | ✅ 已完成（数组池 + 字符串池） |
| t_var 对象池 | ⭐ | 中 (5-10x) | ~50 行 C | ✅ 无需（t_var 栈分配） |
| 批量数组构建 | ⭐⭐ | 中 (5-10x) | ~60 行 CodeGen | ✅ 已完成（预分配容量） |
| SSO 字符串 | ⭐⭐ | 中 (2-3x) | ~80 行 C + types.h 改 | 短期 |
| for 作用域提升 | ⭐ | 低 (消除 bug) | ~30 行 CodeGen | 顺手修 |

---

## 性能实测

100K 次迭代，TCC 编译，对比 PHP 8.x（纳秒）：

| 场景 | PHP | TinyPHP | 比率 |
|---|---|---|---|
| int key 读取 ×100K | 2,982K | 1,111K | **2.7× 快** |
| array_pop ×100K | 4,274K | 2,313K | **1.8× 快** |
| foreach 1K×100K | 1,885,079K | 580,635K | **3.2× 快** |
| 嵌套数组读 ×100K | 3,936K | 1,243K | **3.2× 快** |
| count+for ×100K | 227,532K | 42,192K | **5.4× 快** |
| 数组创建 ×100 | 1,809K | 4,068K | 2.25× 慢* |
| array_push ×100K | 2,169K | 6,112K | 2.82× 慢* |

\* 创建类操作受限于 C `malloc`，PHP 使用 slab 分配器。GCC/Clang 编译可进一步改善。

## 预期最终性能

优化全部落地后，用 GCC -O2 编译：

| 场景 | 当前 vs PHP | 目标 vs PHP | vs Go | vs Rust |
|---|---|---|---|---|
| 整数循环 | 10-25x | 50-100x | ~1x | ~0.5x |
| 数组创建 | 0.03x | 0.3-0.5x | ~1x | ~0.5x |
| 数组读取 | 4x | 10-20x | ~2x | ~0.8x |
| 字符串拼接 | 2-5x | 10-15x | ~1x | ~0.5x |
