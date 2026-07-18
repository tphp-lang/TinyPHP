<?php
#debug int(6)
#debug int(1000)

class Main
{
    public function main(): void
    {
        $a = [
            [[1, 2, 3], [4, 5, 6], [7, 8, 9]],
        ];
        var_dump($a[0][1][2]);   // int(6) — $a[0][1] = [4,5,6], [2] = 6

        $deep = [
            [
                [
                    [1, 2, 1000],
                ],
            ],
        ];
        var_dump($deep[0][0][0][2]);   // int(1000)
    }
}
