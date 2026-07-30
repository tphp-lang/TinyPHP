<?php
// ext/pdo_pgsql 扩展测试 — PDO 基本功能
//
// 测试范围：
//   new PDO("pgsql:...") / prepare / execute / fetch / 事务 / getAttribute
#import pdo
#import pdo_pgsql

#debug === PDO Pgsql Basic Test ===
#debug
#debug 1. PDO connect: OK
#debug 2. prepare + bindValue + execute: OK
#debug 3. fetch (ASSOC): OK
#debug 4. fetch (NUM): OK
#debug 5. fetch (BOTH): OK
#debug 6. lastInsertId: OK
#debug 7. beginTransaction + commit: OK
#debug 8. beginTransaction + rollBack: OK
#debug 9. getAttribute (DRIVER_NAME): OK
#debug 10. getAttribute (SERVER_VERSION): OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PDO Pgsql Basic Test ===\n\n";

        $dsn = "pgsql:host=127.0.0.1;port=5432;dbname=tinyphp_test";

        // 1. PDO 连接
        try {
            $pdo = new PDO($dsn, "postgres", "postgres");
            echo "1. PDO connect: OK\n";
        } catch (Exception $e) {
            echo "1. PDO connect: FAIL\n";
            return;
        }

        // 建表
        try {
            $pdo->exec("DROP TABLE IF EXISTS pdo_test");
            $pdo->exec("CREATE TABLE pdo_test (id SERIAL PRIMARY KEY, name VARCHAR(100), age INT)");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 2. prepare + bindValue + execute
        try {
            $st = $pdo->prepare("INSERT INTO pdo_test (name, age) VALUES (?, ?)");
            $st->bindValueStr(1, "Alice", PDO::PARAM_STR);
            $st->bindValueInt(2, 30, PDO::PARAM_INT);
            $st->execute();
            echo "2. prepare + bindValue + execute: OK\n";
        } catch (Exception $e) {
            echo "2. prepare + bindValue + execute: FAIL\n";
        }

        // 3. fetch (ASSOC)
        try {
            $st = $pdo->prepare("SELECT * FROM pdo_test WHERE name = ?");
            $st->bindValueStr(1, "Alice", PDO::PARAM_STR);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $ok = ($row["name"] == "Alice" && $row["age"] == "30");
            echo "3. fetch (ASSOC): " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. fetch (ASSOC): FAIL\n";
        }

        // 4. fetch (NUM)
        try {
            $st = $pdo->query("SELECT * FROM pdo_test WHERE name = 'Alice'");
            $row = $st->fetch(PDO::FETCH_NUM);
            $ok = ($row[1] == "Alice" && $row[2] == "30");
            echo "4. fetch (NUM): " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. fetch (NUM): FAIL\n";
        }

        // 5. fetch (BOTH)
        try {
            $st = $pdo->query("SELECT * FROM pdo_test WHERE name = 'Alice'");
            $row = $st->fetch(PDO::FETCH_BOTH);
            $ok = ($row["name"] == "Alice" && $row[1] == "Alice");
            echo "5. fetch (BOTH): " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "5. fetch (BOTH): FAIL\n";
        }

        // 6. lastInsertId
        try {
            $pdo->exec("INSERT INTO pdo_test (name, age) VALUES ('Bob', 25)");
            $id = $pdo->lastInsertId();
            $ok = (strlen($id) > 0);
            echo "6. lastInsertId: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "6. lastInsertId: FAIL\n";
        }

        // 7. beginTransaction + commit
        try {
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO pdo_test (name, age) VALUES ('Charlie', 35)");
            $pdo->commit();
            $st = $pdo->query("SELECT count(*) FROM pdo_test");
            $count = $st->fetchColumnInt(0);
            echo "7. beginTransaction + commit: " . ($count == 3 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "7. beginTransaction + commit: FAIL\n";
        }

        // 8. beginTransaction + rollBack
        try {
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO pdo_test (name, age) VALUES ('Dave', 40)");
            $pdo->rollBack();
            $st = $pdo->query("SELECT count(*) FROM pdo_test");
            $count = $st->fetchColumnInt(0);
            echo "8. beginTransaction + rollBack: " . ($count == 3 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "8. beginTransaction + rollBack: FAIL\n";
        }

        // 9. getAttribute (DRIVER_NAME)
        try {
            $driver = $pdo->getAttributeStr(PDO::ATTR_DRIVER_NAME);
            echo "9. getAttribute (DRIVER_NAME): " . ($driver == "pgsql" ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "9. getAttribute (DRIVER_NAME): FAIL\n";
        }

        // 10. getAttribute (SERVER_VERSION)
        try {
            $version = $pdo->getAttributeStr(PDO::ATTR_SERVER_VERSION);
            $ok = (strlen($version) > 0);
            echo "10. getAttribute (SERVER_VERSION): " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "10. getAttribute (SERVER_VERSION): FAIL\n";
        }

        // 清理
        try {
            $pdo->exec("DROP TABLE IF EXISTS pdo_test");
        } catch (Exception $e) {
        }

        echo "\n=== All passed ===\n";
    }
}
