<?php
// ext/gd 扩展测试 — Task 8（图像复制与缩放）纯 phpc 实现验证
//
// 测试范围：
//   1. imagecopy          区域复制（直接覆盖，透明色跳过）
//   2. imagecopymerge     按百分比混合（pct=0/50/100）
//   3. imagecopymergegray 灰度混合
//   4. imagecopyresized   最近邻缩放（2x2 → 4x4）
//   5. imagecopyresampled 双线性插值缩放（2x2 → 4x4）
#import gd

#debug === GD Task 8 Test ===
#debug
#debug -- imagecopy --
#debug 1. copy red to dst: dst(0,0)=16711680
#debug 2. copy red to dst: dst(2,2)=0
#debug 3. copy red to dst: dst(0,2)=0
#debug 4. copy out-of-bounds: 1
#debug 5. copy transparent skip: dst(0,0)=0
#debug
#debug -- imagecopymerge --
#debug 6. merge pct=0: dst(0,0)=0
#debug 7. merge pct=50: dst(0,0)=8355711
#debug 8. merge pct=100: dst(0,0)=16777215
#debug 9. merge pct=150 clamp: dst(0,0)=16777215
#debug
#debug -- imagecopymergegray --
#debug 10. graymerge pct=50: dst(0,0)=9309710
#debug 11. graymerge pct=100: dst(0,0)=16711680
#debug
#debug -- imagecopyresized --
#debug 12. resize 2x2->4x4: dst(0,0)=16711680
#debug 13. resize 2x2->4x4: dst(2,0)=65280
#debug 14. resize 2x2->4x4: dst(0,2)=255
#debug 15. resize 2x2->4x4: dst(2,2)=16777215
#debug 16. resize 2x2->4x4: dst(1,1)=16711680
#debug
#debug -- imagecopyresampled --
#debug 17. resample 2x2->4x4: dst(0,0)=16711680
#debug 18. resample 2x2->4x4: dst(3,3)=16777215
#debug 19. resample 2x2->4x4: dst(1,0)=8421376
#debug 20. resample 2x2->4x4: dst(0,1)=8388736
#debug
#debug -- palette dst --
#debug 21. copy to palette: dst(0,0)=1
#debug 22. resize to palette: 1
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 8 Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. imagecopy — 区域复制
        // ════════════════════════════════════════════════════════════
        echo "-- imagecopy --\n";

        // 创建 4x4 红色 src 和 4x4 黑色 dst
        $src = imagecreatetruecolor(4, 4);
        $red = imagecolorallocate($src, 255, 0, 0);
        imagefilledrectangle($src, 0, 0, 3, 3, $red);

        $dst = imagecreatetruecolor(4, 4);
        imagecopy($dst, $src, 0, 0, 0, 0, 2, 2);

        echo "1. copy red to dst: dst(0,0)=" . imagecolorat($dst, 0, 0) . "\n";
        echo "2. copy red to dst: dst(2,2)=" . imagecolorat($dst, 2, 2) . "\n";
        echo "3. copy red to dst: dst(0,2)=" . imagecolorat($dst, 0, 2) . "\n";

        // 越界自动裁剪（不报错，返回 true）
        $r4 = imagecopy($dst, $src, 10, 10, 0, 0, 2, 2);
        echo "4. copy out-of-bounds: " . ($r4 ? "1" : "0") . "\n";

        // 透明色跳过
        $srcT = imagecreatetruecolor(4, 4);
        $redT = imagecolorallocate($srcT, 255, 0, 0);
        imagefilledrectangle($srcT, 0, 0, 3, 3, $redT);
        imagecolortransparent($srcT, $redT);

        $dstT = imagecreatetruecolor(4, 4);
        imagecopy($dstT, $srcT, 0, 0, 0, 0, 4, 4);
        echo "5. copy transparent skip: dst(0,0)=" . imagecolorat($dstT, 0, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. imagecopymerge — 按百分比混合
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecopymerge --\n";

        // pct=0：不混合，dst 不变
        $srcW = imagecreatetruecolor(4, 4);
        $white = imagecolorallocate($srcW, 255, 255, 255);
        imagefilledrectangle($srcW, 0, 0, 3, 3, $white);

        $dstM0 = imagecreatetruecolor(4, 4);
        imagecopymerge($dstM0, $srcW, 0, 0, 0, 0, 4, 4, 0);
        echo "6. merge pct=0: dst(0,0)=" . imagecolorat($dstM0, 0, 0) . "\n";

        // pct=50：白色源 × 50% + 黑色目标 × 50% = (127,127,127)
        $dstM50 = imagecreatetruecolor(4, 4);
        imagecopymerge($dstM50, $srcW, 0, 0, 0, 0, 4, 4, 50);
        echo "7. merge pct=50: dst(0,0)=" . imagecolorat($dstM50, 0, 0) . "\n";

        // pct=100：完全覆盖，dst = src = 白色
        $dstM100 = imagecreatetruecolor(4, 4);
        imagecopymerge($dstM100, $srcW, 0, 0, 0, 0, 4, 4, 100);
        echo "8. merge pct=100: dst(0,0)=" . imagecolorat($dstM100, 0, 0) . "\n";

        // pct=150 钳位到 100
        $dstM150 = imagecreatetruecolor(4, 4);
        imagecopymerge($dstM150, $srcW, 0, 0, 0, 0, 4, 4, 150);
        echo "9. merge pct=150 clamp: dst(0,0)=" . imagecolorat($dstM150, 0, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. imagecopymergegray — 灰度混合
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecopymergegray --\n";

        // src=红色(255,0,0)，dst=蓝色(0,0,255)，pct=50
        // gray = 0.299*0 + 0.587*0 + 0.114*255 = 29.07 → 29
        // outR = intval(255*0.5 + 29*0.5) = intval(142) = 142
        // outG = intval(0*0.5 + 29*0.5) = intval(14.5) = 14
        // outB = intval(0*0.5 + 29*0.5) = intval(14.5) = 14
        // color = (142<<16)|(14<<8)|14 = 9309710
        $srcRed = imagecreatetruecolor(4, 4);
        imagefilledrectangle($srcRed, 0, 0, 3, 3, $red);

        $dstBlue = imagecreatetruecolor(4, 4);
        $blue = imagecolorallocate($dstBlue, 0, 0, 255);
        imagefilledrectangle($dstBlue, 0, 0, 3, 3, $blue);

        imagecopymergegray($dstBlue, $srcRed, 0, 0, 0, 0, 4, 4, 50);
        echo "10. graymerge pct=50: dst(0,0)=" . imagecolorat($dstBlue, 0, 0) . "\n";

        // pct=100：等价于直接复制 src（灰度权重为 0）
        $dstBlue2 = imagecreatetruecolor(4, 4);
        imagefilledrectangle($dstBlue2, 0, 0, 3, 3, $blue);
        imagecopymergegray($dstBlue2, $srcRed, 0, 0, 0, 0, 4, 4, 100);
        echo "11. graymerge pct=100: dst(0,0)=" . imagecolorat($dstBlue2, 0, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. imagecopyresized — 最近邻缩放
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecopyresized --\n";

        // 2x2 src：左上红、右上绿、左下蓝、右下白
        $src2 = imagecreatetruecolor(2, 2);
        imagefilledrectangle($src2, 0, 0, 0, 0, $red);   // (0,0)=red
        imagefilledrectangle($src2, 1, 0, 1, 0, imagecolorallocate($src2, 0, 255, 0)); // (1,0)=green
        imagefilledrectangle($src2, 0, 1, 0, 1, imagecolorallocate($src2, 0, 0, 255)); // (0,1)=blue
        imagefilledrectangle($src2, 1, 1, 1, 1, $white);  // (1,1)=white

        // 缩放到 4x4：每个 src 像素映射到 2x2 dst 区域
        $dstR = imagecreatetruecolor(4, 4);
        imagecopyresized($dstR, $src2, 0, 0, 0, 0, 4, 4, 2, 2);

        echo "12. resize 2x2->4x4: dst(0,0)=" . imagecolorat($dstR, 0, 0) . "\n";
        echo "13. resize 2x2->4x4: dst(2,0)=" . imagecolorat($dstR, 2, 0) . "\n";
        echo "14. resize 2x2->4x4: dst(0,2)=" . imagecolorat($dstR, 0, 2) . "\n";
        echo "15. resize 2x2->4x4: dst(2,2)=" . imagecolorat($dstR, 2, 2) . "\n";
        echo "16. resize 2x2->4x4: dst(1,1)=" . imagecolorat($dstR, 1, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. imagecopyresampled — 双线性插值缩放
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecopyresampled --\n";

        // 使用相同的 2x2 src
        // dst(0,0): 反向映射到 src(0,0) = red
        // dst(3,3): 反向映射到 src(1,1) 边界钳位 = white
        // dst(1,0): 0.5*red + 0.5*green = (128,128,0) = 8421376
        // dst(0,1): 0.5*red + 0.5*blue = (128,0,128) = 8388736
        $dstS = imagecreatetruecolor(4, 4);
        imagecopyresampled($dstS, $src2, 0, 0, 0, 0, 4, 4, 2, 2);

        echo "17. resample 2x2->4x4: dst(0,0)=" . imagecolorat($dstS, 0, 0) . "\n";
        echo "18. resample 2x2->4x4: dst(3,3)=" . imagecolorat($dstS, 3, 3) . "\n";
        echo "19. resample 2x2->4x4: dst(1,0)=" . imagecolorat($dstS, 1, 0) . "\n";
        echo "20. resample 2x2->4x4: dst(0,1)=" . imagecolorat($dstS, 0, 1) . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. 调色板目标图像测试
        // ════════════════════════════════════════════════════════════
        echo "\n-- palette dst --\n";

        // imagecopy 到调色板图像
        $dstPal = imagecreate(4, 4);
        imagecolorallocate($dstPal, 0, 0, 0); // idx 0 = black
        imagecopy($dstPal, $src, 0, 0, 0, 0, 2, 2);
        // 调色板图像返回的是索引，不是颜色值
        // src 是红色，会通过 imagecolorresolvealpha 分配新索引
        // idx 1 = red, pixels[0] = 1
        echo "21. copy to palette: dst(0,0)=" . imagecolorat($dstPal, 0, 0) . "\n";

        // imagecopyresized 到调色板图像（回退到最近邻）
        $dstPalR = imagecreate(4, 4);
        imagecolorallocate($dstPalR, 0, 0, 0); // idx 0 = black
        $r22 = imagecopyresized($dstPalR, $src2, 0, 0, 0, 0, 4, 4, 2, 2);
        echo "22. resize to palette: " . ($r22 ? "1" : "0") . "\n";

        echo "\n=== All passed ===\n";
    }
}
