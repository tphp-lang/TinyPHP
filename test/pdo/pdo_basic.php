<?php
// ext/pdo 扩展测试 — SQLite 驱动（基于内置 SQLite 3.46.0 amalgamation 静态编译）
//
// 覆盖范围：
//   1. 连接（:memory: 数据库）
//   2. exec — DDL（CREATE TABLE）+ DML（INSERT/UPDATE/DELETE）
//   3. prepare + bindValue — 位置参数 + 命名参数
//   4. execute(array) — 数组参数绑定
//   5. fetch 模式 — FETCH_ASSOC / FETCH_NUM / FETCH_BOTH / FETCH_COLUMN / FETCH_KEY_PAIR
//   6. fetchAll — 批量取行
//   7. fetchColumn — 取单列
//   8. 事务 — beginTransaction / commit / rollBack / inTransaction
//   9. lastInsertId — 最后插入行 ID
//  10. rowCount — 受影响行数
//  11. quote — 字符串转义
//  12. getAttribute / setAttribute — 属性读写
//  13. errorCode / errorInfo — 错误信息
//  14. getColumnMeta — 列元信息
//  15. closeCursor — 关闭游标后重用
//  16. 错误处理 — try-catch 异常捕获
//  17. 静态方法 — getAvailableDrivers
//  18. NULL handling — 空值处理（AOT 类型安全：NULL → 空字符串）
//  19. float column — 浮点列读取
//
// AOT 类型安全 API 说明：
//   - bindValue 按类型拆分：bindValueInt / bindValueStr / bindValueNamedInt / bindValueNamedStr
//   - fetchColumn 按类型拆分：fetchColumnStr / fetchColumnInt
//   - getAttribute 按类型拆分：getAttributeStr / getAttributeInt / getAttributeBool
//   - fetch() 始终返回 array<string>（所有列值统一转为字符串）
//   - fetchDone() 替代 fetch() === false 判断
//   - NULL 值返回空字符串 ""（AOT 无 mixed，无法区分 NULL 和空串）
#import pdo
#debug === 1. connection & exec ===
#debug driver=sqlite
#debug version=3.46.0
#debug create_ok=0
#debug insert_count=3
#debug
#debug === 2. prepare + bindValue (positional) ===
#debug row1_id=1
#debug row1_name=Alice
#debug row1_age=30
#debug
#debug === 3. prepare + bindValue (named) ===
#debug row2_id=2
#debug row2_name=Bob
#debug row2_age=25
#debug
#debug === 4. execute(array) ===
#debug row3_id=3
#debug row3_name=Charlie
#debug row3_age=35
#debug
#debug === 5. fetch modes ===
#debug assoc_name=Alice
#debug assoc_age=30
#debug num_0=1
#debug num_1=Alice
#debug both_name=Alice
#debug both_0=1
#debug col_1=Alice
#debug kp_Alice=30
#debug kp_Bob=25
#debug kp_Charlie=35
#debug
#debug === 6. fetchAll ===
#debug fetchall_count=3
#debug fetchall_0=Alice
#debug fetchall_1=Bob
#debug fetchall_2=Charlie
#debug
#debug === 7. fetchColumn ===
#debug fc_0=1
#debug fc_1=Bob
#debug fc_count=3
#debug
#debug === 8. transactions ===
#debug txn_before=false
#debug txn_begin=true
#debug txn_in=true
#debug txn_commit=true
#debug txn_after=false
#debug txn_rollback_test=4
#debug
#debug === 9. lastInsertId ===
#debug last_id=5
#debug
#debug === 10. rowCount ===
#debug update_rows=5
#debug delete_rows=1
#debug
#debug === 11. quote ===
#debug quoted='hello ''world'''
#debug
#debug === 12. getAttribute / setAttribute ===
#debug attr_driver=sqlite
#debug attr_version=3.46.0
#debug attr_errmode=2
#debug attr_case=0
#debug attr_fetch=4
#debug attr_autocommit=1
#debug
#debug === 13. errorCode / errorInfo ===
#debug errcode=00000
#debug errinfo_state=00000
#debug errinfo_code=0
#debug
#debug === 14. getColumnMeta ===
#debug meta_name=id
#debug meta_decltype=INTEGER
#debug meta_native_type=INTEGER
#debug meta_pdo_type=1
#debug
#debug === 15. closeCursor & reuse ===
#debug reuse_count=4
#debug
#debug === 16. error handling ===
#debug caught_no_table: PDO::exec: no such table: nonexistent
#debug caught_bad_sql: PDO::prepare: failed to prepare statement: near "BADSQL": syntax error
#debug caught_no_txn: PDO::commit: no active transaction
#debug
#debug === 17. static methods ===
#debug drivers_count=1
#debug drivers_0=sqlite
#debug
#debug === 18. NULL handling ===
#debug null_val=
#debug null_check=true
#debug
#debug === 19. float column ===
#debug float_val=3.14
#debug
#debug === PDO tests done ===

class Main
{
    public function main(): void
    {
        // ── 1. connection & exec ──
        echo "=== 1. connection & exec ===\n";
        $pdo = new PDO("sqlite::memory:");
        echo "driver=" . $pdo->getAttributeStr(PDO::ATTR_DRIVER_NAME) . "\n";
        echo "version=" . $pdo->getAttributeStr(PDO::ATTR_SERVER_VERSION) . "\n";
        $n = $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)");
        echo "create_ok=" . $n . "\n";
        $n = $pdo->exec("INSERT INTO users (name, age) VALUES ('Alice', 30), ('Bob', 25), ('Charlie', 35)");
        echo "insert_count=" . $n . "\n";
        echo "\n";

        // ── 2. prepare + bindValue (positional) ──
        echo "=== 2. prepare + bindValue (positional) ===\n";
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->bindValueInt(1, 1, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo "row1_id=" . $row["id"] . "\n";
        echo "row1_name=" . $row["name"] . "\n";
        echo "row1_age=" . $row["age"] . "\n";
        echo "\n";

        // ── 3. prepare + bindValue (named) ──
        echo "=== 3. prepare + bindValue (named) ===\n";
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $st->bindValueNamedInt(":id", 2, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo "row2_id=" . $row["id"] . "\n";
        echo "row2_name=" . $row["name"] . "\n";
        echo "row2_age=" . $row["age"] . "\n";
        echo "\n";

        // ── 4. execute(array) ──
        echo "=== 4. execute(array) ===\n";
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([3]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo "row3_id=" . $row["id"] . "\n";
        echo "row3_name=" . $row["name"] . "\n";
        echo "row3_age=" . $row["age"] . "\n";
        echo "\n";

        // ── 5. fetch modes ──
        echo "=== 5. fetch modes ===\n";
        $st = $pdo->query("SELECT * FROM users WHERE id = 1");
        // FETCH_ASSOC
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo "assoc_name=" . $row["name"] . "\n";
        echo "assoc_age=" . $row["age"] . "\n";

        // FETCH_NUM
        $st = $pdo->query("SELECT * FROM users WHERE id = 1");
        $row = $st->fetch(PDO::FETCH_NUM);
        echo "num_0=" . $row[0] . "\n";
        echo "num_1=" . $row[1] . "\n";

        // FETCH_BOTH
        $st = $pdo->query("SELECT * FROM users WHERE id = 1");
        $row = $st->fetch(PDO::FETCH_BOTH);
        echo "both_name=" . $row["name"] . "\n";
        echo "both_0=" . $row[0] . "\n";

        // FETCH_COLUMN（AOT 模式下用 fetchColumnStr 替代 setFetchMode+fetch）
        $st = $pdo->query("SELECT * FROM users WHERE id = 1");
        echo "col_1=" . $st->fetchColumnStr(1) . "\n";

        // FETCH_KEY_PAIR
        $st = $pdo->query("SELECT name, age FROM users ORDER BY id");
        $pair = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        echo "kp_Alice=" . $pair["Alice"] . "\n";
        echo "kp_Bob=" . $pair["Bob"] . "\n";
        echo "kp_Charlie=" . $pair["Charlie"] . "\n";
        echo "\n";

        // ── 6. fetchAll ──
        echo "=== 6. fetchAll ===\n";
        $st = $pdo->query("SELECT * FROM users ORDER BY id");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo "fetchall_count=" . count($rows) . "\n";
        echo "fetchall_0=" . $rows[0]["name"] . "\n";
        echo "fetchall_1=" . $rows[1]["name"] . "\n";
        echo "fetchall_2=" . $rows[2]["name"] . "\n";
        echo "\n";

        // ── 7. fetchColumn ──
        echo "=== 7. fetchColumn ===\n";
        $st = $pdo->query("SELECT * FROM users ORDER BY id");
        echo "fc_0=" . $st->fetchColumnStr(0) . "\n";
        echo "fc_1=" . $st->fetchColumnStr(1) . "\n";
        // 取剩余行计数（已取 2 行，还剩 1 行）
        $count = 2;
        while (true) {
            $st->fetchColumnStr(0);
            if ($st->fetchDone()) {
                break;
            }
            $count = $count + 1;
        }
        echo "fc_count=" . $count . "\n";
        echo "\n";

        // ── 8. transactions ──
        echo "=== 8. transactions ===\n";
        echo "txn_before=" . ($pdo->inTransaction() ? "true" : "false") . "\n";
        $b = $pdo->beginTransaction();
        echo "txn_begin=" . ($b ? "true" : "false") . "\n";
        echo "txn_in=" . ($pdo->inTransaction() ? "true" : "false") . "\n";
        $pdo->exec("INSERT INTO users (name, age) VALUES ('Dave', 40)");
        $c = $pdo->commit();
        echo "txn_commit=" . ($c ? "true" : "false") . "\n";
        echo "txn_after=" . ($pdo->inTransaction() ? "true" : "false") . "\n";

        // rollback test
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO users (name, age) VALUES ('Eve', 50)");
        $pdo->rollBack();
        $st = $pdo->query("SELECT COUNT(*) FROM users");
        echo "txn_rollback_test=" . $st->fetchColumnInt(0) . "\n";
        echo "\n";

        // ── 9. lastInsertId ──
        echo "=== 9. lastInsertId ===\n";
        $pdo->exec("INSERT INTO users (name, age) VALUES ('Frank', 45)");
        echo "last_id=" . $pdo->lastInsertId() . "\n";
        echo "\n";

        // ── 10. rowCount ──
        echo "=== 10. rowCount ===\n";
        $st = $pdo->prepare("UPDATE users SET age = age + 1");
        $st->execute();
        echo "update_rows=" . $st->rowCount() . "\n";
        $st = $pdo->prepare("DELETE FROM users WHERE name = 'Frank'");
        $st->execute();
        echo "delete_rows=" . $st->rowCount() . "\n";
        echo "\n";

        // ── 11. quote ──
        echo "=== 11. quote ===\n";
        $q = $pdo->quote("hello 'world'");
        echo "quoted=" . $q . "\n";
        echo "\n";

        // ── 12. getAttribute / setAttribute ──
        echo "=== 12. getAttribute / setAttribute ===\n";
        echo "attr_driver=" . $pdo->getAttributeStr(PDO::ATTR_DRIVER_NAME) . "\n";
        echo "attr_version=" . $pdo->getAttributeStr(PDO::ATTR_SERVER_VERSION) . "\n";
        echo "attr_errmode=" . $pdo->getAttributeInt(PDO::ATTR_ERRMODE) . "\n";
        echo "attr_case=" . $pdo->getAttributeInt(PDO::ATTR_CASE) . "\n";
        echo "attr_fetch=" . $pdo->getAttributeInt(PDO::ATTR_DEFAULT_FETCH_MODE) . "\n";
        echo "attr_autocommit=" . ($pdo->getAttributeBool(PDO::ATTR_AUTOCOMMIT) ? "1" : "0") . "\n";
        echo "\n";

        // ── 13. errorCode / errorInfo ──
        echo "=== 13. errorCode / errorInfo ===\n";
        // 执行一个成功语句确保错误码清零
        $pdo->exec("SELECT 1");
        echo "errcode=" . $pdo->errorCode() . "\n";
        $info = $pdo->errorInfo();
        echo "errinfo_state=" . $info[0] . "\n";
        echo "errinfo_code=" . $info[1] . "\n";
        echo "\n";

        // ── 14. getColumnMeta ──
        echo "=== 14. getColumnMeta ===\n";
        $st = $pdo->query("SELECT id, name FROM users LIMIT 1");
        $meta = $st->getColumnMeta(0);
        echo "meta_name=" . $meta["name"] . "\n";
        echo "meta_decltype=" . $meta["native_type"] . "\n";
        echo "meta_native_type=" . $meta["native_type"] . "\n";
        echo "meta_pdo_type=" . $meta["pdo_type"] . "\n";
        echo "\n";

        // ── 15. closeCursor & reuse ──
        echo "=== 15. closeCursor & reuse ===\n";
        // 此时 users: Alice(31), Bob(26), Charlie(36), Dave(41) — Frank 已删除
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE age >= ?");
        $st->bindValueInt(1, 30, PDO::PARAM_INT);
        $st->execute();
        $c1 = $st->fetchColumnInt(0);
        $st->closeCursor();
        $st->bindValueInt(1, 40, PDO::PARAM_INT);
        $st->execute();
        $c2 = $st->fetchColumnInt(0);
        echo "reuse_count=" . ($c1 + $c2) . "\n";
        echo "\n";

        // ── 16. error handling ──
        echo "=== 16. error handling ===\n";
        try {
            $pdo->exec("SELECT * FROM nonexistent");
        } catch (Exception $e) {
            echo "caught_no_table: " . $e->getMessage() . "\n";
        }

        try {
            $pdo->prepare("BADSQL SYNTAX HERE");
        } catch (Exception $e) {
            echo "caught_bad_sql: " . $e->getMessage() . "\n";
        }

        try {
            $pdo->commit();
        } catch (Exception $e) {
            echo "caught_no_txn: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 17. static methods ──
        echo "=== 17. static methods ===\n";
        $drivers = PDO::getAvailableDrivers();
        echo "drivers_count=" . count($drivers) . "\n";
        echo "drivers_0=" . $drivers[0] . "\n";
        echo "\n";

        // ── 18. NULL handling ──
        echo "=== 18. NULL handling ===\n";
        // AOT 类型安全：NULL 值统一返回空字符串 ""（无 mixed 无法区分 NULL 和空串）
        $pdo->exec("CREATE TABLE null_test (id INTEGER, val TEXT)");
        $pdo->exec("INSERT INTO null_test (id, val) VALUES (1, NULL)");
        $st = $pdo->query("SELECT val FROM null_test WHERE id = 1");
        $val = $st->fetchColumnStr(0);
        echo "null_val=" . $val . "\n";
        echo "null_check=" . ($val === "" ? "true" : "false") . "\n";
        echo "\n";

        // ── 19. float column ──
        echo "=== 19. float column ===\n";
        $pdo->exec("CREATE TABLE float_test (id INTEGER, price REAL)");
        $pdo->exec("INSERT INTO float_test (id, price) VALUES (1, 3.14)");
        $st = $pdo->query("SELECT price FROM float_test WHERE id = 1");
        $val = $st->fetchColumnStr(0);
        echo "float_val=" . $val . "\n";
        echo "\n";

        echo "=== PDO tests done ===\n";
    }
}
