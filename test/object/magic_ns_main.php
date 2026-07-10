<?php // @multi @with magic_ns_lib.php
#debug FUNC=greet
#debug NS=Demo\Sub
#debug FUNC=helper
#debug METHOD=Demo\Sub\Util::helper
#debug NS=Demo\Sub

use function Demo\Sub\greet;
use Demo\Sub\Util;

class Main {
    public function main(): void {
        greet();
        Util $u = new Util();
        $u->helper();
    }
}
