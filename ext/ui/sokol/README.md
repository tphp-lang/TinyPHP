# sokol 单头文件库（UI 扩展专属依赖）

来源：https://github.com/floooh/sokol（master 分支）
许可证：MIT（见 ZLIB 许可证声明）

## 包含的头文件

| 文件 | 用途 |
|------|------|
| sokol_app.h   | 窗口创建、事件循环、输入处理（鼠标/键盘/触摸） |
| sokol_gfx.h   | 图形渲染（pipeline/buffer/shader/pass） |
| sokol_glue.h  | sokol_app ↔ sokol_gfx 桥接（sapp_sgcontext） |
| sokol_log.h   | 日志回调（sokol 函数错误输出） |
| sokol_time.h  | 高精度计时（帧时间测量） |

## 平台后端

| 平台 | sokol 后端 | 说明 |
|------|-----------|------|
| Windows | `SOKOL_GLCORE` | GL 3.3 Core（TCC 缺 `windowsx.h`，用 OpenGL 而非 D3D11） |
| Linux | `SOKOL_GLCORE` | GL 3.3 Core |
| macOS | `SOKOL_METAL` | Metal |
| Android | `SOKOL_GLES3` + `SOKOL_NO_ENTRY` | GLES 3.0（NativeActivity 模式，入口为 `sokol_main()` 而非 `main()`） |

## 不包含的文件（及替代方案）

| 原计划文件 | 状态 | 替代方案 |
|-----------|------|---------|
| sokol_sgl.h / sokol_gl.h | 仓库中不存在 | 直接用 sokol_gfx 实现 2D 绘图 |
| fontstash.h | 仓库不可访问 | 内置 font8x8 点阵字体表（ui.h 内实现，跨平台通用，含 Android） |

## 使用方式

在 `ext/ui/src/ui.php` 中通过 `#flag -I__EXT__ . "ui/sokol"` 声明头文件搜索路径，
然后 `#include "sokol_app.h"` 等引入。

sokol IMPL 宏在 `ui.h` 中定义（`SOKOL_APP_IMPL` 等），编译为生成 C 文件的一部分。
