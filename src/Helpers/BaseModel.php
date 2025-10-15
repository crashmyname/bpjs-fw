<?php

namespace Helpers;

use Bpjs\Core\Request;
use PDO;
use PDOException;
use Helpers\Database;
use Helpers\DB;

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
    protected array $relations = [];

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
                throw new \Exception('Database connection failed.');
            }
        } catch (\Exception $e) {
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
            ErrorHandler::handleException($e);
            die();
        }
    }

    private function filterAttributes($attributes)
    {
        if (!empty($this->fillable)) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }

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

    public function commit()
    {
        $this->connection->commit();
    }

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
        $this->selectColumns[] = $rawExpression;
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
            $paramName = str_replace('.', '_', $column) . '_' . count($this->whereParams);
            $this->whereConditions[] = "{$column} LIKE :{$paramName}";
            $this->whereParams[":{$paramName}"] = $value;
        } elseif ($value === null || $value == '') {
            if ($operator === '=') {
                $operator = 'IS';
            } elseif ($operator === '!=') {
                $operator = 'IS NOT';
            }
            $this->whereConditions[] = "{$column} {$operator} NULL";
        } else {
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

        $placeholders = [];
        foreach ($values as $index => $value) {
            $paramName = str_replace('.', '_', $column) . "_in_{$index}";
            $placeholders[] = ":{$paramName}";
            $this->whereParams[":{$paramName}"] = $value;
        }

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
        $this->whereConditions[] = "MONTH({$column}) = :month";
        $this->whereParams[':month'] = $month;
        return $this;
    }

    public function whereYear($column, $year)
    {
        $this->whereConditions[] = "YEAR({$column}) = :year";
        $this->whereParams[':year'] = $year;
        return $this;
    }

    public function whereBetween($column, $start, $end)
    {
        $paramStart = str_replace('.', '_', $column) . "_start_" . count($this->whereParams);
        $paramEnd = str_replace('.', '_', $column) . "_end_" . count($this->whereParams);

        $this->whereConditions[] = "{$column} BETWEEN :{$paramStart} AND :{$paramEnd}";

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

                $models[] = $model;
            }
            if (!empty($this->with)) {
                foreach ($models as $model) {
                    $model->load($this->with);
                }
            }
            return $models;

        } catch (\Exception $e) {
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
            ErrorHandler::handleException($e);
            return [];
        }
    }

    public function first()
    {
        $this->limit(1);
        $results = $this->get(PDO::FETCH_ASSOC, true);

        if (!empty($results)) {
            return $results[0];
        }

        return null;
    }

    public function getWithRelations($fetchStyle = PDO::FETCH_OBJ)
    {
        return $this->get($fetchStyle, true);
    }

    public function paginate($perPage = 10, $fetchStyle = PDO::FETCH_OBJ)
    {
        try {
            $table = self::$dynamicTable ?? $this->table;

            $currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
            if ($currentPage < 1) $currentPage = 1;

            // ==== BUILD BASE QUERY ====
            $sql = "SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$table}";
            if (!empty($this->joins)) $sql .= ' ' . implode(' ', $this->joins);

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

            if (!empty($this->groupBy)) $sql .= ' GROUP BY ' . $this->groupBy;
            if (!empty($this->orderBy)) $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);

            $countSql = "SELECT COUNT(*) as total FROM (
                SELECT {$this->distinct} " . implode(', ', $this->selectColumns) . " FROM {$table}";
            if (!empty($this->joins)) $countSql .= ' ' . implode(' ', $this->joins);
            if (!empty($whereClause)) $countSql .= " " . $whereClause;
            $countSql .= ") as subquery";

            $stmtCount = $this->connection->prepare($countSql);
            foreach ($this->whereParams as $key => $value) {
                $stmtCount->bindValue($key, $value);
            }
            $stmtCount->execute();
            $total = (int)$stmtCount->fetchColumn();

            $lastPage = max(1, (int)ceil($total / $perPage));
            $currentPage = max(1, min($currentPage, $lastPage));
            $offset = ($currentPage - 1) * $perPage;

            $sql .= " LIMIT {$perPage} OFFSET {$offset}";
            $stmt = $this->connection->prepare($sql);
            foreach ($this->whereParams as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll($fetchStyle);

            $data = [];
            foreach ($rows as $row) {
                $model = new static();
                $model->fill((array)$row);

                if (!empty($this->with)) {
                    $model->load($this->with);
                }

                if (method_exists($model, 'toCleanArray')) {
                    $data[] = $model->toCleanArray();
                } else {
                    $data[] = (array)$row;
                }
            }

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
            ErrorHandler::handleException($e);
        }
    }

    public function getRawSQL()
    {
        try {
            $sql = $this->toSql();
            $bindings = $this->bindings ?? [];

            foreach ($bindings as $binding) {
                if (is_string($binding)) {
                    $binding = "'" . addslashes($binding) . "'";
                } elseif (is_null($binding)) {
                    $binding = "NULL";
                } elseif (is_bool($binding)) {
                    $binding = $binding ? '1' : '0';
                }

                $sql = preg_replace('/\?/', $binding, $sql, 1);
            }

            return $sql;
        } catch (\Exception $e) {
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
            ErrorHandler::handleException($e);
        }
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

                if (!empty($this->whereConditions)) {
                    $conditions[] = '(' . implode(' AND ', $this->whereConditions) . ')';
                }

                if (!empty($this->orWhereConditions)) {
                    $conditions[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
                }

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
            ErrorHandler::handleException($e);
            return 0;
        }
    }

    public static function create($attributes)
    {
        try {
            $instance = new static($attributes);
            if (self::$dynamicTable) {
                $instance->table = self::$dynamicTable;
            }
            if (!$instance->table) {
                $instance->table = isset($instance->table) ? $instance->table : 'default_table';
            }
            $instance->save();
            return $instance;
        } catch (\Exception $e) {
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
            ErrorHandler::handleException($e);
            return null;
        }
    }

    public function save()
    {
        try{
            $this->connection = DB::getConnection();
            $table = self::$dynamicTable ?? $this->table;
    
            if (!empty($this->attributes[$this->primaryKey])) {
                $this->updates();
            } else {
                $columns = implode(',', array_keys($this->attributes));
                $placeholders = ':' . implode(', :', array_keys($this->attributes));
                $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
                $stmt = $this->connection->prepare($sql);
    
                foreach ($this->attributes as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
    
                $stmt->execute();
                if ($this->primaryKey) {
                    $this->attributes[$this->primaryKey] = $this->connection->lastInsertId();
                }
            }
    
            return true;
        } catch (\Exception $e){
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
            ErrorHandler::handleException($e);
        }
    }

    public function updates($data = null)
    {
        try {
            $this->connection = DB::getConnection();
            $table = self::$dynamicTable ?? $this->table;

            $dataToUpdate = $data ?? array_filter($this->attributes, function($key) {
                return !in_array($key, $this->with ?? []);
            }, ARRAY_FILTER_USE_KEY);

            $setClause = [];
            foreach ($dataToUpdate as $key => $value) {
                $setClause[] = "{$key} = :{$key}";
            }
            $setClause = implode(', ', $setClause);

            $sql = "UPDATE {$table} SET {$setClause} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt = $this->connection->prepare($sql);

            foreach ($dataToUpdate as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }

            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            $stmt->execute();
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
        }
    }

    public function saveWithRelations(array $relations = [])
    {
        $this->save();

        $relationsToSave = !empty($relations)
            ? $relations
            : array_keys($this->relations);

        foreach ($relationsToSave as $relationName) {
            if (!isset($this->relations[$relationName])) continue;

            $relationInfo = $this->$relationName();
            $relationData = $this->relations[$relationName];
            $relatedModel = $relationInfo['model'];

            switch ($relationInfo['type']) {
                case 'hasOne':
                    $relationData->{$relationInfo['foreignKey']} =
                        $this->attributes[$relationInfo['localKey']];
                    $relationData->save();
                    break;

                case 'hasMany':
                    foreach ($relationData as $item) {
                        $item->{$relationInfo['foreignKey']} =
                            $this->attributes[$relationInfo['localKey']];
                        $item->save();
                    }
                    break;

                case 'belongsTo':
                    $relationData->save();
                    $this->attributes[$relationInfo['foreignKey']] =
                        $relationData->{$relationInfo['ownerKey']};
                    $this->save();
                    break;
            }
        }

        return $this;
    }

    public function update($data)
    {
        $this->connection = DB::getConnection();
        $setClause = [];
        foreach ($data as $key => $value) {
            $setClause[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $setClause);

        $table = self::$dynamicTable ?? $this->table;
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$this->primaryKey} = :{$this->primaryKey}";

        $stmt = $this->connection->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);

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
            ErrorHandler::handleException($e);
        }
    }

    public function lockForUpdate()
    {
        if (empty($this->table)) {
            $this->table = 'default_table';
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
            $this->table = 'default_table';
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
        return [
            'type' => 'hasOne',
            'model' => new $relatedModel(),
            'foreignKey' => $foreignKey,
            'localKey' => $localKey,
        ];
    }

    public function hasMany($relatedModel, $foreignKey, $localKey = 'id')
    {
        return [
            'type' => 'hasMany',
            'model' => new $relatedModel(),
            'foreignKey' => $foreignKey,
            'localKey' => $localKey,
        ];
    }

    public function belongsTo($relatedModel, $foreignKey, $ownerKey = 'id')
    {
        return [
            'type' => 'belongsTo',
            'model' => new $relatedModel(),
            'foreignKey' => $foreignKey,
            'ownerKey' => $ownerKey,
        ];
    }

    public function belongsToMany($relatedModel, $pivotTable, $foreignKey, $relatedKey, $localKey = 'id', $relatedLocalKey = 'id')
    {
        return [
            'type' => 'belongsToMany',
            'model' => new $relatedModel(),
            'pivot' => $pivotTable,
            'foreignKey' => $foreignKey,
            'relatedKey' => $relatedKey,
            'localKey' => $localKey,
            'relatedLocalKey' => $relatedLocalKey,
        ];
    }

    public static function exists($conditions)
    {
        $query = static::query();

        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }

        $result = $query->first();

        return $result !== null;
    }
    
    public function with($relation)
    {
        if (!method_exists($this, $relation)) {
            throw new \Exception("Relation {$relation} not defined in " . static::class);
        }

        $this->$relation = $this->$relation();
        return $this;
    }


    public function load(array $relations)
    {
        foreach ($relations as $relation) {
            if (!method_exists($this, $relation)) continue;

            $relationInfo = $this->$relation();
            if (!is_array($relationInfo)) continue;

            $relatedModel = $relationInfo['model'];

            switch ($relationInfo['type']) {
                case 'belongsTo':
                    $foreignKey = $relationInfo['foreignKey'];
                    $ownerKey   = $relationInfo['ownerKey'];

                    if (isset($this->attributes[$foreignKey])) {
                        $this->relations[$relation] = $relatedModel::query()
                            ->where($ownerKey, '=', $this->attributes[$foreignKey])
                            ->first();
                    }
                    break;

                case 'hasOne':
                    $foreignKey = $relationInfo['foreignKey'];
                    $localKey   = $relationInfo['localKey'];

                    if (isset($this->attributes[$localKey])) {
                        $this->relations[$relation] = $relatedModel::query()
                            ->where($foreignKey, '=', $this->attributes[$localKey])
                            ->first();
                    }
                    break;

                case 'hasMany':
                    $foreignKey = $relationInfo['foreignKey'];
                    $localKey   = $relationInfo['localKey'];

                    if (isset($this->attributes[$localKey])) {
                        $this->relations[$relation] = $relatedModel::query()
                            ->where($foreignKey, '=', $this->attributes[$localKey])
                            ->get();
                    }
                    break;

                case 'belongsToMany':
                    $pivotTable       = $relationInfo['pivot'];
                    $foreignKey       = $relationInfo['foreignKey'];
                    $relatedKey       = $relationInfo['relatedKey'];
                    $localKey         = $relationInfo['localKey'];
                    $relatedLocalKey  = $relationInfo['relatedLocalKey'];

                    if (!isset($this->attributes[$localKey])) break;

                    $conn = DB::getConnection();
                    $sql = "
                        SELECT r.* 
                        FROM {$relatedModel->table} AS r
                        INNER JOIN {$pivotTable} AS p
                            ON p.{$relatedKey} = r.{$relatedLocalKey}
                        WHERE p.{$foreignKey} = :localKey
                    ";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindValue(':localKey', $this->attributes[$localKey]);
                    $stmt->execute();

                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $relatedData = [];
                    foreach ($rows as $row) {
                        $instance = new $relatedModel();
                        $instance->fill($row);
                        $relatedData[] = $instance;
                    }

                    $this->relations[$relation] = $relatedData;
                    break;
            }
        }

        $this->with = $relations;
        return $this;
    }

    public function toCleanArray()
    {
        $data = [];

        foreach ($this->attributes as $key => $value) {
            $data[$key] = $value;
        }

        foreach ($this->relations as $relation => $relValue) {
            if ($relValue instanceof self) {
                $data[$relation] = $relValue->toCleanArray();
            } elseif (is_array($relValue)) {
                $data[$relation] = array_map(function ($item) {
                    return $item instanceof self ? $item->toCleanArray() : $item;
                }, $relValue);
            } else {
                $data[$relation] = $relValue;
            }
        }

        if (property_exists($this, 'hidden') && !empty($this->hidden)) {
            foreach ($this->hidden as $field) unset($data[$field]);
        }

        return $data;
    }


    public static function toCleanArrayCollection($models)
    {
        if (!is_array($models)) return [];
        return array_map(fn($item) => $item instanceof self ? $item->toCleanArray() : $item, $models);
    }

    public function toArray()
    {
        $data = $this->attributes;

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
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }
        if (method_exists($this, $key)) {
            $this->load([$key]);
            return $this->relations[$key] ?? null;
        }

        return null;
    }

    public function __set($key, $value)
    {
        if ($value instanceof BaseModel || (is_array($value) && isset($value[0]) && $value[0] instanceof BaseModel)) {
            $this->relations[$key] = $value;
        } else {
            $this->attributes[$key] = $value;
        }
    }


}
