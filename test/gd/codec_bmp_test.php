<?php
// ext/gd 扩展测试 — Task 12（BMP 编解码）纯 phpc 实现验证
//
// 测试范围：
//   1. imagebmp          编码 BMP（24bpp BI_RGB bottom-up）
//   2. imagecreatefrombmp 解码 BMP（24bpp BI_RGB）
//   3. 往返一致性：写文件 → 读回 → 验证像素一致
//   4. 不同尺寸（10x10, 20x15, 7x3）
//   5. compressed 参数（接受但忽略，始终写 BI_RGB）
//   6. 错误处理（文件不存在）
#import gd

#debug === GD Task 12 BMP Codec Test ===
#debug
#debug -- 10x10 round-trip --
#debug 1. write: 1
#debug 2. read w/h: 10,10
#debug 3. pixel(0,0)=16711680
#debug 4. pixel(9,9)=65280
#debug 5. pixel(5,5)=255
#debug 6. pixel(3,7)=16777215
#debug 7. pixel(1,1)=0
#debug
#debug -- 20x15 round-trip --
#debug 8. write: 1
#debug 9. read w/h: 20,15
#debug 10. pixel(0,0)=16711680
#debug 11. pixel(19,14)=65280
#debug 12. pixel(10,7)=255
#debug 13. pixel(5,3)=16777215
#debug
#debug -- 7x3 round-trip (padding) --
#debug 14. write: 1
#debug 15. read w/h: 7,3
#debug 16. pixel(0,0)=16711680
#debug 17. pixel(6,2)=65280
#debug 18. pixel(3,1)=16777215
#debug
#debug -- compressed flag --
#debug 19. write compressed=1: 1
#debug 20. read back: 10,10
#debug
#debug -- error handling --
#debug 21. not-exist: caught1
#debug
#debug -- full image scan 10x10 --
#debug 22. all pixels match: 1
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 12 BMP Codec Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 10x10 往返测试
        // ════════════════════════════════════════════════════════════
        echo "-- 10x10 round-trip --\n";

        $im = imagecreatetruecolor(10, 10);
        imagealphablending($im, false);

        $red   = imagecolorallocate($im, 255, 0, 0);
        $green = imagecolorallocate($im, 0, 255, 0);
        $blue  = imagecolorallocate($im, 0, 0, 255);
        $white = imagecolorallocate($im, 255, 255, 255);

        imagesetpixel($im, 0, 0, $red);
        imagesetpixel($im, 9, 9, $green);
        imagesetpixel($im, 5, 5, $blue);
        imagesetpixel($im, 3, 7, $white);
        // (1,1) 保持默认黑色

        $ok = imagebmp($im, "test/fixtures/images/test_bmp_10x10.bmp");
        echo "1. write: " . ($ok ? "1" : "0") . "\n";

        $im2 = imagecreatefrombmp("test/fixtures/images/test_bmp_10x10.bmp");
        echo "2. read w/h: " . imagesx($im2) . "," . imagesy($im2) . "\n";
        echo "3. pixel(0,0)=" . imagecolorat($im2, 0, 0) . "\n";
        echo "4. pixel(9,9)=" . imagecolorat($im2, 9, 9) . "\n";
        echo "5. pixel(5,5)=" . imagecolorat($im2, 5, 5) . "\n";
        echo "6. pixel(3,7)=" . imagecolorat($im2, 3, 7) . "\n";
        echo "7. pixel(1,1)=" . imagecolorat($im2, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. 20x15 往返测试（非正方形）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 20x15 round-trip --\n";

        $im3 = imagecreatetruecolor(20, 15);
        imagealphablending($im3, false);

        imagesetpixel($im3, 0, 0, $red);
        imagesetpixel($im3, 19, 14, $green);
        imagesetpixel($im3, 10, 7, $blue);
        imagesetpixel($im3, 5, 3, $white);

        $ok2 = imagebmp($im3, "test/fixtures/images/test_bmp_20x15.bmp");
        echo "8. write: " . ($ok2 ? "1" : "0") . "\n";

        $im4 = imagecreatefrombmp("test/fixtures/images/test_bmp_20x15.bmp");
        echo "9. read w/h: " . imagesx($im4) . "," . imagesy($im4) . "\n";
        echo "10. pixel(0,0)=" . imagecolorat($im4, 0, 0) . "\n";
        echo "11. pixel(19,14)=" . imagecolorat($im4, 19, 14) . "\n";
        echo "12. pixel(10,7)=" . imagecolorat($im4, 10, 7) . "\n";
        echo "13. pixel(5,3)=" . imagecolorat($im4, 5, 3) . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 7x3 往返测试（奇数宽度，需要行填充）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 7x3 round-trip (padding) --\n";

        $im5 = imagecreatetruecolor(7, 3);
        imagealphablending($im5, false);

        imagesetpixel($im5, 0, 0, $red);
        imagesetpixel($im5, 6, 2, $green);
        imagesetpixel($im5, 3, 1, $white);

        $ok3 = imagebmp($im5, "test/fixtures/images/test_bmp_7x3.bmp");
        echo "14. write: " . ($ok3 ? "1" : "0") . "\n";

        $im6 = imagecreatefrombmp("test/fixtures/images/test_bmp_7x3.bmp");
        echo "15. read w/h: " . imagesx($im6) . "," . imagesy($im6) . "\n";
        echo "16. pixel(0,0)=" . imagecolorat($im6, 0, 0) . "\n";
        echo "17. pixel(6,2)=" . imagecolorat($im6, 6, 2) . "\n";
        echo "18. pixel(3,1)=" . imagecolorat($im6, 3, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. compressed 参数测试（接受但忽略）
        // ════════════════════════════════════════════════════════════
        echo "\n-- compressed flag --\n";

        $ok4 = imagebmp($im, "test/fixtures/images/test_bmp_compressed.bmp", 1);
        echo "19. write compressed=1: " . ($ok4 ? "1" : "0") . "\n";

        $im7 = imagecreatefrombmp("test/fixtures/images/test_bmp_compressed.bmp");
        echo "20. read back: " . imagesx($im7) . "," . imagesy($im7) . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. 错误处理
        // ════════════════════════════════════════════════════════════
        echo "\n-- error handling --\n";

        $caught1 = 0;
        try {
            imagecreatefrombmp("no_such_bmp_file.bmp");
        } catch (Exception $e) {
            $caught1 = 1;
        }
        echo "21. not-exist: caught" . $caught1 . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. 全像素扫描（10x10）
        // ════════════════════════════════════════════════════════════
        echo "\n-- full image scan 10x10 --\n";

        $allMatch = 1;
        $x = 0;
        while ($x < 10) {
            $y = 0;
            while ($y < 10) {
                $orig = imagecolorat($im, $x, $y);
                $read = imagecolorat($im2, $x, $y);
                if ($orig != $read) {
                    $allMatch = 0;
                }
                $y = $y + 1;
            }
            $x = $x + 1;
        }
        echo "22. all pixels match: " . $allMatch . "\n";

        echo "\n=== All passed ===\n";
    }
}
