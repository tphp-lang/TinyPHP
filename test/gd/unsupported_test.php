<?php
// ext/gd 扩展测试 — Task 18（不支持格式的明确报错）
//
// 验证纯 phpc 不支持的格式（JPEG/WebP/AVIF/XPM/FreeType）及 Windows 专用函数
// 调用时必须抛出 RuntimeException，消息明确指出格式不支持，不得静默返回 false。
#import gd

#debug === GD Task 18 Test (Unsupported Formats) ===
#debug
#debug -- JPEG --
#debug 1. imagecreatefromjpeg throws: 1
#debug 2. imagecreatefromjpeg msg has JPEG: 1
#debug 3. imagejpeg throws: 1
#debug 4. imagejpeg msg has JPEG: 1
#debug
#debug -- WebP --
#debug 5. imagecreatefromwebp throws: 1
#debug 6. imagecreatefromwebp msg has WebP: 1
#debug 7. imagewebp throws: 1
#debug 8. imagewebp msg has WebP: 1
#debug
#debug -- AVIF --
#debug 9. imagecreatefromavif throws: 1
#debug 10. imagecreatefromavif msg has AVIF: 1
#debug 11. imageavif throws: 1
#debug 12. imageavif msg has AVIF: 1
#debug
#debug -- XPM --
#debug 13. imagecreatefromxpm throws: 1
#debug 14. imagecreatefromxpm msg has XPM: 1
#debug
#debug -- FreeType --
#debug 15. imagettftext throws: 1
#debug 16. imagettftext msg has FreeType: 1
#debug 17. imagefttext throws: 1
#debug 18. imagefttext msg has FreeType: 1
#debug 19. imagettfbbox throws: 1
#debug 20. imagettfbbox msg has FreeType: 1
#debug 21. imageftbbox throws: 1
#debug 22. imageftbbox msg has FreeType: 1
#debug
#debug -- Windows-only --
#debug 23. imagegrabwindow throws: 1
#debug 24. imagegrabwindow msg has Windows: 1
#debug 25. imagegrabscreen throws: 1
#debug 26. imagegrabscreen msg has Windows: 1
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Task 18 Test (Unsupported Formats) ===\n\n";

        // 用于 imageXxx 输出类函数的图像
        $im = imagecreatetruecolor(8, 8);

        // ════════════════════════════════════════════════════════════
        // JPEG
        // ════════════════════════════════════════════════════════════
        echo "-- JPEG --\n";

        $caught = 0;
        $msg = "";
        try {
            imagecreatefromjpeg("test.jpg");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "1. imagecreatefromjpeg throws: " . $caught . "\n";
        echo "2. imagecreatefromjpeg msg has JPEG: " . (str_contains($msg, "JPEG") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imagejpeg($im);
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "3. imagejpeg throws: " . $caught . "\n";
        echo "4. imagejpeg msg has JPEG: " . (str_contains($msg, "JPEG") ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // WebP
        // ════════════════════════════════════════════════════════════
        echo "\n-- WebP --\n";

        $caught = 0;
        $msg = "";
        try {
            imagecreatefromwebp("test.webp");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "5. imagecreatefromwebp throws: " . $caught . "\n";
        echo "6. imagecreatefromwebp msg has WebP: " . (str_contains($msg, "WebP") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imagewebp($im);
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "7. imagewebp throws: " . $caught . "\n";
        echo "8. imagewebp msg has WebP: " . (str_contains($msg, "WebP") ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // AVIF
        // ════════════════════════════════════════════════════════════
        echo "\n-- AVIF --\n";

        $caught = 0;
        $msg = "";
        try {
            imagecreatefromavif("test.avif");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "9. imagecreatefromavif throws: " . $caught . "\n";
        echo "10. imagecreatefromavif msg has AVIF: " . (str_contains($msg, "AVIF") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imageavif($im);
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "11. imageavif throws: " . $caught . "\n";
        echo "12. imageavif msg has AVIF: " . (str_contains($msg, "AVIF") ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // XPM
        // ════════════════════════════════════════════════════════════
        echo "\n-- XPM --\n";

        $caught = 0;
        $msg = "";
        try {
            imagecreatefromxpm("test.xpm");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "13. imagecreatefromxpm throws: " . $caught . "\n";
        echo "14. imagecreatefromxpm msg has XPM: " . (str_contains($msg, "XPM") ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // FreeType
        // ════════════════════════════════════════════════════════════
        echo "\n-- FreeType --\n";

        $caught = 0;
        $msg = "";
        try {
            imagettftext($im, 12.0, 0.0, 1, 1, 0, "arial.ttf", "hi");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "15. imagettftext throws: " . $caught . "\n";
        echo "16. imagettftext msg has FreeType: " . (str_contains($msg, "FreeType") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imagefttext($im, 12.0, 0.0, 1, 1, 0, "arial.ttf", "hi");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "17. imagefttext throws: " . $caught . "\n";
        echo "18. imagefttext msg has FreeType: " . (str_contains($msg, "FreeType") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imagettfbbox(12.0, 0.0, "arial.ttf", "hi");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "19. imagettfbbox throws: " . $caught . "\n";
        echo "20. imagettfbbox msg has FreeType: " . (str_contains($msg, "FreeType") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imageftbbox(12.0, 0.0, "arial.ttf", "hi");
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "21. imageftbbox throws: " . $caught . "\n";
        echo "22. imageftbbox msg has FreeType: " . (str_contains($msg, "FreeType") ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // Windows-only
        // ════════════════════════════════════════════════════════════
        echo "\n-- Windows-only --\n";

        $caught = 0;
        $msg = "";
        try {
            imagegrabwindow(0);
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "23. imagegrabwindow throws: " . $caught . "\n";
        echo "24. imagegrabwindow msg has Windows: " . (str_contains($msg, "Windows") ? "1" : "0") . "\n";

        $caught = 0;
        $msg = "";
        try {
            imagegrabscreen();
        } catch (Exception $e) {
            $caught = 1;
            $msg = $e->getMessage();
        }
        echo "25. imagegrabscreen throws: " . $caught . "\n";
        echo "26. imagegrabscreen msg has Windows: " . (str_contains($msg, "Windows") ? "1" : "0") . "\n";

        imagedestroy($im);

        echo "\n=== All passed ===\n";
    }
}
