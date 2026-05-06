<?php

namespace Bpjs\Framework\Helpers;

use PDO;
use PDOException;
use Bpjs\Framework\Helpers\Database;

class DB
{
    private static $conn = null;
    protected $table;
    protected $query = '';
    protected $params = [];
    protected $joins = [];
    protected $conditions = [];
    protected $limit;
    protected $offset;
    protected $selectColumns = ['*'];  // Default select columns
    protected $orderBy = [];
    protected $groupBy = [];
    protected $having = [];

    // Mendapatkan koneksi database
    public static function getConnection()
    {
        if (self::$conn === null) {
            self::$conn = Database::connection();
        }
        return self::$conn;
    }

    // Mulai transaksi
    public static function beginTransaction()
    {
        return self::getConnection()->beginTransaction();
    }

    // Commit transaksi
    public static function commit()
    {
        return self::getConnection()->commit();
    }

    // Rollback transaksi
    public static function rollback()
    {
        return self::getConnection()->rollback();
    }

    // Menetapkan nama tabel
    public static function table($table)
    {
        $instance = new self();
        $instance->reset();
        $instance->table = $table;
        return $instance;
    }

    // Union query
    public function union($query)
    {
        $this->query .= " UNION ($query)";
        return $this;
    }

    // Union All query
    public function unionAll($query)
    {
        $this->query .= " UNION ALL ($query)";
        return $this;
    }

    // Lock untuk update
    public function lockForUpdate()
    {
        $this->query .= ' FOR UPDATE';
        return $this;
    }

    // Lock bersama
    public function sharedLock()
    {
        $this->query .= ' LOCK IN SHARE MODE';
        return $this;
    }

    public function orderBy($column, $direction = 'ASC')
    {
        $this->orderBy[] = "{$this->quote($column)} " . strtoupper($direction);
        return $this;
    }

    public function groupBy(...$columns)
    {
        $this->groupBy = $columns;
        return $this;
    }

    public function having($column, $operator, $value)
    {
        $key = ':having_' . count($this->params);

        $this->having[] = "{$this->quote($column)} $operator $key";
        $this->params[$key] = $value;

        return $this;
    }

    private function reset()
    {
        $this->query = '';
        $this->params = [];
        $this->joins = [];
        $this->conditions = [];
        $this->limit = null;
        $this->offset = null;
        $this->selectColumns = ['*'];
    }

    private function quote($identifier)
    {
        if ($identifier === '*') return $identifier;

        return preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
    }

    // Menetapkan kolom yang ingin diambil
    public function select(...$columns)
    {
        $this->selectColumns = empty($columns) ? ['*'] : $columns;

        $cols = implode(', ', array_map(fn($c) => $this->quote($c), $this->selectColumns));

        $this->query = "SELECT $cols FROM {$this->quote($this->table)}";

        return $this;
    }

    // Menambahkan JOIN
    public function join($table, $first, $operator, $second, $type = 'INNER')
    {
        $this->joins[] = "$type JOIN $table ON $first $operator $second";
        return $this;
    }

    // Menambahkan kondisi WHERE
    public function where($column, $operator, $value)
    {
        $key = ':' . str_replace('.', '_', $column) . count($this->params);

        $this->conditions[] = [
            'type' => 'AND',
            'sql'  => "{$this->quote($column)} $operator $key"
        ];

        $this->params[$key] = $value;

        return $this;
    }

    // Menambahkan kondisi OR WHERE
    public function orWhere($column, $operator, $value)
    {
        $key = ':' . str_replace('.', '_', $column) . count($this->params);

        $this->conditions[] = [
            'type' => 'OR',
            'sql'  => "{$this->quote($column)} $operator $key"
        ];

        $this->params[$key] = $value;

        return $this;
    }

    // Membatasi jumlah hasil query
    public function limit($limit, $offset = null)
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    private function buildWhere()
    {
        if (empty($this->conditions)) return '';

        $sql = ' WHERE ';
        $first = true;

        foreach ($this->conditions as $cond) {
            if (!$first) {
                $sql .= " {$cond['type']} ";
            }

            $sql .= $cond['sql'];
            $first = false;
        }

        return $sql;
    }

    protected function execute($sql, $params = [], $fetchStyle = PDO::FETCH_OBJ)
    {
        try {
            $start = microtime(true);

            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);

            $duration = round((microtime(true) - $start) * 1000, 2);

            QueryLogger::add($sql, $params, $duration, 'DB');

            return $stmt->fetchAll($fetchStyle);

        } catch (PDOException $e) {
            error_log($e->getMessage());

            if (env('APP_DEBUG')) {
                throw $e;
            }

            return [];
        }
    }

    // Mendapatkan hasil query
    public function get($fetchStyle = PDO::FETCH_OBJ)
    {
        $sql = $this->query;

        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->buildWhere();

        if ($this->limit) {
            $sql .= ' LIMIT ' . (int)$this->limit;

            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . (int)$this->offset;
            }
        }

        if ($this->groupBy) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($c) => $this->quote($c), $this->groupBy));
        }

        if ($this->having) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        $result = $this->execute($sql, $this->params, $fetchStyle);

        $this->reset();

        return $result;
    }

    public function find($id, $column = 'id')
    {
        $this->where($column, '=', $id);
        return $this->first();
    }

    public function count($column = '*')
    {
        $sql = "SELECT COUNT($column) as aggregate FROM {$this->quote($this->table)}";
        $sql .= $this->buildWhere();

        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($this->params);

        $result = $stmt->fetch(PDO::FETCH_OBJ);

        $this->reset();

        return (int) $result->aggregate;
    }

    public function exists()
    {
        return $this->count() > 0;
    }

    public function value($column)
    {
        $result = $this->select($column)->first();

        if (!$result) return null;

        return $result->{$column};
    }

    public function pluck($column)
    {
        $results = $this->select($column)->get();

        return array_map(fn($row) => $row->{$column}, $results);
    }

    public function chunk($size, callable $callback)
    {
        $page = 1;

        do {
            $data = $this->limit($size, ($page - 1) * $size)->get();

            if (empty($data)) break;

            $callback($data);

            $page++;

        } while (true);
    }

    public function upsert($data, $uniqueBy)
    {
        $columns = array_keys($data);

        $placeholders = ':' . implode(', :', $columns);

        $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $columns));

        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ")
                VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE $updates";

        return $this->execute($sql, $data);
    }

    public function toSql()
    {
        $sql = $this->query;
        $sql .= $this->buildWhere();

        return $sql;
    }

    public function paginate($page = 1, $perPage = 10)
    {
        $offset = ($page - 1) * $perPage;

        $data = $this->limit($perPage, $offset)->get();

        return [
            'data' => $data,
            'page' => $page,
            'per_page' => $perPage
        ];
    }

    public function datatables($request)
    {
        $page = ($request['start'] ?? 0) / ($request['length'] ?? 10) + 1;
        $length = $request['length'] ?? 10;

        $data = $this->paginate($page, $length);

        return [
            "draw" => intval($request['draw'] ?? 1),
            "recordsTotal" => count($data['data']),
            "recordsFiltered" => count($data['data']),
            "data" => $data['data']
        ];
    }

    // Mendapatkan satu baris hasil query
    public function first($fetchStyle = PDO::FETCH_OBJ)
    {
        $start = microtime(true);
        $sql = $this->query;

        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->buildWhere();
        $sql .= ' LIMIT 1';

        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($this->params);
        $duration = round((microtime(true) - $start) * 1000, 2);

        $this->reset();
        QueryLogger::add($this->query, $this->params, $duration, 'DB::table');
        return $stmt->fetch($fetchStyle);
    }

    // Menyisipkan data
    public function insert($data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $this->query = "INSERT INTO $this->table ($columns) VALUES ($placeholders)";
        
        return $this->executeQuery($this->query, PDO::FETCH_OBJ, $data);
    }

    // Memperbarui data
    public function update($data)
    {
        $setClause = implode(', ', array_map(fn($col) => "$col = :$col", array_keys($data)));
        $this->query = "UPDATE $this->table SET $setClause" . ($this->query ? " WHERE 1=1 " . $this->query : '');
        
        return $this->executeQuery($this->query, PDO::FETCH_OBJ, $data);
    }

    // Menghapus data
    public function delete()
    {
        $this->query = "DELETE FROM $this->table " . $this->query;
        return $this->executeQuery($this->query);
    }

    // Query mentah
    public static function raw($query, $params = [], $fetchStyle = PDO::FETCH_OBJ)
    {
        try {
            $start = microtime(true);
            $stmt = self::getConnection()->prepare($query);
            $stmt->execute($params);
            $duration = round((microtime(true) - $start) * 1000, 2);

            QueryLogger::add($query, $params, $duration, 'DB::table');
            return $stmt->fetchAll($fetchStyle);
        } catch (PDOException $e) {
            // Tangani error dengan lebih baik
            error_log("Database Query Error: " . $e->getMessage());
            return []; // Mengembalikan array kosong jika error
        }
    }

    // Eksekusi query umum
    protected function executeQuery($query, $fetchStyle = PDO::FETCH_OBJ, $params = [])
    {
        try {
            $stmt = self::getConnection()->prepare($query);
            $params = empty($params) ? $this->params : $params;
            $stmt->execute($params);
            return $stmt->fetchAll($fetchStyle);
        } catch (PDOException $e) {
            // Tangani error dan log jika perlu
            error_log("Database Query Error: " . $e->getMessage());
            return []; // Mengembalikan array kosong jika terjadi error
        }
    }

    // Menampilkan tabel
    public static function showTables()
    {
        $dbType = self::getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $dbType === 'mysql' ? "SHOW TABLES" : "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'";
        return self::fetchAll($sql);
    }

    // Membuat tabel
    public static function createTable($name, $columns)
    {
        $sql = "CREATE TABLE $name (" . implode(", ", $columns) . ")";
        return self::query($sql);
    }

    // Menghapus tabel
    public static function dropTable($name)
    {
        return self::query("DROP TABLE IF EXISTS $name");
    }

    // Query umum
    public static function query($sql, $params = [])
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch semua data
    public static function fetchAll($sql, $params = [], $fetchStyle = PDO::FETCH_OBJ)
    {
        return self::query($sql, $params)->fetchAll($fetchStyle);
    }

    // Fetch satu data
    public static function fetch($sql, $params = [], $fetchStyle = PDO::FETCH_OBJ)
    {
        return self::query($sql, $params)->fetch($fetchStyle);
    }

    // Menghitung hasil query
    public static function rowCount($sql, $params = [])
    {
        return self::query($sql, $params)->rowCount();
    }

    // Menangani error
    public static function renderError($exception)
    {
        static $errorDisplayed = false;

        if (!$errorDisplayed) {
            $errorDisplayed = true;
            if (!headers_sent()) {
                http_response_code(500);
            }
            $exceptionData = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
            extract($exceptionData);
            include __DIR__ . '/../../app/handle/errors/page_error.php';
        }
        exit();
    }
}
