#pragma once
// ============================================================
// file.h — 文件 I/O (仅静态路径，AOT 安全)
// ============================================================
#include <stdio.h>
#include <stdint.h>

/** unlink — 删除文件 */
static inline t_bool tphp_fn_unlink(t_string path) {
    if (STR_PTR(path) == NULL || path.length <= 0) return false;
    char pbuf[4096];
    int plen = path.length < (int)sizeof(pbuf) - 1 ? path.length : (int)sizeof(pbuf) - 1;
    memcpy(pbuf, STR_PTR(path), (size_t)plen);
    pbuf[plen] = '\0';
    return remove(pbuf) == 0;
}

/** file_get_contents — 读入整个文件到 t_string */
static inline t_string tphp_fn_file_get_contents(const char *path) {
    if (path == NULL || *path == '\0') return (t_string){.data = NULL, .length = 0, .is_local = false};
    FILE *fp = fopen(path, "rb");
    if (fp == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    fseek(fp, 0, SEEK_END);
    int64_t size = ftell(fp);
    if (size <= 0) { fclose(fp); return (t_string){.data = NULL, .length = 0, .is_local = false}; }
    fseek(fp, 0, SEEK_SET);
    char *buf = str_pool_alloc((int)size);
    if (buf == NULL) { fclose(fp); return (t_string){.data = NULL, .length = 0, .is_local = false}; }
    size_t n = fread(buf, 1, (size_t)size, fp);
    fclose(fp);
    buf[n] = '\0';
    return (t_string){buf, (int)n};
}

/** file_put_contents — 写字符串到文件（覆盖） */
static inline t_bool tphp_fn_file_put_contents(const char *path, t_string data) {
    if (path == NULL || *path == '\0') return false;
    FILE *fp = fopen(path, "wb");
    if (fp == NULL) return false;
    size_t n = 0;
    if (STR_PTR(data) != NULL && data.length > 0)
        n = fwrite(STR_PTR(data), 1, (size_t)data.length, fp);
    fclose(fp);
    return n > 0 || data.length == 0;
}
