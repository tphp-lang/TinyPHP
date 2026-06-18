/* glibc 2.34+ removed these hooks. Stub declarations for TCC bcheck.c. */
#if defined(__GLIBC__)
#include <stddef.h>
extern void *(*volatile __malloc_hook)(size_t __size, const void *__caller);
extern void *(*volatile __realloc_hook)(void *__ptr, size_t __size, const void *__caller);
extern void (*volatile __free_hook)(void *__ptr, const void *__caller);
#endif
