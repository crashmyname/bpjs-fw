<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Core\Request;
use Bpjs\Framework\Database\Grammar\GrammarFactory;
use Bpjs\Framework\Database\Grammar\GrammarInterface;
use PDO;

/**
 * BaseModel — Multi-Engine ORM Base Class
 *
 * Engine yang didukung:
 *   MySQL / MariaDB  → backtick quote, LIMIT/OFFSET, LOCK IN SHARE MODE
 *   PostgreSQL       → double-quote, LIMIT/OFFSET, FOR SHARE, RETURNING
 *   SQLite           → double-quote, LIMIT/OFFSET, lock diabaikan
 *   SQL Server       → bracket quote, TOP / OFFSET FETCH NEXT, OUTPUT INSERTED
 *
 * Untuk menambah engine baru cukup:
 *   1. Buat class Grammar baru yang implements GrammarInterface
 *   2. Daftarkan: GrammarFactory::extend('nama_driver', MyGrammar::class)
 */
class BaseModel
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    protected string  $table      = '';
    protected string  $primaryKey = 'id';
    protected array   $fillable   = [];
    protected array   $guarded    = [];
    protected array   $attributes = [];
    protected array   $relations  = [];

    // Query builder state
    protected array   $selectColumns     = ['*'];
    protected array   $whereConditions   = [];
    protected array   $whereParams       = [];
    protected array   $joins             = [];
    protected ?string $groupBy           = null;
    protected array   $orderBy           = [];
    protected string  $distinct          = '';
    protected ?int    $limit             = null;
    protected ?int    $offset            = null;
    protected array   $orWhereConditions = [];
    protected array   $with              = [];

    // Per-request dynamic table override
    protected static ?string $dynamicTable = null;

    // PDO connection
    protected ?PDO $connection = null;

    // Grammar — resolved once per driver, cached via GrammarFactory
    protected static ?GrammarInterface $grammar = null;

    // =========================================================================
    // CONSTRUCTOR & CONNECTION
    // =========================================================================

    public function __construct(array|object $attributes = [])
    {
        if (is_object($attributes)) {
            $attributes = (array) $attributes;
        }
        $this->attributes = $this->filterAttributes($attributes);
        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->connection = Database::connection();

            if ($this->connection === null) {
                $this->abort(500, 'Database connection failed.');
            }

            if (static::$grammar === null) {
                $driver          = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
                static::$grammar = GrammarFactory::make($driver);
            }
        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    private function abort(int $code = 500, string $message = 'Internal Server Error', ?\Throwable $e = null)
    {
        if (env('APP_DEBUG') !== 'false') {
            throw new \RuntimeException($message, $code, $e);
        }

        $isJson = Request::isAjax()
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isJson) {
            header('Content-Type: application/json', true, $code);
            echo json_encode(['statusCode' => $code, 'error' => 'Internal Server Error']);
        } else {
            View::error($code);
        }

        exit;
    }

    // =========================================================================
    // GRAMMAR SHORTCUT
    // =========================================================================

    protected function g(): GrammarInterface
    {
        return static::$grammar;
    }

    // =========================================================================
    // ATTRIBUTE HELPERS
    // =========================================================================

    private function filterAttributes(array $attributes): array
    {
        if (!empty($this->fillable)) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }
        if (!empty($this->guarded)) {
            return array_diff_key($attributes, array_flip($this->guarded));
        }
        return $attributes;
    }

    public function fill(array $attributes): void
    {
        $this->attributes = array_merge(
            $this->attributes,
            $this->filterAttributes($attributes)
        );
    }

    // =========================================================================
    // TRANSACTION
    // =========================================================================

    public function beginTransaction(): void { $this->connection->beginTransaction(); }
    public function commit(): void           { $this->connection->commit(); }
    public function rollback(): void         { $this->connection->rollBack(); }

    // =========================================================================
    // QUERY BUILDER
    // =========================================================================

    public static function query(): static
    {
        return new static();
    }

    public function select(string ...$columns): static
    {
        $this->selectColumns = empty($columns) ? ['*'] : array_values($columns);
        return $this;
    }

    public function selectRaw(string $expression): static
    {
        $this->selectColumns[] = $expression;
        return $this;
    }

    public function distinct(bool $value = true): static
    {
        $this->distinct = $value ? 'DISTINCT' : '';
        return $this;
    }

    // ---- WHERE ---------------------------------------------------------------

    public function where(mixed $column, string $operator = '=', mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            return $this->applyClosureWhere($column, $this->whereConditions, $this->whereParams);
        }

        if (strtoupper($operator) === 'LIKE' || strtoupper($operator) === 'NOT LIKE') {
            $param = $this->uniqueParam($column);
            $this->whereConditions[] = "{$column} {$operator} :{$param}";
            $this->whereParams[":{$param}"] = $value;
            return $this;
        }

        if ($value === null || $value === '') {
            $op = ($operator === '!=' || $operator === '<>') ? 'IS NOT' : 'IS';
            $this->whereConditions[] = "{$column} {$op} NULL";
            return $this;
        }

        $param = $this->uniqueParam($column);
        $this->whereConditions[] = "{$column} {$operator} :{$param}";
        $this->whereParams[":{$param}"] = $value;
        return $this;
    }

    public function orWhere(mixed $column, string $operator = '=', mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            return $this->applyClosureWhere($column, $this->orWhereConditions, $this->whereParams);
        }

        if ($value === null || $value === '') {
            $op = ($operator === '!=' || $operator === '<>') ? 'IS NOT' : 'IS';
            $this->orWhereConditions[] = "{$column} {$op} NULL";
            return $this;
        }

        $param = $this->uniqueParam($column);
        $this->orWhereConditions[] = "{$column} {$operator} :{$param}";
        $this->whereParams[":{$param}"] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("whereIn() values cannot be empty.");
        }
        $placeholders = [];
        foreach ($values as $i => $value) {
            $param = $this->uniqueParam($column . "_in{$i}");
            $placeholders[] = ":{$param}";
            $this->whereParams[":{$param}"] = $value;
        }
        $this->whereConditions[] = "{$column} IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("whereNotIn() values cannot be empty.");
        }
        $placeholders = [];
        foreach ($values as $i => $value) {
            $param = $this->uniqueParam($column . "_notin{$i}");
            $placeholders[] = ":{$param}";
            $this->whereParams[":{$param}"] = $value;
        }
        $this->whereConditions[] = "{$column} NOT IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->whereConditions[] = "{$column} IS NULL";
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->whereConditions[] = "{$column} IS NOT NULL";
        return $this;
    }

    public function whereBetween(string $column, mixed $start, mixed $end): static
    {
        $pStart = $this->uniqueParam($column . '_start');
        $pEnd   = $this->uniqueParam($column . '_end');
        $this->whereConditions[] = "{$column} BETWEEN :{$pStart} AND :{$pEnd}";
        $this->whereParams[":{$pStart}"] = $start;
        $this->whereParams[":{$pEnd}"]   = $end;
        return $this;
    }

    public function whereDate(string $column, string $date): static
    {
        $param = $this->uniqueParam($column . '_date');
        $this->whereConditions[] = $this->g()->dateExpr($column, $param);
        $this->whereParams[":{$param}"] = $date;
        return $this;
    }

    public function whereMonth(string $column, int|string $month): static
    {
        $param = $this->uniqueParam($column . '_month');
        $this->whereConditions[] = $this->g()->monthExpr($column) . " = :{$param}";
        $this->whereParams[":{$param}"] = (int) $month;
        return $this;
    }

    public function whereYear(string $column, int|string $year): static
    {
        $param = $this->uniqueParam($column . '_year');
        $this->whereConditions[] = $this->g()->yearExpr($column) . " = :{$param}";
        $this->whereParams[":{$param}"] = (int) $year;
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->whereConditions[] = "({$sql})";
        foreach ($bindings as $key => $val) {
            $this->whereParams[$key] = $val;
        }
        return $this;
    }

    // ---- JOIN ---------------------------------------------------------------

    public function innerJoin(string $table, string $first, string $op, string $second): static
    {
        return $this->addJoin('INNER', $table, $first, $op, $second);
    }

    public function leftJoin(string $table, string $first, string $op, string $second): static
    {
        return $this->addJoin('LEFT', $table, $first, $op, $second);
    }

    public function rightJoin(string $table, string $first, string $op, string $second): static
    {
        return $this->addJoin('RIGHT', $table, $first, $op, $second);
    }

    public function fullOuterJoin(string $table, string $first, string $op, string $second): static
    {
        return $this->addJoin('FULL OUTER', $table, $first, $op, $second);
    }

    public function crossJoin(string $table): static
    {
        $this->joins[] = "CROSS JOIN {$table}";
        return $this;
    }

    public function joinRaw(string $rawJoin): static
    {
        $this->joins[] = $rawJoin;
        return $this;
    }

    private function addJoin(string $type, string $table, string $first, string $op, string $second): static
    {
        $this->joins[] = "{$type} JOIN {$table} ON {$first} {$op} {$second}";
        return $this;
    }

    // ---- ORDER / GROUP / LIMIT ----------------------------------------------

    public function groupBy(array|string $columns): static
    {
        $this->groupBy = is_array($columns) ? implode(', ', $columns) : $columns;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy[] = "{$column} " . (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        return $this;
    }

    public function orderByRaw(string $expression): static
    {
        $this->orderBy[] = $expression;
        return $this;
    }

    public function latest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->primaryKey, 'DESC');
    }

    public function oldest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->primaryKey, 'ASC');
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function get(int $fetchStyle = PDO::FETCH_OBJ, bool $asModel = false): array
    {
        try {
            $sql   = $this->compileSelect();
            $start = microtime(true);
            $stmt  = $this->connection->prepare($sql);
            $this->bindAll($stmt);
            $stmt->execute();

            QueryLogger::add($sql, $this->whereParams, round((microtime(true) - $start) * 1000, 2), static::class);

            if (!$asModel && empty($this->with)) {
                return $stmt->fetchAll($fetchStyle);
            }

            $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $models = [];
            foreach ($rows as $row) {
                $model = new static();
                $model->fill($row);
                if (!empty($this->with)) {
                    $model->load($this->with);
                }
                $models[] = $model;
            }
            return $models;

        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    public function first(): ?static
    {
        $this->limit(1);
        $results = $this->get(PDO::FETCH_ASSOC, true);
        return $results[0] ?? null;
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $clone = clone $this;
        $clone->selectColumns = $key ? [$column, $key] : [$column];
        $results = $clone->get(PDO::FETCH_ASSOC);

        if ($key === null) {
            return array_column($results, $column);
        }
        $out = [];
        foreach ($results as $row) {
            if (isset($row[$key])) $out[$row[$key]] = $row[$column];
        }
        return $out;
    }

    public function getWithRelations(int $fetchStyle = PDO::FETCH_OBJ): array
    {
        return $this->get($fetchStyle, true);
    }

    public function count(): int
    {
        try {
            $table = $this->resolveTable();
            $sql   = "SELECT COUNT(*) as _count FROM {$this->g()->wrapTable($table)}";
            if (!empty($this->joins))   $sql .= ' ' . implode(' ', $this->joins);
            $sql .= $this->buildWhereClause();

            $stmt = $this->connection->prepare($sql);
            $this->bindAll($stmt);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) ($row['_count'] ?? 0);
        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public static function all(int $fetchStyle = PDO::FETCH_OBJ): array
    {
        try {
            $instance = new static();
            $table    = static::$dynamicTable ?? $instance->table;
            $sql      = "SELECT * FROM {$instance->g()->wrapTable($table)}";
            $stmt     = $instance->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll($fetchStyle);
        } catch (\Exception $e) {
            (new static())->abort(500, $e->getMessage(), $e);
        }
    }

    public static function find(mixed $id, int $fetchStyle = PDO::FETCH_OBJ): ?static
    {
        $instance = new static();
        $table    = static::$dynamicTable ?? $instance->table;
        $pk       = $instance->primaryKey;
        $sql      = "SELECT * FROM {$instance->g()->wrapTable($table)} WHERE {$pk} = :id";
        $stmt     = $instance->connection->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? new static($data) : null;
    }

    public static function existsWhere(array $conditions): bool
    {
        $query = static::query();
        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }
        return $query->first() !== null;
    }

    // ---- PAGINATE -----------------------------------------------------------

    public function paginate(int $perPage = 10, int $fetchStyle = PDO::FETCH_OBJ): array
    {
        $blank = ['data' => [], 'pagination' => [
            'total' => 0, 'per_page' => $perPage, 'current_page' => 1,
            'last_page' => 1, 'from' => null, 'to' => null,
        ]];

        try {
            $table        = $this->resolveTable();
            $currentPage  = max(1, (int) ($_GET['page'] ?? 1));
            $where        = $this->buildWhereClause();
            $joinStr      = !empty($this->joins) ? ' ' . implode(' ', $this->joins) : '';
            $groupStr     = $this->groupBy ? " GROUP BY {$this->groupBy}" : '';
            $cols         = implode(', ', $this->selectColumns);
            $wrapped      = $this->g()->wrapTable($table);

            $countSql  = "SELECT COUNT(*) as _total FROM (SELECT {$this->distinct} {$cols} FROM {$wrapped}{$joinStr}{$where}{$groupStr}) as _sub";
            $stmtCount = $this->connection->prepare($countSql);
            $this->bindAll($stmtCount);
            $stmtCount->execute();
            $total = (int) $stmtCount->fetchColumn();

            $lastPage    = max(1, (int) ceil($total / $perPage));
            $currentPage = min($currentPage, $lastPage);
            $offset      = ($currentPage - 1) * $perPage;

            $sql  = $this->g()->buildSelect(
                $this->distinct, $this->selectColumns, $table,
                $this->joins, $where, $this->groupBy ?? '',
                $this->orderBy, $perPage, $offset
            );

            $stmt = $this->connection->prepare($sql);
            $this->bindAll($stmt);
            $stmt->execute();
            $rows = $stmt->fetchAll($fetchStyle);

            $data = [];
            foreach ($rows as $row) {
                $model = new static();
                $model->fill((array) $row);
                if (!empty($this->with)) $model->load($this->with);
                $data[] = method_exists($model, 'toCleanArray') ? $model->toCleanArray() : (array) $row;
            }

            return [
                'data'       => $data,
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $currentPage,
                    'last_page'    => $lastPage,
                    'from'         => $total > 0 ? $offset + 1 : null,
                    'to'           => $total > 0 ? $offset + count($data) : null,
                ],
            ];
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return $blank;
        }
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    public static function create(array $attributes): ?static
    {
        try {
            $instance = new static($attributes);
            if (static::$dynamicTable) $instance->table = static::$dynamicTable;
            $instance->save();
            return $instance;
        } catch (\Exception $e) {
            (new static())->abort(500, $e->getMessage(), $e);
        }
    }

    public function save(): bool
    {
        try {
            $this->connection = DB::getConnection();
            $table = $this->resolveTable();

            if (!empty($this->attributes[$this->primaryKey])) {
                return $this->performUpdate();
            }

            $attrs   = $this->attributes;
            $columns = array_keys($attrs);

            ['sql' => $sql, 'returning' => $returning] = $this->g()->buildInsert($table, $columns, $this->primaryKey);

            $stmt = $this->connection->prepare($sql);
            foreach ($attrs as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->execute();

            $this->attributes[$this->primaryKey] = $this->g()->resolveLastInsertId(
                $this->connection, $stmt, $table, $this->primaryKey
            );
            return true;

        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    public function updates(?array $data = null): bool
    {
        return $this->performUpdate($data);
    }

    public function update(array $data): bool
    {
        return $this->performUpdate($data);
    }

    private function performUpdate(?array $data = null): bool
    {
        try {
            $this->connection = DB::getConnection();
            $table = $this->resolveTable();

            $data = $data ?? array_filter(
                $this->attributes,
                fn($k) => $k !== $this->primaryKey,
                ARRAY_FILTER_USE_KEY
            );

            if (empty($data)) return true;

            $set  = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($data)));
            $sql  = "UPDATE {$this->g()->wrapTable($table)} SET {$set} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt = $this->connection->prepare($sql);
            foreach ($data as $k => $v) $stmt->bindValue(':' . $k, $v);
            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            return $stmt->execute();

        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return false;
        }
    }

    public static function updateOrCreate(array $conditions, array $attributes): ?static
    {
        try {
            $q = static::query();
            foreach ($conditions as $field => $value) $q->where($field, '=', $value);
            $instance = $q->first();

            if ($instance) {
                foreach ($attributes as $k => $v) $instance->$k = $v;
                $instance->save();
                return $instance;
            }
            return static::create(array_merge($conditions, $attributes));
        } catch (\Exception $e) {
            (new static())->abort(500, $e->getMessage(), $e);
        }
    }

    public static function insertBatch(array $rows): array|false
    {
        if (empty($rows)) return false;

        $connection = DB::getConnection();
        $instance   = new static();
        $table      = static::$dynamicTable ?? $instance->table;
        $columns    = array_keys($rows[0]);
        $g          = $instance->g();
        $driver     = $g->driverName();

        try {
            $inTx = $connection->inTransaction();
            if (!$inTx) $connection->beginTransaction();

            $wrapped    = $g->wrapTable($table);
            $colWrapped = implode(', ', array_map(fn($c) => $g->wrapIdentifier($c), $columns));
            $pk         = $g->wrapIdentifier($instance->primaryKey);
            $firstId    = null;

            if (in_array($driver, ['pgsql', 'sqlsrv', 'dblib', 'mssql'], true)) {
                // Named params + engine-specific return clause
                $rowParts  = [];
                $allParams = [];
                foreach ($rows as $i => $row) {
                    $rowP = [];
                    foreach ($columns as $col) {
                        $k = ":{$col}_{$i}";
                        $rowP[]       = $k;
                        $allParams[$k] = $row[$col];
                    }
                    $rowParts[] = '(' . implode(', ', $rowP) . ')';
                }
                $valuesClause = implode(', ', $rowParts);

                $sql = match($driver) {
                    'pgsql'             => "INSERT INTO {$wrapped} ({$colWrapped}) VALUES {$valuesClause} RETURNING {$pk}",
                    'sqlsrv','dblib','mssql' => "INSERT INTO {$wrapped} ({$colWrapped}) OUTPUT INSERTED.{$pk} VALUES {$valuesClause}",
                    default             => "INSERT INTO {$wrapped} ({$colWrapped}) VALUES {$valuesClause}",
                };

                $stmt = $connection->prepare($sql);
                foreach ($allParams as $k => $v) $stmt->bindValue($k, $v);
                $stmt->execute();

                $row     = $stmt->fetch(PDO::FETCH_ASSOC);
                $firstId = $row[$instance->primaryKey] ?? null;

            } else {
                // MySQL / SQLite: positional ? lebih efisien untuk batch besar
                $rowP    = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $allP    = implode(', ', array_fill(0, count($rows), $rowP));
                $values  = [];
                foreach ($rows as $row) {
                    foreach ($columns as $col) $values[] = $row[$col];
                }
                $stmt = $connection->prepare("INSERT INTO {$wrapped} ({$colWrapped}) VALUES {$allP}");
                $stmt->execute($values);
                $firstId = $connection->lastInsertId();
            }

            if (!$inTx) $connection->commit();
            return ['first_id' => $firstId, 'total_inserted' => count($rows)];

        } catch (\Exception $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            ErrorHandler::handleException($e);
            return false;
        }
    }

    // ---- DELETE -------------------------------------------------------------

    public function delete(): bool
    {
        try {
            $table = $this->resolveTable();
            $sql   = "DELETE FROM {$this->g()->wrapTable($table)} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt  = $this->connection->prepare($sql);
            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            return $stmt->execute();
        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    public static function deleteWhere(array $conditions): bool
    {
        try {
            $instance = new static();
            $table    = static::$dynamicTable ?? $instance->table;
            $where    = [];
            $params   = [];
            foreach ($conditions as $field => $value) {
                $where[]        = "{$field} = :{$field}";
                $params[$field] = $value;
            }
            $sql  = "DELETE FROM {$instance->g()->wrapTable($table)} WHERE " . implode(' AND ', $where);
            $stmt = $instance->connection->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
            return $stmt->execute();
        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return false;
        }
    }

    public function deleteWithRelations(array $relations = []): bool
    {
        try {
            $this->connection  = DB::getConnection();
            $table             = $this->resolveTable();
            $localPrimaryVal   = $this->attributes[$this->primaryKey] ?? null;
            $toDelete          = !empty($relations) ? $relations : array_keys($this->relations);

            foreach ($toDelete as $relationName) {
                if (!method_exists($this, $relationName)) continue;
                $info = $this->$relationName();
                if (!is_array($info) || empty($info['model'])) continue;

                $relatedClass = is_object($info['model']) ? get_class($info['model']) : $info['model'];
                $foreignKey   = $info['foreignKey'] ?? null;
                $localKey     = $info['localKey'] ?? $info['ownerKey'] ?? $this->primaryKey;
                $localValue   = $this->attributes[$localKey] ?? $localPrimaryVal;

                if ($localValue === null || !$foreignKey) continue;

                if (in_array($info['type'], ['hasOne', 'hasMany'], true)) {
                    $relTable = (new $relatedClass())->table ?? null;
                    if ($relTable) {
                        $sql  = "DELETE FROM {$this->g()->wrapTable($relTable)} WHERE {$foreignKey} = :val";
                        $stmt = $this->connection->prepare($sql);
                        $stmt->bindValue(':val', $localValue);
                        $stmt->execute();
                    }
                }
            }

            if ($localPrimaryVal !== null) {
                $sql  = "DELETE FROM {$this->g()->wrapTable($table)} WHERE {$this->primaryKey} = :pk";
                $stmt = $this->connection->prepare($sql);
                $stmt->bindValue(':pk', $localPrimaryVal);
                $stmt->execute();
            }
            return true;

        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    // =========================================================================
    // LOCKING
    // =========================================================================

    public function lockForUpdate(): array
    {
        $sql  = $this->compileLockSelect($this->g()->lockForUpdate());
        $stmt = $this->connection->prepare($sql);
        $this->bindAll($stmt);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function sharedLock(): array
    {
        $sql  = $this->compileLockSelect($this->g()->lockForShare());
        $stmt = $this->connection->prepare($sql);
        $this->bindAll($stmt);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    private function compileLockSelect(string $lockHint): string
    {
        // SQL Server: hint masuk setelah nama tabel (WITH (...)), bukan di akhir
        if ($this->g()->driverName() === 'sqlsrv') {
            $table   = $this->resolveTable();
            $wrapped = $this->g()->wrapTable($table);
            $cols    = implode(', ', $this->selectColumns);
            $sql     = "SELECT {$this->distinct} {$cols} FROM {$wrapped}{$lockHint}";
            if (!empty($this->joins))   $sql .= ' ' . implode(' ', $this->joins);
            $sql .= $this->buildWhereClause();
            if ($this->groupBy)         $sql .= " GROUP BY {$this->groupBy}";
            if (!empty($this->orderBy)) $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
            return $sql;
        }
        // MySQL / PostgreSQL: hint di akhir query
        return $this->compileSelect() . $lockHint;
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function hasOne(string $relatedModel, string $foreignKey, string $localKey = 'id'): array
    {
        return ['type' => 'hasOne', 'model' => new $relatedModel(), 'foreignKey' => $foreignKey, 'localKey' => $localKey];
    }

    public function hasMany(string $relatedModel, string $foreignKey, string $localKey = 'id'): array
    {
        return ['type' => 'hasMany', 'model' => new $relatedModel(), 'foreignKey' => $foreignKey, 'localKey' => $localKey];
    }

    public function belongsTo(string $relatedModel, string $foreignKey, string $ownerKey = 'id'): array
    {
        return ['type' => 'belongsTo', 'model' => new $relatedModel(), 'foreignKey' => $foreignKey, 'ownerKey' => $ownerKey];
    }

    public function belongsToMany(
        string $relatedModel, string $pivotTable,
        string $foreignKey, string $relatedKey,
        string $localKey = 'id', string $relatedLocalKey = 'id'
    ): array {
        return [
            'type' => 'belongsToMany', 'model' => new $relatedModel(), 'pivot' => $pivotTable,
            'foreignKey' => $foreignKey, 'relatedKey' => $relatedKey,
            'localKey' => $localKey, 'relatedLocalKey' => $relatedLocalKey,
        ];
    }

    public function with(string|array $relations): static
    {
        foreach ((array) $relations as $rel) {
            if (!method_exists($this, $rel)) {
                throw new \Exception("Relation [{$rel}] not defined in " . static::class);
            }
        }
        $this->with = array_merge($this->with, (array) $relations);
        return $this;
    }

    public function load(array $relations): static
    {
        foreach ($relations as $relation) {
            if (!method_exists($this, $relation)) continue;
            $info    = $this->$relation();
            if (!is_array($info)) continue;
            $related = $info['model'];

            switch ($info['type']) {
                case 'belongsTo':
                    if (isset($this->attributes[$info['foreignKey']])) {
                        $this->relations[$relation] = $related::query()
                            ->where($info['ownerKey'], '=', $this->attributes[$info['foreignKey']])
                            ->first();
                    }
                    break;

                case 'hasOne':
                    if (isset($this->attributes[$info['localKey']])) {
                        $this->relations[$relation] = $related::query()
                            ->where($info['foreignKey'], '=', $this->attributes[$info['localKey']])
                            ->first();
                    }
                    break;

                case 'hasMany':
                    if (isset($this->attributes[$info['localKey']])) {
                        $this->relations[$relation] = $related::query()
                            ->where($info['foreignKey'], '=', $this->attributes[$info['localKey']])
                            ->get();
                    }
                    break;

                case 'belongsToMany':
                    if (!isset($this->attributes[$info['localKey']])) break;
                    $conn = DB::getConnection();
                    $g    = static::$grammar;
                    $sql  = "
                        SELECT r.*
                        FROM {$g->wrapTable($related->table)} AS r
                        INNER JOIN {$g->wrapTable($info['pivot'])} AS p
                            ON p.{$info['relatedKey']} = r.{$info['relatedLocalKey']}
                        WHERE p.{$info['foreignKey']} = :_lk
                    ";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindValue(':_lk', $this->attributes[$info['localKey']]);
                    $stmt->execute();
                    $this->relations[$relation] = array_map(function($row) use ($related) {
                        $inst = new $related();
                        $inst->fill($row);
                        return $inst;
                    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
                    break;
            }
        }
        $this->with = $relations;
        return $this;
    }

    public function saveWithRelations(array $relations = []): static
    {
        $this->save();
        $toSave = !empty($relations) ? $relations : array_keys($this->relations);

        foreach ($toSave as $name) {
            if (!isset($this->relations[$name])) continue;
            $info = $this->$name();
            $data = $this->relations[$name];

            switch ($info['type']) {
                case 'hasOne':
                    $data->{$info['foreignKey']} = $this->attributes[$info['localKey']];
                    $data->save();
                    break;
                case 'hasMany':
                    foreach ($data as $item) {
                        $item->{$info['foreignKey']} = $this->attributes[$info['localKey']];
                        $item->save();
                    }
                    break;
                case 'belongsTo':
                    $data->save();
                    $this->attributes[$info['foreignKey']] = $data->{$info['ownerKey']};
                    $this->save();
                    break;
            }
        }
        return $this;
    }

    // =========================================================================
    // TABLE HELPERS
    // =========================================================================

    public static function setCustomTable(string $table): static
    {
        static::$dynamicTable = $table;
        return new static();
    }

    public static function setTable(string $suffix): static
    {
        $instance = new static();
        static::$dynamicTable = $instance->table . $suffix;
        return new static();
    }

    // =========================================================================
    // DEBUG
    // =========================================================================

    public function toSql(): string
    {
        return $this->compileSelect();
    }

    public function getRawSQL(): string
    {
        $sql = $this->toSql();
        foreach ($this->whereParams as $key => $value) {
            $replacement = match(true) {
                is_null($value)   => 'NULL',
                is_bool($value)   => $value ? '1' : '0',
                is_string($value) => "'" . addslashes($value) . "'",
                default           => (string) $value,
            };
            $sql = str_replace($key, $replacement, $sql);
        }
        return $sql;
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    public function toCleanArray(): array
    {
        $data = $this->attributes;
        foreach ($this->relations as $rel => $val) {
            $data[$rel] = $val instanceof self
                ? $val->toCleanArray()
                : (is_array($val)
                    ? array_map(fn($i) => $i instanceof self ? $i->toCleanArray() : $i, $val)
                    : $val);
        }
        if (property_exists($this, 'hidden')) {
            foreach ($this->hidden as $f) unset($data[$f]);
        }
        return $data;
    }

    public static function toCleanArrayCollection(array $models): array
    {
        return array_map(fn($m) => $m instanceof self ? $m->toCleanArray() : $m, $models);
    }

    public function toArray(): array
    {
        $skip = ['table','primaryKey','fillable','guarded','attributes','connection',
                 'selectColumns','whereConditions','whereParams','joins','groupBy',
                 'orderBy','distinct','limit','offset','orWhereConditions','with','relations'];
        $data = $this->attributes;
        foreach (get_object_vars($this) as $k => $v) {
            if (in_array($k, $skip, true)) continue;
            $data[$k] = $v instanceof self ? $v->toArray()
                : (is_array($v) ? array_map(fn($i) => $i instanceof self ? $i->toArray() : $i, $v) : $v);
        }
        return $data;
    }

    public function getPrimaryKey(): string { return $this->primaryKey; }

    // =========================================================================
    // MAGIC
    // =========================================================================

    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->relations))  return $this->relations[$key];
        if (array_key_exists($key, $this->attributes)) return $this->attributes[$key];
        if (method_exists($this, $key)) {
            $this->load([$key]);
            return $this->relations[$key] ?? null;
        }
        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        if ($value instanceof self || (is_array($value) && !empty($value) && $value[0] instanceof self)) {
            $this->relations[$key] = $value;
        } else {
            $this->attributes[$key] = $value;
        }
    }

    // =========================================================================
    // PRIVATE / PROTECTED HELPERS
    // =========================================================================

    private function resolveTable(): string
    {
        return static::$dynamicTable ?? $this->table;
    }

    private function uniqueParam(string $column): string
    {
        return str_replace(['.', ' ', '-'], '_', $column) . '_' . count($this->whereParams);
    }

    private function buildWhereClause(): string
    {
        if (empty($this->whereConditions) && empty($this->orWhereConditions)) return '';
        $parts = [];
        if (!empty($this->whereConditions))   $parts[] = '(' . implode(' AND ', $this->whereConditions) . ')';
        if (!empty($this->orWhereConditions)) $parts[] = '(' . implode(' OR ',  $this->orWhereConditions) . ')';
        return ' WHERE ' . implode(' AND ', $parts);
    }

    private function compileSelect(): string
    {
        return $this->g()->buildSelect(
            $this->distinct,
            $this->selectColumns,
            $this->resolveTable(),
            $this->joins,
            $this->buildWhereClause(),
            $this->groupBy ?? '',
            $this->orderBy,
            $this->limit,
            $this->offset
        );
    }

    private function bindAll(\PDOStatement $stmt): void
    {
        foreach ($this->whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    }

    private function applyClosureWhere(\Closure $closure, array &$targetConditions, array &$targetParams): static
    {
        $sub = new static();
        $closure($sub);
        $parts = [];
        if (!empty($sub->whereConditions))   $parts[] = implode(' AND ', $sub->whereConditions);
        if (!empty($sub->orWhereConditions)) $parts[] = implode(' OR ',  $sub->orWhereConditions);
        $combined = implode(' OR ', array_filter($parts));
        if (!empty($combined)) {
            $targetConditions[] = '(' . $combined . ')';
            $targetParams       = array_merge($targetParams, $sub->whereParams);
        }
        return $this;
    }
}