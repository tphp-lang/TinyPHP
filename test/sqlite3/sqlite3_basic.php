<?php
// ext/sqlite3 扩展测试 — 函数式 API（基于内置 SQLite 3.46.0 amalgamation 静态编译）
//
// 覆盖范围：
//   1. sqlite_open — 打开 :memory: 数据库
//   2. sqlite_exec — DDL（CREATE TABLE）+ DML（INSERT/UPDATE/DELETE）
//   3. sqlite_query — SELECT 多行结果（ASSOC / NUM / BOTH 模式）
//   4. sqlite_query_single — 单行查询
//   5. sqlite_changes — 受影响行数
//   6. sqlite_last_insert_rowid — 最后插入行 ID
//   7. sqlite_last_error_code / sqlite_last_error_msg — 错误信息
//   8. sqlite_escape_string — 字符串转义
//   9. sqlite_version — SQLite 库版本
//  10. NULL 处理（AOT 类型安全：NULL → 空字符串）
//  11. float 列读取
//  12. BLOB 列读取（统一按字符串返回）
//  13. 错误处理 — try-catch 异常捕获
//  14. sqlite_close — 关闭数据库
//
// AOT 类型安全 API 说明：
//   - 数据库句柄以 int 存储（sqlite3* 指针转 int）
//   - 查询结果统一返回 array<array<string>>（列值统一转为字符串）
//   - NULL 值返回空字符串 ""（AOT 无 mixed，无法区分 NULL 和空串）
//   - 错误抛 Exception（可被 try-catch 捕获）
#import sqlite3
#debug === 1. open & exec ===
#debug version=3.46.0
#debug create_ok=1
#debug insert_count=1
#debug
#debug === 2. query (ASSOC) ===
#debug row1_id=1
#debug row1_name=Alice
#debug row1_age=30
#debug row2_id=2
#debug row2_name=Bob
#debug row2_age=25
#debug row3_id=3
#debug row3_name=Charlie
#debug row3_age=35
#debug
#debug === 3. query (NUM) ===
#debug num_0=1
#debug num_1=Alice
#debug num_2=30
#debug
#debug === 4. query (BOTH) ===
#debug both_id=1
#debug both_0=1
#debug both_name=Alice
#debug both_1=Alice
#debug
#debug === 5. query_single ===
#debug single_name=Alice
#debug single_age=30
#debug single_empty=
#debug
#debug === 6. changes & last_insert_rowid ===
#debug changes_insert=1
#debug last_id=4
#debug changes_update=4
#debug changes_delete=1
#debug
#debug === 7. error info ===
#debug err_code=0
#debug err_msg=not an error
#debug
#debug === 8. escape_string ===
#debug escaped=O''Brien
#debug escaped_empty=
#debug
#debug === 9. NULL handling ===
#debug null_val=
#debug null_check=true
#debug
#debug === 10. float column ===
#debug float_val=3.14
#debug
#debug === 11. BLOB column ===
#debug blob_val=hello
#debug
#debug === 12. error handling ===
#debug caught_no_table: sqlite_exec: no such table: nonexistent
#debug caught_bad_sql: sqlite_query: prepare failed: near "BADSQL": syntax error
#debug
#debug === 13. close & reopen ===
#debug reopen_ok=1
#debug
#debug === sqlite3 tests done ===

class Main
{
    public function main(): void
    {
        // ── 1. open & exec ──
        echo "=== 1. open & exec ===\n";
        $db = sqlite_open(":memory:");
        echo "version=" . sqlite_version() . "\n";
        $ok = sqlite_exec($db, "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)");
        echo "create_ok=" . ($ok ? "1" : "0") . "\n";
        sqlite_exec($db, "INSERT INTO users (name, age) VALUES ('Alice', 30)");
        sqlite_exec($db, "INSERT INTO users (name, age) VALUES ('Bob', 25)");
        sqlite_exec($db, "INSERT INTO users (name, age) VALUES ('Charlie', 35)");
        echo "insert_count=" . sqlite_changes($db) . "\n";
        echo "\n";

        // ── 2. query (ASSOC) ──
        echo "=== 2. query (ASSOC) ===\n";
        $rows = sqlite_query($db, "SELECT * FROM users ORDER BY id", SQLITE3_ASSOC);
        foreach ($rows as $row) {
            echo "row" . $row['id'] . "_id=" . $row['id'] . "\n";
            echo "row" . $row['id'] . "_name=" . $row['name'] . "\n";
            echo "row" . $row['id'] . "_age=" . $row['age'] . "\n";
        }
        echo "\n";

        // ── 3. query (NUM) ──
        echo "=== 3. query (NUM) ===\n";
        $rows = sqlite_query($db, "SELECT * FROM users WHERE id = 1", SQLITE3_NUM);
        $row = $rows[0];
        echo "num_0=" . $row[0] . "\n";
        echo "num_1=" . $row[1] . "\n";
        echo "num_2=" . $row[2] . "\n";
        echo "\n";

        // ── 4. query (BOTH) ──
        echo "=== 4. query (BOTH) ===\n";
        $rows = sqlite_query($db, "SELECT * FROM users WHERE id = 1", SQLITE3_BOTH);
        $row = $rows[0];
        echo "both_id=" . $row['id'] . "\n";
        echo "both_0=" . $row[0] . "\n";
        echo "both_name=" . $row['name'] . "\n";
        echo "both_1=" . $row[1] . "\n";
        echo "\n";

        // ── 5. query_single ──
        echo "=== 5. query_single ===\n";
        $one = sqlite_query_single($db, "SELECT * FROM users WHERE name = 'Alice'");
        echo "single_name=" . $one['name'] . "\n";
        echo "single_age=" . $one['age'] . "\n";
        $empty = sqlite_query_single($db, "SELECT * FROM users WHERE name = 'Nobody'");
        echo "single_empty=" . (count($empty) == 0 ? "" : "not_empty") . "\n";
        echo "\n";

        // ── 6. changes & last_insert_rowid ──
        echo "=== 6. changes & last_insert_rowid ===\n";
        sqlite_exec($db, "INSERT INTO users (name, age) VALUES ('Dave', 40)");
        echo "changes_insert=" . sqlite_changes($db) . "\n";
        echo "last_id=" . sqlite_last_insert_rowid($db) . "\n";
        sqlite_exec($db, "UPDATE users SET age = age + 1");
        echo "changes_update=" . sqlite_changes($db) . "\n";
        sqlite_exec($db, "DELETE FROM users WHERE name = 'Dave'");
        echo "changes_delete=" . sqlite_changes($db) . "\n";
        echo "\n";

        // ── 7. error info ──
        echo "=== 7. error info ===\n";
        // 执行一个成功语句确保错误码清零
        sqlite_exec($db, "SELECT 1");
        echo "err_code=" . sqlite_last_error_code($db) . "\n";
        echo "err_msg=" . sqlite_last_error_msg($db) . "\n";
        echo "\n";

        // ── 8. escape_string ──
        echo "=== 8. escape_string ===\n";
        $escaped = sqlite_escape_string("O'Brien");
        echo "escaped=" . $escaped . "\n";
        $escapedEmpty = sqlite_escape_string("");
        echo "escaped_empty=" . $escapedEmpty . "\n";
        echo "\n";

        // ── 9. NULL handling ──
        echo "=== 9. NULL handling ===\n";
        // AOT 类型安全：NULL 值统一返回空字符串 ""（无 mixed 无法区分 NULL 和空串）
        sqlite_exec($db, "CREATE TABLE null_test (id INTEGER, val TEXT)");
        sqlite_exec($db, "INSERT INTO null_test (id, val) VALUES (1, NULL)");
        $rows = sqlite_query($db, "SELECT val FROM null_test WHERE id = 1");
        $val = $rows[0]['val'];
        echo "null_val=" . $val . "\n";
        echo "null_check=" . ($val === "" ? "true" : "false") . "\n";
        echo "\n";

        // ── 10. float column ──
        echo "=== 10. float column ===\n";
        sqlite_exec($db, "CREATE TABLE float_test (id INTEGER, price REAL)");
        sqlite_exec($db, "INSERT INTO float_test (id, price) VALUES (1, 3.14)");
        $rows = sqlite_query($db, "SELECT price FROM float_test WHERE id = 1");
        echo "float_val=" . $rows[0]['price'] . "\n";
        echo "\n";

        // ── 11. BLOB column ──
        echo "=== 11. BLOB column ===\n";
        sqlite_exec($db, "CREATE TABLE blob_test (id INTEGER, data BLOB)");
        sqlite_exec($db, "INSERT INTO blob_test (id, data) VALUES (1, 'hello')");
        $rows = sqlite_query($db, "SELECT data FROM blob_test WHERE id = 1");
        echo "blob_val=" . $rows[0]['data'] . "\n";
        echo "\n";

        // ── 12. error handling ──
        echo "=== 12. error handling ===\n";
        try {
            sqlite_exec($db, "SELECT * FROM nonexistent");
        } catch (Exception $e) {
            echo "caught_no_table: " . $e->getMessage() . "\n";
        }

        try {
            sqlite_query($db, "BADSQL SYNTAX HERE");
        } catch (Exception $e) {
            echo "caught_bad_sql: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // ── 13. close & reopen ──
        echo "=== 13. close & reopen ===\n";
        sqlite_close($db);
        $db2 = sqlite_open(":memory:");
        echo "reopen_ok=" . ($db2 != 0 ? "1" : "0") . "\n";
        sqlite_close($db2);
        echo "\n";

        echo "=== sqlite3 tests done ===\n";
    }
}
