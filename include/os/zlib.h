#pragma once
// ============================================================
// zlib.h — gzcompress / gzuncompress / gzencode / gzdecode
//          gzdeflate / gzinflate
// 对应 PHP ext/zlib，依赖系统 zlib 库（-lz）
//
// 设计说明：
//   - 依赖系统 zlib（RFC 1950/1951/1952），编译器自动链接 -lz
//   - 压缩输出大小可预估（compressBound），一次 str_pool_alloc
//   - 解压输出大小未知，用 malloc 缓冲区 + 循环 inflate，
//     完成后复制到 str_pool 并 free 临时缓冲区
//   - 错误处理统一 tp_throw_ex（不返回 false，符合 AOT 单返回类型契约）
//   - 字符串输出走 str_pool_alloc，作用域结束自动释放
//
// encoding 参数（zlib windowBits）：
//   ZLIB_ENCODING_RAW (-15)    : 原始 DEFLATE，无头无校验
//   ZLIB_ENCODING_DEFLATE (15) : zlib 格式 (RFC 1950)，有头 + Adler-32
//   ZLIB_ENCODING_GZIP (31)    : gzip 格式 (RFC 1952)，有头 + CRC-32
// ============================================================

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "types.h"
#include "object/exception.h"

// 前向声明
static inline char* str_pool_alloc(int len);

// ══════════════════════════════════════════════════════════
// zlib 接口声明
//   所有编译器（TCC/GCC/Clang）统一使用内置 zlib 源码（include/os/zlib_src/）。
//   编译时将 zlib 源码 .c 文件加入编译列表静态链接，无需外部 -lz 或 zlib1.dll。
//   这确保纯 TCC 环境也能使用 zlib/zip 扩展，零运行时依赖。
// ══════════════════════════════════════════════════════════
// Windows + TCC 的 errno.h 不定义 EWOULDBLOCK，zlib 源码 gzread.c 需要
#ifndef EWOULDBLOCK
#define EWOULDBLOCK EAGAIN
#endif
#include "zlib_src/zlib.h"

// ── zlib 常量（CodeGenerator 需要 TPHP_CONST_ 前缀）──
#define TPHP_CONST_ZLIB_ENCODING_RAW         -15    // 原始 DEFLATE
#define TPHP_CONST_ZLIB_ENCODING_GZIP         31    // gzip 格式 (RFC 1952)
#define TPHP_CONST_ZLIB_ENCODING_DEFLATE      15    // zlib 格式 (RFC 1950)
#define TPHP_CONST_ZLIB_NO_COMPRESSION         0    // 不压缩
#define TPHP_CONST_ZLIB_BEST_SPEED             1    // 最快速度
#define TPHP_CONST_ZLIB_BEST_COMPRESSION       9    // 最小体积
#define TPHP_CONST_ZLIB_DEFAULT_COMPRESSION   -1    // 默认 (zlib 默认=6)
#define TPHP_CONST_ZLIB_VERSION               ZLIB_VERSION
#define TPHP_CONST_ZLIB_VERNUM                ZLIB_VERNUM
// 旧别名
#define TPHP_CONST_FORCE_GZIP                 31    // = ZLIB_ENCODING_GZIP
#define TPHP_CONST_FORCE_DEFLATE              15    // = ZLIB_ENCODING_DEFLATE
// 状态码
#define TPHP_CONST_ZLIB_OK                    Z_OK
#define TPHP_CONST_ZLIB_STREAM_END            Z_STREAM_END
#define TPHP_CONST_ZLIB_NEED_DICT             Z_NEED_DICT
#define TPHP_CONST_ZLIB_ERRNO                 Z_ERRNO
#define TPHP_CONST_ZLIB_STREAM_ERROR          Z_STREAM_ERROR
#define TPHP_CONST_ZLIB_DATA_ERROR            Z_DATA_ERROR
#define TPHP_CONST_ZLIB_MEM_ERROR             Z_MEM_ERROR
#define TPHP_CONST_ZLIB_BUF_ERROR             Z_BUF_ERROR
#define TPHP_CONST_ZLIB_VERSION_ERROR         Z_VERSION_ERROR
// flush 模式
#define TPHP_CONST_ZLIB_NO_FLUSH              Z_NO_FLUSH
#define TPHP_CONST_ZLIB_PARTIAL_FLUSH         Z_PARTIAL_FLUSH
#define TPHP_CONST_ZLIB_SYNC_FLUSH            Z_SYNC_FLUSH
#define TPHP_CONST_ZLIB_FULL_FLUSH            Z_FULL_FLUSH
#define TPHP_CONST_ZLIB_FINISH                Z_FINISH
#define TPHP_CONST_ZLIB_BLOCK                 Z_BLOCK
// 压缩策略
#define TPHP_CONST_ZLIB_FILTERED              Z_FILTERED
#define TPHP_CONST_ZLIB_HUFFMAN_ONLY          Z_HUFFMAN_ONLY
#define TPHP_CONST_ZLIB_RLE                   Z_RLE
#define TPHP_CONST_ZLIB_FIXED                 Z_FIXED
#define TPHP_CONST_ZLIB_DEFAULT_STRATEGY      Z_DEFAULT_STRATEGY

// ══════════════════════════════════════════════════════════
// 内部通用压缩
//   encoding: ZLIB_ENCODING_RAW / DEFLATE / GZIP
//   level: -1~9
// ══════════════════════════════════════════════════════════
static inline t_string _tphp_zlib_compress(const char* data, int len, int level, int encoding) {
    if (data == NULL || len <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib compress: empty input")));
        return (t_string){0};
    }

    // 规范化 level
    if (level < -1 || level > 9) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib compress: invalid compression level")));
        return (t_string){0};
    }
    int zlevel = (level == -1) ? Z_DEFAULT_COMPRESSION : level;

    // 估算压缩后最大大小
    // compressBound 返回 zlib 格式上界；gzip 格式 (enc=31) 有更多头/尾部开销，需额外余量
    uLongf bound = compressBound((uLong)len);
    if (bound < 16) bound = 16;
    bound += 64;  // gzip 头/CRC32/原始大小 等额外开销

    char* buf = str_pool_alloc((int)bound);
    if (buf == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib compress: out of memory")));
        return (t_string){0};
    }

    z_stream strm;
    memset(&strm, 0, sizeof(strm));
    strm.next_in   = (Bytef*)data;
    strm.avail_in  = (uInt)len;
    strm.next_out  = (Bytef*)buf;
    strm.avail_out = (uInt)bound;

    // encoding 直接作为 windowBits：
    //   15 = zlib, -15 = raw, 31 = gzip
    int ret = deflateInit2(&strm, zlevel, Z_DEFLATED, encoding, 8, Z_DEFAULT_STRATEGY);
    if (ret != Z_OK) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib compress: deflateInit2 failed")));
        return (t_string){0};
    }

    ret = deflate(&strm, Z_FINISH);
    deflateEnd(&strm);

    if (ret != Z_STREAM_END) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib compress: deflate failed")));
        return (t_string){0};
    }

    return (t_string){buf, (int)strm.total_out};
}

// ══════════════════════════════════════════════════════════
// 内部通用解压
//   encoding: ZLIB_ENCODING_RAW / DEFLATE / GZIP
//             0 = 自动检测（windowBits | 32）
//   max_length: 0 = 无限制, >0 = 限制最大输出
// ══════════════════════════════════════════════════════════
static inline t_string _tphp_zlib_decompress(const char* data, int len, int max_length, int encoding) {
    if (data == NULL || len <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: empty input data")));
        return (t_string){0};
    }
    if (max_length < 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: max_length must be greater than or equal to 0")));
        return (t_string){0};
    }

    z_stream strm;
    memset(&strm, 0, sizeof(strm));
    strm.next_in   = (Bytef*)data;
    strm.avail_in  = (uInt)len;

    // encoding: 0 表示自动检测（zlib 或 gzip），用 windowBits | 32
    int wb = encoding;
    if (wb == 0) wb = 32 + 15;  // auto-detect

    int ret = inflateInit2(&strm, wb);
    if (ret != Z_OK) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: inflateInit2 failed")));
        return (t_string){0};
    }

    // 初始缓冲区（malloc，因为大小未知需要可能扩展）
    int cap = (max_length > 0) ? max_length : (len * 4 + 1024);
    if (cap < 256) cap = 256;
    // 安全上限：128MB
    #define _ZLIB_DECOMPRESS_MAX  (128 * 1024 * 1024)
    if (cap > _ZLIB_DECOMPRESS_MAX) cap = _ZLIB_DECOMPRESS_MAX;

    char* tmp = (char*)malloc((size_t)cap);
    if (tmp == NULL) {
        inflateEnd(&strm);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: out of memory")));
        return (t_string){0};
    }

    strm.next_out  = (Bytef*)tmp;
    strm.avail_out = (uInt)cap;

    // 循环解压，动态扩展缓冲区
    for (;;) {
        ret = inflate(&strm, Z_NO_FLUSH);

        if (ret == Z_STREAM_END) break;

        if (ret == Z_OK || ret == Z_BUF_ERROR) {
            // 缓冲区不够，需要扩展
            if (strm.avail_out == 0) {
                int newcap = cap * 2;
                // 检查 max_length 限制
                if (max_length > 0 && newcap > max_length) {
                    newcap = max_length;
                    if (newcap <= cap) {
                        // 已达上限
                        inflateEnd(&strm);
                        // 仍然返回已解压的部分
                        char* out = str_pool_alloc((int)strm.total_out);
                        if (out) memcpy(out, tmp, (size_t)strm.total_out);
                        free(tmp);
                        return (t_string){out, (int)strm.total_out};
                    }
                }
                if (newcap > _ZLIB_DECOMPRESS_MAX) newcap = _ZLIB_DECOMPRESS_MAX;
                if (newcap <= cap) {
                    inflateEnd(&strm);
                    free(tmp);
                    tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: output exceeds 128MB limit")));
                    return (t_string){0};
                }
                char* newtmp = (char*)realloc(tmp, (size_t)newcap);
                if (newtmp == NULL) {
                    inflateEnd(&strm);
                    free(tmp);
                    tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: out of memory (realloc)")));
                    return (t_string){0};
                }
                tmp = newtmp;
                strm.next_out  = (Bytef*)(tmp + strm.total_out);
                strm.avail_out = (uInt)(newcap - strm.total_out);
                cap = newcap;
                continue;
            }
            // avail_out != 0 的情况
            if (ret == Z_OK) {
                continue;  // 解压进行中，继续循环
            }
            // Z_BUF_ERROR 且 avail_out != 0：可能需要更多输入
            if (strm.avail_in == 0) {
                break;  // 输入耗尽
            }
        }

        // 其他错误
        inflateEnd(&strm);
        free(tmp);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: inflate failed")));
        return (t_string){0};
    }

    inflateEnd(&strm);

    // 复制到 str_pool
    int out_len = (int)strm.total_out;
    char* out = str_pool_alloc(out_len);
    if (out == NULL) {
        free(tmp);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib decompress: out of memory (str_pool)")));
        return (t_string){0};
    }
    memcpy(out, tmp, (size_t)out_len);
    free(tmp);

    return (t_string){out, out_len};
}

// ══════════════════════════════════════════════════════════
// 公共 API — 6 个函数
// ══════════════════════════════════════════════════════════

/**
 * gzcompress(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_DEFLATE): string
 *
 * 压缩字符串（zlib DEFLATE 格式，默认 RFC 1950）。
 */
static inline t_string tphp_fn_gzcompress(t_string data, t_int level, t_int encoding) {
    (void)encoding;  // gzcompress 固定用传入的 encoding（默认 15=zlib）
    return _tphp_zlib_compress(STR_PTR(data), data.length, (int)level, (int)encoding);
}

/**
 * gzuncompress(string $data, int $max_length = 0, int $encoding = ZLIB_ENCODING_DEFLATE): string
 *
 * 解压 gzcompress 的输出（zlib 格式）。
 */
static inline t_string tphp_fn_gzuncompress(t_string data, t_int max_length, t_int encoding) {
    return _tphp_zlib_decompress(STR_PTR(data), data.length, (int)max_length, (int)encoding);
}

/**
 * gzencode(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_GZIP): string
 *
 * 创建 gzip 格式 (.gz) 压缩数据（RFC 1952）。
 */
static inline t_string tphp_fn_gzencode(t_string data, t_int level, t_int encoding) {
    return _tphp_zlib_compress(STR_PTR(data), data.length, (int)level, (int)encoding);
}

/**
 * gzdecode(string $data, int $max_length = 0): string
 *
 * 解码 gzip 格式压缩数据。自动检测 gzip/zlib/raw 格式。
 */
static inline t_string tphp_fn_gzdecode(t_string data, t_int max_length) {
    // 0 = 自动检测格式
    return _tphp_zlib_decompress(STR_PTR(data), data.length, (int)max_length, 0);
}

/**
 * gzdeflate(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_RAW): string
 *
 * 原始 DEFLATE 压缩（不带任何头部/校验，RFC 1951）。
 */
static inline t_string tphp_fn_gzdeflate(t_string data, t_int level, t_int encoding) {
    return _tphp_zlib_compress(STR_PTR(data), data.length, (int)level, (int)encoding);
}

/**
 * gzinflate(string $data, int $max_length = 0): string
 *
 * 解压原始 DEFLATE 数据（raw，无头，windowBits=-15）。
 */
static inline t_string tphp_fn_gzinflate(t_string data, t_int max_length) {
    return _tphp_zlib_decompress(STR_PTR(data), data.length, (int)max_length, TPHP_CONST_ZLIB_ENCODING_RAW);
}

// ── 额外头文件依赖（gz 文件 API + 上下文 API）──
#include "object/resource.h"
#include "val.h"
#include "array.h"

// ══════════════════════════════════════════════════════════
// zlib_encode / zlib_decode — 通用编码/解码
// ══════════════════════════════════════════════════════════

/**
 * zlib_encode(string $data, int $encoding, int $level = -1): string
 *   encoding 指定格式（ZLIB_ENCODING_RAW/GZIP/DEFLATE）。
 */
static inline t_string tphp_fn_zlib_encode(t_string data, t_int encoding, t_int level) {
    return _tphp_zlib_compress(STR_PTR(data), data.length, (int)level, (int)encoding);
}

/**
 * zlib_decode(string $data, int $max_length = 0): string
 *   自动检测格式（zlib/gzip/raw）。
 */
static inline t_string tphp_fn_zlib_decode(t_string data, t_int max_length) {
    return _tphp_zlib_decompress(STR_PTR(data), data.length, (int)max_length, 0);
}

// ══════════════════════════════════════════════════════════
// gz 文件流 API（gzopen/gzread/gzwrite/gzeof/gzgets/gzgetc/
//               gzrewind/gzseek/gztell/gzpassthru/gzputs/gzclose/gzflush）
//   使用 zlib 的 gzFile API，封装为 Resource
// ══════════════════════════════════════════════════════════

static t_int _tphp_gz_rsrc_type = -1;

static void _tphp_gz_dtor(void* ptr) {
    if (ptr == NULL) return;
    gzFile fp = (gzFile)ptr;
    gzclose(fp);
}

static inline void _tphp_gz_init(void) {
    if (_tphp_gz_rsrc_type < 0) {
        _tphp_gz_rsrc_type = tphp_rt_register_resource_type(_tphp_gz_dtor, "gz");
    }
}

/**
 * gzopen(string $filename, string $mode): Resource
 *   mode 同 fopen，可附加压缩级别数字（如 "wb9"）。
 */
static inline tphp_class_Resource* tphp_fn_gzopen(t_string filename, t_string mode) {
    _tphp_gz_init();

    char pathbuf[4096];
    int plen = filename.length < (int)sizeof(pathbuf) - 1 ? filename.length : (int)sizeof(pathbuf) - 1;
    if (plen < 0) plen = 0;
    if (plen > 0) memcpy(pathbuf, STR_PTR(filename), (size_t)plen);
    pathbuf[plen] = '\0';

    char modebuf[32];
    int mlen = mode.length < (int)sizeof(modebuf) - 1 ? mode.length : (int)sizeof(modebuf) - 1;
    if (mlen < 0) mlen = 0;
    if (mlen > 0) memcpy(modebuf, STR_PTR(mode), (size_t)mlen);
    modebuf[mlen] = '\0';

    gzFile fp = gzopen(pathbuf, modebuf);
    if (fp == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzopen(): failed to open file")));
        return NULL;
    }

    tphp_class_Resource* res = new_tphp_class_Resource();
    if (res == NULL) {
        gzclose(fp);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzopen(): out of memory")));
        return NULL;
    }
    res->type = _tphp_gz_rsrc_type;
    res->ptr = fp;
    tphp_rt_resource_insert(res);
    return res;
}

/**
 * gzclose(Resource $stream): bool
 */
static inline t_bool tphp_fn_gzclose(tphp_class_Resource* stream) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzclose(): invalid resource")));
        return false;
    }
    if (stream->handle >= 0) {
        tphp_rt_resource_delete(stream->handle);
    }
    return true;
}

/**
 * gzread(Resource $stream, int $length): string
 */
static inline t_string tphp_fn_gzread(tphp_class_Resource* stream, t_int length) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzread(): invalid resource")));
        return (t_string){0};
    }
    if (length <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzread(): length must be positive")));
        return (t_string){0};
    }
    gzFile fp = (gzFile)stream->ptr;
    char* buf = str_pool_alloc((int)length);
    if (buf == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzread(): out of memory")));
        return (t_string){0};
    }
    int nread = gzread(fp, buf, (unsigned)length);
    if (nread < 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzread(): read error")));
        return (t_string){0};
    }
    return (t_string){buf, nread};
}

/**
 * gzwrite(Resource $stream, string $data, int $length = 0): int
 *   $length = 0 → 写入全部 $data
 */
static inline t_int tphp_fn_gzwrite(tphp_class_Resource* stream, t_string data, t_int length) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzwrite(): invalid resource")));
        return 0;
    }
    gzFile fp = (gzFile)stream->ptr;
    int wlen = (length > 0 && length < data.length) ? (int)length : data.length;
    if (wlen <= 0) return 0;
    int written = gzwrite(fp, STR_PTR(data), (unsigned)wlen);
    return (t_int)written;
}

/** gzputs — gzwrite 的别名 */
#define tphp_fn_gzputs tphp_fn_gzwrite

/**
 * gzeof(Resource $stream): bool
 */
static inline t_bool tphp_fn_gzeof(tphp_class_Resource* stream) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzeof(): invalid resource")));
        return true;
    }
    return (t_bool)gzeof((gzFile)stream->ptr);
}

/**
 * gzgets(Resource $stream, int $length = 0): string
 *   $length = 0 → 读取到行尾或 EOF（最多 8KB）
 */
static inline t_string tphp_fn_gzgets(tphp_class_Resource* stream, t_int length) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzgets(): invalid resource")));
        return (t_string){0};
    }
    gzFile fp = (gzFile)stream->ptr;
    int maxlen = (length > 0) ? (int)length : 8192;
    char* buf = str_pool_alloc(maxlen);
    if (buf == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzgets(): out of memory")));
        return (t_string){0};
    }
    char* ret = gzgets(fp, buf, maxlen);
    if (ret == NULL) return (t_string){0};
    return (t_string){buf, (int)strlen(buf)};
}

/**
 * gzgetc(Resource $stream): string
 *   EOF 返回空字符串。
 */
static inline t_string tphp_fn_gzgetc(tphp_class_Resource* stream) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzgetc(): invalid resource")));
        return (t_string){0};
    }
    int c = gzgetc((gzFile)stream->ptr);
    if (c < 0) return (t_string){0};
    char* buf = str_pool_alloc(1);
    if (buf == NULL) return (t_string){0};
    buf[0] = (char)c;
    return (t_string){buf, 1};
}

/**
 * gzrewind(Resource $stream): bool
 */
static inline t_bool tphp_fn_gzrewind(tphp_class_Resource* stream) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzrewind(): invalid resource")));
        return false;
    }
    return (t_bool)(gzrewind((gzFile)stream->ptr) == 0);
}

/**
 * gzseek(Resource $stream, int $offset, int $whence = SEEK_SET): int
 *   返回新位置。注意：zlib gzseek 只支持 SEEK_SET 和 SEEK_CUR（仅向前）。
 */
static inline t_int tphp_fn_gzseek(tphp_class_Resource* stream, t_int offset, t_int whence) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzseek(): invalid resource")));
        return -1;
    }
    if (whence != 0 && whence != 1) {  // SEEK_SET=0, SEEK_CUR=1
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzseek(): only SEEK_SET and SEEK_CUR are supported")));
        return -1;
    }
    return (t_int)gzseek((gzFile)stream->ptr, (long)offset, (int)whence);
}

/**
 * gztell(Resource $stream): int
 */
static inline t_int tphp_fn_gztell(tphp_class_Resource* stream) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gztell(): invalid resource")));
        return -1;
    }
    return (t_int)gztell((gzFile)stream->ptr);
}

/**
 * gzpassthru(Resource $stream): int
 *   输出剩余所有数据到 stdout，返回输出的字节数。
 */
static inline t_int tphp_fn_gzpassthru(tphp_class_Resource* stream) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzpassthru(): invalid resource")));
        return 0;
    }
    gzFile fp = (gzFile)stream->ptr;
    char buf[8192];
    t_int total = 0;
    int n;
    while ((n = gzread(fp, buf, sizeof(buf))) > 0) {
        fwrite(buf, 1, (size_t)n, stdout);
        total += n;
    }
    return total;
}

/**
 * gzflush(Resource $stream, int $flush = ZLIB_SYNC_FLUSH): bool
 */
static inline t_bool tphp_fn_gzflush(tphp_class_Resource* stream, t_int flush) {
    if (stream == NULL || stream->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzflush(): invalid resource")));
        return false;
    }
    return (t_bool)(gzflush((gzFile)stream->ptr, (int)flush) == Z_OK);
}

// ══════════════════════════════════════════════════════════
// gzfile / readgzfile — 便捷函数
// ══════════════════════════════════════════════════════════

/**
 * gzfile(string $filename): array
 *   读取整个 gz 文件到数组（每行一个元素）。
 */
static inline t_array* tphp_fn_gzfile(t_string filename) {
    _tphp_gz_init();

    char pathbuf[4096];
    int plen = filename.length < (int)sizeof(pathbuf) - 1 ? filename.length : (int)sizeof(pathbuf) - 1;
    if (plen < 0) plen = 0;
    if (plen > 0) memcpy(pathbuf, STR_PTR(filename), (size_t)plen);
    pathbuf[plen] = '\0';

    gzFile fp = gzopen(pathbuf, "rb");
    if (fp == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("gzfile(): failed to open file")));
        return NULL;
    }

    t_array* result = tphp_fn_arr_create(0);
    if (result == NULL) {
        gzclose(fp);
        return NULL;
    }

    char buf[8192];
    while (1) {
        char* ret = gzgets(fp, buf, sizeof(buf));
        if (ret == NULL) break;
        int len = (int)strlen(buf);
        char* s = str_pool_alloc(len);
        if (s != NULL) memcpy(s, buf, (size_t)len);
        tphp_fn_arr_push(result, VAR_STRING(((t_string){s, len})));
    }

    gzclose(fp);
    return result;
}

/**
 * readgzfile(string $filename): int
 *   读取整个 gz 文件并输出到 stdout，返回输出的字节数。
 */
static inline t_int tphp_fn_readgzfile(t_string filename) {
    _tphp_gz_init();

    char pathbuf[4096];
    int plen = filename.length < (int)sizeof(pathbuf) - 1 ? filename.length : (int)sizeof(pathbuf) - 1;
    if (plen < 0) plen = 0;
    if (plen > 0) memcpy(pathbuf, STR_PTR(filename), (size_t)plen);
    pathbuf[plen] = '\0';

    gzFile fp = gzopen(pathbuf, "rb");
    if (fp == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("readgzfile(): failed to open file")));
        return 0;
    }

    char buf[8192];
    t_int total = 0;
    int n;
    while ((n = gzread(fp, buf, sizeof(buf))) > 0) {
        fwrite(buf, 1, (size_t)n, stdout);
        total += n;
    }
    gzclose(fp);
    return total;
}

// ══════════════════════════════════════════════════════════
// 增量上下文 API（deflate_init / deflate_add /
//                inflate_init / inflate_add /
//                inflate_get_status / inflate_get_read_len）
// ══════════════════════════════════════════════════════════

typedef struct {
    z_stream strm;
    int initialized;    // 0=未初始化, 1=活跃, 2=已完成(Z_FINISH)
    int is_deflate;     // 1=deflate, 0=inflate
    int encoding;       // windowBits
    char *outbuf;       // 输出缓冲区（malloc，上下文持有）
    int outcap;
    int status;         // 最近的 zlib 状态码
} _tphp_zlib_context;

static t_int _tphp_zlib_ctx_rsrc_type = -1;

static void _tphp_zlib_ctx_dtor(void* ptr) {
    if (ptr == NULL) return;
    _tphp_zlib_context* ctx = (_tphp_zlib_context*)ptr;
    if (ctx->initialized == 1) {
        if (ctx->is_deflate) deflateEnd(&ctx->strm);
        else inflateEnd(&ctx->strm);
    }
    if (ctx->outbuf) free(ctx->outbuf);
    free(ctx);
}

static inline void _tphp_zlib_ctx_init_type(void) {
    if (_tphp_zlib_ctx_rsrc_type < 0) {
        _tphp_zlib_ctx_rsrc_type = tphp_rt_register_resource_type(_tphp_zlib_ctx_dtor, "zlib_ctx");
    }
}

static inline tphp_class_Resource* _tphp_zlib_ctx_create(_tphp_zlib_context* ctx) {
    tphp_class_Resource* res = new_tphp_class_Resource();
    if (res == NULL) {
        _tphp_zlib_ctx_dtor(ctx);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zlib context: out of memory")));
        return NULL;
    }
    res->type = _tphp_zlib_ctx_rsrc_type;
    res->ptr = ctx;
    tphp_rt_resource_insert(res);
    return res;
}

/**
 * deflate_init(int $encoding, int $level = -1): Resource
 *   encoding: ZLIB_ENCODING_RAW(-15) / DEFLATE(15) / GZIP(31)
 */
static inline tphp_class_Resource* tphp_fn_deflate_init(t_int encoding, t_int level) {
    _tphp_zlib_ctx_init_type();

    if (level < -1 || level > 9) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_init(): invalid compression level")));
        return NULL;
    }

    _tphp_zlib_context* ctx = (_tphp_zlib_context*)calloc(1, sizeof(_tphp_zlib_context));
    if (ctx == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_init(): out of memory")));
        return NULL;
    }
    ctx->is_deflate = 1;
    ctx->encoding = (int)encoding;
    ctx->outcap = 65536;
    ctx->outbuf = (char*)malloc((size_t)ctx->outcap);
    if (ctx->outbuf == NULL) {
        free(ctx);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_init(): out of memory")));
        return NULL;
    }

    int zlevel = (level == -1) ? Z_DEFAULT_COMPRESSION : (int)level;
    memset(&ctx->strm, 0, sizeof(ctx->strm));
    int ret = deflateInit2(&ctx->strm, zlevel, Z_DEFLATED, (int)encoding, 8, Z_DEFAULT_STRATEGY);
    if (ret != Z_OK) {
        free(ctx->outbuf);
        free(ctx);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_init(): deflateInit2 failed")));
        return NULL;
    }
    ctx->initialized = 1;
    ctx->status = Z_OK;
    return _tphp_zlib_ctx_create(ctx);
}

/**
 * deflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string
 *   flush_mode=ZLIB_FINISH(4) 表示输入结束。
 */
static inline t_string tphp_fn_deflate_add(tphp_class_Resource* ctx_res, t_string data, t_int flush_mode) {
    if (ctx_res == NULL || ctx_res->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_add(): invalid context")));
        return (t_string){0};
    }
    _tphp_zlib_context* ctx = (_tphp_zlib_context*)ctx_res->ptr;

    if (!ctx->is_deflate || ctx->initialized != 1) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_add(): not a deflate context or already finished")));
        return (t_string){0};
    }

    int flush = (int)flush_mode;
    if (flush != Z_NO_FLUSH && flush != Z_PARTIAL_FLUSH && flush != Z_SYNC_FLUSH
        && flush != Z_FULL_FLUSH && flush != Z_FINISH && flush != Z_BLOCK) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_add(): invalid flush mode")));
        return (t_string){0};
    }

    ctx->strm.next_in = (Bytef*)STR_PTR(data);
    ctx->strm.avail_in = (uInt)data.length;

    int chunk_written = 0;
    for (;;) {
        if (chunk_written + 4096 > ctx->outcap) {
            int newcap = ctx->outcap * 2;
            char* newbuf = (char*)realloc(ctx->outbuf, (size_t)newcap);
            if (newbuf == NULL) {
                tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_add(): out of memory")));
                return (t_string){0};
            }
            ctx->outbuf = newbuf;
            ctx->outcap = newcap;
        }

        ctx->strm.next_out = (Bytef*)(ctx->outbuf + chunk_written);
        ctx->strm.avail_out = (uInt)(ctx->outcap - chunk_written);

        int avail_before = (int)ctx->strm.avail_out;
        int ret = deflate(&ctx->strm, flush);
        int produced = avail_before - (int)ctx->strm.avail_out;
        chunk_written += produced;
        ctx->status = ret;

        if (ret == Z_STREAM_END) {
            ctx->initialized = 2;
            break;
        }
        if (ret == Z_OK || ret == Z_BUF_ERROR) {
            if (ctx->strm.avail_in == 0 && ctx->strm.avail_out > 0) break;
            continue;
        }
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_add(): deflate failed")));
        return (t_string){0};
    }

    char* result = str_pool_alloc(chunk_written);
    if (result == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("deflate_add(): out of memory")));
        return (t_string){0};
    }
    if (chunk_written > 0) memcpy(result, ctx->outbuf, (size_t)chunk_written);
    return (t_string){result, chunk_written};
}

/**
 * inflate_init(int $encoding): Resource
 *   encoding: ZLIB_ENCODING_RAW(-15) / DEFLATE(15) / GZIP(31) / 0(自动检测)
 */
static inline tphp_class_Resource* tphp_fn_inflate_init(t_int encoding) {
    _tphp_zlib_ctx_init_type();

    _tphp_zlib_context* ctx = (_tphp_zlib_context*)calloc(1, sizeof(_tphp_zlib_context));
    if (ctx == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_init(): out of memory")));
        return NULL;
    }
    ctx->is_deflate = 0;
    ctx->encoding = (int)encoding;
    ctx->outcap = 65536;
    ctx->outbuf = (char*)malloc((size_t)ctx->outcap);
    if (ctx->outbuf == NULL) {
        free(ctx);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_init(): out of memory")));
        return NULL;
    }

    int wb = (int)encoding;
    if (wb == 0) wb = 32 + 15;  // auto-detect

    memset(&ctx->strm, 0, sizeof(ctx->strm));
    int ret = inflateInit2(&ctx->strm, wb);
    if (ret != Z_OK) {
        free(ctx->outbuf);
        free(ctx);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_init(): inflateInit2 failed")));
        return NULL;
    }
    ctx->initialized = 1;
    ctx->status = Z_OK;
    return _tphp_zlib_ctx_create(ctx);
}

/**
 * inflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string
 */
static inline t_string tphp_fn_inflate_add(tphp_class_Resource* ctx_res, t_string data, t_int flush_mode) {
    if (ctx_res == NULL || ctx_res->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_add(): invalid context")));
        return (t_string){0};
    }
    _tphp_zlib_context* ctx = (_tphp_zlib_context*)ctx_res->ptr;

    if (ctx->is_deflate || ctx->initialized != 1) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_add(): not an inflate context or already finished")));
        return (t_string){0};
    }

    int flush = (int)flush_mode;
    if (flush != Z_NO_FLUSH && flush != Z_PARTIAL_FLUSH && flush != Z_SYNC_FLUSH
        && flush != Z_FULL_FLUSH && flush != Z_FINISH && flush != Z_BLOCK) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_add(): invalid flush mode")));
        return (t_string){0};
    }

    ctx->strm.next_in = (Bytef*)STR_PTR(data);
    ctx->strm.avail_in = (uInt)data.length;

    int chunk_written = 0;
    for (;;) {
        if (chunk_written + 4096 > ctx->outcap) {
            int newcap = ctx->outcap * 2;
            char* newbuf = (char*)realloc(ctx->outbuf, (size_t)newcap);
            if (newbuf == NULL) {
                tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_add(): out of memory")));
                return (t_string){0};
            }
            ctx->outbuf = newbuf;
            ctx->outcap = newcap;
        }

        ctx->strm.next_out = (Bytef*)(ctx->outbuf + chunk_written);
        ctx->strm.avail_out = (uInt)(ctx->outcap - chunk_written);

        int avail_before = (int)ctx->strm.avail_out;
        int ret = inflate(&ctx->strm, flush);
        int produced = avail_before - (int)ctx->strm.avail_out;
        chunk_written += produced;
        ctx->status = ret;

        if (ret == Z_STREAM_END) {
            ctx->initialized = 2;
            break;
        }
        if (ret == Z_OK || ret == Z_BUF_ERROR) {
            if (ctx->strm.avail_in == 0 && ctx->strm.avail_out > 0) break;
            continue;
        }
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_add(): inflate failed")));
        return (t_string){0};
    }

    char* result = str_pool_alloc(chunk_written);
    if (result == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_add(): out of memory")));
        return (t_string){0};
    }
    if (chunk_written > 0) memcpy(result, ctx->outbuf, (size_t)chunk_written);
    return (t_string){result, chunk_written};
}

/**
 * inflate_get_status(Resource $context): int
 *   返回最近的 zlib 状态码（Z_OK / Z_STREAM_END 等）。
 */
static inline t_int tphp_fn_inflate_get_status(tphp_class_Resource* ctx_res) {
    if (ctx_res == NULL || ctx_res->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_get_status(): invalid context")));
        return 0;
    }
    _tphp_zlib_context* ctx = (_tphp_zlib_context*)ctx_res->ptr;
    return (t_int)ctx->status;
}

/**
 * inflate_get_read_len(Resource $context): int
 *   返回已从输入消费的总字节数。
 */
static inline t_int tphp_fn_inflate_get_read_len(tphp_class_Resource* ctx_res) {
    if (ctx_res == NULL || ctx_res->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("inflate_get_read_len(): invalid context")));
        return 0;
    }
    _tphp_zlib_context* ctx = (_tphp_zlib_context*)ctx_res->ptr;
    return (t_int)ctx->strm.total_in;
}
