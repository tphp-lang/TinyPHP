<?php
// @expect-error include not supported in AOT
#debug ~ Fatal
class Main { public function main(): void { include "x.php"; } }
