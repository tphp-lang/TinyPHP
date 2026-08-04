<?php
// stdclass_edge.php — 测试 stdClass 边界情况
class Main {
    public function main(): void {
        echo "=== stdClass edge case tests ===\n";

        // ── 空 stdClass 的 var_dump（应显示 object(stdClass)#N (0) {}）──
        echo "[1] var_dump empty stdClass:\n";
        $empty = new stdClass();
        var_dump($empty);

        // ── 访问未定义属性（返回 null，用 isset 验证 false）──
        echo "[2] access undefined property:\n";
        $obj = new stdClass();
        $val = $obj->undefined;
        echo "  isset(undefined)=" . (isset($obj->undefined) ? "true" : "false") . "\n";
        echo "  value=[" . (string)$val . "]\n";

        // ── 空 stdClass foreach ──
        echo "[3] foreach empty stdClass:\n";
        $count = 0;
        foreach ($empty as $k => $v) {
            $count = $count + 1;
        }
        echo "  iterations=" . (string)$count . "\n";

        // ── (object) 空数组 → 空 stdClass ──
        echo "[4] (object) empty array:\n";
        $emptyArr = [];
        $objFromEmpty = (object) $emptyArr;
        $vars = get_object_vars($objFromEmpty);
        echo "  count=" . (string)count($vars) . "\n";
        var_dump($objFromEmpty);

        // ── (array) 空 stdClass → 空数组 ──
        echo "[5] (array) empty stdClass:\n";
        $arrFromEmpty = (array) $empty;
        echo "  count=" . (string)count($arrFromEmpty) . "\n";

        // ── 多次 set/unset 循环 ──
        echo "[6] repeated set/unset cycle:\n";
        $cycler = new stdClass();
        for ($i = 0; $i < 3; $i = $i + 1) {
            $cycler->prop = $i;
            echo "  set prop=" . (string)$cycler->prop . " isset=" . (isset($cycler->prop) ? "true" : "false") . "\n";
            unset($cycler->prop);
            echo "  unset prop isset=" . (isset($cycler->prop) ? "true" : "false") . "\n";
        }
        $cycler->prop = 99;
        echo "  final prop=" . (string)$cycler->prop . "\n";

        // ── 属性名为长字符串（使用字面量属性名，AOT 不支持 $obj->$var 动态属性名）──
        echo "[7] long property name:\n";
        $longName = new stdClass();
        $longName->this_is_a_very_long_property_name_used_for_testing_edge_cases_in_stdclass_implementation = "long_value";
        echo "  longKey isset=" . (isset($longName->this_is_a_very_long_property_name_used_for_testing_edge_cases_in_stdclass_implementation) ? "true" : "false") . "\n";
        echo "  longKey value=" . (string)$longName->this_is_a_very_long_property_name_used_for_testing_edge_cases_in_stdclass_implementation . "\n";
        $longVars = get_object_vars($longName);
        echo "  longVars count=" . (string)count($longVars) . "\n";
        unset($longName->this_is_a_very_long_property_name_used_for_testing_edge_cases_in_stdclass_implementation);
        echo "  after unset isset=" . (isset($longName->this_is_a_very_long_property_name_used_for_testing_edge_cases_in_stdclass_implementation) ? "true" : "false") . "\n";

        // ── 属性值为 null（PHP 语义：isset 对 null 值返回 false）──
        echo "[8] null property value:\n";
        $nullObj = new stdClass();
        $nullObj->nullVal = null;
        echo "  isset(nullVal)=" . (isset($nullObj->nullVal) ? "true" : "false") . "\n";
        echo "  value=[" . (string)$nullObj->nullVal . "]\n";
        var_dump($nullObj);

        // ── var_dump 最终状态 ──
        echo "[9] final var_dump of cycler:\n";
        var_dump($cycler);

        echo "=== edge case tests done ===\n";
    }
}
