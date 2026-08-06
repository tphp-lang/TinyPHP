<?php
// @expect-error dynamic variable not supported
// $$var 在 AOT 编译期无法确定变量名
#debug ~ Fatal

class Main {
    public function main(): void {
        $name = "foo";
        $$name = 42;
    }
}
