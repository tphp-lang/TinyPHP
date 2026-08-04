<?php
// stdclass_foreach.php — 测试 stdClass 的 foreach 遍历行为
class Main {
    public function main(): void {
        echo "=== stdClass foreach tests ===\n";

        // ── foreach with key => value 遍历所有属性 ──
        echo "[1] foreach key => value:\n";
        $obj = new stdClass();
        $obj->a = 1;
        $obj->b = 2;
        $obj->c = 3;
        foreach ($obj as $key => $val) {
            echo "  " . (string)$key . "=" . (string)$val . "\n";
        }

        // ── foreach 仅 value（无 key）──
        echo "[2] foreach value only:\n";
        $obj2 = new stdClass();
        $obj2->x = 10;
        $obj2->y = 20;
        foreach ($obj2 as $val) {
            echo "  " . (string)$val . "\n";
        }

        // ── 空 stdClass foreach（不执行循环体）──
        echo "[3] foreach empty stdClass:\n";
        $empty = new stdClass();
        $count = 0;
        foreach ($empty as $k => $v) {
            $count = $count + 1;
        }
        echo "  iterations=" . (string)$count . "\n";

        // ── 混合类型属性遍历（int/string/bool/null）──
        echo "[4] foreach mixed types:\n";
        $mixed = new stdClass();
        $mixed->intVal = 42;
        $mixed->strVal = "hello";
        $mixed->boolTrue = true;
        $mixed->boolFalse = false;
        $mixed->nullVal = null;
        foreach ($mixed as $key => $val) {
            echo "  " . (string)$key . "=[" . (string)$val . "]\n";
        }

        // ── 遍历后验证属性数量 ──
        echo "[5] verify property count after foreach:\n";
        $vars = get_object_vars($obj);
        echo "  count(obj)=" . (string)count($vars) . "\n";
        $vars2 = get_object_vars($mixed);
        echo "  count(mixed)=" . (string)count($vars2) . "\n";

        // ── 嵌套 stdClass（属性值为另一个 stdClass）──
        echo "[6] foreach nested stdClass:\n";
        $outer = new stdClass();
        $inner = new stdClass();
        $inner->x = 100;
        $inner->y = "nested";
        $outer->inner = $inner;
        $outer->top = 1;
        foreach ($outer as $key => $val) {
            echo "  key=" . (string)$key . "\n";
        }
        echo "  var_dump nested:\n";
        var_dump($outer);

        echo "=== foreach tests done ===\n";
    }
}
