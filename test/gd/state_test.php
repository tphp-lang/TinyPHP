<?php
// ext/gd 扩展测试 — Task 11（状态与属性函数）纯 phpc 实现验证
//
// 测试范围：
//   1. imagealphablending / imagesavealpha — 设置与获取旧值
//   2. imagesetstyle / imagesetbrush / imagesettile — 存储引用
//   3. imagesetclip / imagegetclip — 剪辑区设置/获取（含规范化与默认值）
//   4. imagesetinterpolation / imagegetinterpolation — 插值方法
//   5. imageresolution — 分辨率 getter/setter 双模式
//   6. imageinterlace — 隔行扫描 getter/setter 双模式
//   7. imagetruecolortopalette / imagepalettetotruecolor — 类型转换
//   8. gd_info — 真实能力（JPG/WebP/AVIF/XPM/FreeType 为 false）
//   9. imagetypes — 位掩码不含 JPG/WEBP/AVIF/XPM
#import gd

#debug === GD Task 11 Test (State & Attributes) ===
#debug
#debug -- imagealphablending / imagesavealpha --
#debug 1. tc default alphaBlending: 1
#debug 2. set alphaBlending=false old: 1
#debug 3. get alphaBlending: 0
#debug 4. set alphaBlending=true old: 0
#debug 5. tc default saveAlpha: 0
#debug 6. set saveAlpha=true old: 0
#debug 7. get saveAlpha: 1
#debug
#debug -- imagesetstyle / imagesetbrush / imagesettile --
#debug 8. setstyle: 1
#debug 9. style count: 3
#debug 10. style[0]: 16711680
#debug 11. setbrush: 1
#debug 12. brush is GdImage: 1
#debug 13. settile: 1
#debug 14. tile is GdImage: 1
#debug
#debug -- imagesetclip / imagegetclip --
#debug 15. default clip: 0,0,19,19
#debug 16. setclip: 1
#debug 17. getclip: 2,3,15,17
#debug 18. setclip reversed: 1
#debug 19. getclip normalized: 1,2,10,20
#debug
#debug -- imagesetinterpolation / imagegetinterpolation --
#debug 20. default interp: 3
#debug 21. set interp=BICUBIC: 1
#debug 22. get interp: 4
#debug 23. set interp=NEAREST: 1
#debug 24. get interp: 19
#debug
#debug -- imageresolution --
#debug 25. default res: 96,96
#debug 26. set res(150,300): 1
#debug 27. get res: 150,300
#debug 28. set res(72): 1
#debug 29. get res: 72,72
#debug
#debug -- imageinterlace --
#debug 30. default interlace: 0
#debug 31. set interlace=1: 1
#debug 32. get interlace: 1
#debug 33. set interlace=0: 1
#debug 34. get interlace: 0
#debug
#debug -- imagetruecolortopalette --
#debug 35. before: isTrueColor=1
#debug 36. truecolortopalette: 1
#debug 37. after: isTrueColor=0
#debug 38. palette colors <= 256: 1
#debug 39. palette colors > 0: 1
#debug
#debug -- imagepalettetotruecolor --
#debug 40. before: isTrueColor=0
#debug 41. palettetotruecolor: 1
#debug 42. after: isTrueColor=1
#debug 43. pixel matches palette color: 1
#debug
#debug -- gd_info (real capabilities) --
#debug 44. JPEG Support: 0
#debug 45. WebP Support: 0
#debug 46. AVIF Support: 0
#debug 47. XPM Support: 0
#debug 48. FreeType Support: 0
#debug 49. PNG Support: 1
#debug 50. GIF Read Support: 1
#debug 51. BMP Support: 1
#debug 52. TGA Read Support: 1
#debug
#debug -- imagetypes (bitmask) --
#debug 53. types & IMG_JPG: 0
#debug 54. types & IMG_WEBP: 0
#debug 55. types & IMG_AVIF: 0
#debug 56. types & IMG_XPM: 0
#debug 57. types & IMG_GIF: 2
#debug 58. types & IMG_PNG: 8
#debug 59. types & IMG_BMP: 128
#debug 60. types & IMG_TGA: 256
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 11 Test (State & Attributes) ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. imagealphablending / imagesavealpha
        // ════════════════════════════════════════════════════════════
        echo "-- imagealphablending / imagesavealpha --\n";

        $tc = imagecreatetruecolor(20, 20);
        // 真彩色默认 alphaBlending=true（imagecreatetruecolor 设置）
        echo "1. tc default alphaBlending: " . ($tc->alphaBlending ? "1" : "0") . "\n";

        $old = imagealphablending($tc, false);
        echo "2. set alphaBlending=false old: " . ($old ? "1" : "0") . "\n";
        echo "3. get alphaBlending: " . ($tc->alphaBlending ? "1" : "0") . "\n";

        $old = imagealphablending($tc, true);
        echo "4. set alphaBlending=true old: " . ($old ? "1" : "0") . "\n";

        // saveAlpha 默认 false
        echo "5. tc default saveAlpha: " . ($tc->saveAlpha ? "1" : "0") . "\n";

        $old = imagesavealpha($tc, true);
        echo "6. set saveAlpha=true old: " . ($old ? "1" : "0") . "\n";
        echo "7. get saveAlpha: " . ($tc->saveAlpha ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. imagesetstyle / imagesetbrush / imagesettile
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagesetstyle / imagesetbrush / imagesettile --\n";

        $red = imagecolorallocate($tc, 255, 0, 0);
        $green = imagecolorallocate($tc, 0, 255, 0);
        $blue = imagecolorallocate($tc, 0, 0, 255);

        $style = [$red, $green, $blue];
        $r = imagesetstyle($tc, $style);
        echo "8. setstyle: " . ($r ? "1" : "0") . "\n";
        echo "9. style count: " . count($tc->style) . "\n";
        echo "10. style[0]: " . $tc->style[0] . "\n";

        $brush = imagecreatetruecolor(5, 5);
        $r = imagesetbrush($tc, $brush);
        echo "11. setbrush: " . ($r ? "1" : "0") . "\n";
        echo "12. brush is GdImage: " . ($tc->brush instanceof GdImage ? "1" : "0") . "\n";

        $tile = imagecreatetruecolor(8, 8);
        $r = imagesettile($tc, $tile);
        echo "13. settile: " . ($r ? "1" : "0") . "\n";
        echo "14. tile is GdImage: " . ($tc->tile instanceof GdImage ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. imagesetclip / imagegetclip
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagesetclip / imagegetclip --\n";

        $tc2 = imagecreatetruecolor(20, 20);
        // 未设置剪辑区时返回图像全范围
        $clip = imagegetclip($tc2);
        echo "15. default clip: " . $clip[0] . "," . $clip[1] . "," . $clip[2] . "," . $clip[3] . "\n";

        $r = imagesetclip($tc2, 2, 3, 15, 17);
        echo "16. setclip: " . ($r ? "1" : "0") . "\n";
        $clip = imagegetclip($tc2);
        echo "17. getclip: " . $clip[0] . "," . $clip[1] . "," . $clip[2] . "," . $clip[3] . "\n";

        // 反向参数自动规范化
        $r = imagesetclip($tc2, 10, 20, 1, 2);
        echo "18. setclip reversed: " . ($r ? "1" : "0") . "\n";
        $clip = imagegetclip($tc2);
        echo "19. getclip normalized: " . $clip[0] . "," . $clip[1] . "," . $clip[2] . "," . $clip[3] . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. imagesetinterpolation / imagegetinterpolation
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagesetinterpolation / imagegetinterpolation --\n";

        // 默认 IMG_BILINEAR_FIXED(3)
        echo "20. default interp: " . imagegetinterpolation($tc2) . "\n";

        $r = imagesetinterpolation($tc2, IMG_BICUBIC);
        echo "21. set interp=BICUBIC: " . ($r ? "1" : "0") . "\n";
        echo "22. get interp: " . imagegetinterpolation($tc2) . "\n";

        $r = imagesetinterpolation($tc2, IMG_NEAREST_NEIGHBOUR);
        echo "23. set interp=NEAREST: " . ($r ? "1" : "0") . "\n";
        echo "24. get interp: " . imagegetinterpolation($tc2) . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. imageresolution（getter/setter 双模式）
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageresolution --\n";

        // 默认 96 DPI
        $res = imageresolution($tc2);
        echo "25. default res: " . $res[0] . "," . $res[1] . "\n";

        // 设置 X=150, Y=300
        $r = imageresolution($tc2, 150, 300);
        echo "26. set res(150,300): " . ($r ? "1" : "0") . "\n";
        $res = imageresolution($tc2);
        echo "27. get res: " . $res[0] . "," . $res[1] . "\n";

        // 仅设置 X=72，Y 应同时变为 72
        $r = imageresolution($tc2, 72);
        echo "28. set res(72): " . ($r ? "1" : "0") . "\n";
        $res = imageresolution($tc2);
        echo "29. get res: " . $res[0] . "," . $res[1] . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. imageinterlace（getter/setter 双模式）
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageinterlace --\n";

        // 默认 interlace=0
        $il = imageinterlace($tc2);
        echo "30. default interlace: " . $il . "\n";

        // 设置 interlace=1
        $r = imageinterlace($tc2, 1);
        echo "31. set interlace=1: " . ($r ? "1" : "0") . "\n";
        $il = imageinterlace($tc2);
        echo "32. get interlace: " . $il . "\n";

        // 设置 interlace=0
        $r = imageinterlace($tc2, 0);
        echo "33. set interlace=0: " . ($r ? "1" : "0") . "\n";
        $il = imageinterlace($tc2);
        echo "34. get interlace: " . $il . "\n";

        // ════════════════════════════════════════════════════════════
        // 7. imagetruecolortopalette
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagetruecolortopalette --\n";

        // 创建真彩色图像并填充几种颜色
        $tc3 = imagecreatetruecolor(10, 10);
        imagefilledrectangle($tc3, 0, 0, 4, 9, imagecolorallocate($tc3, 255, 0, 0));
        imagefilledrectangle($tc3, 5, 0, 9, 9, imagecolorallocate($tc3, 0, 0, 255));

        echo "35. before: isTrueColor=" . (imageistruecolor($tc3) ? "1" : "0") . "\n";

        $r = imagetruecolortopalette($tc3, false, 16);
        echo "36. truecolortopalette: " . ($r ? "1" : "0") . "\n";

        echo "37. after: isTrueColor=" . (imageistruecolor($tc3) ? "1" : "0") . "\n";

        $total = imagecolorstotal($tc3);
        echo "38. palette colors <= 256: " . ($total <= 256 ? "1" : "0") . "\n";
        echo "39. palette colors > 0: " . ($total > 0 ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // 8. imagepalettetotruecolor
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagepalettetotruecolor --\n";

        // 使用上一步的调色板图像
        echo "40. before: isTrueColor=" . (imageistruecolor($tc3) ? "1" : "0") . "\n";

        // 记录转换前某像素对应的调色板颜色
        $idxBefore = imagecolorat($tc3, 0, 0);
        $colorBefore = $tc3->palette[$idxBefore];

        $r = imagepalettetotruecolor($tc3);
        echo "41. palettetotruecolor: " . ($r ? "1" : "0") . "\n";

        echo "42. after: isTrueColor=" . (imageistruecolor($tc3) ? "1" : "0") . "\n";

        // 验证像素值等于转换前调色板中的颜色
        $pixelAfter = imagecolorat($tc3, 0, 0);
        echo "43. pixel matches palette color: " . ($pixelAfter == $colorBefore ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // 9. gd_info（真实能力）
        // ════════════════════════════════════════════════════════════
        echo "\n-- gd_info (real capabilities) --\n";

        $info = gd_info();
        echo "44. JPEG Support: " . ($info["JPEG Support"] ? "1" : "0") . "\n";
        echo "45. WebP Support: " . ($info["WebP Support"] ? "1" : "0") . "\n";
        echo "46. AVIF Support: " . ($info["AVIF Support"] ? "1" : "0") . "\n";
        echo "47. XPM Support: " . ($info["XPM Support"] ? "1" : "0") . "\n";
        echo "48. FreeType Support: " . ($info["FreeType Support"] ? "1" : "0") . "\n";
        echo "49. PNG Support: " . ($info["PNG Support"] ? "1" : "0") . "\n";
        echo "50. GIF Read Support: " . ($info["GIF Read Support"] ? "1" : "0") . "\n";
        echo "51. BMP Support: " . ($info["BMP Support"] ? "1" : "0") . "\n";
        echo "52. TGA Read Support: " . ($info["TGA Read Support"] ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // 10. imagetypes（位掩码）
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagetypes (bitmask) --\n";

        $types = imagetypes();
        echo "53. types & IMG_JPG: " . ($types & IMG_JPG) . "\n";
        echo "54. types & IMG_WEBP: " . ($types & IMG_WEBP) . "\n";
        echo "55. types & IMG_AVIF: " . ($types & IMG_AVIF) . "\n";
        echo "56. types & IMG_XPM: " . ($types & IMG_XPM) . "\n";
        echo "57. types & IMG_GIF: " . ($types & IMG_GIF) . "\n";
        echo "58. types & IMG_PNG: " . ($types & IMG_PNG) . "\n";
        echo "59. types & IMG_BMP: " . ($types & IMG_BMP) . "\n";
        echo "60. types & IMG_TGA: " . ($types & IMG_TGA) . "\n";

        echo "\n=== All passed ===\n";
    }
}
