class Main {
    public function main(): void {
        $a = <<<HTML
        <h1>Hello, World!</h1>
        <div class="" title=""></div>
        <p></p>
        HTML;
        $b = <<<CSS
        body {
            border: 1px solid red;
        }
        CSS;
        echo "Hello, World!\n";
        var_dump($a);
        demo();
    }
}

/**
* @export demo
*/
function demo(): void {
    echo "Hello, World!\n";
}