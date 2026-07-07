// ext/libevent/tcc-stubs/win32_stubs.c
//
// Stubs for Windows API functions that TCC's import libraries don't include.
// libevent was configured with CMake (using GCC) which detected these as available,
// but TCC's .def files don't list them.
//
// This file is compiled with TCC and linked into libevent_core_tcc.a on Windows.

#ifdef _WIN32

#define WIN32_LEAN_AND_MEAN
#include <winsock2.h>
#include <windows.h>

// gettimeofday — POSIX function, not in TCC's msvcrt.def
// (MinGW provides this as a static/inline function; TCC doesn't)
int gettimeofday(struct timeval *tv, void *tz) {
    FILETIME ft;
    ULARGE_INTEGER li;
    (void)tz;
    GetSystemTimeAsFileTime(&ft);
    li.LowPart = ft.dwLowDateTime;
    li.HighPart = ft.dwHighDateTime;
    // Convert FILETIME (100ns intervals since 1601-01-01) to Unix epoch microseconds
    li.QuadPart -= 116444736000000000ULL;
    li.QuadPart /= 10;
    tv->tv_sec  = (long)(li.QuadPart / 1000000);
    tv->tv_usec = (long)(li.QuadPart % 1000000);
    return 0;
}

// gai_strerrorA — in ws2_32.dll but missing from TCC's ws2_32.def
char *gai_strerrorA(int ecode) {
    (void)ecode;
    return "getaddrinfo error";
}

// if_nametoindex — in iphlpapi.dll, TCC has no import library for it
unsigned long if_nametoindex(const char *ifname) {
    (void)ifname;
    return 0;  // Not supported in TCC build
}

#endif // _WIN32
