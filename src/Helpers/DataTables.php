<?php
namespace Bpjs\Framework\Helpers;

use PDO;

class DataTables
{
    private $query;
    private $pdo;
    private $columns;
    private $additionalData = [];
    private $isRawData = false;
    private $data;
    private $addColumns = [];
    private $editColumns = [];
    private $rawColumns = [];

    /**
     * Untuk mode SQL (server-side)
     */
    public static function query(PDO $pdo, string $sql, array $columns)
    {
        $instance = new static([]);
        $instance->pdo = $pdo;
        $instance->query = $sql;
        $instance->columns = $columns;
        return $instance;
    }

    /**
     * Untuk mode array biasa (client-side)
     */
    public static function of(array $data)
    {
        $instance = new static($data);
        $instance->isRawData = true;
        if (!empty($data)) {
            $first = is_object($data[0]) ? (array)$data[0] : $data[0];
            $instance->columns = array_keys($first);
        }

        return $instance;
    }

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function with(array $data)
    {
        $this->additionalData = array_merge($this->additionalData, $data);
        return $this;
    }

    public function addColumn(string $name, callable $callback)
    {
        $this->addColumns[$name] = $callback;
        return $this;
    }

    public function editColumn(string $name, callable $callback)
    {
        $this->editColumns[$name] = $callback;
        return $this;
    }

    private function deepSearch($item, string $searchValue): bool
    {
        if (is_object($item)) $item = (array)$item;

        foreach ($item as $value) {
            if (is_array($value) || is_object($value)) {
                if ($this->deepSearch($value, $searchValue)) return true;
            } elseif (stripos((string)$value, $searchValue) !== false) {
                return true;
            }
        }
        return false;
    }

    public function rawColumns(array $columns)
    {
        $this->rawColumns = $columns;
        return $this;
    }

    public function make(bool $jsonEncode = false)
    {
        ob_start();

        $draw = intval($_REQUEST['draw'] ?? 1);
        $start = intval($_REQUEST['start'] ?? 0);
        $length = intval($_REQUEST['length'] ?? 10);
        $searchValue = trim($_REQUEST['search']['value'] ?? '');
        $orderColumnIndex = $_REQUEST['order'][0]['column'] ?? 0;
        $orderDir = strtoupper($_REQUEST['order'][0]['dir'] ?? 'ASC');

        if ($this->isRawData) {
            // 🔸 mode lama (array PHP)
            $filteredData = array_filter($this->data, function ($item) use ($searchValue) {
                if ($searchValue === '') return true;
                return $this->deepSearch($item, $searchValue);
            });

            $orderColumn = $this->columns[$orderColumnIndex] ?? ($this->columns[0] ?? null);
            usort($filteredData, function ($a, $b) use ($orderColumn, $orderDir) {
                $aVal = is_object($a) ? ($a->$orderColumn ?? null) : ($a[$orderColumn] ?? null);
                $bVal = is_object($b) ? ($b->$orderColumn ?? null) : ($b[$orderColumn] ?? null);
                return $orderDir === 'ASC' ? $aVal <=> $bVal : $bVal <=> $aVal;
            });

            $totalRecords = count($this->data);
            $totalFiltered = count($filteredData);
            $filteredData = array_slice($filteredData, $start, $length);
        } else {
            // 🔹 mode SQL (server-side)
            $searchClause = '';
            if ($searchValue !== '') {
                $searchParts = [];
                foreach ($this->columns as $col) {
                    $searchParts[] = "$col LIKE :search";
                }
                $searchClause = ' WHERE ' . implode(' OR ', $searchParts);
            }

            $orderColumn = $this->columns[$orderColumnIndex] ?? $this->columns[0];
            $countSql = "SELECT COUNT(*) FROM ({$this->query}) AS total";
            $dataSql = "{$this->query} {$searchClause} ORDER BY {$orderColumn} {$orderDir} LIMIT :start, :length";

            $stmtTotal = $this->pdo->query($countSql);
            $totalRecords = $stmtTotal->fetchColumn();

            $stmt = $this->pdo->prepare($dataSql);
            if ($searchValue !== '') $stmt->bindValue(':search', "%$searchValue%");
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->bindValue(':length', $length, PDO::PARAM_INT);
            $stmt->execute();
            $filteredData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalFiltered = count($filteredData);
        }
        foreach ($filteredData as &$row) {
            if (is_object($row)) {
                $row = (array) $row;
            }

            foreach ($this->editColumns as $col => $callback) {
                if (array_key_exists($col, $row)) {
                    $row[$col] = $callback($row[$col], $row);
                }
            }

            foreach ($this->addColumns as $col => $callback) {
                $row[$col] = $callback($row);
            }
        }

        $response = array_merge([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data' => array_values($filteredData),
        ], $this->additionalData);

        if ($jsonEncode) {
            header('Content-Type: application/json');
            echo json_encode($response);
        } else {
            return $response;
        }
    }
}