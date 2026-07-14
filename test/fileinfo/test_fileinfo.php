<?php
// fileinfo 测试 — include/ 内置 MIME 检测
// 覆盖：常量、finfo_open/close、finfo_buffer (各 flags)、finfo_file、
//       mime_content_type、finfo_set_flags、异常边界
#debug === fileinfo Test ===
#debug
#debug -- Constants --
#debug FILEINFO_NONE: 0
#debug FILEINFO_SYMLINK: 2
#debug FILEINFO_DEVICES: 8
#debug FILEINFO_MIME_TYPE: 16
#debug FILEINFO_CONTINUE: 32
#debug FILEINFO_PRESERVE_ATIME: 128
#debug FILEINFO_RAW: 256
#debug FILEINFO_MIME_ENCODING: 1024
#debug FILEINFO_MIME: 1040
#debug FILEINFO_EXTENSION: 16777216
#debug
#debug -- finfo_buffer MIME_TYPE --
#debug JPEG: image/jpeg
#debug PNG: image/png
#debug GIF: image/gif
#debug BMP: image/bmp
#debug TIFF_II: image/tiff
#debug TIFF_MM: image/tiff
#debug ICO: image/x-icon
#debug PSD: image/vnd.adobe.photoshop
#debug PDF: application/pdf
#debug RTF: application/rtf
#debug PS: application/postscript
#debug DOC: application/msword
#debug ZIP: application/zip
#debug GZ: application/gzip
#debug RAR: application/x-rar
#debug 7Z: application/x-7z-compressed
#debug BZ2: application/x-bzip2
#debug XZ: application/x-xz
#debug TAR: application/x-tar
#debug TTF: font/ttf
#debug OTF: font/otf
#debug WOFF: font/woff
#debug WOFF2: font/woff2
#debug ELF: application/x-executable
#debug EXE: application/x-dosexec
#debug Java: application/java-vm
#debug SQLite: application/vnd.sqlite3
#debug XML: text/xml
#debug PHP: text/x-php
#debug WAV: audio/wav
#debug AVI: video/x-msvideo
#debug WebP: image/webp
#debug MP3_ID3: audio/mpeg
#debug FLAC: audio/flac
#debug OGG: audio/ogg
#debug MIDI: audio/midi
#debug AAC: audio/aac
#debug MP4: video/mp4
#debug WebM: video/webm
#debug Text: text/plain
#debug Binary: application/octet-stream
#debug
#debug -- finfo_buffer NONE (description) --
#debug JPEG: JPEG image data
#debug PDF: PDF document
#debug ZIP: Zip archive data
#debug
#debug -- finfo_buffer MIME (type+encoding) --
#debug JPEG: image/jpeg; charset=binary
#debug PHP: text/x-php; charset=utf-8
#debug Text: text/plain; charset=utf-8
#debug Binary: application/octet-stream; charset=binary
#debug
#debug -- finfo_buffer EXTENSION --
#debug JPEG: jpeg
#debug PNG: png
#debug PDF: pdf
#debug ZIP: zip
#debug
#debug -- finfo_buffer MIME_ENCODING --
#debug JPEG: binary
#debug PHP: utf-8
#debug Text: utf-8
#debug
#debug -- finfo_file --
#debug file JPEG: image/jpeg
#debug file PDF: application/pdf
#debug file Text: text/plain
#debug
#debug -- mime_content_type --
#debug mime JPEG: image/jpeg
#debug mime PDF: application/pdf
#debug
#debug -- finfo_set_flags --
#debug default (NONE): JPEG image data
#debug after set_flags (MIME_TYPE): image/jpeg
#debug
#debug -- Exceptions --
#debug empty filename: caught
#debug nonexistent file: caught
#debug closed resource: caught
#debug mime empty: caught
#debug mime nonexistent: caught
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== fileinfo Test ===\n\n";

        // ── 常量 ──
        echo "-- Constants --\n";
        echo "FILEINFO_NONE: " . FILEINFO_NONE . "\n";
        echo "FILEINFO_SYMLINK: " . FILEINFO_SYMLINK . "\n";
        echo "FILEINFO_DEVICES: " . FILEINFO_DEVICES . "\n";
        echo "FILEINFO_MIME_TYPE: " . FILEINFO_MIME_TYPE . "\n";
        echo "FILEINFO_CONTINUE: " . FILEINFO_CONTINUE . "\n";
        echo "FILEINFO_PRESERVE_ATIME: " . FILEINFO_PRESERVE_ATIME . "\n";
        echo "FILEINFO_RAW: " . FILEINFO_RAW . "\n";
        echo "FILEINFO_MIME_ENCODING: " . FILEINFO_MIME_ENCODING . "\n";
        echo "FILEINFO_MIME: " . FILEINFO_MIME . "\n";
        echo "FILEINFO_EXTENSION: " . FILEINFO_EXTENSION . "\n";

        // ── finfo_buffer MIME_TYPE ──
        echo "\n-- finfo_buffer MIME_TYPE --\n";
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        echo "JPEG: " . finfo_buffer($finfo, "\xFF\xD8\xFF") . "\n";
        echo "PNG: " . finfo_buffer($finfo, "\x89PNG\r\n\x1A\n") . "\n";
        echo "GIF: " . finfo_buffer($finfo, "GIF89a") . "\n";
        echo "BMP: " . finfo_buffer($finfo, "BM") . "\n";
        echo "TIFF_II: " . finfo_buffer($finfo, "II*\x00") . "\n";
        echo "TIFF_MM: " . finfo_buffer($finfo, "MM\x00*") . "\n";
        echo "ICO: " . finfo_buffer($finfo, "\x00\x00\x01\x00") . "\n";
        echo "PSD: " . finfo_buffer($finfo, "8BPS") . "\n";
        echo "PDF: " . finfo_buffer($finfo, "%PDF") . "\n";
        echo "RTF: " . finfo_buffer($finfo, "{\\rtf") . "\n";
        echo "PS: " . finfo_buffer($finfo, "%!PS") . "\n";
        echo "DOC: " . finfo_buffer($finfo, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") . "\n";
        echo "ZIP: " . finfo_buffer($finfo, "PK\x03\x04") . "\n";
        echo "GZ: " . finfo_buffer($finfo, "\x1F\x8B") . "\n";
        echo "RAR: " . finfo_buffer($finfo, "Rar!\x1A\x07") . "\n";
        echo "7Z: " . finfo_buffer($finfo, "7z\xBC\xAF\x27\x1C") . "\n";
        echo "BZ2: " . finfo_buffer($finfo, "BZh") . "\n";
        echo "XZ: " . finfo_buffer($finfo, "\xFD" . "7zXZ\x00") . "\n";

        // TAR: magic "ustar" at offset 257
        $tarBuf = str_repeat("\x00", 257) . "ustar";
        echo "TAR: " . finfo_buffer($finfo, $tarBuf) . "\n";

        echo "TTF: " . finfo_buffer($finfo, "\x00\x01\x00\x00") . "\n";
        echo "OTF: " . finfo_buffer($finfo, "OTTO") . "\n";
        echo "WOFF: " . finfo_buffer($finfo, "wOFF") . "\n";
        echo "WOFF2: " . finfo_buffer($finfo, "wOF2") . "\n";
        echo "ELF: " . finfo_buffer($finfo, "\x7F" . "ELF") . "\n";
        echo "EXE: " . finfo_buffer($finfo, "MZ") . "\n";
        echo "Java: " . finfo_buffer($finfo, "\xCA\xFE\xBA\xBE") . "\n";
        echo "SQLite: " . finfo_buffer($finfo, "SQLite format 3\x00") . "\n";
        echo "XML: " . finfo_buffer($finfo, "<?xml") . "\n";
        echo "PHP: " . finfo_buffer($finfo, "<?php") . "\n";
        echo "WAV: " . finfo_buffer($finfo, "RIFF\x00\x00\x00\x00WAVE") . "\n";
        echo "AVI: " . finfo_buffer($finfo, "RIFF\x00\x00\x00\x00" . "AVI ") . "\n";
        echo "WebP: " . finfo_buffer($finfo, "RIFF\x00\x00\x00\x00WEBP") . "\n";
        echo "MP3_ID3: " . finfo_buffer($finfo, "ID3") . "\n";
        echo "FLAC: " . finfo_buffer($finfo, "fLaC") . "\n";
        echo "OGG: " . finfo_buffer($finfo, "OggS") . "\n";
        echo "MIDI: " . finfo_buffer($finfo, "MThd") . "\n";
        echo "AAC: " . finfo_buffer($finfo, "\xFF\xF1") . "\n";

        // MP4: magic "ftyp" at offset 4
        echo "MP4: " . finfo_buffer($finfo, "\x00\x00\x00\x20" . "ftyp") . "\n";
        echo "WebM: " . finfo_buffer($finfo, "\x1A\x45\xDF\xA3") . "\n";

        echo "Text: " . finfo_buffer($finfo, "Hello, World!") . "\n";
        echo "Binary: " . finfo_buffer($finfo, "\x00\x01\x02\x03") . "\n";

        finfo_close($finfo);

        // ── finfo_buffer NONE (description) ──
        echo "\n-- finfo_buffer NONE (description) --\n";
        $finfo2 = finfo_open(FILEINFO_NONE);
        echo "JPEG: " . finfo_buffer($finfo2, "\xFF\xD8\xFF", FILEINFO_NONE) . "\n";
        echo "PDF: " . finfo_buffer($finfo2, "%PDF", FILEINFO_NONE) . "\n";
        echo "ZIP: " . finfo_buffer($finfo2, "PK\x03\x04", FILEINFO_NONE) . "\n";
        finfo_close($finfo2);

        // ── finfo_buffer MIME (type+encoding) ──
        echo "\n-- finfo_buffer MIME (type+encoding) --\n";
        $finfo3 = finfo_open(FILEINFO_MIME);
        echo "JPEG: " . finfo_buffer($finfo3, "\xFF\xD8\xFF") . "\n";
        echo "PHP: " . finfo_buffer($finfo3, "<?php") . "\n";
        echo "Text: " . finfo_buffer($finfo3, "Hello, World!") . "\n";
        echo "Binary: " . finfo_buffer($finfo3, "\x00\x01\x02\x03") . "\n";
        finfo_close($finfo3);

        // ── finfo_buffer EXTENSION ──
        echo "\n-- finfo_buffer EXTENSION --\n";
        $finfo4 = finfo_open(FILEINFO_EXTENSION);
        echo "JPEG: " . finfo_buffer($finfo4, "\xFF\xD8\xFF") . "\n";
        echo "PNG: " . finfo_buffer($finfo4, "\x89PNG\r\n\x1A\n") . "\n";
        echo "PDF: " . finfo_buffer($finfo4, "%PDF") . "\n";
        echo "ZIP: " . finfo_buffer($finfo4, "PK\x03\x04") . "\n";
        finfo_close($finfo4);

        // ── finfo_buffer MIME_ENCODING ──
        echo "\n-- finfo_buffer MIME_ENCODING --\n";
        $finfo5 = finfo_open(FILEINFO_MIME_ENCODING);
        echo "JPEG: " . finfo_buffer($finfo5, "\xFF\xD8\xFF") . "\n";
        echo "PHP: " . finfo_buffer($finfo5, "<?php") . "\n";
        echo "Text: " . finfo_buffer($finfo5, "Hello, World!") . "\n";
        finfo_close($finfo5);

        // ── finfo_file ──
        echo "\n-- finfo_file --\n";
        $dir = __DIR__;
        file_put_contents($dir . "/test_jpeg.bin", "\xFF\xD8\xFF");
        file_put_contents($dir . "/test_pdf.bin", "%PDF");
        file_put_contents($dir . "/test_text.bin", "Hello, World!");

        $finfo6 = finfo_open(FILEINFO_MIME_TYPE);
        echo "file JPEG: " . finfo_file($finfo6, $dir . "/test_jpeg.bin") . "\n";
        echo "file PDF: " . finfo_file($finfo6, $dir . "/test_pdf.bin") . "\n";
        echo "file Text: " . finfo_file($finfo6, $dir . "/test_text.bin") . "\n";
        finfo_close($finfo6);

        // ── mime_content_type ──
        echo "\n-- mime_content_type --\n";
        echo "mime JPEG: " . mime_content_type($dir . "/test_jpeg.bin") . "\n";
        echo "mime PDF: " . mime_content_type($dir . "/test_pdf.bin") . "\n";

        // ── finfo_set_flags ──
        echo "\n-- finfo_set_flags --\n";
        $finfo7 = finfo_open(FILEINFO_NONE);
        // 默认 flags = NONE → 返回描述
        echo "default (NONE): " . finfo_buffer($finfo7, "\xFF\xD8\xFF") . "\n";
        // 设置为 MIME_TYPE
        finfo_set_flags($finfo7, FILEINFO_MIME_TYPE);
        echo "after set_flags (MIME_TYPE): " . finfo_buffer($finfo7, "\xFF\xD8\xFF") . "\n";
        finfo_close($finfo7);

        // ── 异常 ──
        echo "\n-- Exceptions --\n";
        $finfo8 = finfo_open(FILEINFO_MIME_TYPE);

        $caught = 0;
        try {
            finfo_file($finfo8, "");
        } catch (Exception $e) {
            $caught = 1;
        }
        echo "empty filename: " . ($caught ? "caught" : "not caught") . "\n";

        $caught = 0;
        try {
            finfo_file($finfo8, $dir . "/nonexistent_file.bin");
        } catch (Exception $e) {
            $caught = 1;
        }
        echo "nonexistent file: " . ($caught ? "caught" : "not caught") . "\n";

        $caught = 0;
        $closedFinfo = finfo_open(FILEINFO_MIME_TYPE);
        finfo_close($closedFinfo);
        try {
            finfo_buffer($closedFinfo, "\xFF\xD8\xFF");
        } catch (Exception $e) {
            $caught = 1;
        }
        echo "closed resource: " . ($caught ? "caught" : "not caught") . "\n";

        $caught = 0;
        try {
            mime_content_type("");
        } catch (Exception $e) {
            $caught = 1;
        }
        echo "mime empty: " . ($caught ? "caught" : "not caught") . "\n";

        $caught = 0;
        try {
            mime_content_type($dir . "/nonexistent_file.bin");
        } catch (Exception $e) {
            $caught = 1;
        }
        echo "mime nonexistent: " . ($caught ? "caught" : "not caught") . "\n";

        finfo_close($finfo8);

        // 清理
        unlink($dir . "/test_jpeg.bin");
        unlink($dir . "/test_pdf.bin");
        unlink($dir . "/test_text.bin");

        echo "\n=== All passed ===\n";
    }
}
