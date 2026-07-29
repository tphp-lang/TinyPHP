<?php
// ext/gd 扩展测试 — Task 18（gd_info / imagetypes 真实能力）
//
// 验证 gd_info() 和 imagetypes() 真实反映纯 phpc 实现能力：
//   - 不谎报 JPEG/WebP/AVIF/XPM/FreeType 支持
//   - imagetypes() 位掩码不含 IMG_JPG(4)/IMG_WEBP(64)/IMG_AVIF(1)
#import gd

#debug === GD Info & Types Test (Task 18) ===
#debug
#debug -- gd_info (unsupported = false) --
#debug 1. JPEG Support: 0
#debug 2. WebP Support: 0
#debug 3. AVIF Support: 0
#debug 4. XPM Support: 0
#debug 5. FreeType Support: 0
#debug
#debug -- gd_info (supported = true) --
#debug 6. PNG Support: 1
#debug 7. GIF Read Support: 1
#debug 8. GIF Create Support: 1
#debug 9. WBMP Support: 1
#debug 10. XBM Support: 1
#debug 11. BMP Support: 1
#debug 12. TGA Read Support: 1
#debug
#debug -- imagetypes (bitmask excludes unsupported) --
#debug 13. types & IMG_AVIF: 0
#debug 14. types & IMG_JPG: 0
#debug 15. types & IMG_WEBP: 0
#debug 16. types & IMG_XPM: 0
#debug
#debug -- imagetypes (bitmask includes supported) --
#debug 17. types & IMG_GIF: 2
#debug 18. types & IMG_PNG: 8
#debug 19. types & IMG_WBMP: 16
#debug 20. types & IMG_BMP: 128
#debug 21. types & IMG_TGA: 256
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== GD Info & Types Test (Task 18) ===\n\n";

        // ════════════════════════════════════════════════════════════
        // gd_info — 不支持的格式必须为 false
        // ════════════════════════════════════════════════════════════
        $info = gd_info();

        echo "-- gd_info (unsupported = false) --\n";
        echo "1. JPEG Support: " . ($info["JPEG Support"] ? "1" : "0") . "\n";
        echo "2. WebP Support: " . ($info["WebP Support"] ? "1" : "0") . "\n";
        echo "3. AVIF Support: " . ($info["AVIF Support"] ? "1" : "0") . "\n";
        echo "4. XPM Support: " . ($info["XPM Support"] ? "1" : "0") . "\n";
        echo "5. FreeType Support: " . ($info["FreeType Support"] ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // gd_info — 支持的格式必须为 true
        // ════════════════════════════════════════════════════════════
        echo "\n-- gd_info (supported = true) --\n";
        echo "6. PNG Support: " . ($info["PNG Support"] ? "1" : "0") . "\n";
        echo "7. GIF Read Support: " . ($info["GIF Read Support"] ? "1" : "0") . "\n";
        echo "8. GIF Create Support: " . ($info["GIF Create Support"] ? "1" : "0") . "\n";
        echo "9. WBMP Support: " . ($info["WBMP Support"] ? "1" : "0") . "\n";
        echo "10. XBM Support: " . ($info["XBM Support"] ? "1" : "0") . "\n";
        echo "11. BMP Support: " . ($info["BMP Support"] ? "1" : "0") . "\n";
        echo "12. TGA Read Support: " . ($info["TGA Read Support"] ? "1" : "0") . "\n";

        // ════════════════════════════════════════════════════════════
        // imagetypes — 位掩码不含不支持的格式
        // ════════════════════════════════════════════════════════════
        $types = imagetypes();

        echo "\n-- imagetypes (bitmask excludes unsupported) --\n";
        echo "13. types & IMG_AVIF: " . ($types & IMG_AVIF) . "\n";
        echo "14. types & IMG_JPG: " . ($types & IMG_JPG) . "\n";
        echo "15. types & IMG_WEBP: " . ($types & IMG_WEBP) . "\n";
        echo "16. types & IMG_XPM: " . ($types & IMG_XPM) . "\n";

        // ════════════════════════════════════════════════════════════
        // imagetypes — 位掩码含支持的格式
        // ════════════════════════════════════════════════════════════
        echo "\n-- imagetypes (bitmask includes supported) --\n";
        echo "17. types & IMG_GIF: " . ($types & IMG_GIF) . "\n";
        echo "18. types & IMG_PNG: " . ($types & IMG_PNG) . "\n";
        echo "19. types & IMG_WBMP: " . ($types & IMG_WBMP) . "\n";
        echo "20. types & IMG_BMP: " . ($types & IMG_BMP) . "\n";
        echo "21. types & IMG_TGA: " . ($types & IMG_TGA) . "\n";

        echo "\n=== All passed ===\n";
    }
}
