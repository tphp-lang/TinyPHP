<?php

#include __EXT__ . "demo/demo.h"

#flag __EXT__ . "demo/src/demo.c"

function php_demo_hello(): void
{
    C->demo_hello();
}
