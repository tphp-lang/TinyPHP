<?php
// exif 扩展测试 — 纯 phpc 实现验证（全面覆盖）
// 测试范围：exif_imagetype / exif_tagname / exif_read_data / exif_thumbnail
// 覆盖：JPEG LE/BE、TIFF II/MM、无 EXIF、不存在文件、字节序、边界情况
#import exif

#debug === EXIF Test (pure phpc) ===
#debug
#debug -- imagetype --
#debug 1. JPEG: 2
#debug 2. GIF: 1
#debug 3. PNG: 3
#debug 4. BMP: 6
#debug 5. TIFF_II: 7
#debug 6. TIFF_MM: 8
#debug 7. unknown: 0
#debug 8. not-exist: 0
#debug
#debug -- tagname --
#debug 9. Make(0x010F): Make
#debug 10. GPSAltitude(0x0006): GPSAltitude
#debug 11. unknown(0xFFFF) len: 0
#debug
#debug -- read_data JPEG LE --
#debug 12. Make: TestCamera
#debug 13. Model: X-100
#debug 14. Orientation: 1
#debug 15. DateTime: 2024:01:15 14:30:00
#debug 16. ExposureTime: 1/125
#debug 17. FNumber: 28/10
#debug 18. ISOSpeedRatings: 200
#debug 19. GPSLatitudeRef: N
#debug 20. GPSAltitude: 100
#debug
#debug -- read_data JPEG BE --
#debug 21. Make: TestCamera
#debug 22. ExposureTime: 1/125
#debug 23. ISOSpeedRatings: 200
#debug 24. GPSAltitude: 100
#debug
#debug -- read_data TIFF II --
#debug 25. Make: TestCamera
#debug 26. ExposureTime: 1/125
#debug 27. GPSAltitude: 100
#debug
#debug -- read_data TIFF MM --
#debug 28. Make: TestCamera
#debug 29. ExposureTime: 1/125
#debug 30. GPSAltitude: 100
#debug
#debug -- edge cases --
#debug 31. no-exif JPEG count: 0
#debug 32. not-exist count: 0
#debug
#debug -- thumbnail --
#debug 33. thumb imagetype: 2
#debug 34. thumb width: 0
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== EXIF Test (pure phpc) ===\n\n";

        // ── imagetype 全类型测试 ──
        echo "-- imagetype --\n";
        exif_make_test_header("t_gif.jpg", 0x47, 0x49);
        exif_make_test_header("t_jpg.jpg", 0xFF, 0xD8);
        exif_make_test_header("t_png.jpg", 0x89, 0x50);
        exif_make_test_header("t_bmp.jpg", 0x42, 0x4D);
        exif_make_test_header("t_t2.jpg", 0x49, 0x49);
        exif_make_test_header("t_t3.jpg", 0x4D, 0x4D);
        exif_make_test_header("t_unk.jpg", 0x00, 0x00);

        echo "1. JPEG: " . exif_imagetype("t_jpg.jpg") . "\n";
        echo "2. GIF: " . exif_imagetype("t_gif.jpg") . "\n";
        echo "3. PNG: " . exif_imagetype("t_png.jpg") . "\n";
        echo "4. BMP: " . exif_imagetype("t_bmp.jpg") . "\n";
        echo "5. TIFF_II: " . exif_imagetype("t_t2.jpg") . "\n";
        echo "6. TIFF_MM: " . exif_imagetype("t_t3.jpg") . "\n";
        echo "7. unknown: " . exif_imagetype("t_unk.jpg") . "\n";
        echo "8. not-exist: " . exif_imagetype("no_such_file.jpg") . "\n";

        // ── tagname ──
        echo "\n-- tagname --\n";
        echo "9. Make(0x010F): " . exif_tagname(0x010F) . "\n";
        echo "10. GPSAltitude(0x0006): " . exif_tagname(0x0006) . "\n";
        $unk = exif_tagname(0xFFFF);
        echo "11. unknown(0xFFFF) len: " . strlen($unk) . "\n";

        // ── read_data JPEG LE ──
        echo "\n-- read_data JPEG LE --\n";
        exif_make_test_jpeg_ex("t_le.jpg", 1);
        $d = exif_read_data("t_le.jpg");
        echo "12. Make: " . $d["Make"] . "\n";
        echo "13. Model: " . $d["Model"] . "\n";
        echo "14. Orientation: " . $d["Orientation"] . "\n";
        echo "15. DateTime: " . $d["DateTime"] . "\n";
        echo "16. ExposureTime: " . $d["ExposureTime"] . "\n";
        echo "17. FNumber: " . $d["FNumber"] . "\n";
        echo "18. ISOSpeedRatings: " . $d["ISOSpeedRatings"] . "\n";
        echo "19. GPSLatitudeRef: " . $d["GPSLatitudeRef"] . "\n";
        echo "20. GPSAltitude: " . $d["GPSAltitude"] . "\n";

        // ── read_data JPEG BE ──
        echo "\n-- read_data JPEG BE --\n";
        exif_make_test_jpeg_ex("t_be.jpg", 0);
        $d2 = exif_read_data("t_be.jpg");
        echo "21. Make: " . $d2["Make"] . "\n";
        echo "22. ExposureTime: " . $d2["ExposureTime"] . "\n";
        echo "23. ISOSpeedRatings: " . $d2["ISOSpeedRatings"] . "\n";
        echo "24. GPSAltitude: " . $d2["GPSAltitude"] . "\n";

        // ── read_data TIFF II ──
        echo "\n-- read_data TIFF II --\n";
        exif_make_test_tiff("t_t2.tif", 1);
        $d3 = exif_read_data("t_t2.tif");
        echo "25. Make: " . $d3["Make"] . "\n";
        echo "26. ExposureTime: " . $d3["ExposureTime"] . "\n";
        echo "27. GPSAltitude: " . $d3["GPSAltitude"] . "\n";

        // ── read_data TIFF MM ──
        echo "\n-- read_data TIFF MM --\n";
        exif_make_test_tiff("t_t3.tif", 0);
        $d4 = exif_read_data("t_t3.tif");
        echo "28. Make: " . $d4["Make"] . "\n";
        echo "29. ExposureTime: " . $d4["ExposureTime"] . "\n";
        echo "30. GPSAltitude: " . $d4["GPSAltitude"] . "\n";

        // ── 边界情况 ──
        echo "\n-- edge cases --\n";
        exif_make_test_header("t_noexif.jpg", 0xFF, 0xD8);
        $d5 = exif_read_data("t_noexif.jpg");
        echo "31. no-exif JPEG count: " . count($d5) . "\n";
        $d6 = exif_read_data("no_such_file.jpg");
        echo "32. not-exist count: " . count($d6) . "\n";

        // ── thumbnail ──
        echo "\n-- thumbnail --\n";
        $th = exif_thumbnail("t_le.jpg");
        echo "33. thumb imagetype: " . $th["imagetype"] . "\n";
        echo "34. thumb width: " . $th["width"] . "\n";

        echo "\n=== All passed ===\n";
    }
}
