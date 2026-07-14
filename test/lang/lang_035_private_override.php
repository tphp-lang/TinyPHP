<?php
// 对应 PHP 原生 tests/lang/036.phpt — Child public should not override parent private
// 测试 parent::method() 调用 + private 属性静态绑定
// 注：原生用 print，TinyPHP 用 echo；原生 $id 无类型声明，TinyPHP 需加类型
#debug foo

class par {
    private string $id = "foo";

    public function displayMe(): void {
        echo $this->id;
    }
}

class chld extends par {
    public string $id = "bar";

    public function displayHim(): void {
        parent::displayMe();
    }
}

class Main {
    public function main(): void {
        $obj = new chld();
        $obj->displayHim();
    }
}
