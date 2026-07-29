<?php
// ext/gd/src/gd_codec_png.php — Task 17: PNG 编解码（基于 zlib）
//
// PNG 格式（RFC 2083）：
//   - 8 字节签名: \x89PNG\r\n\x1a\n
//   - Chunk: 4B length(BE) + 4B type + data + 4B CRC32(BE)
//   - IHDR(13B): width(4B BE), height(4B BE), bitDepth(1B),
//                 colorType(1B), compression(1B), filter(1B), interlace(1B)
//   - PLTE: 调色板（colorType=3），每项 3 字节 RGB
//   - tRNS: 透明色信息
//   - IDAT: zlib 压缩的像素数据（可能多个 IDAT chunk）
//   - IEND: 结束
//
// colorType: 0=灰度, 2=RGB, 3=调色板, 4=灰度+alpha, 6=RGBA
//
// 像素数据（IDAT 解压后）：
//   每行前 1 字节 filter type (0=None, 1=Sub, 2=Up, 3=Average, 4=Paeth)
//
// zlib 调用：
//   - 编码 IDAT: gzcompress($raw, $level)  → zlib 格式（RFC 1950）
//   - 解码 IDAT: gzuncompress($idatData)   → 解压
//
// 已知陷阱（参考 task 描述）：
//   1. 数组元素赋 int 报错 → 用 gd_get_pixel_raw()
//   2. (float)$arr[i] 对 int 返回 0 → 用 floatval()
//   3. int $x = $arr[...] 报错 → 去掉 int 声明

// 显式引入 zlib.h：CodeGenerator 的 zlib 自动检测只扫描主用户文件，
// 而 gzcompress/gzuncompress 由 GD 扩展内部调用（用户主文件只调 imagepng）。
// CodeGenerator 将 os/ 路径的 #include 排在 common.h 之后（与 ext/ 路径相同）。
#include __INC__ . "os/zlib.h"

// ════════════════════════════════════════════════════════════
// 内部辅助函数
// ════════════════════════════════════════════════════════════

// gd_png_u32be — 从字符串读取 4 字节大端无符号整数
function gd_png_u32be(string $s, int $pos): int
{
    int $b0 = ord(substr($s, $pos, 1));
    int $b1 = ord(substr($s, $pos + 1, 1));
    int $b2 = ord(substr($s, $pos + 2, 1));
    int $b3 = ord(substr($s, $pos + 3, 1));
    return ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
}

// gd_png_write_u32be — 将无符号 32 位整数编码为 4 字节大端字符串
function gd_png_write_u32be(int $v): string
{
    if ($v < 0) { $v = $v + 0x100000000; }
    return chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
}

// gd_png_paeth — Paeth 预测器（PNG filter 4 用）
function gd_png_paeth(int $a, int $b, int $c): int
{
    int $p = $a + $b - $c;
    int $pa = abs($p - $a);
    int $pb = abs($p - $b);
    int $pc = abs($p - $c);
    if ($pa <= $pb && $pa <= $pc) { return $a; }
    if ($pb <= $pc) { return $b; }
    return $c;
}

// gd_png_chunk — 构造一个 PNG chunk（length + type + data + CRC32）
//   CRC 覆盖 type + data
function gd_png_chunk(string $type, string $data): string
{
    int $len = strlen($data);
    $s = gd_png_write_u32be($len);
    $s .= $type . $data;
    int $crc = crc32($type . $data);
    if ($crc < 0) { $crc = $crc + 0x100000000; }
    $s .= chr(($crc >> 24) & 0xFF) . chr(($crc >> 16) & 0xFF) . chr(($crc >> 8) & 0xFF) . chr($crc & 0xFF);
    return $s;
}

// gd_png_alpha_to_png — PHP alpha(0=不透明,127=透明) → PNG alpha(0=透明,255=不透明)
function gd_png_alpha_to_png(int $php_a): int
{
    return intval((127 - $php_a) * 255 / 127);
}

// gd_png_alpha_to_php — PNG alpha(0=透明,255=不透明) → PHP alpha(0=不透明,127=透明)
function gd_png_alpha_to_php(int $png_a): int
{
    return intval(127 * (255 - $png_a) / 255);
}

// ════════════════════════════════════════════════════════════
// gd_png_decode_filters — 对解压后的像素数据执行 filter 逆变换
//
// 返回重构后的像素数据（不含 filter byte，每行恰好 bytesPerRow 字节）
// ════════════════════════════════════════════════════════════
function gd_png_decode_filters(string $raw, int $width, int $height, int $channels, int $bitDepth): string|Exception
{
    // 计算每行字节数（不含 filter byte）
    int $bitsPerRow = $width * $channels * $bitDepth;
    int $bytesPerRow = intval(($bitsPerRow + 7) / 8);
    // bpp = filter 用的"字节每像素"，对 sub-8-bit 调色板为 1
    int $bpp = intval(($channels * $bitDepth + 7) / 8);
    if ($bpp < 1) { $bpp = 1; }

    int $rowSize = 1 + $bytesPerRow;  // filter byte + pixel bytes

    $recon = "";
    int $y = 0;
    while ($y < $height) {
        int $rowStart = $y * $rowSize;
        int $filterType = ord(substr($raw, $rowStart, 1));
        int $dataStart = $rowStart + 1;
        int $prevRowStart = ($y - 1) * $bytesPerRow;

        $rowRecon = "";
        int $i = 0;
        while ($i < $bytesPerRow) {
            int $cur = ord(substr($raw, $dataStart + $i, 1));
            int $a = 0;
            int $b = 0;
            int $c = 0;

            if ($i >= $bpp) {
                $a = ord(substr($rowRecon, $i - $bpp, 1));
            }
            if ($y > 0) {
                $b = ord(substr($recon, $prevRowStart + $i, 1));
            }
            if ($i >= $bpp && $y > 0) {
                $c = ord(substr($recon, $prevRowStart + $i - $bpp, 1));
            }

            int $val = 0;
            if ($filterType == 0) {
                $val = $cur;
            } elseif ($filterType == 1) {
                $val = $cur + $a;
            } elseif ($filterType == 2) {
                $val = $cur + $b;
            } elseif ($filterType == 3) {
                $val = $cur + intval(intval($a + $b) / 2);
            } elseif ($filterType == 4) {
                $val = $cur + gd_png_paeth($a, $b, $c);
            } else {
                throw new Exception("gd_decode_png: invalid filter type $filterType at row $y");
            }
            $val = $val & 0xFF;
            $rowRecon .= chr($val);
            $i = $i + 1;
        }

        $recon .= $rowRecon;
        $y = $y + 1;
    }
    return $recon;
}

// ════════════════════════════════════════════════════════════
// gd_decode_png — 解码 PNG 二进制数据为 GdImage
//
// 支持的 colorType：
//   0 (灰度 8-bit)   → 真彩色，R=G=B=gray
//   2 (RGB 8-bit)    → 真彩色
//   3 (调色板 1/2/4/8-bit) → 调色板图像
//   4 (灰度+alpha 8-bit) → 真彩色
//   6 (RGBA 8-bit)   → 真彩色
//
// 不支持：16-bit 深度、Adam7 隔行扫描
// ════════════════════════════════════════════════════════════
function gd_decode_png(string $data): GdImage|Exception
{
    int $len = strlen($data);
    if ($len < 8) {
        throw new Exception("gd_decode_png: data too short ($len bytes)");
    }

    // ── 验证 PNG 签名 ──
    if (ord(substr($data, 0, 1)) != 0x89 || ord(substr($data, 1, 1)) != 0x50
        || ord(substr($data, 2, 1)) != 0x4E || ord(substr($data, 3, 1)) != 0x47
        || ord(substr($data, 4, 1)) != 0x0D || ord(substr($data, 5, 1)) != 0x0A
        || ord(substr($data, 6, 1)) != 0x1A || ord(substr($data, 7, 1)) != 0x0A) {
        throw new Exception("gd_decode_png: invalid PNG signature");
    }

    int $pos = 8;
    int $width = 0;
    int $height = 0;
    int $bitDepth = 0;
    int $colorType = 0;
    int $interlace = 0;
    $palette = [];
    $trns = "";
    int $hasTrns = 0;
    $idatData = "";
    int $gotIhdr = 0;

    // ── 解析 chunks ──
    while ($pos + 8 <= $len) {
        int $chunkLen = gd_png_u32be($data, $pos);
        $chunkTypeStr = substr($data, $pos + 4, 4);
        int $chunkDataStart = $pos + 8;

        if ($chunkDataStart + $chunkLen + 4 > $len) {
            throw new Exception("gd_decode_png: chunk extends beyond data");
        }

        if ($chunkTypeStr == "IHDR") {
            $width = gd_png_u32be($data, $chunkDataStart);
            $height = gd_png_u32be($data, $chunkDataStart + 4);
            $bitDepth = ord(substr($data, $chunkDataStart + 8, 1));
            $colorType = ord(substr($data, $chunkDataStart + 9, 1));
            // compression = byte 10 (必须为 0)
            // filter = byte 11 (必须为 0)
            $interlace = ord(substr($data, $chunkDataStart + 12, 1));
            $gotIhdr = 1;
        } elseif ($chunkTypeStr == "PLTE") {
            int $palEntries = intval($chunkLen / 3);
            int $pi = 0;
            while ($pi < $palEntries) {
                int $r = ord(substr($data, $chunkDataStart + $pi * 3, 1));
                int $g = ord(substr($data, $chunkDataStart + $pi * 3 + 1, 1));
                int $b = ord(substr($data, $chunkDataStart + $pi * 3 + 2, 1));
                $palette[$pi] = gd_make_color($r, $g, $b, 0);
                $pi = $pi + 1;
            }
        } elseif ($chunkTypeStr == "tRNS") {
            $trns = substr($data, $chunkDataStart, $chunkLen);
            $hasTrns = 1;
        } elseif ($chunkTypeStr == "IDAT") {
            $idatData .= substr($data, $chunkDataStart, $chunkLen);
        } elseif ($chunkTypeStr == "IEND") {
            break;
        }

        // 跳过 data + CRC
        $pos = $chunkDataStart + $chunkLen + 4;
    }

    // ── 参数校验 ──
    if ($gotIhdr == 0) {
        throw new Exception("gd_decode_png: no IHDR chunk");
    }
    if ($width <= 0 || $height <= 0) {
        throw new Exception("gd_decode_png: invalid dimensions ($width x $height)");
    }
    if ($interlace != 0) {
        throw new Exception("gd_decode_png: interlaced PNG not supported");
    }

    // 校验 bitDepth / colorType 组合
    int $channels = 0;
    if ($colorType == 0) {
        $channels = 1;
        if ($bitDepth != 8) {
            throw new Exception("gd_decode_png: unsupported bitDepth=$bitDepth for grayscale");
        }
    } elseif ($colorType == 2) {
        $channels = 3;
        if ($bitDepth != 8) {
            throw new Exception("gd_decode_png: unsupported bitDepth=$bitDepth for RGB");
        }
    } elseif ($colorType == 3) {
        $channels = 1;
        if ($bitDepth != 1 && $bitDepth != 2 && $bitDepth != 4 && $bitDepth != 8) {
            throw new Exception("gd_decode_png: unsupported bitDepth=$bitDepth for palette");
        }
    } elseif ($colorType == 4) {
        $channels = 2;
        if ($bitDepth != 8) {
            throw new Exception("gd_decode_png: unsupported bitDepth=$bitDepth for gray+alpha");
        }
    } elseif ($colorType == 6) {
        $channels = 4;
        if ($bitDepth != 8) {
            throw new Exception("gd_decode_png: unsupported bitDepth=$bitDepth for RGBA");
        }
    } else {
        throw new Exception("gd_decode_png: unsupported colorType=$colorType");
    }

    if (strlen($idatData) == 0) {
        throw new Exception("gd_decode_png: no IDAT data");
    }

    // ── 解压 IDAT ──
    $raw = gzuncompress($idatData);

    // ── filter 逆变换 ──
    $recon = gd_png_decode_filters($raw, $width, $height, $channels, $bitDepth);

    // ── 创建图像 ──
    int $bytesPerRow = intval(($width * $channels * $bitDepth + 7) / 8);
    int $hasAlpha = 0;

    if ($colorType == 3) {
        // 调色板图像
        $im = imagecreate($width, $height);
        $im->palette = $palette;

        // 应用 tRNS（调色板 alpha）
        if ($hasTrns) {
            int $trnsLen = strlen($trns);
            int $ti = 0;
            int $palCount = count($palette);
            while ($ti < $trnsLen && $ti < $palCount) {
                int $png_a = ord(substr($trns, $ti, 1));
                if ($png_a != 255) { $hasAlpha = 1; }
                int $php_a = gd_png_alpha_to_php($png_a);
                $oldColor = $palette[$ti];
                $im->palette[$ti] = gd_make_color(
                    gd_get_red($oldColor),
                    gd_get_green($oldColor),
                    gd_get_blue($oldColor),
                    $php_a
                );
                // 首个完全透明的调色板项设为 transparentColor
                if ($png_a == 0 && $im->transparentColor < 0) {
                    $im->transparentColor = $ti;
                }
                $ti = $ti + 1;
            }
        }
        if ($hasAlpha) {
            $im->saveAlpha = true;
        }
    } else {
        // 真彩色图像
        $im = imagecreatetruecolor($width, $height);
        if ($colorType == 6 || $colorType == 4) {
            $hasAlpha = 1;
        }
        if ($hasAlpha) {
            $im->alphaBlending = false;
            $im->saveAlpha = true;
        }
    }

    // ── 设置像素 ──
    int $y = 0;
    while ($y < $height) {
        int $x = 0;
        while ($x < $width) {
            if ($colorType == 2) {
                // RGB
                int $off = $y * $bytesPerRow + $x * 3;
                int $r = ord(substr($recon, $off, 1));
                int $g = ord(substr($recon, $off + 1, 1));
                int $b = ord(substr($recon, $off + 2, 1));
                gd_set_pixel_raw($im, $x, $y, gd_make_color($r, $g, $b, 0));
            } elseif ($colorType == 6) {
                // RGBA
                int $off = $y * $bytesPerRow + $x * 4;
                int $r = ord(substr($recon, $off, 1));
                int $g = ord(substr($recon, $off + 1, 1));
                int $b = ord(substr($recon, $off + 2, 1));
                int $png_a = ord(substr($recon, $off + 3, 1));
                int $php_a = gd_png_alpha_to_php($png_a);
                gd_set_pixel_raw($im, $x, $y, gd_make_color($r, $g, $b, $php_a));
            } elseif ($colorType == 0) {
                // 灰度
                int $off = $y * $bytesPerRow + $x;
                int $gray = ord(substr($recon, $off, 1));
                gd_set_pixel_raw($im, $x, $y, gd_make_color($gray, $gray, $gray, 0));
            } elseif ($colorType == 4) {
                // 灰度+alpha
                int $off = $y * $bytesPerRow + $x * 2;
                int $gray = ord(substr($recon, $off, 1));
                int $png_a = ord(substr($recon, $off + 1, 1));
                int $php_a = gd_png_alpha_to_php($png_a);
                gd_set_pixel_raw($im, $x, $y, gd_make_color($gray, $gray, $gray, $php_a));
            } elseif ($colorType == 3) {
                // 调色板
                if ($bitDepth == 8) {
                    int $off = $y * $bytesPerRow + $x;
                    int $idx = ord(substr($recon, $off, 1));
                    gd_set_pixel_raw($im, $x, $y, $idx);
                } else {
                    // sub-8-bit: 1/2/4 bit per pixel, MSB first
                    int $pixelsPerByte = 8 / $bitDepth;
                    int $byteIdx = intval($x / $pixelsPerByte);
                    int $bitIdx = $x % $pixelsPerByte;
                    int $shift = 8 - $bitDepth - $bitIdx * $bitDepth;
                    int $mask = (1 << $bitDepth) - 1;
                    int $byteVal = ord(substr($recon, $y * $bytesPerRow + $byteIdx, 1));
                    int $idx = ($byteVal >> $shift) & $mask;
                    gd_set_pixel_raw($im, $x, $y, $idx);
                }
            }
            $x = $x + 1;
        }
        $y = $y + 1;
    }

    return $im;
}

// ════════════════════════════════════════════════════════════
// gd_encode_png — 将 GdImage 编码为 PNG 二进制字符串
//
// 参数：
//   $quality: 压缩级别 (-1=默认, 0-9)
//   $filters: 过滤器位掩码（接受但简化为全部 None filter）
//
// 编码策略：
//   - 真彩色 + saveAlpha → colorType 6 (RGBA)
//   - 真彩色 + !saveAlpha → colorType 2 (RGB)
//   - 调色板 → colorType 3 (8-bit)
//   - 所有行使用 None filter (0)
//   - 不使用隔行扫描
// ════════════════════════════════════════════════════════════
function gd_encode_png(GdImage $image, int $quality = -1, int $filters = PNG_ALL_FILTERS): string|Exception
{
    int $w = $image->width;
    int $h = $image->height;
    if ($w <= 0 || $h <= 0) {
        throw new Exception("gd_encode_png: invalid dimensions ($w x $h)");
    }

    // ── 确定颜色类型 ──
    int $colorType = 0;
    int $channels = 0;
    int $bitDepth = 8;

    if ($image->trueColor) {
        if ($image->saveAlpha) {
            $colorType = 6;  // RGBA
            $channels = 4;
        } else {
            $colorType = 2;  // RGB
            $channels = 3;
        }
    } else {
        $colorType = 3;  // 调色板
        $channels = 1;
    }

    int $bytesPerRow = $w * $channels;

    // ── 构建原始像素数据（每行前加 0x00 filter byte）──
    $raw = "";
    int $y = 0;
    while ($y < $h) {
        $raw .= chr(0);  // filter type = None
        int $x = 0;
        while ($x < $w) {
            if ($image->trueColor) {
                int $c = gd_get_pixel_raw($image, $x, $y);
                int $r = gd_get_red($c);
                int $g = gd_get_green($c);
                int $b = gd_get_blue($c);
                $raw .= chr($r) . chr($g) . chr($b);
                if ($colorType == 6) {
                    int $php_a = gd_get_alpha($c);
                    int $png_a = gd_png_alpha_to_png($php_a);
                    $raw .= chr($png_a);
                }
            } else {
                // 调色板图像：写索引
                int $idx = gd_get_pixel_raw($image, $x, $y);
                $raw .= chr($idx & 0xFF);
            }
            $x = $x + 1;
        }
        $y = $y + 1;
    }

    // ── zlib 压缩 ──
    int $level = $quality;
    if ($level < -1) { $level = -1; }
    if ($level > 9) { $level = 9; }
    $compressed = gzcompress($raw, $level);

    // ── 构建 PNG 文件 ──
    $s = "";

    // PNG 签名
    $s .= chr(0x89) . "PNG" . chr(0x0D) . chr(0x0A) . chr(0x1A) . chr(0x0A);

    // IHDR chunk
    $ihdr = "";
    $ihdr .= gd_png_write_u32be($w);
    $ihdr .= gd_png_write_u32be($h);
    $ihdr .= chr($bitDepth);
    $ihdr .= chr($colorType);
    $ihdr .= chr(0);  // compression (always 0 = deflate)
    $ihdr .= chr(0);  // filter (always 0 = adaptive)
    $ihdr .= chr(0);  // interlace (0 = none)
    $s .= gd_png_chunk("IHDR", $ihdr);

    // PLTE chunk（调色板图像）
    if ($colorType == 3) {
        int $palSize = count($image->palette);
        $plte = "";
        int $hasTrns = 0;
        $trns = "";
        int $i = 0;
        while ($i < $palSize) {
            $c = $image->palette[$i];
            if ($c == -1) {
                // 已释放的槽位
                $plte .= chr(0) . chr(0) . chr(0);
                $trns .= chr(255);  // opaque
            } else {
                $plte .= chr(gd_get_red($c)) . chr(gd_get_green($c)) . chr(gd_get_blue($c));
                int $php_a = gd_get_alpha($c);
                int $png_a = gd_png_alpha_to_png($php_a);
                // transparentColor 对应的索引强制完全透明
                if ($image->transparentColor == $i) {
                    $png_a = 0;
                }
                if ($png_a != 255) { $hasTrns = 1; }
                $trns .= chr($png_a);
            }
            $i = $i + 1;
        }
        $s .= gd_png_chunk("PLTE", $plte);

        // tRNS chunk（有透明信息时才写）
        if ($hasTrns || $image->transparentColor >= 0) {
            $s .= gd_png_chunk("tRNS", $trns);
        }
    }

    // IDAT chunk
    $s .= gd_png_chunk("IDAT", $compressed);

    // IEND chunk
    $s .= gd_png_chunk("IEND", "");

    return $s;
}
