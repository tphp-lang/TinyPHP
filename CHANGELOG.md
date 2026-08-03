# Changelog

本文件记录 TinyPHP 的版本变更历史。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/)。

---

## [0.2.0-beta.9] — 2026-08-02

### 新增

- **Android NDK 交叉编译支持**（`tphp.php` + `ext/ui/`）：新增 `-os android` 编译目标，使用 NDK Clang 工具链将 PHP 编译为 Android 共享库（`libtphp.so`）并打包为 APK。
  - **环境变量**：`ANDROID_NDK`（必需，NDK 根目录）、`JAVA_HOME`（JDK 17/21，APK 打包必需，Java 24+ 不兼容 Gradle 8.9）、`ANDROID_HOME`（Android SDK 路径）、`TPHP_ANDROID_API`（默认 24 = Android 7.0）
  - **多 ABI 编译**：默认编译全部 4 种 ABI（arm64-v8a / x86_64 / armeabi-v7a / x86），覆盖真机与模拟器；可通过 `-arch` 指定单 ABI（`aarch64`/`x86_64`/`armv7a`/`i686`）
  - **APK 输出机制**：通过子进程递归调用编译所有 ABI 的 `libtphp.so`（输出到 `build/android/jniLibs/<abi>/`），随后调用 Gradle 打包并复制 APK 到当前工作目录（`<baseName>-debug.apk`，遵循 `-o` 机制，如 `-o myapp` → `myapp-debug.apk`）；`ext/ui/android/` 仅作为工程模板，不写入任何产物
  - **NDK 工具链**：自动检测 NDK 路径，添加 `-D__ANDROID__` 宏定义和 NDK 内置 sysroot；链接 `-landroid -lEGL -lGLESv3 -llog`
  - **条件编译**：新增 `#if Android` 标识符（Lexer/Parser 支持 `android` 平台前缀识别）
  - **Java 版本兼容**：tphp.php 自动检测 Java 版本，Java 24+ 与 Gradle 8.9/AGP 8.7.0 不兼容时搜索 Java 17/21 LTS 并通过 `putenv('JAVA_HOME=...')` 切换
  - **SDK 许可自动补写**：检测 `android-sdk-license` 文件缺失哈希时自动追加 `d56f5187479451eabf01fb78af698cb`（SDK 34 许可）
  - **SDK source.properties 临时重命名**：构建前临时将 SDK 根目录的 `source.properties` 重命名为 `.tphp_bak`，避免 AGP LegacyLocalRepoLoader 误判根目录为 package 而无法发现 `platforms/` 下的平台包；构建后恢复
  - **国内镜像加速**：Gradle 配置腾讯云 Gradle 镜像和国内 Maven 镜像，解决 SSL 证书和下载速度问题
  - **Android 工程模板**：`ext/ui/android/` 包含 Gradle Wrapper、`build.gradle`、`settings.gradle`、`AndroidManifest.xml`、`MainActivity.kt` 等 8 个文件，`compileSdk/targetSdk/buildToolsVersion` 统一为 35/35/35.0.0
  - **.gitignore**：排除 `local.properties`（包含开发者个人 SDK 路径）

- **Android 平台适配**（`ext/ui/src/ui.h`）：移动端 UI 适配，包括 JNI 软键盘、触摸事件转换、原生按键事件拦截、stdout 重定向等。
  - **JNI 软键盘桥接**：通过 JNI 调用 Android `InputMethodManager` 显示/隐藏软键盘。`_ui_android_show_softinput`/`_ui_android_hide_softinput` 通过 `AttachCurrentThread` 安全附加线程，`_ui_jni_check` 检查异常并释放局部引用，避免引用表溢出
  - **触摸事件 → 鼠标事件转换**：sokol 在 Android 上生成 `TOUCHES_BEGAN/ENDED/MOVED` 事件，`_ui_sokol_event_cb` 中将首个触摸点转换为 `MOUSE_DOWN/UP/MOVE` 事件，使桌面端 PHP 代码无需修改即可在 Android 上响应触摸
  - **原生按键事件拦截**：sokol 的 `_sapp_android_key_event` 只处理 BACK 键，其他按键直接丢弃导致 TextBox 无法输入。通过 `native_event_cb` 钩子拦截 `AInputEvent`，`_ui_android_map_keycode` 将 `AKEYCODE_*` 映射为 PHP Key 枚举的 ASCII 值，构造 `sapp_event` 并调用 `_ui_sokol_event_cb` 分发；对可打印字符（ASCII 32-126）额外生成 `CHAR` 事件驱动文本输入
  - **GLES3 后端**：Android 使用 `SOKOL_GLES3` 后端和 `SOKOL_NO_ENTRY`，通过 `sokol_main()` 作为入口（而非 `main()`）。GL 版本必须为 GLES 3.0（不能用桌面 GL 3.3，否则 `eglCreateContext` 失败秒退）
  - **NativeActivity 生命周期**：Android 入口为 `ANativeActivity_onCreate`（sokol 提供），此时 PHP 的 `Main::main()` 未执行。`sokol_main()` 首次调用时执行 `tphp_android_main()` 填充 desc；CodeGenerator 在 Android 模式下生成 `tphp_android_main()` 替代 `main()`，且不释放 Main 对象和 `_argv`（避免闭包悬垂指针）
  - **stdout/stderr 重定向**：Android NativeActivity 的 stdout 默认不输出到 logcat。用 pipe + 后台线程将 stdout/stderr 重定向到 `__android_log_write`，便于调试
  - **默认竖屏**：`AndroidManifest.xml` 中 `android:screenOrientation` 设置为 `portrait`（非 `landscape`）
  - **字体渲染**：使用内置 font8x8 点阵字体表，跨平台通用（替代 Win32 GDI 字体生成，Android 上不可用）

- **ext/ui GPU 即时模式形状渲染器**（`ext/ui/src/ui.h`）：实现基于 sokol_gfx 的即时模式形状渲染，补全 GPU 后端的 `fill_rect`/`draw_line`/`draw_rect`/`draw_circle`（此前 GPU 后端仅实现 `begin_pass`/`end_pass`，形状绘制抛异常提示）。
  - **GPU 后端实现**：内存顶点缓冲区收集形状数据，每帧 `sg_apply_pipeline` + `sg_update_buffer` 上传 GPU 绘制。着色器使用 sokol shader 生成（顶点位置 + 颜色）。`usage.stream_update=true` 适配新版 sokol_gfx API
  - **sokol_gfx 新版 API 适配**：`_sr_init()` 适配新版 sokol_gfx API，使用结构体字段（`.usage.stream_update`、`.vertex_func`/`.fragment_func`）替代旧版枚举值
  - **DrawDevice 抽象**见下方「变更」部分「ext/ui GPU → CPU 软件渲染自动回退」条目

- **ext/ui**（`ext/ui/`）：基于 sokol C 库的跨平台图形界面扩展（纯 phpc 模式）。9 个 PHP 类 + 9 个枚举，覆盖窗口管理、2D 绘图、控件体系、布局系统和事件处理。
  - **底层依赖**：内置 sokol C 库源码（sokol_app/sokol_gfx/sokol_glue/sokol_log/sokol_time），位于 `ext/ui/sokol/`，零运行时依赖
  - **平台后端**：Windows/Linux = OpenGL (SOKOL_GLCORE)，macOS = Metal (SOKOL_METAL)，Android = GLES3 (SOKOL_GLES3)。TCC 缺失 `windowsx.h`，故 Windows 使用 OpenGL 而非 D3D11
  - **核心类**：`App`（窗口生命周期 + onInit/onFrame/onEvent 回调）、`Window`（静态：width/height/dpiScale/setCursor）、`Event`（fromPtr 解析 sapp_event 指针）、`Color`（RGBA + toUint 0xAABBGGRR）、`Rect`（contains 命中测试）、`Graphics`（静态：clear/fillRect/drawText/drawLine/drawRect/drawCircle）
  - **控件体系**：`Widget` 抽象基类（init/draw/setPos/proposeSize/pointInside + 事件方法）、`WidgetContainer`（addChild/drawAll/hitTestIndex/dispatch*）、`Button`（press/release/click + onClick）、`Label`、`TextBox`（focus/blur/handleKeyDown/handleChar）、`CheckBox`（toggle/setChecked + onChange）、`Slider`（beginDrag/drag/endDrag/setValue + 值夹紧）
  - **布局系统**：`Layout` 抽象基类（addWidget/updateLayout/asWidget）、`Stack`（flex 风格，row/column 静态方法，Compact/Stretch/Fixed 尺寸模式）、`CanvasLayout`（绝对定位）
  - **软键盘桥接**：`SoftInput` 静态类（show/hide/isVisible/onInput/dispatch/clear），桌面端 show/hide 为 no-op，Android 端通过 JNI 调用 `InputMethodManager` 实现（详见上方「Android 平台适配」）
  - **枚举**：EventType（22 值）/ Key（ASCII+VK_*）/ MouseButton / KeyMod / Cursor / Direction / WidgetState / LayoutAlign / ChildSize
  - **绘图契约**：所有 `Graphics::*` 方法必须在 `onFrame` 回调内调用，否则抛 `Exception("drawing outside frame callback")`
  - **事件契约**：sokol `sapp_event*` 指针以 `t_int` 流转（intptr_t 转换），PHP 侧通过 `Event::fromPtr($evPtr)` 解析
  - **回调安全**：`onInit`/`onFrame`/`onEvent`/`SoftInput::onInput` 注册的闭包通过 `phpc_env_pin` 钉在全局 pin 表，防异步回调 UAF
  - **TCC Windows 兼容**：`RegisterRawInputDevices`/`GetRawInputData` 在 user32.def 缺失，提供 stub 满足链接器（仅 mouse_lock 时调用，UI 测试不使用）
  - 测试：`test/ui/ui_color_test.php`（Color 单元测试）+ `test/ui/ui_widget_smoke_test.php`（Widget/Layout 冒烟测试）+ 4 个集成测试（`ui_basic.php`/`ui_events.php`/`ui_widget_render.php`/`ui_layout_render.php`，标记 `@skip` 需图形环境）

- **异步与协程通信库**（`include/object/channel.h`）：参考 vlang 设计的 CSP 风格异步通信原语，采用 tphp OOP 思想。1 个全局函数 + 2 个类 + 2 个异常类型。
  - **Channel 类**：CSP 风格有界通道，环形缓冲区实现（push/pop 零 malloc），阻塞前自旋 750 次以减少 syscall。9 个方法：`__construct/push/pop/tryPush/tryPop/close/isClosed/length/capacity`
  - **Future 类**：一次性异步结果传递机制，支持 await/then/catch 链式调用和 all/race 组合器。10 个方法：`create`（静态）/`resolve/reject/await/isReady/isRejected/then/catch` + 静态 `all/race`
  - **chan_select 函数**：多通道多路复用，支持超时机制（`chan_select(array $channels, int $timeout_ms = -1): int`）。返回就绪通道索引，全关闭返回 -2，超时返回 -1
  - **异常类型**：`ChannelClosedException`（push 到已关闭通道时抛出）、`FutureRejectedException`（await 被 reject 的 Future 时抛出）
  - **内存安全契约**：t_var 值在 push 时 `_arr_val_retain`，pop 时不额外 retain；close 时遍历释放剩余元素；dtor 保证即使忘记 close 也释放所有资源；Future resolve/reject 时 retain，await 返回时不额外 retain
  - **性能优化策略**：push/pop/await 阻塞前自旋 750 次以减少 syscall；Channel 使用固定容量环形缓冲区实现零 malloc；isReady/isClosed/length 采用无锁原子读；chan_select spin 间隔用 thrd_yield 避免空转
  - 测试：`test/thread/test_channel.php`（11 节，基本收发/跨线程/有界容量/tryPush/tryPop/close 唤醒/剩余元素/异常）+ `test/thread/test_future.php`（8 节，resolve+await/reject+异常/跨线程/then/catch/all/all-reject/race）+ `test/thread/test_chan_select.php`（4 节，就绪通道/超时/全关闭/跨线程 push）
  - 同步修复编译器 bug：CodeGenerator 中 `$t_var === null` 误生成 `t_var == null`（结构体与指针比较），修正为 `t_var.type == TYPE_NULL`；Parser 中 `catch` 关键字不允许作为方法名（影响 `Future::catch()`），添加 `CATCH_KW` 到合法方法名 token 列表

- **ext/pgsql**（`ext/pgsql/`）：PostgreSQL 扩展（纯 C 协议实现，支持 trust/md5/SCRAM-SHA-256 认证）
  - 78 个 pg_* 函数（连接/查询/预处理/结果集/COPY/DML/Large Object/持久连接/通知回调）
  - 约 60 个常量
  - 参考 vlang Pool+IdleSlot 模式实现 pg_pconnect 持久连接池
  - 基于 tphp_class_Resource 实现 pg_lo_open 返回 Resource
  - 基于 t_callback 实现 pg_set_notice_callback
- **ext/pdo_pgsql**（`ext/pdo_pgsql/`）：PostgreSQL PDO 驱动
  - 复用 ext/pgsql 协议实现
  - pdo_pgsql_get_pid/get_notify/pgconn 函数

- **GD 扩展**（`ext/gd/`）：纯 phpc 实现图像处理，**不依赖 libgd / libpng / libjpeg / libfreetype**。~90 个函数 + 89 个常量 + 2 个类（GdImage / GdFont），~6000 行 phpc。
  - **支持格式**：PNG（zlib 压缩）/ GIF（LZW 编解码）/ BMP（含 RLE8/4 压缩）/ GD / GD2（RAW 模式）/ WBMP / XBM / TGA（type 2/10，24/32bpp，RLE）共 8 种完整编解码
  - **不支持格式**：JPEG / WebP / AVIF / XPM / FreeType 字体渲染（调用时抛 `RuntimeException` 明确报错，不静默返回 false）
  - **功能**：创建/颜色管理（17 个）/ 绘图（15 个，Bresenham 直线/弧形/椭圆/扫描线填充多边形）/ 字体（7 个）/ 复制缩放（5 个，双线性插值）/ 变换（8 个，仿射矩阵）/ 滤镜（13 种 + 卷积 + 伽马校正）/ 状态属性（14 个）/ 编解码（16 个支持 + 13 个不支持 stub）
  - **gd_info/imagetypes 真实反映能力**：JPEG/WebP/AVIF/XPM/FreeType Support 为 false
  - 测试：`test/gd/`（17 个测试文件，762 断言），TCC/GCC 16.1.0/Clang 22.1.7 全部通过

- **cURL 扩展**（`ext/curl/`）：纯 phpc 实现 HTTP/HTTPS 客户端，**不依赖 libcurl C 库**。35 个函数 + 690 个常量 + 6 个类（CurlHandle/CurlMultiHandle/CurlShareHandle/CurlSharePersistentHandle/CURLFile/CURLStringFile）。
  - **协议**：仅 HTTP/HTTPS（其他协议返回 CURLE_UNSUPPORTED_PROTOCOL），TLS/SSL 复用 ext/openssl（mbedTLS 3.6.6）
  - **Socket**：复用 ext/stream 的 socket 抽象
  - **认证**：仅 CURLAUTH_BASIC
  - **文件上传**：支持 multipart/form-data（CURLFile 磁盘文件 + CURLStringFile 内存字符串）
  - **功能**：curl_init/exec/setopt/setopt_array/getinfo/error/errno/strerror/version/escape/unescape/copy_handle/reset/pause/upkeep + curl_file_create + curl_multi_getcontent
  - **Stub**：curl_multi_add_handle/remove_handle/exec/select/info_read/setopt 和 curl_share_setopt/init_persistent 抛异常（不支持并行/共享）
  - **chunked 解码**：支持 Transfer-Encoding: chunked 响应解码
  - **重定向**：支持 FOLLOWLOCATION + MAXREDIRS 重定向跟随
  - 测试：`test/curl/curl_unit.php`（201 用例，无网络）+ `test/curl/curl_stub_test.php`（38 用例，无网络）+ `test/curl/curl_basic.php`（15 段 17 用例，@skip 需网络）
  - 三编译器验证：TCC/GCC 16.1.0/Clang 22.1.4 全部编译通过

- **`array<T>` 泛型数组类型系统**（`types.h`/`array.h`/`Type.php`/`TypeChecker.php`/`CodeGenerator.php`）：
  - **特化数组结构**：`array<T>` 在编译期单态化为独立 C 类型（`t_arr_int`/`t_arr_str`/`t_arr_float`/`t_arr_bool`/`t_arr_var`/`t_arr_ptr`），元素紧凑存储（`array<int>` 的 value 是 8 字节 `t_int`，比 `array<mixed>` 的 24 字节 `t_var` 节省 67%）
  - **协变转换机制**：`array<T>` 传给 `array<mixed>` 参数时自动调用 `tphp_fn_arr_{int|str|float|bool}_to_var`（O(n) 开销，重新分配 + 元素包装为 `t_var`）。内置数组函数通过 `arrayArgCode` 统一协变转换调用
  - **元素类型追踪**：扩展 `$builtinArrElemTypes` 注册表 + `visitAssign` 动态推导，覆盖 `array_keys`/`array_values`/`array_merge`/`array_slice`/`array_unique`/`array_reverse`/`array_diff`/`array_intersect`/`array_pad`/`array_combine`/`array_chunk`/`array_column`/`array_fill`/`array_count_values`/`str_split`/`parse_str`/`parse_url`/`iconv_get_encoding` 等函数的返回数组元素类型
  - **特化排序/洗牌**：实现 `tphp_fn_arr_{int|str|float|bool}_{sort|rsort|shuffle}` 直接操作特化数组内存，避免协变转换丢失修改
  - **类型严格性**：显式声明 `array<T>` 后 push 不同类型 → 编译错误；无注解 `array` 默认推导为 `array<mixed>` 保持 PHP 动态语义
  - **拒绝 asort/arsort/ksort/krsort/uasort/usort**：这些保持 key-value 关联的函数对 `array<T>` 抛编译期异常（不适用于有序列表）
  - 测试：`test/type/array_generic_test.php`（基础功能）+ `test/type/array_generic_funcs_test.php`（内置函数全覆盖）

### 变更

- **ext/ui 异常处理修复**（`ext/ui/src/ui.h`）：解决 UI 测试窗口秒退且无错误输出的静默崩溃问题。
  - **sokol panic 不再 `abort()`**：新增自定义 `_ui_slog_func` 替代 `sokol_log.h` 的 `slog_func`。原实现中 sokol panic（如 `WIN32_WGL_FIND_PIXELFORMAT_FAILED`）会调 `abort()` 直接杀死进程（exit code 3），无任何错误输出。新实现 panic 级别输出到 stderr 后调 `tp_throw`（可被 try-catch 捕获），错误信息可见
  - **C 回调异常捕获**：三个 sokol 回调（`_ui_sokol_init_cb`/`_ui_sokol_frame_cb`/`_ui_sokol_event_cb`）均包裹 `TP_TRY`/`TP_CATCH_ANY`。原实现中 PHP 回调内 `tp_throw` 触发 `longjmp` 会跳过 sokol 事件循环导致静默崩溃。捕获后输出到 stderr 并调 `sapp_request_quit()` 干净退出
  - **`ui_app_run` 异常包裹**：用 `TP_TRY`/`TP_CATCH_ANY` 包裹 `sapp_run`，sokol 初始化 panic 转为返回 -1，而非走 `tp_throw` 无帧分支 `exit(1)`
  - **pass 自动收尾**：`ui_state_t` 新增 `pass_active` 字段跟踪 `sg_begin_pass` 状态。frame 回调末尾自动调用 `sg_end_pass`+`sg_commit`，即使 PHP 回调中途抛异常也不会漏掉，防止 sokol 状态不一致导致下一帧渲染崩溃
  - **`sapp_desc` 配置 logger**：原 `sapp_desc` 未配 `.logger`，sokol 错误无处输出。现已配置 `.logger = { .func = _ui_slog_func }`；`sg_desc` 同步配置
  - **GL 版本降级**：从 sokol 默认 4.3 降到 3.3 Core，覆盖绝大多数桌面 GPU
  - **参数校验**（不静默处理）：`ui_app_run` 校验 width/height > 0；`ui_window_set_cursor` 校验 cursor < `_SAPP_MOUSECURSOR_NUM`；所有 `ui_event_*` 函数 NULL 指针从返回 0 改为 `tp_throw`；`ui_end_frame` 静默 return 改为 `tp_throw`
  - 测试验证：`test/ui/ui_basic.php` 编译通过，WGL panic 错误信息可见（`[sapp][panic] WIN32_WGL_FIND_PIXELFORMAT_FAILED: failed to find matching WGL pixel format`），进程正常退出（exit code 0）

- **ext/ui GPU → CPU 软件渲染自动回退**（`ext/ui/src/ui.h` / `ext/ui/src/ui_cpu.h`）：无硬件 GPU 环境（RDP/虚拟机）下 sokol WGL 像素格式失败时，自动回退到 CPU 软件渲染后端，窗口正常显示，对 PHP 侧透明。
  - **DrawDevice 抽象**（`ui_draw_device_t`，参考 vlang/ui 的 DrawDevice 接口设计）：定义 `begin_pass`/`end_pass`/`fill_rect`/`draw_text`/`draw_line`/`draw_rect`/`draw_circle` 函数指针表。`ui_clear`/`ui_fill_rect` 等公共 API 通过 `_ui_state.device` 分派到当前后端（`_ui_sokol_device` 或 `_ui_cpu_device`），实现后端解耦
  - **后端自动选择**：`ui_app_run` 先尝试 sokol（GPU）后端，`sapp_run` 的 panic 经 `_ui_slog_func` 转为 `tp_throw`，被 `TP_CATCH_ANY` 捕获后重置状态、切换 `backend=UI_BACKEND_CPU`、`device=&_ui_cpu_device`，调用 `_cpu_app_run` 回退。输出 `[ui] GPU backend unavailable, falling back to CPU software renderer`
  - **CPU 软件渲染后端**（`ui_cpu.h`，Windows 实现）：Win32 窗口 + `CreateDIBSection` 帧缓冲（top-down 32bit BGR）+ GDI `BitBlt` 显示。`fill_rect` 直接操作帧缓冲像素；`draw_line` 用 Bresenham 算法；`draw_rect` 绘制矩形边框；`draw_circle` 用中点圆算法；`draw_text` 用 GDI `TextOut`（系统字体，无需内嵌字体）。事件循环用 `PeekMessage`，事件构造 `sapp_event` 结构保持与 sokol 后端兼容（PHP 侧 `Event::fromPtr` 无需改动）。窗口查询函数（`ui_window_width` 等）按 `backend` 分派到 `sapp_*` 或 `_cpu_window_*`
  - **sokol 后端形状绘制**：`begin_pass`/`end_pass` 已实现（复用 `sg_begin_pass`/`sg_end_pass`/`sg_commit`）；`fill_rect` 等形状绘制在 GPU 后端尚未实现时抛异常提示（无 GPU 环境会自动回退到 CPU 后端，CPU 后端全部已实现）
  - macOS Metal 总是可用（含软件回退），无需 CPU 后端；Linux 桌面通常有 GPU 或 llvmpipe，X11 CPU 后端预留未实现
  - 测试验证：`test/ui/ui_basic.php` 在无 GPU 环境下编译运行，sokol WGL panic 后自动回退到 CPU 后端，窗口正常显示（640x480 黑色背景 + 红色矩形 + 白色文本），不再秒退

- **扩展 `$builtinArrElemTypes` 注册表**（`CodeGenerator.php`）：
  - 新增 `array_fill`→`t_var`、`array_column`→`t_var`（元素通过 VAR_* 包装存储）
  - 新增 `str_split`/`parse_str`/`parse_url`/`iconv_get_encoding`→`t_string`（内部 VAR_STRING 存储）
  - 新增 `array_count_values`→`t_int`（值是出现次数）
  - 在 `visitAssign` 中为 `array_combine`（从 values 推导）、`array_chunk`（外层 t_array*）、`array_reverse`/`array_diff`/`array_intersect`/`array_pad`/`array_merge`/`array_values`/`array_slice`/`array_unique`（跟随源数组）添加动态元素类型推导，覆盖硬编码默认值
  - 修正 `test/misc/tier2_stdlib.php` 的 str_split 预期输出（`int(0)` → `string(1) "a"`，之前是 bug 的体现）

### 修复

- **CodeGenerator**：修复 `tphp_fn_` 前缀函数在通用回退路径被重复添加前缀的 bug
- **对象唯一 ID 机制**（`object.h` / `tls.h` / `core.h`）：`t_object` 新增独立 `id` 字段（uint32_t），由线程本地全局计数器 `_tphp_obj_id_counter` 递增生成，替代原来用 `refcount & 0xFFFF` 作为对象 ID 的做法。`var_dump` 输出 `object(Class)#<id>` 时改用 `obj->id`，符合标准 PHP 每个对象实例拥有唯一 ID 的行为。原方案在数组 retain 引入后 refcount 不再恒为 1，导致 `var_dump` 输出的 ID 不稳定。
- **数组元素引用计数系统性修复**（`array.h`）：`tphp_fn_arr_push` / `tphp_fn_arr_set_int` / `tphp_fn_arr_set_str` 在存储数组/对象类型值时调用 `_arr_val_retain` 增加引用计数，覆盖已有键时先 `_arr_val_release` 旧值再 retain 新值。修复 `PDOStatement::fetchAll` 中 `result[] = row` 后 `row` 被重赋值 free 导致 `result` 残留悬垂指针的 use-after-free（表现为 `fetchall_count=3` 但 `fetchall_0=` 空）。
- **数组重赋值自赋值 guard 双重释放**（`CodeGenerator.php`）：当数组变量同时作为函数参数且为赋值目标时（如 `$result = exif_parse_ifd(..., $result, ...)`），函数内部可能 `realloc` 了 `$var` 指向的内存，旧指针已失效，此时不能再 `tphp_fn_arr_free` 旧指针。新增 `exprIsCallWithVarArg` 检测赋值表达式是否为函数调用且参数列表包含被赋值变量，命中时跳过旧指针释放，避免双重释放导致的堆损坏（`STATUS_HEAP_CORRUPTION` / `Segmentation fault`）。
- **注解 `newInstance()` 类型推断**（`CodeGenerator.php`）：`foreach (ROUTE as $v) { $v->newInstance(); }` 中 `$v` 来自 foreach 遍历注解数组时，通过 `varAnnotSource` 追踪来源注解常量，正确推导 `newInstance()` 返回的类指针类型。修复 Linux/macOS 上 `Call to undefined method t_int::hello()` / `void::hello()` 转译错误。
- **`stream_socket_accept` 错误处理契约**（`stream.h`）：真正的错误（accept 失败等）抛异常供用户 try-catch 捕获，EAGAIN/超时等非错误返回 `-1`，符合 AOT 错误处理原则（用户可通过异常捕获真正的错误，而非静默返回 false）。
- **非阻塞 socket EAGAIN/EWOULDBLOCK/EINTR 处理**（`stream.h`）：`stream_socket_accept`/`stream_socket_recvfrom`/`stream_socket_sendto` 在非阻塞模式下遇到 EAGAIN/EWOULDBLOCK/EINTR 时返回错误值（`-1`/空字符串）而非抛异常，符合非阻塞 I/O 模型（这些是"预期的非阻塞状态"，由事件循环重试，而非真正错误）。新增 `STREAM_EINTR` 跨平台宏（Windows `WSAEINTR` / POSIX `EINTR`），同时修复 Windows 下 `EINTR` 未定义的编译错误。修复 Workerman 事件循环因未捕获 EAGAIN 异常导致进程崩溃（`STATUS_ACCESS_VIOLATION`）的问题。测试：`test/stream/stream_nonblock_eagain.php`。
- **未捕获异常 use-after-free 崩溃**（`try.h`）：`tp_throw_ex`/`tp_throw` 宏在未捕获异常路径中先调用 `tphp_rt_free_all()` 释放异常对象，再访问 `_e->message` 打印错误消息，导致 use-after-free（进程以 `STATUS_ACCESS_VIOLATION` 退出，无任何错误信息）。修复：先将异常消息提取到 malloc 缓冲区，打印后再 `tphp_rt_free_all()`。修复后未捕获异常会打印 `Fatal error: Uncaught exception: <msg>` 并 `exit(1)`。测试：`test/error/uncaught_exception_msg.php`。
- **数组对象属性访问 getter 类型推断**（`CodeGenerator.php`）：`visitArrayAccess` 中未类型化 `array` 属性（如 `public array $conns = []`）的元素类型推断默认走 `tphp_fn_arr_get_int_int`（返回 0/NULL），导致后续对象成员访问空指针解引用崩溃。修复：当 TypeChecker 推导元素类型为 `void*`（对象）时，生成 `tphp_fn_arr_get_int_object` 调用提取对象指针。修复 Workerman `ConnectionPool::$connections[$id]` 返回 NULL 导致 `handleRead` 崩溃的问题。测试：`test/array/prop_array_object_getter.php`。
- **macOS `-framework` 链接支持**：TCC 不识别 macOS 的 `-framework X` 语法（会把 `X` 当作输入文件，报错 `file 'OpenGL' not found`）。`tphp.php` 在 #flag 处理中自动把 `-framework X` 转换为 `-Wl,-framework,X`（透传给系统 `ld`），`-F path` 同理转换为 `-Wl,-F,path`。`-Wl,` 开头的 token 分离到 `$lateLinkFlags`（链接器选项放在源文件之后，遵循单遍扫描顺序）
- **gcc/clang 下特化排序/洗牌函数指针类型不兼容**（`array.h`）：`_TPHP_ARR_TYPED_SORT` 和 `_TPHP_ARR_TYPED_SHUFFLE` 宏在特化数组（`t_arr_int*`/`t_arr_str*` 等）上直接调用 `arr_stridx_free`/`arr_intidx_free`，但这两个函数接收 `t_array*`，gcc/clang 严格检查 `-Wincompatible-pointer-types` 报错。修复：调用前显式 cast 为 `(t_array*)`。特化数组结构与 `t_array` 前 6 个字段布局完全一致，cast 安全。TCC 比较宽松未报错，本地测试未发现。CI Windows gcc 全部 247 个测试编译失败，clang 因超时被取消。
- **`isset`/`empty` 返回类型推导缺失**（`CodeGenerator.php`）：`inferCallReturnType()` 未覆盖 `isset`/`empty`，导致使用这些函数的表达式触发 "Unknown function return type" 编译错误。修复：在类型推导路径中添加 `isset`/`empty` 返回 `t_bool` 的处理。
- **mbedTLS `mbedtls_test_get_last_error` 声明顺序**（`openssl.h`）：函数在声明前被调用，导致 gcc/clang 隐式非静态声明与后续 `static inline` 声明冲突。修复：将声明移到使用之前。
- **mbedTLS `mbedtls_ssl_get_ciphersuite` 参数类型错误**（`openssl.h`）：Clang 22 将 int→pointer 转换视为 error。修复：直接调用 `mbedtls_ssl_get_ciphersuite(&c->ssl)` 获取名称，而非先取 ID 再传指针。
- **gcc/clang 静态库归档命令不兼容**（`tphp.php`）：TCC 支持 `-ar cr` 创建静态库，但 gcc/clang 不识别 `-ar` 选项。修复：TCC 保持原逻辑，gcc/clang 改用系统 `ar` 命令（优先从编译器 bin 目录查找）。
- **mbedTLS `aesni.c` 源文件缺失**（`tphp.php`）：`mbedtls_config.h` 在非 TCC 的 x86_64 平台启用 `MBEDTLS_AESNI_C`，但源文件列表未包含 `aesni.c`，导致 gcc/clang 链接时 `mbedtls_aesni_*` 符号未定义。修复：在 `$mbedtlsSrcFiles` 中添加 `aesni.c`。

## [0.2.0-beta.3] — 2026-07-19

### 新增

- **PDO 扩展**（SQLite 驱动）：首个基于类的扩展实现，含 `PDO` + `PDOStatement` 两个类，16 + 17 = 33 个方法。SQLite amalgamation 3.46.0 静态编译，零运行时依赖。
  - **AOT 类型安全**：所有方法参数/返回值使用 tphp 具体类型（int/string/array/bool），不使用 `mixed`/`t_var`。PHP 原生 `mixed` 方法按类型拆分（`bindValueInt`/`bindValueStr`/`bindValueNamedInt`/`bindValueNamedStr`，`getAttributeStr`/`getAttributeInt`/`getAttributeBool`，`fetchColumnStr`/`fetchColumnInt`）
  - **指针 ↔ int 桥接**：sqlite3*/sqlite3_stmt* 指针以 `t_int` 存储在 PHP 类字段中，方法内部用 `phpc_int_to_ptr` 转回 `C.void*` 调用 SQLite C API
  - **错误处理**：所有错误抛 `Exception`（`tp_throw_ex`），可被 `try-catch` 捕获
  - 测试：`test/pdo/pdo_basic.php`（19 节覆盖连接/exec/prepare/位置绑定/命名绑定/execute(array)/fetch 模式/fetchAll/fetchColumn/事务/lastInsertId/rowCount/quote/getAttribute/setAttribute/errorCode/getColumnMeta/closeCursor 复用/错误处理/静态方法/NULL/float 列）
- **stream 扩展对标 PHP 原生补全**（新增 6 个函数，总数 15 → 21）：
  - `stream_set_write_buffer(int $fd, int $buffer): int` — 设置写缓冲（socket 无 stdio 缓冲，stub 返回 0）
  - `stream_set_timeout(int $fd, int $seconds, int $microseconds = 0): bool` — 设置读写超时（`setsockopt(SO_RCVTIMEO/SO_SNDTIMEO)`）
  - `stream_get_contents(int $fd, int $length = -1, int $offset = -1): string` — 读取剩余所有数据（`length=-1` 读全部，`offset=-1` 从当前位置）
  - `stream_get_line(int $fd, int $length, string $ending = ""): string` — 读到分隔符或长度（不返回 ending）
  - `stream_get_meta_data(int $fd): array` — 获取流元数据（`timed_out`/`blocked`/`eof`/`stream_type`/`unread_bytes`/`seekable`）
  - `stream_socket_pair(int $domain, int $type, int $protocol): array` — 创建一对互连的 socket（POSIX `socketpair()`，Windows 用 TCP 回环模拟）
- **统一 Response File 机制**（`tphp.php`）：当总命令行长度超过 8000 字符时，自动把可变参数（`-I`/`-D`/`-O`/源文件/`-L`/`-l`/`.a`）全部写入 `@file`，保留核心参数（编译器路径、`-B`、内置 `-I`、`-o`）在命令行。TCC/GCC/Clang 均支持 `@file` 语法。彻底解决 Windows CreateProcess 32767 字符限制问题。
  - 覆盖主编译路径和 TCC `.a` fallback 路径
  - 修复旧机制仅覆盖 `$extraSrcs` 且会被 zlib 检测覆盖的 bug
  - 修复 fallback 路径丢失 `-shared` 标记的潜在 bug

### 变更

- **`C->` 调用强制类型声明**（AOT 类型安全）：`C->func()` 和 `C->CONST` 赋值给变量时**必须显式声明类型**，否则编译错误。消除了 `$ptrFns` 白名单和默认 `t_int` 假设，编译期即捕获类型错误。
  - 语句上下文（`C->foo();`）无需声明
  - 赋值上下文：`int $rc = C->foo();` / `C.void* $p = C->foo();` / `float $x = C->foo();`
  - 表达式上下文：用 cast 包装（`php_int(C->foo())`）或先赋值给类型化变量
  - 影响：16 处旧代码需补充类型声明（1 处 ext/demo + 15 处 test/phpc）
- **`#import` 机制重构为显式模型**：`#import name` 只收集 `ext/name/src/*.php`，不再自动收集 `.c` 文件。C 依赖由 ext 的 `.php` 通过 `#flag` 显式声明（如 `#flag __EXT__ . "name/src/name.c"`），符合 phpc 显式声明哲学。
  - 移除 `tphp.php` 中 `#import` 的 `.c` 自动收集逻辑（`$importCFiles` 变量删除）
  - 移除 `tphp.php` 中 `#include .h` 自动关联同名 `.c` 的副作用（`#include` 只负责引入头文件）
  - `ext/pcre/src/pcre.php`、`ext/demo/src/demo.php` 添加 `#flag __EXT__ . ".../xxx.c"` 显式声明 C 依赖
  - 新建 `ext/posix/src/posix.php`、`ext/pcntl/src/pcntl.php` stub（纯 C 扩展的 .php 入口，声明 `#flag` + `#include`）
  - `ext/posix/src/posix.c`、`ext/pcntl/src/pcntl.c` 修复 include 依赖：移除 `common.h`（含非 static inline 函数，多 TU 重复定义），Windows 分支改用 `fprintf+exit`（不使用 `tp_throw`，因 posix/pcntl 在 Windows 上 `@skip`）
  - `CodeGenerator.php` `$builtinRetTypes` 注册 12 个 posix 函数 + 7 个 pcntl 函数返回类型
- **stream 常量体系对标 PHP 原生**：
  - 新增 `STREAM_SOCK_RAW`=3、`STREAM_IPPROTO_IP`=0
  - 修正 `STREAM_OPTION_WRITE_BUFFER`=5（原错误命名 `WRITE_TIMEOUT`）、`STREAM_OPTION_CHUNK_SIZE`=7（原错误值 6）
  - `STREAM_CRYPTO_METHOD_*` 改为 PHP 原生 `_CLIENT`/`_SERVER` 后缀 + bitmask 值（如 `TLSv1_2_CLIENT`=0x10），保留无后缀别名指向 `_CLIENT` 版本（向后兼容）
  - 新增 `STREAM_CRYPTO_METHOD_ANY_CLIENT`/`ANY_SERVER`=0x3F、`STREAM_CRYPTO_PROTO_*`（PHP 8.1+ 别名）
  - C 宏名统一全大写（如 `SSLV2_CLIENT` 而非 `SSLv2_CLIENT`），因为 CodeGenerator 把 PHP 常量名 `strtoupper` 后映射到 C 宏
- **`stream_socket_enable_crypto` 返回值语义修正**：stub 和真实实现失败时返回 `-1`（而非 `0`），避免与 PHP 原生"0=非阻塞需重试"语义混淆

### 修复

- **`stream_select` 数组索引安全**：过滤未就绪 fd 后 in-place 压缩数组，同时调用 `arr_stridx_free`/`arr_intidx_free` 清除哈希索引，避免 entry 位置变化导致索引指向错误位置（内存安全 bug）
- **`CodeGenerator.php` 字符串键类型追踪不一致**（`visitArrayAccess`）：未知字符串键默认改为 `'t_string'`（与 `inferType` 行为和注释"无记录用 get_str_str"一致），原代码误用数组变量类型 `t_array*` 导致 `$meta["stream_type"]` 生成 `tphp_fn_arr_get_str_arr`（返回 `t_array*`），传给 `strlen` 报类型错误
- **`CodeGenerator.php` BinaryExpr 误匹配嵌套调用**：`str_contains($lCode, 'tphp_fn_arr_get_str_str')` 会误匹配嵌套在 `strlen(...)` 等调用内的 `get_str_str`，导致 `tphp_rt_parse_int(tphp_fn_strlen(...))` 类型错误。改用 `$lt === 't_string'` 类型推断检查
- **`array.h` 数组 getter 不处理 TYPE_BOOL**：`arr_get_str_int`/`arr_get_str_str`/`arr_get_int_int`/`arr_get_int_str` 对 TYPE_BOOL 返回零值/空串，导致 `$meta["blocked"] === true` 恒为 false。修复为遵循 PHP 类型转换语义（bool true → 1/"1"，false → 0/""）

### 文档

- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — stream 章节更新：函数数 15 → 21，常量表补全（新增 `STREAM_SOCK_RAW`/`STREAM_IPPROTO_IP`/`STREAM_CRYPTO_METHOD_*_CLIENT`/`_SERVER`/`STREAM_CRYPTO_PROTO_*`），修正 `STREAM_OPTION_WRITE_BUFFER`/`CHUNK_SIZE` 值，API 列表新增 6 个函数
- [FUNCTIONS.md](FUNCTIONS.md) — stream 章节同步更新，总览函数数 339+ → 345+
- [README.md](README.md) — 内置函数数 306+ → 312+

### 测试

- `test/ext/stream_basic.php` 完整重写：18 个测试节覆盖全部 21 个函数（strerror/last_error/TCP echo/get_name/select/blocking/read_buffer/write_buffer/timeout/isatty/get_contents/get_line/get_meta_data/enable_crypto/shutdown/socket_pair/error cases/constant values）
- 全部 168 个测试通过（Windows AMD64 + TCC）

### 变更（续）

- **OpenSSL 扩展方案重构：内置 mbedTLS 3.6.6 源码静态编译**（零运行时依赖）：
  - **核心约束**：TinyPHP 承诺零运行时依赖，不能动态链接系统 OpenSSL（`libssl.so`/`.dll` 等）
  - **参考方案**：vlang `thirdparty/mbedtls/` 集成模式，内置 mbedTLS 3.6.6 源码（`include/mbedtls_src/`）
  - **mbedTLS 3.6.6**：ARM 维护的 TLS 库，纯 C 无 ASM 依赖，TCC 友好，裁剪版配置仅启用 21 个 openssl 函数所需功能
  - **预编译静态库策略**：mbedtls 源码先逐文件编译为 `.o`，再归档为 `libmbedtls.a`，最后与主程序链接
    - 原因：TCC 一次编译过多 `.c` 文件时内部符号表溢出，导致 `static inline` 函数声明丢失（`tphp_fn_echo` 等变为隐式声明）
    - TCC 的 `-c` 模式不支持 `-o` 同时编译多个文件（报 `cannot specify output file with -c many files`），必须逐文件编译
    - 缓存机制：基于 `mbedtls_config.h` 和源文件 mtime 检测，未变更时复用 `build/mbedtls_cache/libmbedtls.a`
    - 预编译失败直接报错退出（不回退到直接编译 `.c`，避免符号表溢出）
  - **TCC 兼容性配置**（`mbedtls_config.h`）：
    - 禁用 `MBEDTLS_HAVE_ASM`、`MBEDTLS_AESNI_C`，强制 `MBEDTLS_HAVE_INT32`（避免 TCC 64x64→128 乘法 bug）
    - 禁用 TLS 1.3（需 PSA Crypto，已裁剪），仅保留 TLS 1.2
    - 添加 `ECDHE_RSA`/`ECDHE_ECDSA`/`RSA` 三种密钥交换方法
  - **Windows + TCC 兼容补丁**（`mbedtls_config.h` 末尾）：
    - `gmtime_s` 不可用（TCC win32 CRT 无 C11 边界检查库）→ 定义 `PLATFORM_UTIL_USE_GMTIME` 使用 `gmtime` 替代
    - `__stosb` 不可用（TCC 无 MSVC intrinsic，`SecureZeroMemory` 依赖）→ 定义 `MBEDTLS_PLATFORM_HAS_EXPLICIT_BZERO` + `explicit_bzero` 宏（volatile 循环）
  - **`-I` 路径顺序修复**：TinyPHP 的 `include/` 必须在 mbedtls 的 `-I` 路径之前，否则 mbedtls 的 `library/common.h` 会顶替 TinyPHP 的 `include/common.h`，导致 `tphp_fn_echo` 等 builtin 函数声明丢失
  - **测试恢复**：`test/ext/openssl_basic.php` 仍标记 `@skip`（CI 默认跳过，mbedTLS 预编译较慢），但本地可手动运行验证

### 新增

- **条件编译指令 `#if`/`#elseif`/`#else`/`#endif`**（TinyPHP 扩展）：
  - 解析期求值：非命中分支的 token 直接跳过（不解析、不类型检查、不生成 C 代码），与 V 语言 `$if` 默认行为一致
  - 可出现在**顶层**（包裹 `#include`/`#flag`/`#callback`/`#cstruct`/`class`/`function`/`const`/`enum`）和**函数体内**（包裹任意语句）
  - 条件表达式支持 `!`/`&&`/`||`/`()` 组合，标识符大小写不敏感
  - 内置标识符：`Windows`/`Linux`/`MacOS`/`Darwin`（OS）、`TCC`/`GCC`/`Clang`（编译器）、`x86_64`/`aarch64`/`arm64`（架构）、`debug`/`prod`（模式）
  - 未知标识符视为 `false`（前向兼容，不报错）
  - 目标 OS/架构优先取 `-os`/`-arch` 参数，未指定时取宿主环境（支持交叉编译条件判定）
  - `#elseif` 别名 `#elif`（兼容 C 习惯）
  - 测试用例：`test/syntax/conditional_compile.php`（顶层/函数体内/嵌套/复合条件/取反）

- **zlib 扩展对标 PHP 原生补全**（新增 22 个函数）：
  - **gz 文件流 API**（15 个）：`gzopen`/`gzclose`/`gzread`/`gzwrite`/`gzputs`/`gzeof`/`gzgets`/`gzgetc`/`gzrewind`/`gzseek`/`gztell`/`gzpassthru`/`gzflush`/`gzfile`/`readgzfile`，统一以 `Resource` 封装 `gzFile`
  - **增量上下文 API**（6 个）：`deflate_init`/`deflate_add`/`inflate_init`/`inflate_add`/`inflate_get_status`/`inflate_get_read_len`，上下文封装为 Resource
  - 通用接口 `zlib_encode`/`zlib_decode`
  - 完整常量集（编码格式/压缩级别/flush 模式/压缩策略/状态码/版本）
- **zip 扩展对标补全**（新增 5 个函数）：`zip_locate`、`zip_entry_name`、`zip_entry_filesize`、`zip_entry_compressedsize`、`zip_entry_compressionmethod`
- 完整常量集（ZIP 打开模式/标志位/压缩方法）

- **ext-stream 扩展**（新增 15 个函数，跨平台 socket stream）：
  - 核心 API：`stream_socket_server`/`stream_socket_client`/`stream_socket_accept`/`stream_close`/`stream_read`/`stream_write`/`stream_set_blocking`/`stream_socket_shutdown`/`stream_getsockname`/`stream_getpeername`/`stream_strerror`/`stream_isatty`/`stream_select`/`stream_socket_enable_crypto`（openssl.h 提供 TLS 实现，stream.h 提供 stub）/`stream_socket_recvfrom`
  - 完整常量集（45+）：socket 类型/协议、客户端/服务端标志、shutdown 模式、socket 选项、crypto 方法
  - 跨平台抽象：Windows winsock2（`closesocket`/`WSAGetLastError`/`ioctlsocket`/`SD_RECEIVE`/`SD_SEND`/`SD_BOTH`）vs POSIX（`close`/`errno`/`fcntl`/`SHUT_RD`/`SHUT_WR`/`SHUT_RDWR`）
  - Windows winsock 懒加载：首次 socket 操作触发 `WSAStartup`（`tphp_fn_stream_init`）
  - `FD_SETSIZE` 在 Windows 提升至 1024（默认 64 不满足高并发）
  - AOT 异常契约：所有错误抛 `Exception`（可 try-catch），不返回 `false`

- **ext-openssl 扩展**（新增 21 个函数，TLS/SSL 加密）：
  - **SSL Context API**（5 个）：`openssl_ctx_new`/`openssl_ctx_free`/`openssl_ctx_use_certificate_file`/`openssl_ctx_use_private_key_file`/`openssl_ctx_set_verify`/`openssl_ctx_set_options`
  - **SSL Connection API**（10 个）：`openssl_ssl_new`/`openssl_ssl_free`/`openssl_ssl_set_fd`/`openssl_ssl_connect`/`openssl_ssl_accept`/`openssl_ssl_read`/`openssl_ssl_write`/`openssl_ssl_shutdown`/`openssl_ssl_get_cipher_name`/`openssl_ssl_get_version`
  - **Error/Encrypt/Random/Hash API**（5 个）：`openssl_error_string`/`openssl_encrypt`/`openssl_decrypt`/`openssl_random_pseudo_bytes`/`openssl_digest`
  - 完整常量集（30+）：SSL 选项、验证模式、文件/密钥类型、加密选项
  - **依赖策略**：内置 mbedTLS 3.6.6 源码静态编译（`include/mbedtls_src/`），零运行时依赖，所有平台/编译器组合（包括纯 TCC 环境）均可使用
  - **TCC 兼容**：`mbedtls_config.h` 禁用 ASM + 强制 32 位 bignum limbs，Windows+TCC 额外补丁 `gmtime_s`/`__stosb`
  - **预编译策略**：逐文件编译为 `.o` → 归档为 `libmbedtls.a` → 链接到主程序（解决 TCC 符号表溢出）
  - **TLS 集成**：`openssl.h` 定义 `TPHP_STREAM_TLS_IMPLEMENTED` 后 `stream.h` 跳过 stub，使用真实 TLS 实现（openssl.h 必须在 stream.h 之前 include）
  - **SSL*/SSL_CTX* 指针以 t_int 流转**：遵循 exif FILE* 模式（`phpc_ptr_to_int`/`phpc_int_to_ptr`）
  - AOT 异常契约：所有错误抛 `tp_throw_ex` 异常（可被 try-catch 捕获）

### 变更

- **CI workflows 移除 OpenSSL 构建步骤**（`.github/workflows/build.yml` + `.github/workflows/test.yml`）：
  - 4 个 OS job（Windows/Linux x86_64/Linux aarch64/macOS）全部移除 TCC/GCC/Clang 分支的 OpenSSL 构建和 Verify 步骤
  - 移除 `OPENSSL_VERSION`/`OPENSSL_SOURCE_URL` 环境变量
  - MSYS2 安装项去掉 perl/nasm（仅 OpenSSL 构建需要）
  - 删除独立的 `.github/workflows/build-openssl.yml`（已合并到 build/test 后又移除）
  - **OpenSSL 扩展已通过内置 mbedTLS 源码恢复**，不再需要 CI 构建系统 OpenSSL 静态库
- **zlib 依赖架构重构**：从"系统 zlib 动态发现（MSYS2 路径 + PATH 扫描 + DLL 复制）"改为**内置 zlib 1.3.2 源码静态编译**：
  - 源码置于 `include/os/zlib_src/`（15 个 `.c` + 11 个 `.h`，约 332KB）
  - `tphp.php` 检测生成的 C 代码引用 `os/zlib.h` 后，自动将 zlib 源码 `.c` 加入编译列表
  - 移除 `tphp.php` 中写死的 MSYS2 路径（`C:\msys64\...`/`C:\env\msys2\...`）
  - 删除 `tcc/win32/lib/zlib1.dll`，零运行时依赖
  - **确保纯 TCC 环境（无 MSYS2/GCC/Clang）也能使用 zlib/zip 扩展**
- **AOT 异常契约统一**：zlib/zip 全部 API 失败时抛 `Exception`（可 try-catch），不返回 `false`
- `include/os/zlib.h` 简化为直接包含内置 `zlib_src/zlib.h`，移除 TCC 手动声明块
- zip 不支持修改已有归档：`zip_delete`/`zip_rename` 抛异常
- **移除 `c_float` / `php_float` 桥接函数**：`t_float` 就是 `double`，转换是无意义的空操作。float 类型直接传递即可。保留 `c_int`/`php_int`（有截断/提升意义）。
- **`.` 点指令只收集 .php 文件**：不再递归扫描 `.c` 文件，避免误收集不需要的源文件。`.c` 文件改由 `#flag` 显式声明（`#flag my_helper.c`），自动加入编译列表。
- **`tphp.php` TCC Windows 库搜索路径补全**：`-B` 仅设置 `tcc_lib_path`（用于 libtcc1.a），`-l` 库搜索走 `library_paths`。Windows dev 模式下额外追加 `-L` 指向 `tcc/win32/lib`，否则 `-lws2_32` 等系统库无法定位 `.def` 文件。
- **`CodeGenerator.php` stream/openssl 类型推断注册**：
  - `$simpleFnMap` 新增 `stream_*` → `tphp_fn_stream_*` 和 `openssl_*` → `tphp_fn_openssl_*` 映射
  - `$builtinRetTypes` 注册 stream/openssl 函数返回类型（避免指针被默认推断为 `t_int` 导致截断）
  - include 顺序：openssl.h 在 stream.h 之前（保证 `TPHP_STREAM_TLS_IMPLEMENTED` 先定义）
  - 项目根目录加入 `-I` 搜索路径（生成的 C 代码引用 `ext/stream/src/stream.h` 相对路径）

### 修复

- `gzfile()` 返回数组元素类型推断（`$builtinArrElemTypes` 注册为 `t_string`）
- `gzeof()` 行为说明：仅在读取超出末尾后返回 true（与 PHP 原生一致）
- `zlib_decode()` 不支持 RAW DEFLATE 自动检测，需用 `gzinflate()` 解码
- 测试运行器文件名冲突：用相对路径生成唯一可执行文件名（`test_zip_basic.exe`/`test_zlib_basic.exe`）
- Windows+TCC 的 `EWOULDBLOCK` 未定义：`zlib_src/zlib.h` 顶部 `#define EWOULDBLOCK EAGAIN`
- `$extraSrcs` 重建时机：追加 zlib 源码后必须重建，否则编译命令缺少这些 `.c` 文件
- TCC macOS `stdarg.h` 缺失：`zconf.h` 用 `__builtin_va_*` 替代，`gzwrite.c` 跳过 `#include <stdarg.h>`，`gzguts.h` POSIX 分支添加 `#include <unistd.h>`
- `CodeGenerator.php` 中 `c_str` 条件重复（清理）
- **Windows+TCC `inet_pton`/`inet_ntop` 隐式声明**：TCC 自带 `ws2tcpip.h` 仅声明 `getaddrinfo` 等，未声明 `inet_pton`/`inet_ntop`（与 `_WIN32_WINNT` 无关）。在 `ext/stream/src/stream.h` 中手动声明，用 `#ifndef __MINGW32__` 守卫（GCC/Clang MinGW 自带正确声明）
- **Windows+TCC `ws2_32.lib not found`**：双重根因——① `-B` 不影响 `-l` 库搜索路径，需额外 `-L` 指向 `tcc/win32/lib`；② `#pragma comment(lib, "ws2_32.lib")` 触发 TCC `tcc_add_pragma_libs()`，将完整名 `"ws2_32.lib"`（含 `.lib` 后缀）传给 `tcc_add_library()`，搜索 `ws2_32.lib.def` 等不存在的文件。移除 `#pragma comment(lib, ...)`，依赖 `#flag windows -lws2_32` 提供
- **`stream_strerror` 测试跨语言兼容**：Windows `FormatMessageA` 返回系统语言消息（中文 Windows 返回中文），测试改为验证 `strlen > 0`（非空），不比较确切文本
- **`_WIN32_WINNT 0x0600`**：Windows Vista+ 才有 `inet_pton`/`inet_ntop`，`ext/stream/src/stream.h` 顶部定义
- **Windows `SHUT_*` → `SD_*` 映射**：Windows winsock2 使用 `SD_RECEIVE`/`SD_SEND`/`SD_BOTH`，与 POSIX `SHUT_RD`/`SHUT_WR`/`SHUT_RDWR` 不同，stream.h 中条件映射

### 文档

- [FUNCTIONS.md](FUNCTIONS.md) — 补全 zlib（29 函数 + 完整常量表）和 zip（18 函数 + 完整常量表）章节，版本号修正为 `1.3.2`/`0x1320`
- [FUNCTIONS.md](FUNCTIONS.md) — 新增 stream（15 函数 + 4 张常量表）和 openssl（21 函数 + 4 张常量表 + 加密/摘要代码示例）章节，总览函数数更新为 `339+`
- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — zlib（§4）和 zip（§12）章节完整重写：
  - API 返回类型从 `string|false`/`ZipArchive|false` 改为 AOT 异常契约
  - 设计说明更新为"内置 zlib 1.3.2 源码静态编译"
  - 目录表函数数修正：zlib 6→29、zip 12→18
- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — 新增 stream（§5）章节，更新 OpenSSL（§8）章节：
  - stream：完整 API、Windows/TCC 兼容性说明（`inet_pton` 声明、`#pragma comment` 不兼容、`SHUT_*`→`SD_*` 映射）
  - OpenSSL：API 返回类型从 `string|false` 改为 `string|Exception` AOT 契约，新增预编译静态库策略说明
  - 目录表标记 stream/OpenSSL 为 ✅ 已完成
- [README.md](README.md) — 内置函数数量从 `281+` 更新为 `306+`，描述加入 zlib/zip

### 测试

- `test/zlib/basic.php` 重写：覆盖基础压缩/解压、gz 文件流、增量上下文、gzeof 双状态演示、RAW DEFLATE 解码
- `test/zip/basic.php` 扩展：覆盖归档创建/读取/条目信息查询/locate
- `test/ext/stream_basic.php` 新增：覆盖 `stream_strerror`（非空检查，跨语言兼容）、TCP echo（127.0.0.1 本地回环）、`stream_set_blocking`、`stream_socket_shutdown`
- `test/ext/openssl_basic.php` 新增（`@skip` 标记，OpenSSL 扩展暂停）：覆盖 `openssl_random_pseudo_bytes`、`openssl_digest`（sha256/md5/sha512）、`openssl_encrypt`/`openssl_decrypt` 往返（AES-256-CBC）、`openssl_error_string`

### 测试体系对标 PHP 原生

- **测试目录重组**：按功能类别重新归类，对齐 PHP 原生 `tests/` 布局
  - 移除按开发阶段命名的目录（`phase1/`/`phase2/`/`tier1/`/`tier2/`/`tier3/`/`builtin/`）
  - 顶层子目录改为功能分类：`lang/`/`func/`/`array/`/`strings/`/`math/`/`type/`/`object/`/`control_flow/`/`syntax/`/`error/`
  - 根目录散落的 `verify_*.php` 文件移入对应功能目录
  - 每个测试文件头部标注 PHP 原生测试编号（如 `// 对应 PHP tests/lang/007.phpt`）
- **新增 50+ PHP 8.5 原生测试移植**：
  - `test/lang/`：do-while、break/continue 多层、list 解构、闭包、heredoc、三元运算符、null 合并、foreach key=>value 等 11 个测试
  - `test/func/`：strlen、递归、默认参数、引用传参等 5 个测试
  - `test/strings/`：sprintf/printf、str_replace、substr、explode/implode、trim 系列、strpos、strrev、chr/ord、strtolower/strtoupper、str_contains/str_starts_with/str_ends_with、str_repeat/str_pad/str_split 等 15 个测试
  - `test/math/`：abs/ceil/floor/round、max/min/pow/sqrt、intdiv/fmod、进制转换、log/log10/sin/cos/tan/pi 等 9 个测试
  - `test/array/`：array_push/pop/shift/unshift、array_slice/merge/flip/reverse、array_keys/values/map/filter/reduce、sort/rsort/asort/ksort/usort、in_array/array_search/count、array_sum/product/fill/pad 等 8 个测试
- **测试数量**：169 → 209（+40，超出 200+ 目标）
- **跨平台 CI 全绿**：Windows AMD64 / Linux x86_64 / Linux aarch64 / macOS arm64 四平台全部通过

### 兼容性缺陷修复（14 项）

- **嵌套数组深层访问**（`$arr[0][1][2]`、`$m["items"][0]["id"]`）：
  - 根因：`visitArrayAccess`/`inferType` 仅追踪单层数组元素类型，3 层及以上嵌套访问返回空数组或空字符串
  - 修复：新增 `arrLiteralAST` 字段保存数组字面量 AST，`traceNestedAccessType()` 方法递归追踪访问链到具体键的值类型；`arrNestedDepth` 字段记录数组嵌套深度，`inferArrayNestedDepth()` 计算总深度，访问时按深度判断到达叶层还是中间数组层
  - 测试：`test/array/nested_access.php`（6 节覆盖 3 层 int、字符串键、混合 int/str 键、4 层深度、foreach 嵌套、嵌套运算）
- **list/[] 解构类型推断**：
  - 根因：`generateListAssign` 用单一 `elemType` 处理整个 list，混合类型数组 `[10, [20, [30]]]` 中 `$a1`（int）被当作 array 读取
  - 修复：`generateListAssign` 新增 `srcLiteral` 参数，从源数组字面量按位置推断每个元素类型（per-index type inference）；零值按元素类型生成（`t_string` → `(t_string){0}`，`t_float` → `0.0`，`t_array*` → `NULL`，`t_int`/`t_bool` → `0`）
  - 测试：`test/lang/lang_039_list_destructure.php`、`test/array/list_test.php`
- **`??` null 合并数组键**：
  - 根因：`??` 直接返回数组 getter 结果，但缺失键的 getter 返回默认值（0/空串）而非 null
  - 修复：`??` 左侧为 `ArrayAccessExpr` 时生成 `array_key_exists` 检查，键存在才取值，否则取右侧
  - 测试：`test/lang/lang_044_null_coalesce.php`
- **abs() 类型分派**：`abs(int)` → `tphp_fn_abs_int`，`abs(float)` → `tphp_fn_abs_float`，返回类型跟随参数类型（测试：`test/math/abs_basic.php`）
- **pow() 负指数语义**：`tphp_fn_pow` 整数路径要求 `exp.value._int >= 0`，负指数走 float 路径返回 float（PHP 语义，测试：`test/math/pow_sqrt_basic.php`）
- **sqrt() 负数返回 NAN**：从 `0.0` 改为 `NAN`（测试：`test/math/sqrt_basic.php`）
- **count() COUNT_RECURSIVE**：新增 `tphp_fn_arr_count_recursive` 递归统计嵌套数组元素数（测试：`test/array/count_recursive.php`）
- **array_pad() 实现**：新增 `tphp_fn_arr_pad`，支持正 size 右填充、负 size 左填充（测试：`test/array/array_pad_basic.php`）
- **array_keys() 搜索参数**：新增 `tphp_fn_array_keys_search`，用 `tphp_rt_var_equals` 严格比较类型+值（测试：`test/array/array_keys_search.php`）
- **max/min 可变参数**：新增 `variadic_pack` 分派，多个参数打包为临时 `t_array*` 再调用 `tphp_fn_max`/`tphp_fn_min`（测试：`test/math/max_min_basic.php`）
- **空数组字面量 `[]` 不设置 arrElementTypes**：避免 `[]` 被误判为 `t_int`，后续 `$arr[$k]=val` 变量键赋值后读取返回 0（测试：`test/array/array_str_key.php`）
- **未知字符串键类型回退**：`visitArrayAccess`/`inferType` 先查 `arrElementTypes[$arrName]` 再默认 `t_string`，避免类型不匹配
- **break 2 / continue 2 语义**：多层循环的 break/continue N 正确跳出指定层数（测试：`test/lang/lang_037.php`）
- **暂缓**：`array_splice` 需要 by-reference 修改支持，AOT 下不支持，测试保留 `@skip`

### 文档

- [FUNCTIONS.md](FUNCTIONS.md) — 更新 `count()`（COUNT_RECURSIVE）、`array_keys()`（search 参数）、`max()`/`min()`（可变参数）、`abs()`（类型分派）、`pow()`（负指数）、`sqrt()`（NAN）条目；新增 `array_pad()` 条目
- [GRAMMAR.md](GRAMMAR.md) — 更新 `??` 运算符（数组键用 array_key_exists）、`list`/`[]` 解构（类型感知零值）、新增嵌套数组深层访问条目、更新 `??=` 和 `...$args` 备注

### 测试

- 全部 209 个测试通过（Windows AMD64 / Linux x86_64 / Linux aarch64 / macOS arm64 四平台 CI 全绿）
- 含纯 TCC 环境验证（无 MSYS2/GCC/Clang）

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
