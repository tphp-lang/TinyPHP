<?php
// ext/gd 扩展测试 — 补充覆盖零散函数
//
// 补充以下 PHP 8.5.8 gd.stub.php 暴露但既有测试未覆盖的函数：
//   1. imagecolorclosestalpha    — 含 alpha 的最近色查找
//   2. imagecolorexactalpha      — 含 alpha 的精确色查找
//   3. imagecolorresolvealpha    — 含 alpha 的查找或分配
//   4. imagecolorclosesthwb       — HWB 距离最近色
//   5. imagecolormatch            — 真彩色→调色板颜色匹配
//   6. imagelayereffect           — 图层效果（映射 alphaBlending）
//   7. imagecreatefromstring      — 从字符串解码图像（PNG 往返 + 错误路径）
#import gd

#debug === GD Misc Test (Missing Coverage) ===
#debug
#debug -- imagecolorclosestalpha --
#debug 1. tc closestalpha(255,0,0,64): 1090453504
#debug 2. pal closestalpha(250,5,5,0): 0
#debug
#debug -- imagecolorexactalpha --
#debug 3. tc exactalpha(255,0,0,64): 1090453504
#debug 4. pal exactalpha(0,255,0,0): 1
#debug 5. pal exactalpha(0,255,0,64): -1
#debug
#debug -- imagecolorresolvealpha --
#debug 6. tc resolvealpha(255,0,0,64): 1090453504
#debug 7. pal resolvealpha(0,255,0,0): 1
#debug 8. pal resolvealpha(200,200,200,0): 3
#debug
#debug -- imagecolorclosesthwb --
#debug 9. tc closesthwb(255,0,0): 16711680
#debug 10. pal closesthwb(250,5,5): 0
#debug
#debug -- imagecolormatch --
#debug 11. match returns: 1
#debug 12. palette[0] after match: 16711680
#debug 13. match invalid (pal,tc): 0
#debug
#debug -- imagelayereffect --
#debug 14. effect REPLACE -> blending: 0
#debug 15. effect NORMAL -> blending: 1
#debug 16. effect returns true: 1
#debug
#debug -- imagecreatefromstring --
#debug 17. png string w/h: 4x4
#debug 18. png string pixel(0,0): 16711680
#debug 19. png string pixel(1,1): 65280
#debug 20. too short throws: 1
#debug 21. unknown format throws: 1
#debug
#debug === All passed ===

// 辅助函数：二进制安全读取文件到 PHP 字符串
//   使用 fgetc 逐字节读取，避免 strlen 截断 null 字节
function misc_test_read_file(string $filename): string
{
    $fpi = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fpi == 0) { return ""; }
    C.void* $f = phpc_int_to_ptr($fpi);
    defer C->fclose($f);
    // 定位到文件末尾获取大小
    C->fseek($f, c_int(0), c_int(2));
    int $size = C->ftell($f);
    C->fseek($f, c_int(0), c_int(0));
    // 逐字节读取（二进制安全）
    $result = "";
    $i = 0;
    while ($i < $size) {
        int $ch = C->fgetc($f);
        if ($ch == -1) { break; }
        $result .= chr($ch & 0xFF);
        $i = $i + 1;
    }
    return $result;
}

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

    public function main(): void
    {
        echo "=== GD Misc Test (Missing Coverage) ===\n\n";

        // 颜色常量
        // red=16711680(0x00FF0000) green=65280(0x0000FF00) blue=255(0x000000FF)
        // tc alpha(255,0,0,64) = (64<<24)|(255<<16) = 1090453504

        // ════════════════════════════════════════════════════════════
        // 1. imagecolorclosestalpha
        //    真彩色：返回 gd_make_color(r,g,b,a)
        //    调色板：返回距离最小的索引（距离含 alpha 分量）
        // ════════════════════════════════════════════════════════════
        echo "-- imagecolorclosestalpha --\n";

        $tc = imagecreatetruecolor(10, 10);
        // 真彩色：(64<<24)|(255<<16) = 1090453504
        $tcCA = imagecolorclosestalpha($tc, 255, 0, 0, 64);
        $this->checkInt($tcCA, 1090453504, "1. tc closestalpha(255,0,0,64)");

        // 调色板：[0]=red(255,0,0,a=0) [1]=green(0,255,0,a=0) [2]=blue(0,0,255,a=0)
        // closestalpha(250,5,5,0) → 距离 red 最近 → idx 0
        $pal = imagecreate(10, 10);
        imagecolorallocate($pal, 255, 0, 0);   // idx 0
        imagecolorallocate($pal, 0, 255, 0);   // idx 1
        imagecolorallocate($pal, 0, 0, 255);   // idx 2
        $palCA = imagecolorclosestalpha($pal, 250, 5, 5, 0);
        $this->checkInt($palCA, 0, "2. pal closestalpha(250,5,5,0)");

        // ════════════════════════════════════════════════════════════
        // 2. imagecolorexactalpha
        //    真彩色：返回 gd_make_color(r,g,b,a)
        //    调色板：精确匹配（含 alpha）→ 索引；未找到 → -1
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecolorexactalpha --\n";

        $tcEA = imagecolorexactalpha($tc, 255, 0, 0, 64);
        $this->checkInt($tcEA, 1090453504, "3. tc exactalpha(255,0,0,64)");

        // green 的 alpha=0 精确匹配 → idx 1
        $palEA1 = imagecolorexactalpha($pal, 0, 255, 0, 0);
        $this->checkInt($palEA1, 1, "4. pal exactalpha(0,255,0,0)");

        // alpha=64 不匹配调色板中 alpha=0 的 green → -1
        $palEA2 = imagecolorexactalpha($pal, 0, 255, 0, 64);
        $this->checkInt($palEA2, -1, "5. pal exactalpha(0,255,0,64)");

        // ════════════════════════════════════════════════════════════
        // 3. imagecolorresolvealpha
        //    真彩色：返回 gd_make_color(r,g,b,a)
        //    调色板：精确查找 → 找到返回索引；未找到则分配新索引
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecolorresolvealpha --\n";

        $tcRA = imagecolorresolvealpha($tc, 255, 0, 0, 64);
        $this->checkInt($tcRA, 1090453504, "6. tc resolvealpha(255,0,0,64)");

        // 精确匹配 green(alpha=0) → idx 1
        $palRA1 = imagecolorresolvealpha($pal, 0, 255, 0, 0);
        $this->checkInt($palRA1, 1, "7. pal resolvealpha(0,255,0,0)");

        // 未找到 → 分配新索引 3
        $palRA2 = imagecolorresolvealpha($pal, 200, 200, 200, 0);
        $this->checkInt($palRA2, 3, "8. pal resolvealpha(200,200,200,0)");

        // ════════════════════════════════════════════════════════════
        // 4. imagecolorclosesthwb
        //    真彩色：返回 gd_make_color(r,g,b,0)
        //    调色板：返回 HWB 距离最小的索引
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecolorclosesthwb --\n";

        // 真彩色：(255<<16) = 16711680
        $tcHWB = imagecolorclosesthwb($tc, 255, 0, 0);
        $this->checkInt($tcHWB, 16711680, "9. tc closesthwb(255,0,0)");

        // 调色板 [red,green,blue]，target(250,5,5) 的 HWB 最接近 red → idx 0
        $pal2 = imagecreate(10, 10);
        imagecolorallocate($pal2, 255, 0, 0);   // idx 0 = red
        imagecolorallocate($pal2, 0, 255, 0);   // idx 1 = green
        imagecolorallocate($pal2, 0, 0, 255);   // idx 2 = blue
        $palHWB = imagecolorclosesthwb($pal2, 250, 5, 5);
        $this->checkInt($palHWB, 0, "10. pal closesthwb(250,5,5)");

        // ════════════════════════════════════════════════════════════
        // 5. imagecolormatch
        //    image1=真彩色, image2=调色板, 同尺寸
        //    用 image1 像素平均值更新 image2 调色板
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecolormatch --\n";

        // 真彩色 4x4 全红
        $tcMatch = imagecreatetruecolor(4, 4);
        $redMatch = imagecolorallocate($tcMatch, 255, 0, 0);
        imagefilledrectangle($tcMatch, 0, 0, 3, 3, $redMatch);

        // 调色板 4x4，分配黑色（idx 0），像素全指向 idx 0
        $palMatch = imagecreate(4, 4);
        imagecolorallocate($palMatch, 0, 0, 0);  // idx 0 = black

        $r = imagecolormatch($tcMatch, $palMatch);
        $this->checkTrue($r, "11. match returns");

        // match 后：idx 0 的 16 个像素在 tc 中全为红(255,0,0) → palette[0] = red
        $matchedColor = $palMatch->palette[0];
        $this->checkInt($matchedColor, 16711680, "12. palette[0] after match");

        // 无效输入：image1 不是真彩色 → 返回 false
        $r2 = imagecolormatch($palMatch, $tcMatch);
        $this->checkInt($r2 ? 1 : 0, 0, "13. match invalid (pal,tc)");

        // ════════════════════════════════════════════════════════════
        // 6. imagelayereffect
        //    IMG_EFFECT_REPLACE → alphaBlending=false
        //    其他（ALPHABLEND/NORMAL/OVERLAY/MULTIPLY）→ alphaBlending=true
        //    始终返回 true
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagelayereffect --\n";

        $tcLE = imagecreatetruecolor(10, 10);

        // REPLACE → alphaBlending = false
        imagelayereffect($tcLE, IMG_EFFECT_REPLACE);
        $this->checkInt($tcLE->alphaBlending ? 1 : 0, 0, "14. effect REPLACE -> blending");

        // NORMAL → alphaBlending = true
        imagelayereffect($tcLE, IMG_EFFECT_NORMAL);
        $this->checkInt($tcLE->alphaBlending ? 1 : 0, 1, "15. effect NORMAL -> blending");

        // 返回值始终为 true
        $r3 = imagelayereffect($tcLE, IMG_EFFECT_OVERLAY);
        $this->checkInt($r3 ? 1 : 0, 1, "16. effect returns true");

        // ════════════════════════════════════════════════════════════
        // 7. imagecreatefromstring
        //    PNG 往返：imagepng 写文件 → 读取字节 → imagecreatefromstring 解码
        //    错误路径：数据过短、未知格式
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagecreatefromstring --\n";

        // 创建 4x4 真彩色，设置像素
        $tcS = imagecreatetruecolor(4, 4);
        $redS = imagecolorallocate($tcS, 255, 0, 0);
        $greenS = imagecolorallocate($tcS, 0, 255, 0);
        imagefilledrectangle($tcS, 0, 0, 3, 3, $redS);
        imagesetpixel($tcS, 1, 1, $greenS);

        // 写 PNG 文件
        imagepng($tcS, "test/gd/misc_test_tc.png");

        // 二进制读取文件内容
        $pngData = misc_test_read_file("test/gd/misc_test_tc.png");

        // 从字符串解码
        $fromStr = imagecreatefromstring($pngData);
        $this->checkInt(imagesx($fromStr), 4, "17. png string w/h");
        $this->checkInt(imagecolorat($fromStr, 0, 0), 16711680, "18. png string pixel(0,0)");
        $this->checkInt(imagecolorat($fromStr, 1, 1), 65280, "19. png string pixel(1,1)");

        // 错误路径：数据过短（< 3 字节）→ 抛异常
        $shortThrown = 0;
        try {
            imagecreatefromstring("ab");
        } catch (Exception $e) {
            $shortThrown = 1;
        }
        $this->checkInt($shortThrown, 1, "20. too short throws");

        // 错误路径：未知格式 → 抛异常
        $unknownThrown = 0;
        try {
            imagecreatefromstring("ZZZZ unrecognized data format");
        } catch (Exception $e) {
            $unknownThrown = 1;
        }
        $this->checkInt($unknownThrown, 1, "21. unknown format throws");

        if ($this->failCount == 0) {
            echo "\n=== All passed ===\n";
        } else {
            echo "\n=== FAILED: " . $this->failCount . " assertions failed ===\n";
        }
    }
}
