<?php

class Main {
    public function main(): void {
        $obj = new stdClass();
        $obj->foo = 42;
        $obj->bar = "hello";
        echo $obj->foo . "\n";
        echo $obj->bar . "\n";
        var_dump($obj);

        // isset / unset
        echo (isset($obj->foo) ? "yes" : "no") . "\n";
        unset($obj->foo);
        echo (isset($obj->foo) ? "yes" : "no") . "\n";

        // foreach
        foreach ($obj as $key => $val) {
            echo $key . "=" . $val . "\n";
        }
    }
}
