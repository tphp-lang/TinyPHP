<?php
// ext/gd 扩展测试 — Task 4-6（绘图函数）纯 phpc 实现验证
//
// 测试范围：
//   Task 4: imagesetpixel / imageline / imagedashedline / imagerectangle / imagefilledrectangle / imagesetthickness
//   Task 5: imagearc / imageellipse / imagefilledellipse / imagefilledarc
//   Task 6: imagefill / imagefilltoborder / imagepolygon / imageopenpolygon / imagefilledpolygon（两种签名）
#import gd

#debug === GD Task 4-6 Test ===
#debug
#debug -- imagesetpixel --
#debug 1. setpixel(5,5,red): 1
#debug 2. at(5,5): 16711680
#debug 3. at(0,0): 0
#debug 4. setpixel(-1,0,red): 0
#debug 5. setpixel(20,0,red): 0
#debug
#debug -- imageline (horizontal) --
#debug 6. at(0,5): 16711680
#debug 7. at(5,5): 16711680
#debug 8. at(10,5): 16711680
#debug 9. at(5,4): 0
#debug
#debug -- imageline (vertical) --
#debug 10. at(5,0): 65280
#debug 11. at(5,10): 65280
#debug 12. at(4,5): 0
#debug
#debug -- imagedashedline --
#debug 13. at(0,5): 255
#debug 14. at(2,5): 0
#debug 15. at(4,5): 255
#debug
#debug -- imagerectangle --
#debug 16. at(2,2): 16777215
#debug 17. at(8,2): 16777215
#debug 18. at(5,2): 16777215
#debug 19. at(5,5): 0
#debug
#debug -- imagefilledrectangle --
#debug 20. at(2,2): 16711680
#debug 21. at(5,5): 16711680
#debug 22. at(0,0): 0
#debug
#debug -- imagesetthickness --
#debug 23. at(5,9): 16711680
#debug 24. at(5,10): 16711680
#debug 25. at(5,11): 16711680
#debug 26. at(5,8): 0
#debug
#debug -- imagearc --
#debug 27. at(15,10): 65280
#debug 28. at(10,15): 65280
#debug 29. at(10,10): 0
#debug
#debug -- imageellipse --
#debug 30. at(15,10): 16777215
#debug 31. at(5,10): 16777215
#debug 32. at(10,5): 16777215
#debug 33. at(10,15): 16777215
#debug 34. at(10,10): 0
#debug
#debug -- imagefilledellipse --
#debug 35. at(10,10): 16711680
#debug 36. at(14,10): 16711680
#debug 37. at(17,10): 0
#debug
#debug -- imagefilledarc (PIE) --
#debug 38. at(10,10): 65280
#debug 39. at(12,12): 65280
#debug
#debug -- imagefill --
#debug 40. at(5,5): 16711680
#debug 41. at(2,2): 16777215
#debug 42. at(0,0): 0
#debug
#debug -- imagefilltoborder --
#debug 43. at(5,5): 16711680
#debug 44. at(2,2): 16777215
#debug 45. at(0,0): 0
#debug
#debug -- imagepolygon (old sig) --
#debug 46. at(2,2): 16777215
#debug 47. at(6,2): 16777215
#debug 48. at(4,6): 16777215
#debug
#debug -- imagepolygon (new sig flat) --
#debug 49. at(2,2): 16777215
#debug 50. at(6,2): 16777215
#debug
#debug -- imagepolygon (pair form) --
#debug 51. at(2,2): 16777215
#debug 52. at(6,2): 16777215
#debug
#debug -- imageopenpolygon (old sig) --
#debug 53. at(2,2): 16711680
#debug 54. at(6,2): 16711680
#debug 55. at(4,6): 0
#debug
#debug -- imagefilledpolygon (old sig) --
#debug 56. at(6,5): 16711680
#debug 57. at(6,10): 16711680
#debug
#debug -- imagefilledpolygon (new sig) --
#debug 58. at(6,5): 65280
#debug 59. at(6,10): 65280
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 4-6 Test ===\n\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.1: imagesetpixel
        // ════════════════════════════════════════════════════════════
        echo "-- imagesetpixel --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);

        $r1 = imagesetpixel($im, 5, 5, $red);
        echo "1. setpixel(5,5,red): " . ($r1 ? "1" : "0") . "\n";
        echo "2. at(5,5): " . imagecolorat($im, 5, 5) . "\n";
        echo "3. at(0,0): " . imagecolorat($im, 0, 0) . "\n";

        $r2 = imagesetpixel($im, -1, 0, $red);
        echo "4. setpixel(-1,0,red): " . ($r2 ? "1" : "0") . "\n";
        $r3 = imagesetpixel($im, 20, 0, $red);
        echo "5. setpixel(20,0,red): " . ($r3 ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.2: imageline (horizontal)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageline (horizontal) --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imageline($im, 0, 5, 10, 5, $red);
        echo "6. at(0,5): " . imagecolorat($im, 0, 5) . "\n";
        echo "7. at(5,5): " . imagecolorat($im, 5, 5) . "\n";
        echo "8. at(10,5): " . imagecolorat($im, 10, 5) . "\n";
        echo "9. at(5,4): " . imagecolorat($im, 5, 4) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.2: imageline (vertical)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageline (vertical) --\n";
        $im = imagecreatetruecolor(20, 20);
        $green = imagecolorallocate($im, 0, 255, 0);
        imageline($im, 5, 0, 5, 10, $green);
        echo "10. at(5,0): " . imagecolorat($im, 5, 0) . "\n";
        echo "11. at(5,10): " . imagecolorat($im, 5, 10) . "\n";
        echo "12. at(4,5): " . imagecolorat($im, 4, 5) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.3: imagedashedline
        //   Pattern: 2 on, 2 off (dashPos 0,1=on, 2,3=off)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagedashedline --\n";
        $im = imagecreatetruecolor(20, 20);
        $blue = imagecolorallocate($im, 0, 0, 255);
        imagedashedline($im, 0, 5, 10, 5, $blue);
        // x=0: on, x=1: on, x=2: off, x=3: off, x=4: on
        echo "13. at(0,5): " . imagecolorat($im, 0, 5) . "\n";
        echo "14. at(2,5): " . imagecolorat($im, 2, 5) . "\n";
        echo "15. at(4,5): " . imagecolorat($im, 4, 5) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.4: imagerectangle (outline only)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagerectangle --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagerectangle($im, 2, 2, 8, 8, $white);
        echo "16. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "17. at(8,2): " . imagecolorat($im, 8, 2) . "\n";
        echo "18. at(5,2): " . imagecolorat($im, 5, 2) . "\n";
        echo "19. at(5,5): " . imagecolorat($im, 5, 5) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.4: imagefilledrectangle
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilledrectangle --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagefilledrectangle($im, 2, 2, 8, 8, $red);
        echo "20. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "21. at(5,5): " . imagecolorat($im, 5, 5) . "\n";
        echo "22. at(0,0): " . imagecolorat($im, 0, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 4.5: imagesetthickness
        //   thickness=3 → horizontal line covers y=9,10,11
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagesetthickness --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagesetthickness($im, 3);
        imageline($im, 0, 10, 10, 10, $red);
        echo "23. at(5,9): " . imagecolorat($im, 5, 9) . "\n";
        echo "24. at(5,10): " . imagecolorat($im, 5, 10) . "\n";
        echo "25. at(5,11): " . imagecolorat($im, 5, 11) . "\n";
        echo "26. at(5,8): " . imagecolorat($im, 5, 8) . "\n";
        imagesetthickness($im, 1);

        // ════════════════════════════════════════════════════════════
        // Task 5.1: imagearc (0° to 90°)
        //   center=(10,10), w=h=10 → rx=ry=5
        //   0° → (15,10), 90° → (10,15)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagearc --\n";
        $im = imagecreatetruecolor(20, 20);
        $green = imagecolorallocate($im, 0, 255, 0);
        imagearc($im, 10, 10, 10, 10, 0, 90, $green);
        echo "27. at(15,10): " . imagecolorat($im, 15, 10) . "\n";
        echo "28. at(10,15): " . imagecolorat($im, 10, 15) . "\n";
        echo "29. at(10,10): " . imagecolorat($im, 10, 10) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 5.2: imageellipse (full, 0° to 360°)
        //   center=(10,10), w=h=10 → rx=ry=5
        //   0°→(15,10), 90°→(10,15), 180°→(5,10), 270°→(10,5)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageellipse --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        imageellipse($im, 10, 10, 10, 10, $white);
        echo "30. at(15,10): " . imagecolorat($im, 15, 10) . "\n";
        echo "31. at(5,10): " . imagecolorat($im, 5, 10) . "\n";
        echo "32. at(10,5): " . imagecolorat($im, 10, 5) . "\n";
        echo "33. at(10,15): " . imagecolorat($im, 10, 15) . "\n";
        echo "34. at(10,10): " . imagecolorat($im, 10, 10) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 5.3: imagefilledellipse
        //   center=(10,10), w=h=10 → rx=ry=5
        //   Center should be filled; (17,10) is outside (dx=7 > rx=5)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilledellipse --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagefilledellipse($im, 10, 10, 10, 10, $red);
        echo "35. at(10,10): " . imagecolorat($im, 10, 10) . "\n";
        echo "36. at(14,10): " . imagecolorat($im, 14, 10) . "\n";
        echo "37. at(17,10): " . imagecolorat($im, 17, 10) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 5.4: imagefilledarc (PIE)
        //   center=(10,10), w=h=10, 0°-90°
        //   PIE includes center + arc + two radii
        //   (12,12) is at 45° from center, distance≈2.83 < 5
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilledarc (PIE) --\n";
        $im = imagecreatetruecolor(20, 20);
        $green = imagecolorallocate($im, 0, 255, 0);
        imagefilledarc($im, 10, 10, 10, 10, 0, 90, $green, IMG_ARC_PIE);
        echo "38. at(10,10): " . imagecolorat($im, 10, 10) . "\n";
        echo "39. at(12,12): " . imagecolorat($im, 12, 12) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.1: imagefill (flood fill)
        //   Draw white rectangle border, fill interior with red
        //   Fill is contained by border; outside stays black
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefill --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagerectangle($im, 2, 2, 8, 8, $white);
        imagefill($im, 5, 5, $red);
        echo "40. at(5,5): " . imagecolorat($im, 5, 5) . "\n";
        echo "41. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "42. at(0,0): " . imagecolorat($im, 0, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.2: imagefilltoborder
        //   Same setup; fill stops at white border
        //   Interior filled red; border stays white; outside stays black
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilltoborder --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagerectangle($im, 2, 2, 8, 8, $white);
        imagefilltoborder($im, 5, 5, $white, $red);
        echo "43. at(5,5): " . imagecolorat($im, 5, 5) . "\n";
        echo "44. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "45. at(0,0): " . imagecolorat($im, 0, 0) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.3: imagepolygon (old signature: points, num_points, color)
        //   Triangle: (2,2), (10,2), (6,10)
        //   Closing edge (6,10)→(2,2) passes through (4,6)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagepolygon (old sig) --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        $points = [2, 2, 10, 2, 6, 10];
        imagepolygon($im, $points, 3, $white);
        echo "46. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "47. at(6,2): " . imagecolorat($im, 6, 2) . "\n";
        echo "48. at(4,6): " . imagecolorat($im, 4, 6) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.3: imagepolygon (new signature: points, color)
        //   Same triangle, flat array, 3 args
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagepolygon (new sig flat) --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        $points = [2, 2, 10, 2, 6, 10];
        imagepolygon($im, $points, $white);
        echo "49. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "50. at(6,2): " . imagecolorat($im, 6, 2) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.3: imagepolygon (pair form: [[x,y],...], color)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagepolygon (pair form) --\n";
        $im = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($im, 255, 255, 255);
        $points = [[2, 2], [10, 2], [6, 10]];
        imagepolygon($im, $points, $white);
        echo "51. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "52. at(6,2): " . imagecolorat($im, 6, 2) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.3: imageopenpolygon (old signature)
        //   Same triangle; closing edge (6,10)→(2,2) NOT drawn
        //   (4,6) is on closing edge → should be 0
        // ════════════════════════════════════════════════════════════
        echo "\n-- imageopenpolygon (old sig) --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        $points = [2, 2, 10, 2, 6, 10];
        imageopenpolygon($im, $points, 3, $red);
        echo "53. at(2,2): " . imagecolorat($im, 2, 2) . "\n";
        echo "54. at(6,2): " . imagecolorat($im, 6, 2) . "\n";
        echo "55. at(4,6): " . imagecolorat($im, 4, 6) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.3: imagefilledpolygon (old signature)
        //   Triangle: (2,2), (10,2), (6,10)
        //   (6,5) is inside the triangle; (6,10) is a vertex
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilledpolygon (old sig) --\n";
        $im = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($im, 255, 0, 0);
        $points = [2, 2, 10, 2, 6, 10];
        imagefilledpolygon($im, $points, 3, $red);
        echo "56. at(6,5): " . imagecolorat($im, 6, 5) . "\n";
        echo "57. at(6,10): " . imagecolorat($im, 6, 10) . "\n";

        // ════════════════════════════════════════════════════════════
        // Task 6.3: imagefilledpolygon (new signature)
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagefilledpolygon (new sig) --\n";
        $im = imagecreatetruecolor(20, 20);
        $green = imagecolorallocate($im, 0, 255, 0);
        $points = [2, 2, 10, 2, 6, 10];
        imagefilledpolygon($im, $points, $green);
        echo "58. at(6,5): " . imagecolorat($im, 6, 5) . "\n";
        echo "59. at(6,10): " . imagecolorat($im, 6, 10) . "\n";

        echo "\n=== All passed ===\n";
    }
}
