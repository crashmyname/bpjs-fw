<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Core\Request;
use PDO;
use PDOException;
use Bpjs\Framework\Helpers\Database;
use Bpjs\Framework\Helpers\DB;

class BaseModel
{
    protected $table;
    protected static $dynamicTable = null;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $guarded = [];
    protected $attributes = [];
    protected $connection;
    protected $selectColumns = ['*'];
    protected $whereConditions = [];
    protected $whereParams = [];
    protected $joins = [];
    protected $groupBy;
    protected $orderBy = [];
    protected $distinct = '';
    protected $limit;
    protected $offset;
    protected $orWhereConditions = [];
    protected $with = [];


    public function __construct($attributes = [])
    {
        if (is_object($attributes)) {
            $attributes = (array) $attributes;
        }
        $this->attributes = $this->filterAttributes($attributes);
        $this->connect();
    }

    private function connect()
    {
        try {
            $this->connection = Database::connection();
            if ($this->connection === null) {
                throw new \Exception('Database connection failed.');
            }
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            die();
        }
    }

    private function filterAttributes($attributes)
    {
        // Jika fillable diisi, hanya ambil atribut yang ada di fillable
        if (!empty($this->fillable)) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }

        // Jika guarded diisi, buang atribut yang ada di guarded
        if (!empty($this->guarded)) {
            return array_diff_key($attributes, array_flip($this->guarded));
        }

        return $attributes;
    }

    public function fill(array $attributes)
    {
        $this->attributes = array_merge($this->attributes, $this->filterAttributes($attributes));
    }

    public function beginTransaction()
    {
        $this->connection->beginTransaction();
    }

    // Commit Transaksi
    public function commit()
    {
        $this->connection->commit();
    }

    // Rollback Transaksi
    public function rollback()
    {
        $this->connection->rollback();
    }

    public static function query()
    {
        return new static();
    }

    public function select(...$columns)
    {
        $this->selectColumns = empty($columns) ? ['*'] : $columns;
        return $this;
    }

    public function selectRaw($rawExpression)
    {
        $this->selectColumns[] = $rawExpression; // Menambahkan SQL mentah ke daftar kolom
        return $this;
    }


    public function distinct($value = true)
    {
        $this->distinct = $value ? 'DISTINCT' : '';
        return $this;
    }

    public function where($column, $operator = '=', $value = null)
    {
        if (strtoupper($operator) === 'LIKE') {
            // LIKE operator handling (handles wildcards %)
            $paramName = str_replace('.', '_', $column) . '_' . count($this->whereParams);
            $this->whereConditions[] = "{$column} LIKE :{$paramName}";
            $this->whereParams[":{$paramName}"] = $value; // e.g., "%keyword%"
        } elseif ($value === null || $value == '') {
            // Handle cases where the value is null, we use IS or IS NOT
            if ($operator === '=') {
                $operator = 'IS';
            } elseif ($operator === '!=') {
                $operator = 'IS NOT';
            }
            $this->whereConditions[] = "{$column} {$operator} NULL";
        } else {
            // Default condition
            $paramName = str_replace('.', '_', $column) . '_' . count($this->whereParams);
            $this->whereConditions[] = "{$column} {$operator} :{$paramName}";
            $this->whereParams[":{$paramName}"] = $value;
        }

        return $this;
    }

    public function whereIn($column, array $values)
    {
        if (empty($values)) {
            if (env('APP_DEBUG') == 'false') {
                if (Request::isAjax() || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                    header('Content-Type: application/json', true, 500);
                    echo json_encode([
                        'statusCode' => 500,
                        'error'      => 'Internal Server Error'
                    ]);
                } else {
                    return View::error(500);
                }
                exit;
            }
            throw new \InvalidArgumentException('The values array cannot be empty for whereIn condition.');
        }

        // Generate unique placeholders for each value
        $placeholders = [];
        foreach ($values as $index => $value) {
            $paramName = str_replace('.', '_', $column) . "_in_{$index}";
            $placeholders[] = ":{$paramName}";
            $this->whereParams[":{$paramName}"] = $value;
        }

        // Build the WHERE IN clause
        $placeholdersString = implode(', ', $placeholders);
        $this->whereConditions[] = "{$column} IN ({$placeholdersString})";

        return $this;
    }

    public function orWhere($column, $operator = '=', $value = null)
    {
        if ($value === null || $value == '') {
            if ($operator === '=') {
                $operator = 'IS';
            } elseif ($operator === '!=') {
                $operator = 'IS NOT';
            }
            $this->orWhereConditions[] = "{$column} {$operator} NULL";
        } else {
            $paramName = str_replace('.', '_', $column) . '_' . count($this->whereParams);
            $this->orWhereConditions[] = "{$column} {$operator} :{$paramName}";
            $this->whereParams[":{$paramName}"] = $value;
        }

        return $this;
    }

    public function whereDate($column, $date)
    {
        return $this->where($column, '=', $date);
    }

    public function whereMonth($column, $month)
    {
        // return $this->where("MONTH({$column})", '=', $month);
        $this->whereConditions[] = "MONTH({$column}) = :month";
        $this->whereParams[':month'] = $month;
        return $this;
    }

    public function whereYear($column, $year)
    {
        // return $this->where("YEAR({$column})", '=', $year);
        $this->whereConditions[] = "YEAR({$column}) = :year";
        $this->whereParams[':year'] = $year;
        return $this;
    }

    public function whereBetween($column, $start, $end)
    {
        // Generate unique parameter names to prevent conflicts
        $paramStart = str_replace('.', '_', $column) . "_start_" . count($this->whereParams);
        $paramEnd = str_replace('.', '_', $column) . "_end_" . count($this->whereParams);

        // Tambahkan kondisi WHERE ke array
        $this->whereConditions[] = "{$column} BETWEEN :{$paramStart} AND :{$paramEnd}";

        // Tambahkan parameter ke array parameter
        $this->whereParams[":{$paramStart}"] = $start;
        $this->whereParams[":{$paramEnd}"] = $end;

        return $this;
    }

    public function innerJoin($table, $first, $operator, $second)
    {
        return $this->join($table, $first, $operator, $second, 'INNER');
    }

    public function leftJoin($table, $first, $operator, $second)
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin($table, $first, $operator, $second)
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function outerJoin($table, $first, $operator, $second)
    {
        return $this->join($table, $first, $operator, $second, 'OUTER');
    }

    private function join($table, $first, $operator, $second, $type)
    {
        $validJoinTypes = ['INNER', 'LEFT', 'RIGHT', 'OUTER'];

        if (in_array(strtoupper($type), $validJoinTypes)) {
            $this->joins[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        } else {
            throw new \InvalidArgumentException("Invalid join type: {$type}");
        }

        return $this;
    }

    public function groupBy($columns)
    {
        $this->groupBy = is_array($columns) ? implode(', ', $columns) : $columns;
        return $this;
    }

    public function orderBy($column, $direction = 'ASC')
    {
        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    public function get($fetchStyle = PDO::FETCH_OBJ, $asModel = false)
    {
        try {
            $table = self::$dynamicTable ?? $this->table;

            $sql = "SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$table}";

            if (!empty($this->joins)) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            if (!empty($this->whereConditions) || !empty($this->orWhereConditions)) {
                $sql .= ' WHERE ';
                $conditions = [];

                if (!empty($this->whereConditions)) {
                    $conditions[] = '(' . implode(' AND ', $this->whereConditions) . ')';
                }

                if (!empty($this->orWhereConditions)) {
                    $conditions[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
                }

                $sql .= implode(' AND ', $conditions);
            }

            if (!empty($this->groupBy)) {
                $sql .= ' GROUP BY ' . $this->groupBy;
            }

            if (!empty($this->orderBy)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
            }

            if ($this->limit !== null) {
                $sql .= ' LIMIT ' . (int) $this->limit;
            }

            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . (int) $this->offset;
            }

            $stmt = $this->connection->prepare($sql);

            foreach ($this->whereParams as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            if (!$asModel && empty($this->with)) {
                return $stmt->fetchAll($fetchStyle);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $models = [];

            foreach ($rows as $row) {
                $model = new static();
                $model->fill($row);

                foreach ($this->with as $relation) {
                    if (method_exists($model, $relation)) {
                        $related = $model->$relation();
                        $model->attributes[$relation] = $related;
                    }
                }

                $models[] = $model;
            }

            return $models;

        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return [];
        }
    }

    public function getWithRelations($fetchStyle = PDO::FETCH_OBJ)
    {
        return $this->get($fetchStyle, true);
    }

    public function paginate($perPage = 10, $fetchStyle = PDO::FETCH_OBJ)
    {
        try {
            $table = self::$dynamicTable ?? $this->table;

            // Ambil current page dari query string (?page=...), default 1
            $currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
            if ($currentPage < 1) {
                $currentPage = 1;
            }

            // Build query dasar (tanpa limit & offset)
            $sql = "SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$table}";

            if (!empty($this->joins)) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            $whereClause = '';
            if (!empty($this->whereConditions) || !empty($this->orWhereConditions)) {
                $whereClause .= ' WHERE ';
                $conditions = [];

                if (!empty($this->whereConditions)) {
                    $conditions[] = '(' . implode(' AND ', $this->whereConditions) . ')';
                }

                if (!empty($this->orWhereConditions)) {
                    $conditions[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
                }

                $whereClause .= implode(' AND ', $conditions);
                $sql .= $whereClause;
            }

            if (!empty($this->groupBy)) {
                $sql .= ' GROUP BY ' . $this->groupBy;
            }

            if (!empty($this->orderBy)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
            }

            // ==== HITUNG TOTAL DATA ====
            $countSql = "SELECT COUNT(*) as total FROM (
                SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$table}";

            if (!empty($this->joins)) {
                $countSql .= ' ' . implode(' ', $this->joins);
            }

            if (!empty($whereClause)) {
                $countSql .= " " . $whereClause;
            }

            $countSql .= ") as subquery";
            $stmtCount = $this->connection->prepare($countSql);
            foreach ($this->whereParams as $key => $value) {
                $stmtCount->bindValue($key, $value);
            }
            $stmtCount->execute();
            $total = (int) $stmtCount->fetchColumn();

            // ==== HITUNG PAGINATION ====
            $lastPage = max(1, (int) ceil($total / $perPage));
            $currentPage = max(1, min($currentPage, $lastPage));
            $offset = ($currentPage - 1) * $perPage;

            // ==== QUERY DATA PAGINATED ====
            $sql .= " LIMIT {$perPage} OFFSET {$offset}";
            $stmt = $this->connection->prepare($sql);
            foreach ($this->whereParams as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $data = $stmt->fetchAll($fetchStyle);

            return [
                "data" => $data,
                "pagination" => [
                    "total"        => $total,
                    "per_page"     => $perPage,
                    "current_page" => $currentPage,
                    "last_page"    => $lastPage,
                    "from"         => $offset + 1,
                    "to"           => $offset + count($data),
                ]
            ];
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return [
                "data" => [],
                "pagination" => [
                    "total" => 0,
                    "per_page" => $perPage,
                    "current_page" => 1,
                    "last_page" => 1,
                    "from" => null,
                    "to" => null,
                ]
            ];
        }
    }

    public function toSql()
    {
        try {
            $table = self::$dynamicTable ?? $this->table;
            $sql = "SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$table}";

            if (!empty($this->joins)) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            if (!empty($this->whereConditions)) {
                $sql .= ' WHERE ' . implode(' AND ', $this->whereConditions);
            }

            if (!empty($this->groupBy)) {
                $sql .= ' GROUP BY ' . $this->groupBy;
            }

            if (!empty($this->orderBy)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
            }

            if ($this->limit !== null) {
                $sql .= ' LIMIT ' . (int) $this->limit;
            }

            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . (int) $this->offset;
            }

            return $sql;
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
        }
    }

    public function getRawSQL()
    {
        try {
            $sql = $this->toSql();
            $bindings = $this->bindings ?? [];

            foreach ($bindings as $binding) {
                // Escape nilai string dengan kutip satu
                if (is_string($binding)) {
                    $binding = "'" . addslashes($binding) . "'";
                } elseif (is_null($binding)) {
                    $binding = "NULL";
                } elseif (is_bool($binding)) {
                    $binding = $binding ? '1' : '0';
                }

                // Ganti ? pertama yang ditemukan
                $sql = preg_replace('/\?/', $binding, $sql, 1);
            }

            return $sql;
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
        }
    }

    public function first()
    {
        $this->limit(1);
        $results = $this->get(PDO::FETCH_ASSOC, true); // ambil sebagai model
        if (!empty($results)) {
            return $results[0];
        }
        return null;
    }


    public static function setCustomTable($parameter)
    {
        $instance = new static();
        self::$dynamicTable = $parameter;
        return $instance;
    }

    public static function setTable($tablecustom)
    {
        $instance = new static();
        $tablePrefix = $instance->table;
        self::$dynamicTable = $tablePrefix . $tablecustom;
        return new static();
    }

    public static function all($fetchStyle = PDO::FETCH_OBJ)
    {
        try {
            $instance = new static();
            $instance->table = self::$dynamicTable ?? ($instance->table ?? 'default_table');
            $sql = "SELECT * FROM {$instance->table}";
            $stmt = $instance->connection->prepare($sql);
            $stmt->execute();
            $data = $stmt->fetchAll($fetchStyle);
            return $data;
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return [];
        }
    }

    public function count()
    {
        try {
            $table = self::$dynamicTable ?? $this->table;
            $sql = "SELECT COUNT(*) as count FROM {$table}";

            if (!empty($this->joins)) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            if (!empty($this->whereConditions) || !empty($this->orWhereConditions)) {
                $sql .= ' WHERE ';
                $conditions = [];

                // Memasukkan where conditions
                if (!empty($this->whereConditions)) {
                    $conditions[] = '(' . implode(' AND ', $this->whereConditions) . ')';
                }

                // Memasukkan orWhere conditions
                if (!empty($this->orWhereConditions)) {
                    $conditions[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
                }

                // Gabungkan semua kondisi
                $sql .= implode(' AND ', $conditions);
            }

            $stmt = $this->connection->prepare($sql);

            foreach ($this->whereParams as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['count'];
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return 0;
        }
    }

    public static function create($attributes)
    {
        try {
            $instance = new static($attributes);
            if (self::$dynamicTable) {
                $instance->table = self::$dynamicTable; // Gunakan tabel yang telah diset secara statis
            }
            // Jika properti $table kosong, set ke default tabel model
            if (!$instance->table) {
                $instance->table = isset($instance->table) ? $instance->table : 'default_table'; // Default based on the model
            }
            $instance->save();
            return $instance;
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return null;
        }
    }

    public function save()
    {
        try {
            $this->connection = DB::getConnection();
            $table = self::$dynamicTable ?? $this->table;

            // pastikan ada data
            if (empty($this->attributes)) {
                throw new \Exception("No attributes set for save()");
            }

            if (!empty($this->attributes[$this->primaryKey])) {
                // update
                $this->updates();
            } else {
                // insert
                $columns = implode(',', array_keys($this->attributes));
                $placeholders = ':' . implode(', :', array_keys($this->attributes));

                $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
                $stmt = $this->connection->prepare($sql);

                foreach ($this->attributes as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }

                $stmt->execute();

                // simpan id terakhir kalau ada primaryKey
                if ($this->primaryKey) {
                    $this->attributes[$this->primaryKey] = $this->connection->lastInsertId();
                }
            }

            return true; // biar bisa dicek berhasil/tidak
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
        }
    }

    public function updates()
    {
        try {
            if (empty($this->table)) {
                $this->table = 'default_table'; // Fallback jika table tidak diatur
            }
            $setClause = [];
            foreach ($this->attributes as $key => $value) {
                $setClause[] = "{$key} = :{$key}";
            }
            $setClause = implode(', ', $setClause);

            $sql = "UPDATE {$this->table} SET {$setClause} WHERE {$this->primaryKey} = :{$this->primaryKey}";

            $stmt = $this->connection->prepare($sql);

            foreach ($this->attributes as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }

            $stmt->execute();
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
        }
    }
    public function update($data)
    {
        // Buat klausa SET untuk SQL query
        $this->connection = DB::getConnection();
        $setClause = [];
        foreach ($data as $key => $value) {
            $setClause[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $setClause);

        $table = self::$dynamicTable ?? $this->table;
        // Siapkan SQL query untuk update
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$this->primaryKey} = :{$this->primaryKey}";

        // Siapkan statement
        $stmt = $this->connection->prepare($sql);

        // Bind data baru yang akan diupdate
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        // Bind primary key untuk WHERE clause
        $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);

        // Eksekusi query
        $stmt->execute();
    }
    public function delete()
    {
        try {
            $table = self::$dynamicTable ?? $this->table;
            $sql = "DELETE FROM {$table} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            $stmt->execute();
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
        }
    }
    public function lockForUpdate()
    {
        if (empty($this->table)) {
            $this->table = 'default_table'; // Fallback jika table tidak diatur
        }
        $sql = "SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$this->table}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->whereConditions) || !empty($this->orWhereConditions)) {
            $sql .= ' WHERE ';
            $conditions = [];

            if (!empty($this->whereConditions)) {
                $conditions[] = '(' . implode(' AND ', $this->whereConditions) . ')';
            }

            if (!empty($this->orWhereConditions)) {
                $conditions[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
            }

            $sql .= implode(' AND ', $conditions);
        }

        $sql .= ' FOR UPDATE';

        $stmt = $this->connection->prepare($sql);

        foreach ($this->whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    public function sharedLock()
    {
        if (empty($this->table)) {
            $this->table = 'default_table'; // Fallback jika table tidak diatur
        }
        $sql = "SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$this->table}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->whereConditions) || !empty($this->orWhereConditions)) {
            $sql .= ' WHERE ';
            $conditions = [];

            if (!empty($this->whereConditions)) {
                $conditions[] = '(' . implode(' AND ', $this->whereConditions) . ')';
            }

            if (!empty($this->orWhereConditions)) {
                $conditions[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
            }

            $sql .= implode(' AND ', $conditions);
        }

        $sql .= ' LOCK IN SHARE MODE';

        $stmt = $this->connection->prepare($sql);

        foreach ($this->whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function find($id, $fetchStyle = PDO::FETCH_OBJ)
    {
        $instance = new static();
        $table = self::$dynamicTable ?? $instance->table;
        $sql = "SELECT * FROM {$table} WHERE {$instance->primaryKey} = :id";
        $stmt = $instance->connection->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetch($fetchStyle);

        if ($data) {
            return new static((array) $data);
        }

        return null;
    }

    public function hasOne($relatedModel, $foreignKey, $localKey = 'id')
    {
        $relatedInstance = new $relatedModel();
        if (isset($this->attributes[$localKey])) {
            $localValue = $this->attributes[$localKey];
        } else {
            error_log("Atribut '{$localKey}' tidak ditemukan.");
            return null; // Atau tangani kesalahan sesuai kebutuhan
        }
    
        // Memastikan bahwa nilai yang akan digunakan untuk query adalah nilai dari atribut
        $relatedInstance->where($foreignKey, '=', $localValue);
        return $relatedInstance->first();
    }

    // One-to-Many relationship
    public function hasMany($relatedModel, $foreignKey, $localKey = 'id')
    {
        if (!isset($this->attributes[$localKey])) {
            return []; // tidak ada localKey di atribut
        }

        $relatedInstance = new $relatedModel();
        return $relatedInstance
            ->where($foreignKey, '=', $this->attributes[$localKey])
            ->get(PDO::FETCH_ASSOC, true); // ambil sebagai model
    }

    // Belongs-to relationship
    public function belongsTo($relatedModel, $foreignKey, $ownerKey = 'id')
    {
        $relatedInstance = new $relatedModel();
        return $relatedInstance->where($ownerKey, '=', $this->attributes[$foreignKey])->first();
    }

    // Many-to-Many relationship
    public function belongsToMany($relatedModel, $pivotTable, $foreignKey, $relatedKey, $localKey = 'id', $relatedLocalKey = 'id')
    {
        $relatedInstance = new $relatedModel();
        $query = "SELECT {$relatedInstance->table}.* FROM {$relatedInstance->table} 
                  INNER JOIN {$pivotTable} ON {$relatedInstance->table}.{$relatedLocalKey} = {$pivotTable}.{$relatedKey}
                  WHERE {$pivotTable}.{$foreignKey} = :local_key";

        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':local_key', $this->attributes[$localKey]);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function exists($conditions)
    {
        $query = static::query();

        // Tambahkan semua kondisi where
        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }

        // Periksa apakah ada data dengan membatasi hasil ke satu dan memeriksa jika ada hasil
        $result = $query->first();

        return $result !== null;
    }
    
    public function with($relation)
    {
        if (!method_exists($this, $relation)) {
            throw new \Exception("Relation {$relation} not defined in " . static::class);
        }

        $this->$relation = $this->$relation(); // jalankan method relasi dan simpan hasilnya
        return $this;
    }


    public function load($relations)
    {
        $this->with = is_array($relations) ? $relations : func_get_args();
        return $this;
    }


    public function toArray()
    {
        $data = $this->attributes;

        // Ambil juga relasi yang sudah dimuat (property tambahan selain attributes)
        foreach (get_object_vars($this) as $key => $value) {
            if (!in_array($key, [
                'table','primaryKey','fillable','guarded','attributes',
                'connection','selectColumns','whereConditions','whereParams',
                'joins','groupBy','orderBy','distinct','limit','offset','orWhereConditions'
            ])) {
                if ($value instanceof BaseModel) {
                    $data[$key] = $value->toArray();
                } elseif (is_array($value)) {
                    $data[$key] = array_map(function($item) {
                        return $item instanceof BaseModel ? $item->toArray() : $item;
                    }, $value);
                } else {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }


    public function __get($key)
    {
        // Kalau ada di attributes
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        // Kalau ada relasi yang sudah dimuat
        if (method_exists($this, $key)) {
            if (!isset($this->attributes[$key])) {
                $this->attributes[$key] = $this->$key();
            }
            return $this->attributes[$key];
        }

        return null;
    }

    public function __set($key, $value)
    {
        $this->attributes[$key] = $value;
    }

}
