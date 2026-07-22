<?php
// closure_this_capture.php — 验证闭包内使用 $this 时是否正确捕获 self 指针
//   Bug: 闭包内 $this->prop 编译为 self->prop，但闭包签名没有 self 参数
//        导致 C 编译错误: 'self' undeclared
//   修复: 闭包内使用 $this 时，自动把 self 指针加入捕获结构并解引用
#debug === closure this capture ===
#debug
#debug [1] closure writes via $this:
#debug val=42
#debug [2] closure reads via $this:
#debug read=42
#debug
#debug === OK ===

class Holder
{
    public int $val;

    public function __construct()
    {
        $this->val = 0;
    }

    public function writeViaClosure(int $v): void
    {
        // 闭包内使用 $this 写入属性
        $cb = function (int $x) {
            $this->val = $x;
        };
        $cb($v);
    }

    public function readViaClosure(): int
    {
        // 闭包内使用 $this 读取属性
        $cb = function (): int {
            return $this->val;
        };
        return $cb();
    }
}

class Main
{
    public function main(): void
    {
        echo "=== closure this capture ===\n\n";

        echo '[1] closure writes via $this:' . "\n";
        $h = new Holder();
        $h->writeViaClosure(42);
        echo 'val=' . $h->val . "\n";

        echo '[2] closure reads via $this:' . "\n";
        echo 'read=' . $h->readViaClosure() . "\n";

        echo "\n=== OK ===\n";
    }
}
