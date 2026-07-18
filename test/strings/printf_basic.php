<?php
// @skip tphp 未实现 printf() 函数（CodeGenerator 无 'printf' 注册，回退到 tphp_fn_printf 不存在）
// 对应 PHP ext/standard/tests/strings/printf_basic1.phpt
// 期望行为： printf 直接输出格式化字符串到 stdout 并返回字符数。
// tphp 替代方案： echo sprintf($fmt, ...$args) 等价（见 sprintf_basic.php）
// 注：以下 #debug 验证 sprintf 替代方案的输出（printf 本身仍待实现）。
#debug format

class Main
{
    public function main(): void
    {
        // 此测试无法运行： printf 在 tphp 中未实现。
        // 替代方案： echo sprintf("format") 实现等价效果。
        echo sprintf("format");
    }
}
