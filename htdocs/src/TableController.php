<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 表 CRUD 逻辑。所有表名/列名都经过白名单校验，值全部走 PDO 预处理，防注入。
 */
class TableController
{
    // 表名/列名只允许字母数字下划线
    const IDENTIFIER_RE = '/^[A-Za-z0-9_]+$/';

    // ---------- 表级操作 ----------

    public static function listTables()
    {
        $rows = Database::get()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = $row[0];
        }
        sort($tables);
        return $tables;
    }

    public static function schema($table)
    {
        self::checkTable($table);
        return [
            'table'       => $table,
            'primary_key' => self::primaryKey($table),
            'columns'     => self::columns($table),
        ];
    }

    // ---------- 行级 CRUD ----------

    public static function listRows($table, array $params)
    {
        self::checkTable($table);
        $cols = self::columns($table);
        $colNames = array_column($cols, 'Field');

        // 选择列
        $select = '*';
        if (!empty($params['columns'])) {
            $reqCols = array_map('trim', explode(',', $params['columns']));
            foreach ($reqCols as $c) {
                if (!in_array($c, $colNames, true)) {
                    Response::error('invalid_column', 'Unknown column: ' . $c, 400);
                }
            }
            $select = '`' . implode('`,`', $reqCols) . '`';
        }

        // limit / offset
        $maxLimit = (int) Config::get('max_limit', 500);
        $limit = isset($params['limit']) ? (int) $params['limit'] : 50;
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > $maxLimit) {
            $limit = $maxLimit;
        }
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        // order
        $orderBy = '';
        if (!empty($params['order'])) {
            $orderCol = $params['order'];
            if (!in_array($orderCol, $colNames, true)) {
                Response::error('invalid_column', 'Unknown order column: ' . $orderCol, 400);
            }
            $dir = strtolower($params['dir'] ?? 'asc');
            if (!in_array($dir, ['asc', 'desc'], true)) {
                $dir = 'asc';
            }
            $orderBy = " ORDER BY `$orderCol` $dir";
        }

        // where / 搜索
        $values = [];
        $whereSql = self::buildWhere($cols, $params['where'] ?? null, $params['q'] ?? null, $values);

        $sql = "SELECT $select FROM `$table`$whereSql$orderBy LIMIT $limit OFFSET $offset";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($values);
        $rows = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) FROM `$table`$whereSql";
        $stmt2 = Database::get()->prepare($countSql);
        $stmt2->execute($values);
        $total = (int) $stmt2->fetchColumn();

        return [
            'rows' => $rows,
            'meta' => [
                'table'  => $table,
                'count'  => count($rows),
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ];
    }

    public static function getRow($table, $id)
    {
        self::checkTable($table);
        $pk = self::requirePrimaryKey($table);

        $stmt = Database::get()->prepare("SELECT * FROM `$table` WHERE `$pk` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Response::error('not_found', 'Row not found.', 404);
        }
        return $row;
    }

    public static function insertRow($table, array $data)
    {
        self::checkTable($table);
        $cols = self::columns($table);
        $colNames = array_column($cols, 'Field');
        $pk = self::primaryKey($table);

        $insert = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $colNames, true)) {
                continue; // 忽略未知列
            }
            if ($key === $pk && self::isAutoIncrement($cols, $key)) {
                continue; // 自增主键交给数据库
            }
            $insert[$key] = $value;
        }

        if (!$insert) {
            Response::error('empty_body', 'No valid columns to insert.', 400);
        }

        $fields = array_keys($insert);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $fields) . '`) VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')';
        Database::get()->prepare($sql)->execute(array_values($insert));

        $id = (int) Database::get()->lastInsertId();
        if ($pk && $id > 0) {
            return self::getRow($table, $id);
        }
        return ['inserted' => true, 'row' => $insert];
    }

    public static function updateRow($table, $id, array $data)
    {
        self::checkTable($table);
        $cols = self::columns($table);
        $colNames = array_column($cols, 'Field');
        $pk = self::requirePrimaryKey($table);

        $sets = [];
        $values = [];
        foreach ($data as $key => $value) {
            if ($key === $pk) {
                continue; // 不允许改主键
            }
            if (!in_array($key, $colNames, true)) {
                continue;
            }
            $sets[] = "`$key` = ?";
            $values[] = $value;
        }

        if (!$sets) {
            Response::error('empty_body', 'No valid columns to update.', 400);
        }

        $values[] = $id;
        $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$pk` = ?";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($values);

        if ($stmt->rowCount() === 0) {
            // 可能值没变，也可能行不存在
            $check = Database::get()->prepare("SELECT 1 FROM `$table` WHERE `$pk` = ?");
            $check->execute([$id]);
            if (!$check->fetch()) {
                Response::error('not_found', 'Row not found.', 404);
            }
        }
        return self::getRow($table, $id);
    }

    public static function deleteRow($table, $id)
    {
        self::checkTable($table);
        $pk = self::requirePrimaryKey($table);

        $stmt = Database::get()->prepare("DELETE FROM `$table` WHERE `$pk` = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            Response::error('not_found', 'Row not found.', 404);
        }
        return ['deleted' => true, 'rows' => $stmt->rowCount()];
    }

    // ---------- 裸 SQL ----------

    public static function rawQuery($sql)
    {
        $allow = Config::get('allow_raw_query');
        // JWT 里显式带了 allow_query 则覆盖全局设置
        $jwtAllow = Auth::claims('allow_query');
        if ($jwtAllow !== null) {
            $allow = (bool) $jwtAllow;
        }
        if (!$allow) {
            Response::error('forbidden', 'Raw query is disabled on this server.', 403);
        }
        $sql = trim($sql);
        if ($sql === '') {
            Response::error('empty_query', 'SQL is empty.', 400);
        }
        if (Config::get('raw_query_readonly')) {
            $word = strtoupper(strtok($sql, " \t\n\r("));
            if (!in_array($word, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
                Response::error('readonly', 'Only read-only queries are allowed.', 403);
            }
        }

        $stmt = Database::get()->query($sql);
        if ($stmt->columnCount() > 0) {
            return ['rows' => $stmt->fetchAll()];
        }
        return ['affected_rows' => $stmt->rowCount()];
    }

    // ---------- 内部工具 ----------

    public static function checkTable($table)
    {
        if (!preg_match(self::IDENTIFIER_RE, $table)) {
            Response::error('invalid_table', 'Invalid table name.', 400);
        }
        $allowed = Config::get('allowed_tables', []);
        // JWT 里带了 tables 权限声明，则与全局白名单取交集
        $jwtTables = Auth::claims('tables');
        if ($jwtTables !== null && is_array($jwtTables)) {
            $allowed = empty($allowed)
                ? $jwtTables
                : array_values(array_intersect($allowed, $jwtTables));
        }
        if (!empty($allowed) && !in_array($table, $allowed, true)) {
            Response::error('forbidden_table', 'Table is not in the allowed list.', 403);
        }
        if (!self::tableExists($table)) {
            Response::error('table_not_found', "Table '$table' does not exist.", 404);
        }
        return $table;
    }

    private static function tableExists($table)
    {
        return in_array($table, self::listTables(), true);
    }

    private static function columns($table)
    {
        $stmt = Database::get()->query("SHOW COLUMNS FROM `$table`");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function primaryKey($table)
    {
        foreach (self::columns($table) as $col) {
            if ($col['Key'] === 'PRI') {
                return $col['Field'];
            }
        }
        return null;
    }

    private static function requirePrimaryKey($table)
    {
        $pk = self::primaryKey($table);
        if ($pk === null) {
            Response::error('no_primary_key', "Table '$table' has no primary key; single-row operations are unavailable.", 400);
        }
        return $pk;
    }

    private static function isAutoIncrement(array $cols, $field)
    {
        foreach ($cols as $col) {
            if ($col['Field'] === $field) {
                return stripos($col['Extra'], 'auto_increment') !== false;
            }
        }
        return false;
    }

    /**
     * 构造 WHERE 子句。
     * $where 支持：
     *   {"age": 18}                          -> age = 18
     *   {"age": {"op": ">", "value": 18}}    -> age > 18
     *   {"id": {"op": "IN", "value": [1,2]}} -> id IN (1,2)
     * 多个条件用 AND 连接。
     * $q 会在所有 text/char 列上做 LIKE 模糊搜索。
     */
    private static function buildWhere(array $cols, $where, $q, &$values)
    {
        $colNames = array_column($cols, 'Field');
        $conditions = [];
        $values = [];

        if ($where !== null && $where !== '') {
            if (is_string($where)) {
                $where = json_decode($where, true);
            }
            if (!is_array($where)) {
                Response::error('invalid_where', 'Parameter "where" must be a JSON object.', 400);
            }

            $allowedOps = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

            foreach ($where as $col => $cond) {
                if (!in_array($col, $colNames, true)) {
                    Response::error('invalid_column', 'Unknown column in where: ' . $col, 400);
                }
                if (is_array($cond)) {
                    $op = strtoupper(trim(isset($cond['op']) ? $cond['op'] : '='));
                    if (!in_array($op, $allowedOps, true)) {
                        Response::error('invalid_where', 'Unsupported operator: ' . $op, 400);
                    }
                    $val = array_key_exists('value', $cond) ? $cond['value'] : null;

                    if ($op === 'IN' || $op === 'NOT IN') {
                        if (!is_array($val)) {
                            Response::error('invalid_where', "Operator $op requires an array value.", 400);
                        }
                        $ph = implode(',', array_fill(0, count($val), '?'));
                        $conditions[] = "`$col` $op ($ph)";
                        foreach ($val as $v) {
                            $values[] = $v;
                        }
                    } else {
                        $conditions[] = "`$col` $op ?";
                        $values[] = $val;
                    }
                } else {
                    $conditions[] = "`$col` = ?";
                    $values[] = $cond;
                }
            }
        }

        if ($q !== null && $q !== '') {
            $like = '%' . $q . '%';
            $searchParts = [];
            foreach ($cols as $col) {
                $type = strtolower($col['Type']);
                if (strpos($type, 'char') !== false || strpos($type, 'text') !== false) {
                    $searchParts[] = "`{$col['Field']}` LIKE ?";
                    $values[] = $like;
                }
            }
            if ($searchParts) {
                $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        return $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    }
}
