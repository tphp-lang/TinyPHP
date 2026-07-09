<?php
#debug FUNC=main
#debug METHOD=tphp_class_Main::main
#debug NS=[]
#debug FUNC=doWork
#debug NS=[]
#debug FUNC=helper
#debug METHOD=tphp_class_Util::helper
#debug NS=[]

function doWork(): void {
    echo 'FUNC=' . __FUNCTION__ . "\n";
    echo 'NS=[' . __NAMESPACE__ . "]\n";
}

class Util {
    public function helper(): void {
        echo 'FUNC=' . __FUNCTION__ . "\n";
        echo 'METHOD=' . __METHOD__ . "\n";
        echo 'NS=[' . __NAMESPACE__ . "]\n";
    }
}

class Main {
    public function main(): void {
        echo 'FUNC=' . __FUNCTION__ . "\n";
        echo 'METHOD=' . __METHOD__ . "\n";
        echo 'NS=[' . __NAMESPACE__ . "]\n";
        doWork();
        Util $u = new Util();
        $u->helper();
    }
}
