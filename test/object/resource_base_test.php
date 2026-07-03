<?php
// Resource 基类功能测试
#debug === Resource Base Class Test ===
#debug
#debug 1. Resource construction
#debug type=0
#debug
#debug 2. File construction (valid path)
#debug file_type=0
#debug file_open=1
#debug is_resource=1
#debug
#debug 3. File construction (invalid path)
#debug invalid_type=0
#debug invalid_open=0
#debug
#debug 4. File close idempotent
#debug before_close=1
#debug after_first_close=0
#debug after_second_close=0
#debug
#debug 5. File read/write cycle
#debug write_ok=1
#debug read_ok=1
#debug content=hello
#debug
#debug 6. File EOF detection
#debug eof_after=1
#debug
#debug 7. is_resource checks
#debug is_resource_file=1
#debug is_resource_int=0
#debug is_resource_null=0
#debug
#debug 8. Resource type constants
#debug IS_RSRC=15
#debug RSRC_TYPE_UNKNOWN=0
#debug RSRC_TYPE_FILE=1
#debug RSRC_TYPE_SOCKET=2
#debug RSRC_TYPE_DB=3
#debug RSRC_TYPE_PROCESS=4
#debug RSRC_TYPE_DIR=5
#debug
#debug === OK ===

class Main
{
    public function main(): void
    {
        echo "=== Resource Base Class Test ===\n\n";

        // Test 1: Resource construction
        echo "1. Resource construction\n";
        $r = new Resource();
        echo "type=" . $r->getType() . "\n";
        echo "\n";

        // Test 2: File construction (valid path)
        echo "2. File construction (valid path)\n";
        $tmpFile = __DIR__ . "/resource_test.tmp";
        file_put_contents($tmpFile, "test content");
        $f = new File($tmpFile, "rb");
        echo "file_type=" . $f->getType() . "\n";
        echo "file_open=" . $f->isOpen() . "\n";
        echo "is_resource=" . is_resource($f) . "\n";
        $f->close();
        echo "\n";

        // Test 3: File construction (invalid path)
        echo "3. File construction (invalid path)\n";
        $invalid = new File("/nonexistent/path/file.txt", "rb");
        echo "invalid_type=" . $invalid->getType() . "\n";
        echo "invalid_open=" . $invalid->isOpen() . "\n";
        echo "\n";

        // Test 4: File close idempotent
        echo "4. File close idempotent\n";
        $fc = new File($tmpFile, "rb");
        echo "before_close=" . $fc->isOpen() . "\n";
        $fc->close();
        echo "after_first_close=" . $fc->isOpen() . "\n";
        $fc->close(); // Should be safe
        echo "after_second_close=" . $fc->isOpen() . "\n";
        echo "\n";

        // Test 5: File read/write cycle
        echo "5. File read/write cycle\n";
        $writeFile = __DIR__ . "/resource_test_write.tmp";
        $fw = new File($writeFile, "wb");
        $len = $fw->write("hello");
        echo "write_ok=" . ($len == 5) . "\n";
        $fw->close();

        $fr = new File($writeFile, "rb");
        $data = $fr->read(5);
        echo "read_ok=" . ($data == "hello") . "\n";
        echo "content=" . $data . "\n";
        $fr->close();
        echo "\n";

        // Test 6: File EOF detection
        echo "6. File EOF detection\n";
        $fe = new File($tmpFile, "rb");
        $fe->read(100); // Read more than file size
        echo "eof_after=" . $fe->eof() . "\n";
        $fe->close();
        echo "\n";

        // Test 7: is_resource checks
        echo "7. is_resource checks\n";
        $f2 = new File($tmpFile, "rb");
        echo "is_resource_file=" . is_resource($f2) . "\n";
        $f2->close();
        echo "is_resource_int=" . is_resource(42) . "\n";
        echo "is_resource_null=" . is_resource(null) . "\n";
        echo "\n";

        // Test 8: Resource type constants
        echo "8. Resource type constants\n";
        echo "IS_RSRC=" . IS_RSRC . "\n";
        echo "RSRC_TYPE_UNKNOWN=" . RSRC_TYPE_UNKNOWN . "\n";
        echo "RSRC_TYPE_FILE=" . RSRC_TYPE_FILE . "\n";
        echo "RSRC_TYPE_SOCKET=" . RSRC_TYPE_SOCKET . "\n";
        echo "RSRC_TYPE_DB=" . RSRC_TYPE_DB . "\n";
        echo "RSRC_TYPE_PROCESS=" . RSRC_TYPE_PROCESS . "\n";
        echo "RSRC_TYPE_DIR=" . RSRC_TYPE_DIR . "\n";

        echo "\n=== OK ===\n";

        // Cleanup
        unlink($tmpFile);
        unlink($writeFile);
    }
}
