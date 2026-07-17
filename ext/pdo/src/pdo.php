<?php
// ext/pdo/src/pdo.php — PDO 扩展（SQLite 驱动）
//
// 设计说明（AOT 类型安全，无 mixed/t_var）：
//   - 所有方法参数/返回值使用 tphp 具体类型（int/string/array/bool）
//   - 不使用 mixed（会映射为 t_var 标签联合体，违反 AOT 编译期类型解析原则）
//   - 多态方法按类型拆分：bindValueInt/bindValueStr/bindValueNamedInt/bindValueNamedStr
//   - 多态返回按类型拆分：getAttributeStr/getAttributeInt/getAttributeBool
//   - fetch() 始终返回 array（元素统一为 string），标量取值用 fetchColumnStr/fetchColumnInt
//   - sqlite3*/sqlite3_stmt* 指针以 int 存储（phpc_ptr_to_int/phpc_int_to_ptr 转换）
//   - C 包装函数（tphp_fn_pdo_*）内部完成 t_int ↔ 指针转换
//   - 方法内部用 C.void* 局部变量承接 phpc_int_to_ptr 返回值，传给 C->sqlite3_*() Raw Call
//   - 错误抛 Exception（pdo_throw_* → tp_throw_ex），可被 try-catch 捕获
//   - 常量在 pdo.h 中以 TPHP_CONST_TPHP_CLASS_PDO_* 定义
//
// 文件作用：
//   - #flag 声明 sqlite3.c 源码 + -I 路径
//   - #include pdo.h 引入 sqlite3.h + 常量 + 辅助函数
//   - 声明 PDO / PDOStatement 两个 PHP 类

// SQLite amalgamation 源码（9MB 单文件，#flag .c 机制自动加入编译）
#flag __INC__ . "os/sqlite_src/sqlite3.c"
// 头文件搜索路径
#flag -I__INC__ . "os/sqlite_src"

#include __EXT__ . "pdo/pdo.h"

// ============================================================
// PDOStatement — 预处理语句
// ============================================================

class PDOStatement
{
    // SQLite 语句句柄（以 int 存储指针，内部用 phpc_int_to_ptr 转换）
    public int $stmt = 0;
    // 所属 PDO 连接句柄（不持有所有权，仅用于错误信息查询和 changes）
    public int $db = 0;
    // SQL 查询字符串（公开属性，PHP 原生 PDOStatement 有此属性）
    public string $queryString = "";
    // 默认 fetch 模式（由 setFetchMode / PDO::__construct 设置）
    public int $fetchMode = 4;  // PDO::FETCH_BOTH
    // 默认 fetch 列号（FETCH_COLUMN 模式用）
    public int $fetchCol = 0;
    // execute 后的行数（sqlite3_changes 返回值，仅对 INSERT/UPDATE/DELETE 有效）
    public int $rowCount = 0;
    // 是否已 execute（重复 execute 时需 reset）
    public bool $executed = false;
    // 是否已取完（sqlite3_step 返回 DONE）
    public bool $done = false;
    // 列数（execute 后缓存，避免重复调用 sqlite3_column_count）
    public int $columnCount = 0;
    // 首次 fetch 标志（execute 预取了一行，首次 fetch 不再 step）
    public bool $firstFetch = false;

    public function __construct(int $db, int $stmt, string $sql)
    {
        $this->db = $db;
        $this->stmt = $stmt;
        $this->queryString = $sql;
    }

    public function __destruct()
    {
        if ($this->stmt != 0) {
            C.void* $s = phpc_int_to_ptr($this->stmt);
            C->sqlite3_finalize($s);
            $this->stmt = 0;
        }
    }

    // ── bindValueInt: 绑定整数值（位置参数，1-based）──
    //   type: PDO::PARAM_NULL/INT/STR/LOB/BOOL（int 值按 type 绑定）
    public function bindValueInt(int $param, int $value, int $type = 2): bool
    {
        if ($this->stmt == 0) {
            return false;
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        $idx = $param;
        $rc = 0;
        if ($type == 0) {
            // PARAM_NULL
            $rc = php_int(C->sqlite3_bind_null($s, c_int($idx)));
        } elseif ($type == 1 || $type == 5) {
            // PARAM_INT / PARAM_BOOL
            $rc = php_int(C->sqlite3_bind_int64($s, c_int($idx), c_int($value)));
        } else {
            // PARAM_STR / PARAM_LOB — int 转字符串后绑定
            $str = c_str(strval($value));
            $len = php_int(pdo_str_len($str));
            $rc = php_int(pdo_bind_text($this->stmt, c_int($idx), $str, c_int($len)));
        }
        if ($rc != 0) {
            pdo_throw_stmt_error("PDOStatement::bindValueInt: bind failed", $this->stmt);
            return false;
        }
        return true;
    }

    // ── bindValueStr: 绑定字符串值（位置参数，1-based）──
    public function bindValueStr(int $param, string $value, int $type = 2): bool
    {
        if ($this->stmt == 0) {
            return false;
        }
        $idx = $param;
        $str = c_str($value);
        $len = php_int(pdo_str_len($str));
        $rc = php_int(pdo_bind_text($this->stmt, c_int($idx), $str, c_int($len)));
        if ($rc != 0) {
            pdo_throw_stmt_error("PDOStatement::bindValueStr: bind failed", $this->stmt);
            return false;
        }
        return true;
    }

    // ── bindValueNamedInt: 绑定整数值（命名参数 ":name"）──
    public function bindValueNamedInt(string $param, int $value, int $type = 2): bool
    {
        if ($this->stmt == 0) {
            return false;
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        $name = c_str($param);
        $idx = php_int(C->sqlite3_bind_parameter_index($s, $name));
        if ($idx == 0) {
            pdo_throw_stmt_error("PDOStatement::bindValueNamedInt: unknown parameter name", $this->stmt);
            return false;
        }
        $rc = 0;
        if ($type == 0) {
            $rc = php_int(C->sqlite3_bind_null($s, c_int($idx)));
        } elseif ($type == 1 || $type == 5) {
            $rc = php_int(C->sqlite3_bind_int64($s, c_int($idx), c_int($value)));
        } else {
            $str = c_str(strval($value));
            $len = php_int(pdo_str_len($str));
            $rc = php_int(pdo_bind_text($this->stmt, c_int($idx), $str, c_int($len)));
        }
        if ($rc != 0) {
            pdo_throw_stmt_error("PDOStatement::bindValueNamedInt: bind failed", $this->stmt);
            return false;
        }
        return true;
    }

    // ── bindValueNamedStr: 绑定字符串值（命名参数 ":name"）──
    public function bindValueNamedStr(string $param, string $value, int $type = 2): bool
    {
        if ($this->stmt == 0) {
            return false;
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        $name = c_str($param);
        $idx = php_int(C->sqlite3_bind_parameter_index($s, $name));
        if ($idx == 0) {
            pdo_throw_stmt_error("PDOStatement::bindValueNamedStr: unknown parameter name", $this->stmt);
            return false;
        }
        $str = c_str($value);
        $len = php_int(pdo_str_len($str));
        $rc = php_int(pdo_bind_text($this->stmt, c_int($idx), $str, c_int($len)));
        if ($rc != 0) {
            pdo_throw_stmt_error("PDOStatement::bindValueNamedStr: bind failed", $this->stmt);
            return false;
        }
        return true;
    }

    // ── execute: 执行预处理语句 ──
    //   params: 可选的参数数组（位置或命名），传入时由 C helper 内部处理 t_var 类型分发
    //   注意：array 默认值为 []（空数组），不是 null（PHP 8.4+ 废弃隐式 nullable）
    public function execute(array $params = []): bool
    {
        if ($this->stmt == 0) {
            pdo_throw_msg("PDOStatement::execute: no statement");
            return false;
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        // 重复 execute 时先 reset
        if ($this->executed) {
            C->sqlite3_reset($s);
            C->sqlite3_clear_bindings($s);
        }
        $this->executed = true;
        $this->done = false;
        $this->firstFetch = false;

        // 绑定传入的参数数组（C helper 内部处理 t_var 类型分发，避免 mixed 暴露到 PHP API）
        if (count($params) > 0) {
            pdo_bind_params($this->stmt, $params);
        }

        // 执行第一步（预取一行，判断是否有结果集）
        $rc = php_int(C->sqlite3_step($s));
        if ($rc == 100) {
            // SQLITE_ROW — 有数据，预取了一行
            $this->firstFetch = true;
            $this->columnCount = php_int(C->sqlite3_data_count($s));
            if ($this->columnCount == 0) {
                $this->columnCount = php_int(C->sqlite3_column_count($s));
            }
        } elseif ($rc == 101) {
            // SQLITE_DONE — 无数据（INSERT/UPDATE/DELETE 或空结果集）
            $this->done = true;
            $this->columnCount = php_int(C->sqlite3_column_count($s));
            C.void* $db = phpc_int_to_ptr($this->db);
            $this->rowCount = php_int(C->sqlite3_changes($db));
        } else {
            // 错误
            pdo_throw_stmt_error("PDOStatement::execute: step failed", $this->stmt);
            return false;
        }
        return true;
    }

    // ── fetch: 取一行，返回 array ──
    //   mode: PDO::FETCH_ASSOC / FETCH_NUM / FETCH_BOTH / FETCH_KEY_PAIR
    //   返回 array<string>（所有列值统一转为字符串，AOT 类型安全）
    //   取完返回空数组（用 fetchDone() 或 count($row)==0 检测）
    //   注意：FETCH_COLUMN 模式已移除（用 fetchColumnStr/fetchColumnInt 替代）
    public function fetch(int $mode = 0): array
    {
        // FETCH_DEFAULT → 使用默认模式
        if ($mode == 0) {
            $mode = $this->fetchMode;
        }
        // 已取完或未 execute → 返回空数组
        if ($this->done) {
            return [];
        }
        if (!$this->executed) {
            pdo_throw_msg("PDOStatement::fetch: statement not executed");
            return [];
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        // 判断是否需要 step
        if ($this->firstFetch) {
            $this->firstFetch = false;
        } else {
            $rc = php_int(C->sqlite3_step($s));
            if ($rc == 101) {
                // SQLITE_DONE
                $this->done = true;
                return [];
            } elseif ($rc != 100) {
                pdo_throw_stmt_error("PDOStatement::fetch: step failed", $this->stmt);
                return [];
            }
        }
        // 此时 stmt 定位在当前行，读取列数据
        $cc = $this->columnCount;
        if ($cc == 0) {
            $cc = php_int(C->sqlite3_column_count($s));
            $this->columnCount = $cc;
        }
        $arr = [];
        if ($mode == 3) {
            // FETCH_NUM: 索引数组
            for ($i = 0; $i < $cc; $i = $i + 1) {
                $arr[] = $this->_fetchColumn($i);
            }
        } elseif ($mode == 2) {
            // FETCH_ASSOC: 关联数组
            for ($i = 0; $i < $cc; $i = $i + 1) {
                $name = php_str(pdo_column_name($this->stmt, c_int($i)));
                $arr[$name] = $this->_fetchColumn($i);
            }
        } elseif ($mode == 12) {
            // FETCH_KEY_PAIR: 第一列 key，第二列 value
            if ($cc < 2) {
                pdo_throw_msg("PDOStatement::fetch: FETCH_KEY_PAIR requires at least 2 columns");
                return [];
            }
            $k = $this->_fetchColumn(0);
            $v = $this->_fetchColumn(1);
            $arr[$k] = $v;
        } else {
            // FETCH_BOTH (4, 默认): 关联 + 索引
            for ($i = 0; $i < $cc; $i = $i + 1) {
                $name = php_str(pdo_column_name($this->stmt, c_int($i)));
                $val = $this->_fetchColumn($i);
                $arr[$name] = $val;
                $arr[$i] = $val;
            }
        }
        return $arr;
    }

    // ── _fetchColumn: 读取当前行指定列的值，统一返回 string（内部辅助）──
    //   int/float/null 均转为字符串，保证 array 元素类型一致（AOT 类型安全）
    private function _fetchColumn(int $col): string
    {
        $c = c_int($col);
        $t = php_int(C->sqlite3_column_type(phpc_int_to_ptr($this->stmt), $c));
        if ($t == 5) {
            // SQLITE_NULL → 空字符串
            return "";
        } elseif ($t == 1) {
            // SQLITE_INTEGER → 转字符串
            $v = php_int(C->sqlite3_column_int64(phpc_int_to_ptr($this->stmt), $c));
            return strval($v);
        } elseif ($t == 2) {
            // SQLITE_FLOAT → 转字符串（用独立变量避免与 int $v 类型冲突）
            $fv = pdo_column_double($this->stmt, $c);
            return strval($fv);
        } else {
            // SQLITE_TEXT(3) / SQLITE_BLOB(4) — 统一按字符串返回
            // pdo_column_text 返回 SQLite 借用指针（下次 step/reset 后失效），
            // pdo_str_from_ptr 内部深拷贝到 str_pool，无泄漏
            $len = php_int(C->sqlite3_column_bytes(phpc_int_to_ptr($this->stmt), $c));
            return pdo_str_from_ptr(pdo_column_text($this->stmt, $c), c_int($len));
        }
    }

    // ── _fetchColumnInt: 读取当前行指定列的整数值（内部辅助）──
    //   null/float/string 列调用此方法返回 0（调用方需确保列类型为 INTEGER）
    private function _fetchColumnInt(int $col): int
    {
        $c = c_int($col);
        $t = php_int(C->sqlite3_column_type(phpc_int_to_ptr($this->stmt), $c));
        if ($t == 1) {
            // SQLITE_INTEGER
            return php_int(C->sqlite3_column_int64(phpc_int_to_ptr($this->stmt), $c));
        }
        return 0;
    }

    // ── fetchAll: 取所有行，返回 array ──
    //   返回 array<array<string>>（外层数组元素是行数组，行数组元素是字符串）
    //   FETCH_KEY_PAIR 模式返回 array<string>（key=>value 单层）
    public function fetchAll(int $mode = 0): array
    {
        if ($mode == 0) {
            $mode = $this->fetchMode;
        }
        $result = [];
        if ($mode == 12) {
            // FETCH_KEY_PAIR: 第一列作 key，第二列作 value
            // 用 FETCH_NUM 取行后直接按索引取值，避免 array_keys 元素类型推导问题
            while (true) {
                $row = $this->fetch(3);  // FETCH_NUM
                if (count($row) == 0) {
                    break;
                }
                $k = $row[0];
                $v = $row[1];
                $result[$k] = $v;
            }
        } else {
            // 其他模式：每行作为一个元素追加
            while (true) {
                $row = $this->fetch($mode);
                if (count($row) == 0) {
                    break;
                }
                $result[] = $row;
            }
        }
        return $result;
    }

    // ── fetchColumnStr: 取下一行的指定列，返回 string ──
    //   取完返回空字符串（用 fetchDone() 检测）
    public function fetchColumnStr(int $col = 0): string
    {
        $row = $this->fetch(3);  // FETCH_NUM
        if (count($row) == 0) {
            return "";
        }
        $c = php_int($col);
        if ($c >= count($row)) {
            return "";
        }
        return $row[$c];
    }

    // ── fetchColumnInt: 取下一行的指定列，返回 int ──
    //   适用于 INTEGER 列（如 COUNT(*)）。取完返回 0。
    public function fetchColumnInt(int $col = 0): int
    {
        if ($this->done) {
            return 0;
        }
        if (!$this->executed) {
            pdo_throw_msg("PDOStatement::fetchColumnInt: statement not executed");
            return 0;
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        if ($this->firstFetch) {
            $this->firstFetch = false;
        } else {
            $rc = php_int(C->sqlite3_step($s));
            if ($rc == 101) {
                $this->done = true;
                return 0;
            } elseif ($rc != 100) {
                pdo_throw_stmt_error("PDOStatement::fetchColumnInt: step failed", $this->stmt);
                return 0;
            }
        }
        return $this->_fetchColumnInt(php_int($col));
    }

    // ── fetchDone: 检测 fetch 是否已取完 ──
    //   用于替代 PHP 原生 fetch() === false 的判断
    public function fetchDone(): bool
    {
        return $this->done;
    }

    // ── closeCursor: 关闭游标（允许再次 execute）──
    public function closeCursor(): bool
    {
        if ($this->stmt == 0) {
            return true;
        }
        C.void* $s = phpc_int_to_ptr($this->stmt);
        C->sqlite3_reset($s);
        $this->done = false;
        $this->firstFetch = false;
        $this->executed = false;
        return true;
    }

    // ── columnCount: 列数 ──
    public function columnCount(): int
    {
        if ($this->stmt == 0) {
            return 0;
        }
        return php_int(C->sqlite3_column_count(phpc_int_to_ptr($this->stmt)));
    }

    // ── rowCount: 受影响行数 ──
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    // ── setFetchMode: 设置默认 fetch 模式 ──
    //   arg: FETCH_COLUMN 模式的列号（int，不是 null）
    public function setFetchMode(int $mode, int $arg = 0): bool
    {
        $this->fetchMode = $mode;
        if ($mode == 7) {
            // FETCH_COLUMN 的列号参数
            $this->fetchCol = $arg;
        }
        return true;
    }

    // ── errorCode: 返回 SQLSTATE ──
    public function errorCode(): string
    {
        $rc = php_int(C->sqlite3_errcode(phpc_int_to_ptr($this->db)));
        return pdo_sqlite_errstate(c_int($rc));
    }

    // ── errorInfo: 返回 [SQLSTATE, native_code, message] ──
    public function errorInfo(): array
    {
        $rc = php_int(C->sqlite3_errcode(phpc_int_to_ptr($this->db)));
        $state = pdo_sqlite_errstate(c_int($rc));
        $msg = php_str(pdo_errmsg($this->db));
        return [$state, strval($rc), $msg];
    }

    // ── getColumnMeta: 列元信息 ──
    public function getColumnMeta(int $col): array
    {
        $c = c_int($col);
        $name = php_str(pdo_column_name($this->stmt, $c));
        $declType = php_str(pdo_column_decltype($this->stmt, $c));
        $t = php_int(C->sqlite3_column_type(phpc_int_to_ptr($this->stmt), $c));
        $pdoType = 2;  // PARAM_STR 默认
        if ($t == 1) {
            $pdoType = 1;  // PARAM_INT
        } elseif ($t == 2) {
            $pdoType = 2;  // PARAM_STR（PHP 原生把 float 也归为 STR）
        } elseif ($t == 5) {
            $pdoType = 0;  // PARAM_NULL
        } elseif ($t == 4) {
            $pdoType = 3;  // PARAM_LOB
        }
        return [
            "native_type" => $declType,
            "pdo_type" => strval($pdoType),
            "name" => $name,
        ];
    }
}

// ============================================================
// PDO — 数据库连接
// ============================================================

class PDO
{
    // SQLite 连接句柄（以 int 存储指针，内部用 phpc_int_to_ptr 转换）
    public int $db = 0;
    // 错误模式：0=SILENT 1=WARNING 2=EXCEPTION
    public int $errMode = 2;
    // 列名大小写：0=NATURAL 1=LOWER 2=UPPER
    public int $caseMode = 0;
    // 空值处理：0=NATURAL 1=EMPTY_STRING→NULL 2=NULL→STRING
    public int $nullMode = 0;
    // 默认 fetch 模式
    public int $defaultFetchMode = 4;  // FETCH_BOTH
    // 是否在事务中
    public bool $inTransaction = false;
    // 事务模式：0=DEFERRED 1=IMMEDIATE 2=EXCLUSIVE
    public int $txnMode = 0;
    // 打开 flags（默认 READWRITE | CREATE = 6）
    public int $openFlags = 6;

    // 注意：array 默认值为 []（空数组），不是 null（PHP 8.4+ 废弃隐式 nullable）
    public function __construct(string $dsn, string $username = "", string $password = "", array $options = [])
    {
        $this->db = 0;
        // 处理 options 数组
        $flags = $this->openFlags;
        $timeout = 60;
        if (count($options) > 0) {
            foreach ($options as $k => $v) {
                $ki = php_int($k);
                if ($ki == 1000) {
                    // ATTR_OPEN_FLAGS
                    $flags = php_int($v);
                } elseif ($ki == 2) {
                    // ATTR_TIMEOUT
                    $timeout = php_int($v);
                } elseif ($ki == 3) {
                    // ATTR_ERRMODE
                    $this->errMode = php_int($v);
                } elseif ($ki == 8) {
                    // ATTR_CASE
                    $this->caseMode = php_int($v);
                } elseif ($ki == 11) {
                    // ATTR_ORACLE_NULLS
                    $this->nullMode = php_int($v);
                } elseif ($ki == 19) {
                    // ATTR_DEFAULT_FETCH_MODE
                    $this->defaultFetchMode = php_int($v);
                } elseif ($ki == 1005) {
                    // ATTR_TRANSACTION_MODE
                    $this->txnMode = php_int($v);
                }
            }
        }
        $this->openFlags = $flags;
        // 打开数据库（pdo_open_db 内部解析 DSN + 调用 sqlite3_open_v2）
        $dsnC = c_str($dsn);
        $db = pdo_open_db($dsnC, c_int($flags));
        if ($db == 0) {
            return;  // 已抛异常
        }
        $this->db = $db;
        // 设置 busy timeout
        C.void* $dbh = phpc_int_to_ptr($this->db);
        C->sqlite3_busy_timeout($dbh, c_int($timeout * 1000));
        // 启用扩展结果码（更详细的错误信息）
        C->sqlite3_extended_result_codes($dbh, c_int(1));
    }

    public function __destruct()
    {
        if ($this->db != 0) {
            C.void* $dbh = phpc_int_to_ptr($this->db);
            C->sqlite3_close_v2($dbh);
            $this->db = 0;
        }
    }

    // ── prepare: 预处理 SQL ──
    public function prepare(string $query, array $options = []): PDOStatement|Exception
    {
        if ($this->db == 0) {
            pdo_throw_msg("PDO::prepare: no active database connection");
        }
        // 检查 options 中的 ATTR_CURSOR（SQLite 仅支持 CURSOR_FWDONLY）
        if (count($options) > 0) {
            foreach ($options as $k => $v) {
                $ki = php_int($k);
                if ($ki == 10) {
                    // ATTR_CURSOR
                    if (php_int($v) != 0) {
                        pdo_throw_msg("PDO::prepare: SQLite only supports PDO::CURSOR_FWDONLY");
                    }
                }
            }
        }
        $sqlC = c_str($query);
        $stmt = pdo_prepare($this->db, $sqlC);
        $st = new PDOStatement($this->db, $stmt, $query);
        $st->fetchMode = $this->defaultFetchMode;
        return $st;
    }

    // ── query: 执行 SQL 并返回 PDOStatement ──
    public function query(string $query, int $fetchMode = 0): PDOStatement|Exception
    {
        $st = $this->prepare($query);
        $st->execute();
        if ($fetchMode != 0) {
            $st->setFetchMode($fetchMode);
        }
        return $st;
    }

    // ── exec: 执行无结果集的 SQL，返回受影响行数 ──
    public function exec(string $statement): int|Exception
    {
        if ($this->db == 0) {
            pdo_throw_msg("PDO::exec: no active database connection");
        }
        $sqlC = c_str($statement);
        $n = php_int(pdo_exec($this->db, $sqlC));
        return $n;
    }

    // ── quote: 转义字符串（用 sqlite3_mprintf 的 %Q 格式）──
    public function quote(string $string, int $type = 2): string|Exception
    {
        if ($this->db == 0) {
            pdo_throw_msg("PDO::quote: no active database connection");
        }
        $s = c_str($string);
        return pdo_quote($s);
    }

    // ── lastInsertId: 最后插入的行 ID ──
    public function lastInsertId(string $name = ""): string|Exception
    {
        if ($this->db == 0) {
            pdo_throw_msg("PDO::lastInsertId: no active database connection");
        }
        // SQLite 的 last_insert_rowid 返回 int64
        C.void* $dbh = phpc_int_to_ptr($this->db);
        $id = php_int(C->sqlite3_last_insert_rowid($dbh));
        // PHP 原生返回字符串（避免大整数溢出）
        return strval($id);
    }

    // ── 事务 ──
    public function beginTransaction(): bool
    {
        if ($this->db == 0) {
            return false;
        }
        if ($this->inTransaction) {
            pdo_throw_msg("PDO::beginTransaction: already in transaction");
            return false;
        }
        $sql = "BEGIN";
        if ($this->txnMode == 1) {
            $sql = "BEGIN IMMEDIATE";
        } elseif ($this->txnMode == 2) {
            $sql = "BEGIN EXCLUSIVE";
        }
        $n = php_int(pdo_exec($this->db, c_str($sql)));
        if ($n < 0) {
            return false;
        }
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        if ($this->db == 0) {
            return false;
        }
        if (!$this->inTransaction) {
            pdo_throw_msg("PDO::commit: no active transaction");
            return false;
        }
        $n = php_int(pdo_exec($this->db, c_str("COMMIT")));
        if ($n < 0) {
            return false;
        }
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->db == 0) {
            return false;
        }
        if (!$this->inTransaction) {
            pdo_throw_msg("PDO::rollBack: no active transaction");
            return false;
        }
        $n = php_int(pdo_exec($this->db, c_str("ROLLBACK")));
        if ($n < 0) {
            return false;
        }
        $this->inTransaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    // ── errorCode / errorInfo ──
    public function errorCode(): string
    {
        if ($this->db == 0) {
            return "00000";
        }
        $rc = php_int(C->sqlite3_errcode(phpc_int_to_ptr($this->db)));
        return pdo_sqlite_errstate(c_int($rc));
    }

    public function errorInfo(): array
    {
        if ($this->db == 0) {
            return ["00000", "0", ""];
        }
        $rc = php_int(C->sqlite3_errcode(phpc_int_to_ptr($this->db)));
        $state = pdo_sqlite_errstate(c_int($rc));
        $msg = php_str(pdo_errmsg($this->db));
        return [$state, strval($rc), $msg];
    }

    // ── getAttributeStr: 获取字符串类属性 ──
    //   适用于 ATTR_DRIVER_NAME / ATTR_SERVER_VERSION / ATTR_CLIENT_VERSION / ATTR_SERVER_INFO
    public function getAttributeStr(int $attribute): string
    {
        $a = php_int($attribute);
        if ($a == 16) {
            // ATTR_DRIVER_NAME
            return "sqlite";
        } elseif ($a == 4 || $a == 5 || $a == 6) {
            // ATTR_SERVER_VERSION / ATTR_CLIENT_VERSION / ATTR_SERVER_INFO
            return php_str(pdo_libversion());
        }
        return "";
    }

    // ── getAttributeInt: 获取整数类属性 ──
    //   适用于 ATTR_ERRMODE / ATTR_CASE / ATTR_ORACLE_NULLS / ATTR_DEFAULT_FETCH_MODE / ATTR_TIMEOUT
    public function getAttributeInt(int $attribute): int
    {
        $a = php_int($attribute);
        if ($a == 3) {
            return $this->errMode;
        } elseif ($a == 8) {
            return $this->caseMode;
        } elseif ($a == 11) {
            return $this->nullMode;
        } elseif ($a == 19) {
            return $this->defaultFetchMode;
        } elseif ($a == 2) {
            return 60;
        }
        return 0;
    }

    // ── getAttributeBool: 获取布尔类属性 ──
    //   适用于 ATTR_AUTOCOMMIT
    public function getAttributeBool(int $attribute): bool
    {
        $a = php_int($attribute);
        if ($a == 0) {
            // ATTR_AUTOCOMMIT（SQLite 默认 autocommit）
            return true;
        }
        return false;
    }

    // ── setAttribute: 设置属性（value 统一为 int）──
    //   所有可设置属性均为整数类型（ERRMODE/CASE/ORACLE_NULLS/DEFAULT_FETCH_MODE/TIMEOUT/TRANSACTION_MODE）
    public function setAttribute(int $attribute, int $value): bool
    {
        $a = php_int($attribute);
        if ($a == 3) {
            $this->errMode = $value;
            return true;
        } elseif ($a == 8) {
            $this->caseMode = $value;
            return true;
        } elseif ($a == 11) {
            $this->nullMode = $value;
            return true;
        } elseif ($a == 19) {
            $this->defaultFetchMode = $value;
            return true;
        } elseif ($a == 2) {
            // ATTR_TIMEOUT
            if ($this->db != 0) {
                $ms = $value * 1000;
                C.void* $dbh = phpc_int_to_ptr($this->db);
                C->sqlite3_busy_timeout($dbh, c_int($ms));
            }
            return true;
        } elseif ($a == 1000) {
            // ATTR_OPEN_FLAGS（仅在 open 前有效，已 open 则忽略）
            return true;
        } elseif ($a == 1005) {
            $this->txnMode = $value;
            return true;
        }
        // 不支持的属性静默忽略（PHP 原生返回 false，这里返回 true 保持简单）
        return true;
    }

    // ── getAvailableDrivers: 静态方法，返回支持的驱动列表 ──
    public static function getAvailableDrivers(): array
    {
        return ["sqlite"];
    }
}
