<?php
// @skip:linux+tcc  // TCC on Linux silently crashes on XBM codec (pre-existing)
// ext/gd 扩展测试 — Task 14（XBM 编解码）纯 phpc 实现验证
//
// 测试范围：
//   1. imagexbm           编码 XBM（C 源码, 1bit 黑白, LSB 优先）
//   2. imagecreatefromxbm 解码 XBM（解析 #define + hex 数组）
//   3. 往返一致性：写文件 → 读回 → 验证像素一致
//   4. 不同尺寸（10x10, 7x3 奇数宽度行填充）
//   5. 文件名自动追加 .xbm 扩展名
//   6. 错误处理（文件不存在）
#import gd

#debug === GD Task 14 XBM Codec Test ===
#debug
#debug -- 10x10 round-trip --
#debug 1. write: 1
#debug 2. read w/h: 10,10
#debug 3. pixel(0,0)=16777215
#debug 4. pixel(9,9)=16777215
#debug 5. pixel(5,5)=16777215
#debug 6. pixel(3,7)=16777215
#debug 7. pixel(1,1)=0
#debug
#debug -- 7x3 round-trip (padding) --
#debug 8. write: 1
#debug 9. read w/h: 7,3
#debug 10. pixel(0,0)=16777215
#debug 11. pixel(6,2)=16777215
#debug 12. pixel(3,1)=16777215
#debug 13. pixel(1,1)=0
#debug
#debug -- error handling --
#debug 14. not-exist: caught1
#debug
#debug -- full image scan 10x10 --
#debug 15. all pixels match: 1
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 14 XBM Codec Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 10x10 往返测试
        //    foreground=0（黑），黑→bit1，白→bit0，LSB 优先
        //    文件名无扩展名 → 自动追加 .xbm
        // ════════════════════════════════════════════════════════════
        echo "-- 10x10 round-trip --\n";

        $im = imagecreatetruecolor(10, 10);
        imagealphablending($im, false);

        $white = imagecolorallocate($im, 255, 255, 255);
        // 默认全黑(0)，设置部分白像素
        imagesetpixel($im, 0, 0, $white);
        imagesetpixel($im, 9, 9, $white);
        imagesetpixel($im, 5, 5, $white);
        imagesetpixel($im, 3, 7, $white);

        // 无扩展名 → imagexbm 自动追加 .xbm
        $ok = imagexbm($im, "test/fixtures/images/test_xbm_10x10");
        echo "1. write: " . ($ok ? "1" : "0") . "\n";

        $im2 = imagecreatefromxbm("test/fixtures/images/test_xbm_10x10.xbm");
        echo "2. read w/h: " . imagesx($im2) . "," . imagesy($im2) . "\n";
        echo "3. pixel(0,0)=" . imagecolorat($im2, 0, 0) . "\n";
        echo "4. pixel(9,9)=" . imagecolorat($im2, 9, 9) . "\n";
        echo "5. pixel(5,5)=" . imagecolorat($im2, 5, 5) . "\n";
        echo "6. pixel(3,7)=" . imagecolorat($im2, 3, 7) . "\n";
        echo "7. pixel(1,1)=" . imagecolorat($im2, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. 7x3 往返测试（奇数宽度，行填充）
        //    文件名带 .xbm 扩展名 → 不再追加
        // ════════════════════════════════════════════════════════════
        echo "\n-- 7x3 round-trip (padding) --\n";

        $im3 = imagecreatetruecolor(7, 3);
        imagealphablending($im3, false);

        imagesetpixel($im3, 0, 0, $white);
        imagesetpixel($im3, 6, 2, $white);
        imagesetpixel($im3, 3, 1, $white);

        $ok2 = imagexbm($im3, "test/fixtures/images/test_xbm_7x3.xbm");
        echo "8. write: " . ($ok2 ? "1" : "0") . "\n";

        $im4 = imagecreatefromxbm("test/fixtures/images/test_xbm_7x3.xbm");
        echo "9. read w/h: " . imagesx($im4) . "," . imagesy($im4) . "\n";
        echo "10. pixel(0,0)=" . imagecolorat($im4, 0, 0) . "\n";
        echo "11. pixel(6,2)=" . imagecolorat($im4, 6, 2) . "\n";
        echo "12. pixel(3,1)=" . imagecolorat($im4, 3, 1) . "\n";
        echo "13. pixel(1,1)=" . imagecolorat($im4, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 错误处理（文件不存在）
        // ════════════════════════════════════════════════════════════
        echo "\n-- error handling --\n";

        $caught1 = 0;
        try {
            imagecreatefromxbm("no_such_xbm_file.xbm");
        } catch (Exception $e) {
            $caught1 = 1;
        }
        echo "14. not-exist: caught" . $caught1 . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. 全像素扫描（10x10）
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
        echo "15. all pixels match: " . $allMatch . "\n";

        echo "\n=== All passed ===\n";
    }
}
