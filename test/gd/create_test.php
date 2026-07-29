<?php
// ext/gd 扩展测试 — Task 2-3（图像创建与颜色管理）纯 phpc 实现验证
//
// 测试范围：
//   1. imagecreate / imagecreatetruecolor / imagedestroy / imagesx / imagesy / imageistruecolor
//   2. imagecolorallocate / imagecolorallocatealpha（真彩色 + 调色板）
//   3. imagecolorat / imagecolorsforindex 往返
//   4. imagecolorexact / imagecolorclosest / imagecolorresolve
//   5. imagecolordeallocate（槽位复用）
//   6. imagecolorset（修改调色板项）
//   7. imagecolorstotal / imagecolortransparent
//   8. imagepalettecopy
#import gd

#debug === GD Task 2-3 Test ===
#debug
#debug -- Image Creation --
#debug 1. palette: w=10 h=10 tc=0
#debug 2. truecolor: w=20 h=20 tc=1
#debug 3. create(0,0) throws: 1
#debug 4. create(-1,5) throws: 1
#debug 5. destroy: 1
#debug
#debug -- Color Allocate (TrueColor) --
#debug 6. tc red: 16711680
#debug 7. tc green: 65280
#debug 8. tc blue: 255
#debug 9. tc alpha red: 2147418112
#debug
#debug -- Color Allocate (Palette) --
#debug 10. pal red idx: 0
#debug 11. pal green idx: 1
#debug 12. pal blue idx: 2
#debug 13. pal total: 3
#debug
#debug -- Color Query --
#debug 14. tc at(0,0): 0
#debug 15. tc colorsfor(red): 255,0,0,0
#debug 16. pal at(0,0): 0
#debug
#debug -- Color Exact --
#debug 17. tc exact(255,0,0): 16711680
#debug 18. pal exact(0,255,0): 1
#debug 19. pal exact(128,128,128): -1
#debug
#debug -- Color Closest --
#debug 20. pal closest(250,5,5): 0
#debug
#debug -- Color Resolve --
#debug 21. pal resolve(255,0,0): 0
#debug 22. pal resolve(200,200,200): 3
#debug
#debug -- Transparent --
#debug 23. get transparent: -1
#debug 24. set transparent=5 old: -1
#debug 25. get transparent: 5
#debug
#debug -- Deallocate + Reuse --
#debug 26. dealloc idx 1: 1
#debug 27. dealloc idx 1 again: 0
#debug 28. realloc reuses idx 1: 1
#debug
#debug -- Color Set --
#debug 29. set idx 0: 1
#debug 30. colorsfor(0) after set: 128,128,128,0
#debug
#debug -- Palette Copy --
#debug 31. copy total: 4
#debug 32. copy transparent: 5
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 2-3 Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. 图像创建
        // ════════════════════════════════════════════════════════════
        echo "-- Image Creation --\n";

        $pal = imagecreate(10, 10);
        echo "1. palette: w=" . imagesx($pal) . " h=" . imagesy($pal) . " tc=" . ($pal->trueColor ? "1" : "0") . "\n";

        $tc = imagecreatetruecolor(20, 20);
        echo "2. truecolor: w=" . imagesx($tc) . " h=" . imagesy($tc) . " tc=" . ($tc->trueColor ? "1" : "0") . "\n";

        $bad1Thrown = 0;
        try {
            imagecreate(0, 0);
        } catch (Exception $e) {
            $bad1Thrown = 1;
        }
        echo "3. create(0,0) throws: " . $bad1Thrown . "\n";

        $bad2Thrown = 0;
        try {
            imagecreate(-1, 5);
        } catch (Exception $e) {
            $bad2Thrown = 1;
        }
        echo "4. create(-1,5) throws: " . $bad2Thrown . "\n";

        echo "5. destroy: " . (imagedestroy($pal) ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. 颜色分配（真彩色）
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Allocate (TrueColor) --\n";

        $tcRed = imagecolorallocate($tc, 255, 0, 0);
        echo "6. tc red: " . $tcRed . "\n";

        $tcGreen = imagecolorallocate($tc, 0, 255, 0);
        echo "7. tc green: " . $tcGreen . "\n";

        $tcBlue = imagecolorallocate($tc, 0, 0, 255);
        echo "8. tc blue: " . $tcBlue . "\n";

        $tcAlphaRed = imagecolorallocatealpha($tc, 255, 0, 0, 127);
        echo "9. tc alpha red: " . $tcAlphaRed . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 颜色分配（调色板）
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Allocate (Palette) --\n";

        $pal2 = imagecreate(10, 10);
        $pRed = imagecolorallocate($pal2, 255, 0, 0);
        echo "10. pal red idx: " . $pRed . "\n";

        $pGreen = imagecolorallocate($pal2, 0, 255, 0);
        echo "11. pal green idx: " . $pGreen . "\n";

        $pBlue = imagecolorallocate($pal2, 0, 0, 255);
        echo "12. pal blue idx: " . $pBlue . "\n";

        echo "13. pal total: " . imagecolorstotal($pal2) . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. 颜色查询
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Query --\n";

        // 真彩色：默认黑色像素 = 0
        $at00 = imagecolorat($tc, 0, 0);
        echo "14. tc at(0,0): " . $at00 . "\n";

        // 真彩色：查询红色分量
        $cfr = imagecolorsforindex($tc, $tcRed);
        echo "15. tc colorsfor(red): " . $cfr["red"] . "," . $cfr["green"] . "," . $cfr["blue"] . "," . $cfr["alpha"] . "\n";

        // 调色板：默认像素 = 索引 0
        $palAt = imagecolorat($pal2, 0, 0);
        echo "16. pal at(0,0): " . $palAt . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. 精确匹配
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Exact --\n";

        $tcExact = imagecolorexact($tc, 255, 0, 0);
        echo "17. tc exact(255,0,0): " . $tcExact . "\n";

        $palExact = imagecolorexact($pal2, 0, 255, 0);
        echo "18. pal exact(0,255,0): " . $palExact . "\n";

        $palNotFound = imagecolorexact($pal2, 128, 128, 128);
        echo "19. pal exact(128,128,128): " . $palNotFound . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. 最近颜色
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Closest --\n";

        // 调色板含 [0]=red(255,0,0) [1]=green(0,255,0) [2]=blue(0,0,255)
        // closest(250,5,5) → 距离 red 最近
        $closest = imagecolorclosest($pal2, 250, 5, 5);
        echo "20. pal closest(250,5,5): " . $closest . "\n";

        // ════════════════════════════════════════════════════════════
        // 7. 查找或分配
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Resolve --\n";

        // resolve(255,0,0) → 精确匹配索引 0
        $resolve1 = imagecolorresolve($pal2, 255, 0, 0);
        echo "21. pal resolve(255,0,0): " . $resolve1 . "\n";

        // resolve(200,200,200) → 未找到，分配新索引 3
        $resolve2 = imagecolorresolve($pal2, 200, 200, 200);
        echo "22. pal resolve(200,200,200): " . $resolve2 . "\n";

        // ════════════════════════════════════════════════════════════
        // 8. 透明色
        // ════════════════════════════════════════════════════════════
        echo "\n-- Transparent --\n";

        $t1 = imagecolortransparent($pal2);
        echo "23. get transparent: " . $t1 . "\n";

        $t2 = imagecolortransparent($pal2, 5);
        echo "24. set transparent=5 old: " . $t2 . "\n";

        $t3 = imagecolortransparent($pal2);
        echo "25. get transparent: " . $t3 . "\n";

        // ════════════════════════════════════════════════════════════
        // 9. 释放与复用
        // ════════════════════════════════════════════════════════════
        echo "\n-- Deallocate + Reuse --\n";

        $d1 = imagecolordeallocate($pal2, 1);
        echo "26. dealloc idx 1: " . ($d1 ? "1" : "0") . "\n";

        $d2 = imagecolordeallocate($pal2, 1);
        echo "27. dealloc idx 1 again: " . ($d2 ? "1" : "0") . "\n";

        // 重新分配应复用索引 1
        $reuse = imagecolorallocate($pal2, 100, 200, 50);
        echo "28. realloc reuses idx 1: " . $reuse . "\n";

        // ════════════════════════════════════════════════════════════
        // 10. 修改调色板项
        // ════════════════════════════════════════════════════════════
        echo "\n-- Color Set --\n";

        $s1 = imagecolorset($pal2, 0, 128, 128, 128);
        echo "29. set idx 0: " . ($s1 ? "1" : "0") . "\n";

        $cfi0 = imagecolorsforindex($pal2, 0);
        echo "30. colorsfor(0) after set: " . $cfi0["red"] . "," . $cfi0["green"] . "," . $cfi0["blue"] . "," . $cfi0["alpha"] . "\n";

        // ════════════════════════════════════════════════════════════
        // 11. 调色板复制
        // ════════════════════════════════════════════════════════════
        echo "\n-- Palette Copy --\n";

        $dst = imagecreate(5, 5);
        imagepalettecopy($dst, $pal2);
        echo "31. copy total: " . imagecolorstotal($dst) . "\n";
        echo "32. copy transparent: " . imagecolortransparent($dst) . "\n";

        echo "\n=== All passed ===\n";
    }
}
