<?php
#import pdo

// ============================================================
// SQLite PDO 性能基准 — 数据库 CRUD 吞吐量
// 测试场景：连接/建表/插入/查询/预处理/事务/聚合
// 运行: php tphp.php test/bench/bench_sqlite.php -o build/bench_sqlite.exe
// ============================================================

class Main
{
    public function main(): void
    {
        echo "=== TinyPHP SQLite PDO Benchmark ===\n\n";

        $N = 10000;

        // ═══ 1. 连接 + 建表 ═══
        $t0 = hrtime();
        $pdo = new PDO("sqlite::memory:");
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, email TEXT)");
        $t1 = hrtime();
        echo "1. connect+create table:    " . ($t1 - $t0) . " ns\n";

        // ═══ 2. 单条 INSERT（无事务）x N/10 ═══
        $m2 = (int)($N / 10);
        $t0 = hrtime();
        $i = 0;
        while ($i < $m2) {
            $pdo->exec("INSERT INTO users (name, age, email) VALUES ('user" . $i . "', " . ($i % 80) . ", 'u" . $i . "@x.com')");
            $i = $i + 1;
        }
        $t1 = hrtime();
        $cnt2 = $pdo->query("SELECT COUNT(*) FROM users");
        echo "2. insert (no txn) x" . $m2 . ":  " . ($t1 - $t0) . " ns  (rows=" . $cnt2->fetchColumnInt(0) . ")\n";

        // ═══ 3. 事务批量 INSERT x N ═══
        $pdo->exec("DELETE FROM users");
        $t0 = hrtime();
        $pdo->beginTransaction();
        $i = 0;
        while ($i < $N) {
            $pdo->exec("INSERT INTO users (name, age, email) VALUES ('u" . $i . "', " . ($i % 80) . ", 'e" . $i . "@x.com')");
            $i = $i + 1;
        }
        $pdo->commit();
        $t1 = hrtime();
        $cnt3 = $pdo->query("SELECT COUNT(*) FROM users");
        echo "3. insert (txn) x" . $N . ":     " . ($t1 - $t0) . " ns  (rows=" . $cnt3->fetchColumnInt(0) . ")\n";

        // ═══ 4. 预处理 INSERT x N ═══
        $pdo->exec("DELETE FROM users");
        $stmt = $pdo->prepare("INSERT INTO users (name, age, email) VALUES (?, ?, ?)");
        $t0 = hrtime();
        $pdo->beginTransaction();
        $i = 0;
        while ($i < $N) {
            $stmt->bindValueStr(1, "user" . $i);
            $stmt->bindValueInt(2, $i % 80);
            $stmt->bindValueStr(3, "u" . $i . "@x.com");
            $stmt->execute();
            $i = $i + 1;
        }
        $pdo->commit();
        $t1 = hrtime();
        echo "4. insert (prepare) x" . $N . ":  " . ($t1 - $t0) . " ns\n";

        // ═══ 5. SELECT 全表 fetchAll ═══
        $t0 = hrtime();
        $st5 = $pdo->query("SELECT * FROM users");
        $rows = $st5->fetchAll(PDO::FETCH_NUM);
        $t1 = hrtime();
        echo "5. select all (fetchAll):    " . ($t1 - $t0) . " ns  (rows=" . count($rows) . ")\n";
        $st5->closeCursor();

        // ═══ 6. SELECT 逐行 fetch x N/10 ═══
        $m6 = (int)($N / 10);
        $sum = 0;
        $t0 = hrtime();
        $i = 0;
        while ($i < $m6) {
            $st6 = $pdo->query("SELECT age FROM users WHERE id = " . ($i % $N));
            $row = $st6->fetch(PDO::FETCH_NUM);
            $sum = $sum + intval($row[0]);
            $st6->closeCursor();
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "6. select by id x" . $m6 . ":     " . ($t1 - $t0) . " ns  (sum=" . $sum . ")\n";

        // ═══ 7. 预处理 SELECT x N/10 ═══
        $m7 = (int)($N / 10);
        $ps = $pdo->prepare("SELECT name, age FROM users WHERE id = ?");
        $sum2 = 0;
        $t0 = hrtime();
        $i = 0;
        while ($i < $m7) {
            $ps->bindValueInt(1, $i % $N);
            $ps->execute();
            $r = $ps->fetch(PDO::FETCH_NUM);
            $sum2 = $sum2 + intval($r[1]);
            $ps->closeCursor();
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "7. select (prepare) x" . $m7 . ":  " . ($t1 - $t0) . " ns  (sum=" . $sum2 . ")\n";
        $ps->closeCursor();

        // ═══ 8. UPDATE x N/10 ═══
        $m8 = (int)($N / 10);
        $t0 = hrtime();
        $pdo->beginTransaction();
        $i = 0;
        while ($i < $m8) {
            $pdo->exec("UPDATE users SET age = " . ($i % 90) . " WHERE id = " . ($i % $N));
            $i = $i + 1;
        }
        $pdo->commit();
        $t1 = hrtime();
        $cnt8 = $pdo->query("SELECT COUNT(*) FROM users WHERE age < 90");
        echo "8. update x" . $m8 . ":           " . ($t1 - $t0) . " ns  (affected=" . $cnt8->fetchColumnInt(0) . ")\n";

        // ═══ 9. COUNT(*) 聚合 x N/10 ═══
        $m9 = (int)($N / 10);
        $total = 0;
        $t0 = hrtime();
        $i = 0;
        while ($i < $m9) {
            $cnt9 = $pdo->query("SELECT COUNT(*) FROM users");
            $total = $cnt9->fetchColumnInt(0);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "9. count(*) x" . $m9 . ":         " . ($t1 - $t0) . " ns  (count=" . $total . ")\n";

        // ═══ 10. quote 转义 x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $q = $pdo->quote("O'Brien " . $i);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "10. quote x" . $N . ":           " . ($t1 - $t0) . " ns\n";

        echo "\n=== done ===\n";
    }
}
