# TinyPHP Android 构建

> 将 PHP UI 应用编译为 Android APK。基于 NDK Clang 工具链交叉编译为 `libtphp.so`，通过 Gradle 打包为 APK。

## 前置条件

### 1. Android NDK（必需）

设置 `ANDROID_NDK` 环境变量指向 NDK 根目录：

```bash
# Windows
set ANDROID_NDK=C:\Android\ndk\27.0.12077973

# macOS / Linux
export ANDROID_NDK=/opt/android-ndk
```

或通过 Android Studio SDK Manager 安装 NDK (Side by side)。

### 2. JDK 17 或 21（APK 打包必需）

```bash
set JAVA_HOME=C:\Program Files\Java\jdk-17
```

> ⚠️ **Java 版本兼容性**：Java 24+ 与 Gradle 8.9 / AGP 8.7.0 不兼容，会导致 AGP 内部 XML 解析器静默失败。tphp.php 会自动检测并搜索 Java 17/21 LTS 切换 `JAVA_HOME`，但建议直接安装 LTS 版本。

### 3. Android SDK（APK 打包必需）

```bash
set ANDROID_HOME=C:\Users\<user>\AppData\Local\Android\Sdk
```

需安装 `platforms;android-35` 和 `build-tools;35.0.0`。tphp.php 会自动检测并补写 SDK 34 许可哈希。

### 4. Gradle

无需全局安装。`ext/ui/android/` 已包含 Gradle Wrapper（`gradlew`/`gradlew.bat` + `gradle/wrapper/`），构建时复制到工作目录自动使用。

## 一键构建

```bash
# 默认编译全部 4 种 ABI，生成 <baseName>-debug.apk
php tphp.php ui_basic.php -os android

# 指定输出名（生成 myapp-debug.apk）
php tphp.php ui_basic.php -os android -o myapp

# 仅编译单 ABI（加速本地测试）
php tphp.php ui_basic.php -os android -arch x86_64   # 模拟器常用
php tphp.php ui_basic.php -os android -arch aarch64   # 真机常用
```

### 构建流程

1. **多 ABI 编译**（默认）：通过子进程递归调用，为每个 ABI 编译 `libtphp.so`，输出到 `<cwd>/build/android/jniLibs/<abi>/`
2. **Gradle 打包**：复制 `ext/ui/android/` 工程模板到 `<cwd>/build/android/`（模板保持洁净），调用 `gradlew assembleDebug` 打包
3. **APK 输出**：Gradle 默认输出到 `build/android/app-debug.apk`，由 tphp.php 复制到当前工作目录，命名为 `<baseName>-debug.apk`（遵循 `-o` 机制）

> `ext/ui/android/` 仅作为工程模板，构建过程中不会写入任何产物，所有产物位于 `<cwd>/build/android/`。

### 产物位置

| 产物 | 路径 | 说明 |
|------|------|------|
| `libtphp.so` | `<cwd>/build/android/jniLibs/<abi>/` | 各 ABI 共享库（供 Gradle 打包） |
| `<baseName>-debug.apk` | `<cwd>/` | 最终 APK（与其他二进制产物位置一致） |

## 安装到设备

```bash
# 通过 adb 安装
adb install ui_basic-debug.apk

# 查看应用日志（PHP echo 输出已重定向到 logcat）
adb logcat -s tphp
```

## 环境变量

| 变量 | 必需 | 说明 |
|------|------|------|
| `ANDROID_NDK` | ✅ | NDK 根目录路径 |
| `JAVA_HOME` | APK 打包 | JDK 17/21 路径（Java 24+ 不兼容） |
| `ANDROID_HOME` | APK 打包 | Android SDK 路径 |
| `TPHP_ANDROID_API` | 否 | 目标 API 级别（默认 24 = Android 7.0） |

> NDK 缺失时编译阶段直接退出并提示安装配置；SDK 或 JAVA_HOME 缺失时跳过 APK 打包但仍生成 `libtphp.so`。

## 支持的 ABI

默认编译全部 4 种 ABI，覆盖真机与模拟器：

| `-arch` 值 | ABI | 典型场景 |
|----------|-----|---------|
| `aarch64` | arm64-v8a | 64 位真机 |
| `x86_64` | x86_64 | 64 位模拟器 |
| `armv7a` | armeabi-v7a | 32 位真机 |
| `i686` | x86 | 32 位模拟器 |

> 不指定 `-arch` 时默认编译全部 4 种 ABI。模拟器秒退通常是 ABI 不匹配导致（模拟器多为 x86_64，只编译 aarch64 时无匹配 `.so`）。

## 自定义 API Level

默认 API level 为 24（Android 7.0）。可通过环境变量修改：

```bash
set TPHP_ANDROID_API=30   # Android 11
```

## 平台适配说明

### 屏幕方向

`AndroidManifest.xml` 中 `android:screenOrientation` 默认为 `portrait`（竖屏）。如需横屏，修改 `ext/ui/android/app/src/main/AndroidManifest.xml` 第 15 行。

### 触摸事件

sokol 在 Android 上生成 `TOUCHES_BEGAN/ENDED/MOVED` 事件，`ui.h` 中自动将首个触摸点转换为 `MOUSE_DOWN/UP/MOVE` 事件，桌面端 PHP 代码无需修改即可响应触摸。

### 按键输入

sokol 默认只处理 BACK 键，其他按键被丢弃。`ui.h` 通过 `native_event_cb` 钩子拦截 `AInputEvent`，映射 `AKEYCODE_*` 到 PHP Key 枚举的 ASCII 值，并对可打印字符（ASCII 32-126）额外生成 `CHAR` 事件驱动 TextBox 输入。

### 软键盘

`SoftInput::show()` / `SoftInput::hide()` 通过 JNI 调用 Android `InputMethodManager` 显示/隐藏软键盘。点击 TextBox 等可聚焦控件时会自动弹出。

### 调试输出

PHP `echo` / `var_dump` 等标准输出已通过 pipe + 后台线程重定向到 Android logcat（tag: `tphp`）。用 `adb logcat -s tphp` 查看。

## .gitignore

`local.properties`（包含开发者个人 SDK 路径）已在 `.gitignore` 中排除，不要提交。
