<?php
#debug hello world
#debug 6

#import demo

class Main
{
    public function main(): void
    {
        php_demo_hello();
        $a = new DemoA(1, 2);
        echo $a->add(3) . "\n";
    }
}
