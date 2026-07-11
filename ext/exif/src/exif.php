<?php
// ext/exif/src/exif.php — EXIF 元数据扩展（纯 phpc，无自定义 C 代码）
//
// 验证 phpc 实用性：仅通过 C 标准库函数 (fopen/fgetc/fseek/ftell/fwrite/fclose)
// 实现二进制 JPEG/TIFF EXIF 格式解析，无需编写任何 C 代码。
//
// 设计要点（phpc 实用性验证，已用新特性全面优化）：
//   1. 所有函数参数/返回值使用 tphp 类型(int/string/array)，不暴露 C 类型
//   2. FILE* 指针通过 phpc_ptr_to_int() 转为 t_int 在 PHP 层流转
//      函数内部用 phpc_int_to_ptr() 转回 void* 调用 C 库
//   3. ASCII 字符串读取：fgetc 逐字节 + chr() 拼接
//   4. 整数读取：fseek + fgetc 逐字节组合（支持 LE/BE 字节序）
//   5. EXIF/JPEG/TIFF 格式解析完全在 PHP 层完成
//   6. 二进制数据构造：chr() + 字符串拼接 + C->fwrite()
//   7. 内存管理：FILE* 用 C->fclose() 显式关闭（非 malloc 指针，不适用 phpc_auto）

#include <stdio.h>
#include <stdlib.h>

// ── 图像类型常量（与 PHP IMAGETYPE_* 对齐） ──
const IMAGETYPE_GIF = 1;
const IMAGETYPE_JPEG = 2;
const IMAGETYPE_PNG = 3;
const IMAGETYPE_BMP = 6;
const IMAGETYPE_TIFF_II = 7;
const IMAGETYPE_TIFF_MM = 8;
const IMAGETYPE_WEBP = 18;

// ── TIFF 数据类型 ──
const EXIF_TYPE_BYTE = 1;
const EXIF_TYPE_ASCII = 2;
const EXIF_TYPE_SHORT = 3;
const EXIF_TYPE_LONG = 4;
const EXIF_TYPE_RATIONAL = 5;
const EXIF_TYPE_UNDEFINED = 7;
const EXIF_TYPE_SLONG = 9;
const EXIF_TYPE_SRATIONAL = 10;

// ════════════════════════════════════════════════════════════
// 内部辅助：二进制读取（通过 C 标准库 fgetc）
//   $fp 为 t_int（指针值），内部用 phpc_int_to_ptr 转回 void*
// ════════════════════════════════════════════════════════════

// 读取文件指定偏移处的单字节
function exif_rd_byte(int $fp, int $offset): int
{
    C.void* $f = phpc_int_to_ptr($fp);
    C->fseek($f, c_int($offset), c_int(0));
    return php_int(C->fgetc($f));
}

// 读取 16 位整数（支持 LE/BE）
function exif_rd16(int $fp, int $offset, int $le): int
{
    C.void* $f = phpc_int_to_ptr($fp);
    C->fseek($f, c_int($offset), c_int(0));
    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    if ($le == 1) {
        return $b0 | ($b1 << 8);
    }
    return ($b0 << 8) | $b1;
}

// 读取 32 位整数（支持 LE/BE）
function exif_rd32(int $fp, int $offset, int $le): int
{
    C.void* $f = phpc_int_to_ptr($fp);
    C->fseek($f, c_int($offset), c_int(0));
    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    $b2 = php_int(C->fgetc($f));
    $b3 = php_int(C->fgetc($f));
    if ($le == 1) {
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }
    return ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
}

// 读取 ASCII 字符串（fgetc 逐字节 + chr 拼接，遇到 \0 或 EOF 停止）
function exif_rd_str(int $fp, int $offset): string
{
    C.void* $f = phpc_int_to_ptr($fp);
    C->fseek($f, c_int($offset), c_int(0));
    $s = "";
    while (true) {
        $c = php_int(C->fgetc($f));
        if ($c == 0 || $c < 0) {
            break;
        }
        $s .= chr($c);
    }
    return $s;
}

// ════════════════════════════════════════════════════════════
// 内部辅助：标签名/类型
// ════════════════════════════════════════════════════════════

function exif_tag_name(int $tag): string
{
    if ($tag == 0x010E) { return "ImageDescription"; }
    if ($tag == 0x010F) { return "Make"; }
    if ($tag == 0x0110) { return "Model"; }
    if ($tag == 0x0112) { return "Orientation"; }
    if ($tag == 0x011A) { return "XResolution"; }
    if ($tag == 0x011B) { return "YResolution"; }
    if ($tag == 0x0128) { return "ResolutionUnit"; }
    if ($tag == 0x0131) { return "Software"; }
    if ($tag == 0x0132) { return "DateTime"; }
    if ($tag == 0x013B) { return "Artist"; }
    if ($tag == 0x8298) { return "Copyright"; }
    if ($tag == 0x8769) { return "ExifIFDPointer"; }
    if ($tag == 0x8825) { return "GPSIFDPointer"; }
    if ($tag == 0x829A) { return "ExposureTime"; }
    if ($tag == 0x829D) { return "FNumber"; }
    if ($tag == 0x8827) { return "ISOSpeedRatings"; }
    if ($tag == 0x9003) { return "DateTimeOriginal"; }
    if ($tag == 0x9004) { return "DateTimeDigitized"; }
    if ($tag == 0x9204) { return "ExposureBiasValue"; }
    if ($tag == 0x9207) { return "MeteringMode"; }
    if ($tag == 0x9209) { return "Flash"; }
    if ($tag == 0x920A) { return "FocalLength"; }
    if ($tag == 0xA001) { return "ColorSpace"; }
    if ($tag == 0xA002) { return "ExifImageWidth"; }
    if ($tag == 0xA003) { return "ExifImageLength"; }
    if ($tag == 0xA403) { return "WhiteBalance"; }
    if ($tag == 0x0001) { return "GPSLatitudeRef"; }
    if ($tag == 0x0002) { return "GPSLatitude"; }
    if ($tag == 0x0003) { return "GPSLongitudeRef"; }
    if ($tag == 0x0004) { return "GPSLongitude"; }
    if ($tag == 0x0005) { return "GPSAltitudeRef"; }
    if ($tag == 0x0006) { return "GPSAltitude"; }
    return "";
}

function exif_type_size(int $type): int
{
    if ($type == EXIF_TYPE_BYTE) { return 1; }
    if ($type == EXIF_TYPE_ASCII) { return 1; }
    if ($type == EXIF_TYPE_UNDEFINED) { return 1; }
    if ($type == EXIF_TYPE_SHORT) { return 2; }
    if ($type == EXIF_TYPE_LONG) { return 4; }
    if ($type == EXIF_TYPE_SLONG) { return 4; }
    if ($type == EXIF_TYPE_RATIONAL) { return 8; }
    if ($type == EXIF_TYPE_SRATIONAL) { return 8; }
    return 1;
}

// ════════════════════════════════════════════════════════════
// 内部辅助：值提取
// ════════════════════════════════════════════════════════════

// 根据 TIFF 数据类型提取 PHP 值（统一返回 string 避免 mixed 在数组中的类型追踪问题）
function exif_get_value(int $fp, int $val_offset, int $type, int $count, int $le): string
{
    if ($type == EXIF_TYPE_ASCII) {
        return exif_rd_str($fp, $val_offset);
    }
    if ($type == EXIF_TYPE_BYTE) {
        return "" . exif_rd_byte($fp, $val_offset);
    }
    if ($type == EXIF_TYPE_SHORT) {
        return "" . exif_rd16($fp, $val_offset, $le);
    }
    if ($type == EXIF_TYPE_LONG) {
        return "" . exif_rd32($fp, $val_offset, $le);
    }
    if ($type == EXIF_TYPE_SLONG) {
        return "" . exif_rd32($fp, $val_offset, $le);
    }
    if ($type == EXIF_TYPE_RATIONAL || $type == EXIF_TYPE_SRATIONAL) {
        $num = exif_rd32($fp, $val_offset, $le);
        $den = exif_rd32($fp, $val_offset + 4, $le);
        if ($den == 0) { return "0"; }
        if ($num % $den == 0) {
            return "" . ($num / $den);
        }
        return $num . "/" . $den;
    }
    // UNDEFINED / 其他 — 返回 count
    return "" . $count;
}

// ════════════════════════════════════════════════════════════
// 内部辅助：IFD 解析
// ════════════════════════════════════════════════════════════

// 解析一个 IFD，将条目添加到 result 数组
function exif_parse_ifd(int $fp, int $ifd_offset, int $tiff_start, int $le, array $result, int $depth): array
{
    if ($depth > 3) { return $result; }

    $count = exif_rd16($fp, $ifd_offset, $le);
    for ($i = 0; $i < $count; $i++) {
        $entry_offset = $ifd_offset + 2 + $i * 12;
        $tag = exif_rd16($fp, $entry_offset, $le);
        $type = exif_rd16($fp, $entry_offset + 2, $le);
        $val_count = exif_rd32($fp, $entry_offset + 4, $le);

        if ($tag == 0 || $type == 0) { continue; }

        // 子 IFD（EXIF IFD / GPS IFD）→ 递归
        if ($tag == 0x8769 || $tag == 0x8825) {
            $sub_rel = exif_rd32($fp, $entry_offset + 8, $le);
            $sub_abs = $tiff_start + $sub_rel;
            $result = exif_parse_ifd($fp, $sub_abs, $tiff_start, $le, $result, $depth + 1);
            continue;
        }

        // 值位置：total <= 4 时内联，否则为 TIFF 相对偏移
        $ts = exif_type_size($type);
        $total = $val_count * $ts;
        if ($total <= 4) {
            $val_offset = $entry_offset + 8;
        } else {
            $rel_offset = exif_rd32($fp, $entry_offset + 8, $le);
            $val_offset = $tiff_start + $rel_offset;
        }

        $name = exif_tag_name($tag);
        if (strlen($name) == 0) {
            $name = "Tag_" . $tag;
        }
        $val = exif_get_value($fp, $val_offset, $type, $val_count, $le);
        $result[$name] = $val;
    }
    return $result;
}

// 解析 TIFF 头 + IFD0
function exif_parse_tiff(int $fp, int $tiff_start, array $result): array
{
    // 字节序
    $bo0 = exif_rd_byte($fp, $tiff_start);
    $bo1 = exif_rd_byte($fp, $tiff_start + 1);
    $le = 0;
    if ($bo0 == 0x49 && $bo1 == 0x49) {
        $le = 1;
    } elseif ($bo0 == 0x4D && $bo1 == 0x4D) {
        $le = 0;
    } else {
        return $result;
    }

    // Magic (0x002A)
    $magic = exif_rd16($fp, $tiff_start + 2, $le);
    if ($magic != 0x002A) { return $result; }

    // IFD0 偏移
    $ifd0_rel = exif_rd32($fp, $tiff_start + 4, $le);
    $ifd0_abs = $tiff_start + $ifd0_rel;

    $result = exif_parse_ifd($fp, $ifd0_abs, $tiff_start, $le, $result, 0);
    return $result;
}

// ════════════════════════════════════════════════════════════
// 公共 API
// ════════════════════════════════════════════════════════════

/**
 * exif_imagetype(string $filename): int
 *
 * 检测图像类型（只看文件头魔数）。
 * 文件无法打开 → throw Exception（I/O 错误，可 try-catch）
 * 未知图像格式 → 返回 0（合理零值）
 */
function exif_imagetype(string $filename): int|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) { throw new Exception("exif_imagetype: unable to open file: " . $filename); }
    C.void* $f = phpc_int_to_ptr($fp);
    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    C->fclose($f);

    if ($b0 == 0x47 && $b1 == 0x49) { return IMAGETYPE_GIF; }       // GIF
    if ($b0 == 0xFF && $b1 == 0xD8) { return IMAGETYPE_JPEG; }      // JPEG
    if ($b0 == 0x89 && $b1 == 0x50) { return IMAGETYPE_PNG; }       // PNG
    if ($b0 == 0x42 && $b1 == 0x4D) { return IMAGETYPE_BMP; }       // BMP
    if ($b0 == 0x49 && $b1 == 0x49) { return IMAGETYPE_TIFF_II; }   // TIFF II
    if ($b0 == 0x4D && $b1 == 0x4D) { return IMAGETYPE_TIFF_MM; }   // TIFF MM
    return 0;
}

/**
 * exif_tagname(int $index): string
 *
 * 根据标签编号返回标签名称。未知返回空字符串。
 */
function exif_tagname(int $index): string
{
    return exif_tag_name($index);
}

/**
 * exif_read_data(string $filename, string $sections = "",
 *                bool $arrays = false, bool $thumbnail = false): array
 *
 * 读取 JPEG/TIFF 文件的 EXIF 头信息。
 * $sections/$arrays/$thumbnail 参数为 PHP 兼容性保留，当前实现忽略。
 * 文件无法打开 → throw Exception（I/O 错误，可 try-catch）
 * 无 EXIF 数据 → 返回空数组（合理空结果）
 */
function exif_read_data(string $filename, string $sections = "",
                        bool $arrays = false, bool $thumbnail = false): array|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) { throw new Exception("exif_read_data: unable to open file: " . $filename); }

    C.void* $f = phpc_int_to_ptr($fp);

    // 获取文件大小
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $size = php_int(C->ftell($f));
    C->fseek($f, c_int(0), c_int(0));  // SEEK_SET

    $result = [];

    // 检查 JPEG SOI
    $b0 = exif_rd_byte($fp, 0);
    $b1 = exif_rd_byte($fp, 1);

    if ($b0 == 0xFF && $b1 == 0xD8) {
        // JPEG: 扫描 APP1 marker
        $pos = 2;
        while ($pos + 4 < $size) {
            $marker = exif_rd_byte($fp, $pos);
            if ($marker != 0xFF) { break; }
            $marker_type = exif_rd_byte($fp, $pos + 1);

            // SOI/EOI
            if ($marker_type == 0xD8 || $marker_type == 0xD9) { $pos += 2; continue; }

            // SOF marker → 提取尺寸
            if ($marker_type >= 0xC0 && $marker_type <= 0xCF
                && $marker_type != 0xC4 && $marker_type != 0xC8 && $marker_type != 0xCC) {
                $result["Height"] = exif_rd16($fp, $pos + 5, 1);
                $result["Width"] = exif_rd16($fp, $pos + 7, 1);
                break;
            }

            // 非 APPn → 停止
            if ($marker_type < 0xE0 || $marker_type > 0xEF) { break; }

            // APPn 段长度
            $seg_len = exif_rd16($fp, $pos + 2, 1);

            // 检查 EXIF APP1
            if ($marker_type == 0xE1 && $seg_len >= 8) {
                $c0 = exif_rd_byte($fp, $pos + 4);
                $c1 = exif_rd_byte($fp, $pos + 5);
                $c2 = exif_rd_byte($fp, $pos + 6);
                $c3 = exif_rd_byte($fp, $pos + 7);
                $c4 = exif_rd_byte($fp, $pos + 8);
                $c5 = exif_rd_byte($fp, $pos + 9);
                if ($c0 == 0x45 && $c1 == 0x78 && $c2 == 0x69 && $c3 == 0x66 && $c4 == 0 && $c5 == 0) {
                    // 找到 EXIF: "Exif\0\0"
                    $tiff_start = $pos + 10;
                    $result = exif_parse_tiff($fp, $tiff_start, $result);
                    break;
                }
            }
            $pos += 2 + $seg_len;
        }
    } elseif (($b0 == 0x49 && $b1 == 0x49) || ($b0 == 0x4D && $b1 == 0x4D)) {
        // TIFF: 直接解析
        $result = exif_parse_tiff($fp, 0, $result);
    }

    C->fclose($f);
    return $result;
}

/**
 * exif_thumbnail(string $filename): array
 *
 * 读取图像的缩略图信息。
 * 文件无法打开 → throw Exception（由 exif_imagetype 传播）
 * 无缩略图 → 返回 width=0/height=0 的数组（合理空结果）
 */
function exif_thumbnail(string $filename): array
{
    $type = exif_imagetype($filename);
    return ["width" => 0, "height" => 0, "imagetype" => $type];
}

// ════════════════════════════════════════════════════════════
// 测试辅助：生成合成 JPEG+EXIF 文件
// ════════════════════════════════════════════════════════════

// LE 16 位编码 → 2 字节字符串
function exif_u16le(int $v): string
{
    return chr($v & 0xFF) . chr(($v >> 8) & 0xFF);
}

// LE 32 位编码 → 4 字节字符串
function exif_u32le(int $v): string
{
    return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 24) & 0xFF);
}

// BE 16 位编码 → 2 字节字符串
function exif_u16be(int $v): string
{
    return chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
}

// BE 32 位编码 → 4 字节字符串
function exif_u32be(int $v): string
{
    return chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
}

// 按字节序编码 16 位（$le=1→LE/II, $le=0→BE/MM）
function exif_u16(int $v, int $le): string
{
    if ($le == 1) { return exif_u16le($v); }
    return exif_u16be($v);
}

// 按字节序编码 32 位
function exif_u32(int $v, int $le): string
{
    if ($le == 1) { return exif_u32le($v); }
    return exif_u32be($v);
}

/**
 * exif_build_tiff(int $le): string
 *
 * 构建完整 TIFF 数据（header + IFD0 + EXIF IFD + GPS IFD + data）。
 * $le=1→LE/II, $le=0→BE/MM。偏移量与字段值固定，仅字节序不同。
 *
 * 布局:
 *   0-7     TIFF header (II/MM, 0x002A, IFD0 offset=8)
 *   8-85    IFD0 (6 entries: Make/Model/Orientation/DateTime/ExifIFD/GPSIFD)
 *   86-122  IFD0 data (Make/Model/DateTime)
 *   123-164 EXIF IFD (3 entries: ExposureTime/FNumber/ISOSpeedRatings)
 *   165-180 EXIF IFD data (ExposureTime/FNumber)
 *   181-210 GPS IFD (2 entries: GPSLatitudeRef/GPSAltitude)
 *   211-218 GPS IFD data (GPSAltitude)
 */
function exif_build_tiff(int $le): string
{
    $s = "";
    // TIFF header
    if ($le == 1) {
        $s .= chr(0x49) . chr(0x49);  // II
    } else {
        $s .= chr(0x4D) . chr(0x4D);  // MM
    }
    $s .= exif_u16(0x002A, $le);       // magic
    $s .= exif_u32(8, $le);            // IFD0 offset

    // ── IFD0 (6 entries) ──
    $s .= exif_u16(6, $le);
    $s .= exif_u16(0x010F, $le) . exif_u16(EXIF_TYPE_ASCII, $le) . exif_u32(11, $le) . exif_u32(86, $le);
    $s .= exif_u16(0x0110, $le) . exif_u16(EXIF_TYPE_ASCII, $le) . exif_u32(6, $le) . exif_u32(97, $le);
    $s .= exif_u16(0x0112, $le) . exif_u16(EXIF_TYPE_SHORT, $le) . exif_u32(1, $le) . exif_u16(1, $le) . exif_u16(0, $le);
    $s .= exif_u16(0x0132, $le) . exif_u16(EXIF_TYPE_ASCII, $le) . exif_u32(20, $le) . exif_u32(103, $le);
    $s .= exif_u16(0x8769, $le) . exif_u16(EXIF_TYPE_LONG, $le) . exif_u32(1, $le) . exif_u32(123, $le);
    $s .= exif_u16(0x8825, $le) . exif_u16(EXIF_TYPE_LONG, $le) . exif_u32(1, $le) . exif_u32(181, $le);
    $s .= exif_u32(0, $le);  // next IFD

    // IFD0 data
    $s .= "TestCamera" . chr(0);                  // 11 bytes (rel 86)
    $s .= "X-100" . chr(0);                        // 6 bytes (rel 97)
    $s .= "2024:01:15 14:30:00" . chr(0);          // 20 bytes (rel 103)

    // ── EXIF IFD (3 entries, at rel 123) ──
    $s .= exif_u16(3, $le);
    $s .= exif_u16(0x829A, $le) . exif_u16(EXIF_TYPE_RATIONAL, $le) . exif_u32(1, $le) . exif_u32(165, $le);
    $s .= exif_u16(0x829D, $le) . exif_u16(EXIF_TYPE_RATIONAL, $le) . exif_u32(1, $le) . exif_u32(173, $le);
    $s .= exif_u16(0x8827, $le) . exif_u16(EXIF_TYPE_SHORT, $le) . exif_u32(1, $le) . exif_u16(200, $le) . exif_u16(0, $le);
    $s .= exif_u32(0, $le);  // next IFD

    // EXIF IFD data
    $s .= exif_u32(1, $le) . exif_u32(125, $le);    // ExposureTime = 1/125 (rel 165)
    $s .= exif_u32(28, $le) . exif_u32(10, $le);     // FNumber = 28/10 (rel 173)

    // ── GPS IFD (2 entries, at rel 181) ──
    $s .= exif_u16(2, $le);
    $s .= exif_u16(0x0001, $le) . exif_u16(EXIF_TYPE_ASCII, $le) . exif_u32(2, $le) . chr(0x4E) . chr(0) . exif_u16(0, $le);
    $s .= exif_u16(0x0006, $le) . exif_u16(EXIF_TYPE_RATIONAL, $le) . exif_u32(1, $le) . exif_u32(211, $le);
    $s .= exif_u32(0, $le);  // next IFD

    // GPS IFD data
    $s .= exif_u32(100, $le) . exif_u32(1, $le);     // GPSAltitude = 100/1 (rel 211)

    return $s;
}

/**
 * exif_make_test_jpeg_ex(string $filename, int $le): int
 *
 * 生成 JPEG+EXIF 文件，$le 控制字节序 (1=LE/II, 0=BE/MM)。返回 0=成功, -1=失败。
 */
function exif_make_test_jpeg_ex(string $filename, int $le): int
{
    $tiff = exif_build_tiff($le);
    $s = "";
    $s .= chr(0xFF) . chr(0xD8);  // SOI
    $s .= chr(0xFF) . chr(0xE1);  // APP1
    $seg_len = 2 + 6 + strlen($tiff);  // 2 (len field) + 6 (Exif\0\0) + tiff
    $s .= chr(($seg_len >> 8) & 0xFF) . chr($seg_len & 0xFF);  // big-endian length
    $s .= "Exif" . chr(0) . chr(0);
    $s .= $tiff;
    $s .= chr(0xFF) . chr(0xD9);  // EOI

    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) { return -1; }
    C.void* $f = phpc_int_to_ptr($fp);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    C->fclose($f);
    return 0;
}

/**
 * exif_make_test_jpeg(string $filename): int
 *
 * 生成合成 JPEG+EXIF 文件（LE 字节序）。返回 0=成功, -1=失败。
 * 便捷包装，等价于 exif_make_test_jpeg_ex($filename, 1)。
 */
function exif_make_test_jpeg(string $filename): int
{
    return exif_make_test_jpeg_ex($filename, 1);
}

/**
 * exif_make_test_tiff(string $filename, int $le): int
 *
 * 生成 TIFF 文件，$le 控制字节序 (1=LE/II, 0=BE/MM)。返回 0=成功, -1=失败。
 */
function exif_make_test_tiff(string $filename, int $le): int
{
    $s = exif_build_tiff($le);
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) { return -1; }
    C.void* $f = phpc_int_to_ptr($fp);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    C->fclose($f);
    return 0;
}

/**
 * exif_make_test_header(string $filename, int $b0, int $b1): int
 *
 * 生成指定 2 字节文件头的文件（用于测试 exif_imagetype）。返回 0=成功, -1=失败。
 */
function exif_make_test_header(string $filename, int $b0, int $b1): int
{
    $s = chr($b0) . chr($b1);
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) { return -1; }
    C.void* $f = phpc_int_to_ptr($fp);
    C->fwrite(c_str($s), c_int(1), c_int(2), $f);
    C->fclose($f);
    return 0;
}
