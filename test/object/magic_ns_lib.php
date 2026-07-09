<?php // @skip — companion file, no class Main

namespace Demo\Sub;

function greet(): void {
    echo 'FUNC=' . __FUNCTION__ . "\n";
    echo 'NS=' . __NAMESPACE__ . "\n";
}

class Util {
    public function helper(): void {
        echo 'FUNC=' . __FUNCTION__ . "\n";
        echo 'METHOD=' . __METHOD__ . "\n";
        echo 'NS=' . __NAMESPACE__ . "\n";
    }
}
