# TinyPHP Android 构建

## 前置条件

1. 安装 Android NDK 并设置环境变量：
   ```
   set ANDROID_NDK=C:\Android\ndk\27.0.12077973
   ```
   或通过 Android Studio SDK Manager 安装 NDK (Side by side)。

2. 安装 Gradle（任选其一）：
   - 全局安装：`choco install gradle`（Windows）/ `brew install gradle`（macOS）
   - 或将 Gradle Wrapper（`gradlew`/`gradlew.bat` + `gradle/wrapper/`）放入 `ext/ui/android/`

## 一键构建

```bash
# 默认 aarch64，自动编译 .so + 打包 APK
php tphp.php . -os android

# x86_64
php tphp.php . -os android -arch x86_64
```

流程：
1. 编译 PHP → `libtphp.so`，输出到 `build/android/jniLibs/<abi>/`（执行命令所在目录的 build/android）
2. 自动调用 `gradle assembleDebug` 打包 APK（`ext/ui/android` 仅作为工程模板，不写入任何产物）
3. APK 输出到 `build/android/app-debug.apk`

## 安装到设备

```bash
adb install build/android/app-debug.apk
```

## 自定义 API Level

默认 API level 为 24（Android 7.0）。可通过环境变量修改：
```bash
set TPHP_ANDROID_API=30
```

## 支持的 ABI

| -arch 值 | ABI |
|----------|-----|
| aarch64 (默认) | arm64-v8a |
| x86_64 | x86_64 |
| armv7a | armeabi-v7a |
| i686 | x86 |
