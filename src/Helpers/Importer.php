<?php
namespace Helpers;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Exception;

abstract class Importer
{
    protected $sheet;
    protected $rows;
    protected $filepath;
    protected $headers = [];
    protected $hasHeader;
    protected $startRow;
    protected $customMap;
    protected $requiredHeaders = [];
    protected $limitRows = null;
    protected $skipEmptyRows = true;
    protected $sheetIndex = 0;
    protected $sheetName = null;

    protected $stats = [
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    /**
     * @param string $filepath
     * @param array $options [
     *     'hasHeader' => true|false,
     *     'startRow' => int,
     *     'customMap' => callable|null,
     *     'requiredHeaders' => array,
     *     'limitRows' => int|null,
     *     'skipEmptyRows' => bool,
     *     'sheetIndex' => int,
     *     'sheetName' => string|null
     * ]
     */
    public function __construct(string $filepath, array $options = [])
    {
        $this->filepath = $filepath;
        $this->hasHeader = $options['hasHeader'] ?? true;
        $this->startRow = $options['startRow'] ?? 1;
        $this->customMap = $options['customMap'] ?? null;
        $this->requiredHeaders = $options['requiredHeaders'] ?? [];
        $this->limitRows = $options['limitRows'] ?? null;
        $this->skipEmptyRows = $options['skipEmptyRows'] ?? true;
        $this->sheetIndex = $options['sheetIndex'] ?? 0;
        $this->sheetName = $options['sheetName'] ?? null;

        if (!file_exists($filepath)) {
            throw new Exception("File tidak ditemukan: {$filepath}");
        }

        $spreadsheet = IOFactory::load($filepath);

        if ($this->sheetName) {
            $this->sheet = $spreadsheet->getSheetByName($this->sheetName);
            if (!$this->sheet) {
                throw new Exception("Sheet '{$this->sheetName}' tidak ditemukan dalam file.");
            }
        } else {
            $this->sheet = $spreadsheet->getSheet($this->sheetIndex);
            if (!$this->sheet) {
                throw new Exception("Sheet index {$this->sheetIndex} tidak ditemukan.");
            }
        }

        $this->rows = $this->sheet->toArray(null, true, true, true);

        if ($this->hasHeader) {
            $this->headers = $this->getHeader();
            $this->validateRequiredHeaders();
        }
    }

    protected function getHeader(): array
    {
        $row = $this->rows[$this->startRow] ?? [];
        return array_map(fn($h) => strtolower(trim((string) $h ?? '')), $row);
    }

    protected function getDataRows(): array
    {
        $offset = $this->hasHeader ? $this->startRow : $this->startRow - 1;
        $rows = array_slice($this->rows, $offset, $this->limitRows, true);

        if ($this->skipEmptyRows) {
            $rows = array_filter($rows, function ($r) {
                return array_filter($r, fn($v) => trim((string)$v) !== '');
            });
        }

        return $rows;
    }

    protected function validateRequiredHeaders(): void
    {
        if (empty($this->requiredHeaders)) return;

        $missing = array_diff($this->requiredHeaders, $this->headers);
        if (!empty($missing)) {
            throw new Exception('Header wajib hilang: ' . implode(', ', $missing));
        }
    }

    protected function beforeImport(): void {}
    protected function afterImport(array &$results): void {}

    protected function onError(Exception $e, array $row, int $index): array
    {
        return [
            'status' => 'error',
            'row' => $index + 1,
            'message' => $e->getMessage(),
        ];
    }

    public function import(): array
    {
        $results = [];
        $this->beforeImport();

        foreach ($this->getDataRows() as $index => $row) {
            try {
                $mapped = $this->mapRow($row);
                $result = $this->handle($mapped, $index);

                $status = $result['status'] ?? 'success';
                if (isset($this->stats[$status])) {
                    $this->stats[$status]++;
                }

                $results[] = $result;
            } catch (Exception $e) {
                $this->stats['failed']++;
                $results[] = $this->onError($e, $row, $index);
            }
        }

        $this->afterImport($results);
        return [
            'summary' => $this->stats,
            'results' => $results
        ];
    }

    protected function mapRow(array $row): array
    {
        if (is_callable($this->customMap)) {
            return call_user_func($this->customMap, $row);
        }

        if ($this->hasHeader) {
            $mapped = [];
            foreach ($this->headers as $col => $header) {
                if (!$header) continue;
                $mapped[$header] = $row[$col] ?? null;
            }
            return $mapped;
        }

        return $row;
    }

    abstract public function handle(array $mappedRow, int $index): mixed;
}
