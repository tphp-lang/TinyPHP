<?php

class Main {
    public function main(): void {
        echo 'LINE=' . __LINE__ . "\n";
        echo 'FILE=' . __FILE__ . "\n";
        echo 'DIR=' . __DIR__ . "\n";
        echo 'SEP=[' . DIRECTORY_SEPARATOR . "]\n";
    }
}
