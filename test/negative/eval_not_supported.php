<?php
// @expect-error eval not supported
// AOT 编译器不支持 eval()，应在编译时报错
#debug ~ Fatal

class Main {
    public function main(): void {
        eval("echo 1;");
    }
}
