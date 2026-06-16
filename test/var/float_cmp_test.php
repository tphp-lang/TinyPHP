<?php
namespace Main;
function main(): void {
    echo "=== float cmp ===\n";
    var_dump(1.0 == 1.0);
    var_dump(1.0 == 2.0);
    var_dump(1.0 != 2.0);
    var_dump(1.0 < 2.0);
    var_dump(2.0 > 1.0);
    var_dump(1.0 <= 1.0);
    var_dump(2.0 >= 1.0);
    var_dump(1.5 < 2.5);
    var_dump(3.14 > 1.0);
    var_dump(2.0 <= 2.0);
    var_dump(2.0 >= 2.0);
}
