<?php
// 对应 PHP 原生 tests/lang/036.phpt — private 变量静态绑定
// 演示 private 属性静态绑定：父类 private 属性（foo）与子类 public 同名属性（bar）
// 互不覆盖。适配 TinyPHP：不支持 parent::method()，分别实例化父/子类调用各自方法
#debug foo
#debug bar

class par {
    private string $id = "foo";

    public function displayMe(): void {
        echo $this->id;
    }
}

class chld extends par {
    public string $id = "bar";

    public function displayMine(): void {
        echo $this->id;
    }
}

class Main {
    public function main(): void {
        $p = new par();
        $p->displayMe();      // foo (父类 private 静态绑定)
        echo "\n";
        $c = new chld();
        $c->displayMine();    // bar (子类 public 属性)
    }
}
