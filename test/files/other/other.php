<?php

namespace Other;

function otherFn(): void
{
    echo "其他可用函数\n";
    oneToTwo(); // 输出 调用同命名空间不同文件的的其他函数
}


function oneToTwo():void
{
    echo "调用同命名空间不同文件的的其他函数\n";
    other2Fn(); // 输出 其他可用函数2
}
