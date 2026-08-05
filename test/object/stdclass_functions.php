<?php
// stdclass_functions.php — 测试 stdClass 与内置函数交互
class Main {
    public function main(): void {
        echo "=== stdClass built-in function tests ===\n";

        // ── get_object_vars 返回属性数组 ──
        echo "[1] get_object_vars on populated stdClass:\n";
        $obj = new stdClass();
        $obj->name = "Alice";
        $obj->age = 30;
        $obj->active = true;
        $vars = get_object_vars($obj);
        echo "  count=" . (string)count($vars) . "\n";
        echo "  name=" . (string)$vars['name'] . "\n";
        echo "  age=" . (string)$vars['age'] . "\n";
        echo "  active=" . (string)$vars['active'] . "\n";

        // ── get_object_vars 在空 stdClass 上返回空数组 ──
        echo "[2] get_object_vars on empty stdClass:\n";
        $empty = new stdClass();
        $emptyVars = get_object_vars($empty);
        echo "  count=" . (string)count($emptyVars) . "\n";

        // ── (object) 数组后 get_object_vars 往返 ──
        echo "[3] array -> object -> get_object_vars roundtrip:\n";
        $arr = ['x' => 1, 'y' => 2, 'z' => 3];
        $obj2 = (object) $arr;
        $back = get_object_vars($obj2);
        echo "  count=" . (string)count($back) . "\n";
        echo "  x=" . (string)$back['x'] . "\n";
        echo "  y=" . (string)$back['y'] . "\n";
        echo "  z=" . (string)$back['z'] . "\n";

        // ── count(get_object_vars($obj)) 验证属性数量 ──
        echo "[4] count(get_object_vars()) for various objects:\n";
        $obj3 = new stdClass();
        $obj3->a = 10;
        $obj3->b = 20;
        $obj3->c = 30;
        echo "  obj3 count=" . (string)count(get_object_vars($obj3)) . "\n";
        echo "  empty count=" . (string)count(get_object_vars($empty)) . "\n";

        // ── (object) -> (array) 后用 count 验证 ──
        echo "[5] (array) stdClass then count:\n";
        $obj4 = new stdClass();
        $obj4->p = 1;
        $obj4->q = 2;
        $arrFromObj = (array) $obj4;
        echo "  count=" . (string)count($arrFromObj) . "\n";
        echo "  p=" . (string)$arrFromObj['p'] . "\n";
        echo "  q=" . (string)$arrFromObj['q'] . "\n";

        // ── var_dump 输出验证 ──
        echo "[6] var_dump validation:\n";
        var_dump($obj);
        var_dump($empty);

        echo "=== built-in function tests done ===\n";
    }
}
