package com.tphp.ui;

import android.app.NativeActivity;

/**
 * TinyPHP UI 的 NativeActivity 入口。
 * sokol_app.h 的 ANativeActivity_onCreate 会在此 Activity 的 nativeWindow 上运行。
 * 编译产物 libapp.so 需放在 jniLibs/<abi>/ 下。
 */
public class MainActivity extends NativeActivity {
}
