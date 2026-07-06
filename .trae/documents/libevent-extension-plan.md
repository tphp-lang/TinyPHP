# ext/libevent 扩展实现计划

## 目标
在 `ext/libevent/` 中用 `tphp_fn_` 前缀的 C 封装函数实现 libevent 的核心 API，符合 TinyPHP AOT 模型。参考原生 PECL-event（`C:\Users\kllxs\Desktop\osmanov-pecl-event-e14e0f5e134e`）的接口设计，但用 C 前缀封装形式（非 phpc 桥接）。

## 设计原则

### 指针存储
- PHP 类用 `public int $ptr` 字段存储 libevent C 指针
- `t_int` = `int64_t`，可安全存储 64 位指针
- 指针 ↔ t_int 转换：`(t_int)(intptr_t)ptr` 和 `(type*)(uintptr_t)$ptr`

### C 函数返回类型策略
| C 返回类型 | tphp_fn_ 签名 | PHP 侧处理 | 原因 |
|---|---|---|---|
| 指针 (event_base*, event*, evbuffer*) | `t_int tphp_fn_...(...)` | 直接赋值给 `$ptr` | codegen 对 C-only `tphp_fn_` 默认推断 `t_int` |
| `const char*` (方法名等) | `const char* tphp_fn_...(...)` | `php_str(C->tphp_fn_...(...))` | codegen 对 `php_str()` 返回 `t_string` |
| `int` (成功/失败) | `t_int tphp_fn_...(...)` | 直接返回 | t_int 兼容 |
| `void` | `void tphp_fn_...(...)` | 忽略返回 | — |

### 回调机制
- 用 `#callback` + `phpc_thunk()` 包装 PHP 闭包为 C 回调
- libevent 回调签名：`void (*)(evutil_socket_t fd, short events, void *arg)`
- `evutil_socket_t`：POSIX=`int`(32位)，Windows=`intptr_t`(64位)
- #callback 声明用 `intptr_t fd` 匹配所有平台（POSIX 上 fd 值小，64位寄存器高位为0，调用约定兼容）
- `void* arg` 参数被 thunk 忽略，用户数据通过闭包 `use()` 捕获

### 常量访问
- libevent 头文件中的宏（`EV_READ`, `EV_WRITE`, `EV_PERSIST`, `EV_ET`, `EV_SIGNAL`, `EV_TIMEOUT` 等）通过 `C->CONST` 语法访问
- 无需在 PHP 侧重新定义

## 文件结构

```
ext/libevent/
├── src/
│   ├── event.h          # 新建 — C 函数声明（tphp_fn_ 前缀）
│   ├── event.c          # 新建 — C 函数实现（包装 libevent API）
│   └── event.php        # 修改 — PHP 类定义 + #include + #flag + #callback
├── include/             # 已有 — libevent 头文件
├── lib/libevent_core.a  # 已有 — 静态库
└── CMakeLists.txt       # 已有
```

## 实现范围（分两阶段）

### Phase 1：核心无回调 API
**EventConfig 类**
- `__construct()` → `event_config_new()`
- `avoidMethod(string $method): bool` → `event_config_avoid_method()`
- `requireFeatures(int $feature): bool` → `event_config_require_features()`
- `setFlag(int $flag): bool` → `event_config_set_flag()`
- `free()` → `event_config_free()`

**EventBase 类**
- `__construct(?EventConfig $cfg)` → `event_base_new_with_config()` / `event_base_new()`
- `loop(int $flags = -1): int` → `event_base_loop()`
- `dispatch(): int` → `event_base_dispatch()`
- `exit(float $timeout = 0): void` → `event_base_loopexit()` (timeout>0) / `event_base_loopbreak()` (timeout=0)
- `stop(): void` → `event_base_loopbreak()`
- `getMethod(): string` → `event_base_get_method()` + `php_str()`
- `getFeatures(): int` → `event_base_get_features()`
- `priorityInit(int $n): bool` → `event_base_priority_init()`
- `free()` → `event_base_free()`

**EventBuffer 类**
- `__construct()` → `evbuffer_new()`
- `add(string $data): bool` → `evbuffer_add()`
- `read(int $maxlen): string` → `evbuffer_remove()` + 分配缓冲区
- `drain(int $len): bool` → `evbuffer_drain()`
- `prepend(string $data): bool` → `evbuffer_prepend()`
- `expand(int $len): bool` → `evbuffer_expand()`
- `getLength(): int` → `evbuffer_get_length()`
- `readLine(int $eol): ?string` → `evbuffer_readln()` + free
- `free()` → `evbuffer_free()`

### Phase 2：回调 API
**Event 类**
- `__construct(EventBase, int $fd, int $what, callable $cb, ?mixed $arg)` → `event_new()` + `phpc_thunk()`
- `add(float $timeout = -1): bool` → `event_add()` + `timeval` 构造
- `del(): bool` → `event_del()`
- `free()` → `event_free()`
- `static timer(EventBase, callable, ?mixed $arg): Event` → `event_new(base, -1, EV_TIMEOUT, ...)`
- `static signal(EventBase, int $signum, callable, ?mixed $arg): Event` → `event_new(base, signum, EV_SIGNAL|EV_PERSIST, ...)`

## 实现细节

### event.h（C 函数声明）
```c
#pragma once
#include "types.h"

// 防止 Windows 头文件冲突 — 必须在 event2/event.h 之前定义
#ifdef _WIN32
#ifndef WIN32_LEAN_AND_MEAN
#define WIN32_LEAN_AND_MEAN
#endif
#endif

#include <event2/event.h>
#include <event2/buffer.h>
#include <event2/util.h>

// EventConfig
t_int event_config_new(void);
void  event_config_free(t_int cfg);
t_int event_config_avoid_method(t_int cfg, t_string method);
t_int event_config_require_features(t_int cfg, t_int feature);
t_int event_config_set_flag(t_int cfg, t_int flag);

// EventBase
t_int event_base_new(t_int cfg);
void  event_base_free(t_int base);
t_int event_base_loop(t_int base, t_int flags);
t_int event_base_dispatch(t_int base);
void  event_base_loopbreak(t_int base);
t_int event_base_loopexit(t_int base, double timeout);
const char* event_base_get_method(t_int base);
t_int event_base_get_features(t_int base);
t_int event_base_priority_init(t_int base, t_int n);

// Event
t_int event_new(t_int base, t_int fd, t_int what, t_int cb_fn, t_int cb_arg);
void  event_free(t_int ev);
t_int event_add(t_int ev, double timeout);
t_int event_del(t_int ev);

// EventBuffer
t_int evbuffer_new(void);
void  evbuffer_free(t_int buf);
t_int evbuffer_add(t_int buf, t_string data);
t_string evbuffer_read(t_int buf, t_int maxlen);
t_int evbuffer_drain(t_int buf, t_int len);
t_int evbuffer_prepend(t_int buf, t_string data);
t_int evbuffer_expand(t_int buf, t_int len);
t_int evbuffer_get_length(t_int buf);
t_string evbuffer_readln(t_int buf, t_int eol);
```

### event.c 实现要点
- `_mk_str(const char*)` helper（从 pcntl.c 复制模式）— 将 C 字符串转为 t_string
- `_timeval_from_double(double)` helper — 将秒数转为 struct timeval
- 指针转换：`CAST_PTR(t, v)` = `(t)(intptr_t)(v)` 和 `BACK_PTR(v)` = `(void*)(uintptr_t)(v)`
- Windows 兼容：所有封装函数对 NULL 指针检查返回错误

### event.php 结构
```php
<?php
// ext/libevent/src/event.php — libevent 事件循环扩展
//
// 本文件不做 phpc 桥接：所有 C 函数使用 tphp_fn_ 前缀直接封装 libevent API。
// PHP 侧通过 $ptr 字段存储 C 指针（t_int = int64_t 可存 64 位指针）。

#include __EXT__ . "/libevent/src/event.h"

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
#flag -levent_core
#ifdef _WIN32
#flag -lws2_32
#flag -ladvapi32
#endif

// libevent 回调签名：fd, events, arg
#callback void event_cb(intptr_t fd, short events, void* arg)

class EventConfig {
    public int $ptr = 0;

    public function __construct() {
        $this->ptr = C->event_config_new();
    }
    public function avoidMethod(string $method): bool {
        return (bool)C->event_config_avoid_method($this->ptr, $method);
    }
    // ... 其他方法
    public function free(): void {
        if ($this->ptr != 0) {
            C->event_config_free($this->ptr);
            $this->ptr = 0;
        }
    }
}

class EventBase {
    public int $ptr = 0;

    public function __construct(?EventConfig $cfg = null) {
        $this->ptr = $cfg !== null
            ? C->event_base_new($cfg->ptr)
            : C->event_base_new(0);
    }
    public function loop(int $flags = -1): int {
        return C->event_base_loop($this->ptr, $flags);
    }
    public function getMethod(): string {
        return php_str(C->event_base_get_method($this->ptr));
    }
    // ...
}

class Event {
    public int $ptr = 0;

    public function __construct(EventBase $base, int $fd, int $what, callable $cb, ?mixed $arg = null) {
        $this->ptr = C->event_new(
            $base->ptr, $fd, $what,
            phpc_thunk('event_cb', $cb), 0
        );
    }
    public static function timer(EventBase $base, callable $cb, ?mixed $arg = null): Event {
        $ev = new Event($base, -1, C->EV_TIMEOUT, $cb, $arg);
        return $ev;
    }
    // ...
}
```

## 测试方案

### 测试文件
`test/ext/libevent_main.php` — 使用 `#debug` 断言 + `#import libevent`

### 测试内容（Phase 1）
1. EventConfig 创建 + free
2. EventBase 创建（带 config 和不带 config）+ getMethod() + free
3. EventBuffer 创建 + add() + read() + getLength() + drain() + free

### 测试内容（Phase 2）
4. timer 事件 — 设置 100ms 定时器，loop 后断言回调触发
5. signal 事件 — POSIX 上测试 SIGUSR1（Windows 上 @skip）

### 测试标记
- Windows 兼容性测试：直接运行
- POSIX only 测试：`#debug` 注释标记，文件头加 `// @skip on Windows`

## 风险与缓解

### 风险 1：TCC 与 GCC 构建的静态库兼容性
- `libevent_core.a` 是 CMake/GCC 构建的 ar 归档
- TCC on Windows 使用 COFF 格式，GCC on Windows 也使用 COFF（mingw）
- **缓解**：先编译最小测试（仅 EventBase 创建 + free），验证链接成功

### 风险 2：Windows 头文件冲突
- `<event2/event.h>` 内部包含 `<winsock2.h>` 和 `<windows.h>`
- 必须在包含前定义 `WIN32_LEAN_AND_MEAN`
- **缓解**：event.h 顶部 `#define WIN32_LEAN_AND_MEAN` + `#include <winsock2.h>` 在前

### 风险 3：evutil_socket_t 类型不匹配
- Windows: `intptr_t` (64位)，POSIX: `int` (32位)
- `#callback` 签名用 `intptr_t fd` 匹配所有平台
- **缓解**：验证 thunk 生成的 C 代码中 fd 参数类型正确

### 风险 4：返回类型注册
- C-only `tphp_fn_` 函数未注册到 `funcRetTypes`，codegen 默认返回 `t_int`
- 对指针/整数返回正确，但 `const char*` 返回需用 `php_str()` 包装
- **缓解**：所有字符串返回的 C 函数用 `const char*` 签名，PHP 侧 `php_str()` 包装

## 实施步骤

1. **创建 `event.h`** — 所有 tphp_fn_ 函数声明 + Windows 头文件防护
2. **创建 `event.c`** — Phase 1 函数实现（EventConfig, EventBase, EventBuffer）
3. **更新 `event.php`** — Phase 1 PHP 类定义 + #flag + #include
4. **编译最小测试** — 验证链接成功（仅 EventBase new + free）
5. **创建测试文件** — Phase 1 完整测试
6. **运行全量回归** — 确保不破坏现有 88 个测试
7. **Phase 2 实现** — Event 类 + #callback + phpc_thunk
8. **Phase 2 测试** — timer 事件测试
9. **更新文档** — EXT_IMPLEMENTATION.md 标记完成，FUNCTIONS.md 新增章节，README.md 更新
