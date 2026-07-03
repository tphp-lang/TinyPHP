<?php
// Resource 类型测试
// 模拟 PHP 的 resource 类型设计
#debug === Resource Test ===
#debug
#debug 1. File resource creation
#debug file_type=0
#debug is_resource=1
#debug
#debug 2. File read/write
#debug write_len=5
#debug read_data=hello
#debug
#debug 3. File close (idempotent)
#debug before_close=1
#debug after_first_close=0
#debug after_second_close=0
#debug
#debug 4. File on invalid path
#debug invalid_type=0
#debug invalid_open=0
#debug invalid_read=
#debug
#debug 5. is_resource checks
#debug is_resource_file=1
#debug is_resource_int=0
#debug is_resource_string=0
#debug
#debug 6. Resource type constants
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
        echo "=== Resource Test ===\n\n";

        // Test 1: File resource creation
        echo "1. File resource creation\n";
        $tmpFile = __DIR__ . "/resource_test.tmp";
        file_put_contents($tmpFile, "test content");
        $f = new File($tmpFile, "rb");
        echo "file_type=" . $f->getType() . "\n";
        echo "is_resource=" . is_resource($f) . "\n";
        $f->close();
        echo "\n";

        // Test 2: File read/write
        echo "2. File read/write\n";
        $writeFile = __DIR__ . "/resource_test_write.tmp";
        $fw = new File($writeFile, "wb");
        $len = $fw->write("hello");
        echo "write_len=" . $len . "\n";
        $fw->close();

        $fr = new File($writeFile, "rb");
        $data = $fr->read(5);
        echo "read_data=" . $data . "\n";
        $fr->close();
        echo "\n";

        // Test 3: File close (idempotent)
        echo "3. File close (idempotent)\n";
        $fc = new File($tmpFile, "rb");
        echo "before_close=" . $fc->isOpen() . "\n";
        $fc->close();
        echo "after_first_close=" . $fc->isOpen() . "\n";
        $fc->close(); // Should be safe (idempotent)
        echo "after_second_close=" . $fc->isOpen() . "\n";
        echo "\n";

        // Test 4: File on invalid path
        echo "4. File on invalid path\n";
        $invalid = new File("/nonexistent/path/file.txt", "rb");
        echo "invalid_type=" . $invalid->getType() . "\n";
        echo "invalid_open=" . $invalid->isOpen() . "\n";
        $data = $invalid->read(10);
        echo "invalid_read=" . $data . "\n";
        echo "\n";

        // Test 5: is_resource checks
        echo "5. is_resource checks\n";
        $f2 = new File($tmpFile, "rb");
        echo "is_resource_file=" . is_resource($f2) . "\n";
        $f2->close();
        echo "is_resource_int=" . is_resource(42) . "\n";
        echo "is_resource_string=" . is_resource("hello") . "\n";
        echo "\n";

        // Test 6: Resource type constants
        echo "6. Resource type constants\n";
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
