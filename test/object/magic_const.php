<?php
#debug ~ LINE=12
#debug ~ FILE=C:\project\php\TinyPHP\test\object\magic_const.php
#debug ~ DIR=C:\project\php\TinyPHP\test\object
#debug ~ SEP=[\]

class Main {
    public function main(): void {
        echo 'LINE=' . __LINE__ . "\n";
        echo 'FILE=' . __FILE__ . "\n";
        echo 'DIR=' . __DIR__ . "\n";
        echo 'SEP=[' . DIRECTORY_SEPARATOR . "]\n";
    }
}
