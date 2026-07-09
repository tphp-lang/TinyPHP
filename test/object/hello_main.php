<?php // @multi @with hello.php
#debug hello world
#debug string(5) "other"
#debug world

use Other\Other;

class Main
{
    public function main(): void
    {
        $o = new Other();
        $o->hello();
        $o->world();
    }
}
