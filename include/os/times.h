#pragma once
// ============================================================
// os/times.h — time / date / sleep / usleep
// ============================================================

#include <time.h>
#include <stdint.h>

#ifdef _WIN32
#  include <windows.h>
#else
#  include <unistd.h>
#endif

// === time() — 返回当前 Unix 时间戳 ===
static inline t_int tphp_fn_time(void) {
    return (t_int)time(NULL);
}

// === date() — 格式化时间字符串（PHP 格式，非 strftime） ===
// 手写解析 PHP date 格式字符，使用 SSO 返回。
static inline t_string tphp_fn_date(t_string fmt, t_int timestamp) {
    char buf[256];
    time_t t = (time_t)(timestamp >= 0 ? timestamp : time(NULL));
    struct tm *tm = localtime(&t);
    if (tm == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};

    char *d = buf;
    char *end = buf + sizeof(buf) - 1;
    int i = 0;
    while (i < fmt.length && d < end) {
        char c = STR_PTR_V(fmt)[i];
        switch (c) {
            case 'Y': d += snprintf(d, (size_t)(end - d), "%04d", tm->tm_year + 1900); break;
            case 'y': d += snprintf(d, (size_t)(end - d), "%02d", tm->tm_year % 100);  break;
            case 'm': d += snprintf(d, (size_t)(end - d), "%02d", tm->tm_mon + 1);     break;
            case 'n': d += snprintf(d, (size_t)(end - d), "%d",   tm->tm_mon + 1);     break;
            case 'd': d += snprintf(d, (size_t)(end - d), "%02d", tm->tm_mday);        break;
            case 'j': d += snprintf(d, (size_t)(end - d), "%d",   tm->tm_mday);        break;
            case 'H': d += snprintf(d, (size_t)(end - d), "%02d", tm->tm_hour);        break;
            case 'G': d += snprintf(d, (size_t)(end - d), "%d",   tm->tm_hour);        break;
            case 'i': d += snprintf(d, (size_t)(end - d), "%02d", tm->tm_min);         break;
            case 's': d += snprintf(d, (size_t)(end - d), "%02d", tm->tm_sec);         break;
            default:  *d++ = c; break;
        }
        i++;
    }
    int len = (int)(d - buf);
    // 使用 SSO 返回（短字符串内联，安全释放）
    t_string result = {.is_local = true, .length = len};
    memcpy(result.local, buf, (size_t)len);
    result.local[len] = '\0';
    return result;
}

// === sleep() — 休眠指定秒数 ===
static inline void tphp_fn_sleep(t_int seconds) {
    if (seconds < 0) return;
#ifdef _WIN32
    Sleep((DWORD)(seconds * 1000));
#else
    sleep((unsigned int)seconds);
#endif
}

// === usleep() — 休眠指定微秒数 ===
static inline void tphp_fn_usleep(t_int microseconds) {
    if (microseconds < 0) return;
#ifdef _WIN32
    Sleep((DWORD)(microseconds / 1000));
#else
    usleep((useconds_t)microseconds);
#endif
}

// === hrtime() — 高分辨率时间（纳秒） ===
static inline t_int tphp_fn_hrtime(void) {
#ifdef _WIN32
    static LARGE_INTEGER freq = {0};
    if (freq.QuadPart == 0) QueryPerformanceFrequency(&freq);
    LARGE_INTEGER now;
    QueryPerformanceCounter(&now);
    // 拆分计算避免 now * 1e9 溢出 64-bit
    t_int sec  = now.QuadPart / freq.QuadPart;
    t_int nsec = (now.QuadPart % freq.QuadPart) * 1000000000LL / freq.QuadPart;
    return sec * 1000000000LL + nsec;
#else
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (t_int)ts.tv_sec * 1000000000LL + (t_int)ts.tv_nsec;
#endif
}

static inline t_float tphp_fn_microtime(void) {
#ifdef _WIN32
    static LARGE_INTEGER freq = {0};
    if (freq.QuadPart == 0) QueryPerformanceFrequency(&freq);
    LARGE_INTEGER now;
    QueryPerformanceCounter(&now);
    return (t_float)now.QuadPart / (t_float)freq.QuadPart;
#else
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (t_float)ts.tv_sec + (t_float)ts.tv_nsec / 1e9;
#endif
}

// ── mktime(h, m, s, mon, day, year) — 生成时间戳 ────────────
// 日历天数累加法，零依赖外部库
static inline t_bool _is_leap(t_int y) {
    return (y % 4 == 0 && y % 100 != 0) || (y % 400 == 0);
}

static inline t_int tphp_fn_mktime(t_int h, t_int m, t_int s,
                                     t_int mon, t_int day, t_int year) {
    static const int8_t _mdays[12] = {31,28,31,30,31,30,31,31,30,31,30,31};

    // 从 1970-01-01 起算天数
    t_int days = 0;
    // 年
    if (year >= 1970) {
        for (t_int y = 1970; y < year; y++) days += _is_leap(y) ? 366 : 365;
    } else {
        for (t_int y = year; y < 1970; y++) days -= _is_leap(y) ? 366 : 365;
    }
    // 月（mon 1-12）
    for (t_int i = 0; i < mon - 1 && i < 12; i++) {
        days += _mdays[i];
        if (i == 1 && _is_leap(year)) days++;
    }
    // 日
    days += day - 1;
    // 时分秒 → 秒
    return days * 86400 + h * 3600 + m * 60 + s;
}

// ── strtotime($s) — 字符串 → 时间戳 ─────────────────────────
// 支持 Y-m-d H:i:s, Y/m/d H:i:s, Y-m-d, Y/m/d, 纯数字(time())
static inline t_int tphp_fn_strtotime(t_string s) {
    if (s.data == NULL || s.length == 0) return 0;

    // 纯数字 → 直接返回
    t_bool allDig = true;
    for (int i = 0; i < s.length; i++) {
        if (STR_PTR(s)[i] < '0' || STR_PTR(s)[i] > '9') { allDig = false; break; }
    }
    if (allDig) return (t_int)strtoll(STR_PTR(s), NULL, 10);

    // 解析 Y-m-d H:i:s 或 Y/m/d H:i:s
    int Y = 1970, M = 1, D = 1, H = 0, I = 0, S = 0;
    int n = 0;
    char sep = '-'; // 试探分隔符
    if (s.length > 4 && STR_PTR(s)[4] == '/') sep = '/';

    // Y-m-d 部分
    char part[32];
    int start = 0, pi = 0;
    for (int j = 0; j <= s.length; j++) {
        char c = (j < s.length) ? STR_PTR(s)[j] : ' ';
        if (c == sep || c == ' ' || c == '\0' || c == 'T') {
            if (pi > 0) {
                memcpy(part, STR_PTR(s) + start, (size_t)pi);
                part[pi] = '\0';
                int val = atoi(part);
                if (n == 0) Y = val; else if (n == 1) M = val; else if (n == 2) D = val;
                n++;
                pi = 0;
            }
            start = j + 1;
            if (c == ' ' || c == 'T') break;
        } else if (c == ':') {
            if (pi > 0) {
                memcpy(part, STR_PTR(s) + start, (size_t)pi);
                part[pi] = '\0';
                int val = atoi(part);
                if (n == 3) H = val; else if (n == 4) I = val; else if (n == 5) S = val;
                n++;
                pi = 0;
            }
            start = j + 1;
        } else {
            pi++;
        }
    }
    if (pi > 0 && n >= 3) {
        memcpy(part, STR_PTR(s) + start, (size_t)pi);
        part[pi] = '\0';
        int val = atoi(part);
        if (n == 3) H = val; else if (n == 4) I = val; else if (n == 5) S = val;
    }

    return tphp_fn_mktime((t_int)H, (t_int)I, (t_int)S,
                           (t_int)M, (t_int)D, (t_int)Y);
}

// ── uniqid($prefix?) — 唯一 ID ──────────────────────────────
static inline t_string tphp_fn_uniqid0(void);
static inline t_int tphp_fn_time(void);
static inline t_int tphp_fn_rand_int(t_int min, t_int max);

static inline t_string tphp_fn_uniqid(t_string prefix) {
    char *buf = str_pool_alloc(48);
    if (!buf) return (t_string){.data = NULL, .length = 0, .is_local = false};
    t_int t = tphp_fn_time();
    t_int r = tphp_fn_rand_int(0, 99999);

    int plen = (STR_PTR(prefix) != NULL && prefix.length > 0) ? prefix.length : 0;
    if (plen > 32) plen = 32;

    int pos = 0;
    if (plen > 0) { memcpy(buf, STR_PTR(prefix), (size_t)plen); pos += plen; }
    pos += snprintf(buf + pos, 48 - (size_t)pos,
                    "%08lx%05lx", (unsigned long)t, (unsigned long)r);
    buf[pos] = '\0';
    return (t_string){buf, pos};
}

static inline t_string tphp_fn_uniqid0(void) {
    return tphp_fn_uniqid((t_string){.data = NULL, .length = 0, .is_local = false});
}
