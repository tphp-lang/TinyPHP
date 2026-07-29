<?php
// ext/gd 扩展测试 — Task 16（GIF 编解码，含 LZW）纯 phpc 实现验证
//
// 测试范围：
//   1. 调色板图像 imagegif → imagecreatefromgif 往返，验证像素索引一致
//   2. 真彩色图像 imagegif（内部量化）→ imagecreatefromgif 往返，验证 RGB 一致
//   3. 透明色支持（GIF89a + GCE）
//   4. 错误处理（文件不存在、空文件名）
#import gd
#flag -I__INC__

#debug === GD Task 16 GIF Codec Test ===
#debug
#debug -- Palette round-trip --
#debug 1. write: 1
#debug 2. read w/h: 4,4
#debug 3. pixel(0,0)=1
#debug 4. pixel(1,1)=2
#debug 5. pixel(2,2)=3
#debug 6. pixel(3,3)=1
#debug 7. pixel(0,1)=0
#debug 8. all match: 1
#debug
#debug -- TrueColor round-trip (quantized) --
#debug 9. write: 1
#debug 10. read w/h: 4,4
#debug 11. rgb(0,0)=0,224,0
#debug 12. rgb(1,1)=0,0,192
#debug 13. rgb(2,2)=224,0,0
#debug 14. all rgb match: 1
#debug
#debug -- Transparent color --
#debug 15. write: 1
#debug 16. read w/h: 4,4
#debug 17. transparent: 2
#debug 18. pixel(1,1)=2
#debug
#debug -- Error handling --
#debug 19. not-exist: caught1
#debug 20. empty: caught1
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 16 GIF Codec Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 调色板图像往返
        //    4 色：white(0)/red(1)/green(2)/blue(3)，bpp=2
        // ════════════════════════════════════════════════════════════
        echo "-- Palette round-trip --\n";

        $pal = imagecreate(4, 4);
        $white = imagecolorallocate($pal, 255, 255, 255);  // idx 0
        $red   = imagecolorallocate($pal, 255, 0, 0);      // idx 1
        $green = imagecolorallocate($pal, 0, 255, 0);      // idx 2
        $blue  = imagecolorallocate($pal, 0, 0, 255);      // idx 3
        imagefilledrectangle($pal, 0, 0, 3, 3, $white);
        imagesetpixel($pal, 0, 0, $red);
        imagesetpixel($pal, 1, 1, $green);
        imagesetpixel($pal, 2, 2, $blue);
        imagesetpixel($pal, 3, 3, $red);

        $ok = imagegif($pal, "test/gd/test_pal.gif");
        echo "1. write: " . ($ok ? "1" : "0") . "\n";

        $pal2 = imagecreatefromgif("test/gd/test_pal.gif");
        echo "2. read w/h: " . imagesx($pal2) . "," . imagesy($pal2) . "\n";
        echo "3. pixel(0,0)=" . imagecolorat($pal2, 0, 0) . "\n";
        echo "4. pixel(1,1)=" . imagecolorat($pal2, 1, 1) . "\n";
        echo "5. pixel(2,2)=" . imagecolorat($pal2, 2, 2) . "\n";
        echo "6. pixel(3,3)=" . imagecolorat($pal2, 3, 3) . "\n";
        echo "7. pixel(0,1)=" . imagecolorat($pal2, 0, 1) . "\n";

        // 全像素索引比对（$pal 未被 imagegif 修改）
        $allMatch = 1;
        $x = 0;
        while ($x < 4) {
            $y = 0;
            while ($y < 4) {
                $orig = imagecolorat($pal, $x, $y);
                $read = imagecolorat($pal2, $x, $y);
                if ($orig != $read) {
                    $allMatch = 0;
                }
                $y = $y + 1;
            }
            $x = $x + 1;
        }
        echo "8. all match: " . $allMatch . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. 真彩色图像往返（内部量化）
        //    imagegif 调用 imagetruecolortopalette 原地量化 $tc
        //    量化：R/G 3-bit (255→224)，B 2-bit (255→192)
        //    比较 $tc（量化后）与 $tc2（解码回）的 RGB
        // ════════════════════════════════════════════════════════════
        echo "\n-- TrueColor round-trip (quantized) --\n";

        $tc = imagecreatetruecolor(4, 4);
        imagealphablending($tc, false);
        $tcRed   = imagecolorallocate($tc, 255, 0, 0);
        $tcGreen = imagecolorallocate($tc, 0, 255, 0);
        $tcBlue  = imagecolorallocate($tc, 0, 0, 255);
        imagefilledrectangle($tc, 0, 0, 3, 3, $tcRed);
        imagesetpixel($tc, 0, 0, $tcGreen);
        imagesetpixel($tc, 1, 1, $tcBlue);

        // imagegif 内部量化 $tc（trueColor→false，palette 填充）
        $ok2 = imagegif($tc, "test/gd/test_tc.gif");
        echo "9. write: " . ($ok2 ? "1" : "0") . "\n";

        $tc2 = imagecreatefromgif("test/gd/test_tc.gif");
        echo "10. read w/h: " . imagesx($tc2) . "," . imagesy($tc2) . "\n";

        // 验证解码后 RGB（量化值：green→0,224,0 / blue→0,0,192 / red→224,0,0）
        $idx00 = imagecolorat($tc2, 0, 0);
        $col00 = $tc2->palette[$idx00];
        echo "11. rgb(0,0)=" . gd_get_red($col00) . "," . gd_get_green($col00) . "," . gd_get_blue($col00) . "\n";

        $idx11 = imagecolorat($tc2, 1, 1);
        $col11 = $tc2->palette[$idx11];
        echo "12. rgb(1,1)=" . gd_get_red($col11) . "," . gd_get_green($col11) . "," . gd_get_blue($col11) . "\n";

        $idx22 = imagecolorat($tc2, 2, 2);
        $col22 = $tc2->palette[$idx22];
        echo "13. rgb(2,2)=" . gd_get_red($col22) . "," . gd_get_green($col22) . "," . gd_get_blue($col22) . "\n";

        // 全像素 RGB 比对（$tc 已量化，$tc2 解码回）
        $allRgbMatch = 1;
        $x2 = 0;
        while ($x2 < 4) {
            $y2 = 0;
            while ($y2 < 4) {
                $origIdx = imagecolorat($tc, $x2, $y2);
                $origColor = $tc->palette[$origIdx];
                $decIdx = imagecolorat($tc2, $x2, $y2);
                $decColor = $tc2->palette[$decIdx];
                if (gd_get_red($origColor) != gd_get_red($decColor)) {
                    $allRgbMatch = 0;
                }
                if (gd_get_green($origColor) != gd_get_green($decColor)) {
                    $allRgbMatch = 0;
                }
                if (gd_get_blue($origColor) != gd_get_blue($decColor)) {
                    $allRgbMatch = 0;
                }
                $y2 = $y2 + 1;
            }
            $x2 = $x2 + 1;
        }
        echo "14. all rgb match: " . $allRgbMatch . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 透明色支持（GIF89a + GCE）
        // ════════════════════════════════════════════════════════════
        echo "\n-- Transparent color --\n";

        $tr = imagecreate(4, 4);
        $trWhite = imagecolorallocate($tr, 255, 255, 255);  // idx 0
        $trRed   = imagecolorallocate($tr, 255, 0, 0);      // idx 1
        $trGreen = imagecolorallocate($tr, 0, 255, 0);      // idx 2
        imagefilledrectangle($tr, 0, 0, 3, 3, $trWhite);
        imagesetpixel($tr, 0, 0, $trRed);
        imagesetpixel($tr, 1, 1, $trGreen);
        imagecolortransparent($tr, $trGreen);  // transparent idx = 2

        $ok3 = imagegif($tr, "test/gd/test_trans.gif");
        echo "15. write: " . ($ok3 ? "1" : "0") . "\n";

        $tr2 = imagecreatefromgif("test/gd/test_trans.gif");
        echo "16. read w/h: " . imagesx($tr2) . "," . imagesy($tr2) . "\n";
        echo "17. transparent: " . imagecolortransparent($tr2) . "\n";
        echo "18. pixel(1,1)=" . imagecolorat($tr2, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. 错误处理
        // ════════════════════════════════════════════════════════════
        echo "\n-- Error handling --\n";

        $caught1 = 0;
        try {
            imagecreatefromgif("no_such_gif_file.gif");
        } catch (Exception $e) {
            $caught1 = 1;
        }
        echo "19. not-exist: caught" . $caught1 . "\n";

        $caught2 = 0;
        try {
            imagegif($pal, "");
        } catch (Exception $e) {
            $caught2 = 1;
        }
        echo "20. empty: caught" . $caught2 . "\n";

        echo "\n=== All passed ===\n";
    }
}
