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

## 不包含的文件（及替代方案）

| 原计划文件 | 状态 | 替代方案 |
|-----------|------|---------|
| sokol_sgl.h / sokol_gl.h | 仓库中不存在 | 直接用 sokol_gfx 实现 2D 绘图 |
| fontstash.h | 仓库不可访问 | 自定义位图字体渲染器（ui.h 内实现） |

## 使用方式

在 `ext/ui/src/ui.php` 中通过 `#flag -I__EXT__ . "ui/sokol"` 声明头文件搜索路径，
然后 `#include "sokol_app.h"` 等引入。

sokol IMPL 宏在 `ui.h` 中定义（`SOKOL_APP_IMPL` 等），编译为生成 C 文件的一部分。
