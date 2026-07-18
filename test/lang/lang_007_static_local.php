<?php
// 对应 PHP tests/lang/007.phpt — Function call with global and static variables
// 过滤 AOT 不兼容部分：global $b（tphp 不支持 global 关键字）
// 测试重点：static 局部变量 $a 跨调用保持并递增；普通局部变量 $c 每次调用都重新初始化
#debug int(1)
#debug int(2)
#debug int(2)
#debug int(2)
#debug int(3)
#debug int(2)

class Main {
    public function test(): void {
        static int $a = 0;   // 仅首次调用初始化为 0
        $a = $a + 1;          // 跨调用递增：1, 2, 3
        $c = 1;                // 每次调用都重置为 1
        $c = $c + 1;           // 修改局部变量，下次调用又被重置
        var_dump($a);
        var_dump($c);
    }

    public function main(): void {
        $this->test();  // a=1, c=2
        $this->test();  // a=2, c=2 (static preserved, local reset)
        $this->test();  // a=3, c=2
    }
}
