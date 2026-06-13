// 同时兼容 TCC/Clang/GCC 的跨平台导出宏
#if defined(_WIN32) || defined(_WIN64)
    #ifdef DLL_BUILD
        #define DLL_EXPORT __declspec(dllexport)
    #else
        #define DLL_EXPORT __declspec(dllimport)
    #endif
#elif defined(__linux__) || defined(__APPLE__)
    #ifdef DLL_BUILD
        #define DLL_EXPORT __attribute__((visibility("default")))
    #else
        #define DLL_EXPORT
    #endif
#else
    #error "不支持的操作系统"
#endif

// ==============================================
// 修复方案：直接在定义处标注导出，去掉单独的声明
// ==============================================
#ifdef DLL_BUILD
#include <stdio.h>
DLL_EXPORT void say_hello(const char* name) {
    printf("Hello %s \n", name);
}

DLL_EXPORT int process_int(int n) {
    return n * 2;
}

DLL_EXPORT double process_float(double f) {
    return f * 2.0;
}
#else
DLL_EXPORT void say_hello(const char* name);
DLL_EXPORT int process_int(int n);
DLL_EXPORT double process_float(double f);
#endif