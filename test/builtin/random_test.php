<?php
#debug ===== random_int =====
#debug int(42)
#debug ~ int(1)
#debug ~ int(1)
#debug ===== random_bytes =====
#debug int(16)
#debug int(0)
#debug ===== done =====

class Main {
    public function main(): void {
        echo "===== random_int =====\n";
        // 固定范围 → 确定性输出
        var_dump(random_int(42, 42));
        // 随机范围 → 只验证在区间内
        $r = random_int(1, 10);
        var_dump($r >= 1 && $r <= 10 ? 1 : 0);
        $r2 = random_int(100, 200);
        var_dump($r2 >= 100 && $r2 <= 200 ? 1 : 0);

        echo "===== random_bytes =====\n";
        // 长度验证
        $b = random_bytes(16);
        var_dump(strlen($b));
        // 空/负长度 → 返回空
        $b2 = random_bytes(0);
        var_dump(strlen($b2));

        echo "===== done =====\n";
    }
}
