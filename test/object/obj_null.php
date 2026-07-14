<?php
#debug NULL
#debug done

class Demo {
    public string $name = "obj";
}

class Container {
    // 对象属性不赋值 — 默认 null
    public Demo $obj;
}

class Main {
    public function main(): void {
        $c = new Container();
        var_dump($c->obj);
        echo "done\n";
    }
}
