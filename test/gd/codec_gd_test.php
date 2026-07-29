<?php
// ext/gd 扩展测试 — Task 13（GD/GD2 编解码）纯 phpc 实现验证
//
// 测试范围：
//   1. 真彩色 imagegd → imagecreatefromgd 往返
//   2. 调色板 imagegd → imagecreatefromgd 往返
//   3. 真彩色 imagegd2 (RAW) → imagecreatefromgd2 往返
//   4. 调色板 imagegd2 (RAW) → imagecreatefromgd2 往返
//   5. imagecreatefromgd2part 部分解码
//   6. COMPRESSED 格式拒绝
#import gd

#debug === GD Task 13 Codec Test ===
#debug
#debug -- TrueColor GD round-trip --
#debug 1. encode tc: 1
#debug 2. decode tc: 1
#debug 3. tc w/h: 4x4
#debug 4. tc pixel(0,0): 16711680
#debug 5. tc pixel(1,1): 65280
#debug 6. tc pixel(3,3): 255
#debug 7. tc pixel(2,0): 16777215
#debug
#debug -- Palette GD round-trip --
#debug 8. encode pal: 1
#debug 9. decode pal: 1
#debug 10. pal w/h: 3x3
#debug 11. pal total: 3
#debug 12. pal pixel(0,0): 0
#debug 13. pal pixel(1,0): 1
#debug 14. pal pixel(2,2): 2
#debug 15. pal transparent: 1
#debug
#debug -- TrueColor GD2 RAW round-trip --
#debug 16. encode tc gd2: 1
#debug 17. decode tc gd2: 1
#debug 18. tc2 w/h: 4x4
#debug 19. tc2 pixel(0,0): 16711680
#debug 20. tc2 pixel(3,3): 255
#debug
#debug -- Palette GD2 RAW round-trip --
#debug 21. encode pal gd2: 1
#debug 22. decode pal gd2: 1
#debug 23. pal2 w/h: 3x3
#debug 24. pal2 pixel(0,0): 0
#debug 25. pal2 pixel(2,2): 2
#debug
#debug -- GD2 part decode --
#debug 26. part w/h: 2x2
#debug 27. part pixel(0,0): 65280
#debug 28. part pixel(1,1): 65280
#debug
#debug -- COMPRESSED reject --
#debug 29. compressed throws: 1
#debug
#debug -- Large chunk size GD2 --
#debug 30. encode tc gd2 cs=4096: 1
#debug 31. decode tc gd2 cs=4096: 1
#debug 32. tc3 pixel(1,2): 65280
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 13 Codec Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 真彩色 GD 往返
        // ════════════════════════════════════════════════════════════
        echo "-- TrueColor GD round-trip --\n";

        $tc = imagecreatetruecolor(4, 4);
        $red = imagecolorallocate($tc, 255, 0, 0);
        $green = imagecolorallocate($tc, 0, 255, 0);
        $blue = imagecolorallocate($tc, 0, 0, 255);
        $white = imagecolorallocate($tc, 255, 255, 255);
        imagefilledrectangle($tc, 0, 0, 3, 3, $red);
        imagefilledrectangle($tc, 1, 1, 2, 2, $green);
        imagefilledrectangle($tc, 3, 3, 3, 3, $blue);
        imagefilledrectangle($tc, 2, 0, 2, 0, $white);

        $r1 = imagegd($tc, "test/gd/test_tc.gd");
        echo "1. encode tc: " . ($r1 ? "1" : "0") . "\n";

        $tc2 = imagecreatefromgd("test/gd/test_tc.gd");
        echo "2. decode tc: " . ($tc2 !== null ? "1" : "0") . "\n";

        echo "3. tc w/h: " . imagesx($tc2) . "x" . imagesy($tc2) . "\n";
        echo "4. tc pixel(0,0): " . imagecolorat($tc2, 0, 0) . "\n";
        echo "5. tc pixel(1,1): " . imagecolorat($tc2, 1, 1) . "\n";
        echo "6. tc pixel(3,3): " . imagecolorat($tc2, 3, 3) . "\n";
        echo "7. tc pixel(2,0): " . imagecolorat($tc2, 2, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. 调色板 GD 往返
        // ════════════════════════════════════════════════════════════
        echo "\n-- Palette GD round-trip --\n";

        $pal = imagecreate(3, 3);
        $pRed = imagecolorallocate($pal, 255, 0, 0);
        $pGreen = imagecolorallocate($pal, 0, 255, 0);
        $pBlue = imagecolorallocate($pal, 0, 0, 255);
        imagefilledrectangle($pal, 0, 0, 2, 2, $pRed);
        imagefilledrectangle($pal, 1, 0, 1, 0, $pGreen);
        imagefilledrectangle($pal, 2, 2, 2, 2, $pBlue);
        imagecolortransparent($pal, $pGreen);

        $r2 = imagegd($pal, "test/gd/test_pal.gd");
        echo "8. encode pal: " . ($r2 ? "1" : "0") . "\n";

        $pal2 = imagecreatefromgd("test/gd/test_pal.gd");
        echo "9. decode pal: " . ($pal2 !== null ? "1" : "0") . "\n";

        echo "10. pal w/h: " . imagesx($pal2) . "x" . imagesy($pal2) . "\n";
        echo "11. pal total: " . imagecolorstotal($pal2) . "\n";
        echo "12. pal pixel(0,0): " . imagecolorat($pal2, 0, 0) . "\n";
        echo "13. pal pixel(1,0): " . imagecolorat($pal2, 1, 0) . "\n";
        echo "14. pal pixel(2,2): " . imagecolorat($pal2, 2, 2) . "\n";
        echo "15. pal transparent: " . imagecolortransparent($pal2) . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 真彩色 GD2 RAW 往返
        // ════════════════════════════════════════════════════════════
        echo "\n-- TrueColor GD2 RAW round-trip --\n";

        $r3 = imagegd2($tc, "test/gd/test_tc.gd2", 0, IMG_GD2_RAW);
        echo "16. encode tc gd2: " . ($r3 ? "1" : "0") . "\n";

        $tc3 = imagecreatefromgd2("test/gd/test_tc.gd2");
        echo "17. decode tc gd2: " . ($tc3 !== null ? "1" : "0") . "\n";

        echo "18. tc2 w/h: " . imagesx($tc3) . "x" . imagesy($tc3) . "\n";
        echo "19. tc2 pixel(0,0): " . imagecolorat($tc3, 0, 0) . "\n";
        echo "20. tc2 pixel(3,3): " . imagecolorat($tc3, 3, 3) . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. 调色板 GD2 RAW 往返
        // ════════════════════════════════════════════════════════════
        echo "\n-- Palette GD2 RAW round-trip --\n";

        $r4 = imagegd2($pal, "test/gd/test_pal.gd2", 0, IMG_GD2_RAW);
        echo "21. encode pal gd2: " . ($r4 ? "1" : "0") . "\n";

        $pal3 = imagecreatefromgd2("test/gd/test_pal.gd2");
        echo "22. decode pal gd2: " . ($pal3 !== null ? "1" : "0") . "\n";

        echo "23. pal2 w/h: " . imagesx($pal3) . "x" . imagesy($pal3) . "\n";
        echo "24. pal2 pixel(0,0): " . imagecolorat($pal3, 0, 0) . "\n";
        echo "25. pal2 pixel(2,2): " . imagecolorat($pal3, 2, 2) . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. GD2 部分解码
        // ════════════════════════════════════════════════════════════
        echo "\n-- GD2 part decode --\n";

        $part = imagecreatefromgd2part("test/gd/test_tc.gd2", 1, 1, 2, 2);
        echo "26. part w/h: " . imagesx($part) . "x" . imagesy($part) . "\n";
        echo "27. part pixel(0,0): " . imagecolorat($part, 0, 0) . "\n";
        echo "28. part pixel(1,1): " . imagecolorat($part, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. COMPRESSED 格式拒绝
        // ════════════════════════════════════════════════════════════
        echo "\n-- COMPRESSED reject --\n";

        $compThrown = 0;
        try {
            imagegd2($tc, "test/gd/test_comp.gd2", 0, IMG_GD2_COMPRESSED);
        } catch (Exception $e) {
            $compThrown = 1;
        }
        echo "29. compressed throws: " . $compThrown . "\n";

        // ════════════════════════════════════════════════════════════
        // 7. 大 chunk_size GD2 往返
        // ════════════════════════════════════════════════════════════
        echo "\n-- Large chunk size GD2 --\n";

        $r5 = imagegd2($tc, "test/gd/test_tc_large.gd2", 4096, IMG_GD2_RAW);
        echo "30. encode tc gd2 cs=4096: " . ($r5 ? "1" : "0") . "\n";

        $tc4 = imagecreatefromgd2("test/gd/test_tc_large.gd2");
        echo "31. decode tc gd2 cs=4096: " . ($tc4 !== null ? "1" : "0") . "\n";
        echo "32. tc3 pixel(1,2): " . imagecolorat($tc4, 1, 2) . "\n";

        echo "\n=== All passed ===\n";
    }
}
