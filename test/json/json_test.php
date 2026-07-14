<?php
#debug ===== 1. encode primitives =====
#debug string(4) "null"
#debug string(4) "true"
#debug string(5) "false"
#debug string(2) "42"
#debug string(2) "-7"
#debug string(4) "3.14"
#debug string(7) ""hello""
#debug
#debug ===== 2. encode array =====
#debug string(7) "[1,2,3]"
#debug string(21) "[10,"text",true,null]"
#debug string(2) "[]"
#debug
#debug ===== 3. encode nested =====
#debug string(13) "[[1,2],[3,4]]"
#debug
#debug ===== 4. decode primitives =====
#debug int(42)
#debug float(3.14)
#debug bool(true)
#debug bool(false)
#debug NULL
#debug string(5) "hello"
#debug
#debug ===== 5. decode array =====
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug bool(true)
#debug array(4) {
#debug   [0]=>
#debug   int(10)
#debug   [1]=>
#debug   bool(true)
#debug   [2]=>
#debug   NULL
#debug
#debug   [3]=>
#debug   string(1) "x"
#debug }
#debug
#debug ===== 6. decode object =====
#debug array(2) {
#debug   ["name"]=>
#debug   string(5) "alice"
#debug   ["age"]=>
#debug   int(30)
#debug }
#debug bool(true)
#debug
#debug ===== 7. round-trip =====
#debug string(11) "[1,2,3,4,5]"
#debug string(11) "[1,2,3,4,5]"
#debug
#debug ===== 8. string escape =====
#debug "say \"hi\""
#debug "line1\nline2"
#debug
#debug === all json tests done ===

class Main
{
    public function main(): void
    {
        echo "===== 1. encode primitives =====\n";
        var_dump(json_encode(null));
        var_dump(json_encode(true));
        var_dump(json_encode(false));
        var_dump(json_encode(42));
        var_dump(json_encode(-7));
        var_dump(json_encode(3.14));
        var_dump(json_encode("hello"));

        echo "\n===== 2. encode array =====\n";
        $a = [1, 2, 3];
        var_dump(json_encode($a));
        $b = [10, "text", true, null];
        var_dump(json_encode($b));
        $empty = [];
        var_dump(json_encode($empty));

        echo "\n===== 3. encode nested =====\n";
        $nested = [[1, 2], [3, 4]];
        var_dump(json_encode($nested));

        echo "\n===== 4. decode primitives =====\n";
        $d1 = json_decode("42");        var_dump($d1);
        $d2 = json_decode("3.14");      var_dump($d2);
        $d3 = json_decode("true");      var_dump($d3);
        $d4 = json_decode("false");     var_dump($d4);
        $d5 = json_decode("null");      var_dump($d5);
        $d6 = json_decode('"hello"');   var_dump($d6);

        echo "\n===== 5. decode array =====\n";
        $arr1 = json_decode("[1,2,3]");
        var_dump($arr1);
        var_dump(is_array($arr1));
        $arr2 = json_decode('[10, true, null, "x"]');
        var_dump($arr2);

        echo "\n===== 6. decode object =====\n";
        $obj = json_decode('{"name":"alice","age":30}');
        var_dump($obj);
        var_dump(is_array($obj));

        echo "\n===== 7. round-trip =====\n";
        $orig = [1, 2, 3, 4, 5];
        $enc  = json_encode($orig);
        $dec  = json_decode($enc);
        $enc2 = json_encode($dec);
        var_dump($enc);
        var_dump($enc2);

        echo "\n===== 8. string escape =====\n";
        echo json_encode('say "hi"') . "\n";
        echo json_encode("line1\nline2") . "\n";

        echo "\n=== all json tests done ===\n";
    }
}
