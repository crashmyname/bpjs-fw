<?php
namespace Bpjs\Framework\Helpers;

use PDO;
use Exception;

class TablePlus
{
    protected PDO $pdo;
    protected string $table;
    protected array $select = ['*'];
    protected array $joins = [];
    protected array $wheres = [];
    protected array $bindings = [];
    protected array $searchable = [];
    protected ?string $orderBy = null;
    protected string $orderDir = 'ASC';
    protected int $perPage = 10;
    protected int $page = 1;
    protected array $filters = [];
    protected array $addColumns = [];
    protected array $editColumns = [];
    protected array $removeColumns = [];
    protected $transformRow = null;

    public static function of(string $table): self
    {
        $instance = new self();
        $instance->table = $table;
        $instance->pdo = self::resolvePDO();
        $instance->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $instance;
    }

    protected static function resolvePDO(): PDO
    {
        global $pdo;
        if ($pdo instanceof PDO) return $pdo;

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $db   = $_ENV['DB_DATABASE'] ?? 'koperasi_stanley';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';
        $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
        try {
            $newPdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $newPdo;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function select(...$columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    public function searchable(array $columns): self
    {
        $this->searchable = $columns;
        return $this;
    }

    public function filters($filters): self
    {
        if (is_string($filters) && $filters !== '') {
            $decoded = json_decode($filters, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->filters = $decoded;
            } else {
                $this->filters = [];
            }
        } elseif (is_array($filters)) {
            $this->filters = $filters;
        } else {
            $this->filters = [];
        }
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy = $column;
        $this->orderDir = strtoupper($direction);
        return $this;
    }

    public function paginate(?int $perPage = null, ?int $page = null): self
    {
        $this->perPage = $perPage ?? (int)($_REQUEST['per_page'] ?? $this->perPage);
        $this->page = $page ?? (int)($_REQUEST['page'] ?? $this->page);
        return $this;
    }

    protected function buildBaseWhereAndBindings(): array
    {
        $whereClauses = [];
        $bindings = $this->bindings;

        foreach ($this->filters as $column => $value) {

            if ($value === '' || $value === null) continue;

            // ===== HANDLE RANGE DATE =====
            if (is_array($value) && isset($value['start'], $value['end'])) {

                $phStart = ":f_" . count($bindings) . "_start";
                $phEnd   = ":f_" . count($bindings) . "_end";

                $whereClauses[] = "{$column} BETWEEN {$phStart} AND {$phEnd}";
                $bindings[$phStart] = $value['start'];
                $bindings[$phEnd]   = $value['end'];

                continue;
            }

            // ===== HANDLE WHERE IN =====
            if (is_array($value)) {
                if (count($value) === 0) continue;

                $placeholders = [];
                foreach ($value as $i => $v) {
                    $ph = ":f_" . count($bindings) . "_{$i}";
                    $placeholders[] = $ph;
                    $bindings[$ph] = $v;
                }

                $whereClauses[] = "{$column} IN (" . implode(',', $placeholders) . ")";
                continue;
            }

            // ===== DEFAULT EQUAL =====
            $ph = ":f_" . count($bindings);
            $whereClauses[] = "{$column} = {$ph}";
            $bindings[$ph] = $value;
        }

        return [$whereClauses, $bindings];
    }

    public function distinct(string $column): void
    {
        try {
            if (!preg_match('/^[\w\.]+$/', $column)) {
                throw new Exception("Invalid column name");
            }

            $sql = "SELECT DISTINCT {$column} FROM {$this->table}";
            if (!empty($this->joins)) $sql .= " " . implode(' ', $this->joins);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

            header('Content-Type: application/json');
            echo json_encode([
                'status' => 200,
                'data' => array_values(array_unique($values))
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 500,
                'message' => 'Gagal mengambil distinct values',
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    public function handleDistinct(?string $column = null): self
    {
        if ($column) {
            $this->distinct($column);
            exit;
        }
        return $this;
    }

    public function addColumn(string $column, callable $callback): self
    {
        $this->addColumns[$column] = $callback;
        return $this;
    }

    public function editColumn(string $column, callable $callback): self
    {
        $this->editColumns[$column] = $callback;
        return $this;
    }

    public function removeColumn(string|array $columns): self
    {
        $this->removeColumns = array_merge(
            $this->removeColumns,
            (array)$columns
        );

        return $this;
    }

    public function transformRow(callable $callback): self
    {
        $this->transformRow = $callback;
        return $this;
    }

    public function make(bool $jsonEncode = true)
    {
        try {
            $search = trim($_REQUEST['search'] ?? '');
            $page = (int)($_REQUEST['page'] ?? $this->page);
            $perPage = (int)($_REQUEST['per_page'] ?? $this->perPage);
            $offset = max(0, ($page - 1) * $perPage);

            $sql = "SELECT " . implode(', ', $this->select) . " FROM {$this->table}";
            if (!empty($this->joins)) $sql .= " " . implode(' ', $this->joins);

            [$whereClauses, $bindings] = $this->buildBaseWhereAndBindings();

            if ($search !== '' && count($this->searchable) > 0) {
                $searchParts = [];
                foreach ($this->searchable as $col) {
                    $searchParts[] = "{$col} LIKE :_search";
                }
                $searchSql = '(' . implode(' OR ', $searchParts) . ')';
                $bindings[':_search'] = "%{$search}%";
                $whereClauses[] = $searchSql;
            }

            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(' AND ', $whereClauses);
            }

            if ($this->orderBy) {
                $sql .= " ORDER BY {$this->orderBy} {$this->orderDir}";
            }

            $countSql = "SELECT COUNT(*) FROM ({$sql}) AS __total";
            $stmtCount = $this->pdo->prepare($countSql);
            foreach ($bindings as $k => $v) $stmtCount->bindValue($k, $v);
            $stmtCount->execute();
            $total = (int)$stmtCount->fetchColumn();

            $sql .= " LIMIT :_limit OFFSET :_offset";
            $stmt = $this->pdo->prepare($sql);
            foreach ($bindings as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':_limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $data = array_map(function ($row) {
                foreach ($this->editColumns as $col => $callback) {
                    if (array_key_exists($col, $row)) {
                        $row[$col] = $callback($row[$col], $row);
                    }
                }

                foreach ($this->addColumns as $col => $callback) {
                    $row[$col] = $callback($row);
                }

                foreach ($this->removeColumns as $col) {
                    unset($row[$col]);
                }

                if ($this->transformRow) {
                    $row = call_user_func($this->transformRow, $row);
                }

                return $row;

            }, $data);

            $response = [
                'status' => $total > 0 ? 200 : 404,
                'message' => $total > 0 ? 'Data Found' : 'Not Found',
                'data' => [
                    'data' => $data,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => (int)ceil($total / max(1, $perPage)),
                    ]
                ]
            ];

            if ($jsonEncode) {
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }
            return $response;
        } catch (Exception $e) {
            http_response_code(500);
            $err = [
                'status' => 500,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
            if ($jsonEncode) {
                header('Content-Type: application/json');
                echo json_encode($err);
                exit;
            }
            return $err;
        }
    }
}
