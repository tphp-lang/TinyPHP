<?php // @skip — $r used for both string(int/float) and array, static AOT type conflict

class Main
{
    public int $t0;

    public function main(): void
    {
        echo "=== Optimization Benchmark ===\n\n";
        $N = 200000;

        // ═══ 1. trim ═══
        echo "-- 1. trim x" . $N . " --\n";
        $s = 'hello_world_no_spaces_at_all';
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = trim($s); }
        echo 'trim no-ws: ' . (hrtime() - $this->t0) . "ns\n";

        $sw = '  hello  ';
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = trim($sw); }
        echo 'trim ws: ' . (hrtime() - $this->t0) . "ns\n";

        // ═══ 2. strtolower ═══
        echo "\n-- 2. strtolower x" . $N . " --\n";
        $lo = 'already_lowercase_123';
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = strtolower($lo); }
        echo 'no-change: ' . (hrtime() - $this->t0) . "ns\n";

        $up = 'ALL_UPPERCASE';
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = strtolower($up); }
        echo 'change: ' . (hrtime() - $this->t0) . "ns\n";

        // ═══ 3. substr ═══
        echo "\n-- 3. substr x" . $N . " --\n";
        $ss = 'hello world benchmark string';
        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = substr($ss, 0, strlen($ss)); }
        echo 'full-copy: ' . (hrtime() - $this->t0) . "ns\n";

        $this->t0 = hrtime();
        for ($i = 0; $i < $N; $i++) { $r = substr($ss, 6, 5); }
        echo 'partial: ' . (hrtime() - $this->t0) . "ns\n";

        // ═══ 4. array_unique ═══
        echo "\n-- 4. array_unique --\n";
        $small = [1, 2, 2, 3, 3, 3, 4, 5];
        $N2 = 50000;
        $this->t0 = hrtime();
        for ($i = 0; $i < $N2; $i++) { $ra = array_unique($small); }
        echo 'small(8el)x50K: ' . (hrtime() - $this->t0) . "ns\n";

        $big = [];
        for ($i = 0; $i < 500; $i++) { array_push($big, $i % 50); }
        $N3 = 5000;
        $this->t0 = hrtime();
        for ($i = 0; $i < $N3; $i++) { $ra = array_unique($big); }
        echo 'large(500el)x5K: ' . (hrtime() - $this->t0) . "ns\n";

        echo "\n=== Done ===\n";
    }
}
