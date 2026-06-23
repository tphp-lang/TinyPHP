#include "demo.h"
#include <math.h>
#include <string.h>
#include <stdlib.h>

double calc_distance(double x1, double y1, double x2, double y2) {
    double dx = x2 - x1;
    double dy = y2 - y1;
    return sqrt(dx * dx + dy * dy);
}

static char reverse_buf[1024];
const char* reverse_str(const char* input) {
    if (!input) return "";
    int len = (int)strlen(input);
    if (len >= 1023) len = 1023;
    for (int i = 0; i < len; i++) {
        reverse_buf[i] = input[len - 1 - i];
    }
    reverse_buf[len] = '\0';
    return reverse_buf;
}

int64_t factorial(int n) {
    if (n <= 1) return 1;
    return n * factorial(n - 1);
}
