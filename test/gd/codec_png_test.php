<?php
// ext/gd 扩展测试 — Task 17（PNG 编解码）纯 phpc 实现验证
//
// 测试范围：
//   1. 真彩色 imagepng → imagecreatefrompng 往返，验证像素一致
//   2. 不同 quality 参数（0, 6, 9）
//   3. 调色板图像（colorType 3）往返
//   4. 错误处理（文件不存在）
#import gd

#debug === GD Task 17 PNG Codec Test ===
#debug
#debug -- TrueColor round-trip --
#debug 1. tc w/h: 4x4
#debug 2. tc pixel(0,0): 16711680
#debug 3. tc pixel(1,1): 65280
#debug 4. tc pixel(2,2): 255
#debug 5. tc pixel(3,3): 16777215
#debug 6. tc pixel(0,1): 0
#debug 7. all pixels match: 1
#debug
#debug -- Quality parameters --
#debug 8. q0 pixel(0,0): 16711680
#debug 9. q6 pixel(2,2): 255
#debug 10. q9 pixel(3,3): 16777215
#debug
#debug -- Palette round-trip --
#debug 11. pal w/h: 3x3
#debug 12. pal total: 3
#debug 13. pal pixel(0,0): 0
#debug 14. pal pixel(1,0): 1
#debug 15. pal pixel(2,2): 2
#debug 16. pal transparent: 1
#debug 17. pal all match: 1
#debug
#debug -- Error handling --
#debug 18. not-exist: caught1
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 17 PNG Codec Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 真彩色 PNG 往返
        // ════════════════════════════════════════════════════════════
        echo "-- TrueColor round-trip --\n";

        $tc = imagecreatetruecolor(4, 4);
        imagealphablending($tc, false);

        $red = imagecolorallocate($tc, 255, 0, 0);       // 0x00FF0000 = 16711680
        $green = imagecolorallocate($tc, 0, 255, 0);     // 0x0000FF00 = 65280
        $blue = imagecolorallocate($tc, 0, 0, 255);      // 0x000000FF = 255
        $white = imagecolorallocate($tc, 255, 255, 255); // 0x00FFFFFF = 16777215

        imagesetpixel($tc, 0, 0, $red);
        imagesetpixel($tc, 1, 1, $green);
        imagesetpixel($tc, 2, 2, $blue);
        imagesetpixel($tc, 3, 3, $white);
        // (0,1) 等保持默认黑色 (0)

        $r1 = imagepng($tc, "test/gd/test_tc.png");
        $tc2 = imagecreatefrompng("test/gd/test_tc.png");

        echo "1. tc w/h: " . imagesx($tc2) . "x" . imagesy($tc2) . "\n";
        echo "2. tc pixel(0,0): " . imagecolorat($tc2, 0, 0) . "\n";
        echo "3. tc pixel(1,1): " . imagecolorat($tc2, 1, 1) . "\n";
        echo "4. tc pixel(2,2): " . imagecolorat($tc2, 2, 2) . "\n";
        echo "5. tc pixel(3,3): " . imagecolorat($tc2, 3, 3) . "\n";
        echo "6. tc pixel(0,1): " . imagecolorat($tc2, 0, 1) . "\n";

        // 全像素扫描
        $allMatch = 1;
        int $y = 0;
        while ($y < 4) {
            int $x = 0;
            while ($x < 4) {
                if (imagecolorat($tc, $x, $y) != imagecolorat($tc2, $x, $y)) {
                    $allMatch = 0;
                }
                $x = $x + 1;
            }
            $y = $y + 1;
        }
        echo "7. all pixels match: " . $allMatch . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. 不同 quality 参数
        // ════════════════════════════════════════════════════════════
        echo "\n-- Quality parameters --\n";

        imagepng($tc, "test/gd/test_tc_q0.png", 0);
        imagepng($tc, "test/gd/test_tc_q6.png", 6);
        imagepng($tc, "test/gd/test_tc_q9.png", 9);

        $tc_q0 = imagecreatefrompng("test/gd/test_tc_q0.png");
        $tc_q6 = imagecreatefrompng("test/gd/test_tc_q6.png");
        $tc_q9 = imagecreatefrompng("test/gd/test_tc_q9.png");

        echo "8. q0 pixel(0,0): " . imagecolorat($tc_q0, 0, 0) . "\n";
        echo "9. q6 pixel(2,2): " . imagecolorat($tc_q6, 2, 2) . "\n";
        echo "10. q9 pixel(3,3): " . imagecolorat($tc_q9, 3, 3) . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 调色板图像（colorType 3）往返
        // ════════════════════════════════════════════════════════════
        echo "\n-- Palette round-trip --\n";

        $pal = imagecreate(3, 3);
        $pRed = imagecolorallocate($pal, 255, 0, 0);     // index 0
        $pGreen = imagecolorallocate($pal, 0, 255, 0);   // index 1
        $pBlue = imagecolorallocate($pal, 0, 0, 255);    // index 2
        imagefilledrectangle($pal, 0, 0, 2, 2, $pRed);
        imagesetpixel($pal, 1, 0, $pGreen);
        imagesetpixel($pal, 2, 2, $pBlue);
        imagecolortransparent($pal, $pGreen);

        imagepng($pal, "test/gd/test_pal.png");
        $pal2 = imagecreatefrompng("test/gd/test_pal.png");

        echo "11. pal w/h: " . imagesx($pal2) . "x" . imagesy($pal2) . "\n";
        echo "12. pal total: " . imagecolorstotal($pal2) . "\n";
        echo "13. pal pixel(0,0): " . imagecolorat($pal2, 0, 0) . "\n";
        echo "14. pal pixel(1,0): " . imagecolorat($pal2, 1, 0) . "\n";
        echo "15. pal pixel(2,2): " . imagecolorat($pal2, 2, 2) . "\n";
        echo "16. pal transparent: " . imagecolortransparent($pal2) . "\n";

        // 调色板全像素扫描
        $palMatch = 1;
        int $py = 0;
        while ($py < 3) {
            int $px = 0;
            while ($px < 3) {
                if (imagecolorat($pal, $px, $py) != imagecolorat($pal2, $px, $py)) {
                    $palMatch = 0;
                }
                $px = $px + 1;
            }
            $py = $py + 1;
        }
        echo "17. pal all match: " . $palMatch . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. 错误处理
        // ════════════════════════════════════════════════════════════
        echo "\n-- Error handling --\n";

        $errCaught = 0;
        try {
            imagecreatefrompng("test/gd/nonexistent.png");
        } catch (Exception $e) {
            $errCaught = 1;
        }
        echo "18. not-exist: caught" . $errCaught . "\n";

        echo "\n=== All passed ===\n";
    }
}
