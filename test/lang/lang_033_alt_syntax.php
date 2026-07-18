<?php
// @skip — tphp 暂不支持 alternative syntax（if/while/for/switch 的 endif/endwhile/endfor/endswitch 形式）
// 对应 PHP tests/lang/033.phpt — Alternative syntaxes test
//
// PHP 原生 033.phpt 测试以下 alternative syntax：
//   if (...): ... else: ... endif;
//   while (...): ... endwhile;
//   for (...): ... endfor;
//   switch (...): ... endswitch;
//
// 已验证 tphp 不支持：Parser 报错 "Expected expression, got ':'"
// （TokenType.php 中无 endif/endwhile/endfor/endswitch 关键字）
//
// 以下为 alternative syntax 参考代码（无法编译，仅作说明）：
//   $a = 1;
//   if ($a):
//       echo 1;
//   else:
//       echo 0;
//   endif;
//
// PHP 原生 --EXPECTF-- 输出（含 Deprecated 警告，tphp 不产生）：
//   If: 11
//   While: 12346789
//   For: 0123401234
//   Switch: 1
#debug If: 1
#debug done

class Main {
    public function main(): void {
        // 等价的 brace 语法（tphp 支持）— 仅为占位
        $a = 1;
        echo "If: ";
        if ($a) {
            echo "1";
        }
        echo "\n";
        echo "done\n";
    }
}
