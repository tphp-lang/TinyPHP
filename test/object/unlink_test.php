<?php
// unlink 测试
#debug === Unlink Test ===
#debug
#debug 1. Create and delete file
#debug write_ok=1
#debug file_exists_before=1
#debug unlink_ok=1
#debug file_exists_after=0
#debug
#debug 2. Unlink non-existent file
#debug unlink_missing=0
#debug
#debug === OK ===

class Main
{
    public function main(): void
    {
        echo "=== Unlink Test ===\n\n";

        // Test 1: Create and delete file
        echo "1. Create and delete file\n";
        $tmpFile = __DIR__ . "/unlink_test.tmp";
        $written = file_put_contents($tmpFile, "test");
        echo "write_ok=" . ($written ? 1 : 0) . "\n";

        // Check file exists (try to read it)
        $f = new File($tmpFile, "rb");
        echo "file_exists_before=" . $f->isOpen() . "\n";
        $f->close();

        // Delete file
        $deleted = unlink($tmpFile);
        echo "unlink_ok=" . ($deleted ? 1 : 0) . "\n";

        // Check file doesn't exist
        $f2 = new File($tmpFile, "rb");
        echo "file_exists_after=" . $f2->isOpen() . "\n";
        $f2->close();
        echo "\n";

        // Test 2: Unlink non-existent file
        echo "2. Unlink non-existent file\n";
        $deleted2 = unlink("/nonexistent/path/file.txt");
        echo "unlink_missing=" . ($deleted2 ? 1 : 0) . "\n";

        echo "\n=== OK ===\n";
    }
}
