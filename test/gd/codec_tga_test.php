<?php
// ext/gd 扩展测试 — Task 15（TGA 解码）纯 phpc 实现验证
//
// 测试范围：
//   1. 未压缩 24bpp bottom-up TGA（type 2）→ 像素验证
//   2. RLE 24bpp bottom-up TGA（type 10，RLE packets）→ 像素验证
//   3. 未压缩 32bpp top-down TGA（type 2，BGRA）→ 像素验证
//   4. RLE 24bpp raw packet（type 10，raw packets）→ 像素验证
//   5. 32bpp alpha 通道（alpha=255 不透明 / alpha=0 透明）
//   6. 错误处理：文件不存在 / 不支持 type 3 / 不支持 bpp 16
#import gd

#debug === GD Task 15 TGA Test ===
#debug
#debug -- Uncompressed 24bpp bottom-up --
#debug 1. width: 2
#debug 2. height: 2
#debug 3. pixel(0,0) red: 16711680
#debug 4. pixel(1,0) green: 65280
#debug 5. pixel(0,1) blue: 255
#debug 6. pixel(1,1) white: 16777215
#debug
#debug -- RLE 24bpp (RLE packets) --
#debug 7. width: 4
#debug 8. pixel(0,0) red: 16711680
#debug 9. pixel(1,0) red: 16711680
#debug 10. pixel(2,0) green: 65280
#debug 11. pixel(3,0) green: 65280
#debug
#debug -- Uncompressed 32bpp top-down --
#debug 12. width: 2
#debug 13. pixel(0,0) red: 16711680
#debug 14. pixel(1,0) green: 65280
#debug 15. pixel(0,1) blue: 255
#debug 16. pixel(1,1) white: 16777215
#debug
#debug -- RLE 24bpp (raw packet) --
#debug 17. width: 4
#debug 18. pixel(0,0) red: 16711680
#debug 19. pixel(1,0) green: 65280
#debug 20. pixel(2,0) blue: 255
#debug 21. pixel(3,0) white: 16777215
#debug
#debug -- 32bpp alpha channel --
#debug 22. opaque red pixel: 16711680
#debug 23. transparent red pixel: 2147418112
#debug 24. opaque alpha: 0
#debug 25. transparent alpha: 127
#debug
#debug -- Error cases --
#debug 26. nonexistent file: caught1
#debug 27. unsupported type 3: caught1
#debug 28. unsupported bpp 16: caught1
#debug
#debug === All passed ===

// ════════════════════════════════════════════════════════════
// 辅助函数：构造 TGA 二进制数据并写入文件
// ════════════════════════════════════════════════════════════

// 写二进制数据到文件（参考 ext/exif exif_make_test_jpeg 模式）
function tga_write_file(string $filename, string $data): int
{
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) { return -1; }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($data);
    C->fwrite(c_str($data), c_int(1), c_int($len), $f);
    return 0;
}

// 构造 TGA 头部（18 字节，小端序）
//   $width/$height: 图像尺寸
//   $bpp: 24 或 32
//   $imageType: 2（未压缩）或 10（RLE）
//   $topDown: 1=top-down, 0=bottom-up
function tga_build_header(int $width, int $height, int $bpp, int $imageType, int $topDown): string
{
    $descriptor = $topDown ? 0x20 : 0x00;
    $s = "";
    $s .= chr(0);                                              // [0] ID length
    $s .= chr(0);                                              // [1] color map type (0=none)
    $s .= chr($imageType);                                     // [2] image type
    $s .= chr(0) . chr(0);                                     // [3-4] color map first entry (LE)
    $s .= chr(0) . chr(0);                                     // [5-6] color map length (LE)
    $s .= chr(0);                                              // [7] color map entry size
    $s .= chr(0) . chr(0);                                     // [8-9] x origin (LE)
    $s .= chr(0) . chr(0);                                     // [10-11] y origin (LE)
    $s .= chr($width & 0xFF) . chr(($width >> 8) & 0xFF);     // [12-13] width (LE)
    $s .= chr($height & 0xFF) . chr(($height >> 8) & 0xFF);   // [14-15] height (LE)
    $s .= chr($bpp);                                           // [16] bpp
    $s .= chr($descriptor);                                    // [17] image descriptor
    return $s;
}

// 构造 BGR 像素（24bpp）
function tga_pixel_bgr(int $r, int $g, int $b): string
{
    return chr($b) . chr($g) . chr($r);
}

// 构造 BGRA 像素（32bpp）
function tga_pixel_bgra(int $r, int $g, int $b, int $a): string
{
    return chr($b) . chr($g) . chr($r) . chr($a);
}

// ── Test 1: 未压缩 24bpp bottom-up 2x2 ──
//   图像: (0,0)=red (1,0)=green (0,1)=blue (1,1)=white
//   bottom-up: 数据行 0 = 图像行 1 (底部)
function tga_make_uncomp_24bpp_bottomup(string $filename): int
{
    $header = tga_build_header(2, 2, 24, 2, 0);
    $pixels = "";
    // 数据行 0 (image y=1): blue, white
    $pixels .= tga_pixel_bgr(0, 0, 255);      // blue
    $pixels .= tga_pixel_bgr(255, 255, 255);  // white
    // 数据行 1 (image y=0): red, green
    $pixels .= tga_pixel_bgr(255, 0, 0);      // red
    $pixels .= tga_pixel_bgr(0, 255, 0);      // green
    return tga_write_file($filename, $header . $pixels);
}

// ── Test 2: RLE 24bpp bottom-up 4x1 ──
//   图像: (0,0)=red (1,0)=red (2,0)=green (3,0)=green
//   RLE: 2x red (RLE packet), 2x green (RLE packet)
function tga_make_rle_24bpp(string $filename): int
{
    $header = tga_build_header(4, 1, 24, 10, 0);
    $data = "";
    // RLE packet: 2x red
    $data .= chr(0x80 | 1);                   // header: RLE, count=2
    $data .= tga_pixel_bgr(255, 0, 0);        // red
    // RLE packet: 2x green
    $data .= chr(0x80 | 1);                   // header: RLE, count=2
    $data .= tga_pixel_bgr(0, 255, 0);        // green
    return tga_write_file($filename, $header . $data);
}

// ── Test 3: 未压缩 32bpp top-down 2x2 ──
//   图像: (0,0)=red (1,0)=green (0,1)=blue (1,1)=white
//   全部 alpha=255 (不透明)
//   top-down: 数据行 0 = 图像行 0 (顶部)
function tga_make_uncomp_32bpp_topdown(string $filename): int
{
    $header = tga_build_header(2, 2, 32, 2, 1);
    $pixels = "";
    // 数据行 0 (image y=0): red, green
    $pixels .= tga_pixel_bgra(255, 0, 0, 255);      // red, opaque
    $pixels .= tga_pixel_bgra(0, 255, 0, 255);      // green, opaque
    // 数据行 1 (image y=1): blue, white
    $pixels .= tga_pixel_bgra(0, 0, 255, 255);      // blue, opaque
    $pixels .= tga_pixel_bgra(255, 255, 255, 255);  // white, opaque
    return tga_write_file($filename, $header . $pixels);
}

// ── Test 4: RLE 24bpp raw packet 4x1 ──
//   图像: (0,0)=red (1,0)=green (2,0)=blue (3,0)=white
//   Raw packet: 4 个不同像素
function tga_make_rle_raw_24bpp(string $filename): int
{
    $header = tga_build_header(4, 1, 24, 10, 0);
    $data = "";
    // Raw packet: 4 pixels
    $data .= chr(0x00 | 3);                   // header: raw, count=4
    $data .= tga_pixel_bgr(255, 0, 0);        // red
    $data .= tga_pixel_bgr(0, 255, 0);        // green
    $data .= tga_pixel_bgr(0, 0, 255);        // blue
    $data .= tga_pixel_bgr(255, 255, 255);    // white
    return tga_write_file($filename, $header . $data);
}

// ── Test 5: 32bpp alpha 通道 2x1 top-down ──
//   图像: (0,0)=red alpha=255 (不透明) (1,0)=red alpha=0 (透明)
function tga_make_alpha_32bpp(string $filename): int
{
    $header = tga_build_header(2, 1, 32, 2, 1);
    $pixels = "";
    $pixels .= tga_pixel_bgra(255, 0, 0, 255);  // red, opaque (alpha=255)
    $pixels .= tga_pixel_bgra(255, 0, 0, 0);    // red, transparent (alpha=0)
    return tga_write_file($filename, $header . $pixels);
}

// ── Test 7: 不支持的 image type 3 (灰度) ──
function tga_make_bad_type(string $filename): int
{
    $header = tga_build_header(1, 1, 8, 3, 0);
    $pixels = chr(128);  // 1 grayscale pixel
    return tga_write_file($filename, $header . $pixels);
}

// ── Test 8: 不支持的 bpp 16 ──
function tga_make_bad_bpp(string $filename): int
{
    $header = tga_build_header(1, 1, 16, 2, 0);
    $pixels = chr(0) . chr(0);  // 1 pixel, 2 bytes
    return tga_write_file($filename, $header . $pixels);
}

// ════════════════════════════════════════════════════════════
// 主测试类
// ════════════════════════════════════════════════════════════

class Main
{
    public function main(): void
    {
        echo "=== GD Task 15 TGA Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 未压缩 24bpp bottom-up 2x2
        // ════════════════════════════════════════════════════════════
        echo "-- Uncompressed 24bpp bottom-up --\n";

        tga_make_uncomp_24bpp_bottomup("test/fixtures/images/tga_test1.tga");
        $im = imagecreatefromtga("test/fixtures/images/tga_test1.tga");

        echo "1. width: " . imagesx($im) . "\n";
        echo "2. height: " . imagesy($im) . "\n";
        // bottom-up: (0,0)=red, (1,0)=green, (0,1)=blue, (1,1)=white
        echo "3. pixel(0,0) red: " . imagecolorat($im, 0, 0) . "\n";
        echo "4. pixel(1,0) green: " . imagecolorat($im, 1, 0) . "\n";
        echo "5. pixel(0,1) blue: " . imagecolorat($im, 0, 1) . "\n";
        echo "6. pixel(1,1) white: " . imagecolorat($im, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. RLE 24bpp (RLE packets) 4x1
        // ════════════════════════════════════════════════════════════
        echo "\n-- RLE 24bpp (RLE packets) --\n";

        tga_make_rle_24bpp("test/fixtures/images/tga_test2.tga");
        $im2 = imagecreatefromtga("test/fixtures/images/tga_test2.tga");

        echo "7. width: " . imagesx($im2) . "\n";
        echo "8. pixel(0,0) red: " . imagecolorat($im2, 0, 0) . "\n";
        echo "9. pixel(1,0) red: " . imagecolorat($im2, 1, 0) . "\n";
        echo "10. pixel(2,0) green: " . imagecolorat($im2, 2, 0) . "\n";
        echo "11. pixel(3,0) green: " . imagecolorat($im2, 3, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 未压缩 32bpp top-down 2x2
        // ════════════════════════════════════════════════════════════
        echo "\n-- Uncompressed 32bpp top-down --\n";

        tga_make_uncomp_32bpp_topdown("test/fixtures/images/tga_test3.tga");
        $im3 = imagecreatefromtga("test/fixtures/images/tga_test3.tga");

        echo "12. width: " . imagesx($im3) . "\n";
        // top-down: (0,0)=red, (1,0)=green, (0,1)=blue, (1,1)=white
        echo "13. pixel(0,0) red: " . imagecolorat($im3, 0, 0) . "\n";
        echo "14. pixel(1,0) green: " . imagecolorat($im3, 1, 0) . "\n";
        echo "15. pixel(0,1) blue: " . imagecolorat($im3, 0, 1) . "\n";
        echo "16. pixel(1,1) white: " . imagecolorat($im3, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. RLE 24bpp (raw packet) 4x1
        // ════════════════════════════════════════════════════════════
        echo "\n-- RLE 24bpp (raw packet) --\n";

        tga_make_rle_raw_24bpp("test/fixtures/images/tga_test4.tga");
        $im4 = imagecreatefromtga("test/fixtures/images/tga_test4.tga");

        echo "17. width: " . imagesx($im4) . "\n";
        echo "18. pixel(0,0) red: " . imagecolorat($im4, 0, 0) . "\n";
        echo "19. pixel(1,0) green: " . imagecolorat($im4, 1, 0) . "\n";
        echo "20. pixel(2,0) blue: " . imagecolorat($im4, 2, 0) . "\n";
        echo "21. pixel(3,0) white: " . imagecolorat($im4, 3, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. 32bpp alpha 通道
        // ════════════════════════════════════════════════════════════
        echo "\n-- 32bpp alpha channel --\n";

        tga_make_alpha_32bpp("test/fixtures/images/tga_test5.tga");
        $im5 = imagecreatefromtga("test/fixtures/images/tga_test5.tga");

        // (0,0) = red with alpha=255 → PHP alpha=0 (不透明)
        // (1,0) = red with alpha=0 → PHP alpha=127 (透明)
        $opaquePix = imagecolorat($im5, 0, 0);
        $transparentPix = imagecolorat($im5, 1, 0);
        echo "22. opaque red pixel: " . $opaquePix . "\n";
        echo "23. transparent red pixel: " . $transparentPix . "\n";

        $opaqueInfo = imagecolorsforindex($im5, $opaquePix);
        $transparentInfo = imagecolorsforindex($im5, $transparentPix);
        echo "24. opaque alpha: " . $opaqueInfo["alpha"] . "\n";
        echo "25. transparent alpha: " . $transparentInfo["alpha"] . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. 错误处理
        // ════════════════════════════════════════════════════════════
        echo "\n-- Error cases --\n";

        // 文件不存在
        $caught1 = 0;
        try {
            imagecreatefromtga("no_such_file.tga");
        } catch (Exception $e) {
            $caught1 = 1;
        }
        echo "26. nonexistent file: caught" . $caught1 . "\n";

        // 不支持的 image type 3 (灰度)
        tga_make_bad_type("test/fixtures/images/tga_test6.tga");
        $caught2 = 0;
        try {
            imagecreatefromtga("test/fixtures/images/tga_test6.tga");
        } catch (Exception $e) {
            $caught2 = 1;
        }
        echo "27. unsupported type 3: caught" . $caught2 . "\n";

        // 不支持的 bpp 16
        tga_make_bad_bpp("test/fixtures/images/tga_test7.tga");
        $caught3 = 0;
        try {
            imagecreatefromtga("test/fixtures/images/tga_test7.tga");
        } catch (Exception $e) {
            $caught3 = 1;
        }
        echo "28. unsupported bpp 16: caught" . $caught3 . "\n";

        echo "\n=== All passed ===\n";
    }
}
