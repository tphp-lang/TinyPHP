<?php
// ext/gd/src/gd.php — GD 图像处理扩展（纯 phpc，无自定义 C 代码）
//
// 完整模拟 PHP 8.5.8 原生 gd 扩展功能。
// 支持的格式：PNG(基于 zlib)、GIF(LZW)、BMP、GD/GD2、WBMP、XBM、TGA
// 不支持的格式（JPEG/WebP/AVIF/XPM/FreeType）调用时抛出 RuntimeException。

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>

// gd_constants.php 由 #import gd 自动加载（同目录下的 .php 文件一起加载）
// 常量定义见 gd_constants.php（IMG_AVIF/GIF/JPG/PNG/...、IMG_COLOR_*、IMG_ARC_* 等）

// ════════════════════════════════════════════════════════════
// GdImage — GD 图像对象（对应 PHP 8.5 GdImage 类）
//
// 设计说明（phpc 模式，无 C 代码）：
//   - 像素数据全部存储在 PHP 层 array<int>，不使用 C 层 gdImagePtr
//   - 真彩色图像：trueColor=true，pixels 每元素为 0x7FRRGGBB 颜色值（ARGB，A 在高位）
//   - 调色板图像：trueColor=false，pixels 每元素为 palette 数组的索引；
//     palette 每元素为 0x7FRRGGBB 颜色值
//   - brush/tile 为可空 GdImage 引用，因 TinyPHP 不支持 nullable 类型，
//     使用 mixed = null 表示"未设置"（与 curl.php 中 $writeFunction 模式一致）
//   - transparentColor = -1 表示无透明色（对应 libgd GD_NO_TRANSPARENT_COLOR）
//   - interpolationMethod 默认 IMG_BILINEAR_FIXED(3)，与 libgd 默认一致
//   - resolutionX/Y 默认 96 DPI（与 libgd gdImageCreate 默认一致）
//
// 像素颜色值编码（0x7FRRGGBB，4 字节）：
//   位 31..24: Alpha（0x00=不透明，0x7F=完全透明）
//   位 23..16: Red
//   位 15..8 : Green
//   位 7 ..0 : Blue
// ════════════════════════════════════════════════════════════

final class GdImage
{
    // ── 图像尺寸 ──────────────────────────────────────────────
    public int $width = 0;                        // 图像宽度（像素）
    public int $height = 0;                       // 图像高度（像素）

    // ── 图像类型 ──────────────────────────────────────────────
    public bool $trueColor = true;                // true=真彩色，false=调色板

    // ── 像素数据 ──────────────────────────────────────────────
    //   真彩色：每元素为 0x7FRRGGBB 颜色值
    //   调色板：每元素为 palette 数组的索引
    public array $pixels = [];                    // 像素缓冲区（长度 = width * height）

    // ── 调色板（仅调色板图像使用）──────────────────────────
    //   每元素为 0x7FRRGGBB 颜色值
    public array $palette = [];                   // 调色板颜色表

    // ── Alpha 混合与保存 ──────────────────────────────────────
    public bool $alphaBlending = false;           // 是否启用 Alpha 混合（imagealphablending）
    public bool $saveAlpha = false;               // 是否保存 Alpha 通道（imagesavealpha）

    // ── 隔行扫描 ──────────────────────────────────────────────
    public bool $interlace = false;               // 隔行扫描（imageinterlace）

    // ── 裁剪区域 ──────────────────────────────────────────────
    //   [x1, y1, x2, y2]，imagecopy 等操作受此限制
    public array $clip = [];                      // 裁剪矩形（空数组=未设置）

    // ── 画线样式 ──────────────────────────────────────────────
    public int $thickness = 1;                    // 线宽（imagesetthickness）
    public array $style = [];                     // 画线样式（imagesetstyle，颜色索引数组）

    // ── 画笔与平铺图像（可空 GdImage）──────────────────────
    //   TinyPHP 不支持 nullable，用 mixed = null 表示未设置
    public mixed $brush = null;                   // 画笔图像（imagesetbrush）
    public mixed $tile = null;                    // 平铺图像（imagesettile）

    // ── 透明色 ────────────────────────────────────────────────
    public int $transparentColor = -1;            // 透明色索引（-1=无透明色）

    // ── 分辨率 ────────────────────────────────────────────────
    public int $resolutionX = 96;                 // 水平分辨率（DPI）
    public int $resolutionY = 96;                 // 垂直分辨率（DPI）

    // ── 插值方法 ──────────────────────────────────────────────
    //   默认 IMG_BILINEAR_FIXED(3)，与 libgd 默认一致
    public int $interpolationMethod = 3;          // 插值方法（IMG_BILINEAR_FIXED）
}

// ════════════════════════════════════════════════════════════
// GdFont — GD 位图字体对象（对应 PHP 8.5 GdFont 类）
//
// 用于 imageloadfont 加载的位图字体，每个字符为 width×height 的位图。
// glyphs 为扁平数组：glyphs[c * height + row] 为字符 c 第 row 行的位掩码。
//
// 字体格式（与 libgd gdFont 结构一致）：
//   - width:  每个字符的宽度（像素）
//   - height: 每个字符的高度（像素）
//   - glyphs: 扁平字符位图数据，索引 c*height+row 对应字符 c 的第 row 行位掩码
//             位 1=前景色，位 0=背景色
// ════════════════════════════════════════════════════════════

final class GdFont
{
    // ── 字符尺寸 ──────────────────────────────────────────────
    public int $width = 0;                        // 单个字符宽度（像素）
    public int $height = 0;                       // 单个字符高度（像素）

    // ── 字符位图数据（扁平数组）──────────────────────────────
    //   glyphs[c * height + row] = 第 c 个字符第 row 行的位掩码（位 1=前景色）
    public array $glyphs = [];                    // 扁平字符位图数组
}

// ════════════════════════════════════════════════════════════
// 内部辅助函数（gd_ 前缀，避免命名冲突）
//
// 颜色编码：0x7FRRGGBB
//   位 31..24: Alpha（0x00=不透明，0x7F=完全透明，与 PHP alpha 0-127 一致）
//   位 23..16: Red
//   位 15..8 : Green
//   位 7 ..0 : Blue
// ════════════════════════════════════════════════════════════

// gd_make_color — 组装 0x7FRRGGBB 颜色值
//   $r/$g/$b: 0-255（超出范围自动钳位）
//   $a: PHP alpha（0=不透明, 127=完全透明，超出范围自动钳位）
function gd_make_color(int $r, int $g, int $b, int $a): int
{
    if ($r < 0) { $r = 0; } elseif ($r > 255) { $r = 255; }
    if ($g < 0) { $g = 0; } elseif ($g > 255) { $g = 255; }
    if ($b < 0) { $b = 0; } elseif ($b > 255) { $b = 255; }
    if ($a < 0) { $a = 0; } elseif ($a > 127) { $a = 127; }
    return ($a << 24) | ($r << 16) | ($g << 8) | $b;
}

// gd_get_red — 提取红色分量 (0-255)
function gd_get_red(int $color): int
{
    return ($color >> 16) & 0xFF;
}

// gd_get_green — 提取绿色分量 (0-255)
function gd_get_green(int $color): int
{
    return ($color >> 8) & 0xFF;
}

// gd_get_blue — 提取蓝色分量 (0-255)
function gd_get_blue(int $color): int
{
    return $color & 0xFF;
}

// gd_get_alpha — 提取 PHP alpha 分量 (0=不透明, 127=完全透明)
function gd_get_alpha(int $color): int
{
    return ($color >> 24) & 0x7F;
}

// gd_clamp — 钳位到 [min, max] 范围
function gd_clamp(int $v, int $min, int $max): int
{
    if ($v < $min) { return $min; }
    if ($v > $max) { return $max; }
    return $v;
}

// gd_rgb_to_hwb — 将 RGB 转换为 HWB（Hue/Whiteness/Blackness）
//   返回 [hue(0-360 float), whiteness(0-255), blackness(0-255)]
function gd_rgb_to_hwb(int $r, int $g, int $b): array
{
    $maxv = $r;
    if ($g > $maxv) { $maxv = $g; }
    if ($b > $maxv) { $maxv = $b; }
    $minv = $r;
    if ($g < $minv) { $minv = $g; }
    if ($b < $minv) { $minv = $b; }
    $delta = $maxv - $minv;

    $h = 0.0;
    if ($delta > 0) {
        if ($maxv == $r) {
            $h = 60.0 * (($g - $b) / $delta);
        } elseif ($maxv == $g) {
            $h = 60.0 * (2.0 + ($b - $r) / $delta);
        } else {
            $h = 60.0 * (4.0 + ($r - $g) / $delta);
        }
        if ($h < 0.0) { $h = $h + 360.0; }
    }

    $w = $minv;
    $blk = 255 - $maxv;
    return [$h, $w, $blk];
}

// ════════════════════════════════════════════════════════════
// Task 2: 图像创建与销毁
// ════════════════════════════════════════════════════════════

/**
 * imagecreate(int $w, int $h): GdImage
 *
 * 创建调色板图像（256 色上限）。
 * - 初始黑色背景（pixels 全为 0，指向首个调色板项）
 * - trueColor=false，palette 为空（colorsTotal=0，首色由 imagecolorallocate 分配）
 * - 参数 $w/$h 必须 > 0，否则返回 false
 */
function imagecreate(int $w, int $h): GdImage|Exception
{
    if ($w <= 0 || $h <= 0) { throw new Exception("imagecreate: invalid dimensions (w=$w, h=$h)"); }
    $im = new GdImage();
    $im->width = $w;
    $im->height = $h;
    $im->trueColor = false;
    $im->palette = [];
    $im->pixels = array_fill(0, $w * $h, 0);
    $im->alphaBlending = false;
    $im->saveAlpha = false;
    $im->interlace = false;
    $im->clip = [];
    $im->style = [];
    $im->thickness = 1;
    $im->transparentColor = -1;
    $im->resolutionX = 96;
    $im->resolutionY = 96;
    $im->interpolationMethod = 3;
    // brush/tile 默认为 null（类属性声明已设置默认值，无需重复赋值）
    return $im;
}

/**
 * imagecreatetruecolor(int $w, int $h): GdImage|Exception
 *
 * 创建真彩色图像（32 位 RGBA）。
 * - 初始黑色不透明（每个像素 = 0x00000000，alpha=0 不透明）
 * - trueColor=true，palette 不使用
 * - 参数 $w/$h 必须 > 0，否则返回 false
 */
function imagecreatetruecolor(int $w, int $h): GdImage|Exception
{
    if ($w <= 0 || $h <= 0) { throw new Exception("imagecreatetruecolor: invalid dimensions (w=$w, h=$h)"); }
    $im = new GdImage();
    $im->width = $w;
    $im->height = $h;
    $im->trueColor = true;
    $im->palette = [];
    $im->pixels = array_fill(0, $w * $h, 0);
    $im->alphaBlending = true;
    $im->saveAlpha = false;
    $im->interlace = false;
    $im->clip = [];
    $im->style = [];
    $im->thickness = 1;
    $im->transparentColor = -1;
    $im->resolutionX = 96;
    $im->resolutionY = 96;
    $im->interpolationMethod = 3;
    // brush/tile 默认为 null（类属性声明已设置默认值，无需重复赋值）
    return $im;
}

/**
 * imagedestroy(GdImage $image): booltrue
 *
 * 释放图像资源。PHP 8.5 已标记 #[Deprecated]，但仍返回 true。
 * 纯 phpc 实现中图像由 PHP 层管理，无需手动释放。
 */
function imagedestroy(GdImage $image): bool
{
    return true;
}

/**
 * imagesx(GdImage $image): int
 *
 * 返回图像宽度（像素）。
 */
function imagesx(GdImage $image): int
{
    return $image->width;
}

/**
 * imagesy(GdImage $image): int
 *
 * 返回图像高度（像素）。
 */
function imagesy(GdImage $image): int
{
    return $image->height;
}

/**
 * imageistruecolor(GdImage $image): bool
 *
 * 检测图像是否为真彩色。
 */
function imageistruecolor(GdImage $image): bool
{
    return $image->trueColor;
}

// ════════════════════════════════════════════════════════════
// 占位解码器（后续 Task 12-17 实现，当前抛 RuntimeException）
// ════════════════════════════════════════════════════════════

// gd_decode_png 实现见 gd_codec_png.php（Task 17）
// gd_encode_png 实现见 gd_codec_png.php（Task 17）

function gd_decode_gif(string $data): GdImage|Exception
{
    return gd_gif_decode_impl($data);
}

function gd_decode_bmp(string $data): GdImage|Exception
{
    throw new RuntimeException("decoder not yet implemented: BMP");
}

function gd_decode_gd(string $data): GdImage|Exception
{
    throw new RuntimeException("decoder not yet implemented: GD");
}

function gd_decode_gd2(string $data): GdImage|Exception
{
    throw new RuntimeException("decoder not yet implemented: GD2");
}

function gd_decode_wbmp(string $data): GdImage|Exception
{
    int $len = strlen($data);
    if ($len < 4) {
        throw new Exception("gd_decode_wbmp: data too short ($len bytes)");
    }
    int $pos = 0;
    // Type (必须为 0)
    int $type = ord(substr($data, 0, 1));
    $pos = 1;
    if ($type != 0) {
        throw new Exception("gd_decode_wbmp: unsupported type ($type)");
    }
    // Fixed header (必须为 0)
    int $fix = ord(substr($data, 1, 1));
    $pos = 2;
    if ($fix != 0) {
        throw new Exception("gd_decode_wbmp: invalid fixed header ($fix)");
    }
    // 读取宽度（可变长度编码：每字节低 7 位数据，高位续传）
    int $w = 0;
    while (true) {
        if ($pos >= $len) {
            throw new Exception("gd_decode_wbmp: unexpected EOF reading width");
        }
        int $b = ord(substr($data, $pos, 1));
        $pos = $pos + 1;
        $w = ($w << 7) | ($b & 0x7F);
        if (($b & 0x80) == 0) { break; }
    }
    // 读取高度（同上）
    int $h = 0;
    while (true) {
        if ($pos >= $len) {
            throw new Exception("gd_decode_wbmp: unexpected EOF reading height");
        }
        int $b = ord(substr($data, $pos, 1));
        $pos = $pos + 1;
        $h = ($h << 7) | ($b & 0x7F);
        if (($b & 0x80) == 0) { break; }
    }
    if ($w <= 0 || $h <= 0) {
        throw new Exception("gd_decode_wbmp: invalid dimensions ($w x $h)");
    }
    // 每行字节数（MSB 优先，每行字节对齐）
    int $rowBytes = ($w + 7) >> 3;
    if ($pos + $rowBytes * $h > $len) {
        throw new Exception("gd_decode_wbmp: not enough pixel data");
    }
    // 创建真彩色图像：1=黑(0x00000000)，0=白(0x00FFFFFF)
    $im = imagecreatetruecolor($w, $h);
    int $black = gd_make_color(0, 0, 0, 0);
    int $white = gd_make_color(255, 255, 255, 0);
    int $y = 0;
    while ($y < $h) {
        int $rowBase = $pos + $y * $rowBytes;
        int $x = 0;
        while ($x < $w) {
            int $byteIdx = $rowBase + ($x >> 3);
            int $byteVal = ord(substr($data, $byteIdx, 1));
            // WBMP: MSB first — bit 7 is leftmost pixel
            int $bit = ($byteVal >> (7 - ($x & 7))) & 1;
            if ($bit == 1) {
                gd_set_pixel_raw($im, $x, $y, $black);
            } else {
                gd_set_pixel_raw($im, $x, $y, $white);
            }
            $x = $x + 1;
        }
        $y = $y + 1;
    }
    return $im;
}

/**
 * gd_decode_tga — 解码 TGA（Targa）二进制数据为 GdImage
 *
 * 支持范围：
 *   - image type 2（未压缩真彩色）/ 10（RLE 真彩色）
 *   - bpp 24（BGR）/ 32（BGRA）
 *   - top-down（descriptor bit5=1）/ bottom-up（默认）
 *   - 自动跳过 ID 字段与调色板字段
 *
 * 不支持：type 0/1/3/9/11、bpp 16（返回 Exception）
 *
 * TGA 2.0 头（18 字节，小端序）：
 *   [0]    ID length
 *   [1]    color map type (0=无, 1=有)
 *   [2]    image type (2=未压缩真彩色, 10=RLE真彩色)
 *   [3..7] color map spec（忽略，仅用于跳过）
 *   [8..9]  x origin (LE)
 *   [10..11] y origin (LE)
 *   [12..13] width (LE)
 *   [14..15] height (LE)
 *   [16]   bpp (16/24/32)
 *   [17]   image descriptor（bit5=1 top-down，bit0-3=alpha 位数）
 */
function gd_decode_tga(string $data): GdImage|Exception
{
    $len = strlen($data);
    if ($len < 18) {
        throw new Exception("gd_decode_tga: data too short ($len bytes, need >= 18)");
    }

    // ── 读取 18 字节头 ──
    $idLength     = ord(substr($data, 0, 1));
    $colorMapType = ord(substr($data, 1, 1));
    $imageType    = ord(substr($data, 2, 1));
    $width        = ord(substr($data, 12, 1)) | (ord(substr($data, 13, 1)) << 8);
    $height       = ord(substr($data, 14, 1)) | (ord(substr($data, 15, 1)) << 8);
    $bpp          = ord(substr($data, 16, 1));
    $descriptor   = ord(substr($data, 17, 1));
    $topDown      = ($descriptor & 0x20) ? 1 : 0;

    // ── 参数校验 ──
    if ($width <= 0 || $height <= 0) {
        throw new Exception("gd_decode_tga: invalid dimensions (w=$width, h=$height)");
    }
    if ($imageType != 2 && $imageType != 10) {
        throw new Exception("gd_decode_tga: unsupported image type $imageType (only 2/10 supported)");
    }
    if ($bpp != 24 && $bpp != 32) {
        throw new Exception("gd_decode_tga: unsupported bpp $bpp (only 24/32 supported)");
    }

    // ── 创建真彩色图像 ──
    $im = imagecreatetruecolor($width, $height);

    // ── 计算像素数据起始偏移 ──
    $offset = 18 + $idLength;
    if ($colorMapType == 1) {
        // 跳过调色板数据：长度 * 每项字节数
        $cmLength    = ord(substr($data, 5, 1)) | (ord(substr($data, 6, 1)) << 8);
        $cmEntrySize = ord(substr($data, 7, 1));
        $cmBytes     = intval(($cmEntrySize + 7) / 8);
        $offset      = $offset + $cmLength * $cmBytes;
    }

    $bytesPerPixel = intval($bpp / 8);
    $totalPixels   = $width * $height;
    $pixelIdx      = 0;

    // ── 像素行映射：bottom-up 时第一行为图像底行 ──
    //   pixelIdx 为数据流中的线性索引（按行从左到右、从上到下）
    //   bottom-up: 数据行 0 → 图像行 height-1
    //   top-down:  数据行 0 → 图像行 0

    if ($imageType == 2) {
        // ── 未压缩真彩色 ──
        while ($pixelIdx < $totalPixels) {
            if ($offset + $bytesPerPixel > $len) {
                throw new Exception("gd_decode_tga: unexpected end of pixel data at pixel $pixelIdx");
            }
            $b = ord(substr($data, $offset, 1));
            $g = ord(substr($data, $offset + 1, 1));
            $r = ord(substr($data, $offset + 2, 1));
            $a = 0;
            if ($bpp == 32) {
                $ta = ord(substr($data, $offset + 3, 1));
                // TGA: 0=透明, 255=不透明 → PHP: 0=不透明, 127=透明
                $a = 127 - intval($ta / 2);
            }
            $color = gd_make_color($r, $g, $b, $a);

            $x    = $pixelIdx % $width;
            $yRow = intval($pixelIdx / $width);
            if ($topDown) {
                $y = $yRow;
            } else {
                $y = $height - 1 - $yRow;
            }
            gd_set_pixel_raw($im, $x, $y, $color);

            $offset   = $offset + $bytesPerPixel;
            $pixelIdx = $pixelIdx + 1;
        }
    } else {
        // ── RLE 真彩色（type 10）──
        //   packet header: bit7=1 → RLE packet（1 像素 × count）
        //                  bit7=0 → raw packet（count 个不同像素）
        //   count = (header & 0x7F) + 1
        while ($pixelIdx < $totalPixels) {
            if ($offset >= $len) {
                throw new Exception("gd_decode_tga: unexpected end of RLE stream at pixel $pixelIdx");
            }
            $header = ord(substr($data, $offset, 1));
            $offset = $offset + 1;
            $count  = ($header & 0x7F) + 1;

            if ($header & 0x80) {
                // RLE packet：后跟 1 个像素，重复 count 次
                if ($offset + $bytesPerPixel > $len) {
                    throw new Exception("gd_decode_tga: unexpected end of RLE pixel data");
                }
                $b = ord(substr($data, $offset, 1));
                $g = ord(substr($data, $offset + 1, 1));
                $r = ord(substr($data, $offset + 2, 1));
                $a = 0;
                if ($bpp == 32) {
                    $ta = ord(substr($data, $offset + 3, 1));
                    $a  = 127 - intval($ta / 2);
                }
                $color  = gd_make_color($r, $g, $b, $a);
                $offset = $offset + $bytesPerPixel;

                $k = 0;
                while ($k < $count && $pixelIdx < $totalPixels) {
                    $x    = $pixelIdx % $width;
                    $yRow = intval($pixelIdx / $width);
                    if ($topDown) {
                        $y = $yRow;
                    } else {
                        $y = $height - 1 - $yRow;
                    }
                    gd_set_pixel_raw($im, $x, $y, $color);
                    $pixelIdx = $pixelIdx + 1;
                    $k        = $k + 1;
                }
            } else {
                // Raw packet：后跟 count 个不同像素
                $k = 0;
                while ($k < $count && $pixelIdx < $totalPixels) {
                    if ($offset + $bytesPerPixel > $len) {
                        throw new Exception("gd_decode_tga: unexpected end of raw pixel data");
                    }
                    $b = ord(substr($data, $offset, 1));
                    $g = ord(substr($data, $offset + 1, 1));
                    $r = ord(substr($data, $offset + 2, 1));
                    $a = 0;
                    if ($bpp == 32) {
                        $ta = ord(substr($data, $offset + 3, 1));
                        $a  = 127 - intval($ta / 2);
                    }
                    $color  = gd_make_color($r, $g, $b, $a);
                    $offset = $offset + $bytesPerPixel;

                    $x    = $pixelIdx % $width;
                    $yRow = intval($pixelIdx / $width);
                    if ($topDown) {
                        $y = $yRow;
                    } else {
                        $y = $height - 1 - $yRow;
                    }
                    gd_set_pixel_raw($im, $x, $y, $color);

                    $pixelIdx = $pixelIdx + 1;
                    $k        = $k + 1;
                }
            }
        }
    }

    return $im;
}

/**
 * imagecreatefromstring(string $data): GdImage
 *
 * 从二进制字符串创建图像，自动检测格式并分发到对应解码器。
 *
 * 支持的格式（后续 Task 实现，当前调用占位解码器）：
 *   PNG (\x89PNG)、GIF (GIF8)、BMP (BM)、GD2 (gd2)、GD、WBMP、TGA
 *
 * 不支持的格式（立即抛 RuntimeException）：
 *   JPEG (\xFF\xD8\xFF)、WebP (RIFF...WEBP)、AVIF (ftyp+avif)、XPM (XPM magic bytes)
 *
 * 未知格式返回 false。
 */
function imagecreatefromstring(string $data): GdImage|Exception
{
    $len = strlen($data);
    if ($len < 3) { throw new Exception("imagecreatefromstring: data too short ($len bytes)"); }

    // 读取前 12 字节用于格式检测
    $b0 = ord(substr($data, 0, 1));
    $b1 = ord(substr($data, 1, 1));
    $b2 = ord(substr($data, 2, 1));
    $b3 = 0;
    $b4 = 0;
    $b5 = 0;
    $b6 = 0;
    $b7 = 0;
    $b8 = 0;
    $b9 = 0;
    $b10 = 0;
    $b11 = 0;
    if ($len > 3) { $b3 = ord(substr($data, 3, 1)); }
    if ($len > 4) { $b4 = ord(substr($data, 4, 1)); }
    if ($len > 5) { $b5 = ord(substr($data, 5, 1)); }
    if ($len > 6) { $b6 = ord(substr($data, 6, 1)); }
    if ($len > 7) { $b7 = ord(substr($data, 7, 1)); }
    if ($len > 8) { $b8 = ord(substr($data, 8, 1)); }
    if ($len > 9) { $b9 = ord(substr($data, 9, 1)); }
    if ($len > 10) { $b10 = ord(substr($data, 10, 1)); }
    if ($len > 11) { $b11 = ord(substr($data, 11, 1)); }

    // ── PNG: \x89 P N G ──
    if ($b0 == 0x89 && $b1 == 0x50 && $b2 == 0x4E && $b3 == 0x47) {
        return gd_decode_png($data);
    }

    // ── GIF: G I F 8 ──
    if ($b0 == 0x47 && $b1 == 0x49 && $b2 == 0x46 && $b3 == 0x38) {
        return gd_decode_gif($data);
    }

    // ── BMP: B M ──
    if ($b0 == 0x42 && $b1 == 0x4D) {
        return gd_decode_bmp($data);
    }

    // ── GD2: g d 2 ──
    if ($b0 == 0x67 && $b1 == 0x64 && $b2 == 0x32) {
        return gd_decode_gd2($data);
    }

    // ── JPEG: \xFF \xD8 \xFF → 不支持 ──
    if ($b0 == 0xFF && $b1 == 0xD8 && $b2 == 0xFF) {
        throw new RuntimeException("JPEG format is not supported in pure phpc GD implementation");
    }

    // ── WebP: R I F F ... W E B P → 不支持 ──
    if ($b0 == 0x52 && $b1 == 0x49 && $b2 == 0x46 && $b3 == 0x46
        && $b8 == 0x57 && $b9 == 0x45 && $b10 == 0x42 && $b11 == 0x50) {
        throw new RuntimeException("WebP format is not supported in pure phpc GD implementation");
    }

    // ── AVIF: ... f t y p a v i f / a v i s → 不支持 ──
    if ($b4 == 0x66 && $b5 == 0x74 && $b6 == 0x79 && $b7 == 0x70
        && (($b8 == 0x61 && $b9 == 0x76 && $b10 == 0x69 && $b11 == 0x66)
            || ($b8 == 0x61 && $b9 == 0x76 && $b10 == 0x69 && $b11 == 0x73))) {
        throw new RuntimeException("AVIF format is not supported in pure phpc GD implementation");
    }

    // ── XPM: / * space X P M space * / → 不支持 ──
    if ($b0 == 0x2F && $b1 == 0x2A && $b2 == 0x20 && $b3 == 0x58
        && $b4 == 0x50 && $b5 == 0x4D && $b6 == 0x20 && $b7 == 0x2A && $b8 == 0x2F) {
        throw new RuntimeException("XPM format is not supported in pure phpc GD implementation");
    }

    // ── GD: 首字节为 0（palette）或 1（trueColor），数据长度 >= 7 ──
    if (($b0 == 0 || $b0 == 1) && $len >= 7) {
        return gd_decode_gd($data);
    }

    // ── WBMP: 首字节为 0（type 0），第二字节为 0（fix header），长度 >= 4 ──
    if ($b0 == 0 && $b1 == 0 && $len >= 4) {
        return gd_decode_wbmp($data);
    }

    // ── TGA: 数据长度 >= 18，byte 2 为图像类型 (0/1/2/3/9/10/11) ──
    if ($len >= 18 && ($b2 == 0 || $b2 == 1 || $b2 == 2 || $b2 == 3
                       || $b2 == 9 || $b2 == 10 || $b2 == 11)) {
        return gd_decode_tga($data);
    }

    // ── 未知格式 ──
    throw new Exception("imagecreatefromstring: unknown image format");
}

// ════════════════════════════════════════════════════════════
// Task 3: 颜色管理函数集
// ════════════════════════════════════════════════════════════

/**
 * imagecolorallocate(GdImage $image, int $red, int $green, int $blue): int
 *
 * 分配颜色。
 * - 真彩色图像：直接返回 0x00RRGGBB 颜色值（无需分配）
 * - 调色板图像：追加颜色到 palette，返回索引；调色板满（256 项）返回 false
 *   会优先复用已释放（标记为 -1）的调色板槽位
 */
function imagecolorallocate(GdImage $image, int $red, int $green, int $blue): int|Exception
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, 0);
    }
    $total = count($image->palette);
    // 复用已释放的槽位
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == -1) {
            $image->palette[$i] = gd_make_color($red, $green, $blue, 0);
            return $i;
        }
    }
    // 追加新颜色
    if ($total >= 256) { throw new Exception("imagecolorallocate: palette full (256 colors)"); }
    $image->palette[$total] = gd_make_color($red, $green, $blue, 0);
    return $total;
}

/**
 * imagecolorallocatealpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
 *
 * 分配带 alpha 通道的颜色。
 * - 真彩色图像：返回 0xAARRGGBB 颜色值（alpha 在高位）
 * - 调色板图像：追加到 palette 并返回索引；调色板满返回 false
 * - $alpha: 0=不透明, 127=完全透明
 */
function imagecolorallocatealpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int|Exception
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, $alpha);
    }
    $total = count($image->palette);
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == -1) {
            $image->palette[$i] = gd_make_color($red, $green, $blue, $alpha);
            return $i;
        }
    }
    if ($total >= 256) { throw new Exception("imagecolorallocatealpha: palette full (256 colors)"); }
    $image->palette[$total] = gd_make_color($red, $green, $blue, $alpha);
    return $total;
}

/**
 * imagecolorat(GdImage $image, int $x, int $y): int
 *
 * 获取像素颜色值。
 * - 真彩色图像：返回 0x7FRRGGBB 颜色值
 * - 调色板图像：返回调色板索引
 * - 坐标越界返回 false
 */
function imagecolorat(GdImage $image, int $x, int $y): int|Exception
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) {
        throw new Exception("imagecolorat: coordinates out of bounds ($x, $y)");
    }
    return $image->pixels[$y * $image->width + $x];
}

/**
 * imagecolorsforindex(GdImage $image, int $color): array
 *
 * 获取颜色的 RGBA 分量。
 * - 真彩色图像：$color 为 0x7FRRGGBB 颜色值
 * - 调色板图像：$color 为调色板索引
 * - 返回 ['red'=>int, 'green'=>int, 'blue'=>int, 'alpha'=>int]
 * - alpha: 0=不透明, 127=完全透明
 * - 索引无效返回 false
 */
function imagecolorsforindex(GdImage $image, int $color): array|Exception
{
    if ($image->trueColor) {
        return [
            'red' => gd_get_red($color),
            'green' => gd_get_green($color),
            'blue' => gd_get_blue($color),
            'alpha' => gd_get_alpha($color),
        ];
    }
    // 调色板图像
    $total = count($image->palette);
    if ($color < 0 || $color >= $total) { throw new Exception("imagecolorsforindex: invalid color index $color"); }
    if ($image->palette[$color] == -1) { throw new Exception("imagecolorsforindex: color index $color already deallocated"); }
    $c = $image->palette[$color];
    return [
        'red' => gd_get_red($c),
        'green' => gd_get_green($c),
        'blue' => gd_get_blue($c),
        'alpha' => gd_get_alpha($c),
    ];
}

/**
 * imagecolorclosest(GdImage $image, int $red, int $green, int $blue): int
 *
 * 查找最接近的颜色（欧氏距离）。
 * - 真彩色图像：直接返回颜色值（任何 RGB 都可精确表示）
 * - 调色板图像：返回最接近的调色板索引；空调色板返回 -1
 */
function imagecolorclosest(GdImage $image, int $red, int $green, int $blue): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, 0);
    }
    $total = count($image->palette);
    if ($total == 0) { return -1; }
    $best = 0;
    $mindist = -1;
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == -1) { continue; }
        $c = $image->palette[$i];
        $dr = gd_get_red($c) - $red;
        $dg = gd_get_green($c) - $green;
        $db = gd_get_blue($c) - $blue;
        $dist = $dr * $dr + $dg * $dg + $db * $db;
        if ($mindist < 0 || $dist < $mindist) {
            $mindist = $dist;
            $best = $i;
        }
    }
    return $best;
}

/**
 * imagecolorclosestalpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
 *
 * 查找最接近的颜色（含 alpha，欧氏距离）。
 * - 真彩色图像：直接返回颜色值
 * - 调色板图像：返回最接近的调色板索引；空调色板返回 -1
 */
function imagecolorclosestalpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, $alpha);
    }
    $total = count($image->palette);
    if ($total == 0) { return -1; }
    $best = 0;
    $mindist = -1;
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == -1) { continue; }
        $c = $image->palette[$i];
        $dr = gd_get_red($c) - $red;
        $dg = gd_get_green($c) - $green;
        $db = gd_get_blue($c) - $blue;
        $da = gd_get_alpha($c) - $alpha;
        $dist = $dr * $dr + $dg * $dg + $db * $db + $da * $da;
        if ($mindist < 0 || $dist < $mindist) {
            $mindist = $dist;
            $best = $i;
        }
    }
    return $best;
}

/**
 * imagecolorclosesthwb(GdImage $image, int $red, int $green, int $blue): int
 *
 * 查找最接近的颜色（HWB 距离：Hue/Whiteness/Blackness）。
 * - 真彩色图像：直接返回颜色值
 * - 调色板图像：返回 HWB 距离最小的调色板索引；空调色板返回 -1
 */
function imagecolorclosesthwb(GdImage $image, int $red, int $green, int $blue): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, 0);
    }
    $total = count($image->palette);
    if ($total == 0) { return -1; }
    $target = gd_rgb_to_hwb($red, $green, $blue);
    $th = $target[0];
    $tw = $target[1];
    $tb = $target[2];
    $best = 0;
    $mindist = -1.0;
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == -1) { continue; }
        $c = $image->palette[$i];
        $hwb = gd_rgb_to_hwb(gd_get_red($c), gd_get_green($c), gd_get_blue($c));
        $h_diff = $hwb[0] - $th;
        if ($h_diff < 0.0) { $h_diff = -$h_diff; }
        if ($h_diff > 180.0) { $h_diff = 360.0 - $h_diff; }
        $w_diff = $hwb[1] - $tw;
        if ($w_diff < 0) { $w_diff = -$w_diff; }
        $b_diff = $hwb[2] - $tb;
        if ($b_diff < 0) { $b_diff = -$b_diff; }
        $dist = $h_diff + $w_diff + $b_diff;
        if ($mindist < 0.0 || $dist < $mindist) {
            $mindist = $dist;
            $best = $i;
        }
    }
    return $best;
}

/**
 * imagecolorexact(GdImage $image, int $red, int $green, int $blue): int
 *
 * 精确查找颜色。
 * - 真彩色图像：直接返回颜色值
 * - 调色板图像：返回匹配的调色板索引；未找到返回 -1
 */
function imagecolorexact(GdImage $image, int $red, int $green, int $blue): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, 0);
    }
    $target = gd_make_color($red, $green, $blue, 0);
    $total = count($image->palette);
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == $target) {
            return $i;
        }
    }
    return -1;
}

/**
 * imagecolorexactalpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
 *
 * 精确查找颜色（含 alpha）。
 * - 真彩色图像：直接返回颜色值
 * - 调色板图像：返回匹配的调色板索引；未找到返回 -1
 */
function imagecolorexactalpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, $alpha);
    }
    $target = gd_make_color($red, $green, $blue, $alpha);
    $total = count($image->palette);
    for ($i = 0; $i < $total; $i++) {
        if ($image->palette[$i] == $target) {
            return $i;
        }
    }
    return -1;
}

/**
 * imagecolorresolve(GdImage $image, int $red, int $green, int $blue): int
 *
 * 查找或分配颜色。
 * - 真彩色图像：直接返回颜色值
 * - 调色板图像：先精确查找，找到则返回索引；未找到则分配新颜色；
 *   调色板满则返回最接近的颜色索引
 */
function imagecolorresolve(GdImage $image, int $red, int $green, int $blue): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, 0);
    }
    // 精确查找
    $exact = imagecolorexact($image, $red, $green, $blue);
    if ($exact >= 0) { return $exact; }
    // 分配新颜色（调色板满时回退到 closest）
    try {
        return imagecolorallocate($image, $red, $green, $blue);
    } catch (Exception $e) {
        return imagecolorclosest($image, $red, $green, $blue);
    }
}

/**
 * imagecolorresolvealpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
 *
 * 查找或分配颜色（含 alpha）。
 * - 真彩色图像：直接返回颜色值
 * - 调色板图像：先精确查找，找到则返回索引；未找到则分配新颜色；
 *   调色板满则返回最接近的颜色索引
 */
function imagecolorresolvealpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
{
    if ($image->trueColor) {
        return gd_make_color($red, $green, $blue, $alpha);
    }
    $exact = imagecolorexactalpha($image, $red, $green, $blue, $alpha);
    if ($exact >= 0) { return $exact; }
    try {
        return imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
    } catch (Exception $e) {
        return imagecolorclosestalpha($image, $red, $green, $blue, $alpha);
    }
}

/**
 * imagecolordeallocate(GdImage $image, int $color): bool
 *
 * 释放调色板颜色。
 * - 仅调色板图像有效；真彩色图像返回 false
 * - 将 palette[color] 标记为 -1（已释放），后续 imagecolorallocate 可复用该槽位
 * - 索引无效或已释放返回 false
 */
function imagecolordeallocate(GdImage $image, int $color): bool
{
    if ($image->trueColor) { return false; }
    $total = count($image->palette);
    if ($color < 0 || $color >= $total) { return false; }
    if ($image->palette[$color] == -1) { return false; }
    $image->palette[$color] = -1;
    return true;
}

/**
 * imagecolorset(GdImage $image, int $index, int $red, int $green, int $blue, int $alpha = 0): bool
 *
 * 修改调色板项的颜色。
 * - 仅调色板图像有效；真彩色图像返回 false
 * - 索引无效返回 false
 */
function imagecolorset(GdImage $image, int $index, int $red, int $green, int $blue, int $alpha = 0): bool
{
    if ($image->trueColor) { return false; }
    $total = count($image->palette);
    if ($index < 0 || $index >= $total) { return false; }
    $image->palette[$index] = gd_make_color($red, $green, $blue, $alpha);
    return true;
}

/**
 * imagecolorstotal(GdImage $image): int
 *
 * 返回调色板颜色数。
 * - 调色板图像：返回 palette 数组长度（含已释放的槽位）
 * - 真彩色图像：返回 0
 */
function imagecolorstotal(GdImage $image): int
{
    if ($image->trueColor) { return 0; }
    return count($image->palette);
}

/**
 * imagecolortransparent(GdImage $image, int $color = -1): int
 *
 * 获取或设置透明色。
 * - $color = -1（默认）：返回当前透明色（获取模式）
 * - $color >= 0：设置透明色并返回之前的值（设置模式）
 * - 真彩色图像：$color 为 0x7FRRGGBB 颜色值
 * - 调色板图像：$color 为调色板索引
 */
function imagecolortransparent(GdImage $image, int $color = -1): int
{
    if ($color == -1) {
        return $image->transparentColor;
    }
    $old = $image->transparentColor;
    $image->transparentColor = $color;
    return $old;
}

/**
 * imagepalettecopy(GdImage $dst, GdImage $src): void
 *
 * 复制源图像的调色板到目标图像。
 * - 同时复制透明色设置
 * - 真彩色图像的 palette 为空，复制后仍为空
 */
function imagepalettecopy(GdImage $dst, GdImage $src): void
{
    $dst->palette = [];
    $total = count($src->palette);
    for ($i = 0; $i < $total; $i++) {
        $dst->palette[$i] = $src->palette[$i];
    }
    $dst->transparentColor = $src->transparentColor;
}

/**
 * imagecolormatch(GdImage $image1, GdImage $image2): bool
 *
 * 匹配色板图像到真彩色图像。
 * - $image1 为真彩色图像，$image2 为调色板图像
 * - 对 image2 的每个调色板项，计算 image1 中对应像素的平均颜色，
 *   更新 image2 的调色板项使其更接近真彩色版本
 * - 两图像尺寸必须相同，否则返回 false
 * - 类型不匹配返回 false
 */
function imagecolormatch(GdImage $image1, GdImage $image2): bool
{
    if (!$image1->trueColor || $image2->trueColor) { return false; }
    if ($image1->width != $image2->width || $image1->height != $image2->height) {
        return false;
    }
    $numColors = count($image2->palette);
    if ($numColors == 0) { return true; }
    $total = $image2->width * $image2->height;

    // 累加每个调色板索引对应的真彩色像素 RGB 总和
    $sumR = array_fill(0, $numColors, 0);
    $sumG = array_fill(0, $numColors, 0);
    $sumB = array_fill(0, $numColors, 0);
    $cnt = array_fill(0, $numColors, 0);

    for ($i = 0; $i < $total; $i++) {
        int $idx = intval($image2->pixels[$i]);
        if ($idx >= 0 && $idx < $numColors && $image2->palette[$idx] != -1) {
            $c = $image1->pixels[$i];
            $sumR[$idx] = $sumR[$idx] + gd_get_red($c);
            $sumG[$idx] = $sumG[$idx] + gd_get_green($c);
            $sumB[$idx] = $sumB[$idx] + gd_get_blue($c);
            $cnt[$idx] = $cnt[$idx] + 1;
        }
    }

    // 用平均值更新调色板项
    for ($i = 0; $i < $numColors; $i++) {
        if ($cnt[$i] > 0 && $image2->palette[$i] != -1) {
            $ci = $cnt[$i];
            $avgR = $sumR[$i] / $ci;
            $avgG = $sumG[$i] / $ci;
            $avgB = $sumB[$i] / $ci;
            $image2->palette[$i] = gd_make_color($avgR, $avgG, $avgB, 0);
        }
    }
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 4: 像素与基本图形绘制
//
// 内部辅助函数（tphp 风格，无返回类型声明）：
//   gd_set_pixel_raw  — 无 alpha/clip 的像素设置（越界返回）
//   gd_get_pixel_raw  — 像素读取（越界返回 0）
//   gd_apply_alpha_blend — 0x7FRRGGBB 颜色 alpha 混合
//   gd_set_pixel_blend   — 带边界+clip+alpha 的像素设置（公开 API 用）
//   gd_brush_pixel       — 获取 brush 中心像素（简化实现）
//   gd_resolve_draw_color — 解析 style/brush/tile 特殊颜色
//   gd_bresenham         — 单像素宽 Bresenham 直线（含 style/brush）
// ════════════════════════════════════════════════════════════

// PI 常量（TinyPHP 已内置 pi()，但常量更直接）
const GD_PI = 3.14159265358979323846;

// gd_set_pixel_raw — 内部像素设置（不处理 alpha/clip，越界直接返回）
function gd_set_pixel_raw(GdImage $image, int $x, int $y, int $color): void
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return; }
    $image->pixels[$y * $image->width + $x] = $color;
}

// gd_get_pixel_raw — 内部像素读取（越界返回 0）
function gd_get_pixel_raw(GdImage $image, int $x, int $y): int
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return 0; }
    return $image->pixels[$y * $image->width + $x];
}

// gd_apply_alpha_blend — alpha 混合两个 0x7FRRGGBB 颜色值
//   公式：out = src * (127-srcA)/127 + dst * srcA/127
//   srcA=0(不透明) → out=src；srcA=127(透明) → out=dst
function gd_apply_alpha_blend(int $dst, int $src): int
{
    $sa = gd_get_alpha($src);
    if ($sa == 0) { return $src; }
    if ($sa == 127) { return $dst; }
    $sr = gd_get_red($src);
    $sg = gd_get_green($src);
    $sb = gd_get_blue($src);
    $dr = gd_get_red($dst);
    $dg = gd_get_green($dst);
    $db = gd_get_blue($dst);
    $w = 127 - $sa;
    $out_r = ($sr * $w + $dr * $sa + 63) / 127;
    $out_g = ($sg * $w + $dg * $sa + 63) / 127;
    $out_b = ($sb * $w + $db * $sa + 63) / 127;
    $da = gd_get_alpha($dst);
    return gd_make_color($out_r, $out_g, $out_b, $da);
}

// gd_set_pixel_blend — 带边界+clip+alpha 的像素设置（公开 API 用）
//   $color 为真彩色 0x7FRRGGBB 或调色板索引
function gd_set_pixel_blend(GdImage $image, int $x, int $y, int $color): void
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return; }
    if (count($image->clip) >= 4) {
        if ($x < $image->clip[0] || $x > $image->clip[2]
            || $y < $image->clip[1] || $y > $image->clip[3]) { return; }
    }
    $idx = $y * $image->width + $x;
    if ($image->trueColor) {
        if ($image->alphaBlending) {
            $image->pixels[$idx] = gd_apply_alpha_blend($image->pixels[$idx], $color);
        } else {
            $image->pixels[$idx] = $color;
        }
    } else {
        // 调色板图像：直接存储索引
        $image->pixels[$idx] = $color;
    }
}

// gd_brush_pixel — 获取 brush 图像中心像素颜色
//   brush 为 mixed（GdImage 或 null），由于 imagesetbrush 尚未实现（Task 11），
//   当前 brush 始终为 null，返回 0（黑色）作为回退。
//   完整实现将在 Task 11 完成 imagesetbrush 后补全。
function gd_brush_pixel(GdImage $image): int
{
    // Task 11 未实现 imagesetbrush，brush 始终为 null，返回 0
    return 0;
}

// gd_resolve_draw_color — 解析画笔颜色（处理 style/brush/tile 特殊颜色）
//   $styleIdx 为当前像素在 style 序列中的位置
//   返回 IMG_COLOR_TRANSPARENT 表示跳过该点
function gd_resolve_draw_color(GdImage $image, int $color, int $styleIdx): int
{
    $styleCount = count($image->style);
    $useStyle = ($styleCount > 0) && ($color == IMG_COLOR_STYLED || $color == IMG_COLOR_STYLEDBRUSHED);
    if ($useStyle) {
        $sc = $image->style[$styleIdx % $styleCount];
        if ($sc == IMG_COLOR_TRANSPARENT) { return IMG_COLOR_TRANSPARENT; }
        if ($color == IMG_COLOR_STYLEDBRUSHED) {
            return gd_brush_pixel($image);
        }
        return $sc;
    }
    if ($color == IMG_COLOR_BRUSHED || $color == IMG_COLOR_STYLEDBRUSHED) {
        return gd_brush_pixel($image);
    }
    if ($color == IMG_COLOR_TILED) {
        // 简化处理：用 brush 替代 tile
        return gd_brush_pixel($image);
    }
    return $color;
}

// gd_bresenham — 单像素宽 Bresenham 直线（含 style/brush 解析，无 thickness）
//   供 imageline 内部调用（thickness 由 imageline 在外层处理）
function gd_bresenham(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): void
{
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $adx = $dx < 0 ? -$dx : $dx;
    $ady = $dy < 0 ? -$dy : $dy;
    $sx = $dx < 0 ? -1 : 1;
    $sy = $dy < 0 ? -1 : 1;
    $styleCount = count($image->style);
    $useStyle = ($styleCount > 0) && ($color == IMG_COLOR_STYLED || $color == IMG_COLOR_STYLEDBRUSHED);
    $step = 0;

    if ($adx > $ady) {
        $err = $adx / 2;
        $y = $y1;
        $x = $x1;
        while (true) {
            $ec = $color;
            if ($useStyle) {
                $sc = $image->style[$step % $styleCount];
                if ($sc == IMG_COLOR_TRANSPARENT) { $sc = IMG_COLOR_TRANSPARENT; }
                $ec = gd_resolve_draw_color($image, $color, $step);
            }
            if ($ec != IMG_COLOR_TRANSPARENT) {
                gd_set_pixel_blend($image, $x, $y, $ec);
            }
            if ($x == $x2) { break; }
            $err = $err - $ady;
            if ($err < 0) { $y = $y + $sy; $err = $err + $adx; }
            $x = $x + $sx;
            $step = $step + 1;
        }
    } else {
        $err = $ady / 2;
        $x = $x1;
        $y = $y1;
        while (true) {
            $ec = $color;
            if ($useStyle) {
                $ec = gd_resolve_draw_color($image, $color, $step);
            }
            if ($ec != IMG_COLOR_TRANSPARENT) {
                gd_set_pixel_blend($image, $x, $y, $ec);
            }
            if ($y == $y2) { break; }
            $err = $err - $adx;
            if ($err < 0) { $x = $x + $sx; $err = $err + $ady; }
            $y = $y + $sy;
            $step = $step + 1;
        }
    }
}

// ── Task 4 公开函数 ──────────────────────────────────────────

// imagesetpixel — 设置单个像素（带边界检查 + alpha 混合）
function imagesetpixel(GdImage $image, int $x, int $y, int $color): bool
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return false; }
    gd_set_pixel_blend($image, $x, $y, $color);
    return true;
}

// imageline — Bresenham 直线算法（支持 thickness/style/brush）
function imageline(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
{
    $th = $image->thickness;
    if ($th < 1) { $th = 1; }

    if ($th == 1) {
        // 单像素宽：直接 Bresenham
        gd_bresenham($image, $x1, $y1, $x2, $y2, $color);
        return true;
    }

    // 粗线：沿法线方向偏移绘制多条平行线
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    float $len = sqrt($dx * $dx + $dy * $dy);
    if ($len < 0.5) {
        // 退化：单点 → 画 thickness×thickness 的方块
        $lo = ($th - 1) / 2;
        $hi = $th / 2;
        $oy = -$lo;
        while ($oy <= $hi) {
            $ox = -$lo;
            while ($ox <= $hi) {
                gd_set_pixel_blend($image, $x1 + $ox, $y1 + $oy, $color);
                $ox = $ox + 1;
            }
            $oy = $oy + 1;
        }
        return true;
    }

    // 法线方向单位向量（垂直于线段方向）
    float $nx = (0.0 - $dy) / $len;
    float $ny = (float)$dx / $len;
    float $half = (float)($th - 1) / 2.0;
    // 偏移范围：从 -half 到 +half
    $start = 0 - intval($half + 0.5);
    $end = intval($half + 0.5);
    float $t = (float)$start;
    while ($t <= $end) {
        $ox = intval($t * $nx + ($t >= 0 ? 0.5 : -0.5));
        $oy = intval($t * $ny + ($t >= 0 ? 0.5 : -0.5));
        gd_bresenham($image, $x1 + $ox, $y1 + $oy, $x2 + $ox, $y2 + $oy, $color);
        $t = $t + 1;
    }
    return true;
}

// imagedashedline — 虚线（dash on/off 各 2 像素）
function imagedashedline(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
{
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $adx = $dx < 0 ? -$dx : $dx;
    $ady = $dy < 0 ? -$dy : $dy;
    $sx = $dx < 0 ? -1 : 1;
    $sy = $dy < 0 ? -1 : 1;
    $dashPos = 0;  // 0,1=on, 2,3=off

    if ($adx > $ady) {
        $err = $adx / 2;
        $y = $y1;
        $x = $x1;
        while (true) {
            if ($dashPos < 2) {
                gd_set_pixel_blend($image, $x, $y, $color);
            }
            if ($x == $x2) { break; }
            $err = $err - $ady;
            if ($err < 0) { $y = $y + $sy; $err = $err + $adx; }
            $x = $x + $sx;
            $dashPos = ($dashPos + 1) % 4;
        }
    } else {
        $err = $ady / 2;
        $x = $x1;
        $y = $y1;
        while (true) {
            if ($dashPos < 2) {
                gd_set_pixel_blend($image, $x, $y, $color);
            }
            if ($y == $y2) { break; }
            $err = $err - $adx;
            if ($err < 0) { $x = $x + $sx; $err = $err + $ady; }
            $y = $y + $sy;
            $dashPos = ($dashPos + 1) % 4;
        }
    }
    return true;
}

// imagerectangle — 矩形边框（4 条线，受 thickness 影响）
function imagerectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
{
    if ($x1 > $x2) { $tx = $x1; $x1 = $x2; $x2 = $tx; }
    if ($y1 > $y2) { $ty = $y1; $y1 = $y2; $y2 = $ty; }
    imageline($image, $x1, $y1, $x2, $y1, $color);
    imageline($image, $x2, $y1, $x2, $y2, $color);
    imageline($image, $x2, $y2, $x1, $y2, $color);
    imageline($image, $x1, $y2, $x1, $y1, $color);
    return true;
}

// imagefilledrectangle — 填充矩形
function imagefilledrectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
{
    if ($x1 > $x2) { $tx = $x1; $x1 = $x2; $x2 = $tx; }
    if ($y1 > $y2) { $ty = $y1; $y1 = $y2; $y2 = $ty; }
    // 裁剪到图像范围
    if ($x1 < 0) { $x1 = 0; }
    if ($y1 < 0) { $y1 = 0; }
    if ($x2 >= $image->width) { $x2 = $image->width - 1; }
    if ($y2 >= $image->height) { $y2 = $image->height - 1; }
    $y = $y1;
    while ($y <= $y2) {
        $x = $x1;
        while ($x <= $x2) {
            gd_set_pixel_blend($image, $x, $y, $color);
            $x = $x + 1;
        }
        $y = $y + 1;
    }
    return true;
}

// imagesetthickness — 设置线宽（>=1）
function imagesetthickness(GdImage $image, int $thickness): bool
{
    if ($thickness < 1) { $thickness = 1; }
    $image->thickness = $thickness;
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 5: 弧形与椭圆绘制
// ════════════════════════════════════════════════════════════

// gd_arc_points — 生成椭圆弧上的点（扁平数组 [x0,y0,x1,y1,...]）
//   角度 0=右(3点), 90=下(6点), 180=左, 270=上（PHP gd 惯例，顺时针为正）
//   从 start 到 end 顺时针扫描
function gd_arc_points(int $cx, int $cy, int $w, int $h, int $start, int $end): array
{
    $pts = [];
    // 规范化角度
    $s = $start;
    $e = $end;
    while ($s < 0) { $s = $s + 360; }
    while ($s >= 360) { $s = $s - 360; }
    while ($e < 0) { $e = $e + 360; }
    while ($e >= 360) { $e = $e - 360; }
    // 如果 end <= start，end += 360（顺时针扫描跨越 0 度）
    if ($e <= $s) { $e = $e + 360; }

    float $rx = (float)$w / 2.0;
    float $ry = (float)$h / 2.0;
    // 步进：确保覆盖（约 1 点/像素）
    float $maxR = $rx > $ry ? $rx : $ry;
    float $step = 1.0;
    if ($maxR > 50.0) { $step = 360.0 / (2.0 * GD_PI * $maxR); }
    if ($step < 0.1) { $step = 0.1; }

    float $a = (float)$s;
    float $rad = 0.0;
    float $px = 0.0;
    float $py = 0.0;
    while ($a <= $e) {
        $rad = $a * GD_PI / 180.0;
        $px = (float)$cx + $rx * cos($rad);
        $py = (float)$cy + $ry * sin($rad);
        $pts[] = intval($px + 0.5);
        $pts[] = intval($py + 0.5);
        $a = $a + $step;
    }
    // 确保终点
    $rad = (float)$e * GD_PI / 180.0;
    $px = (float)$cx + $rx * cos($rad);
    $py = (float)$cy + $ry * sin($rad);
    $pts[] = intval($px + 0.5);
    $pts[] = intval($py + 0.5);
    return $pts;
}

// imagearc — 椭圆弧（角度以度为单位，0=3点钟，顺时针为正）
function imagearc(GdImage $image, int $center_x, int $center_y, int $width, int $height,
                  int $start_angle, int $end_angle, int $color): bool|Exception
{
    if ($width <= 0 || $height <= 0) {
        throw new Exception("imagearc: invalid dimensions (w=$width, h=$height)");
    }
    $pts = gd_arc_points($center_x, $center_y, $width, $height, $start_angle, $end_angle);
    $n = count($pts) / 2;
    // 逐点画弧（连线以确保连续）
    $i = 0;
    while ($i < $n) {
        gd_set_pixel_blend($image, $pts[$i * 2], $pts[$i * 2 + 1], $color);
        $i = $i + 1;
    }
    return true;
}

// imageellipse — 完整椭圆（调用 imagearc 从 0 到 360）
function imageellipse(GdImage $image, int $center_x, int $center_y, int $width, int $height,
                      int $color): bool|Exception
{
    if ($width <= 0 || $height <= 0) {
        throw new Exception("imageellipse: invalid dimensions (w=$width, h=$height)");
    }
    return imagearc($image, $center_x, $center_y, $width, $height, 0, 360, $color);
}

// imagefilledellipse — 填充椭圆（扫描线填充）
function imagefilledellipse(GdImage $image, int $center_x, int $center_y, int $width, int $height,
                            int $color): bool|Exception
{
    if ($width <= 0 || $height <= 0) {
        throw new Exception("imagefilledellipse: invalid dimensions (w=$width, h=$height)");
    }
    float $rx = (float)$width / 2.0;
    float $ry = (float)$height / 2.0;
    $cy = $center_y;
    // y 范围
    $y0 = $cy - intval($ry + 0.5);
    $y1 = $cy + intval($ry + 0.5);
    if ($y0 < 0) { $y0 = 0; }
    if ($y1 >= $image->height) { $y1 = $image->height - 1; }

    $y = $y0;
    while ($y <= $y1) {
        $dy = $y - $cy;
        // 椭圆方程：(x-cx)/rx)^2 + ((y-cy)/ry)^2 = 1
        float $t = 1.0 - (float)($dy * $dy) / ($ry * $ry);
        if ($t >= 0.0) {
            float $dx = $rx * sqrt($t);
            float $xL = (float)$center_x - $dx;
            float $xR = (float)$center_x + $dx;
            $x1 = intval($xL + 0.5);
            $x2 = intval($xR + 0.5);
            if ($x1 < 0) { $x1 = 0; }
            if ($x2 >= $image->width) { $x2 = $image->width - 1; }
            $x = $x1;
            while ($x <= $x2) {
                gd_set_pixel_blend($image, $x, $y, $color);
                $x = $x + 1;
            }
        }
        $y = $y + 1;
    }
    return true;
}

// gd_fill_arc_sector — 填充扇形（PIE）使用椭圆方程 + 叉积角度测试
//   直接基于椭圆方程逐像素填充，避免 gd_arc_points 整数圆整导致的扫描线间隙
//   角度约定：0=3点钟方向，顺时针为正（与 PHP GD 一致）
function gd_fill_arc_sector(GdImage $image, int $cx, int $cy, int $w, int $h,
                            int $start, int $end, int $color): void
{
    float $rx = (float)$w / 2.0;
    float $ry = (float)$h / 2.0;

    // 规范化角度
    float $s = (float)$start;
    float $e = (float)$end;
    while ($s < 0.0) { $s = $s + 360.0; }
    while ($s >= 360.0) { $s = $s - 360.0; }
    while ($e < 0.0) { $e = $e + 360.0; }
    while ($e >= 360.0) { $e = $e - 360.0; }
    // wrapped = 扇形跨越 0°（start >= end）
    $wrapped = ($e <= $s);
    if ($wrapped) { $e = $e + 360.0; }

    // 全圆：直接填充整个椭圆
    if ($e - $s >= 360.0) {
        $y0 = $cy - intval($ry + 0.5);
        $y1 = $cy + intval($ry + 0.5);
        if ($y0 < 0) { $y0 = 0; }
        if ($y1 >= $image->height) { $y1 = $image->height - 1; }
        $y = $y0;
        while ($y <= $y1) {
            float $dy = (float)($y - $cy);
            float $tt = 1.0 - ($dy * $dy) / ($ry * $ry);
            if ($tt >= 0.0) {
                float $dxr = $rx * sqrt($tt);
                $x1 = intval((float)$cx - $dxr);
                $x2 = intval((float)$cx + $dxr);
                if ($x1 < 0) { $x1 = 0; }
                if ($x2 >= $image->width) { $x2 = $image->width - 1; }
                $x = $x1;
                while ($x <= $x2) {
                    gd_set_pixel_blend($image, $x, $y, $color);
                    $x = $x + 1;
                }
            }
            $y = $y + 1;
        }
        return;
    }

    // 起止方向的单位向量
    float $sRad = $s * GD_PI / 180.0;
    float $eRad = $e * GD_PI / 180.0;
    float $sCos = cos($sRad);
    float $sSin = sin($sRad);
    float $eCos = cos($eRad);
    float $eSin = sin($eRad);

    // 遍历椭圆边界框
    $y0 = $cy - intval($ry + 0.5);
    $y1 = $cy + intval($ry + 0.5);
    if ($y0 < 0) { $y0 = 0; }
    if ($y1 >= $image->height) { $y1 = $image->height - 1; }

    $y = $y0;
    while ($y <= $y1) {
        float $dy = (float)($y - $cy);
        float $tt = 1.0 - ($dy * $dy) / ($ry * $ry);
        if ($tt >= 0.0) {
            float $dxr = $rx * sqrt($tt);
            $xL = intval((float)$cx - $dxr);
            $xR = intval((float)$cx + $dxr);
            $x = $xL;
            while ($x <= $xR) {
                float $px = (float)($x - $cx);
                // 叉积测试（屏幕坐标系：y 向下，顺时针为正）
                // crossS >= 0: 点在起边顺时针侧（含线上）
                // crossE <= 0: 点在终边逆时针侧（含线上）
                float $crossS = $sCos * $dy - $sSin * $px;
                float $crossE = $eCos * $dy - $eSin * $px;
                if ($wrapped) {
                    if ($crossS >= 0.0 || $crossE <= 0.0) {
                        gd_set_pixel_blend($image, $x, $y, $color);
                    }
                } else {
                    if ($crossS >= 0.0 && $crossE <= 0.0) {
                        gd_set_pixel_blend($image, $x, $y, $color);
                    }
                }
                $x = $x + 1;
            }
        }
        $y = $y + 1;
    }
}

// imagefilledarc — 填充弧（支持 PIE/CHORD/NOFILL/EDGED）
function imagefilledarc(GdImage $image, int $center_x, int $center_y, int $width, int $height,
                        int $start_angle, int $end_angle, int $color, int $style = IMG_ARC_PIE): bool|Exception
{
    if ($width <= 0 || $height <= 0) {
        throw new Exception("imagefilledarc: invalid dimensions (w=$width, h=$height)");
    }
    $pts = gd_arc_points($center_x, $center_y, $width, $height, $start_angle, $end_angle);
    $n = count($pts) / 2;
    if ($n < 2) { return true; }

    $noFill = ($style & IMG_ARC_NOFILL) != 0;
    $chord = ($style & IMG_ARC_CHORD) != 0;
    $edged = ($style & IMG_ARC_EDGED) != 0;

    // 填充
    if (!$noFill) {
        if ($chord) {
            // 弓形：弧点构成的闭合多边形
            gd_fill_polygon_scanline($image, $pts, $color);
        } else {
            // 扇形（PIE）：使用椭圆方程 + 叉积角度测试，避免整数圆整间隙
            gd_fill_arc_sector($image, $center_x, $center_y, $width, $height,
                               $start_angle, $end_angle, $color);
        }
    }

    // 描边弧线
    $i = 0;
    while ($i < $n) {
        gd_set_pixel_blend($image, $pts[$i * 2], $pts[$i * 2 + 1], $color);
        $i = $i + 1;
    }

    // 描边半径线或弦
    $sx = $pts[0];
    $sy = $pts[1];
    $ex = $pts[($n - 1) * 2];
    $ey = $pts[($n - 1) * 2 + 1];
    if ($chord) {
        // 画弦（起点到终点）
        imageline($image, $sx, $sy, $ex, $ey, $color);
    } else if ($edged || !$noFill) {
        // PIE：画两条半径线
        imageline($image, $center_x, $center_y, $sx, $sy, $color);
        imageline($image, $center_x, $center_y, $ex, $ey, $color);
    }
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 6: 填充与多边形
// ════════════════════════════════════════════════════════════

// imagefill — 泛洪填充（Flood Fill）：从 (x,y) 替换连通同色区域
function imagefill(GdImage $image, int $x, int $y, int $color): bool
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return false; }
    $target = $image->pixels[$y * $image->width + $x];
    // 调色板：目标为索引；真彩色：目标为颜色值
    if (!$image->trueColor) {
        // color 是调色板索引，直接比较
    } else {
        // color 是真彩色值，比较 0x7FRRGGBB
    }
    if ($target == $color) { return true; }

    $stackX = [];
    $stackY = [];
    $stackX[] = $x;
    $stackY[] = $y;
    $w = $image->width;
    $h = $image->height;

    while (count($stackX) > 0) {
        $cx = array_pop($stackX);
        $cy = array_pop($stackY);
        if ($cx < 0 || $cx >= $w || $cy < 0 || $cy >= $h) { continue; }
        $idx = $cy * $w + $cx;
        if ($image->pixels[$idx] != $target) { continue; }
        if ($image->pixels[$idx] == $color) { continue; }
        $image->pixels[$idx] = $color;
        $stackX[] = $cx + 1;
        $stackY[] = $cy;
        $stackX[] = $cx - 1;
        $stackY[] = $cy;
        $stackX[] = $cx;
        $stackY[] = $cy + 1;
        $stackX[] = $cx;
        $stackY[] = $cy - 1;
    }
    return true;
}

// imagefilltoborder — 边界填充：填充直到遇到 border 颜色
function imagefilltoborder(GdImage $image, int $x, int $y, int $border, int $color): bool
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return false; }
    $w = $image->width;
    $h = $image->height;

    $stackX = [];
    $stackY = [];
    $stackX[] = $x;
    $stackY[] = $y;

    while (count($stackX) > 0) {
        $cx = array_pop($stackX);
        $cy = array_pop($stackY);
        if ($cx < 0 || $cx >= $w || $cy < 0 || $cy >= $h) { continue; }
        $idx = $cy * $w + $cx;
        $pv = $image->pixels[$idx];
        if ($pv == $border) { continue; }
        if ($pv == $color) { continue; }
        $image->pixels[$idx] = $color;
        $stackX[] = $cx + 1;
        $stackY[] = $cy;
        $stackX[] = $cx - 1;
        $stackY[] = $cy;
        $stackX[] = $cx;
        $stackY[] = $cy + 1;
        $stackX[] = $cx;
        $stackY[] = $cy - 1;
    }
    return true;
}

// gd_flatten_points — 将多边形点数组扁平化
//   输入：[[x,y],...] 对形式 或 [x0,y0,x1,y1,...] 扁平形式
//   输出：始终为 [x0,y0,x1,y1,...] 扁平形式
function gd_flatten_points(array $points): array
{
    $n = count($points);
    if ($n == 0) { return []; }
    if (!is_array($points[0])) {
        return $points;
    }
    $flat = [];
    $i = 0;
    while ($i < $n) {
        $pt = $points[$i];
        $flat[] = $pt[0];
        $flat[] = $pt[1];
        $i = $i + 1;
    }
    return $flat;
}

// gd_polygon_color — 解析多边形颜色参数（兼容两种签名）
//   $points: 原始点数组（用于检测对形式）
//   $num_points_or_color: 旧签名为点数，新签名为颜色
//   $color: 旧签名的颜色参数
function gd_polygon_color(array $points, int $num_points_or_color, int $color): int
{
    // 对形式 → 新签名（3rd = color）
    if (count($points) > 0 && is_array($points[0])) {
        return $num_points_or_color;
    }
    $numPoints = count($points) / 2;
    // 旧签名：3rd=num_points, 4th=color
    if ($num_points_or_color > 0 && $num_points_or_color <= $numPoints) {
        return $color;
    }
    // 新签名：3rd=color
    return $num_points_or_color;
}

// gd_fill_polygon_scanline — 扫描线填充多边形（内部辅助）
//   $points 为扁平数组 [x0,y0,x1,y1,...]
function gd_fill_polygon_scanline(GdImage $image, array $points, int $color): void
{
    $n = count($points) / 2;
    if ($n < 3) { return; }

    // 求 y 范围（intval 提取 int，确保后续 float 运算正确）
    int $ymin = intval($points[1]);
    int $ymax = intval($points[1]);
    int $i = 1;
    while ($i < $n) {
        int $py = intval($points[$i * 2 + 1]);
        if ($py < $ymin) { $ymin = $py; }
        if ($py > $ymax) { $ymax = $py; }
        $i = $i + 1;
    }
    if ($ymin < 0) { $ymin = 0; }
    if ($ymax >= $image->height) { $ymax = $image->height - 1; }

    int $y = $ymin;
    while ($y <= $ymax) {
        // 求所有边与扫描线 y 的交点
        $xs = [];
        $i = 0;
        while ($i < $n) {
            int $j = ($i + 1) % $n;
            int $x1 = intval($points[$i * 2]);
            int $y1 = intval($points[$i * 2 + 1]);
            int $x2 = intval($points[$j * 2]);
            int $y2 = intval($points[$j * 2 + 1]);
            if ($y1 == $y2) { $i = $i + 1; continue; }  // 跳过水平边
            int $ye_min = $y1 < $y2 ? $y1 : $y2;
            int $ye_max = $y1 < $y2 ? $y2 : $y1;
            // 半开区间 [ye_min, ye_max) 避免顶点重复计数
            if ($y >= $ye_min && $y < $ye_max) {
                float $t = (float)($y - $y1) / (float)($y2 - $y1);
                float $xint = (float)$x1 + $t * (float)($x2 - $x1);
                $xs[] = intval($xint + 0.5);
            }
            $i = $i + 1;
        }
        // 排序交点（插入排序）
        int $xn = count($xs);
        int $a = 1;
        while ($a < $xn) {
            int $key = intval($xs[$a]);
            int $b = $a - 1;
            while ($b >= 0 && intval($xs[$b]) > $key) {
                $xs[$b + 1] = $xs[$b];
                $b = $b - 1;
            }
            $xs[$b + 1] = $key;
            $a = $a + 1;
        }
        // 成对填充
        int $k = 0;
        while ($k + 1 < $xn) {
            int $x1i = intval($xs[$k]);
            int $x2i = intval($xs[$k + 1]);
            if ($x1i < 0) { $x1i = 0; }
            if ($x2i >= $image->width) { $x2i = $image->width - 1; }
            int $x = $x1i;
            while ($x <= $x2i) {
                gd_set_pixel_blend($image, $x, $y, $color);
                $x = $x + 1;
            }
            $k = $k + 2;
        }
        $y = $y + 1;
    }
}

// imagepolygon — 多边形边框（闭合）
function imagepolygon(GdImage $image, array $points, int $num_points_or_color, int $color = -1): bool
{
    $flat = gd_flatten_points($points);
    $c = gd_polygon_color($points, $num_points_or_color, $color);
    $n = count($flat) / 2;
    if ($n < 2) { return false; }
    $i = 0;
    while ($i < $n) {
        $j = ($i + 1) % $n;
        imageline($image, $flat[$i * 2], $flat[$i * 2 + 1], $flat[$j * 2], $flat[$j * 2 + 1], $c);
        $i = $i + 1;
    }
    return true;
}

// imageopenpolygon — 开放多边形（不画最后一条闭合边）
function imageopenpolygon(GdImage $image, array $points, int $num_points_or_color, int $color = -1): bool
{
    $flat = gd_flatten_points($points);
    $c = gd_polygon_color($points, $num_points_or_color, $color);
    $n = count($flat) / 2;
    if ($n < 2) { return false; }
    $i = 0;
    while ($i < $n - 1) {
        imageline($image, $flat[$i * 2], $flat[$i * 2 + 1], $flat[($i + 1) * 2], $flat[($i + 1) * 2 + 1], $c);
        $i = $i + 1;
    }
    return true;
}

// imagefilledpolygon — 填充多边形（扫描线填充 + 边界描边）
function imagefilledpolygon(GdImage $image, array $points, int $num_points_or_color, int $color = -1): bool
{
    $flat = gd_flatten_points($points);
    $c = gd_polygon_color($points, $num_points_or_color, $color);
    $n = count($flat) / 2;
    if ($n < 3) {
        // 不足 3 点，仅画线
        $i = 0;
        while ($i < $n - 1) {
            imageline($image, $flat[$i * 2], $flat[$i * 2 + 1], $flat[($i + 1) * 2], $flat[($i + 1) * 2 + 1], $c);
            $i = $i + 1;
        }
        return true;
    }
    // 扫描线填充
    gd_fill_polygon_scanline($image, $flat, $c);
    // 描边
    $i = 0;
    while ($i < $n) {
        $j = ($i + 1) % $n;
        imageline($image, $flat[$i * 2], $flat[$i * 2 + 1], $flat[$j * 2], $flat[$j * 2 + 1], $c);
        $i = $i + 1;
    }
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 7: 内置位图字体与文字绘制
//
// 字体数据见 gd_fonts.php（由 #import gd 自动加载同目录 .php）
// 字体参数 $font 接受 int(1-5 内置字体) 或 GdFont(imageloadfont 加载)
//
// 位掩码约定（与 gd_fonts.php 一致）：
//   glyphs[c] 为字符 c 的位图行数组（长度=height）
//   每行为 int 位掩码，bit (w-1-x) 对应像素列 x（最左像素=最高位）
//   位 1 = 前景色，位 0 = 背景色
// ════════════════════════════════════════════════════════════

// gd_resolve_font — 解析字体参数为 GdFont 对象
//   $font: int(1-5) 选择内置字体，或 GdFont 对象
//   无效索引抛 Exception
function gd_resolve_font(mixed $font): GdFont|Exception
{
    if (is_int($font)) {
        if ($font == 1) { return gd_font_1(); }
        if ($font == 2) { return gd_font_2(); }
        if ($font == 3) { return gd_font_3(); }
        if ($font == 4) { return gd_font_4(); }
        if ($font == 5) { return gd_font_5(); }
        throw new Exception("gd_resolve_font: invalid font index $font (must be 1-5)");
    }
    // GdFont 对象：instanceof 后编译器自动缩窄类型，直接返回即可
    if ($font instanceof GdFont) {
        return $font;
    }
    throw new Exception("gd_resolve_font: invalid font type (must be int 1-5 or GdFont)");
}

/**
 * imagefontwidth(mixed $font): int
 *
 * 返回字体单个字符的宽度（像素）。
 * $font: 1-5(内置字体) 或 GdFont 对象
 */
function imagefontwidth(mixed $font): int|Exception
{
    $f = gd_resolve_font($font);
    return $f->width;
}

/**
 * imagefontheight(mixed $font): int
 *
 * 返回字体单个字符的高度（像素）。
 * $font: 1-5(内置字体) 或 GdFont 对象
 */
function imagefontheight(mixed $font): int|Exception
{
    $f = gd_resolve_font($font);
    return $f->height;
}

/**
 * imagechar(GdImage $image, mixed $font, int $x, int $y, string $char, int $color): bool
 *
 * 水平绘制单字符。
 * - $char 取首字节的 ASCII 码作为字形索引
 * - 字符从 ($x, $y) 向右向下延伸，占用 width×height 像素
 * - 空字符串或字符编码超出字形范围返回 false
 */
function imagechar(GdImage $image, mixed $font, int $x, int $y, string $char, int $color): bool|Exception
{
    $f = gd_resolve_font($font);
    if (strlen($char) == 0) { return false; }
    $c = ord(substr($char, 0, 1));
    $w = $f->width;
    $h = $f->height;
    $totalGlyphs = count($f->glyphs);
    $glyphCount = intval($totalGlyphs / $h);
    if ($c < 0 || $c >= $glyphCount) { return false; }
    // 扁平索引：glyphs[c * h + cy] 为字符 c 第 cy 行的位掩码
    // 按位掩码绘制：bit (w-1-cx) → 像素 (x+cx, y+cy)
    $cy = 0;
    while ($cy < $h) {
        $rowVal = $f->glyphs[$c * $h + $cy];
        $cx = 0;
        while ($cx < $w) {
            if (($rowVal & (1 << ($w - 1 - $cx))) != 0) {
                gd_set_pixel_blend($image, $x + $cx, $y + $cy, $color);
            }
            $cx = $cx + 1;
        }
        $cy = $cy + 1;
    }
    return true;
}

/**
 * imagecharup(GdImage $image, mixed $font, int $x, int $y, string $char, int $color): bool
 *
 * 垂直绘制单字符（逆时针旋转 90°，从下往上）。
 * - 字符从 ($x, $y) 向上延伸，x 方向扩展 height 像素
 * - 旋转映射：glyph[cy] 的 bit (w-1-cx) → 像素 (x+cy, y-cx)
 *   即原字符的行变为列，列变为行（自底向上）
 */
function imagecharup(GdImage $image, mixed $font, int $x, int $y, string $char, int $color): bool|Exception
{
    $f = gd_resolve_font($font);
    if (strlen($char) == 0) { return false; }
    $c = ord(substr($char, 0, 1));
    $w = $f->width;
    $h = $f->height;
    $totalGlyphs = count($f->glyphs);
    $glyphCount = intval($totalGlyphs / $h);
    if ($c < 0 || $c >= $glyphCount) { return false; }
    // 扁平索引：glyphs[c * h + cy] 为字符 c 第 cy 行的位掩码
    // 旋转映射：bit (w-1-cx) → 像素 (x+cy, y-cx)
    $cx = 0;
    while ($cx < $w) {
        $cy = 0;
        while ($cy < $h) {
            $rowVal = $f->glyphs[$c * $h + $cy];
            if (($rowVal & (1 << ($w - 1 - $cx))) != 0) {
                gd_set_pixel_blend($image, $x + $cy, $y - $cx, $color);
            }
            $cy = $cy + 1;
        }
        $cx = $cx + 1;
    }
    return true;
}

/**
 * imagestring(GdImage $image, mixed $font, int $x, int $y, string $string, int $color): bool
 *
 * 水平绘制字符串（从左到右）。
 * - 每个字符占用 font->width 像素宽
 * - 等价于依次调用 imagechar，x 递增 width
 */
function imagestring(GdImage $image, mixed $font, int $x, int $y, string $string, int $color): bool|Exception
{
    $f = gd_resolve_font($font);
    $w = $f->width;
    $len = strlen($string);
    $i = 0;
    while ($i < $len) {
        $ch = substr($string, $i, 1);
        imagechar($image, $font, $x + $i * $w, $y, $ch, $color);
        $i = $i + 1;
    }
    return true;
}

/**
 * imagestringup(GdImage $image, mixed $font, int $x, int $y, string $string, int $color): bool
 *
 * 垂直绘制字符串（从下往上）。
 * - 每个字符占用 font->height 像素高
 * - 等价于依次调用 imagecharup，y 递减 height
 */
function imagestringup(GdImage $image, mixed $font, int $x, int $y, string $string, int $color): bool|Exception
{
    $f = gd_resolve_font($font);
    $h = $f->height;
    $len = strlen($string);
    $i = 0;
    while ($i < $len) {
        $ch = substr($string, $i, 1);
        imagecharup($image, $font, $x, $y - $i * $h, $ch, $color);
        $i = $i + 1;
    }
    return true;
}

/**
 * imageloadfont(string $filename): GdFont
 *
 * 从文件加载 GDF 格式位图字体。
 *
 * GDF 文件格式（libgd 二进制 dump）：
 *   byte 0-3:   int32 nchars  — 字符数
 *   byte 4-7:   int32 offset  — 首字符的 ASCII 值（通常为 32=空格）
 *   byte 8-11:  int32 width   — 每字符像素宽度
 *   byte 12-15: int32 height  — 每字符像素高度
 *   byte 16-:   char[nchars*width*height] — 位图数据，每字节一个像素(0/1)
 *
 * 字节序：先尝试小端序（x86 原生），不匹配则尝试大端序。
 *
 * 返回的 GdFont 的 glyphs 扁平数组已按 offset 偏移填充：
 *   glyphs[c * height + row] 对应字符码 c 的第 row 行位掩码
 *   glyphs[0..offset-1] 对应空字形，glyphs[offset..offset+nchars-1] 为实际数据
 *   因此 imagechar 等函数可直接用字符 ASCII 码作为字形索引
 */
function imageloadfont(string $filename): GdFont|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imageloadfont: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // 读取 16 字节头部（小端序）
    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    $b2 = php_int(C->fgetc($f));
    $b3 = php_int(C->fgetc($f));
    $nchars = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    $b2 = php_int(C->fgetc($f));
    $b3 = php_int(C->fgetc($f));
    $offset = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    $b2 = php_int(C->fgetc($f));
    $b3 = php_int(C->fgetc($f));
    $w = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    $b2 = php_int(C->fgetc($f));
    $b3 = php_int(C->fgetc($f));
    $h = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

    // 验证头部基本合法性
    if ($nchars <= 0 || $nchars > 256 || $w <= 0 || $w > 64 || $h <= 0 || $h > 64
        || $offset < 0 || $offset > 255) {
        throw new Exception("imageloadfont: invalid font header (nchars=$nchars, offset=$offset, w=$w, h=$h)");
    }

    // 获取文件大小验证数据完整性
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $filesize = php_int(C->ftell($f));
    $body_size = $nchars * $w * $h;
    $expected_size = 16 + $body_size;

    // 字节序检测：小端不匹配则尝试大端
    if ($expected_size != $filesize) {
        // 重新以大端序读取头部
        C->fseek($f, c_int(0), c_int(0));  // SEEK_SET
        $b0 = php_int(C->fgetc($f));
        $b1 = php_int(C->fgetc($f));
        $b2 = php_int(C->fgetc($f));
        $b3 = php_int(C->fgetc($f));
        $nchars = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;

        $b0 = php_int(C->fgetc($f));
        $b1 = php_int(C->fgetc($f));
        $b2 = php_int(C->fgetc($f));
        $b3 = php_int(C->fgetc($f));
        $offset = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;

        $b0 = php_int(C->fgetc($f));
        $b1 = php_int(C->fgetc($f));
        $b2 = php_int(C->fgetc($f));
        $b3 = php_int(C->fgetc($f));
        $w = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;

        $b0 = php_int(C->fgetc($f));
        $b1 = php_int(C->fgetc($f));
        $b2 = php_int(C->fgetc($f));
        $b3 = php_int(C->fgetc($f));
        $h = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;

        $body_size = $nchars * $w * $h;
        $expected_size = 16 + $body_size;
        if ($expected_size != $filesize) {
            throw new Exception("imageloadfont: file size mismatch (expected=$expected_size, actual=$filesize)");
        }
        if ($nchars <= 0 || $nchars > 256 || $w <= 0 || $w > 64 || $h <= 0 || $h > 64
            || $offset < 0 || $offset > 255) {
            throw new Exception("imageloadfont: invalid font header BE (nchars=$nchars, offset=$offset, w=$w, h=$h)");
        }
    }

    // 定位到数据区起始
    C->fseek($f, c_int(16), c_int(0));  // SEEK_SET

    // 创建 GdFont 并填充字形数据
    $font = new GdFont();
    $font->width = $w;
    $font->height = $h;
    $font->glyphs = [];

    // 填充 0 到 offset-1 为空字形（扁平数组：每个字符占 h 个元素）
    $i = 0;
    while ($i < $offset) {
        $row = 0;
        while ($row < $h) {
            $font->glyphs[$i * $h + $row] = 0;
            $row = $row + 1;
        }
        $i = $i + 1;
    }

    // 读取实际字形数据，逐字节转换为位掩码格式（扁平存储）
    // GDF 数据布局：字符 c 的像素位于偏移 c*w*h，每行 w 字节，共 h 行
    // 扁平 glyphs：glyphs[(offset+c) * h + row] = 第 offset+c 个字符第 row 行的位掩码
    $c = 0;
    while ($c < $nchars) {
        $row = 0;
        while ($row < $h) {
            $rowVal = 0;
            $col = 0;
            while ($col < $w) {
                $pixel = php_int(C->fgetc($f));
                if ($pixel != 0) {
                    $rowVal = $rowVal | (1 << ($w - 1 - $col));
                }
                $col = $col + 1;
            }
            $font->glyphs[($offset + $c) * $h + $row] = $rowVal;
            $row = $row + 1;
        }
        $c = $c + 1;
    }

    return $font;
}

// ════════════════════════════════════════════════════════════
// Task 11: 状态与属性函数
//
// 包含：图像类型转换、Alpha 混合开关、图层效果、画线样式/画笔/平铺/剪辑区、
//       插值方法、分辨率、隔行扫描、GD 信息与格式位掩码。
//
// 设计说明：
//   - imagelayereffect 本阶段将 effect 映射到 alphaBlending（REPLACE→false，其余→true）
//   - imagetruecolortopalette 采用均匀量化（每通道取高位），dither 简化为不抖动
//   - imagepalettetotruecolor 遍历 pixels 将索引替换为 palette[index] 颜色值
//   - imageresolution/imageinterlace 为 getter/setter 二合一双模式函数
//   - gd_info/imagetypes 真实反映纯 phpc 实现能力，不谎报 JPEG/WebP/AVIF/XPM 支持
// ════════════════════════════════════════════════════════════

/**
 * imagetruecolortopalette(GdImage $image, bool $dither, int $num_colors): bool
 *
 * 真彩色图像转调色板图像。
 * - 均匀量化：每通道取高位（3-3-2 默认 256 色，按 num_colors 递减）
 * - dither 参数本阶段简化为不抖动（直接截断到桶起点）
 * - num_colors 钳位到 [1, 256]
 * - 已是调色板图像时无操作返回 true
 */
function imagetruecolortopalette(GdImage $image, bool $dither, int $num_colors): bool
{
    if (!$image->trueColor) { return true; }
    if ($num_colors < 1) { $num_colors = 1; }
    if ($num_colors > 256) { $num_colors = 256; }

    // ── 确定每通道量化位数（起始 3-3-2=256，按 num_colors 递减）──
    $rbits = 3;
    $gbits = 3;
    $bbits = 2;
    while ((1 << $rbits) * (1 << $gbits) * (1 << $bbits) > $num_colors
           && ($rbits + $gbits + $bbits) > 0) {
        if ($bbits > 0) { $bbits = $bbits - 1; }
        elseif ($gbits > 0) { $gbits = $gbits - 1; }
        else { $rbits = $rbits - 1; }
    }

    // ── 量化并构建调色板 ──
    // 使用扁平查找表：keyToIdx[key] = -1（未分配）或调色板索引
    // 最大键数 = 2^(rbits+gbits+bbits) <= 256
    $maxKeys = 1 << ($rbits + $gbits + $bbits);
    $keyToIdx = array_fill(0, $maxKeys, -1);
    $newPalette = [];    // 索引数组：索引 → 0x7FRRGGBB 颜色值
    $total = count($image->pixels);

    for ($i = 0; $i < $total; $i++) {
        $c = $image->pixels[$i];
        $r = gd_get_red($c);
        $g = gd_get_green($c);
        $b = gd_get_blue($c);

        // 取高位作为量化值
        $qr = $rbits > 0 ? ($r >> (8 - $rbits)) : 0;
        $qg = $gbits > 0 ? ($g >> (8 - $gbits)) : 0;
        $qb = $bbits > 0 ? ($b >> (8 - $bbits)) : 0;

        // 量化键：组合三通道量化值
        $key = ($qr << ($gbits + $bbits)) | ($qg << $bbits) | $qb;

        $existing = $keyToIdx[$key];
        if ($existing >= 0) {
            $image->pixels[$i] = $existing;
        } else {
            $idx = count($newPalette);
            if ($idx >= $num_colors) {
                // 超出颜色数上限：回退到索引 0
                $keyToIdx[$key] = 0;
                $image->pixels[$i] = 0;
            } else {
                // 桶起点作为调色板颜色（简化，不取中点）
                $pr = $rbits > 0 ? ($qr << (8 - $rbits)) : 0;
                $pg = $gbits > 0 ? ($qg << (8 - $gbits)) : 0;
                $pb = $bbits > 0 ? ($qb << (8 - $bbits)) : 0;
                $newPalette[$idx] = gd_make_color($pr, $pg, $pb, 0);
                $keyToIdx[$key] = $idx;
                $image->pixels[$i] = $idx;
            }
        }
    }

    $image->palette = $newPalette;
    $image->trueColor = false;
    $image->alphaBlending = false;
    return true;
}

/**
 * imagepalettetotruecolor(GdImage $image): bool
 *
 * 调色板图像转真彩色图像。
 * - 遍历 pixels，将调色板索引替换为 palette[index] 颜色值
 * - 无效索引或已释放槽位（-1）替换为 0（黑色）
 * - 已是真彩色图像时无操作返回 true
 * - 保留原 alphaBlending 设置（与 libgd 行为一致）
 */
function imagepalettetotruecolor(GdImage $image): bool
{
    if ($image->trueColor) { return true; }
    $total = count($image->pixels);
    $palCount = count($image->palette);
    for ($i = 0; $i < $total; $i++) {
        int $idx = intval($image->pixels[$i]);
        if ($idx >= 0 && $idx < $palCount && $image->palette[$idx] != -1) {
            $image->pixels[$i] = $image->palette[$idx];
        } else {
            $image->pixels[$i] = 0;
        }
    }
    $image->palette = [];
    $image->trueColor = true;
    return true;
}

/**
 * imagealphablending(GdImage $image, bool $enable): bool
 *
 * 设置 Alpha 混合开关，返回旧值。
 * - true=启用混合（绘图时按 alpha 通道混合新旧像素）
 * - false=直接替换（绘图时覆盖目标像素）
 */
function imagealphablending(GdImage $image, bool $enable): bool
{
    $old = $image->alphaBlending;
    $image->alphaBlending = $enable;
    return $old;
}

/**
 * imagesavealpha(GdImage $image, bool $enable): bool
 *
 * 设置是否保存 Alpha 通道，返回旧值。
 * - true=保存 Alpha 通道（PNG 输出时保留透明度信息）
 * - false=不保存（默认）
 */
function imagesavealpha(GdImage $image, bool $enable): bool
{
    $old = $image->saveAlpha;
    $image->saveAlpha = $enable;
    return $old;
}

/**
 * imagelayereffect(GdImage $image, int $effect): bool
 *
 * 设置图层效果（IMG_EFFECT_*）。
 * - 本阶段将 effect 映射到 alphaBlending：
 *   IMG_EFFECT_REPLACE → alphaBlending=false（替换模式）
 *   其他（ALPHABLEND/NORMAL/OVERLAY/MULTIPLY）→ alphaBlending=true
 * - 后续完整实现可扩展为独立 effect 属性
 */
function imagelayereffect(GdImage $image, int $effect): bool
{
    if ($effect == IMG_EFFECT_REPLACE) {
        $image->alphaBlending = false;
    } else {
        $image->alphaBlending = true;
    }
    return true;
}

/**
 * imagesetstyle(GdImage $image, array $style): bool
 *
 * 设置画线样式（颜色索引/颜色值数组）。
 * - style 为颜色序列，imageline 等函数使用 IMG_COLOR_STYLED 时按此序列循环绘制
 * - 空数组清除样式
 */
function imagesetstyle(GdImage $image, array $style): bool
{
    $image->style = $style;
    return true;
}

/**
 * imagesetbrush(GdImage $image, mixed $brush): bool
 *
 * 设置画笔图像。
 * - imageline 等函数使用 IMG_COLOR_BRUSHED 时以 brush 中心像素作为绘制颜色
 * - 本阶段仅存储 brush 引用，实际绘制由 gd_brush_pixel 等内部函数使用
 * - 参数声明为 mixed 而非 GdImage：phpc 代码生成器对 typed→mixed 属性赋值
 *   不会自动 VAR_OBJ 包装，使用 mixed 参数让调用点经 wrapTvarAssign 包装
 */
function imagesetbrush(GdImage $image, mixed $brush): bool
{
    $image->brush = $brush;
    return true;
}

/**
 * imagesettile(GdImage $image, mixed $tile): bool
 *
 * 设置平铺图像。
 * - imagefill 等函数使用 IMG_COLOR_TILED 时以 tile 图像平铺填充
 * - 本阶段仅存储 tile 引用
 * - 参数声明为 mixed（同 imagesetbrush，确保 VAR_OBJ 包装）
 */
function imagesettile(GdImage $image, mixed $tile): bool
{
    $image->tile = $tile;
    return true;
}

/**
 * imagesetclip(GdImage $image, int $x1, int $y1, int $x2, int $y2): bool
 *
 * 设置剪辑区（裁剪矩形）。
 * - 后续绘图操作仅影响 [x1,y1]-[x2,y2] 范围内的像素
 * - 自动规范：x1<=x2, y1<=y2
 */
function imagesetclip(GdImage $image, int $x1, int $y1, int $x2, int $y2): bool
{
    if ($x1 > $x2) { $tx = $x1; $x1 = $x2; $x2 = $tx; }
    if ($y1 > $y2) { $ty = $y1; $y1 = $y2; $y2 = $ty; }
    $image->clip = [$x1, $y1, $x2, $y2];
    return true;
}

/**
 * imagegetclip(GdImage $image): array
 *
 * 获取剪辑区，返回 [x1, y1, x2, y2]。
 * - 未设置剪辑区时返回图像全范围 [0, 0, width-1, height-1]
 */
function imagegetclip(GdImage $image): array
{
    if (count($image->clip) >= 4) {
        return [$image->clip[0], $image->clip[1], $image->clip[2], $image->clip[3]];
    }
    return [0, 0, $image->width - 1, $image->height - 1];
}

/**
 * imagegetinterpolation(GdImage $image): int
 *
 * 获取当前插值方法（IMG_* 常量）。
 */
function imagegetinterpolation(GdImage $image): int
{
    return $image->interpolationMethod;
}

/**
 * imagesetinterpolation(GdImage $image, int $method = IMG_BILINEAR_FIXED): bool
 *
 * 设置插值方法（用于 imagescale/imagecopyresampled 等）。
 * - 默认 IMG_BILINEAR_FIXED(3)，与 libgd 默认一致
 */
function imagesetinterpolation(GdImage $image, int $method = IMG_BILINEAR_FIXED): bool
{
    $image->interpolationMethod = $method;
    return true;
}

/**
 * imageresolution(GdImage $image, int $res_x = -1, int $res_y = -1): array|bool
 *
 * 获取或设置分辨率（DPI）。
 * - 无参数（res_x=res_y=-1）：返回当前 [resolutionX, resolutionY]
 * - 仅 res_x > 0：同时设置 X 和 Y 为 res_x
 * - res_x > 0 且 res_y > 0：分别设置 X 和 Y
 * - 设置模式返回 true
 */
function imageresolution(GdImage $image, int $res_x = -1, int $res_y = -1): array|bool
{
    // getter 模式：两个参数均为 -1
    if ($res_x == -1 && $res_y == -1) {
        return [$image->resolutionX, $image->resolutionY];
    }
    // setter 模式
    if ($res_x > 0) {
        $image->resolutionX = $res_x;
        if ($res_y <= 0) {
            $image->resolutionY = $res_x;
        }
    }
    if ($res_y > 0) {
        $image->resolutionY = $res_y;
    }
    return true;
}

/**
 * imageinterlace(GdImage $image, int $enable = -1): int|bool
 *
 * 获取或设置隔行扫描。
 * - enable=-1（默认）：返回当前 0(否)/1(是)
 * - enable=0 或 1：设置 interlace 并返回 true
 */
function imageinterlace(GdImage $image, int $enable = -1): int|bool
{
    if ($enable == -1) {
        return $image->interlace ? 1 : 0;
    }
    $image->interlace = ($enable != 0);
    return true;
}

/**
 * gd_info(): array
 *
 * 返回 GD 扩展能力信息。
 * - 真实反映纯 phpc 实现能力，不谎报 JPEG/WebP/AVIF/XPM/FreeType 支持
 * - 支持的格式：GIF(读/创建)、PNG、WBMP、XBM、BMP、TGA(读)
 * - 不支持的格式：JPEG、WebP、AVIF、XPM、FreeType
 */
function gd_info(): array
{
    return [
        "GD Version" => "bundled (2.3.3 compatible)",
        "FreeType Support" => false,
        "GIF Read Support" => true,
        "GIF Create Support" => true,
        "JPEG Support" => false,
        "PNG Support" => true,
        "WBMP Support" => true,
        "XPM Support" => false,
        "XBM Support" => true,
        "WebP Support" => false,
        "AVIF Support" => false,
        "BMP Support" => true,
        "TGA Read Support" => true,
    ];
}

/**
 * imagetypes(): int
 *
 * 返回支持的图像格式位掩码。
 * - 只返回实际支持的格式：IMG_GIF|IMG_PNG|IMG_WBMP|IMG_BMP|IMG_TGA
 * - 不包含 IMG_JPG/IMG_WEBP/IMG_AVIF/IMG_XPM（纯 phpc 不支持）
 * - 计算：2|8|16|128|256 = 410
 */
function imagetypes(): int
{
    return IMG_GIF | IMG_PNG | IMG_WBMP | IMG_BMP | IMG_TGA;
}

// ════════════════════════════════════════════════════════════
// Task 9: 图像变换
//
// 包含：imageflip / imagerotate / imagecrop / imagecropauto /
//       imagescale / imageaffine / imageaffinematrixget /
//       imageaffinematrixconcat
//
// 设计说明：
//   - imagerotate/imagescale/imageaffine 返回真彩色新图像（不修改原图）
//   - imagecrop/imagecropauto 返回真彩色新图像（统一类型，简化实现）
//   - 仿射矩阵约定 [a,b,c,d,e,f]：x'=a*x+c*y+e, y'=b*x+d*y+f
//   - imagerotate 角度按"逆时针视觉"约定（与 PHP 文档一致）
//   - imagescale 默认 IMG_BILINEAR_FIXED 用双线性，其他模式回退最近邻
//   - imagecropauto 各 mode 行为与 libgd 一致：
//       DEFAULT   - 用 color 参数（-1 时回退 transparent，再回退 black）
//       TRANSPARENT - 用透明色（无透明色则不裁剪，返回原图拷贝）
//       BLACK/WHITE - 用黑/白
//       SIDES     - 用 (0,0) 处的颜色作为四边色
//       THRESHOLD - 用 color + threshold 阈值裁剪
// ════════════════════════════════════════════════════════════

// gd_pixel_color — 获取像素的 0x7FRRGGBB 颜色值（兼容真彩色与调色板）
//   越界或无效索引返回 0（黑色不透明）
//   不同于 gd_get_pixel_raw（返回原始 palette 索引），本函数始终返回颜色值
function gd_pixel_color(GdImage $image, int $x, int $y): int
{
    if ($x < 0 || $x >= $image->width || $y < 0 || $y >= $image->height) { return 0; }
    $v = gd_get_pixel_raw($image, $x, $y);
    if ($image->trueColor) {
        return $v;
    }
    $total = count($image->palette);
    if ($v < 0 || $v >= $total) { return 0; }
    if ($image->palette[$v] == -1) { return 0; }
    return $image->palette[$v];
}

/**
 * imageflip(GdImage $image, int $mode): bool
 *
 * 翻转图像（原地修改，不返回新图像）。
 * - IMG_FLIP_HORIZONTAL(1): 左右翻转
 * - IMG_FLIP_VERTICAL(2): 上下翻转
 * - IMG_FLIP_BOTH(3): 水平+垂直（等价于 180° 旋转）
 * - 未知 mode 返回 false
 */
function imageflip(GdImage $image, int $mode): bool
{
    int $w = $image->width;
    int $h = $image->height;

    if ($mode == IMG_FLIP_HORIZONTAL) {
        int $halfW = intval($w / 2);
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $halfW) {
                int $i1 = $y * $w + $x;
                int $i2 = $y * $w + ($w - 1 - $x);
                $tmp = $image->pixels[$i1];
                $image->pixels[$i1] = $image->pixels[$i2];
                $image->pixels[$i2] = $tmp;
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    if ($mode == IMG_FLIP_VERTICAL) {
        int $halfH = intval($h / 2);
        int $y = 0;
        while ($y < $halfH) {
            int $x = 0;
            while ($x < $w) {
                int $i1 = $y * $w + $x;
                int $i2 = ($h - 1 - $y) * $w + $x;
                $tmp = $image->pixels[$i1];
                $image->pixels[$i1] = $image->pixels[$i2];
                $image->pixels[$i2] = $tmp;
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    if ($mode == IMG_FLIP_BOTH) {
        // 水平+垂直 = 180° 旋转：首尾交换 pixels 数组
        int $total = $w * $h;
        int $half = intval($total / 2);
        int $i = 0;
        while ($i < $half) {
            int $j = $total - 1 - $i;
            $tmp = $image->pixels[$i];
            $image->pixels[$i] = $image->pixels[$j];
            $image->pixels[$j] = $tmp;
            $i = $i + 1;
        }
        return true;
    }

    return false;
}

/**
 * imagerotate(GdImage $image, float $angle, int $background_color, int $ignore_transparent = 0): GdImage
 *
 * 逆时针旋转图像（返回新图像，不修改原图）。
 * - $angle: 旋转角度（度，逆时针为正），规范化到 [0, 360)
 * - $background_color: 空白区域填充色（0x7FRRGGBB）
 * - $ignore_transparent: 非 0 时忽略原图透明色设置
 * - 新图像尺寸：new_w = w*|cos| + h*|sin|, new_h = w*|sin| + h*|cos|
 * - 反向映射（最近邻）：对每个新像素反算原图坐标，越界用背景色
 */
function imagerotate(GdImage $image, float $angle, int $background_color, int $ignore_transparent = 0): GdImage|Exception
{
    // 规范化角度到 [0, 360)
    float $a = $angle;
    while ($a < 0.0) { $a = $a + 360.0; }
    while ($a >= 360.0) { $a = $a - 360.0; }

    int $w = $image->width;
    int $h = $image->height;

    // 0 度：返回原图的真彩色拷贝
    if ($a == 0.0) {
        $dst0 = imagecreatetruecolor($w, $h);
        int $total0 = $w * $h;
        int $i0 = 0;
        while ($i0 < $total0) {
            $dst0->pixels[$i0] = gd_pixel_color($image, $i0 % $w, intval($i0 / $w));
            $i0 = $i0 + 1;
        }
        return $dst0;
    }

    float $rad = $a * GD_PI / 180.0;
    float $cosA = cos($rad);
    float $sinA = sin($rad);
    float $fabsCos = $cosA < 0.0 ? 0.0 - $cosA : $cosA;
    float $fabsSin = $sinA < 0.0 ? 0.0 - $sinA : $sinA;

    int $new_w = intval((float)$w * $fabsCos + (float)$h * $fabsSin + 0.5);
    int $new_h = intval((float)$w * $fabsSin + (float)$h * $fabsCos + 0.5);
    if ($new_w < 1) { $new_w = 1; }
    if ($new_h < 1) { $new_h = 1; }

    $dst = imagecreatetruecolor($new_w, $new_h);

    // 填充背景色
    int $total = $new_w * $new_h;
    int $i = 0;
    while ($i < $total) {
        $dst->pixels[$i] = $background_color;
        $i = $i + 1;
    }

    // 旋转中心
    float $cx_orig = ((float)$w - 1.0) / 2.0;
    float $cy_orig = ((float)$h - 1.0) / 2.0;
    float $cx_new = ((float)$new_w - 1.0) / 2.0;
    float $cy_new = ((float)$new_h - 1.0) / 2.0;

    // 对新图像每个像素反向映射到原图（最近邻）
    // 逆时针视觉旋转的逆映射：
    //   ox = cos(a)*(nx-cx_new) - sin(a)*(ny-cy_new) + cx_orig
    //   oy = sin(a)*(nx-cx_new) + cos(a)*(ny-cy_new) + cy_orig
    int $ny = 0;
    while ($ny < $new_h) {
        int $nx = 0;
        while ($nx < $new_w) {
            float $dx = (float)$nx - $cx_new;
            float $dy = (float)$ny - $cy_new;
            float $ox = $cosA * $dx - $sinA * $dy + $cx_orig;
            float $oy = $sinA * $dx + $cosA * $dy + $cy_orig;
            // 四舍五入到最近整数（正负均处理）
            int $ix = intval($ox + ($ox >= 0.0 ? 0.5 : -0.5));
            int $iy = intval($oy + ($oy >= 0.0 ? 0.5 : -0.5));
            if ($ix >= 0 && $ix < $w && $iy >= 0 && $iy < $h) {
                $dst->pixels[$ny * $new_w + $nx] = gd_pixel_color($image, $ix, $iy);
            }
            $nx = $nx + 1;
        }
        $ny = $ny + 1;
    }

    if ($ignore_transparent == 0 && $image->transparentColor != -1) {
        $dst->transparentColor = $image->transparentColor;
    }
    return $dst;
}

/**
 * imagecrop(GdImage $image, array $rect): GdImage
 *
 * 矩形裁剪（返回新图像）。
 * - $rect: ['x'=>int, 'y'=>int, 'width'=>int, 'height'=>int]
 * - 越界区域填 0（黑色不透明，imagecreatetruecolor 默认值）
 * - width/height <= 0 抛 Exception
 */
function imagecrop(GdImage $image, array $rect): GdImage|Exception
{
    int $rx = 0;
    int $ry = 0;
    int $rw = 0;
    int $rh = 0;
    if (isset($rect['x'])) { $rx = (int)$rect['x']; }
    if (isset($rect['y'])) { $ry = (int)$rect['y']; }
    if (isset($rect['width'])) { $rw = (int)$rect['width']; }
    if (isset($rect['height'])) { $rh = (int)$rect['height']; }
    if ($rw <= 0 || $rh <= 0) {
        throw new Exception("imagecrop: invalid rect dimensions (w=$rw, h=$rh)");
    }

    $dst = imagecreatetruecolor($rw, $rh);
    int $dy = 0;
    while ($dy < $rh) {
        int $dx = 0;
        while ($dx < $rw) {
            int $sx = $rx + $dx;
            int $sy = $ry + $dy;
            if ($sx >= 0 && $sx < $image->width && $sy >= 0 && $sy < $image->height) {
                $dst->pixels[$dy * $rw + $dx] = gd_pixel_color($image, $sx, $sy);
            }
            $dx = $dx + 1;
        }
        $dy = $dy + 1;
    }
    return $dst;
}

/**
 * imagecropauto(GdImage $image, int $mode, float $threshold, int $color): GdImage
 *
 * 自动裁剪（返回新图像）。
 * - DEFAULT: 用 color 参数（-1 时回退 transparent，再回退 black）
 * - TRANSPARENT: 用透明色（无透明色则不裁剪，返回原图拷贝）
 * - BLACK/WHITE: 用黑/白作为背景色裁剪边界
 * - SIDES: 用 (0,0) 处的颜色作为四边色裁剪
 * - THRESHOLD: 用 color + threshold 阈值裁剪（颜色距离 <= threshold 视为背景）
 * - 无非背景像素时返回原图拷贝
 */
function imagecropauto(GdImage $image, int $mode = IMG_CROP_DEFAULT, float $threshold = 0.5, int $color = -1): GdImage|Exception
{
    int $w = $image->width;
    int $h = $image->height;

    // 确定背景色
    int $bgColor = 0;
    int $useThreshold = 0;

    if ($mode == IMG_CROP_DEFAULT) {
        if ($color != -1) {
            $bgColor = $color;
        } elseif ($image->transparentColor != -1) {
            if ($image->trueColor) {
                $bgColor = $image->transparentColor;
            } else {
                int $tc = $image->transparentColor;
                int $palTotal = count($image->palette);
                if ($tc >= 0 && $tc < $palTotal && $image->palette[$tc] != -1) {
                    $bgColor = $image->palette[$tc];
                }
            }
        } else {
            $bgColor = 0;
        }
    } elseif ($mode == IMG_CROP_TRANSPARENT) {
        if ($image->transparentColor == -1) {
            return imagecrop($image, ['x' => 0, 'y' => 0, 'width' => $w, 'height' => $h]);
        }
        if ($image->trueColor) {
            $bgColor = $image->transparentColor;
        } else {
            int $tc = $image->transparentColor;
            int $palTotal = count($image->palette);
            if ($tc >= 0 && $tc < $palTotal && $image->palette[$tc] != -1) {
                $bgColor = $image->palette[$tc];
            }
        }
    } elseif ($mode == IMG_CROP_BLACK) {
        $bgColor = 0;
    } elseif ($mode == IMG_CROP_WHITE) {
        $bgColor = 0x00FFFFFF;
    } elseif ($mode == IMG_CROP_SIDES) {
        $bgColor = gd_pixel_color($image, 0, 0);
    } elseif ($mode == IMG_CROP_THRESHOLD) {
        $bgColor = $color;
        $useThreshold = 1;
    } else {
        return imagecrop($image, ['x' => 0, 'y' => 0, 'width' => $w, 'height' => $h]);
    }

    int $bgR = gd_get_red($bgColor);
    int $bgG = gd_get_green($bgColor);
    int $bgB = gd_get_blue($bgColor);

    // THRESHOLD 模式：颜色距离平方和 <= threshold^2 * 3 * 255^2 视为背景
    float $thrSq = $threshold * $threshold * 3.0 * 255.0 * 255.0;

    int $x1 = $w;
    int $y1 = $h;
    int $x2 = -1;
    int $y2 = -1;

    int $y = 0;
    while ($y < $h) {
        int $x = 0;
        while ($x < $w) {
            int $pc = gd_pixel_color($image, $x, $y);
            int $isBg = 0;
            if ($useThreshold) {
                int $pr = gd_get_red($pc);
                int $pg = gd_get_green($pc);
                int $pb = gd_get_blue($pc);
                int $dr = $pr - $bgR;
                int $dg = $pg - $bgG;
                int $db = $pb - $bgB;
                int $distSq = $dr * $dr + $dg * $dg + $db * $db;
                if ((float)$distSq <= $thrSq) {
                    $isBg = 1;
                }
            } else {
                if ($pc == $bgColor) {
                    $isBg = 1;
                }
            }
            if (!$isBg) {
                if ($x < $x1) { $x1 = $x; }
                if ($x > $x2) { $x2 = $x; }
                if ($y < $y1) { $y1 = $y; }
                if ($y > $y2) { $y2 = $y; }
            }
            $x = $x + 1;
        }
        $y = $y + 1;
    }

    // 无非背景像素：返回原图拷贝
    if ($x2 < 0) {
        return imagecrop($image, ['x' => 0, 'y' => 0, 'width' => $w, 'height' => $h]);
    }

    int $cw = $x2 - $x1 + 1;
    int $ch = $y2 - $y1 + 1;
    return imagecrop($image, ['x' => $x1, 'y' => $y1, 'width' => $cw, 'height' => $ch]);
}

/**
 * imagescale(GdImage $image, int $width, int $height, int $mode): GdImage
 *
 * 缩放图像（返回新图像）。
 * - $width: 目标宽度（必须 > 0）
 * - $height: 目标高度（-1 表示按比例计算：srcH * width / srcW）
 * - $mode: 插值方法
 *   - IMG_BILINEAR_FIXED(3): 双线性插值
 *   - 其他模式（含 IMG_NEAREST_NEIGHBOUR）: 最近邻插值
 * - 新图像的 interpolationMethod 设为 $mode
 */
function imagescale(GdImage $image, int $width, int $height = -1, int $mode = IMG_BILINEAR_FIXED): GdImage|Exception
{
    int $srcW = $image->width;
    int $srcH = $image->height;
    if ($width <= 0) {
        throw new Exception("imagescale: invalid width $width");
    }

    int $dstW = $width;
    int $dstH = $height;
    if ($dstH <= 0) {
        $dstH = intval((float)$srcH * (float)$width / (float)$srcW + 0.5);
    }
    if ($dstH <= 0) { $dstH = 1; }

    $dst = imagecreatetruecolor($dstW, $dstH);

    float $sxRatio = (float)$srcW / (float)$dstW;
    float $syRatio = (float)$srcH / (float)$dstH;

    int $useBilinear = ($mode == IMG_BILINEAR_FIXED) ? 1 : 0;

    int $dy = 0;
    while ($dy < $dstH) {
        int $dx = 0;
        while ($dx < $dstW) {
            float $sx = (float)$dx * $sxRatio;
            float $sy = (float)$dy * $syRatio;
            int $ix = intval($sx);
            int $iy = intval($sy);
            if ($ix >= $srcW) { $ix = $srcW - 1; }
            if ($iy >= $srcH) { $iy = $srcH - 1; }

            if ($useBilinear) {
                // 双线性插值
                int $ix1 = $ix + 1;
                int $iy1 = $iy + 1;
                if ($ix1 >= $srcW) { $ix1 = $srcW - 1; }
                if ($iy1 >= $srcH) { $iy1 = $srcH - 1; }
                float $fx = $sx - (float)$ix;
                float $fy = $sy - (float)$iy;
                int $p00 = gd_pixel_color($image, $ix, $iy);
                int $p10 = gd_pixel_color($image, $ix1, $iy);
                int $p01 = gd_pixel_color($image, $ix, $iy1);
                int $p11 = gd_pixel_color($image, $ix1, $iy1);
                int $r00 = gd_get_red($p00);
                int $g00 = gd_get_green($p00);
                int $b00 = gd_get_blue($p00);
                int $a00 = gd_get_alpha($p00);
                int $r10 = gd_get_red($p10);
                int $g10 = gd_get_green($p10);
                int $b10 = gd_get_blue($p10);
                int $a10 = gd_get_alpha($p10);
                int $r01 = gd_get_red($p01);
                int $g01 = gd_get_green($p01);
                int $b01 = gd_get_blue($p01);
                int $a01 = gd_get_alpha($p01);
                int $r11 = gd_get_red($p11);
                int $g11 = gd_get_green($p11);
                int $b11 = gd_get_blue($p11);
                int $a11 = gd_get_alpha($p11);
                float $w00 = (1.0 - $fx) * (1.0 - $fy);
                float $w10 = $fx * (1.0 - $fy);
                float $w01 = (1.0 - $fx) * $fy;
                float $w11 = $fx * $fy;
                int $r = intval((float)$r00 * $w00 + (float)$r10 * $w10 + (float)$r01 * $w01 + (float)$r11 * $w11 + 0.5);
                int $g = intval((float)$g00 * $w00 + (float)$g10 * $w10 + (float)$g01 * $w01 + (float)$g11 * $w11 + 0.5);
                int $b = intval((float)$b00 * $w00 + (float)$b10 * $w10 + (float)$b01 * $w01 + (float)$b11 * $w11 + 0.5);
                int $a = intval((float)$a00 * $w00 + (float)$a10 * $w10 + (float)$a01 * $w01 + (float)$a11 * $w11 + 0.5);
                $dst->pixels[$dy * $dstW + $dx] = gd_make_color($r, $g, $b, $a);
            } else {
                // 最近邻插值
                $dst->pixels[$dy * $dstW + $dx] = gd_pixel_color($image, $ix, $iy);
            }
            $dx = $dx + 1;
        }
        $dy = $dy + 1;
    }

    $dst->interpolationMethod = $mode;
    return $dst;
}

/**
 * imageaffine(GdImage $image, array $affine, array $clip): GdImage
 *
 * 仿射变换（返回新图像）。
 * - $affine: [a, b, c, d, e, f] 6 元素矩阵
 *   x' = a*x + c*y + e
 *   y' = b*x + d*y + f
 * - $clip: ['x'=>int, 'y'=>int, 'width'=>int, 'height'=>int]
 *   空数组时自动计算变换后角点的包围盒
 * - 对输出每个像素反向映射到原图（最近邻），越界像素为 0（黑色）
 * - 奇异矩阵（det=0）抛 Exception
 */
function imageaffine(GdImage $image, array $affine, array $clip = []): GdImage|Exception
{
    if (count($affine) < 6) {
        throw new Exception("imageaffine: affine matrix must have 6 elements");
    }

    // 使用 floatval/intval 而非 (float)/(int) 强制转换：
    // VAR_AS_FLOAT/VAR_AS_INT 宏仅处理单一类型，无法跨 TYPE_INT/TYPE_FLOAT 转换
    float $a = floatval($affine[0]);
    float $b = floatval($affine[1]);
    float $c = floatval($affine[2]);
    float $d = floatval($affine[3]);
    float $e = floatval($affine[4]);
    float $f = floatval($affine[5]);

    int $srcW = $image->width;
    int $srcH = $image->height;

    // 确定 clip 区域
    int $clipX = 0;
    int $clipY = 0;
    int $clipW = 0;
    int $clipH = 0;

    if (count($clip) >= 4 && isset($clip['width']) && isset($clip['height'])) {
        if (isset($clip['x'])) { $clipX = intval($clip['x']); }
        if (isset($clip['y'])) { $clipY = intval($clip['y']); }
        $clipW = intval($clip['width']);
        $clipH = intval($clip['height']);
    } else {
        // 计算 4 个变换后角点的包围盒
        float $sw = (float)$srcW;
        float $sh = (float)$srcH;
        float $x0 = $a * 0.0 + $c * 0.0 + $e;
        float $y0 = $b * 0.0 + $d * 0.0 + $f;
        float $x1 = $a * $sw + $c * 0.0 + $e;
        float $y1 = $b * $sw + $d * 0.0 + $f;
        float $x2 = $a * 0.0 + $c * $sh + $e;
        float $y2 = $b * 0.0 + $d * $sh + $f;
        float $x3 = $a * $sw + $c * $sh + $e;
        float $y3 = $b * $sw + $d * $sh + $f;

        float $minX = $x0;
        if ($x1 < $minX) { $minX = $x1; }
        if ($x2 < $minX) { $minX = $x2; }
        if ($x3 < $minX) { $minX = $x3; }
        float $maxX = $x0;
        if ($x1 > $maxX) { $maxX = $x1; }
        if ($x2 > $maxX) { $maxX = $x2; }
        if ($x3 > $maxX) { $maxX = $x3; }
        float $minY = $y0;
        if ($y1 < $minY) { $minY = $y1; }
        if ($y2 < $minY) { $minY = $y2; }
        if ($y3 < $minY) { $minY = $y3; }
        float $maxY = $y0;
        if ($y1 > $maxY) { $maxY = $y1; }
        if ($y2 > $maxY) { $maxY = $y2; }
        if ($y3 > $maxY) { $maxY = $y3; }

        $clipX = intval($minX);
        $clipY = intval($minY);
        $clipW = intval($maxX - $minX + 0.5);
        $clipH = intval($maxY - $minY + 0.5);
    }

    if ($clipW <= 0 || $clipH <= 0) {
        throw new Exception("imageaffine: invalid clip dimensions (w=$clipW, h=$clipH)");
    }

    // 计算仿射矩阵的逆（用于 new → original 映射）
    float $det = $a * $d - $b * $c;
    if ($det == 0.0) {
        throw new Exception("imageaffine: singular affine matrix (det=0)");
    }
    float $invA = $d / $det;
    float $invB = (0.0 - $b) / $det;
    float $invC = (0.0 - $c) / $det;
    float $invD = $a / $det;
    float $invE = ($c * $f - $d * $e) / $det;
    float $invF = ($b * $e - $a * $f) / $det;

    $dst = imagecreatetruecolor($clipW, $clipH);

    // 对输出每个像素反向映射到原图（最近邻）
    int $ny = 0;
    while ($ny < $clipH) {
        int $nx = 0;
        while ($nx < $clipW) {
            float $tx = (float)($clipX + $nx);
            float $ty = (float)($clipY + $ny);
            float $ox = $invA * $tx + $invC * $ty + $invE;
            float $oy = $invB * $tx + $invD * $ty + $invF;
            int $ix = intval($ox);
            int $iy = intval($oy);
            if ($ix >= 0 && $ix < $srcW && $iy >= 0 && $iy < $srcH) {
                $dst->pixels[$ny * $clipW + $nx] = gd_pixel_color($image, $ix, $iy);
            }
            $nx = $nx + 1;
        }
        $ny = $ny + 1;
    }

    return $dst;
}

/**
 * imageaffinematrixget(int $type, array $options): array
 *
 * 获取仿射矩阵（返回 [a, b, c, d, e, f] 6 元素浮点数组）。
 * - IMG_AFFINE_TRANSLATE: options=['x'=>dx, 'y'=>dy] → [1, 0, 0, 1, dx, dy]
 * - IMG_AFFINE_SCALE: options=['x'=>sx, 'y'=>sy] → [sx, 0, 0, sy, 0, 0]
 * - IMG_AFFINE_ROTATE: options=['angle'=>deg] → [cos, sin, -sin, cos, 0, 0]
 * - IMG_AFFINE_SHEAR_HORIZONTAL: options=['angle'=>deg] → [1, 0, tan, 1, 0, 0]
 * - IMG_AFFINE_SHEAR_VERTICAL: options=['angle'=>deg] → [1, tan, 0, 1, 0, 0]
 * - 旋转/剪切矩阵与 libgd gdAffineRotate/gdAffineShear* 一致
 */
function imageaffinematrixget(int $type, array $options): array|Exception
{
    // 使用 floatval 而非 (float)：数组元素 t_var 可能是 TYPE_INT/TYPE_FLOAT，
    // VAR_AS_FLOAT 宏仅处理 TYPE_FLOAT，跨类型转换会返回 0
    if ($type == IMG_AFFINE_TRANSLATE) {
        float $tx = 0.0;
        float $ty = 0.0;
        if (isset($options['x'])) { $tx = floatval($options['x']); }
        if (isset($options['y'])) { $ty = floatval($options['y']); }
        return [1.0, 0.0, 0.0, 1.0, $tx, $ty];
    }

    if ($type == IMG_AFFINE_SCALE) {
        float $sx = 1.0;
        float $sy = 1.0;
        if (isset($options['x'])) { $sx = floatval($options['x']); }
        if (isset($options['y'])) { $sy = floatval($options['y']); }
        return [$sx, 0.0, 0.0, $sy, 0.0, 0.0];
    }

    if ($type == IMG_AFFINE_ROTATE) {
        float $angle = 0.0;
        if (isset($options['angle'])) { $angle = floatval($options['angle']); }
        float $rad = $angle * GD_PI / 180.0;
        float $cosA = cos($rad);
        float $sinA = sin($rad);
        return [$cosA, $sinA, 0.0 - $sinA, $cosA, 0.0, 0.0];
    }

    if ($type == IMG_AFFINE_SHEAR_HORIZONTAL) {
        float $angle = 0.0;
        if (isset($options['angle'])) { $angle = floatval($options['angle']); }
        float $m = tan($angle * GD_PI / 180.0);
        return [1.0, 0.0, $m, 1.0, 0.0, 0.0];
    }

    if ($type == IMG_AFFINE_SHEAR_VERTICAL) {
        float $angle = 0.0;
        if (isset($options['angle'])) { $angle = floatval($options['angle']); }
        float $m = tan($angle * GD_PI / 180.0);
        return [1.0, $m, 0.0, 1.0, 0.0, 0.0];
    }

    throw new Exception("imageaffinematrixget: invalid type $type");
}

/**
 * imageaffinematrixconcat(array $matrix1, array $matrix2): array
 *
 * 连接两个仿射矩阵（m1 * m2，返回 [a, b, c, d, e, f]）。
 * - 矩阵乘法：result = m1 × m2（m1 先应用 m2 后应用）
 * - 公式与 libgd gdAffineConcat 一致
 */
function imageaffinematrixconcat(array $matrix1, array $matrix2): array|Exception
{
    if (count($matrix1) < 6 || count($matrix2) < 6) {
        throw new Exception("imageaffinematrixconcat: matrices must have 6 elements each");
    }

    // 使用 floatval 而非 (float)：数组元素 t_var 可能是 TYPE_INT/TYPE_FLOAT
    float $a1 = floatval($matrix1[0]);
    float $b1 = floatval($matrix1[1]);
    float $c1 = floatval($matrix1[2]);
    float $d1 = floatval($matrix1[3]);
    float $e1 = floatval($matrix1[4]);
    float $f1 = floatval($matrix1[5]);
    float $a2 = floatval($matrix2[0]);
    float $b2 = floatval($matrix2[1]);
    float $c2 = floatval($matrix2[2]);
    float $d2 = floatval($matrix2[3]);
    float $e2 = floatval($matrix2[4]);
    float $f2 = floatval($matrix2[5]);

    float $a = $a1 * $a2 + $c1 * $b2;
    float $b = $b1 * $a2 + $d1 * $b2;
    float $c = $a1 * $c2 + $c1 * $d2;
    float $d = $b1 * $c2 + $d1 * $d2;
    float $e = $a1 * $e2 + $c1 * $f2 + $e1;
    float $f = $b1 * $e2 + $d1 * $f2 + $f1;

    return [$a, $b, $c, $d, $e, $f];
}

// ════════════════════════════════════════════════════════════
// Task 8: 图像复制与缩放
//
// 实现函数：
//   - imagecopy          区域复制（直接覆盖，不混合）
//   - imagecopymerge     按百分比混合（不处理 alpha 通道）
//   - imagecopymergegray 按百分比混合（dst 转灰度后参与混合）
//   - imagecopyresized   最近邻缩放
//   - imagecopyresampled 双线性插值缩放（调色板 dst 回退到 resized）
//
// 透明色处理：
//   - truecolor 图像：transparentColor 为 0x7FRRGGBB 颜色值
//   - 调色板图像：transparentColor 为调色板索引
//   - 源像素匹配透明色时跳过（不复制到 dst）
// ════════════════════════════════════════════════════════════

/**
 * imagecopy(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_width, int $src_height): bool
 *
 * 区域复制（直接覆盖，不混合）。
 * - 将 src 中 (src_x, src_y, src_width, src_height) 区域复制到 dst 的 (dst_x, dst_y)
 * - 源像素匹配 src 透明色时跳过
 * - 越界自动裁剪（gd_set_pixel_blend 处理边界）
 * - 真彩色 dst：直接写入 0x7FRRGGBB 颜色值
 * - 调色板 dst：通过 imagecolorresolvealpha 解析为调色板索引
 */
function imagecopy(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_width, int $src_height): bool
{
    if ($src_width <= 0 || $src_height <= 0) { return false; }

    int $srcTrans = $src_image->transparentColor;
    int $y = 0;
    while ($y < $src_height) {
        int $sy = $src_y + $y;
        if ($sy >= 0 && $sy < $src_image->height) {
            int $x = 0;
            while ($x < $src_width) {
                int $sx = $src_x + $x;
                if ($sx >= 0 && $sx < $src_image->width) {
                    // 透明色检测
                    int $isTransparent = 0;
                    if ($srcTrans != -1) {
                        if ($src_image->trueColor) {
                            // 真彩色：比较颜色值
                            int $pc = gd_pixel_color($src_image, $sx, $sy);
                            if ($pc == $srcTrans) { $isTransparent = 1; }
                        } else {
                            // 调色板：比较索引
                            int $rawIdx = gd_get_pixel_raw($src_image, $sx, $sy);
                            if ($rawIdx == $srcTrans) { $isTransparent = 1; }
                        }
                    }
                    if (!$isTransparent) {
                        int $color = gd_pixel_color($src_image, $sx, $sy);
                        int $dx = $dst_x + $x;
                        int $dy = $dst_y + $y;
                        if ($dst_image->trueColor) {
                            gd_set_pixel_blend($dst_image, $dx, $dy, $color);
                        } else {
                            // 调色板 dst：解析为调色板索引
                            int $r = gd_get_red($color);
                            int $g = gd_get_green($color);
                            int $b = gd_get_blue($color);
                            int $a = gd_get_alpha($color);
                            int $idx = imagecolorresolvealpha($dst_image, $r, $g, $b, $a);
                            gd_set_pixel_blend($dst_image, $dx, $dy, $idx);
                        }
                    }
                }
                $x = $x + 1;
            }
        }
        $y = $y + 1;
    }
    return true;
}

/**
 * imagecopymerge(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_width, int $src_height, int $pct): bool
 *
 * 按百分比混合（不处理 alpha 通道，与 libgd gdImageCopyMerge 一致）。
 * - 公式：out = src * (pct/100) + dst * ((100-pct)/100)
 * - pct=100 时等价于 imagecopy（但保留 dst alpha）
 * - pct 范围自动钳位到 [0, 100]
 * - 源像素匹配 src 透明色时跳过
 * - 输出 alpha 始终为 dst 原 alpha（libgd 行为）
 */
function imagecopymerge(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_width, int $src_height, int $pct): bool
{
    if ($src_width <= 0 || $src_height <= 0) { return false; }
    int $p = $pct;
    if ($p < 0) { $p = 0; } elseif ($p > 100) { $p = 100; }
    if ($p == 0) { return true; }

    int $srcTrans = $src_image->transparentColor;
    float $srcW = (float)$p / 100.0;
    float $dstW = (float)(100 - $p) / 100.0;

    int $y = 0;
    while ($y < $src_height) {
        int $sy = $src_y + $y;
        int $dy = $dst_y + $y;
        if ($sy >= 0 && $sy < $src_image->height && $dy >= 0 && $dy < $dst_image->height) {
            int $x = 0;
            while ($x < $src_width) {
                int $sx = $src_x + $x;
                int $dx = $dst_x + $x;
                if ($sx >= 0 && $sx < $src_image->width && $dx >= 0 && $dx < $dst_image->width) {
                    // 透明色检测
                    int $isTransparent = 0;
                    if ($srcTrans != -1) {
                        if ($src_image->trueColor) {
                            int $pc = gd_pixel_color($src_image, $sx, $sy);
                            if ($pc == $srcTrans) { $isTransparent = 1; }
                        } else {
                            int $rawIdx = gd_get_pixel_raw($src_image, $sx, $sy);
                            if ($rawIdx == $srcTrans) { $isTransparent = 1; }
                        }
                    }
                    if (!$isTransparent) {
                        int $srcColor = gd_pixel_color($src_image, $sx, $sy);
                        int $dstColor = gd_pixel_color($dst_image, $dx, $dy);
                        int $sr = gd_get_red($srcColor);
                        int $sg = gd_get_green($srcColor);
                        int $sb = gd_get_blue($srcColor);
                        int $dr = gd_get_red($dstColor);
                        int $dg = gd_get_green($dstColor);
                        int $db = gd_get_blue($dstColor);
                        // dst alpha 保留（libgd 不处理 alpha 通道）
                        int $da = gd_get_alpha($dstColor);
                        int $outR = intval((float)$sr * $srcW + (float)$dr * $dstW);
                        int $outG = intval((float)$sg * $srcW + (float)$dg * $dstW);
                        int $outB = intval((float)$sb * $srcW + (float)$db * $dstW);
                        int $outColor = gd_make_color($outR, $outG, $outB, $da);
                        if ($dst_image->trueColor) {
                            $dst_image->pixels[$dy * $dst_image->width + $dx] = $outColor;
                        } else {
                            int $idx = imagecolorresolvealpha($dst_image, $outR, $outG, $outB, $da);
                            $dst_image->pixels[$dy * $dst_image->width + $dx] = $idx;
                        }
                    }
                }
                $x = $x + 1;
            }
        }
        $y = $y + 1;
    }
    return true;
}

/**
 * imagecopymergegray(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_width, int $src_height, int $pct): bool
 *
 * 按百分比混合（dst 转灰度后参与混合，与 libgd gdImageCopyMergeGray 一致）。
 * - 灰度公式：g = 0.299*R + 0.587*G + 0.114*B（NTSC/YIQ 亮度）
 * - 公式：out = src * (pct/100) + g * ((100-pct)/100)
 * - pct=100 时等价于 imagecopy（灰度分量权重为 0）
 * - 源像素匹配 src 透明色时跳过
 * - 输出 alpha 始终为 dst 原 alpha
 */
function imagecopymergegray(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_width, int $src_height, int $pct): bool
{
    if ($src_width <= 0 || $src_height <= 0) { return false; }
    int $p = $pct;
    if ($p < 0) { $p = 0; } elseif ($p > 100) { $p = 100; }
    if ($p == 0) { return true; }

    int $srcTrans = $src_image->transparentColor;
    float $srcW = (float)$p / 100.0;
    float $dstW = (float)(100 - $p) / 100.0;

    int $y = 0;
    while ($y < $src_height) {
        int $sy = $src_y + $y;
        int $dy = $dst_y + $y;
        if ($sy >= 0 && $sy < $src_image->height && $dy >= 0 && $dy < $dst_image->height) {
            int $x = 0;
            while ($x < $src_width) {
                int $sx = $src_x + $x;
                int $dx = $dst_x + $x;
                if ($sx >= 0 && $sx < $src_image->width && $dx >= 0 && $dx < $dst_image->width) {
                    // 透明色检测
                    int $isTransparent = 0;
                    if ($srcTrans != -1) {
                        if ($src_image->trueColor) {
                            int $pc = gd_pixel_color($src_image, $sx, $sy);
                            if ($pc == $srcTrans) { $isTransparent = 1; }
                        } else {
                            int $rawIdx = gd_get_pixel_raw($src_image, $sx, $sy);
                            if ($rawIdx == $srcTrans) { $isTransparent = 1; }
                        }
                    }
                    if (!$isTransparent) {
                        int $srcColor = gd_pixel_color($src_image, $sx, $sy);
                        int $dstColor = gd_pixel_color($dst_image, $dx, $dy);
                        int $sr = gd_get_red($srcColor);
                        int $sg = gd_get_green($srcColor);
                        int $sb = gd_get_blue($srcColor);
                        int $dr = gd_get_red($dstColor);
                        int $dg = gd_get_green($dstColor);
                        int $db = gd_get_blue($dstColor);
                        // dst 转灰度
                        float $gray = 0.299 * (float)$dr + 0.587 * (float)$dg + 0.114 * (float)$db;
                        int $da = gd_get_alpha($dstColor);
                        int $outR = intval((float)$sr * $srcW + $gray * $dstW);
                        int $outG = intval((float)$sg * $srcW + $gray * $dstW);
                        int $outB = intval((float)$sb * $srcW + $gray * $dstW);
                        int $outColor = gd_make_color($outR, $outG, $outB, $da);
                        if ($dst_image->trueColor) {
                            $dst_image->pixels[$dy * $dst_image->width + $dx] = $outColor;
                        } else {
                            int $idx = imagecolorresolvealpha($dst_image, $outR, $outG, $outB, $da);
                            $dst_image->pixels[$dy * $dst_image->width + $dx] = $idx;
                        }
                    }
                }
                $x = $x + 1;
            }
        }
        $y = $y + 1;
    }
    return true;
}

/**
 * imagecopyresized(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $dst_width, int $dst_height, int $src_width, int $src_height): bool
 *
 * 最近邻缩放复制（与 libgd gdImageCopyResized 一致）。
 * - 使用 stretch vectors（stx/sty）实现最近邻映射
 * - stx[i] = dstW * (i+1) / srcW - dstW * i / srcW
 * - 源像素匹配 src 透明色时跳过对应 dst 区域
 * - 真彩色 dst：直接写入颜色值
 * - 调色板 dst：通过 imagecolorresolvealpha 解析为索引
 */
function imagecopyresized(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $dst_width, int $dst_height, int $src_width, int $src_height): bool
{
    if ($src_width <= 0 || $src_height <= 0 || $dst_width <= 0 || $dst_height <= 0) { return false; }

    int $srcTrans = $src_image->transparentColor;

    // 构建 stretch vectors（libgd 算法）
    int $i = 0;
    array $stx = [];
    array $sty = [];
    while ($i < $src_width) {
        $stx[$i] = intval($dst_width * ($i + 1) / $src_width) - intval($dst_width * $i / $src_width);
        $i = $i + 1;
    }
    $i = 0;
    while ($i < $src_height) {
        $sty[$i] = intval($dst_height * ($i + 1) / $src_height) - intval($dst_height * $i / $src_height);
        $i = $i + 1;
    }

    int $toy = $dst_y;
    int $sy = $src_y;
    int $yy = 0;
    while ($yy < $src_height) {
        int $ydest = 0;
        int $styVal = intval($sty[$yy]);
        while ($ydest < $styVal) {
            int $tox = $dst_x;
            int $sx = $src_x;
            int $xx = 0;
            while ($xx < $src_width) {
                int $stxVal = intval($stx[$xx]);
                if ($stxVal > 0) {
                    // 源像素有效且在边界内
                    if ($sx >= 0 && $sx < $src_image->width && $sy >= 0 && $sy < $src_image->height) {
                        // 透明色检测
                        int $isTransparent = 0;
                        if ($srcTrans != -1) {
                            if ($src_image->trueColor) {
                                int $pc = gd_pixel_color($src_image, $sx, $sy);
                                if ($pc == $srcTrans) { $isTransparent = 1; }
                            } else {
                                int $rawIdx = gd_get_pixel_raw($src_image, $sx, $sy);
                                if ($rawIdx == $srcTrans) { $isTransparent = 1; }
                            }
                        }
                        if (!$isTransparent) {
                            int $color = gd_pixel_color($src_image, $sx, $sy);
                            // 写入 stxVal 个像素
                            int $k = 0;
                            while ($k < $stxVal) {
                                if ($tox >= 0 && $tox < $dst_image->width && $toy >= 0 && $toy < $dst_image->height) {
                                    if ($dst_image->trueColor) {
                                        $dst_image->pixels[$toy * $dst_image->width + $tox] = $color;
                                    } else {
                                        int $r = gd_get_red($color);
                                        int $g = gd_get_green($color);
                                        int $b = gd_get_blue($color);
                                        int $a = gd_get_alpha($color);
                                        int $idx = imagecolorresolvealpha($dst_image, $r, $g, $b, $a);
                                        $dst_image->pixels[$toy * $dst_image->width + $tox] = $idx;
                                    }
                                }
                                $tox = $tox + 1;
                                $k = $k + 1;
                            }
                        } else {
                            $tox = $tox + $stxVal;
                        }
                    } else {
                        $tox = $tox + $stxVal;
                    }
                }
                $sx = $sx + 1;
                $xx = $xx + 1;
            }
            $toy = $toy + 1;
            $ydest = $ydest + 1;
        }
        $sy = $sy + 1;
        $yy = $yy + 1;
    }
    return true;
}

/**
 * imagecopyresampled(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $dst_width, int $dst_height, int $src_width, int $src_height): bool
 *
 * 双线性插值缩放复制（与 libgd gdImageCopyResampled 行为一致）。
 * - 调色板 dst：回退到 imagecopyresized（libgd 行为）
 * - 真彩色 dst：对每个 dst 像素反向映射到 src，使用双线性插值
 * - 插值公式与 imagescale 一致：4 邻域加权平均
 * - 源像素越界时用边界像素钳位
 */
function imagecopyresampled(GdImage $dst_image, GdImage $src_image, int $dst_x, int $dst_y, int $src_x, int $src_y, int $dst_width, int $dst_height, int $src_width, int $src_height): bool
{
    // 调色板 dst 回退到最近邻
    if (!$dst_image->trueColor) {
        return imagecopyresized($dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_width, $dst_height, $src_width, $src_height);
    }

    if ($src_width <= 0 || $src_height <= 0 || $dst_width <= 0 || $dst_height <= 0) { return false; }

    float $sxRatio = (float)$src_width / (float)$dst_width;
    float $syRatio = (float)$src_height / (float)$dst_height;

    int $dy = 0;
    while ($dy < $dst_height) {
        int $dx = 0;
        while ($dx < $dst_width) {
            // 反向映射到 src 坐标
            float $sx = (float)$src_x + (float)$dx * $sxRatio;
            float $sy = (float)$src_y + (float)$dy * $syRatio;
            int $ix = intval($sx);
            int $iy = intval($sy);
            // 钳位到 src 边界
            if ($ix < 0) { $ix = 0; }
            if ($iy < 0) { $iy = 0; }
            if ($ix >= $src_image->width) { $ix = $src_image->width - 1; }
            if ($iy >= $src_image->height) { $iy = $src_image->height - 1; }

            int $ix1 = $ix + 1;
            int $iy1 = $iy + 1;
            if ($ix1 >= $src_image->width) { $ix1 = $src_image->width - 1; }
            if ($iy1 >= $src_image->height) { $iy1 = $src_image->height - 1; }

            float $fx = $sx - (float)$ix;
            float $fy = $sy - (float)$iy;
            if ($fx < 0.0) { $fx = 0.0; }
            if ($fy < 0.0) { $fy = 0.0; }

            // 4 邻域像素
            int $p00 = gd_pixel_color($src_image, $ix, $iy);
            int $p10 = gd_pixel_color($src_image, $ix1, $iy);
            int $p01 = gd_pixel_color($src_image, $ix, $iy1);
            int $p11 = gd_pixel_color($src_image, $ix1, $iy1);

            int $r00 = gd_get_red($p00);
            int $g00 = gd_get_green($p00);
            int $b00 = gd_get_blue($p00);
            int $a00 = gd_get_alpha($p00);
            int $r10 = gd_get_red($p10);
            int $g10 = gd_get_green($p10);
            int $b10 = gd_get_blue($p10);
            int $a10 = gd_get_alpha($p10);
            int $r01 = gd_get_red($p01);
            int $g01 = gd_get_green($p01);
            int $b01 = gd_get_blue($p01);
            int $a01 = gd_get_alpha($p01);
            int $r11 = gd_get_red($p11);
            int $g11 = gd_get_green($p11);
            int $b11 = gd_get_blue($p11);
            int $a11 = gd_get_alpha($p11);

            float $w00 = (1.0 - $fx) * (1.0 - $fy);
            float $w10 = $fx * (1.0 - $fy);
            float $w01 = (1.0 - $fx) * $fy;
            float $w11 = $fx * $fy;

            int $r = intval((float)$r00 * $w00 + (float)$r10 * $w10 + (float)$r01 * $w01 + (float)$r11 * $w11 + 0.5);
            int $g = intval((float)$g00 * $w00 + (float)$g10 * $w10 + (float)$g01 * $w01 + (float)$g11 * $w11 + 0.5);
            int $b = intval((float)$b00 * $w00 + (float)$b10 * $w10 + (float)$b01 * $w01 + (float)$b11 * $w11 + 0.5);
            int $a = intval((float)$a00 * $w00 + (float)$a10 * $w10 + (float)$a01 * $w01 + (float)$a11 * $w11 + 0.5);

            int $outColor = gd_make_color($r, $g, $b, $a);

            int $tx = $dst_x + $dx;
            int $ty = $dst_y + $dy;
            if ($tx >= 0 && $tx < $dst_image->width && $ty >= 0 && $ty < $dst_image->height) {
                $dst_image->pixels[$ty * $dst_image->width + $tx] = $outColor;
            }
            $dx = $dx + 1;
        }
        $dy = $dy + 1;
    }
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 10: 滤镜与卷积
//
// 实现函数：
//   - imagefilter       支持 IMG_FILTER_* 全部滤镜（13 种）
//   - imageconvolution  3x3 卷积
//   - imagegammacorrect 伽马校正
//   - imageantialias    抗锯齿标志（仅存储，无实际效果）
//
// 设计说明：
//   - 卷积辅助函数 gd_convolve 处理 3x3 卷积，边缘镜像处理
//   - imagefilter 的卷积类滤镜（EDGEDETECT/GAUSSIAN_BLUR/SELECTIVE_BLUR/
//     EMBOSS/MEAN_REMOVAL/SMOOTH）复用 gd_convolve
//   - 调色板图像返回 false（与 PHP 8.5 行为一致）
//   - 临时缓冲区：卷积先复制原图像素，避免覆盖
//   - pow() 使用 TinyPHP 内置函数（内部走 libc pow）
// ════════════════════════════════════════════════════════════

// gd_convolve — 3x3 卷积（内部辅助函数）
//   $matrix: [[m00,m01,m02],[m10,m11,m12],[m20,m21,m22]]
//   $div: 除数（0 视为 1）
//   $offset: 偏移量
//   边缘镜像处理：x<0 → -x, x>=w → 2w-x-1
function gd_convolve(GdImage $image, array $matrix, float $div, float $offset): void
{
    int $w = $image->width;
    int $h = $image->height;
    int $total = $w * $h;

    // 备份原图像素（避免卷积时覆盖）
    array $backup = array_fill(0, $total, 0);
    int $by = 0;
    while ($by < $h) {
        int $bx = 0;
        while ($bx < $w) {
            $backup[$by * $w + $bx] = gd_get_pixel_raw($image, $bx, $by);
            $bx = $bx + 1;
        }
        $by = $by + 1;
    }

    // 提取矩阵值（使用 floatval 避免数组元素类型问题）
    $row0 = $matrix[0];
    $row1 = $matrix[1];
    $row2 = $matrix[2];
    float $m00 = floatval($row0[0]);
    float $m01 = floatval($row0[1]);
    float $m02 = floatval($row0[2]);
    float $m10 = floatval($row1[0]);
    float $m11 = floatval($row1[1]);
    float $m12 = floatval($row1[2]);
    float $m20 = floatval($row2[0]);
    float $m21 = floatval($row2[1]);
    float $m22 = floatval($row2[2]);

    if ($div == 0.0) { $div = 1.0; }

    int $cy = 0;
    while ($cy < $h) {
        int $cx = 0;
        while ($cx < $w) {
            // 镜像坐标
            int $xm = $cx - 1;
            int $xp = $cx + 1;
            int $ym = $cy - 1;
            int $yp = $cy + 1;
            if ($xm < 0) { $xm = 0 - $xm; }
            if ($xp >= $w) { $xp = 2 * $w - $xp - 1; }
            if ($ym < 0) { $ym = 0 - $ym; }
            if ($yp >= $h) { $yp = 2 * $h - $yp - 1; }

            // 获取 9 个邻居像素（从 backup，使用 intval 避免类型问题）
            int $p00 = intval($backup[$ym * $w + $xm]);
            int $p01 = intval($backup[$ym * $w + $cx]);
            int $p02 = intval($backup[$ym * $w + $xp]);
            int $p10 = intval($backup[$cy * $w + $xm]);
            int $p11 = intval($backup[$cy * $w + $cx]);
            int $p12 = intval($backup[$cy * $w + $xp]);
            int $p20 = intval($backup[$yp * $w + $xm]);
            int $p21 = intval($backup[$yp * $w + $cx]);
            int $p22 = intval($backup[$yp * $w + $xp]);

            // 提取 RGB 分量
            int $r00 = gd_get_red($p00);
            int $r01 = gd_get_red($p01);
            int $r02 = gd_get_red($p02);
            int $r10 = gd_get_red($p10);
            int $r11 = gd_get_red($p11);
            int $r12 = gd_get_red($p12);
            int $r20 = gd_get_red($p20);
            int $r21 = gd_get_red($p21);
            int $r22 = gd_get_red($p22);

            int $g00 = gd_get_green($p00);
            int $g01 = gd_get_green($p01);
            int $g02 = gd_get_green($p02);
            int $g10 = gd_get_green($p10);
            int $g11 = gd_get_green($p11);
            int $g12 = gd_get_green($p12);
            int $g20 = gd_get_green($p20);
            int $g21 = gd_get_green($p21);
            int $g22 = gd_get_green($p22);

            int $b00 = gd_get_blue($p00);
            int $b01 = gd_get_blue($p01);
            int $b02 = gd_get_blue($p02);
            int $b10 = gd_get_blue($p10);
            int $b11 = gd_get_blue($p11);
            int $b12 = gd_get_blue($p12);
            int $b20 = gd_get_blue($p20);
            int $b21 = gd_get_blue($p21);
            int $b22 = gd_get_blue($p22);

            // 卷积计算（floatval 确保浮点运算）
            float $sumR = $m00 * floatval($r00) + $m01 * floatval($r01) + $m02 * floatval($r02)
                        + $m10 * floatval($r10) + $m11 * floatval($r11) + $m12 * floatval($r12)
                        + $m20 * floatval($r20) + $m21 * floatval($r21) + $m22 * floatval($r22);
            float $sumG = $m00 * floatval($g00) + $m01 * floatval($g01) + $m02 * floatval($g02)
                        + $m10 * floatval($g10) + $m11 * floatval($g11) + $m12 * floatval($g12)
                        + $m20 * floatval($g20) + $m21 * floatval($g21) + $m22 * floatval($g22);
            float $sumB = $m00 * floatval($b00) + $m01 * floatval($b01) + $m02 * floatval($b02)
                        + $m10 * floatval($b10) + $m11 * floatval($b11) + $m12 * floatval($b12)
                        + $m20 * floatval($b20) + $m21 * floatval($b21) + $m22 * floatval($b22);

            float $outR = $sumR / $div + $offset;
            float $outG = $sumG / $div + $offset;
            float $outB = $sumB / $div + $offset;

            int $ir = intval($outR + ($outR >= 0.0 ? 0.5 : -0.5));
            int $ig = intval($outG + ($outG >= 0.0 ? 0.5 : -0.5));
            int $ib = intval($outB + ($outB >= 0.0 ? 0.5 : -0.5));

            // 保持原 alpha
            int $origAlpha = gd_get_alpha($p11);
            int $newColor = gd_make_color(gd_clamp($ir, 0, 255), gd_clamp($ig, 0, 255), gd_clamp($ib, 0, 255), $origAlpha);
            gd_set_pixel_raw($image, $cx, $cy, $newColor);

            $cx = $cx + 1;
        }
        $cy = $cy + 1;
    }
}

/**
 * imagefilter(GdImage $image, int $filter, int $arg1 = 0, int $arg2 = 0, int $arg3 = 0, int $arg4 = 0): bool
 *
 * 应用图像滤镜（原地修改）。
 * - 调色板图像返回 false
 * - 支持全部 IMG_FILTER_* 常量（13 种）
 */
function imagefilter(GdImage $image, int $filter, int $arg1 = 0, int $arg2 = 0, int $arg3 = 0, int $arg4 = 0): bool
{
    // 调色板图像不支持滤镜
    if (!$image->trueColor) { return false; }

    int $w = $image->width;
    int $h = $image->height;

    // ── IMG_FILTER_NEGATE: 反色 r=255-r ──
    if ($filter == IMG_FILTER_NEGATE) {
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                int $c = gd_get_pixel_raw($image, $x, $y);
                int $r = gd_get_red($c);
                int $g = gd_get_green($c);
                int $b = gd_get_blue($c);
                int $a = gd_get_alpha($c);
                gd_set_pixel_raw($image, $x, $y, gd_make_color(255 - $r, 255 - $g, 255 - $b, $a));
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    // ── IMG_FILTER_GRAYSCALE: 灰度 gray = r*0.299 + g*0.587 + b*0.114 ──
    if ($filter == IMG_FILTER_GRAYSCALE) {
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                int $c = gd_get_pixel_raw($image, $x, $y);
                int $r = gd_get_red($c);
                int $g = gd_get_green($c);
                int $b = gd_get_blue($c);
                int $a = gd_get_alpha($c);
                float $gray = 0.299 * floatval($r) + 0.587 * floatval($g) + 0.114 * floatval($b);
                int $grayI = intval($gray);
                gd_set_pixel_raw($image, $x, $y, gd_make_color($grayI, $grayI, $grayI, $a));
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    // ── IMG_FILTER_BRIGHTNESS: 亮度 r += arg1 ──
    if ($filter == IMG_FILTER_BRIGHTNESS) {
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                int $c = gd_get_pixel_raw($image, $x, $y);
                int $r = gd_get_red($c);
                int $g = gd_get_green($c);
                int $b = gd_get_blue($c);
                int $a = gd_get_alpha($c);
                gd_set_pixel_raw($image, $x, $y,
                    gd_make_color(gd_clamp($r + $arg1, 0, 255), gd_clamp($g + $arg1, 0, 255), gd_clamp($b + $arg1, 0, 255), $a));
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    // ── IMG_FILTER_CONTRAST: 对比度 r = (r-128)*arg1/100 + 128 ──
    if ($filter == IMG_FILTER_CONTRAST) {
        float $contrast = floatval($arg1);
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                int $c = gd_get_pixel_raw($image, $x, $y);
                int $r = gd_get_red($c);
                int $g = gd_get_green($c);
                int $b = gd_get_blue($c);
                int $a = gd_get_alpha($c);
                float $fr = (floatval($r) - 128.0) * $contrast / 100.0 + 128.0;
                float $fg = (floatval($g) - 128.0) * $contrast / 100.0 + 128.0;
                float $fb = (floatval($b) - 128.0) * $contrast / 100.0 + 128.0;
                int $ir = intval($fr + ($fr >= 0.0 ? 0.5 : -0.5));
                int $ig = intval($fg + ($fg >= 0.0 ? 0.5 : -0.5));
                int $ib = intval($fb + ($fb >= 0.0 ? 0.5 : -0.5));
                gd_set_pixel_raw($image, $x, $y, gd_make_color(gd_clamp($ir, 0, 255), gd_clamp($ig, 0, 255), gd_clamp($ib, 0, 255), $a));
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    // ── IMG_FILTER_COLORIZE: 着色 r += arg1, g += arg2, b += arg3, alpha = arg4 ──
    if ($filter == IMG_FILTER_COLORIZE) {
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                int $c = gd_get_pixel_raw($image, $x, $y);
                int $r = gd_get_red($c);
                int $g = gd_get_green($c);
                int $b = gd_get_blue($c);
                gd_set_pixel_raw($image, $x, $y,
                    gd_make_color(gd_clamp($r + $arg1, 0, 255), gd_clamp($g + $arg2, 0, 255), gd_clamp($b + $arg3, 0, 255), gd_clamp($arg4, 0, 127)));
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    // ── IMG_FILTER_EDGEDETECT: 边缘检测卷积 ──
    if ($filter == IMG_FILTER_EDGEDETECT) {
        $matrix = [[1, 0, -1], [0, 0, 0], [-1, 0, 1]];
        gd_convolve($image, $matrix, 1.0, 0.0);
        return true;
    }

    // ── IMG_FILTER_GAUSSIAN_BLUR: 3x3 高斯核 ──
    if ($filter == IMG_FILTER_GAUSSIAN_BLUR) {
        $matrix = [[1, 2, 1], [2, 4, 2], [1, 2, 1]];
        gd_convolve($image, $matrix, 16.0, 0.0);
        return true;
    }

    // ── IMG_FILTER_SELECTIVE_BLUR: 简单模糊（均值）──
    if ($filter == IMG_FILTER_SELECTIVE_BLUR) {
        $matrix = [[1, 1, 1], [1, 1, 1], [1, 1, 1]];
        gd_convolve($image, $matrix, 9.0, 0.0);
        return true;
    }

    // ── IMG_FILTER_EMBOSS: 浮雕卷积核 [-1,-1,0,-1,1,1,0,1,1] ──
    if ($filter == IMG_FILTER_EMBOSS) {
        $matrix = [[-1, -1, 0], [-1, 1, 1], [0, 1, 1]];
        gd_convolve($image, $matrix, 1.0, 0.0);
        return true;
    }

    // ── IMG_FILTER_MEAN_REMOVAL: 卷积核 [-1,-1,-1,-1,9,-1,-1,-1,-1] ──
    if ($filter == IMG_FILTER_MEAN_REMOVAL) {
        $matrix = [[-1, -1, -1], [-1, 9, -1], [-1, -1, -1]];
        gd_convolve($image, $matrix, 1.0, 0.0);
        return true;
    }

    // ── IMG_FILTER_SMOOTH: arg1 为平滑度 ──
    //   matrix = [[1,2,1],[2,weight,2],[1,2,1]], div = weight+12
    if ($filter == IMG_FILTER_SMOOTH) {
        float $weight = floatval($arg1);
        float $div = $weight + 12.0;
        $matrix = [[1, 2, 1], [2, $arg1, 2], [1, 2, 1]];
        gd_convolve($image, $matrix, $div, 0.0);
        return true;
    }

    // ── IMG_FILTER_PIXELATE: arg1=块大小, arg2=高级模式 ──
    if ($filter == IMG_FILTER_PIXELATE) {
        int $blockSize = $arg1;
        if ($blockSize <= 0) { return false; }
        int $advanced = $arg2;

        // 备份原图
        int $total = $w * $h;
        array $backup = array_fill(0, $total, 0);
        int $by = 0;
        while ($by < $h) {
            int $bx = 0;
            while ($bx < $w) {
                $backup[$by * $w + $bx] = gd_get_pixel_raw($image, $bx, $by);
                $bx = $bx + 1;
            }
            $by = $by + 1;
        }

        int $blockY = 0;
        while ($blockY < $h) {
            int $blockX = 0;
            while ($blockX < $w) {
                int $blockColor = 0;
                if ($advanced != 0) {
                    // 高级模式：块内平均色
                    int $sumR = 0;
                    int $sumG = 0;
                    int $sumB = 0;
                    int $cnt = 0;
                    int $dy = 0;
                    while ($dy < $blockSize && $blockY + $dy < $h) {
                        int $dx = 0;
                        while ($dx < $blockSize && $blockX + $dx < $w) {
                            int $pc = intval($backup[($blockY + $dy) * $w + ($blockX + $dx)]);
                            $sumR = $sumR + gd_get_red($pc);
                            $sumG = $sumG + gd_get_green($pc);
                            $sumB = $sumB + gd_get_blue($pc);
                            $cnt = $cnt + 1;
                            $dx = $dx + 1;
                        }
                        $dy = $dy + 1;
                    }
                    if ($cnt > 0) {
                        int $avgR = intval(floatval($sumR) / floatval($cnt));
                        int $avgG = intval(floatval($sumG) / floatval($cnt));
                        int $avgB = intval(floatval($sumB) / floatval($cnt));
                        int $firstC = intval($backup[$blockY * $w + $blockX]);
                        int $alpha = gd_get_alpha($firstC);
                        $blockColor = gd_make_color($avgR, $avgG, $avgB, $alpha);
                    }
                } else {
                    // 基本模式：左上角像素色
                    $blockColor = intval($backup[$blockY * $w + $blockX]);
                }

                // 填充块
                int $dy = 0;
                while ($dy < $blockSize && $blockY + $dy < $h) {
                    int $dx = 0;
                    while ($dx < $blockSize && $blockX + $dx < $w) {
                        gd_set_pixel_raw($image, $blockX + $dx, $blockY + $dy, $blockColor);
                        $dx = $dx + 1;
                    }
                    $dy = $dy + 1;
                }
                $blockX = $blockX + $blockSize;
            }
            $blockY = $blockY + $blockSize;
        }
        return true;
    }

    // ── IMG_FILTER_SCATTER: arg1/arg2=随机偏移范围 ──
    if ($filter == IMG_FILTER_SCATTER) {
        int $sub = $arg1;
        int $plus = $arg2;
        if ($sub < 0) { $sub = 0 - $sub; }
        if ($plus <= 0) { $plus = $sub; }

        // 备份原图
        int $total = $w * $h;
        array $backup = array_fill(0, $total, 0);
        int $by = 0;
        while ($by < $h) {
            int $bx = 0;
            while ($bx < $w) {
                $backup[$by * $w + $bx] = gd_get_pixel_raw($image, $bx, $by);
                $bx = $bx + 1;
            }
            $by = $by + 1;
        }

        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                int $srcX = $x;
                int $srcY = $y;
                if ($sub > 0 || $plus > 0) {
                    $srcX = $x + rand(0 - $sub, $plus);
                    $srcY = $y + rand(0 - $sub, $plus);
                }
                if ($srcX < 0) { $srcX = 0; }
                if ($srcX >= $w) { $srcX = $w - 1; }
                if ($srcY < 0) { $srcY = 0; }
                if ($srcY >= $h) { $srcY = $h - 1; }
                int $srcColor = intval($backup[$srcY * $w + $srcX]);
                gd_set_pixel_raw($image, $x, $y, $srcColor);
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        return true;
    }

    // 未知滤镜
    return false;
}

/**
 * imageconvolution(GdImage $image, array $matrix, float $div, float $offset): bool
 *
 * 3x3 卷积。
 * - matrix 为 [[m00,m01,m02],[m10,m11,m12],[m20,m21,m22]]
 * - out = (sum(m*p) / div) + offset
 * - 边缘镜像处理
 * - 调色板图像返回 false
 */
function imageconvolution(GdImage $image, array $matrix, float $div, float $offset): bool
{
    if (!$image->trueColor) { return false; }
    gd_convolve($image, $matrix, $div, $offset);
    return true;
}

/**
 * imagegammacorrect(GdImage $image, float $input_gamma, float $output_gamma): bool
 *
 * 伽马校正。
 * - out = pow(v/255, input_gamma/output_gamma) * 255
 * - 真彩色：逐像素调整 RGB
 * - 调色板：调整调色板颜色
 * - input/output_gamma <= 0 返回 false
 */
function imagegammacorrect(GdImage $image, float $input_gamma, float $output_gamma): bool
{
    if ($input_gamma <= 0.0 || $output_gamma <= 0.0) { return false; }

    float $ratio = $input_gamma / $output_gamma;

    if ($image->trueColor) {
        int $w = $image->width;
        int $h = $image->height;
        int $total = $w * $h;
        int $i = 0;
        while ($i < $total) {
            int $c = intval($image->pixels[$i]);
            int $r = gd_get_red($c);
            int $g = gd_get_green($c);
            int $b = gd_get_blue($c);
            int $a = gd_get_alpha($c);
            float $fr = floatval($r) / 255.0;
            float $fg = floatval($g) / 255.0;
            float $fb = floatval($b) / 255.0;
            // pow() 返回 mixed，用 floatval 确保得到 float
            float $nr = floatval(pow($fr, $ratio)) * 255.0;
            float $ng = floatval(pow($fg, $ratio)) * 255.0;
            float $nb = floatval(pow($fb, $ratio)) * 255.0;
            int $ir = intval($nr + 0.5);
            int $ig = intval($ng + 0.5);
            int $ib = intval($nb + 0.5);
            $image->pixels[$i] = gd_make_color($ir, $ig, $ib, $a);
            $i = $i + 1;
        }
    } else {
        // 调色板：调整调色板颜色
        int $numColors = count($image->palette);
        int $i = 0;
        while ($i < $numColors) {
            int $c = intval($image->palette[$i]);
            if ($c == -1) { $i = $i + 1; continue; }
            int $r = gd_get_red($c);
            int $g = gd_get_green($c);
            int $b = gd_get_blue($c);
            int $a = gd_get_alpha($c);
            float $fr = floatval($r) / 255.0;
            float $fg = floatval($g) / 255.0;
            float $fb = floatval($b) / 255.0;
            float $nr = floatval(pow($fr, $ratio)) * 255.0;
            float $ng = floatval(pow($fg, $ratio)) * 255.0;
            float $nb = floatval(pow($fb, $ratio)) * 255.0;
            int $ir = intval($nr + 0.5);
            int $ig = intval($ng + 0.5);
            int $ib = intval($nb + 0.5);
            $image->palette[$i] = gd_make_color($ir, $ig, $ib, $a);
            $i = $i + 1;
        }
    }
    return true;
}

/**
 * imageantialias(GdImage $image, bool $enable): bool
 *
 * 设置抗锯齿标志（仅存储，无实际效果）。
 * - 纯 phpc 实现不进行实际的抗锯齿渲染
 * - 存储标志位以保持 API 兼容性
 */
function imageantialias(GdImage $image, bool $enable): bool
{
    // 存储标志位（GdImage 无独立 antialias 属性，映射到 alphaBlending 作为近似）
    // 实际无抗锯齿效果，仅保持 API 兼容
    $image->alphaBlending = $enable;
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 12: BMP 编解码
//
// 实现说明：
//   - imagecreatefrombmp: 解码 BMP 文件，支持 24/32bpp BI_RGB、
//     8/4/1bpp BI_RGB（调色板）、8bpp BI_RLE8、4bpp BI_RLE4
//   - imagebmp: 编码 BMP 文件，简化实现 24bpp BI_RGB bottom-up
//     （$compressed 参数接受但忽略，始终写 BI_RGB）
//
// 文件 I/O 模式（参考 exif.php）：
//   - C->fopen + C->fgetc 逐字节读取 + phpc_ptr_to_int/phpc_int_to_ptr
//   - defer C->fclose 确保所有退出路径关闭文件
//   - 二进制数据构造：chr() + 字符串拼接 + C->fwrite
// ════════════════════════════════════════════════════════════

// BMP 压缩类型常量
const GD_BMP_BI_RGB = 0;              // BI_RGB 无压缩
const GD_BMP_BI_RLE8 = 1;             // BI_RLE8 8bpp RLE 压缩
const GD_BMP_BI_RLE4 = 2;             // BI_RLE4 4bpp RLE 压缩

// gd_bmp_u16le — LE 16 位编码 → 2 字节字符串
function gd_bmp_u16le(int $v): string
{
    return chr($v & 0xFF) . chr(($v >> 8) & 0xFF);
}

// gd_bmp_u32le — LE 32 位编码 → 4 字节字符串
function gd_bmp_u32le(int $v): string
{
    return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 24) & 0xFF);
}

/**
 * imagecreatefrombmp(string $filename): GdImage
 *
 * 从 BMP 文件创建图像（真彩色）。
 *
 * 支持的格式：
 *   - 24bpp BI_RGB（BGR 顺序，每行 4 字节对齐）
 *   - 32bpp BI_RGB（BGRA 顺序）
 *   - 8bpp BI_RGB（调色板索引，每行 4 字节对齐）
 *   - 4bpp BI_RGB（每字节 2 像素，每行 4 字节对齐）
 *   - 1bpp BI_RGB（每字节 8 像素，每行 4 字节对齐）
 *   - 8bpp BI_RLE8（RLE 压缩）
 *   - 4bpp BI_RLE4（RLE 压缩）
 *
 * 支持 top-down（height 为负）与 bottom-up（height 为正）。
 * 文件无法打开 → throw Exception
 * 格式不支持 → throw Exception
 */
function imagecreatefrombmp(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) { throw new Exception("imagecreatefrombmp: unable to open file: " . $filename); }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // ── 文件头（14 字节）──
    // 0-1: magic "BM"
    $m0 = php_int(C->fgetc($f));
    $m1 = php_int(C->fgetc($f));
    if ($m0 != 0x42 || $m1 != 0x4D) {
        throw new Exception("imagecreatefrombmp: not a BMP file (missing BM magic)");
    }
    // 2-5: file size (4 bytes LE) — 跳过
    C->fgetc($f); C->fgetc($f); C->fgetc($f); C->fgetc($f);
    // 6-9: reserved (4 bytes) — 跳过
    C->fgetc($f); C->fgetc($f); C->fgetc($f); C->fgetc($f);
    // 10-13: pixel data offset (4 bytes LE)
    $po0 = php_int(C->fgetc($f));
    $po1 = php_int(C->fgetc($f));
    $po2 = php_int(C->fgetc($f));
    $po3 = php_int(C->fgetc($f));
    int $pixelOffset = $po0 | ($po1 << 8) | ($po2 << 16) | ($po3 << 24);

    // ── DIB 头（BITMAPINFOHEADER, 40 字节起）──
    // 14-17: header size (4 bytes LE)
    $hs0 = php_int(C->fgetc($f));
    $hs1 = php_int(C->fgetc($f));
    $hs2 = php_int(C->fgetc($f));
    $hs3 = php_int(C->fgetc($f));
    int $dibSize = $hs0 | ($hs1 << 8) | ($hs2 << 16) | ($hs3 << 24);
    if ($dibSize < 40) {
        throw new Exception("imagecreatefrombmp: unsupported DIB header size $dibSize");
    }

    // 18-21: width (4 bytes LE, 有符号但总为正)
    $w0 = php_int(C->fgetc($f));
    $w1 = php_int(C->fgetc($f));
    $w2 = php_int(C->fgetc($f));
    $w3 = php_int(C->fgetc($f));
    int $width = $w0 | ($w1 << 8) | ($w2 << 16) | ($w3 << 24);

    // 22-25: height (4 bytes LE, 有符号 — 负值 = top-down)
    $h0 = php_int(C->fgetc($f));
    $h1 = php_int(C->fgetc($f));
    $h2 = php_int(C->fgetc($f));
    $h3 = php_int(C->fgetc($f));
    int $rawHeight = $h0 | ($h1 << 8) | ($h2 << 16) | ($h3 << 24);
    int $height = $rawHeight;
    int $topDown = 0;
    if ($h3 >= 128) {
        // Bit 31 set → 负值 → top-down bitmap
        // 通过逐字节取反加 1 计算绝对值（避免大字面量）
        $height = ((255 - $h3) << 24) | ((255 - $h2) << 16) | ((255 - $h1) << 8) | (255 - $h0);
        $height = $height + 1;
        $topDown = 1;
    }

    if ($width <= 0 || $height <= 0) {
        throw new Exception("imagecreatefrombmp: invalid dimensions (w=$width, h=$height)");
    }

    // 26-27: planes (2 bytes LE) — 跳过（应为 1）
    C->fgetc($f); C->fgetc($f);

    // 28-29: bpp (2 bytes LE)
    $bp0 = php_int(C->fgetc($f));
    $bp1 = php_int(C->fgetc($f));
    int $bpp = $bp0 | ($bp1 << 8);

    // 30-33: compression (4 bytes LE)
    $co0 = php_int(C->fgetc($f));
    $co1 = php_int(C->fgetc($f));
    $co2 = php_int(C->fgetc($f));
    $co3 = php_int(C->fgetc($f));
    int $compression = $co0 | ($co1 << 8) | ($co2 << 16) | ($co3 << 24);

    // 34-37: image size (4) — 跳过
    C->fgetc($f); C->fgetc($f); C->fgetc($f); C->fgetc($f);
    // 38-41: x pixels/meter (4) — 跳过
    C->fgetc($f); C->fgetc($f); C->fgetc($f); C->fgetc($f);
    // 42-45: y pixels/meter (4) — 跳过
    C->fgetc($f); C->fgetc($f); C->fgetc($f); C->fgetc($f);

    // 46-49: colors used (4 bytes LE)
    $cu0 = php_int(C->fgetc($f));
    $cu1 = php_int(C->fgetc($f));
    $cu2 = php_int(C->fgetc($f));
    $cu3 = php_int(C->fgetc($f));
    int $colorsUsed = $cu0 | ($cu1 << 8) | ($cu2 << 16) | ($cu3 << 24);

    // 50-53: important colors (4) — 跳过
    C->fgetc($f); C->fgetc($f); C->fgetc($f); C->fgetc($f);

    // ── 读取调色板（仅 bpp <= 8）──
    array $palette = [];
    if ($bpp <= 8) {
        int $numColors = $colorsUsed;
        if ($numColors == 0) {
            if ($bpp == 1) { $numColors = 2; }
            elseif ($bpp == 4) { $numColors = 16; }
            elseif ($bpp == 8) { $numColors = 256; }
        }
        // 调色板位于 DIB 头之后（offset = 14 + dibSize）
        C->fseek($f, c_int(14 + $dibSize), c_int(0));
        int $i = 0;
        while ($i < $numColors) {
            $bv = php_int(C->fgetc($f));
            $gv = php_int(C->fgetc($f));
            $rv = php_int(C->fgetc($f));
            $av = php_int(C->fgetc($f));  // 保留字节
            $palette[$i] = gd_make_color($rv, $gv, $bv, 0);
            $i = $i + 1;
        }
    }

    // ── 创建真彩色图像 ──
    $im = new GdImage();
    $im->width = $width;
    $im->height = $height;
    $im->trueColor = true;
    $im->palette = [];
    $im->pixels = array_fill(0, $width * $height, 0);
    $im->alphaBlending = false;
    $im->saveAlpha = true;
    $im->interlace = false;
    $im->clip = [];
    $im->style = [];
    $im->thickness = 1;
    $im->transparentColor = -1;
    $im->resolutionX = 96;
    $im->resolutionY = 96;
    $im->interpolationMethod = 3;

    // ── 解码像素数据 ──
    // 定位到像素数据起始
    C->fseek($f, c_int($pixelOffset), c_int(0));

    if ($compression == GD_BMP_BI_RGB) {
        // ── 无压缩 ──
        if ($bpp == 24) {
            // 24bpp: BGR 顺序，每行 4 字节对齐
            int $rowBytes = $width * 3;
            int $padding = (4 - ($rowBytes % 4)) % 4;
            int $y = 0;
            while ($y < $height) {
                int $imgY = $height - 1 - $y;
                if ($topDown == 1) { $imgY = $y; }
                int $x = 0;
                while ($x < $width) {
                    $bv = php_int(C->fgetc($f));
                    $gv = php_int(C->fgetc($f));
                    $rv = php_int(C->fgetc($f));
                    gd_set_pixel_raw($im, $x, $imgY, gd_make_color($rv, $gv, $bv, 0));
                    $x = $x + 1;
                }
                int $p = 0;
                while ($p < $padding) {
                    C->fgetc($f);
                    $p = $p + 1;
                }
                $y = $y + 1;
            }
        } elseif ($bpp == 32) {
            // 32bpp: BGRA 顺序（无行填充）
            int $y = 0;
            while ($y < $height) {
                int $imgY = $height - 1 - $y;
                if ($topDown == 1) { $imgY = $y; }
                int $x = 0;
                while ($x < $width) {
                    $bv = php_int(C->fgetc($f));
                    $gv = php_int(C->fgetc($f));
                    $rv = php_int(C->fgetc($f));
                    $av = php_int(C->fgetc($f));
                    int $alpha = 0;
                    if ($av != 0) {
                        // BMP alpha (0-255) → PHP alpha (0-127, 0=不透明)
                        $alpha = 127 - ($av >> 1);
                    }
                    gd_set_pixel_raw($im, $x, $imgY, gd_make_color($rv, $gv, $bv, $alpha));
                    $x = $x + 1;
                }
                $y = $y + 1;
            }
        } elseif ($bpp == 8) {
            // 8bpp: 调色板索引，每行 4 字节对齐
            int $padding = (4 - ($width % 4)) % 4;
            int $y = 0;
            while ($y < $height) {
                int $imgY = $height - 1 - $y;
                if ($topDown == 1) { $imgY = $y; }
                int $x = 0;
                while ($x < $width) {
                    $iv = php_int(C->fgetc($f));
                    if ($iv < count($palette)) {
                        gd_set_pixel_raw($im, $x, $imgY, $palette[$iv]);
                    }
                    $x = $x + 1;
                }
                int $p = 0;
                while ($p < $padding) {
                    C->fgetc($f);
                    $p = $p + 1;
                }
                $y = $y + 1;
            }
        } elseif ($bpp == 4) {
            // 4bpp: 每字节 2 像素（高 nibble 先），每行 4 字节对齐
            int $rowBytes = ($width + 1) / 2;
            int $padding = (4 - ($rowBytes % 4)) % 4;
            int $y = 0;
            while ($y < $height) {
                int $imgY = $height - 1 - $y;
                if ($topDown == 1) { $imgY = $y; }
                int $x = 0;
                while ($x < $width) {
                    $bv = php_int(C->fgetc($f));
                    int $hi = ($bv >> 4) & 0x0F;
                    int $lo = $bv & 0x0F;
                    if ($hi < count($palette)) {
                        gd_set_pixel_raw($im, $x, $imgY, $palette[$hi]);
                    }
                    $x = $x + 1;
                    if ($x < $width) {
                        if ($lo < count($palette)) {
                            gd_set_pixel_raw($im, $x, $imgY, $palette[$lo]);
                        }
                        $x = $x + 1;
                    }
                }
                int $p = 0;
                while ($p < $padding) {
                    C->fgetc($f);
                    $p = $p + 1;
                }
                $y = $y + 1;
            }
        } elseif ($bpp == 1) {
            // 1bpp: 每字节 8 像素（高位先），每行 4 字节对齐
            int $rowBytes = ($width + 7) / 8;
            int $padding = (4 - ($rowBytes % 4)) % 4;
            int $y = 0;
            while ($y < $height) {
                int $imgY = $height - 1 - $y;
                if ($topDown == 1) { $imgY = $y; }
                int $x = 0;
                while ($x < $width) {
                    $bv = php_int(C->fgetc($f));
                    int $bit = 0;
                    while ($bit < 8 && $x < $width) {
                        int $idx = ($bv >> (7 - $bit)) & 1;
                        if ($idx < count($palette)) {
                            gd_set_pixel_raw($im, $x, $imgY, $palette[$idx]);
                        }
                        $bit = $bit + 1;
                        $x = $x + 1;
                    }
                }
                int $p = 0;
                while ($p < $padding) {
                    C->fgetc($f);
                    $p = $p + 1;
                }
                $y = $y + 1;
            }
        } else {
            throw new Exception("imagecreatefrombmp: unsupported bpp $bpp for BI_RGB");
        }
    } elseif ($compression == GD_BMP_BI_RLE8) {
        // ── RLE8 压缩（8bpp 专用）──
        if ($bpp != 8) {
            throw new Exception("imagecreatefrombmp: RLE8 requires 8bpp, got $bpp");
        }
        // RLE 游标从 (0,0) 开始，对应文件中第一行
        int $cx = 0;
        int $cy = 0;
        int $done = 0;
        while ($done == 0) {
            $r0 = php_int(C->fgetc($f));
            $r1 = php_int(C->fgetc($f));
            if ($r0 < 0 || $r1 < 0) {
                $done = 1;
            } elseif ($r0 != 0) {
                // 编码模式：$r0 个像素，调色板索引 $r1
                int $count = $r0;
                int $idx = $r1;
                int $i = 0;
                while ($i < $count) {
                    if ($cx < $width && $cy < $height) {
                        int $imgY = $height - 1 - $cy;
                        if ($topDown == 1) { $imgY = $cy; }
                        if ($idx < count($palette)) {
                            gd_set_pixel_raw($im, $cx, $imgY, $palette[$idx]);
                        }
                    }
                    $cx = $cx + 1;
                    $i = $i + 1;
                }
            } else {
                // 转义序列
                if ($r1 == 0) {
                    // 行结束
                    $cx = 0;
                    $cy = $cy + 1;
                } elseif ($r1 == 1) {
                    // 位图结束
                    $done = 1;
                } elseif ($r1 == 2) {
                    // 增量：下两字节为 dx, dy
                    $dx = php_int(C->fgetc($f));
                    $dy = php_int(C->fgetc($f));
                    $cx = $cx + $dx;
                    $cy = $cy + $dy;
                } else {
                    // 绝对模式：$r1 个字节直接为调色板索引
                    int $count = $r1;
                    int $i = 0;
                    while ($i < $count) {
                        $v = php_int(C->fgetc($f));
                        if ($cx < $width && $cy < $height) {
                            int $imgY = $height - 1 - $cy;
                            if ($topDown == 1) { $imgY = $cy; }
                            if ($v < count($palette)) {
                                gd_set_pixel_raw($im, $cx, $imgY, $palette[$v]);
                            }
                        }
                        $cx = $cx + 1;
                        $i = $i + 1;
                    }
                    // 奇数个字节需补齐到偶数
                    if ($count % 2 != 0) {
                        C->fgetc($f);
                    }
                }
            }
        }
    } elseif ($compression == GD_BMP_BI_RLE4) {
        // ── RLE4 压缩（4bpp 专用）──
        if ($bpp != 4) {
            throw new Exception("imagecreatefrombmp: RLE4 requires 4bpp, got $bpp");
        }
        int $cx = 0;
        int $cy = 0;
        int $done = 0;
        while ($done == 0) {
            $r0 = php_int(C->fgetc($f));
            $r1 = php_int(C->fgetc($f));
            if ($r0 < 0 || $r1 < 0) {
                $done = 1;
            } elseif ($r0 != 0) {
                // 编码模式：$r0 个像素，交替使用 $r1 的高低 nibble
                int $count = $r0;
                int $i = 0;
                int $idx = 0;
                while ($i < $count) {
                    if ($i % 2 == 0) {
                        $idx = ($r1 >> 4) & 0x0F;
                    } else {
                        $idx = $r1 & 0x0F;
                    }
                    if ($cx < $width && $cy < $height) {
                        int $imgY = $height - 1 - $cy;
                        if ($topDown == 1) { $imgY = $cy; }
                        if ($idx < count($palette)) {
                            gd_set_pixel_raw($im, $cx, $imgY, $palette[$idx]);
                        }
                    }
                    $cx = $cx + 1;
                    $i = $i + 1;
                }
            } else {
                if ($r1 == 0) {
                    // 行结束
                    $cx = 0;
                    $cy = $cy + 1;
                } elseif ($r1 == 1) {
                    // 位图结束
                    $done = 1;
                } elseif ($r1 == 2) {
                    // 增量
                    $dx = php_int(C->fgetc($f));
                    $dy = php_int(C->fgetc($f));
                    $cx = $cx + $dx;
                    $cy = $cy + $dy;
                } else {
                    // 绝对模式：$r1 个像素，每字节 2 像素
                    int $count = $r1;
                    int $numBytes = ($count + 1) / 2;
                    int $bytesRead = 0;
                    int $pixelsRead = 0;
                    while ($bytesRead < $numBytes) {
                        $bv = php_int(C->fgetc($f));
                        $bytesRead = $bytesRead + 1;
                        int $hi = ($bv >> 4) & 0x0F;
                        int $lo = $bv & 0x0F;
                        if ($pixelsRead < $count) {
                            if ($cx < $width && $cy < $height) {
                                int $imgY = $height - 1 - $cy;
                                if ($topDown == 1) { $imgY = $cy; }
                                if ($hi < count($palette)) {
                                    gd_set_pixel_raw($im, $cx, $imgY, $palette[$hi]);
                                }
                            }
                            $cx = $cx + 1;
                            $pixelsRead = $pixelsRead + 1;
                        }
                        if ($pixelsRead < $count) {
                            if ($cx < $width && $cy < $height) {
                                int $imgY = $height - 1 - $cy;
                                if ($topDown == 1) { $imgY = $cy; }
                                if ($lo < count($palette)) {
                                    gd_set_pixel_raw($im, $cx, $imgY, $palette[$lo]);
                                }
                            }
                            $cx = $cx + 1;
                            $pixelsRead = $pixelsRead + 1;
                        }
                    }
                    // 字节数为奇数时补齐
                    if ($numBytes % 2 != 0) {
                        C->fgetc($f);
                    }
                }
            }
        }
    } else {
        throw new Exception("imagecreatefrombmp: unsupported compression $compression");
    }

    return $im;
}

/**
 * imagebmp(GdImage $image, string $filename = "", int $compressed = 0): bool
 *
 * 将图像编码为 BMP 文件。
 *
 * 简化实现：始终使用 24bpp BI_RGB bottom-up（无压缩）。
 * 即使 $compressed = 1 也写 BI_RGB（RLE 压缩复杂，简化处理但返回 true）。
 *
 * - filename 为空 → 输出到 stdout
 * - filename 非空 → 写入文件
 * - 写入失败返回 false
 */
function imagebmp(GdImage $image, string $filename = "", int $compressed = 0): bool
{
    int $w = $image->width;
    int $h = $image->height;
    if ($w <= 0 || $h <= 0) { return false; }

    // 24bpp BI_RGB bottom-up
    int $rowBytes = $w * 3;
    int $padding = (4 - ($rowBytes % 4)) % 4;
    int $rowSize = $rowBytes + $padding;
    int $pixelDataSize = $rowSize * $h;
    int $pixelOffset = 14 + 40;
    int $fileSize = $pixelOffset + $pixelDataSize;

    // 构建 BMP 数据
    $s = "";
    // ── 文件头（14 字节）──
    $s .= "BM";                            // magic
    $s .= gd_bmp_u32le($fileSize);        // file size
    $s .= gd_bmp_u16le(0);                // reserved
    $s .= gd_bmp_u16le(0);                // reserved
    $s .= gd_bmp_u32le($pixelOffset);     // pixel data offset

    // ── DIB 头（40 字节 BITMAPINFOHEADER）──
    $s .= gd_bmp_u32le(40);               // header size
    $s .= gd_bmp_u32le($w);               // width（正值 = bottom-up）
    $s .= gd_bmp_u32le($h);               // height（正值 = bottom-up）
    $s .= gd_bmp_u16le(1);                // planes
    $s .= gd_bmp_u16le(24);               // bpp
    $s .= gd_bmp_u32le(GD_BMP_BI_RGB);    // compression
    $s .= gd_bmp_u32le($pixelDataSize);   // image size
    $s .= gd_bmp_u32le(2835);             // x pixels/meter (72 DPI)
    $s .= gd_bmp_u32le(2835);             // y pixels/meter (72 DPI)
    $s .= gd_bmp_u32le(0);                // colors used
    $s .= gd_bmp_u32le(0);                // important colors

    // ── 像素数据（bottom-up, BGR, 4 字节对齐）──
    int $y = $h - 1;
    while ($y >= 0) {
        int $x = 0;
        while ($x < $w) {
            int $c = gd_pixel_color($image, $x, $y);
            int $r = gd_get_red($c);
            int $g = gd_get_green($c);
            int $b = gd_get_blue($c);
            $s .= chr($b) . chr($g) . chr($r);
            $x = $x + 1;
        }
        int $p = 0;
        while ($p < $padding) {
            $s .= chr(0);
            $p = $p + 1;
        }
        $y = $y - 1;
    }

    // ── 写入文件或 stdout ──
    if (strlen($filename) > 0) {
        $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
        if ($fp == 0) { return false; }
        C.void* $f = phpc_int_to_ptr($fp);
        defer C->fclose($f);
        int $len = strlen($s);
        C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    } else {
        echo $s;
    }

    return true;
}

// ════════════════════════════════════════════════════════════
// Task 15: TGA 文件解码器
// ════════════════════════════════════════════════════════════

/**
 * imagecreatefromtga(string $filename): GdImage
 *
 * 从 TGA（Targa）文件创建图像。
 * - 读取整个文件到内存，委托给 gd_decode_tga 解码
 * - 支持 type 2（未压缩真彩色）/ type 10（RLE 真彩色），24/32 bpp
 * - 文件无法打开 → throw Exception（I/O 错误）
 * - 格式不支持 → throw Exception（由 gd_decode_tga 抛出）
 *
 * 文件 I/O 模式参考 ext/exif/src/exif.php：
 *   fopen → phpc_ptr_to_int → phpc_int_to_ptr → fgetc 逐字节读取 → fclose
 */
function imagecreatefromtga(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefromtga: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // 获取文件大小
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $size = php_int(C->ftell($f));
    C->fseek($f, c_int(0), c_int(0));  // SEEK_SET

    // 逐字节读取整个文件到字符串（phpc 模式，参考 exif.php 的 fgetc 用法）
    $data = "";
    $i = 0;
    while ($i < $size) {
        $c = php_int(C->fgetc($f));
        if ($c < 0) { break; }
        $data .= chr($c);
        $i = $i + 1;
    }

    return gd_decode_tga($data);
}

// ════════════════════════════════════════════════════════════
// Task 13: GD/GD2 编解码
//
// 格式说明（与 libgd 2.x 一致，所有多字节整数为大端 BE）：
//
//   GD 格式：
//     - 2 字节签名: 0xFFFE=truecolor, 0xFFFF=palette
//     - 2 字节 width (BE)
//     - 2 字节 height (BE)
//     - 1 字节 trueColor 标志
//     - palette: 2 字节 colorsTotal (BE)
//     - 4 字节 transparent (BE, -1=无)
//     - palette: 256×4 字节 (R,G,B,A 每项)
//     - 像素数据: truecolor=w*h*4(BE int), palette=w*h(1 byte)
//
//   GD2 格式：
//     - 4 字节签名: "gd2\0"
//     - 2 字节 version (BE, =2)
//     - 2 字节 width (BE)
//     - 2 字节 height (BE)
//     - 2 字节 chunk_size (BE)
//     - 2 字节 format (BE, 1=RAW/3=TC_RAW/2=COMP/4=TC_COMP)
//     - 2 字节 ncx (BE, 水平 chunk 数)
//     - 2 字节 ncy (BE, 垂直 chunk 数)
//     - 颜色表（同 GD 格式）
//     - chunk 数据（RAW: 逐 chunk 逐行逐像素）
//
//   文件 I/O 模式参考 ext/exif/src/exif.php：
//     fopen → phpc_ptr_to_int → phpc_int_to_ptr → fgetc/fwrite → defer fclose
// ════════════════════════════════════════════════════════════

// ── I/O 辅助函数（大端读写）──

// gd_io_rd_byte — 从文件读取 1 字节
function gd_io_rd_byte(int $fp): int
{
    C.void* $f = phpc_int_to_ptr($fp);
    return php_int(C->fgetc($f));
}

// gd_io_rd_word_be — 读取 2 字节大端无符号整数
function gd_io_rd_word_be(int $fp): int
{
    C.void* $f = phpc_int_to_ptr($fp);
    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    return ($b0 << 8) | $b1;
}

// gd_io_rd_int_be — 读取 4 字节大端有符号整数（含符号扩展）
function gd_io_rd_int_be(int $fp): int
{
    C.void* $f = phpc_int_to_ptr($fp);
    $b0 = php_int(C->fgetc($f));
    $b1 = php_int(C->fgetc($f));
    $b2 = php_int(C->fgetc($f));
    $b3 = php_int(C->fgetc($f));
    $v = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
    // 32 位有符号整数符号扩展
    if ($v >= 0x80000000) { $v = $v - 0x100000000; }
    return $v;
}

// gd_io_word_be — 返回 2 字节大端字符串
function gd_io_word_be(int $w): string
{
    return chr(($w >> 8) & 0xFF) . chr($w & 0xFF);
}

// gd_io_int_be — 返回 4 字节大端字符串（处理负数）
function gd_io_int_be(int $v): string
{
    if ($v < 0) { $v = $v + 0x100000000; }
    return chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
}

// ── GD 格式编码（返回 GD 格式二进制字符串）──

function gd_encode_gd(GdImage $image): string
{
    $s = "";
    // 1. 签名 (2 bytes BE): 0xFFFE=truecolor, 0xFFFF=palette
    if ($image->trueColor) {
        $s .= gd_io_word_be(0xFFFE);
    } else {
        $s .= gd_io_word_be(0xFFFF);
    }
    // 2. width, height (2 bytes BE each)
    $s .= gd_io_word_be($image->width);
    $s .= gd_io_word_be($image->height);

    // 3. 颜色表
    // trueColor flag (1 byte)
    if ($image->trueColor) {
        $s .= chr(1);
    } else {
        $s .= chr(0);
    }
    // palette: colorsTotal (2 bytes BE)
    if (!$image->trueColor) {
        $colorsTotal = count($image->palette);
        $s .= gd_io_word_be($colorsTotal);
    }
    // transparent (4 bytes BE)
    $s .= gd_io_int_be($image->transparentColor);

    // palette: 256×4 bytes (R,G,B,A)
    if (!$image->trueColor) {
        $paletteSize = count($image->palette);
        int $i = 0;
        while ($i < 256) {
            if ($i < $paletteSize) {
                $c = $image->palette[$i];
                if ($c != -1) {
                    $s .= chr(gd_get_red($c));
                    $s .= chr(gd_get_green($c));
                    $s .= chr(gd_get_blue($c));
                    $s .= chr(gd_get_alpha($c));
                } else {
                    $s .= chr(0) . chr(0) . chr(0) . chr(0);
                }
            } else {
                $s .= chr(0) . chr(0) . chr(0) . chr(0);
            }
            $i = $i + 1;
        }
    }

    // 4. 像素数据
    $w = $image->width;
    $h = $image->height;
    if ($image->trueColor) {
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                $p = gd_get_pixel_raw($image, $x, $y);
                $s .= gd_io_int_be($p);
                $x = $x + 1;
            }
            $y = $y + 1;
        }
    } else {
        int $y = 0;
        while ($y < $h) {
            int $x = 0;
            while ($x < $w) {
                $p = gd_get_pixel_raw($image, $x, $y);
                $s .= chr($p & 0xFF);
                $x = $x + 1;
            }
            $y = $y + 1;
        }
    }
    return $s;
}

// ── GD 格式解码（从文件句柄读取）──

function gd_decode_gd_file(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefromgd: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // 1. 签名 (2 bytes BE)
    $sig = gd_io_rd_word_be($fp);
    if ($sig != 0xFFFE && $sig != 0xFFFF) {
        throw new Exception("imagecreatefromgd: invalid GD signature");
    }

    // 2. width, height (2 bytes BE each)
    $w = gd_io_rd_word_be($fp);
    $h = gd_io_rd_word_be($fp);
    if ($w <= 0 || $h <= 0) {
        throw new Exception("imagecreatefromgd: invalid dimensions (w=$w, h=$h)");
    }

    // 3. 颜色表
    $tcFlag = gd_io_rd_byte($fp);
    $colorsTotal = 0;
    if ($tcFlag == 0) {
        $colorsTotal = gd_io_rd_word_be($fp);
    }
    $transparent = gd_io_rd_int_be($fp);

    // palette: 256×4 bytes
    $palette = [];
    if ($tcFlag == 0) {
        int $i = 0;
        while ($i < 256) {
            $r = gd_io_rd_byte($fp);
            $g = gd_io_rd_byte($fp);
            $b = gd_io_rd_byte($fp);
            $a = gd_io_rd_byte($fp);
            if ($i < $colorsTotal) {
                $palette[$i] = gd_make_color($r, $g, $b, $a);
            }
            $i = $i + 1;
        }
    }

    // 创建图像
    if ($tcFlag == 1) {
        $im = imagecreatetruecolor($w, $h);
    } else {
        $im = imagecreate($w, $h);
    }
    $im->transparentColor = $transparent;
    if (!$im->trueColor) {
        $im->palette = $palette;
    }

    // 4. 像素数据
    int $y = 0;
    while ($y < $h) {
        int $x = 0;
        while ($x < $w) {
            if ($im->trueColor) {
                $p = gd_io_rd_int_be($fp);
                gd_set_pixel_raw($im, $x, $y, $p);
            } else {
                $p = gd_io_rd_byte($fp);
                gd_set_pixel_raw($im, $x, $y, $p);
            }
            $x = $x + 1;
        }
        $y = $y + 1;
    }
    return $im;
}

// ── GD2 格式编码（返回 GD2 格式二进制字符串）──

function gd_encode_gd2(GdImage $image, int $cs, int $fmt): string|Exception
{
    // 钳位 chunk_size（与 libgd 一致）
    if ($cs == 0) { $cs = 128; }
    if ($cs < 64) { $cs = 64; }
    if ($cs > 4096) { $cs = 4096; }

    // 不支持 COMPRESSED
    if ($fmt == IMG_GD2_COMPRESSED) {
        throw new Exception("imagegd2: COMPRESSED format not supported");
    }

    // 计算实际 file format（truecolor +2）
    $fileFmt = $fmt;
    if ($image->trueColor) { $fileFmt = $fmt + 2; }

    // chunk 数量
    int $ncx = intval(($image->width + $cs - 1) / $cs);
    int $ncy = intval(($image->height + $cs - 1) / $cs);

    $s = "";
    // 签名 "gd2\0" (4 bytes)
    $s .= "gd2" . chr(0);
    // version (2 bytes BE)
    $s .= gd_io_word_be(2);
    // width, height (2 bytes BE each)
    $s .= gd_io_word_be($image->width);
    $s .= gd_io_word_be($image->height);
    // chunk_size (2 bytes BE)
    $s .= gd_io_word_be($cs);
    // format (2 bytes BE)
    $s .= gd_io_word_be($fileFmt);
    // ncx, ncy (2 bytes BE each)
    $s .= gd_io_word_be($ncx);
    $s .= gd_io_word_be($ncy);

    // 颜色表（同 GD 格式）
    if ($image->trueColor) {
        $s .= chr(1);
    } else {
        $s .= chr(0);
    }
    if (!$image->trueColor) {
        $colorsTotal = count($image->palette);
        $s .= gd_io_word_be($colorsTotal);
    }
    $s .= gd_io_int_be($image->transparentColor);
    if (!$image->trueColor) {
        $paletteSize = count($image->palette);
        int $i = 0;
        while ($i < 256) {
            if ($i < $paletteSize) {
                $c = $image->palette[$i];
                if ($c != -1) {
                    $s .= chr(gd_get_red($c));
                    $s .= chr(gd_get_green($c));
                    $s .= chr(gd_get_blue($c));
                    $s .= chr(gd_get_alpha($c));
                } else {
                    $s .= chr(0) . chr(0) . chr(0) . chr(0);
                }
            } else {
                $s .= chr(0) . chr(0) . chr(0) . chr(0);
            }
            $i = $i + 1;
        }
    }

    // chunk 数据 (RAW): 逐 chunk 逐行逐像素
    int $cy = 0;
    while ($cy < $ncy) {
        int $cx = 0;
        while ($cx < $ncx) {
            int $ylo = $cy * $cs;
            int $yhi = $ylo + $cs;
            if ($yhi > $image->height) { $yhi = $image->height; }
            int $y = $ylo;
            while ($y < $yhi) {
                int $xlo = $cx * $cs;
                int $xhi = $xlo + $cs;
                if ($xhi > $image->width) { $xhi = $image->width; }
                int $x = $xlo;
                while ($x < $xhi) {
                    $p = gd_get_pixel_raw($image, $x, $y);
                    if ($image->trueColor) {
                        $s .= gd_io_int_be($p);
                    } else {
                        $s .= chr($p & 0xFF);
                    }
                    $x = $x + 1;
                }
                $y = $y + 1;
            }
            $cx = $cx + 1;
        }
        $cy = $cy + 1;
    }
    return $s;
}

// ── GD2 格式解码（从文件句柄读取）──

function gd_decode_gd2_file(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefromgd2: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // 1. 签名 "gd2\0" (4 bytes)
    $g0 = gd_io_rd_byte($fp);
    $g1 = gd_io_rd_byte($fp);
    $g2 = gd_io_rd_byte($fp);
    $g3 = gd_io_rd_byte($fp);
    if ($g0 != 0x67 || $g1 != 0x64 || $g2 != 0x32) {
        throw new Exception("imagecreatefromgd2: invalid GD2 signature");
    }

    // 2. version (2 bytes BE)
    $vers = gd_io_rd_word_be($fp);
    if ($vers != 2) {
        throw new Exception("imagecreatefromgd2: unsupported version $vers");
    }

    // 3. width, height (2 bytes BE each)
    $w = gd_io_rd_word_be($fp);
    $h = gd_io_rd_word_be($fp);
    if ($w <= 0 || $h <= 0) {
        throw new Exception("imagecreatefromgd2: invalid dimensions (w=$w, h=$h)");
    }

    // 4. chunk_size (2 bytes BE)
    $cs = gd_io_rd_word_be($fp);
    if ($cs < 64 || $cs > 4096) {
        throw new Exception("imagecreatefromgd2: invalid chunk size $cs");
    }

    // 5. format (2 bytes BE)
    $fmt = gd_io_rd_word_be($fp);
    if ($fmt == 2 || $fmt == 4) {
        throw new Exception("imagecreatefromgd2: COMPRESSED format not supported");
    }
    if ($fmt != 1 && $fmt != 3) {
        throw new Exception("imagecreatefromgd2: invalid format $fmt");
    }

    // 6. ncx, ncy (2 bytes BE each)
    $ncx = gd_io_rd_word_be($fp);
    $ncy = gd_io_rd_word_be($fp);

    // 7. 颜色表（同 GD 格式）
    $tcFlag = gd_io_rd_byte($fp);
    $colorsTotal = 0;
    if ($tcFlag == 0) {
        $colorsTotal = gd_io_rd_word_be($fp);
    }
    $transparent = gd_io_rd_int_be($fp);

    $palette = [];
    if ($tcFlag == 0) {
        int $i = 0;
        while ($i < 256) {
            $r = gd_io_rd_byte($fp);
            $g = gd_io_rd_byte($fp);
            $b = gd_io_rd_byte($fp);
            $a = gd_io_rd_byte($fp);
            if ($i < $colorsTotal) {
                $palette[$i] = gd_make_color($r, $g, $b, $a);
            }
            $i = $i + 1;
        }
    }

    // 创建图像
    if ($tcFlag == 1) {
        $im = imagecreatetruecolor($w, $h);
    } else {
        $im = imagecreate($w, $h);
    }
    $im->transparentColor = $transparent;
    if (!$im->trueColor) {
        $im->palette = $palette;
    }

    // 8. chunk 数据 (RAW): 逐 chunk 逐行逐像素
    int $cy = 0;
    while ($cy < $ncy) {
        int $cx = 0;
        while ($cx < $ncx) {
            int $ylo = $cy * $cs;
            int $yhi = $ylo + $cs;
            if ($yhi > $h) { $yhi = $h; }
            int $y = $ylo;
            while ($y < $yhi) {
                int $xlo = $cx * $cs;
                int $xhi = $xlo + $cs;
                if ($xhi > $w) { $xhi = $w; }
                int $x = $xlo;
                while ($x < $xhi) {
                    if ($im->trueColor) {
                        $p = gd_io_rd_int_be($fp);
                        gd_set_pixel_raw($im, $x, $y, $p);
                    } else {
                        $p = gd_io_rd_byte($fp);
                        gd_set_pixel_raw($im, $x, $y, $p);
                    }
                    $x = $x + 1;
                }
                $y = $y + 1;
            }
            $cx = $cx + 1;
        }
        $cy = $cy + 1;
    }
    return $im;
}

// ════════════════════════════════════════════════════════════
// 公开 API（与 PHP 8.5 gd.stub.php 签名一致）
// ════════════════════════════════════════════════════════════

/**
 * imagecreatefromgd(string $filename): GdImage
 *
 * 从 GD 格式文件创建图像。
 * - 文件无法打开 → throw Exception（I/O 错误）
 * - 格式无效 → throw Exception
 */
function imagecreatefromgd(string $filename): GdImage|Exception
{
    return gd_decode_gd_file($filename);
}

/**
 * imagegd(GdImage $image, string $filename = ""): bool
 *
 * 将图像编码为 GD 格式并写入文件。
 * - $filename 为空 → throw Exception（不支持 stdout 输出）
 * - 文件无法创建 → throw Exception
 */
function imagegd(GdImage $image, string $filename = ""): bool|Exception
{
    if ($filename == "") {
        throw new Exception("imagegd: filename required");
    }
    $s = gd_encode_gd($image);
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) {
        throw new Exception("imagegd: unable to create file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    return true;
}

/**
 * imagecreatefromgd2(string $filename): GdImage
 *
 * 从 GD2 格式文件创建图像。
 * - 仅支持 RAW 格式（format=1/3），COMPRESSED 抛异常
 * - 文件无法打开 → throw Exception
 */
function imagecreatefromgd2(string $filename): GdImage|Exception
{
    return gd_decode_gd2_file($filename);
}

/**
 * imagecreatefromgd2part(string $filename, int $src_x, int $src_y, int $src_w, int $src_h): GdImage
 *
 * 从 GD2 文件中读取指定区域。
 * - 先解码完整图像，再用 imagecopy 提取区域
 * - 调色板图像会复制源调色板以保持索引一致
 */
function imagecreatefromgd2part(string $filename, int $src_x, int $src_y, int $src_w, int $src_h): GdImage|Exception
{
    if ($src_w <= 0 || $src_h <= 0) {
        throw new Exception("imagecreatefromgd2part: invalid dimensions (w=$src_w, h=$src_h)");
    }
    $src = gd_decode_gd2_file($filename);
    if ($src->trueColor) {
        $dst = imagecreatetruecolor($src_w, $src_h);
    } else {
        $dst = imagecreate($src_w, $src_h);
        // 复制源调色板以保持索引一致
        $dst->palette = $src->palette;
        $dst->transparentColor = $src->transparentColor;
    }
    imagecopy($dst, $src, 0, 0, $src_x, $src_y, $src_w, $src_h);
    return $dst;
}

/**
 * imagegd2(GdImage $image, string $filename = "", int $chunk_size = 0, int $format = IMG_GD2_RAW): bool
 *
 * 将图像编码为 GD2 格式并写入文件。
 * - $chunk_size=0 → 使用默认值 128（GD2_CHUNKSIZE）
 * - 仅支持 RAW 格式（IMG_GD2_RAW），COMPRESSED 抛异常
 * - $filename 为空 → throw Exception
 */
function imagegd2(GdImage $image, string $filename = "", int $chunk_size = 0, int $format = IMG_GD2_RAW): bool|Exception
{
    if ($filename == "") {
        throw new Exception("imagegd2: filename required");
    }
    $s = gd_encode_gd2($image, $chunk_size, $format);
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) {
        throw new Exception("imagegd2: unable to create file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 14: WBMP 与 XBM 编解码
//
// WBMP（无线位图）Type 0：1bit 黑白图像
//   - 1 字节 type(0) + 1 字节 fixed header(0)
//   - 宽度/高度（可变长度编码，每字节 7 位 + 高位续传）
//   - 像素：MSB 优先，每行字节对齐（ceil(width/8) 字节）
//   - 1=黑(0x00000000)，0=白(0x00FFFFFF)
//
// XBM（X 位图）：C 源码格式
//   - #define <name>_width N / #define <name>_height N
//   - static unsigned char <name>_bits[] = { 0xFF, 0x03, ... };
//   - 像素：LSB 优先，每行字节对齐
//   - 1=黑(0x00000000)，0=白(0x00FFFFFF)
//
// 文件 I/O 模式参考 ext/exif/src/exif.php
// ════════════════════════════════════════════════════════════

// gd_wbmp_write_varint — 将整数以 WBMP 可变长度格式编码为字符串
//   每 7 位一组，MSB 先存，除最后一组外高位 (0x80) 置 1
function gd_wbmp_write_varint(int $value): string
{
    $groups = [];
    if ($value == 0) {
        $groups[0] = 0;
    } else {
        int $v = $value;
        int $idx = 0;
        while ($v > 0) {
            $groups[$idx] = $v & 0x7F;
            $v = $v >> 7;
            $idx = $idx + 1;
        }
    }
    int $cnt = count($groups);
    $s = "";
    int $i = $cnt - 1;
    while ($i >= 0) {
        int $b = intval($groups[$i]);
        if ($i > 0) {
            $b = $b | 0x80;
        }
        $s .= chr($b);
        $i = $i - 1;
    }
    return $s;
}

/**
 * imagecreatefromwbmp(string $filename): GdImage
 *
 * 从 WBMP 文件创建图像。
 * - WBMP Type 0：1bit 黑白图像
 * - 1=黑(0x00000000)，0=白(0x00FFFFFF)
 * - 文件无法打开 → throw Exception（I/O 错误）
 */
function imagecreatefromwbmp(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefromwbmp: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    // 获取文件大小
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $size = php_int(C->ftell($f));
    C->fseek($f, c_int(0), c_int(0));  // SEEK_SET
    // 逐字节读取整个文件到字符串
    $data = "";
    $i = 0;
    while ($i < $size) {
        $c = php_int(C->fgetc($f));
        if ($c < 0) { break; }
        $data .= chr($c);
        $i = $i + 1;
    }
    return gd_decode_wbmp($data);
}

/**
 * imagewbmp(GdImage $image, string $filename = "", int $foreground = 0): bool
 *
 * 将图像编码为 WBMP 格式写入文件。
 * - foreground 为前景色（黑）的整数值，匹配的像素为黑(1)，其余为白(0)
 * - filename 为空时返回 false（PHP 原生输出到 stdout，此处不支持）
 */
function imagewbmp(GdImage $image, string $filename = "", int $foreground = 0): bool
{
    if ($filename == "") {
        return false;
    }
    int $w = $image->width;
    int $h = $image->height;
    int $rowBytes = ($w + 7) >> 3;
    // 构造 WBMP 数据
    $s = chr(0) . chr(0);  // type=0, fixed header=0
    $s .= gd_wbmp_write_varint($w);
    $s .= gd_wbmp_write_varint($h);
    // 像素数据（MSB 优先，每行字节对齐）
    int $y = 0;
    while ($y < $h) {
        int $bi = 0;
        while ($bi < $rowBytes) {
            int $byteVal = 0;
            int $bit = 0;
            while ($bit < 8) {
                int $x = $bi * 8 + $bit;
                if ($x < $w) {
                    int $px = gd_get_pixel_raw($image, $x, $y);
                    if ($px == $foreground) {
                        $byteVal = $byteVal | (1 << (7 - $bit));
                    }
                }
                $bit = $bit + 1;
            }
            $s .= chr($byteVal);
            $bi = $bi + 1;
        }
        $y = $y + 1;
    }
    // 写入文件
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) {
        return false;
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 17: PNG 编解码（基于 zlib）
//
// 编解码核心实现见 gd_codec_png.php：
//   - gd_decode_png(string $data): GdImage|Exception
//   - gd_encode_png(GdImage $image, int $quality, int $filters): string|Exception
//
// 文件 I/O 模式参考 ext/exif/src/exif.php：
//   fopen → phpc_ptr_to_int → phpc_int_to_ptr → fgetc/fwrite → defer fclose
// ════════════════════════════════════════════════════════════

/**
 * imagecreatefrompng(string $filename): GdImage
 *
 * 从 PNG 文件创建图像。
 * - 读取整个文件到内存，委托给 gd_decode_png 解码
 * - 支持 colorType 0/2/3/4/6（8-bit），不支持 16-bit 和隔行扫描
 * - 文件无法打开 → throw Exception（I/O 错误）
 * - 格式无效 → throw Exception（由 gd_decode_png 抛出）
 */
function imagecreatefrompng(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefrompng: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // 获取文件大小
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $size = php_int(C->ftell($f));
    C->fseek($f, c_int(0), c_int(0));  // SEEK_SET

    // 逐字节读取整个文件到字符串
    $data = "";
    $i = 0;
    while ($i < $size) {
        $c = php_int(C->fgetc($f));
        if ($c < 0) { break; }
        $data .= chr($c);
        $i = $i + 1;
    }

    return gd_decode_png($data);
}

/**
 * imagepng(GdImage $image, string $filename = "", int $quality = -1, int $filters = PNG_ALL_FILTERS): bool
 *
 * 将图像编码为 PNG 格式并写入文件（或输出到 stdout）。
 * - $quality: 压缩级别 (-1=默认, 0-9)
 * - $filters: 过滤器位掩码（接受但简化为 None filter）
 * - $filename 为空时输出到 stdout
 * - 真彩色 + saveAlpha → RGBA；真彩色 + !saveAlpha → RGB；调色板 → colorType 3
 */
function imagepng(GdImage $image, string $filename = "", int $quality = -1, int $filters = PNG_ALL_FILTERS): bool|Exception
{
    $s = gd_encode_png($image, $quality, $filters);

    if (strlen($filename) > 0) {
        $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
        if ($fp == 0) {
            throw new Exception("imagepng: unable to create file: " . $filename);
        }
        C.void* $f = phpc_int_to_ptr($fp);
        defer C->fclose($f);
        int $len = strlen($s);
        C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    } else {
        echo $s;
    }

    return true;
}

// gd_decode_xbm — 解码 XBM C 源码字符串为 GdImage
//   解析 #define <name>_width/height 和 hex 数据数组
//   LSB 优先，1=黑(0x00000000)，0=白(0x00FFFFFF)
function gd_decode_xbm(string $data): GdImage|Exception
{
    int $dataLen = strlen($data);

    // 解析 #define xxx_width N
    int $wPos = strpos($data, "_width");
    if ($wPos < 0) {
        throw new Exception("gd_decode_xbm: _width not found");
    }
    int $scan = $wPos + 6;  // strlen("_width") = 6
    // 跳过空白
    while ($scan < $dataLen) {
        int $ch = ord(substr($data, $scan, 1));
        if ($ch == 32 || $ch == 9) {
            $scan = $scan + 1;
        } else {
            break;
        }
    }
    // 读取数字
    int $wStart = $scan;
    while ($scan < $dataLen) {
        int $ch = ord(substr($data, $scan, 1));
        if ($ch >= 48 && $ch <= 57) {
            $scan = $scan + 1;
        } else {
            break;
        }
    }
    if ($scan == $wStart) {
        throw new Exception("gd_decode_xbm: invalid width value");
    }
    int $w = intval(substr($data, $wStart, $scan - $wStart));

    // 解析 #define xxx_height N
    int $hPos = strpos($data, "_height");
    if ($hPos < 0) {
        throw new Exception("gd_decode_xbm: _height not found");
    }
    $scan = $hPos + 7;  // strlen("_height") = 7
    while ($scan < $dataLen) {
        int $ch = ord(substr($data, $scan, 1));
        if ($ch == 32 || $ch == 9) {
            $scan = $scan + 1;
        } else {
            break;
        }
    }
    int $hStart = $scan;
    while ($scan < $dataLen) {
        int $ch = ord(substr($data, $scan, 1));
        if ($ch >= 48 && $ch <= 57) {
            $scan = $scan + 1;
        } else {
            break;
        }
    }
    if ($scan == $hStart) {
        throw new Exception("gd_decode_xbm: invalid height value");
    }
    int $h = intval(substr($data, $hStart, $scan - $hStart));

    if ($w <= 0 || $h <= 0) {
        throw new Exception("gd_decode_xbm: invalid dimensions ($w x $h)");
    }

    // 解析 hex 字节数组：查找 { ... }
    int $braceStart = strpos($data, "{");
    if ($braceStart < 0) {
        throw new Exception("gd_decode_xbm: data array start '{' not found");
    }
    // 从 { 之后搜索 }
    $afterBrace = substr($data, $braceStart + 1, 0);
    int $braceEnd = strpos($afterBrace, "}");
    if ($braceEnd < 0) {
        throw new Exception("gd_decode_xbm: data array end '}' not found");
    }
    $hexContent = substr($afterBrace, 0, $braceEnd);
    // 按逗号分割并解析 hex 值
    $parts = explode(",", $hexContent);
    int $partCount = count($parts);
    $bytes = [];
    int $pi = 0;
    while ($pi < $partCount) {
        $tok = trim($parts[$pi]);
        if (strlen($tok) > 0) {
            $bytes[count($bytes)] = intval(hexdec($tok));
        }
        $pi = $pi + 1;
    }
    int $byteCount = count($bytes);

    // 创建真彩色图像：1=黑(0x00000000)，0=白(0x00FFFFFF)
    $im = imagecreatetruecolor($w, $h);
    int $black = gd_make_color(0, 0, 0, 0);
    int $white = gd_make_color(255, 255, 255, 0);
    int $rowBytes = ($w + 7) >> 3;
    int $y = 0;
    while ($y < $h) {
        int $x = 0;
        while ($x < $w) {
            int $byteIndex = $y * $rowBytes + ($x >> 3);
            int $bitVal = 0;
            if ($byteIndex < $byteCount) {
                $bitVal = intval($bytes[$byteIndex]);
            }
            // XBM: LSB first — bit 0 is leftmost pixel
            int $bit = ($bitVal >> ($x & 7)) & 1;
            if ($bit == 1) {
                gd_set_pixel_raw($im, $x, $y, $black);
            } else {
                gd_set_pixel_raw($im, $x, $y, $white);
            }
            $x = $x + 1;
        }
        $y = $y + 1;
    }
    return $im;
}

/**
 * imagecreatefromxbm(string $filename): GdImage
 *
 * 从 XBM 文件创建图像。
 * - 解析 #define width/height 和 hex 数据数组
 * - LSB 优先，1=黑(0x00000000)，0=白(0x00FFFFFF)
 * - 文件无法打开 → throw Exception（I/O 错误）
 */
function imagecreatefromxbm(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefromxbm: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    // 获取文件大小
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $size = php_int(C->ftell($f));
    C->fseek($f, c_int(0), c_int(0));  // SEEK_SET
    // 逐字节读取整个文件到字符串
    $data = "";
    $i = 0;
    while ($i < $size) {
        $c = php_int(C->fgetc($f));
        if ($c < 0) { break; }
        $data .= chr($c);
        $i = $i + 1;
    }
    return gd_decode_xbm($data);
}

/**
 * imagexbm(GdImage $image, string $filename, int $foreground = 0): bool
 *
 * 将图像编码为 XBM C 源码写入文件。
 * - filename 无扩展名时加 .xbm
 * - foreground 为前景色（黑）的整数值
 * - LSB 优先，1=前景色(黑)，0=白
 * - 12 字节每行，格式 "0xNN"（大写）
 */
function imagexbm(GdImage $image, string $filename, int $foreground = 0): bool
{
    // 处理文件名：无扩展名时加 .xbm
    int $fnLen = strlen($filename);
    // 查找 basename 起始位置（最后一个 / 或 \ 之后）
    int $baseStart = 0;
    int $slashPos = strrpos($filename, "/");
    if ($slashPos >= 0) { $baseStart = $slashPos + 1; }
    int $bslashPos = strrpos($filename, "\\");
    if ($bslashPos >= $baseStart) { $baseStart = $bslashPos + 1; }
    // 在 basename 中查找扩展名点
    $basename = substr($filename, $baseStart, 0);
    int $dotPos = strrpos($basename, ".");
    if ($dotPos < 0) {
        // 无扩展名，追加 .xbm（用 .= 避免 CodeGenerator 先 free 再 concat 的 use-after-free）
        $filename .= ".xbm";
    }
    // 重新提取 basename 和标识名
    $basename = substr($filename, $baseStart, 0);
    $dotPos = strrpos($basename, ".");
    // 用 if/else 避免对 $name 二次赋值导致 use-after-free
    if ($dotPos > 0) {
        $name = substr($basename, 0, $dotPos);
    } else {
        $name = $basename;
    }
    // 如果 name 为空，使用 "image"
    if (strlen($name) == 0) {
        $name = "image";
    }
    // 清理标识名：非字母数字替换为 _
    int $nameLen = strlen($name);
    $cleanName = "";
    int $ni = 0;
    while ($ni < $nameLen) {
        int $ch = ord(substr($name, $ni, 1));
        if (($ch >= 65 && $ch <= 90) || ($ch >= 97 && $ch <= 122) || ($ch >= 48 && $ch <= 57)) {
            $cleanName .= chr($ch);
        } else {
            $cleanName .= "_";
        }
        $ni = $ni + 1;
    }
    // 构造 XBM 数据
    int $w = $image->width;
    int $h = $image->height;
    $s = "#define " . $cleanName . "_width " . $w . "\n";
    $s .= "#define " . $cleanName . "_height " . $h . "\n";
    $s .= "static unsigned char " . $cleanName . "_bits[] = {\n  ";
    // 像素数据（LSB 优先，每行字节对齐）
    int $rowBytes = ($w + 7) >> 3;
    int $p = 0;  // 字节计数器（用于换行）
    int $y = 0;
    while ($y < $h) {
        int $bi = 0;
        while ($bi < $rowBytes) {
            int $byteVal = 0;
            int $bit = 0;
            while ($bit < 8) {
                int $x = $bi * 8 + $bit;
                if ($x < $w) {
                    int $px = gd_get_pixel_raw($image, $x, $y);
                    if ($px == $foreground) {
                        $byteVal = $byteVal | (1 << $bit);
                    }
                }
                $bit = $bit + 1;
            }
            // 输出字节
            if ($p > 0) {
                $s .= ", ";
                if ($p % 12 == 0) {
                    $s .= "\n  ";
                }
            }
            $s .= sprintf("0x%02X", $byteVal);
            $p = $p + 1;
            $bi = $bi + 1;
        }
        $y = $y + 1;
    }
    $s .= "};\n";
    // 写入文件
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) {
        return false;
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    return true;
}

// ════════════════════════════════════════════════════════════
// Task 18: 不支持格式的明确报错
//
// 纯 phpc 实现无法支持 JPEG/WebP/AVIF/XPM/FreeType。
// 这些函数调用时必须抛出 RuntimeException，消息明确指出格式不支持。
// 不得静默返回 false。
//
// 说明：
//   - RuntimeException 为内置 Exception 子类，可被 catch (Exception) 捕获
//   - imagecreatefromstring 分发器已对 JPEG/WebP/AVIF/XPM 抛 RuntimeException
//   - 此处为独立的 imagecreatefromXxx / imageXxx 入口函数
// ════════════════════════════════════════════════════════════

// ── JPEG ──

/**
 * imagecreatefromjpeg(string $filename): GdImage
 *
 * 纯 phpc 不支持 JPEG，立即抛 RuntimeException。
 */
function imagecreatefromjpeg(string $filename): GdImage|Exception
{
    throw new RuntimeException("imagecreatefromjpeg: JPEG format not supported in pure phpc GD implementation");
}

/**
 * imagejpeg(GdImage $image, string $filename = "", int $quality = -1): bool
 *
 * 纯 phpc 不支持 JPEG，立即抛 RuntimeException。
 */
function imagejpeg(GdImage $image, string $filename = "", int $quality = -1): bool|Exception
{
    throw new RuntimeException("imagejpeg: JPEG format not supported in pure phpc GD implementation");
}

// ── WebP ──

/**
 * imagecreatefromwebp(string $filename): GdImage
 *
 * 纯 phpc 不支持 WebP，立即抛 RuntimeException。
 */
function imagecreatefromwebp(string $filename): GdImage|Exception
{
    throw new RuntimeException("imagecreatefromwebp: WebP format not supported in pure phpc GD implementation");
}

/**
 * imagewebp(GdImage $image, string $filename = "", int $quality = -1): bool
 *
 * 纯 phpc 不支持 WebP，立即抛 RuntimeException。
 */
function imagewebp(GdImage $image, string $filename = "", int $quality = -1): bool|Exception
{
    throw new RuntimeException("imagewebp: WebP format not supported in pure phpc GD implementation");
}

// ── AVIF ──

/**
 * imagecreatefromavif(string $filename): GdImage
 *
 * 纯 phpc 不支持 AVIF，立即抛 RuntimeException。
 */
function imagecreatefromavif(string $filename): GdImage|Exception
{
    throw new RuntimeException("imagecreatefromavif: AVIF format not supported in pure phpc GD implementation");
}

/**
 * imageavif(GdImage $image, string $filename = "", int $quality = -1, int $speed = -1): bool
 *
 * 纯 phpc 不支持 AVIF，立即抛 RuntimeException。
 */
function imageavif(GdImage $image, string $filename = "", int $quality = -1, int $speed = -1): bool|Exception
{
    throw new RuntimeException("imageavif: AVIF format not supported in pure phpc GD implementation");
}

// ── XPM ──

/**
 * imagecreatefromxpm(string $filename): GdImage
 *
 * 纯 phpc 不支持 XPM，立即抛 RuntimeException。
 */
function imagecreatefromxpm(string $filename): GdImage|Exception
{
    throw new RuntimeException("imagecreatefromxpm: XPM format not supported in pure phpc GD implementation");
}

// ── FreeType ──

/**
 * imagettftext(GdImage $image, float $size, float $angle, int $x, int $y, int $color, string $font_filename, string $text, array $options = []): array
 *
 * 纯 phpc 不支持 FreeType，立即抛 RuntimeException。
 */
function imagettftext(GdImage $image, float $size, float $angle, int $x, int $y, int $color, string $font_filename, string $text, array $options = []): array|Exception
{
    throw new RuntimeException("imagettftext: FreeType not supported in pure phpc GD implementation");
}

/**
 * imagefttext(GdImage $image, float $size, float $angle, int $x, int $y, int $color, string $font_filename, string $text, array $options = []): array
 *
 * 纯 phpc 不支持 FreeType，立即抛 RuntimeException。
 */
function imagefttext(GdImage $image, float $size, float $angle, int $x, int $y, int $color, string $font_filename, string $text, array $options = []): array|Exception
{
    throw new RuntimeException("imagefttext: FreeType not supported in pure phpc GD implementation");
}

/**
 * imagettfbbox(float $size, float $angle, string $font_filename, string $text, array $options = []): array
 *
 * 纯 phpc 不支持 FreeType，立即抛 RuntimeException。
 */
function imagettfbbox(float $size, float $angle, string $font_filename, string $text, array $options = []): array|Exception
{
    throw new RuntimeException("imagettfbbox: FreeType not supported in pure phpc GD implementation");
}

/**
 * imageftbbox(float $size, float $angle, string $font_filename, string $text, array $options = []): array
 *
 * 纯 phpc 不支持 FreeType，立即抛 RuntimeException。
 */
function imageftbbox(float $size, float $angle, string $font_filename, string $text, array $options = []): array|Exception
{
    throw new RuntimeException("imageftbbox: FreeType not supported in pure phpc GD implementation");
}

// ── Windows 专用（非 Windows 平台不可用）──

/**
 * imagegrabwindow(int $window_handle, int $client_area = 0): GdImage
 *
 * Windows 专用函数，纯 phpc 实现不可用，立即抛 RuntimeException。
 */
function imagegrabwindow(int $window_handle, int $client_area = 0): GdImage|Exception
{
    throw new RuntimeException("imagegrabwindow: Windows-only function not supported");
}

/**
 * imagegrabscreen(): GdImage
 *
 * Windows 专用函数，纯 phpc 实现不可用，立即抛 RuntimeException。
 */
function imagegrabscreen(): GdImage|Exception
{
    throw new RuntimeException("imagegrabscreen: Windows-only function not supported");
}

// ════════════════════════════════════════════════════════════
// Task 16: GIF 编解码（含 LZW 压缩）
//
// GIF 格式：
//   - Header: "GIF87a" 或 "GIF89a"
//   - Logical Screen Descriptor (7 字节)
//   - Global Color Table（可选）
//   - GCE / Image Descriptor / Local Color Table / LZW Data
//   - Trailer (0x3B)
//
// GIF 仅支持调色板图像（最多 256 色）。
// 真彩色图像编码前需量化为调色板（imagetruecolortopalette）。
//
// LZW 算法：
//   - 初始 code size = min_code_size + 1
//   - Clear code = 1 << min_code_size
//   - EOI code = clear code + 1
//   - 下一可用 code = clear code + 2
//   - code size 在 next_code 达到 2^code_size 时增加（上限 12 位）
//   - 字典满（4096）时发 clear code 重置
//   - 码以 LSB first 位流写入/读取
// ════════════════════════════════════════════════════════════

// ── GIF 位写入器（LZW 编码用）──

final class gd_GifBitWriter
{
    public string $data = "";       // 输出字节流
    public int $bitBuf = 0;         // 位累加器
    public int $bitCount = 0;       // 累加器中有效位数
}

// gd_gif_write_bits — 向位写入器写入指定宽度的码（LSB first）
function gd_gif_write_bits(gd_GifBitWriter $w, int $code, int $size): void
{
    $w->bitBuf = $w->bitBuf | ($code << $w->bitCount);
    $w->bitCount = $w->bitCount + $size;
    while ($w->bitCount >= 8) {
        $w->data .= chr($w->bitBuf & 0xFF);
        $w->bitBuf = $w->bitBuf >> 8;
        $w->bitCount = $w->bitCount - 8;
    }
}

// gd_gif_flush_bits — 刷新剩余位（补零到完整字节）
function gd_gif_flush_bits(gd_GifBitWriter $w): void
{
    if ($w->bitCount > 0) {
        $w->data .= chr($w->bitBuf & 0xFF);
        $w->bitBuf = 0;
        $w->bitCount = 0;
    }
}

// ── GIF 位读取器（LZW 解码用）──

final class gd_GifBitReader
{
    public string $data = "";       // 输入字节流
    public int $pos = 0;            // 当前字节位置
    public int $len = 0;            // 数据总长度
    public int $bitBuf = 0;         // 位累加器
    public int $bitCount = 0;       // 累加器中有效位数
}

// gd_gif_read_code — 从位读取器读取指定宽度的码（LSB first）
//   返回码值；数据不足返回 -1
function gd_gif_read_code(gd_GifBitReader $r, int $codeSize): int
{
    while ($r->bitCount < $codeSize && $r->pos < $r->len) {
        $byte = ord(substr($r->data, $r->pos, 1));
        $r->pos = $r->pos + 1;
        $r->bitBuf = $r->bitBuf | ($byte << $r->bitCount);
        $r->bitCount = $r->bitCount + 8;
    }
    if ($r->bitCount < $codeSize) {
        return -1;
    }
    $mask = (1 << $codeSize) - 1;
    $code = $r->bitBuf & $mask;
    $r->bitBuf = $r->bitBuf >> $codeSize;
    $r->bitCount = $r->bitCount - $codeSize;
    return $code;
}

// ── LZW 解码 ──
//   $lzwData: LZW 压缩数据（已拼装的连续字节流，不含子块长度前缀）
//   $minCodeSize: LZW 最小码宽（通常 = 调色板位宽，最小 2）
//   $expectedPixels: 预期解码像素数（width * height）
//   返回: int 数组，每元素为调色板索引
function gd_gif_lzw_decode(string $lzwData, int $minCodeSize, int $expectedPixels): array
{
    $clearCode = 1 << $minCodeSize;
    $endCode = $clearCode + 1;

    $reader = new gd_GifBitReader();
    $reader->data = $lzwData;
    $reader->len = strlen($lzwData);

    // 字典：prefix[code] = 前缀码，suffix[code] = 后缀字节
    $prefix = array_fill(0, 4096, 0);
    $suffix = array_fill(0, 4096, 0);

    // 初始化单字节字典
    $i = 0;
    while ($i < $clearCode) {
        $suffix[$i] = $i;
        $i = $i + 1;
    }

    $codeSize = $minCodeSize + 1;
    $maxCodeSize = 2 * $clearCode;
    $nextCode = $clearCode + 2;

    $output = array_fill(0, $expectedPixels, 0);
    $outPos = 0;

    $stack = array_fill(0, 4097, 0);
    $oldCode = -1;
    $firstCode = 0;
    $fresh = 1;

    while (true) {
        $code = gd_gif_read_code($reader, $codeSize);
        if ($code < 0) { break; }

        if ($code == $clearCode) {
            // 重置字典
            $codeSize = $minCodeSize + 1;
            $maxCodeSize = 2 * $clearCode;
            $nextCode = $clearCode + 2;
            $fresh = 1;
            continue;
        }

        if ($code == $endCode) {
            break;
        }

        if ($fresh) {
            $fresh = 0;
            $oldCode = $code;
            $firstCode = $code;
            if ($outPos < $expectedPixels) {
                $output[$outPos] = $code;
                $outPos = $outPos + 1;
            }
            continue;
        }

        $inCode = $code;
        $stackTop = 0;

        // 特殊情况：码尚未在字典中（code == nextCode）
        if ($code >= $nextCode) {
            $stack[$stackTop] = $firstCode;
            $stackTop = $stackTop + 1;
            $code = $oldCode;
        }

        // 沿前缀链解码
        while ($code >= $clearCode) {
            $stack[$stackTop] = intval($suffix[$code]);
            $stackTop = $stackTop + 1;
            $code = intval($prefix[$code]);
        }

        $firstCode = intval($suffix[$code]);
        if ($outPos < $expectedPixels) {
            $output[$outPos] = $firstCode;
            $outPos = $outPos + 1;
        }

        // 输出栈（逆序）
        while ($stackTop > 0) {
            $stackTop = $stackTop - 1;
            if ($outPos < $expectedPixels) {
                $output[$outPos] = intval($stack[$stackTop]);
                $outPos = $outPos + 1;
            }
        }

        // 新增字典项
        //   编码器在 output() 内检查 free_ent > maxcode（GIFLIB 风格，free_ent 来自上一轮 ++），
        //   解码器慢一个条目（首码不建字典），但码宽检查同样使用 nextCode >= maxCodeSize，
        //   因编码器码宽增长发生在写码之后、dict 赋值之前，与解码器读码后增长同步。
        if ($nextCode < 4096) {
            $prefix[$nextCode] = $oldCode;
            $suffix[$nextCode] = $firstCode;
            $nextCode = $nextCode + 1;
            if ($nextCode >= $maxCodeSize && $maxCodeSize < 4096) {
                $maxCodeSize = $maxCodeSize * 2;
                $codeSize = $codeSize + 1;
            }
        }

        $oldCode = $inCode;
    }

    return $output;
}

// ── LZW 编码 ──
//   $pixels: 调色板索引数组
//   $pixelCount: 像素数
//   $minCodeSize: LZW 最小码宽（>= 2）
//   返回: LZW 压缩字节流（不含子块长度前缀）
function gd_gif_lzw_encode(array $pixels, int $pixelCount, int $minCodeSize): string
{
    $clearCode = 1 << $minCodeSize;
    $endCode = $clearCode + 1;
    $initBits = $minCodeSize + 1;

    $writer = new gd_GifBitWriter();

    $nBits = $initBits;
    $maxcode = (1 << $nBits) - 1;
    $freeEnt = $clearCode + 2;

    // 字典：key = ent * 256 + c（数值键，避免 phpc 字符串键 isset 陷阱）→ code
    $dict = [];

    // 输出 clear code
    gd_gif_write_bits($writer, $clearCode, $nBits);

    if ($pixelCount == 0) {
        gd_gif_write_bits($writer, $endCode, $nBits);
        gd_gif_flush_bits($writer);
        return $writer->data;
    }

    $ent = intval($pixels[0]);
    $i = 1;
    while ($i < $pixelCount) {
        $c = intval($pixels[$i]);
        $key = $ent * 256 + $c;
        if (isset($dict[$key])) {
            $ent = intval($dict[$key]);
        } else {
            gd_gif_write_bits($writer, $ent, $nBits);
            // 新增字典项或重置
            if ($freeEnt < 4096) {
                // 码宽检查必须在 freeEnt++ 之前进行（使用当前 freeEnt 值），
                // 与参考实现 gd_gif_out.c 的 output() 一致：output(code) 先写码，
                // 再检查 free_ent > maxcode，最后才 free_ent++。
                // 解码器在 max_code++ 之后检查 max_code >= max_code_size，
                // 因解码器比编码器慢一个条目，此顺序保证双方同步。
                if ($freeEnt > $maxcode && $nBits < 12) {
                    $nBits = $nBits + 1;
                    if ($nBits == 12) {
                        $maxcode = 4096;
                    } else {
                        $maxcode = (1 << $nBits) - 1;
                    }
                }
                $dict[$key] = $freeEnt;
                $freeEnt = $freeEnt + 1;
            } else {
                // 字典满：发 clear code 重置
                gd_gif_write_bits($writer, $clearCode, $nBits);
                $dict = [];
                $nBits = $initBits;
                $maxcode = (1 << $nBits) - 1;
                $freeEnt = $clearCode + 2;
            }
            $ent = $c;
        }
        $i = $i + 1;
    }

    // 输出最后的码和 EOI
    // 参考实现: output(ent) 内部写码后检查 free_ent > maxcode 可能增宽，
    // 随后 output(EOFCode) 用新码宽写 EOI
    gd_gif_write_bits($writer, $ent, $nBits);
    if ($freeEnt > $maxcode && $nBits < 12) {
        $nBits = $nBits + 1;
        if ($nBits == 12) {
            $maxcode = 4096;
        } else {
            $maxcode = (1 << $nBits) - 1;
        }
    }
    gd_gif_write_bits($writer, $endCode, $nBits);
    gd_gif_flush_bits($writer);

    return $writer->data;
}

// ── GIF 调色板颜色数 → 每像素位数 ──
function gd_gif_colors_to_bpp(int $colors): int
{
    if ($colors <= 2) { return 1; }
    if ($colors <= 4) { return 2; }
    if ($colors <= 8) { return 3; }
    if ($colors <= 16) { return 4; }
    if ($colors <= 32) { return 5; }
    if ($colors <= 64) { return 6; }
    if ($colors <= 128) { return 7; }
    return 8;
}

// ── GIF 解码实现 ──
//   解析 GIF 二进制数据为 GdImage（调色板图像）
//   仅解码第一帧；支持全局/局部调色板、GCE 透明色、隔行扫描
function gd_gif_decode_impl(string $data): GdImage|Exception
{
    $len = strlen($data);
    if ($len < 13) {
        throw new Exception("gd_decode_gif: data too short ($len bytes, need >= 13)");
    }

    // ── Header（6 字节）──
    $sig = substr($data, 0, 6);
    if ($sig != "GIF87a" && $sig != "GIF89a") {
        throw new Exception("gd_decode_gif: invalid signature (not GIF87a/GIF89a)");
    }

    $pos = 6;

    // ── Logical Screen Descriptor（7 字节）──
    $width  = ord(substr($data, $pos, 1)) | (ord(substr($data, $pos + 1, 1)) << 8);
    $height = ord(substr($data, $pos + 2, 1)) | (ord(substr($data, $pos + 3, 1)) << 8);
    $packed = ord(substr($data, $pos + 4, 1));
    $pos = $pos + 7;

    if ($width <= 0 || $height <= 0) {
        throw new Exception("gd_decode_gif: invalid dimensions (w=$width, h=$height)");
    }

    $hasGlobalPalette = ($packed & 0x80) ? 1 : 0;
    $gplBits = ($packed & 0x07) + 1;
    $gplSize = $hasGlobalPalette ? (1 << $gplBits) : 0;

    // ── 全局调色板 ──
    $globalPalette = [];
    $transparentIdx = -1;

    if ($hasGlobalPalette) {
        $i = 0;
        while ($i < $gplSize) {
            $r = ord(substr($data, $pos + $i * 3, 1));
            $g = ord(substr($data, $pos + $i * 3 + 1, 1));
            $b = ord(substr($data, $pos + $i * 3 + 2, 1));
            $globalPalette[$i] = gd_make_color($r, $g, $b, 0);
            $i = $i + 1;
        }
        $pos = $pos + $gplSize * 3;
    }

    // ── 解析块 ──
    GdImage $im = null;

    while ($pos < $len) {
        $blockType = ord(substr($data, $pos, 1));
        $pos = $pos + 1;

        if ($blockType == 0x3B) {
            // Trailer
            break;
        }

        if ($blockType == 0x21) {
            // Extension
            if ($pos >= $len) { break; }
            $label = ord(substr($data, $pos, 1));
            $pos = $pos + 1;

            if ($label == 0xF9) {
                // Graphic Control Extension
                if ($pos + 1 > $len) { break; }
                $blockSize = ord(substr($data, $pos, 1));
                $pos = $pos + 1;
                if ($pos + $blockSize > $len) { break; }
                $gcePacked = ord(substr($data, $pos, 1));
                $transparent = ord(substr($data, $pos + 3, 1));
                $pos = $pos + $blockSize;
                // 跳过块终止符
                if ($pos < $len) {
                    $term = ord(substr($data, $pos, 1));
                    $pos = $pos + 1;
                }
                if ($gcePacked & 0x01) {
                    $transparentIdx = $transparent;
                }
            } else {
                // 跳过其他扩展的子块
                while ($pos < $len) {
                    $subSize = ord(substr($data, $pos, 1));
                    $pos = $pos + 1;
                    if ($subSize == 0) { break; }
                    $pos = $pos + $subSize;
                }
            }
            continue;
        }

        if ($blockType == 0x2C) {
            // Image Descriptor
            if ($pos + 9 > $len) {
                throw new Exception("gd_decode_gif: truncated image descriptor");
            }

            $imgLeft   = ord(substr($data, $pos, 1)) | (ord(substr($data, $pos + 1, 1)) << 8);
            $imgTop    = ord(substr($data, $pos + 2, 1)) | (ord(substr($data, $pos + 3, 1)) << 8);
            $imgWidth  = ord(substr($data, $pos + 4, 1)) | (ord(substr($data, $pos + 5, 1)) << 8);
            $imgHeight = ord(substr($data, $pos + 6, 1)) | (ord(substr($data, $pos + 7, 1)) << 8);
            $imgPacked = ord(substr($data, $pos + 8, 1));
            $pos = $pos + 9;

            if ($imgWidth <= 0 || $imgHeight <= 0) {
                throw new Exception("gd_decode_gif: invalid image dimensions (w=$imgWidth, h=$imgHeight)");
            }

            $hasLocalPalette = ($imgPacked & 0x80) ? 1 : 0;
            $interlaced = ($imgPacked & 0x40) ? 1 : 0;
            $lplBits = ($imgPacked & 0x07) + 1;
            $lplSize = $hasLocalPalette ? (1 << $lplBits) : 0;

            // 选择调色板（局部优先）
            $palette = $globalPalette;
            if ($hasLocalPalette) {
                $palette = [];
                $i = 0;
                while ($i < $lplSize) {
                    $r = ord(substr($data, $pos + $i * 3, 1));
                    $g = ord(substr($data, $pos + $i * 3 + 1, 1));
                    $b = ord(substr($data, $pos + $i * 3 + 2, 1));
                    $palette[$i] = gd_make_color($r, $g, $b, 0);
                    $i = $i + 1;
                }
                $pos = $pos + $lplSize * 3;
            }

            if (count($palette) == 0) {
                throw new Exception("gd_decode_gif: no color table available");
            }

            // LZW 最小码宽
            if ($pos >= $len) {
                throw new Exception("gd_decode_gif: missing LZW minimum code size");
            }
            $minCodeSize = ord(substr($data, $pos, 1));
            $pos = $pos + 1;
            if ($minCodeSize < 2) { $minCodeSize = 2; }
            if ($minCodeSize > 8) { $minCodeSize = 8; }

            // 读取 LZW 数据子块
            $lzwData = "";
            while ($pos < $len) {
                $subSize = ord(substr($data, $pos, 1));
                $pos = $pos + 1;
                if ($subSize == 0) { break; }
                $lzwData .= substr($data, $pos, $subSize);
                $pos = $pos + $subSize;
            }

            // LZW 解码
            $indices = gd_gif_lzw_decode($lzwData, $minCodeSize, $imgWidth * $imgHeight);

            // 创建调色板图像
            $im = imagecreate($imgWidth, $imgHeight);
            $im->palette = $palette;
            $im->transparentColor = $transparentIdx;

            // 设置像素（处理隔行扫描）
            if ($interlaced) {
                // 构建隔行扫描行映射：输入行 → 输出行
                $yMap = [];
                $yIdx = 0;
                $row = 0;
                while ($row < $imgHeight) {
                    $yMap[$yIdx] = $row;
                    $yIdx = $yIdx + 1;
                    $row = $row + 8;
                }
                $row = 4;
                while ($row < $imgHeight) {
                    $yMap[$yIdx] = $row;
                    $yIdx = $yIdx + 1;
                    $row = $row + 8;
                }
                $row = 2;
                while ($row < $imgHeight) {
                    $yMap[$yIdx] = $row;
                    $yIdx = $yIdx + 1;
                    $row = $row + 4;
                }
                $row = 1;
                while ($row < $imgHeight) {
                    $yMap[$yIdx] = $row;
                    $yIdx = $yIdx + 1;
                    $row = $row + 2;
                }

                $srcRow = 0;
                while ($srcRow < $imgHeight) {
                    $dstY = $yMap[$srcRow];
                    $x = 0;
                    while ($x < $imgWidth) {
                        $p = intval($indices[$srcRow * $imgWidth + $x]);
                        gd_set_pixel_raw($im, $x, $dstY, $p);
                        $x = $x + 1;
                    }
                    $srcRow = $srcRow + 1;
                }
            } else {
                $y = 0;
                while ($y < $imgHeight) {
                    $x = 0;
                    while ($x < $imgWidth) {
                        $p = intval($indices[$y * $imgWidth + $x]);
                        gd_set_pixel_raw($im, $x, $y, $p);
                        $x = $x + 1;
                    }
                    $y = $y + 1;
                }
            }

            // 仅解码第一帧
            break;
        }

        // 未知块类型：跳过
    }

    if ($im === null) {
        throw new Exception("gd_decode_gif: no image data found");
    }

    return $im;
}

// ── GIF 编码实现 ──
//   将 GdImage 编码为 GIF 二进制字符串
//   真彩色图像先量化为调色板（imagetruecolortopalette）
//   支持透明色（GIF89a + GCE）
function gd_encode_gif(GdImage $image): string|Exception
{
    $w = $image->width;
    $h = $image->height;
    if ($w <= 0 || $h <= 0) {
        throw new Exception("gd_encode_gif: invalid dimensions (w=$w, h=$h)");
    }

    // 真彩色图像需先量化为调色板
    if ($image->trueColor) {
        imagetruecolortopalette($image, false, 256);
    }

    $paletteSize = count($image->palette);
    if ($paletteSize < 1) { $paletteSize = 1; }
    if ($paletteSize > 256) { $paletteSize = 256; }

    $bitsPerPixel = gd_gif_colors_to_bpp($paletteSize);
    $colorMapSize = 1 << $bitsPerPixel;
    $initCodeSize = ($bitsPerPixel <= 1) ? 2 : $bitsPerPixel;
    $hasTransparent = ($image->transparentColor >= 0 && $image->transparentColor < $paletteSize) ? 1 : 0;

    $s = "";

    // ── Header ──
    if ($hasTransparent) {
        $s .= "GIF89a";
    } else {
        $s .= "GIF87a";
    }

    // ── Logical Screen Descriptor ──
    $s .= chr($w & 0xFF) . chr(($w >> 8) & 0xFF);     // width LE
    $s .= chr($h & 0xFF) . chr(($h >> 8) & 0xFF);     // height LE
    // packed: bit7=全局调色板, bit6-4=色分辨率-1, bit3=排序, bit2-0=bpp-1
    $packed = 0x80 | (($bitsPerPixel - 1) << 4) | ($bitsPerPixel - 1);
    $s .= chr($packed);
    $s .= chr(0);     // background color index
    $s .= chr(0);     // pixel aspect ratio

    // ── 全局调色板 ──
    $i = 0;
    while ($i < $colorMapSize) {
        if ($i < $paletteSize) {
            $c = $image->palette[$i];
            if ($c != -1) {
                $s .= chr(gd_get_red($c));
                $s .= chr(gd_get_green($c));
                $s .= chr(gd_get_blue($c));
            } else {
                $s .= chr(0) . chr(0) . chr(0);
            }
        } else {
            $s .= chr(0) . chr(0) . chr(0);
        }
        $i = $i + 1;
    }

    // ── GCE（透明色）──
    if ($hasTransparent) {
        $s .= chr(0x21);     // extension introducer
        $s .= chr(0xF9);     // GCE label
        $s .= chr(4);        // block size
        $s .= chr(0x01);     // packed: transparent flag
        $s .= chr(0) . chr(0);  // delay (LE, 0)
        $s .= chr($image->transparentColor & 0xFF);  // transparent color index
        $s .= chr(0);        // block terminator
    }

    // ── Image Descriptor ──
    $s .= chr(0x2C);     // image separator
    $s .= chr(0) . chr(0);  // left (LE, 0)
    $s .= chr(0) . chr(0);  // top (LE, 0)
    $s .= chr($w & 0xFF) . chr(($w >> 8) & 0xFF);  // width LE
    $s .= chr($h & 0xFF) . chr(($h >> 8) & 0xFF);  // height LE
    $s .= chr(0x00);     // packed: 无局部调色板, 无隔行

    // ── LZW 数据 ──
    $s .= chr($initCodeSize);  // LZW minimum code size

    // 收集像素索引
    $pixels = [];
    $total = $w * $h;
    $y = 0;
    while ($y < $h) {
        $x = 0;
        while ($x < $w) {
            $pixels[$y * $w + $x] = gd_get_pixel_raw($image, $x, $y);
            $x = $x + 1;
        }
        $y = $y + 1;
    }

    $lzwData = gd_gif_lzw_encode($pixels, $total, $initCodeSize);

    // 写入子块（每块最大 255 字节，前 1 字节长度）
    $lzwLen = strlen($lzwData);
    $offset = 0;
    while ($offset < $lzwLen) {
        $chunk = 255;
        $remaining = $lzwLen - $offset;
        if ($remaining < 255) { $chunk = $remaining; }
        $s .= chr($chunk);
        $s .= substr($lzwData, $offset, $chunk);
        $offset = $offset + $chunk;
    }
    $s .= chr(0);  // 块终止符

    // ── Trailer ──
    $s .= chr(0x3B);

    return $s;
}

// ════════════════════════════════════════════════════════════
// GIF 公开 API
// ════════════════════════════════════════════════════════════

/**
 * imagecreatefromgif(string $filename): GdImage
 *
 * 从 GIF 文件创建图像（调色板）。
 * - 读取整个文件到内存，委托给 gd_decode_gif 解码
 * - 仅解码第一帧；支持全局/局部调色板、透明色、隔行扫描
 * - 文件无法打开 → throw Exception（I/O 错误）
 * - 格式不支持 → throw Exception（由 gd_decode_gif 抛出）
 */
function imagecreatefromgif(string $filename): GdImage|Exception
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) {
        throw new Exception("imagecreatefromgif: unable to open file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);

    // 获取文件大小
    C->fseek($f, c_int(0), c_int(2));  // SEEK_END
    $size = php_int(C->ftell($f));
    C->fseek($f, c_int(0), c_int(0));  // SEEK_SET

    // 逐字节读取整个文件到字符串
    $data = "";
    $i = 0;
    while ($i < $size) {
        $c = php_int(C->fgetc($f));
        if ($c < 0) { break; }
        $data .= chr($c);
        $i = $i + 1;
    }

    return gd_decode_gif($data);
}

/**
 * imagegif(GdImage $image, string $filename = ""): bool
 *
 * 将图像编码为 GIF 格式写入文件。
 * - 真彩色图像内部量化为调色板（imagetruecolortopalette）
 * - 支持透明色（GIF89a + GCE）
 * - $filename 为空 → throw Exception（不支持 stdout 输出）
 * - 文件无法创建 → throw Exception
 */
function imagegif(GdImage $image, string $filename = ""): bool|Exception
{
    if ($filename == "") {
        throw new Exception("imagegif: filename required");
    }
    $s = gd_encode_gif($image);
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) {
        throw new Exception("imagegif: unable to create file: " . $filename);
    }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    return true;
}
