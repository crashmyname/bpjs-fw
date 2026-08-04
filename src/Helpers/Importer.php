<?php

namespace Bpjs\Framework\Helpers;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Exception;

/**
 * Importer — Abstract base class untuk import data dari Excel/CSV.
 *
 * Cara penggunaan:
 *   1. Extend class ini di folder app/Import/
 *   2. Implementasikan method handle(array $row, int $index): mixed
 *   3. (Opsional) Override validate(), transform(), beforeImport(), afterImport(),
 *      onError(), onSkip(), beforeRow(), afterRow()
 *   4. Panggil (new YourImporter($filepath))->import()
 *
 * @version 2.0
 */
abstract class Importer
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    protected $sheet;
    protected $rows;
    protected $filepath;
    protected $headers       = [];
    protected $hasHeader     = true;
    protected $startRow      = 1;
    protected $customMap     = null;
    protected $requiredHeaders = [];
    protected $limitRows     = null;
    protected $skipEmptyRows = true;
    protected $sheetIndex    = 0;
    protected $sheetName     = null;

    /**
     * Dry run: jika true, handle() tidak benar-benar dieksekusi,
     * hanya validate() dan transform() yang dijalankan.
     * Berguna untuk preview/validasi sebelum import sungguhan.
     */
    protected bool $dryRun = false;

    /**
     * Proses baris secara batch.
     * Jika > 0, handle() menerima array baris (bukan per baris).
     * Override handleBatch() jika menggunakan mode ini.
     */
    protected int $chunkSize = 0;

    /**
     * Statistik import.
     */
    protected array $stats = [
        'total'   => 0,
        'success' => 0,
        'failed'  => 0,
        'skipped' => 0,
    ];

    /**
     * Simpan semua error untuk ditampilkan setelah import.
     */
    protected array $errors = [];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * @param string $filepath  Path ke file Excel/CSV
     * @param array  $options   Opsi konfigurasi:
     *   - hasHeader      (bool)         Baris pertama adalah header. Default: true
     *   - startRow       (int)          Baris mulai membaca (1-indexed). Default: 1
     *   - customMap      (callable)     Custom mapping function(array $row): array
     *   - requiredHeaders(array)        Header yang wajib ada
     *   - limitRows      (int|null)     Batasi jumlah baris yang dibaca
     *   - skipEmptyRows  (bool)         Lewati baris kosong. Default: true
     *   - sheetIndex     (int)          Index sheet (0-based). Default: 0
     *   - sheetName      (string|null)  Nama sheet (prioritas atas sheetIndex)
     *   - dryRun         (bool)         Mode preview tanpa eksekusi handle(). Default: false
     *   - chunkSize      (int)          Ukuran batch; 0 = per baris. Default: 0
     */
    public function __construct(string $filepath, array $options = [])
    {
        $this->filepath      = $filepath;
        $this->hasHeader     = $options['hasHeader']      ?? true;
        $this->startRow      = $options['startRow']       ?? 1;
        $this->customMap     = $options['customMap']      ?? null;
        $this->requiredHeaders = $options['requiredHeaders'] ?? [];
        $this->limitRows     = $options['limitRows']      ?? null;
        $this->skipEmptyRows = $options['skipEmptyRows']  ?? true;
        $this->sheetIndex    = $options['sheetIndex']     ?? 0;
        $this->sheetName     = $options['sheetName']      ?? null;
        $this->dryRun        = $options['dryRun']         ?? false;
        $this->chunkSize     = (int) ($options['chunkSize'] ?? 0);

        $this->loadFile();
    }

    // =========================================================================
    // FILE LOADING
    // =========================================================================

    private function loadFile(): void
    {
        if (!file_exists($this->filepath)) {
            throw new Exception("File tidak ditemukan: {$this->filepath}");
        }

        $spreadsheet = IOFactory::load($this->filepath);

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
            $this->headers = $this->parseHeader();
            $this->validateRequiredHeaders();
        }
    }

    // =========================================================================
    // HOOKS — Override di child class sesuai kebutuhan
    // =========================================================================

    /**
     * Dipanggil sekali sebelum looping baris dimulai.
     * Gunakan untuk setup, buka koneksi, dll.
     */
    protected function beforeImport(): void {}

    /**
     * Dipanggil sekali setelah seluruh baris selesai diproses.
     * Gunakan untuk cleanup, kirim notifikasi, dll.
     *
     * @param array $results  Semua hasil import (by reference, bisa dimodifikasi)
     */
    protected function afterImport(array &$results): void {}

    /**
     * Dipanggil sebelum setiap baris diproses.
     * Return false untuk skip baris tersebut.
     *
     * @param array $mappedRow  Data baris yang sudah di-map
     * @param int   $index      Index baris (0-based)
     * @return bool|null        false = skip, null/true = lanjut
     */
    protected function beforeRow(array $mappedRow, int $index): bool|null
    {
        return true;
    }

    /**
     * Dipanggil setelah setiap baris selesai diproses (berhasil maupun gagal).
     *
     * @param array  $mappedRow  Data baris
     * @param int    $index      Index baris
     * @param mixed  $result     Hasil dari handle()
     */
    protected function afterRow(array $mappedRow, int $index, mixed $result): void {}

    /**
     * Validasi satu baris data setelah mapping & transform.
     * Return array error jika gagal, atau array kosong jika valid.
     *
     * Contoh:
     *   protected function validate(array $row, int $index): array
     *   {
     *       $errors = [];
     *       if (empty($row['email'])) $errors[] = 'Email wajib diisi.';
     *       if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
     *       return $errors;
     *   }
     *
     * @return array  Array pesan error; kosong = valid
     */
    protected function validate(array $row, int $index): array
    {
        return [];
    }

    /**
     * Transformasi data setelah mapping, sebelum validate & handle.
     * Gunakan untuk normalisasi, trim, konversi tipe, dll.
     *
     * Contoh:
     *   protected function transform(array $row): array
     *   {
     *       $row['email'] = strtolower(trim($row['email'] ?? ''));
     *       $row['name']  = ucwords(strtolower(trim($row['name'] ?? '')));
     *       return $row;
     *   }
     *
     * @return array  Data yang sudah ditransformasi
     */
    protected function transform(array $row): array
    {
        return $row;
    }

    /**
     * Dipanggil ketika validasi gagal.
     * Override untuk custom behavior (log, dll).
     *
     * @return array  Result yang dimasukkan ke $results
     */
    protected function onValidationError(array $row, int $index, array $errors): array
    {
        return [
            'status'  => 'failed',
            'row'     => $index + 1,
            'message' => implode('; ', $errors),
            'data'    => $row,
        ];
    }

    /**
     * Dipanggil ketika exception dilempar di dalam handle().
     * Override untuk custom logging.
     *
     * @return array  Result yang dimasukkan ke $results
     */
    protected function onError(Exception $e, array $row, int $index): array
    {
        return [
            'status'  => 'failed',
            'row'     => $index + 1,
            'message' => $e->getMessage(),
            'data'    => $row,
        ];
    }

    /**
     * Dipanggil ketika beforeRow() return false (baris di-skip manual).
     *
     * @return array  Result yang dimasukkan ke $results
     */
    protected function onSkip(array $row, int $index): array
    {
        return [
            'status'  => 'skipped',
            'row'     => $index + 1,
            'message' => 'Baris dilewati.',
        ];
    }

    // =========================================================================
    // CORE — Wajib diimplementasi di child class
    // =========================================================================

    /**
     * Logika import per baris.
     * Gunakan $mappedRow untuk akses data kolom via nama header.
     *
     * @param array $mappedRow  Data baris yang sudah di-map & di-transform
     * @param int   $index      Index baris (0-based dari data rows)
     * @return mixed            Harus return array dengan key 'status' (success|failed|skipped)
     */
    abstract public function handle(array $mappedRow, int $index): mixed;

    /**
     * Logika import per batch (override jika chunkSize > 0).
     * Default: iterasi satu per satu dan panggil handle().
     *
     * @param array $chunk  Array of mapped rows
     * @param int   $chunkIndex  Index batch (0-based)
     */
    protected function handleBatch(array $chunk, int $chunkIndex): array
    {
        $results = [];
        foreach ($chunk as $index => $row) {
            try {
                $result = $this->handle($row, $index);
                $results[] = $result;
            } catch (Exception $e) {
                $this->stats['failed']++;
                $results[] = $this->onError($e, $row, $index);
            }
        }
        return $results;
    }

    // =========================================================================
    // IMPORT RUNNER
    // =========================================================================

    /**
     * Jalankan proses import.
     *
     * @return array [
     *   'summary' => ['total' => int, 'success' => int, 'failed' => int, 'skipped' => int],
     *   'results' => [...],
     *   'errors'  => [...],
     *   'dry_run' => bool,
     * ]
     */
    public function import(): array
    {
        $results = [];
        $this->beforeImport();

        $dataRows = $this->getDataRows();
        $this->stats['total'] = count($dataRows);

        if ($this->chunkSize > 0) {
            // ── Batch / chunk mode ─────────────────────────────────────────
            $chunks = array_chunk($dataRows, $this->chunkSize, true);
            foreach ($chunks as $chunkIndex => $chunk) {
                $mappedChunk = [];
                foreach ($chunk as $index => $row) {
                    $mapped = $this->mapRow($row);
                    $mapped = $this->transform($mapped);
                    $mappedChunk[$index] = $mapped;
                }

                if ($this->dryRun) {
                    foreach ($mappedChunk as $index => $mapped) {
                        $errors = $this->validate($mapped, $index);
                        if (!empty($errors)) {
                            $this->stats['failed']++;
                            $result = $this->onValidationError($mapped, $index, $errors);
                            $this->errors[] = $result;
                        } else {
                            $this->stats['success']++;
                            $result = ['status' => 'dry_run', 'row' => $index + 1, 'data' => $mapped];
                        }
                        $results[] = $result;
                    }
                    continue;
                }

                $batchResults = $this->handleBatch($mappedChunk, $chunkIndex);
                foreach ($batchResults as $res) {
                    $status = $res['status'] ?? 'success';
                    if (isset($this->stats[$status])) $this->stats[$status]++;
                    $results[] = $res;
                }
            }

        } else {
            // ── Per-row mode (default) ─────────────────────────────────────
            foreach ($dataRows as $index => $row) {
                try {
                    $mapped = $this->mapRow($row);
                    $mapped = $this->transform($mapped);

                    // Validasi
                    $validationErrors = $this->validate($mapped, $index);
                    if (!empty($validationErrors)) {
                        $this->stats['failed']++;
                        $result = $this->onValidationError($mapped, $index, $validationErrors);
                        $this->errors[] = $result;
                        $results[] = $result;
                        continue;
                    }

                    // beforeRow hook — false = skip
                    if ($this->beforeRow($mapped, $index) === false) {
                        $this->stats['skipped']++;
                        $result = $this->onSkip($mapped, $index);
                        $results[] = $result;
                        $this->afterRow($mapped, $index, $result);
                        continue;
                    }

                    // Dry run: skip handle()
                    if ($this->dryRun) {
                        $this->stats['success']++;
                        $result = ['status' => 'dry_run', 'row' => $index + 1, 'data' => $mapped];
                        $results[] = $result;
                        $this->afterRow($mapped, $index, $result);
                        continue;
                    }

                    // Eksekusi utama
                    $result = $this->handle($mapped, $index);
                    $status = $result['status'] ?? 'success';
                    if (isset($this->stats[$status])) $this->stats[$status]++;
                    $results[] = $result;
                    $this->afterRow($mapped, $index, $result);

                } catch (Exception $e) {
                    $this->stats['failed']++;
                    $result = $this->onError($e, $row, $index);
                    $this->errors[] = $result;
                    $results[] = $result;
                }
            }
        }

        $this->afterImport($results);

        return [
            'summary' => $this->stats,
            'results' => $results,
            'errors'  => $this->errors,
            'dry_run' => $this->dryRun,
        ];
    }

    // =========================================================================
    // HEADER & ROW HELPERS
    // =========================================================================

    protected function parseHeader(): array
    {
        $row = $this->rows[$this->startRow] ?? [];
        return array_map(fn($h) => strtolower(trim((string)($h ?? ''))), $row);
    }

    protected function getDataRows(): array
    {
        $offset = $this->hasHeader ? $this->startRow : $this->startRow - 1;
        $rows   = array_slice($this->rows, $offset, $this->limitRows, true);

        if ($this->skipEmptyRows) {
            $rows = array_filter($rows, function ($r) {
                return !empty(array_filter($r, fn($v) => trim((string)$v) !== ''));
            });
        }

        return array_values($rows); // reset index
    }

    protected function validateRequiredHeaders(): void
    {
        if (empty($this->requiredHeaders)) return;

        $missing = array_diff(
            array_map('strtolower', $this->requiredHeaders),
            $this->headers
        );

        if (!empty($missing)) {
            throw new Exception('Header wajib tidak ditemukan: ' . implode(', ', $missing));
        }
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

    // =========================================================================
    // PUBLIC ACCESSORS
    // =========================================================================

    /**
     * Ambil statistik import.
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Ambil semua error yang terjadi selama import.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Ambil daftar header yang terdeteksi dari file.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Preview baris pertama $n data (tanpa menjalankan import).
     */
    public function preview(int $n = 5): array
    {
        $rows = array_slice($this->getDataRows(), 0, $n);
        return array_map(fn($row) => $this->transform($this->mapRow($row)), $rows);
    }

    /**
     * Jumlah total baris data (tidak termasuk header).
     */
    public function totalRows(): int
    {
        return count($this->getDataRows());
    }

    /**
     * Aktifkan / nonaktifkan dry run secara programatik.
     */
    public function setDryRun(bool $value): static
    {
        $this->dryRun = $value;
        return $this;
    }

    /**
     * Set chunk size secara programatik.
     */
    public function setChunkSize(int $size): static
    {
        $this->chunkSize = $size;
        return $this;
    }
}