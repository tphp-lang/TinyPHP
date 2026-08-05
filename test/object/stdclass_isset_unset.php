<?php
// stdclass_isset_unset.php — 测试 stdClass 的 isset / unset 行为
class Main {
    public function main(): void {
        echo "=== stdClass isset/unset tests ===\n";

        // ── 创建 stdClass 设置多个属性（int/string/bool）──
        $obj = new stdClass();
        $obj->intVal = 42;
        $obj->strVal = "hello";
        $obj->boolVal = true;
        echo "[setup] intVal=42, strVal=hello, boolVal=true\n";

        // ── isset 已存在属性 → true ──
        echo "[isset intVal] " . (isset($obj->intVal) ? "true" : "false") . "\n";
        echo "[isset strVal] " . (isset($obj->strVal) ? "true" : "false") . "\n";
        echo "[isset boolVal] " . (isset($obj->boolVal) ? "true" : "false") . "\n";

        // ── isset 不存在属性 → false ──
        echo "[isset missing] " . (isset($obj->missing) ? "true" : "false") . "\n";

        // ── unset 已存在属性后再 isset → false ──
        unset($obj->intVal);
        echo "[after unset intVal] isset=" . (isset($obj->intVal) ? "true" : "false") . "\n";

        // ── unset 不存在属性（不应报错）──
        unset($obj->notExist);
        echo "[unset notExist] ok (no error)\n";

        // ── unset 后重新设置属性 ──
        $obj->intVal = 99;
        echo "[reset intVal=99] isset=" . (isset($obj->intVal) ? "true" : "false") . "\n";
        echo "[reset intVal value] " . (string)$obj->intVal . "\n";

        // ── unset string 属性 ──
        unset($obj->strVal);
        echo "[after unset strVal] isset=" . (isset($obj->strVal) ? "true" : "false") . "\n";

        // ── unset bool 属性 ──
        unset($obj->boolVal);
        echo "[after unset boolVal] isset=" . (isset($obj->boolVal) ? "true" : "false") . "\n";

        // ── var_dump 验证最终状态 ──
        echo "[final var_dump]\n";
        var_dump($obj);

        echo "=== isset/unset tests done ===\n";
    }
}
