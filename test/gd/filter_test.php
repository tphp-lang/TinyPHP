<?php
// ext/gd 扩展测试 — Task 10（滤镜与卷积）纯 phpc 实现验证
//
// 测试范围：
//   1. imagefilter — 13 种 IMG_FILTER_* 滤镜
//   2. imageconvolution — 3x3 卷积（锐化核）
//   3. imagegammacorrect — 伽马校正
//   4. imageantialias — 抗锯齿标志
#import gd

#debug === GD Task 10 Test (Filter) ===
#debug
#debug -- imagefilter: NEGATE --
#debug 1. negate r: OK
#debug 1. negate g: OK
#debug 1. negate b: OK
#debug
#debug -- imagefilter: GRAYSCALE --
#debug 2. grayscale r: OK
#debug 2. grayscale g: OK
#debug 2. grayscale b: OK
#debug
#debug -- imagefilter: BRIGHTNESS --
#debug 3. brightness r: OK
#debug 3. brightness g: OK
#debug 3. brightness b: OK
#debug
#debug -- imagefilter: CONTRAST --
#debug 4. contrast r: OK
#debug 4. contrast g: OK
#debug 4. contrast b: OK
#debug
#debug -- imagefilter: COLORIZE --
#debug 5. colorize r: OK
#debug 5. colorize g: OK
#debug 5. colorize b: OK
#debug 5. colorize alpha: OK
#debug
#debug -- imagefilter: EDGEDETECT --
#debug 6. edgedetect returns: OK
#debug
#debug -- imagefilter: GAUSSIAN_BLUR --
#debug 7. gaussian_blur returns: OK
#debug
#debug -- imagefilter: SELECTIVE_BLUR --
#debug 8. selective_blur returns: OK
#debug
#debug -- imagefilter: EMBOSS --
#debug 9. emboss returns: OK
#debug
#debug -- imagefilter: MEAN_REMOVAL --
#debug 10. mean_removal returns: OK
#debug
#debug -- imagefilter: SMOOTH --
#debug 11. smooth returns: OK
#debug
#debug -- imagefilter: PIXELATE --
#debug 12. pixelate block uniform: OK
#debug
#debug -- imagefilter: SCATTER --
#debug 13. scatter returns: OK
#debug
#debug -- imagefilter: palette returns false --
#debug 14. palette negate: OK
#debug
#debug -- imageconvolution --
#debug 15. convolution returns: OK
#debug 15. convolution center preserved: OK
#debug
#debug -- imagegammacorrect --
#debug 16. gamma returns: OK
#debug 16. gamma 0->0: OK
#debug 16. gamma 255->255: OK
#debug 16. gamma 128 changed: OK
#debug
#debug -- imagegammacorrect invalid --
#debug 17. gamma invalid returns false: OK
#debug
#debug -- imageantialias --
#debug 18. antialias enable: OK
#debug 18. antialias disable: OK
#debug
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

    public function checkTrue(bool $actual, string $label): void
    {
        if ($actual) {
            echo $label . ": OK\n";
        } else {
            echo $label . ": FAIL (expected=true)\n";
            $this->failCount = $this->failCount + 1;
        }
    }

    public function checkFalse(bool $actual, string $label): void
    {
        if (!$actual) {
            echo $label . ": OK\n";
        } else {
            echo $label . ": FAIL (expected=false)\n";
            $this->failCount = $this->failCount + 1;
        }
    }

    // 创建 4x4 填充指定颜色的真彩色图像
    public function makeFilled(int $r, int $g, int $b): GdImage
    {
        $im = imagecreatetruecolor(4, 4);
        $color = imagecolorallocate($im, $r, $g, $b);
        imagefilledrectangle($im, 0, 0, 3, 3, $color);
        return $im;
    }

    public function main(): void
    {
        echo "=== GD Task 10 Test (Filter) ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. IMG_FILTER_NEGATE: r=255-r
        // ════════════════════════════════════════════════════════════
        echo "-- imagefilter: NEGATE --\n";
        $im1 = $this->makeFilled(100, 150, 200);
        imagefilter($im1, IMG_FILTER_NEGATE);
        $c1 = imagecolorsforindex($im1, imagecolorat($im1, 0, 0));
        $this->checkInt(intval($c1['red']), 155, "1. negate r");
        $this->checkInt(intval($c1['green']), 105, "1. negate g");
        $this->checkInt(intval($c1['blue']), 55, "1. negate b");

        // ════════════════════════════════════════════════════════════
        // 2. IMG_FILTER_GRAYSCALE: gray = r*0.299 + g*0.587 + b*0.114
        //    100*0.299 + 150*0.587 + 200*0.114 = 29.9 + 88.05 + 22.8 = 140.75 → 140
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: GRAYSCALE --\n";
        $im2 = $this->makeFilled(100, 150, 200);
        imagefilter($im2, IMG_FILTER_GRAYSCALE);
        $c2 = imagecolorsforindex($im2, imagecolorat($im2, 0, 0));
        $this->checkInt(intval($c2['red']), 140, "2. grayscale r");
        $this->checkInt(intval($c2['green']), 140, "2. grayscale g");
        $this->checkInt(intval($c2['blue']), 140, "2. grayscale b");

        // ════════════════════════════════════════════════════════════
        // 3. IMG_FILTER_BRIGHTNESS: r += arg1
        //    100+50=150, 150+50=200, 200+50=250
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: BRIGHTNESS --\n";
        $im3 = $this->makeFilled(100, 150, 200);
        imagefilter($im3, IMG_FILTER_BRIGHTNESS, 50);
        $c3 = imagecolorsforindex($im3, imagecolorat($im3, 0, 0));
        $this->checkInt(intval($c3['red']), 150, "3. brightness r");
        $this->checkInt(intval($c3['green']), 200, "3. brightness g");
        $this->checkInt(intval($c3['blue']), 250, "3. brightness b");

        // ════════════════════════════════════════════════════════════
        // 4. IMG_FILTER_CONTRAST: r = (r-128)*arg1/100 + 128
        //    arg1=200: (100-128)*200/100+128 = -56+128 = 72
        //               (150-128)*200/100+128 = 44+128 = 172
        //               (200-128)*200/100+128 = 144+128 = 272 → clamp 255
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: CONTRAST --\n";
        $im4 = $this->makeFilled(100, 150, 200);
        imagefilter($im4, IMG_FILTER_CONTRAST, 200);
        $c4 = imagecolorsforindex($im4, imagecolorat($im4, 0, 0));
        $this->checkInt(intval($c4['red']), 72, "4. contrast r");
        $this->checkInt(intval($c4['green']), 172, "4. contrast g");
        $this->checkInt(intval($c4['blue']), 255, "4. contrast b");

        // ════════════════════════════════════════════════════════════
        // 5. IMG_FILTER_COLORIZE: r += arg1, g += arg2, b += arg3, alpha = arg4
        //    100+50=150, 150-50=100, 200+0=200, alpha=64
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: COLORIZE --\n";
        $im5 = $this->makeFilled(100, 150, 200);
        imagefilter($im5, IMG_FILTER_COLORIZE, 50, -50, 0, 64);
        $c5 = imagecolorsforindex($im5, imagecolorat($im5, 0, 0));
        $this->checkInt(intval($c5['red']), 150, "5. colorize r");
        $this->checkInt(intval($c5['green']), 100, "5. colorize g");
        $this->checkInt(intval($c5['blue']), 200, "5. colorize b");
        $this->checkInt(intval($c5['alpha']), 64, "5. colorize alpha");

        // ════════════════════════════════════════════════════════════
        // 6. IMG_FILTER_EDGEDETECT: 卷积类滤镜，验证返回 true
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: EDGEDETECT --\n";
        $im6 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im6, IMG_FILTER_EDGEDETECT), "6. edgedetect returns");

        // ════════════════════════════════════════════════════════════
        // 7. IMG_FILTER_GAUSSIAN_BLUR
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: GAUSSIAN_BLUR --\n";
        $im7 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im7, IMG_FILTER_GAUSSIAN_BLUR), "7. gaussian_blur returns");

        // ════════════════════════════════════════════════════════════
        // 8. IMG_FILTER_SELECTIVE_BLUR
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: SELECTIVE_BLUR --\n";
        $im8 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im8, IMG_FILTER_SELECTIVE_BLUR), "8. selective_blur returns");

        // ════════════════════════════════════════════════════════════
        // 9. IMG_FILTER_EMBOSS
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: EMBOSS --\n";
        $im9 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im9, IMG_FILTER_EMBOSS), "9. emboss returns");

        // ════════════════════════════════════════════════════════════
        // 10. IMG_FILTER_MEAN_REMOVAL
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: MEAN_REMOVAL --\n";
        $im10 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im10, IMG_FILTER_MEAN_REMOVAL), "10. mean_removal returns");

        // ════════════════════════════════════════════════════════════
        // 11. IMG_FILTER_SMOOTH
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: SMOOTH --\n";
        $im11 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im11, IMG_FILTER_SMOOTH, 8), "11. smooth returns");

        // ════════════════════════════════════════════════════════════
        // 12. IMG_FILTER_PIXELATE: arg1=块大小, arg2=高级模式
        //     创建 4x4 图像，左上角 (0,0)=红色，其余黑色
        //     PIXELATE block=2 后，块 (0,0)→(1,1) 应全为红色
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: PIXELATE --\n";
        $im12 = imagecreatetruecolor(4, 4);
        $red = imagecolorallocate($im12, 255, 0, 0);
        $black = imagecolorallocate($im12, 0, 0, 0);
        imagefilledrectangle($im12, 0, 0, 3, 3, $black);
        imagesetpixel($im12, 0, 0, $red);
        imagefilter($im12, IMG_FILTER_PIXELATE, 2);
        // 基本模式：块 (0,0)→(1,1) 全为 (0,0) 处的红色
        $c12 = imagecolorsforindex($im12, imagecolorat($im12, 1, 1));
        $this->checkInt(intval($c12['red']), 255, "12. pixelate block uniform");

        // ════════════════════════════════════════════════════════════
        // 13. IMG_FILTER_SCATTER: 随机偏移，验证返回 true
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: SCATTER --\n";
        $im13 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imagefilter($im13, IMG_FILTER_SCATTER, 3, 3), "13. scatter returns");

        // ════════════════════════════════════════════════════════════
        // 14. 调色板图像返回 false
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilter: palette returns false --\n";
        $im14 = imagecreate(4, 4);
        imagecolorallocate($im14, 100, 150, 200);
        $this->checkFalse(imagefilter($im14, IMG_FILTER_NEGATE), "14. palette negate");

        // ════════════════════════════════════════════════════════════
        // 15. imageconvolution — 锐化核 [[0,-1,0],[-1,5,-1],[0,-1,0]], div=1, offset=0
        //     对均匀色区域：中心=5v, 四邻=-v*4, 总和=5v-4v=v（不变）
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageconvolution --\n";
        $im15 = $this->makeFilled(100, 150, 200);
        $matrix = [[0, -1, 0], [-1, 5, -1], [0, -1, 0]];
        $this->checkTrue(imageconvolution($im15, $matrix, 1.0, 0.0), "15. convolution returns");
        // 均匀区域锐化后应保持不变
        $c15 = imagecolorsforindex($im15, imagecolorat($im15, 1, 1));
        $this->checkInt(intval($c15['red']), 100, "15. convolution center preserved");

        // ════════════════════════════════════════════════════════════
        // 16. imagegammacorrect — gamma 1.0 → 2.0
        //     out = pow(v/255, 0.5) * 255
        //     v=0: pow(0, 0.5)=0 → 0
        //     v=255: pow(1, 0.5)=1 → 255
        //     v=128: pow(128/255, 0.5)*255 ≈ pow(0.502, 0.5)*255 ≈ 0.7085*255 ≈ 180.7 → 181
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagegammacorrect --\n";
        $im16 = imagecreatetruecolor(4, 4);
        $c_black = imagecolorallocate($im16, 0, 0, 0);
        $c_white = imagecolorallocate($im16, 255, 255, 255);
        $c_gray = imagecolorallocate($im16, 128, 128, 128);
        imagefilledrectangle($im16, 0, 0, 3, 3, $c_gray);
        imagesetpixel($im16, 0, 0, $c_black);
        imagesetpixel($im16, 1, 0, $c_white);
        $this->checkTrue(imagegammacorrect($im16, 1.0, 2.0), "16. gamma returns");
        $c16a = imagecolorsforindex($im16, imagecolorat($im16, 0, 0));
        $c16b = imagecolorsforindex($im16, imagecolorat($im16, 1, 0));
        $c16c = imagecolorsforindex($im16, imagecolorat($im16, 2, 0));
        $this->checkInt(intval($c16a['red']), 0, "16. gamma 0->0");
        $this->checkInt(intval($c16b['red']), 255, "16. gamma 255->255");
        // v=128 → pow(128/255, 0.5)*255 ≈ 181
        $grayVal = intval($c16c['red']);
        $this->checkTrue($grayVal > 150 && $grayVal < 200, "16. gamma 128 changed");

        // ════════════════════════════════════════════════════════════
        // 17. imagegammacorrect 无效参数返回 false
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagegammacorrect invalid --\n";
        $im17 = $this->makeFilled(100, 150, 200);
        $this->checkFalse(imagegammacorrect($im17, 0.0, 2.0), "17. gamma invalid returns false");

        // ════════════════════════════════════════════════════════════
        // 18. imageantialias — 仅存储标志
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageantialias --\n";
        $im18 = $this->makeFilled(100, 150, 200);
        $this->checkTrue(imageantialias($im18, true), "18. antialias enable");
        $this->checkTrue(imageantialias($im18, false), "18. antialias disable");

        if ($this->failCount == 0) {
            echo "\n=== All passed ===\n";
        } else {
            echo "\n=== FAILED: " . $this->failCount . " assertions failed ===\n";
        }
    }
}
