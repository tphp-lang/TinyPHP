<?php
// MySQL 驱动集成测试 — 需要 MySQL 服务器运行 (root/root, 127.0.0.1:3306)
//
// CI 运行: .github/workflows/mysql_test.yml (Linux + TCC/GCC/Clang + mysql:8 service)
// 本地运行: 启动 MySQL 后 php tphp.php test/pdo_mysql/pdo_mysql_integration.php --debug -o build/test.exe
//
// 设计说明：
//   - 输出必须完全可预测（#debug 严格行对行匹配）
//   - 避免输出 MySQL 版本号等易变值，改为固定标记
//   - 确定性插入顺序保证 last_insert_id 可预测
#import pdo
#import pdo_mysql
#import stream
#debug === 1. connect ===
#debug connected
#debug server_version_ok=1
#debug driver_name=mysql
#debug
#debug === 2. create database & table ===
#debug table_created
#debug
#debug === 3. insert ===
#debug insert_rows=1
#debug last_insert_id=3
#debug
#debug === 4. query (FETCH_ASSOC) ===
#debug id=1 name=Alice age=30 price=99.50
#debug id=2 name=Bob age=25 price=0.00
#debug id=3 name=Charlie age=35 price=1234.56
#debug
#debug === 5. prepare + bindValueInt ===
#debug found: id=1 name=Alice
#debug
#debug === 6. prepare + bindValueStr ===
#debug found: id=2 name=Bob age=25
#debug
#debug === 7. fetchColumnInt (COUNT) ===
#debug count=3
#debug
#debug === 8. transaction ===
#debug committed
#debug alice_new_age=31
#debug
#debug === 9. error handling ===
#debug caught_error
#debug
#debug === 10. quote ===
#debug quoted='O\'Brien'
#debug
#debug === 11. cleanup ===
#debug cleaned
#debug
#debug === MySQL tests done ===

class Main
{
    public function main(): void
    {
        // ── 1. 连接测试 ──
        echo "=== 1. connect ===\n";
        try {
            // 显式注册 MySQL 驱动（部分 TCC 版本不执行 __attribute__((constructor))）
            pdo_mysql_init();
            $pdo = new PDO("mysql:host=127.0.0.1;port=3306", "root", "root");
            echo "connected\n";
            // 版本号易变（8.0.x / 8.4.x），只验证以 "8." 开头，输出固定标记
            $ver = $pdo->getAttributeStr(PDO::ATTR_SERVER_VERSION);
            $ok = (strlen($ver) >= 2 && substr($ver, 0, 2) == "8.") ? "1" : "0";
            echo "server_version_ok=" . $ok . "\n";
            echo "driver_name=" . $pdo->getAttributeStr(PDO::ATTR_DRIVER_NAME) . "\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            return;
        }
        echo "\n";

        // ── 2. 创建测试数据库和表 ──
        echo "=== 2. create database & table ===\n";
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS tphp_test");
            $pdo->exec("USE tphp_test");
            $pdo->exec("DROP TABLE IF EXISTS users");
            $pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), age INT, price DECIMAL(10,2))");
            echo "table_created\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            return;
        }
        echo "\n";

        // ── 3. 插入数据（确定性顺序，保证 last_insert_id=3）──
        echo "=== 3. insert ===\n";
        try {
            $n = $pdo->exec("INSERT INTO users (name, age, price) VALUES ('Alice', 30, 99.50)");
            echo "insert_rows=" . $n . "\n";
            $pdo->exec("INSERT INTO users (name, age, price) VALUES ('Bob', 25, 0.00)");
            $pdo->exec("INSERT INTO users (name, age, price) VALUES ('Charlie', 35, 1234.56)");
            $id = $pdo->lastInsertId();
            echo "last_insert_id=" . $id . "\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 4. 查询（fetch）──
        echo "=== 4. query (FETCH_ASSOC) ===\n";
        try {
            $st = $pdo->query("SELECT * FROM users ORDER BY id", PDO::FETCH_ASSOC);
            while (true) {
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (count($row) == 0) {
                    break;
                }
                echo "id=" . $row['id'] . " name=" . $row['name'] . " age=" . $row['age'] . " price=" . $row['price'] . "\n";
            }
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 5. 预处理语句 ──
        echo "=== 5. prepare + bindValueInt ===\n";
        try {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $st->bindValueInt(1, 1, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (count($row) > 0) {
                echo "found: id=" . $row['id'] . " name=" . $row['name'] . "\n";
            } else {
                echo "not found\n";
            }
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 6. 预处理字符串绑定 ──
        echo "=== 6. prepare + bindValueStr ===\n";
        try {
            $st = $pdo->prepare("SELECT * FROM users WHERE name = ?");
            $st->bindValueStr(1, "Bob", PDO::PARAM_STR);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (count($row) > 0) {
                echo "found: id=" . $row['id'] . " name=" . $row['name'] . " age=" . $row['age'] . "\n";
            } else {
                echo "not found\n";
            }
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 7. fetchColumnInt ──
        echo "=== 7. fetchColumnInt (COUNT) ===\n";
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM users", PDO::FETCH_NUM);
            $count = $st->fetchColumnInt(0);
            echo "count=" . $count . "\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 8. 事务 ──
        echo "=== 8. transaction ===\n";
        try {
            $pdo->beginTransaction();
            $pdo->exec("UPDATE users SET age = age + 1 WHERE name = 'Alice'");
            $pdo->commit();
            echo "committed\n";
            $st = $pdo->prepare("SELECT age FROM users WHERE name = ?");
            $st->bindValueStr(1, "Alice", PDO::PARAM_STR);
            $st->execute();
            $age = $st->fetchColumnInt(0);
            echo "alice_new_age=" . $age . "\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            $pdo->rollBack();
        }
        echo "\n";

        // ── 9. 错误处理 ──
        echo "=== 9. error handling ===\n";
        try {
            $pdo->exec("SELECT * FROM nonexistent_table_xyz");
            echo "no_error\n";
        } catch (Exception $e) {
            // 错误消息含 MySQL 具体文本（表名/语法描述），不可预测，只验证捕获到异常
            echo "caught_error\n";
        }
        echo "\n";

        // ── 10. quote ──
        echo "=== 10. quote ===\n";
        try {
            $q = $pdo->quote("O'Brien");
            echo "quoted=" . $q . "\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 11. 清理 ──
        echo "=== 11. cleanup ===\n";
        try {
            $pdo->exec("DROP DATABASE tphp_test");
            echo "cleaned\n";
        } catch (Exception $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
        }
        echo "\n";

        echo "=== MySQL tests done ===\n";
    }
}
