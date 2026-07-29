<?php
// ext/gd 扩展测试 — Task 7（内置位图字体与文字绘制）纯 phpc 实现验证
//
// 测试范围：
//   Task 7.2: imagefontwidth / imagefontheight（字体 1-5 尺寸 + 非法字体抛 Exception）
//   Task 7.3: imagechar / imagecharup（水平/垂直单字符绘制，验证像素位置）
//   Task 7.4: imagestring / imagestringup（字符串绘制，验证字符间距）
//   Task 7.5: imageloadfont（生成 GDF 文件后加载并绘制）
#import gd

#debug === GD Task 7 Test (Fonts & Text) ===
#debug
#debug -- Font Dimensions --
#debug 1. font1: 5x8
#debug 2. font2: 6x13
#debug 3. font3: 7x13
#debug 4. font4: 8x16
#debug 5. font5: 9x15
#debug 6. font6 throws: 1
#debug
#debug -- imagechar (font 1, 'A') --
#debug 7. at(1,1): 16711680
#debug 8. at(0,0): 0
#debug 9. at(0,2): 16711680
#debug 10. at(0,4): 16711680
#debug 11. at(4,4): 0
#debug 12. at(6,6): 0
#debug
#debug -- imagecharup (font 1, 'A') --
#debug 13. at(2,14): 16711680
#debug 14. at(4,14): 16711680
#debug 15. at(0,0): 0
#debug
#debug -- imagestring (font 1, 'Hi') --
#debug 16. at(0,1): 16711680
#debug 17. at(7,1): 16711680
#debug 18. at(7,4): 16711680
#debug
#debug -- imagestringup (font 1, 'Hi') --
#debug 19. at(2,14): 16711680
#debug 20. at(4,4): 16711680
#debug
#debug -- All Fonts Draw --
#debug 21. font1 ok: 1
#debug 22. font2 ok: 1
#debug 23. font3 ok: 1
#debug 24. font4 ok: 1
#debug 25. font5 ok: 1
#debug
#debug -- imageloadfont --
#debug 26. loaded width: 3
#debug 27. loaded height: 3
#debug 28. loaded char A at(1,0): 16711680
#debug 29. loaded char A at(0,1): 16711680
#debug 30. loaded char A at(2,2): 16711680
#debug
#debug === All passed ===

// 辅助函数：构造 GDF 字体文件用于 imageloadfont 测试
//   nchars=1, offset=65('A'), width=3, height=3
//   字符 'A' 的位图（每字节一个像素，0/1）：
//     row 0: 0 1 0   (顶部一个点)
//     row 1: 1 1 1   (中间一行)
//     row 2: 1 0 1   (底部两个点)
function font_test_make_gdf(string $filename): int
{
    // 头部 16 字节（4 个 int32 LE）
    $nchars = 1;
    $offset = 65;
    $w = 3;
    $h = 3;
    $s = "";
    // nchars (LE)
    $s .= chr($nchars & 0xFF) . chr(($nchars >> 8) & 0xFF) . chr(($nchars >> 16) & 0xFF) . chr(($nchars >> 24) & 0xFF);
    // offset (LE)
    $s .= chr($offset & 0xFF) . chr(($offset >> 8) & 0xFF) . chr(($offset >> 16) & 0xFF) . chr(($offset >> 24) & 0xFF);
    // width (LE)
    $s .= chr($w & 0xFF) . chr(($w >> 8) & 0xFF) . chr(($w >> 16) & 0xFF) . chr(($w >> 24) & 0xFF);
    // height (LE)
    $s .= chr($h & 0xFF) . chr(($h >> 8) & 0xFF) . chr(($h >> 16) & 0xFF) . chr(($h >> 24) & 0xFF);

    // 位图数据：nchars * width * height = 1*3*3 = 9 字节
    // row 0: 0, 1, 0
    $s .= chr(0) . chr(1) . chr(0);
    // row 1: 1, 1, 1
    $s .= chr(1) . chr(1) . chr(1);
    // row 2: 1, 0, 1
    $s .= chr(1) . chr(0) . chr(1);

    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("wb")));
    if ($fp == 0) { return -1; }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);
    $len = strlen($s);
    C->fwrite(c_str($s), c_int(1), c_int($len), $f);
    return 0;
}

class Main
{
    public function main(): void
    {
        echo "=== GD Task 7 Test (Fonts & Text) ===\n\n";

        // ════════════════════════════════════════════════════════════
        // Task 7.2: 字体尺寸查询
        //   font 1 (tiny):     5x8
        //   font 2 (small):    6x13
        //   font 3 (mediumbold): 7x13
        //   font 4 (large):    8x16
        //   font 5 (giant):    9x15
        //   font 6: 非法 → 抛 Exception
        // ════════════════════════════════════════════════════════════
        echo "-- Font Dimensions --\n";
        echo "1. font1: " . imagefontwidth(1) . "x" . imagefontheight(1) . "\n";
        echo "2. font2: " . imagefontwidth(2) . "x" . imagefontheight(2) . "\n";
        echo "3. font3: " . imagefontwidth(3) . "x" . imagefontheight(3) . "\n";
        echo "4. font4: " . imagefontwidth(4) . "x" . imagefontheight(4) . "\n";
        echo "5. font5: " . imagefontwidth(5) . "x" . imagefontheight(5) . "\n";

        $font6Thrown = 0;
        try {
            imagefontwidth(6);
        } catch (Exception $e) {
            $font6Thrown = 1;
        }
        echo "6. font6 throws: " . $font6Thrown . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 7.3: imagechar 水平绘制
        //   字体 1 'A' (ASCII 65, 索引 65): [0,12,18,18,30,18,18,0], w=5, h=8
        //   位掩码约定: bit (w-1-x) 对应像素列 x（最左像素=最高位）
        //   在 (0,0) 绘制 'A'：
        //     row 0: 0   = 00000 → 无像素
        //     row 1: 12  = 01100 → x=1,2  (bit3, bit2 = 1)
        //     row 2: 18  = 10010 → x=0,3  (bit4, bit1 = 1)
        //     row 3: 18  = 10010 → x=0,3
        //     row 4: 30  = 11110 → x=0,1,2,3  (横杠)
        //     row 5: 18  = 10010 → x=0,3
        //     row 6: 18  = 10010 → x=0,3
        //     row 7: 0   → 无像素
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagechar (font 1, 'A') --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagechar($im, 1, 0, 0, "A", $red);
        echo "7. at(1,1): " . imagecolorat($im, 1, 1) . "\n";    // 12 bit3=1 → 红
        echo "8. at(0,0): " . imagecolorat($im, 0, 0) . "\n";    // 0 → 黑
        echo "9. at(0,2): " . imagecolorat($im, 0, 2) . "\n";    // 18 bit4=1 → 红
        echo "10. at(0,4): " . imagecolorat($im, 0, 4) . "\n";   // 30 bit4=1 → 红
        echo "11. at(4,4): " . imagecolorat($im, 4, 4) . "\n";   // 30 bit0=0 → 黑
        echo "12. at(6,6): " . imagecolorat($im, 6, 6) . "\n";   // 字符外 → 黑

        // ════════════════════════════════════════════════════════════
        // Task 7.3: imagecharup 垂直绘制（逆时针旋转 90°，从下往上）
        //   'A' at (0, 14): glyph[cy] bit (w-1-cx) → 像素 (x+cy, y-cx) = (cy, 14-cx)
        //   'A' = [0,12,18,18,30,18,18,0]
        //   cx=0 (bit4):
        //     cy=2: 18 bit4=1 → (2, 14)
        //     cy=4: 30 bit4=1 → (4, 14)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecharup (font 1, 'A') --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagecharup($im, 1, 0, 14, "A", $red);
        echo "13. at(2,14): " . imagecolorat($im, 2, 14) . "\n"; // cy=2 bit4=1 → 红
        echo "14. at(4,14): " . imagecolorat($im, 4, 14) . "\n"; // cy=4 bit4=1 → 红
        echo "15. at(0,0): " . imagecolorat($im, 0, 0) . "\n";   // 黑

        // ════════════════════════════════════════════════════════════
        // Task 7.4: imagestring 水平绘制字符串
        //   字体 1 "Hi" at (0, 0), width=5
        //   'H' at (0, 0): 索引 72, [0,18,18,30,18,18,18,0]
        //     row 1: 18=10010 → x=0,3 → at(0,1)=红
        //   'i' at (5, 0): 索引 105, [0,4,0,12,4,4,14,0]
        //     row 1: 4=00100 → x=2 → 全局 x=5+2=7, at(7,1)=红
        //     row 4: 4=00100 → x=2 → at(7,4)=红
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagestring (font 1, 'Hi') --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagestring($im, 1, 0, 0, "Hi", $red);
        echo "16. at(0,1): " . imagecolorat($im, 0, 1) . "\n";   // 'H' row1 x=0 → 红
        echo "17. at(7,1): " . imagecolorat($im, 7, 1) . "\n";   // 'i' row1 x=2 → 红
        echo "18. at(7,4): " . imagecolorat($im, 7, 4) . "\n";   // 'i' row4 x=2 → 红

        // ════════════════════════════════════════════════════════════
        // Task 7.4: imagestringup 垂直绘制字符串
        //   字体 1 "Hi" from (0, 14), height=8
        //   'H' at (0, 14): 同 imagecharup 测试 → at(2,14)=红
        //   'i' at (0, 14-8)=(0, 6): 索引 105, [0,0,4,0,4,4,4,14]
        //     cx=2 (bit2):
        //       cy=4: 4 bit2=1 → (0+4, 6-2)=(4, 4) → 红
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagestringup (font 1, 'Hi') --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagestringup($im, 1, 0, 14, "Hi", $red);
        echo "19. at(2,14): " . imagecolorat($im, 2, 14) . "\n"; // 'H' cy=2 bit4 → 红
        echo "20. at(4,4): " . imagecolorat($im, 4, 4) . "\n";  // 'i' cy=4 bit2 → 红

        // ════════════════════════════════════════════════════════════
        // 字体 1-5 都能正常绘制 'A'
        //   验证：每个字体绘制 'A' 后，字符区域内至少有一个前景色像素
        // ════════════════════════════════════════════════════════════
        echo "\n-- All Fonts Draw --\n";

        $fontIdx = 1;
        while ($fontIdx <= 5) {
            $fw = imagefontwidth($fontIdx);
            $fh = imagefontheight($fontIdx);
            $im = imagecreatetruecolor($fw + 4, $fh + 4);
            $white = imagecolorallocate($im, 255, 255, 255);
            imagechar($im, $fontIdx, 0, 0, "A", $white);
            // 扫描字符区域，统计前景色像素数
            $fgCount = 0;
            $yy = 0;
            while ($yy < $fh) {
                $xx = 0;
                while ($xx < $fw) {
                    if (imagecolorat($im, $xx, $yy) == $white) {
                        $fgCount = $fgCount + 1;
                    }
                    $xx = $xx + 1;
                }
                $yy = $yy + 1;
            }
            echo "" . ($fontIdx + 20) . ". font" . $fontIdx . " ok: " . ($fgCount > 0 ? "1" : "0") . "\n";
            $fontIdx = $fontIdx + 1;
        }

        // ════════════════════════════════════════════════════════════
        // Task 7.5: imageloadfont
        //   生成 GDF 文件 (nchars=1, offset=65, w=3, h=3)
        //   字符 'A' 位图:
        //     row 0: 0 1 0  → 位掩码 010 = 2
        //     row 1: 1 1 1  → 位掩码 111 = 7
        //     row 2: 1 0 1  → 位掩码 101 = 5
        //   加载后绘制 'A' at (0,0):
        //     row 0: 2=010 → x=1 → at(1,0)=红
        //     row 1: 7=111 → x=0,1,2 → at(0,1)=红
        //     row 2: 5=101 → x=0,2 → at(2,2)=红
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageloadfont --\n";
        $gdfFile = "font_test_custom.gdf";
        font_test_make_gdf($gdfFile);
        $customFont = imageloadfont($gdfFile);
        echo "26. loaded width: " . $customFont->width . "\n";
        echo "27. loaded height: " . $customFont->height . "\n";

        $im = imagecreatetruecolor(10, 10);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagechar($im, $customFont, 0, 0, "A", $red);
        echo "28. loaded char A at(1,0): " . imagecolorat($im, 1, 0) . "\n"; // row0 x=1 → 红
        echo "29. loaded char A at(0,1): " . imagecolorat($im, 0, 1) . "\n"; // row1 x=0 → 红
        echo "30. loaded char A at(2,2): " . imagecolorat($im, 2, 2) . "\n"; // row2 x=2 → 红

        echo "\n=== All passed ===\n";
    }
}
