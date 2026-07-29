<?php
// ext/gd 扩展测试 — Task 9（图像变换）纯 phpc 实现验证
//
// 测试范围：
//   1. imageflip — 三种翻转模式（水平/垂直/双向）
//   2. imagerotate — 90/180/45 度旋转
//   3. imagecrop — 矩形裁剪
//   4. imagecropauto — 各裁剪模式（BLACK/WHITE/SIDES/THRESHOLD）
//   5. imagescale — 放大与缩小
//   6. imageaffine — 平移与缩放仿射变换
//   7. imageaffinematrixget / imageaffinematrixconcat — 矩阵生成与连接
#import gd

#debug === GD Task 9 Test (Transform) ===
#debug
#debug -- imageflip --
#debug 1. flip H (0,0): OK
#debug 1. flip H (3,0): OK
#debug 1. flip H (0,3): OK
#debug 1. flip H (3,3): OK
#debug 2. flip V (0,0): OK
#debug 2. flip V (3,0): OK
#debug 2. flip V (0,3): OK
#debug 2. flip V (3,3): OK
#debug 3. flip B (0,0): OK
#debug 3. flip B (3,0): OK
#debug 3. flip B (0,3): OK
#debug 3. flip B (3,3): OK
#debug 4. flip unknown: OK
#debug
#debug -- imagerotate --
#debug 5. rotate 180 w: OK
#debug 5. rotate 180 h: OK
#debug 5. rotate 180 (0,0): OK
#debug 5. rotate 180 (3,3): OK
#debug 6. rotate 90 w: OK
#debug 6. rotate 90 h: OK
#debug 6. rotate 90 (0,0): OK
#debug 6. rotate 90 (0,3): OK
#debug 7. rotate 45 w: OK
#debug 7. rotate 45 h: OK
#debug 7. rotate 45 corner: OK
#debug
#debug -- imagecrop --
#debug 8. crop w: OK
#debug 8. crop h: OK
#debug 8. crop (0,0): OK
#debug 8. crop (1,0): OK
#debug 8. crop (0,1): OK
#debug 8. crop (1,1): OK
#debug 9. crop invalid throws: OK
#debug
#debug -- imagecropauto --
#debug 10. crop BLACK w: OK
#debug 10. crop BLACK h: OK
#debug 10. crop BLACK (0,0): OK
#debug 11. crop WHITE w: OK
#debug 11. crop WHITE h: OK
#debug 11. crop WHITE (0,0): OK
#debug 12. crop SIDES w: OK
#debug 12. crop SIDES h: OK
#debug 12. crop SIDES (0,0): OK
#debug 13. crop THRESHOLD w: OK
#debug 13. crop THRESHOLD h: OK
#debug 13. crop THRESHOLD (0,0): OK
#debug
#debug -- imagescale --
#debug 14. scale up w: OK
#debug 14. scale up h: OK
#debug 14. scale up (0,0): OK
#debug 15. scale down w: OK
#debug 15. scale down h: OK
#debug 15. scale down (0,0): OK
#debug
#debug -- imageaffine --
#debug 16. affine translate w: OK
#debug 16. affine translate h: OK
#debug 16. affine translate (0,0): OK
#debug 16. affine translate (1,0): OK
#debug 17. affine scale w: OK
#debug 17. affine scale h: OK
#debug 17. affine scale (0,0): OK
#debug 17. affine scale (6,6): OK
#debug 18. affine singular throws: OK
#debug
#debug -- imageaffinematrixget --
#debug 19. TRANSLATE a: OK
#debug 19. TRANSLATE b: OK
#debug 19. TRANSLATE c: OK
#debug 19. TRANSLATE d: OK
#debug 19. TRANSLATE e: OK
#debug 19. TRANSLATE f: OK
#debug 20. SCALE a: OK
#debug 20. SCALE b: OK
#debug 20. SCALE c: OK
#debug 20. SCALE d: OK
#debug 20. SCALE e: OK
#debug 20. SCALE f: OK
#debug 21. ROTATE a: OK
#debug 21. ROTATE b: OK
#debug 21. ROTATE c: OK
#debug 21. ROTATE d: OK
#debug 21. ROTATE e: OK
#debug 21. ROTATE f: OK
#debug
#debug -- imageaffinematrixconcat --
#debug 22. CONCAT a: OK
#debug 22. CONCAT b: OK
#debug 22. CONCAT c: OK
#debug 22. CONCAT d: OK
#debug 22. CONCAT e: OK
#debug 22. CONCAT f: OK
#debug
#debug === All passed ===

class Main
{
    public int $failCount = 0;

    public function checkInt(int $actual, int $expected, string $label): void
    {
        if ($actual == $expected) {
            echo $label . ": OK\n";
        } else {
            echo $label . ": FAIL (expected=" . $expected . " actual=" . $actual . ")\n";
            $this->failCount = $this->failCount + 1;
        }
    }

    public function main(): void
    {
        echo "=== GD Task 9 Test (Transform) ===\n\n";

        // 颜色常量（真彩色 imagecolorallocate 返回 0x00RRGGBB）
        // red=16711680 green=65280 blue=255 white=16777215

        // ════════════════════════════════════════════════════════════
        // 1. imageflip — 三种翻转模式
        // ════════════════════════════════════════════════════════════
        echo "-- imageflip --\n";

        // 创建 4x4 测试图像，四角设置不同颜色
        $im = imagecreatetruecolor(4, 4);
        $red = imagecolorallocate($im, 255, 0, 0);       // 16711680
        $green = imagecolorallocate($im, 0, 255, 0);     // 65280
        $blue = imagecolorallocate($im, 0, 0, 255);      // 255
        $white = imagecolorallocate($im, 255, 255, 255); // 16777215

        imagesetpixel($im, 0, 0, $red);
        imagesetpixel($im, 3, 0, $green);
        imagesetpixel($im, 0, 3, $blue);
        imagesetpixel($im, 3, 3, $white);

        // 水平翻转：左右镜像
        imageflip($im, IMG_FLIP_HORIZONTAL);
        // 翻转后: (0,0)=green(65280) (3,0)=red(16711680) (0,3)=white(16777215) (3,3)=blue(255)
        $this->checkInt(imagecolorat($im, 0, 0), 65280, "1. flip H (0,0)");
        $this->checkInt(imagecolorat($im, 3, 0), 16711680, "1. flip H (3,0)");
        $this->checkInt(imagecolorat($im, 0, 3), 16777215, "1. flip H (0,3)");
        $this->checkInt(imagecolorat($im, 3, 3), 255, "1. flip H (3,3)");

        // 还原后垂直翻转：上下镜像
        imageflip($im, IMG_FLIP_HORIZONTAL);
        imageflip($im, IMG_FLIP_VERTICAL);
        // 还原后 (0,0)=red(16711680) (3,0)=green(65280) (0,3)=blue(255) (3,3)=white(16777215)
        // flip V 后: (0,0)←(0,3)=blue(255) (3,0)←(3,3)=white(16777215) (0,3)←(0,0)=red(16711680) (3,3)←(3,0)=green(65280)
        $this->checkInt(imagecolorat($im, 0, 0), 255, "2. flip V (0,0)");
        $this->checkInt(imagecolorat($im, 3, 0), 16777215, "2. flip V (3,0)");
        $this->checkInt(imagecolorat($im, 0, 3), 16711680, "2. flip V (0,3)");
        $this->checkInt(imagecolorat($im, 3, 3), 65280, "2. flip V (3,3)");

        // 还原后双向翻转（等价 180° 旋转）
        imageflip($im, IMG_FLIP_VERTICAL);
        imageflip($im, IMG_FLIP_BOTH);
        // 还原后 (0,0)=red(16711680) (3,0)=green(65280) (0,3)=blue(255) (3,3)=white(16777215)
        // flip B 后: (0,0)←(3,3)=white(16777215) (3,0)←(0,3)=blue(255) (0,3)←(3,0)=green(65280) (3,3)←(0,0)=red(16711680)
        $this->checkInt(imagecolorat($im, 0, 0), 16777215, "3. flip B (0,0)");
        $this->checkInt(imagecolorat($im, 3, 0), 255, "3. flip B (3,0)");
        $this->checkInt(imagecolorat($im, 0, 3), 65280, "3. flip B (0,3)");
        $this->checkInt(imagecolorat($im, 3, 3), 16711680, "3. flip B (3,3)");

        // 未知 mode 返回 false
        $r = imageflip($im, 99);
        $this->checkInt($r ? 1 : 0, 0, "4. flip unknown");

        // ════════════════════════════════════════════════════════════
        // 2. imagerotate — 90/180/45 度旋转
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagerotate --\n";

        // 重新创建 4x4 测试图像（四角不同颜色）
        $im2 = imagecreatetruecolor(4, 4);
        imagesetpixel($im2, 0, 0, $red);
        imagesetpixel($im2, 3, 0, $green);
        imagesetpixel($im2, 0, 3, $blue);
        imagesetpixel($im2, 3, 3, $white);

        // 180 度旋转：4x4 → 4x4
        //   dest(0,0) ← src(3,3)=white, dest(3,3) ← src(0,0)=red
        $rot180 = imagerotate($im2, 180.0, 0);
        $this->checkInt(imagesx($rot180), 4, "5. rotate 180 w");
        $this->checkInt(imagesy($rot180), 4, "5. rotate 180 h");
        $this->checkInt(imagecolorat($rot180, 0, 0), 16777215, "5. rotate 180 (0,0)");
        $this->checkInt(imagecolorat($rot180, 3, 3), 16711680, "5. rotate 180 (3,3)");

        // 90 度逆时针旋转：4x4 → 4x4
        //   dest(0,0) ← src(3,0)=green, dest(0,3) ← src(0,0)=red
        $rot90 = imagerotate($im2, 90.0, 0);
        $this->checkInt(imagesx($rot90), 4, "6. rotate 90 w");
        $this->checkInt(imagesy($rot90), 4, "6. rotate 90 h");
        $this->checkInt(imagecolorat($rot90, 0, 0), 65280, "6. rotate 90 (0,0)");
        $this->checkInt(imagecolorat($rot90, 0, 3), 16711680, "6. rotate 90 (0,3)");

        // 45 度旋转：4x4 → 6x6，角落为背景色（红色）
        $rot45 = imagerotate($im2, 45.0, $red);
        $this->checkInt(imagesx($rot45), 6, "7. rotate 45 w");
        $this->checkInt(imagesy($rot45), 6, "7. rotate 45 h");
        $this->checkInt(imagecolorat($rot45, 0, 0), 16711680, "7. rotate 45 corner");

        // ════════════════════════════════════════════════════════════
        // 3. imagecrop — 矩形裁剪
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecrop --\n";

        $im3 = imagecreatetruecolor(4, 4);
        imagesetpixel($im3, 1, 1, $red);
        imagesetpixel($im3, 2, 1, $green);
        imagesetpixel($im3, 1, 2, $blue);
        imagesetpixel($im3, 2, 2, $white);

        // 裁剪 (1,1) 起 2x2 区域
        $cropped = imagecrop($im3, ['x'=>1, 'y'=>1, 'width'=>2, 'height'=>2]);
        $this->checkInt(imagesx($cropped), 2, "8. crop w");
        $this->checkInt(imagesy($cropped), 2, "8. crop h");
        $this->checkInt(imagecolorat($cropped, 0, 0), 16711680, "8. crop (0,0)");
        $this->checkInt(imagecolorat($cropped, 1, 0), 65280, "8. crop (1,0)");
        $this->checkInt(imagecolorat($cropped, 0, 1), 255, "8. crop (0,1)");
        $this->checkInt(imagecolorat($cropped, 1, 1), 16777215, "8. crop (1,1)");

        // 无效尺寸抛异常
        $cropThrown = 0;
        try {
            imagecrop($im3, ['x'=>0, 'y'=>0, 'width'=>0, 'height'=>5]);
        } catch (Exception $e) {
            $cropThrown = 1;
        }
        $this->checkInt($cropThrown, 1, "9. crop invalid throws");

        // ════════════════════════════════════════════════════════════
        // 4. imagecropauto — 各裁剪模式
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecropauto --\n";

        // BLACK 模式：6x6 黑色背景，中心 2x2 红色 → 裁剪到红色区域
        $im4 = imagecreatetruecolor(6, 6);
        imagefilledrectangle($im4, 2, 2, 3, 3, $red);
        $ca1 = imagecropauto($im4, IMG_CROP_BLACK);
        $this->checkInt(imagesx($ca1), 2, "10. crop BLACK w");
        $this->checkInt(imagesy($ca1), 2, "10. crop BLACK h");
        $this->checkInt(imagecolorat($ca1, 0, 0), 16711680, "10. crop BLACK (0,0)");

        // WHITE 模式：6x6 白色背景，中心 2x2 红色 → 裁剪到红色区域
        $im5 = imagecreatetruecolor(6, 6);
        imagefilledrectangle($im5, 0, 0, 5, 5, $white);
        imagefilledrectangle($im5, 2, 2, 3, 3, $red);
        $ca2 = imagecropauto($im5, IMG_CROP_WHITE);
        $this->checkInt(imagesx($ca2), 2, "11. crop WHITE w");
        $this->checkInt(imagesy($ca2), 2, "11. crop WHITE h");
        $this->checkInt(imagecolorat($ca2, 0, 0), 16711680, "11. crop WHITE (0,0)");

        // SIDES 模式：(0,0) 处颜色（黑色）为背景色 → 裁剪到红色区域
        $im6 = imagecreatetruecolor(6, 6);
        imagefilledrectangle($im6, 2, 2, 3, 3, $red);
        $ca3 = imagecropauto($im6, IMG_CROP_SIDES);
        $this->checkInt(imagesx($ca3), 2, "12. crop SIDES w");
        $this->checkInt(imagesy($ca3), 2, "12. crop SIDES h");
        $this->checkInt(imagecolorat($ca3, 0, 0), 16711680, "12. crop SIDES (0,0)");

        // THRESHOLD 模式：color=0（黑色），threshold=0.5 → 裁剪到红色区域
        $im7 = imagecreatetruecolor(6, 6);
        imagefilledrectangle($im7, 2, 2, 3, 3, $red);
        $ca4 = imagecropauto($im7, IMG_CROP_THRESHOLD, 0.5, 0);
        $this->checkInt(imagesx($ca4), 2, "13. crop THRESHOLD w");
        $this->checkInt(imagesy($ca4), 2, "13. crop THRESHOLD h");
        $this->checkInt(imagecolorat($ca4, 0, 0), 16711680, "13. crop THRESHOLD (0,0)");

        // ════════════════════════════════════════════════════════════
        // 5. imagescale — 放大与缩小
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagescale --\n";

        $im8 = imagecreatetruecolor(4, 4);
        imagesetpixel($im8, 0, 0, $red);

        // 放大 4→8（最近邻插值）
        $scaledUp = imagescale($im8, 8, -1, IMG_NEAREST_NEIGHBOUR);
        $this->checkInt(imagesx($scaledUp), 8, "14. scale up w");
        $this->checkInt(imagesy($scaledUp), 8, "14. scale up h");
        $this->checkInt(imagecolorat($scaledUp, 0, 0), 16711680, "14. scale up (0,0)");

        // 缩小 4→2（最近邻插值）
        $scaledDown = imagescale($im8, 2, -1, IMG_NEAREST_NEIGHBOUR);
        $this->checkInt(imagesx($scaledDown), 2, "15. scale down w");
        $this->checkInt(imagesy($scaledDown), 2, "15. scale down h");
        $this->checkInt(imagecolorat($scaledDown, 0, 0), 16711680, "15. scale down (0,0)");

        // ════════════════════════════════════════════════════════════
        // 6. imageaffine — 平移与缩放仿射变换
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageaffine --\n";

        // 平移 (1,0)：显式 clip (0,0,4,4)
        //   dest(0,0) ← src(-1,0)=越界=0, dest(1,0) ← src(0,0)=red
        $im9 = imagecreatetruecolor(4, 4);
        imagesetpixel($im9, 0, 0, $red);
        $aff1 = imageaffine($im9, [1, 0, 0, 1, 1, 0], ['x'=>0, 'y'=>0, 'width'=>4, 'height'=>4]);
        $this->checkInt(imagesx($aff1), 4, "16. affine translate w");
        $this->checkInt(imagesy($aff1), 4, "16. affine translate h");
        $this->checkInt(imagecolorat($aff1, 0, 0), 0, "16. affine translate (0,0)");
        $this->checkInt(imagecolorat($aff1, 1, 0), 16711680, "16. affine translate (1,0)");

        // 缩放 (2,2)：自动 clip → 8x8
        //   dest(0,0) ← src(0,0)=red, dest(6,6) ← src(3,3)=blue
        $im10 = imagecreatetruecolor(4, 4);
        imagesetpixel($im10, 0, 0, $red);
        imagesetpixel($im10, 3, 3, $blue);
        $aff2 = imageaffine($im10, [2, 0, 0, 2, 0, 0], []);
        $this->checkInt(imagesx($aff2), 8, "17. affine scale w");
        $this->checkInt(imagesy($aff2), 8, "17. affine scale h");
        $this->checkInt(imagecolorat($aff2, 0, 0), 16711680, "17. affine scale (0,0)");
        $this->checkInt(imagecolorat($aff2, 6, 6), 255, "17. affine scale (6,6)");

        // 奇异矩阵（det=0）抛异常
        $affThrown = 0;
        try {
            imageaffine($im10, [1, 1, 1, 1, 0, 0], []);
        } catch (Exception $e) {
            $affThrown = 1;
        }
        $this->checkInt($affThrown, 1, "18. affine singular throws");

        // ════════════════════════════════════════════════════════════
        // 7. imageaffinematrixget — 矩阵生成
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageaffinematrixget --\n";

        // TRANSLATE(10,20) → [1, 0, 0, 1, 10, 20]
        $m1 = imageaffinematrixget(IMG_AFFINE_TRANSLATE, ['x'=>10, 'y'=>20]);
        $this->checkInt(intval($m1[0]), 1, "19. TRANSLATE a");
        $this->checkInt(intval($m1[1]), 0, "19. TRANSLATE b");
        $this->checkInt(intval($m1[2]), 0, "19. TRANSLATE c");
        $this->checkInt(intval($m1[3]), 1, "19. TRANSLATE d");
        $this->checkInt(intval($m1[4]), 10, "19. TRANSLATE e");
        $this->checkInt(intval($m1[5]), 20, "19. TRANSLATE f");

        // SCALE(2,3) → [2, 0, 0, 3, 0, 0]
        $m2 = imageaffinematrixget(IMG_AFFINE_SCALE, ['x'=>2, 'y'=>3]);
        $this->checkInt(intval($m2[0]), 2, "20. SCALE a");
        $this->checkInt(intval($m2[1]), 0, "20. SCALE b");
        $this->checkInt(intval($m2[2]), 0, "20. SCALE c");
        $this->checkInt(intval($m2[3]), 3, "20. SCALE d");
        $this->checkInt(intval($m2[4]), 0, "20. SCALE e");
        $this->checkInt(intval($m2[5]), 0, "20. SCALE f");

        // ROTATE(90) → [cos90, sin90, -sin90, cos90, 0, 0] = [0, 1, -1, 0, 0, 0]
        $m3 = imageaffinematrixget(IMG_AFFINE_ROTATE, ['angle'=>90]);
        $this->checkInt(intval($m3[0]), 0, "21. ROTATE a");
        $this->checkInt(intval($m3[1]), 1, "21. ROTATE b");
        $this->checkInt(intval($m3[2]), -1, "21. ROTATE c");
        $this->checkInt(intval($m3[3]), 0, "21. ROTATE d");
        $this->checkInt(intval($m3[4]), 0, "21. ROTATE e");
        $this->checkInt(intval($m3[5]), 0, "21. ROTATE f");

        // ════════════════════════════════════════════════════════════
        // 8. imageaffinematrixconcat — 矩阵连接
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageaffinematrixconcat --\n";

        // CONCAT(TRANSLATE(10,20), SCALE(2,3)) = [2, 0, 0, 3, 10, 20]
        //   m1=[1,0,0,1,10,20], m2=[2,0,0,3,0,0]
        //   a = 1*2 + 0*0 = 2
        //   b = 0*2 + 1*0 = 0
        //   c = 1*0 + 0*3 = 0
        //   d = 0*0 + 1*3 = 3
        //   e = 1*0 + 0*0 + 10 = 10
        //   f = 0*0 + 1*0 + 20 = 20
        $mc = imageaffinematrixconcat($m1, $m2);
        $this->checkInt(intval($mc[0]), 2, "22. CONCAT a");
        $this->checkInt(intval($mc[1]), 0, "22. CONCAT b");
        $this->checkInt(intval($mc[2]), 0, "22. CONCAT c");
        $this->checkInt(intval($mc[3]), 3, "22. CONCAT d");
        $this->checkInt(intval($mc[4]), 10, "22. CONCAT e");
        $this->checkInt(intval($mc[5]), 20, "22. CONCAT f");

        if ($this->failCount == 0) {
            echo "\n=== All passed ===\n";
        } else {
            echo "\n=== FAILED: " . $this->failCount . " assertions failed ===\n";
        }
    }
}
