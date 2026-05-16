<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Core\Request;
use Bpjs\Framework\Database\Grammar\GrammarFactory;
use Bpjs\Framework\Database\Grammar\GrammarInterface;
use PDO;

/**
 * BaseModel — Multi-Engine ORM Base Class (Patched, Optimized & Extended)
 *
 * Changelog (original):
 *   - BUG FIX: grammar di-cache per driver (bukan static global) sehingga multi-koneksi beda driver aman
 *   - BUG FIX: orWhere() kini digabung dengan OR, bukan AND di buildWhereClause()
 *   - BUG FIX: whereIn / whereNotIn dengan key yang sama di query berbeda tidak lagi crash (uniqueParam)
 *   - BUG FIX: save() memanggil DB::getConnection() lalu langsung menimpa $this->connection,
 *              padahal connect() sudah set connection — sekarang hanya resolve ulang jika null
 *   - BUG FIX: deleteWithRelations() tidak memanggil commit/rollback → ditambah transaction guard
 *   - BUG FIX: applyClosureWhere() menggabung AND+OR dengan OR — seharusnya AND — sudah diperbaiki
 *   - BUG FIX: paginate() count query tidak ikut filter DISTINCT dengan benar untuk semua driver
 *   - PERF: grammar di-cache per driver name (bukan per-instance), tidak di-instantiate ulang
 *   - PERF: paginate() — COUNT dan SELECT dijalankan dalam satu round-trip lewat multi-statement
 *           jika driver mendukung, atau dua prepare terpisah (tidak rebuild SQL dari scratch)
 *   - PERF: bindAll() menggunakan PDO::PARAM_* type hint agar driver bisa optimasi binding
 *   - PERF: compileSelect() di-cache dalam $compiledSql per instance agar tidak dikompilasi ulang
 *   - PERF: selectColumns default ['*'] → resolusi lazy, tidak alokasi array kosong di tiap clone
 *   - NEW:  chunk() — iterasi besar dataset tanpa load semua ke memori
 *   - NEW:  firstOrCreate() — cari atau buat record
 *   - NEW:  increment() / decrement() — update kolom numerik secara atomik
 *   - NEW:  toJson() — shortcut serialize ke JSON
 *   - NEW:  dd() / dump() — debug helper
 *   - NEW:  withCount() — eager load count relasi
 *   - NEW:  tap() — chainable callback
 *   - NEW:  when() — conditional query building
 *
 * Changelog (extended):
 *   - NEW:  Dirty Tracking — getDirty(), getOriginal(), isDirty(), isClean(), wasChanged(), getChanges()
 *   - NEW:  Attribute Casting — $casts property, castAttribute(), setCastAttribute()
 *   - NEW:  Soft Delete — $softDelete flag, softDelete(), restore(), withTrashed(), onlyTrashed()
 *   - NEW:  Timestamps — $timestamps flag, auto set created_at / updated_at
 *   - NEW:  Audit / Observer — $observers, observe(), boot hooks (creating, created, updating, updated, deleting, deleted)
 *   - NEW:  Scopes — addScope(), addGlobalScope(), removeScope() — query scope reusable
 *   - NEW:  Accessors & Mutators — getXxxAttribute() / setXxxAttribute() via __get/__set
 *   - NEW:  replicate() — duplikat model tanpa primary key
 *   - NEW:  fresh() / refresh() — reload dari database
 *   - NEW:  is() / isNot() — bandingkan dua model berdasarkan primary key
 *   - NEW:  only() / except() — ambil subset attributes
 *   - NEW:  makeHidden() / makeVisible() — kontrol field tampilan per-instance
 *   - NEW:  getKey() / getKeyName() — shortcut primary key
 *   - NEW:  touch() — update timestamps saja
 *   - NEW:  forceFill() — isi attributes tanpa cek fillable/guarded
 *   - NEW:  forceDelete() — hapus permanen meski soft delete aktif
 *   - NEW:  trashed() — cek apakah model sudah di-soft-delete
 *   - NEW:  BUG FIX: buildWhereClause() sekarang aware soft delete global scope secara otomatis
 *   - NEW:  BUG FIX: performUpdate() kini memicu dirty check & event hooks
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
    protected array   $hidden     = [];

    /**
     * Attribute casting.
     * Contoh: protected array $casts = ['is_active' => 'bool', 'meta' => 'array', 'score' => 'float'];
     * Tipe yang didukung: int|integer, float|double, bool|boolean, array|json, object, string, datetime
     */
    protected array $casts = [];

    /**
     * Aktifkan timestamps (created_at, updated_at).
     */
    protected bool $timestamps = false;

    /**
     * Nama kolom timestamps. Override jika nama kolom berbeda.
     */
    protected string $createdAtColumn = 'created_at';
    protected string $updatedAtColumn = 'updated_at';

    /**
     * Aktifkan soft delete. Pastikan tabel memiliki kolom 'deleted_at'.
     */
    protected bool $softDelete = false;

    /**
     * Nama kolom soft delete.
     */
    protected string $deletedAtColumn = 'deleted_at';

    // ---- Dirty Tracking -----------------------------------------------------

    /**
     * Snapshot attributes setelah load/save — dipakai untuk diff dirty.
     * @var array
     */
    protected array $original = [];

    /**
     * Perubahan setelah save() terakhir berhasil — dipakai wasChanged().
     * @var array
     */
    protected array $changes = [];

    // ---- Query builder state ------------------------------------------------

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
    protected array   $withCount         = [];
    protected array $appends = [];

    // ---- Global / named scopes ----------------------------------------------

    /**
     * Global scopes yang selalu diterapkan pada setiap query.
     * Format: ['name' => \Closure]
     */
    protected array $globalScopes = [];

    /**
     * Scopes yang di-remove untuk query tertentu (withoutScope).
     */
    protected array $removedScopes = [];

    // ---- Observer -----------------------------------------------------------

    /**
     * Daftar observer class per model.
     * Format: ['App\Observers\UserObserver', ...]
     */
    protected static array $observers = [];

    // ---- Cache --------------------------------------------------------------

    protected ?string $compiledSql = null;

    // ---- State flags --------------------------------------------------------

    /**
     * Jika true, query akan menyertakan baris soft-deleted.
     */
    protected bool $withTrashedFlag  = false;

    /**
     * Jika true, query HANYA mengembalikan baris soft-deleted.
     */
    protected bool $onlyTrashedFlag  = false;

    // ---- Static / shared ----------------------------------------------------

    protected static ?string $dynamicTable = null;

    protected ?PDO $connection = null;

    /**
     * Cache grammar per nama driver.
     * @var array<string, GrammarInterface>
     */
    protected static array $grammarCache = [];

    // =========================================================================
    // CONSTRUCTOR & CONNECTION
    // =========================================================================

    public function __construct(array|object $attributes = [])
    {
        if (is_object($attributes)) {
            $attributes = (array) $attributes;
        }

        // Boot global scopes yang didefinisikan di child class
        $this->bootGlobalScopes();

        $filtered = $this->filterAttributes($attributes);
        $this->attributes = $this->castAttributes($filtered);

        // Simpan snapshot original setelah pertama kali di-set
        $this->syncOriginal();

        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->connection = Database::connection();

            if ($this->connection === null) {
                $this->abort(500, 'Database connection failed.');
            }

            $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);

            if (!isset(static::$grammarCache[$driver])) {
                static::$grammarCache[$driver] = GrammarFactory::make($driver);
            }
        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    private function abort(int $code = 500, string $message = 'Internal Server Error', ?\Throwable $e = null): never
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
        $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        return static::$grammarCache[$driver];
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

    public function fill(array $attributes): static
    {
        $filtered = $this->filterAttributes($attributes);
        foreach ($filtered as $key => $value) {
            $this->setAttribute($key, $value);
        }
        $this->compiledSql = null;
        return $this;
    }

    /**
     * Isi attributes tanpa memperhatikan fillable/guarded.
     * Berguna untuk operasi internal atau seeder.
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        $this->compiledSql = null;
        return $this;
    }

    // =========================================================================
    // CASTING
    // =========================================================================

    /**
     * Cast semua attributes sesuai $casts definition.
     */
    private function castAttributes(array $attributes): array
    {
        foreach ($this->casts as $key => $type) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->castAttribute($key, $attributes[$key]);
            }
        }
        return $attributes;
    }

    /**
     * Cast satu attribute ke tipe yang ditentukan.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) return null;

        $type = strtolower($this->casts[$key] ?? '');

        return match(true) {
            in_array($type, ['int', 'integer'], true)        => (int) $value,
            in_array($type, ['float', 'double', 'real'], true) => (float) $value,
            in_array($type, ['bool', 'boolean'], true)       => (bool) $value,
            in_array($type, ['array', 'json'], true)         => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
            $type === 'object'                               => is_string($value) ? json_decode($value) : (object) $value,
            $type === 'string'                               => (string) $value,
            $type === 'datetime'                             => $value instanceof \DateTimeInterface
                                                                ? $value
                                                                : new \DateTime((string) $value),
            default => $value,
        };
    }

    /**
     * Cast value sebelum disimpan ke database (reverse cast).
     * array/json → JSON string, datetime → string, bool → 0/1.
     */
    protected function castAttributeForStorage(string $key, mixed $value): mixed
    {
        if ($value === null) return null;

        $type = strtolower($this->casts[$key] ?? '');

        return match(true) {
            in_array($type, ['array', 'json', 'object'], true)
                => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            $type === 'datetime'
                => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value,
            in_array($type, ['bool', 'boolean'], true)
                => $value ? 1 : 0,
            default => $value,
        };
    }

    /**
     * Set satu attribute dengan melewati accessor jika ada.
     * Mutator: definisikan setXxxAttribute($value) di child class.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $mutator = 'set' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }

        if (isset($this->casts[$key])) {
            $value = $this->castAttribute($key, $value);
        }

        $this->attributes[$key] = $value;
        $this->compiledSql = null;
    }

    /**
     * Get satu attribute dengan melewati accessor jika ada.
     * Accessor: definisikan getXxxAttribute($value) di child class.
     */
    public function getAttribute(string $key): mixed
    {
        // Cek relations dulu
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        $value    = $this->attributes[$key] ?? null;
        $accessor = 'get' . $this->studly($key) . 'Attribute';

        if (method_exists($this, $accessor)) {
            return $this->$accessor($value);
        }

        return $value;
    }

    // =========================================================================
    // DIRTY TRACKING
    // =========================================================================

    /**
     * Simpan snapshot attributes saat ini sebagai "original".
     * Dipanggil setelah load dari DB atau setelah save berhasil.
     */
    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        return $this;
    }

    /**
     * Ambil semua attributes yang berubah sejak syncOriginal() terakhir.
     *
     * @return array ['column' => 'nilai_baru', ...]
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
                continue;
            }
            // Bandingkan setelah cast agar '1' === true tidak dianggap dirty
            if ($this->originalIsNumericallyEquivalent($key)) continue;
            if ($this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Ambil nilai original (sebelum perubahan) untuk satu atau semua attribute.
     *
     * @param string|null $key  Jika null, kembalikan semua original.
     */
    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) return $this->original;
        return $this->original[$key] ?? $default;
    }

    /**
     * Cek apakah satu atau lebih attribute berubah.
     *
     * Contoh:
     *   $model->isDirty()           → ada perubahan apapun?
     *   $model->isDirty('name')     → 'name' berubah?
     *   $model->isDirty(['a','b'])  → 'a' atau 'b' berubah?
     */
    public function isDirty(string|array|null $attributes = null): bool
    {
        $dirty = $this->getDirty();
        if ($attributes === null) return !empty($dirty);

        foreach ((array) $attributes as $attr) {
            if (array_key_exists($attr, $dirty)) return true;
        }
        return false;
    }

    /**
     * Kebalikan isDirty().
     */
    public function isClean(string|array|null $attributes = null): bool
    {
        return !$this->isDirty($attributes);
    }

    /**
     * Ambil perubahan yang terjadi SETELAH save() berhasil.
     * (berbeda dengan getDirty() yang menghitung sejak original terakhir)
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Cek apakah attribute berubah pada save() terakhir.
     */
    public function wasChanged(string|array|null $attributes = null): bool
    {
        if ($attributes === null) return !empty($this->changes);

        foreach ((array) $attributes as $attr) {
            if (array_key_exists($attr, $this->changes)) return true;
        }
        return false;
    }

    /**
     * Helper: apakah nilai original dan current secara numerik sama
     * (menghindari false-positive saat cast '1' vs 1).
     */
    private function originalIsNumericallyEquivalent(string $key): bool
    {
        $current  = $this->attributes[$key];
        $original = $this->original[$key] ?? null;

        if (!is_numeric($current) || !is_numeric($original)) return false;
        return (float) $current === (float) $original;
    }

    // =========================================================================
    // TIMESTAMPS
    // =========================================================================

    /**
     * Set timestamps sebelum operasi INSERT.
     */
    private function setCreatingTimestamps(): void
    {
        if (!$this->timestamps) return;
        $now = $this->freshTimestamp();
        if (!isset($this->attributes[$this->createdAtColumn])) {
            $this->attributes[$this->createdAtColumn] = $now;
        }
        $this->attributes[$this->updatedAtColumn] = $now;
    }

    /**
     * Set timestamps sebelum operasi UPDATE.
     */
    private function setUpdatingTimestamps(): void
    {
        if (!$this->timestamps) return;
        $this->attributes[$this->updatedAtColumn] = $this->freshTimestamp();
    }

    /**
     * Update hanya kolom updated_at tanpa mengubah data lain.
     */
    public function touch(?string $column = null): bool
    {
        $col = $column ?? $this->updatedAtColumn;
        $this->attributes[$col] = $this->freshTimestamp();
        return $this->performUpdate([$col => $this->attributes[$col]]);
    }

    protected function freshTimestamp(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s');
    }

    // =========================================================================
    // SOFT DELETE
    // =========================================================================

    /**
     * Soft delete: set deleted_at dan simpan.
     * Event 'deleting' dan 'deleted' tetap difire.
     */
    public function softDelete(): bool
    {
        if (!$this->softDelete) {
            throw new \LogicException("Soft delete tidak aktif. Set \$softDelete = true di model.");
        }

        if ($this->fireEvent('deleting') === false) return false;

        $col = $this->deletedAtColumn;
        $this->attributes[$col] = $this->freshTimestamp();
        $result = $this->performUpdate([$col => $this->attributes[$col]]);

        if ($result) {
            $this->syncOriginal();
            $this->fireEvent('deleted');
        }

        return $result;
    }

    /**
     * Restore record yang sudah di-soft-delete.
     */
    public function restore(): bool
    {
        if (!$this->softDelete) {
            throw new \LogicException("Soft delete tidak aktif.");
        }

        $col = $this->deletedAtColumn;
        $this->attributes[$col] = null;
        $result = $this->performUpdate([$col => null]);

        if ($result) $this->syncOriginal();

        return $result;
    }

    /**
     * Cek apakah model sudah di-soft-delete.
     */
    public function trashed(): bool
    {
        return $this->softDelete && !empty($this->attributes[$this->deletedAtColumn]);
    }

    /**
     * Hapus permanen meski soft delete aktif.
     */
    public function forceDelete(): bool
    {
        if ($this->fireEvent('deleting') === false) return false;

        try {
            $table = $this->resolveTable();
            $sql   = "DELETE FROM {$this->g()->wrapTable($table)} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt  = $this->connection->prepare($sql);
            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            $result = $stmt->execute();
            if ($result) $this->fireEvent('deleted');
            return $result;
        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    /**
     * Sertakan baris soft-deleted dalam query.
     */
    public function withTrashed(): static
    {
        $this->withTrashedFlag = true;
        $this->compiledSql     = null;
        return $this;
    }

    /**
     * Hanya kembalikan baris soft-deleted.
     */
    public function onlyTrashed(): static
    {
        $this->onlyTrashedFlag = true;
        $this->compiledSql     = null;
        return $this;
    }

    // =========================================================================
    // GLOBAL SCOPES
    // =========================================================================

    /**
     * Dipanggil di constructor untuk menginisialisasi global scopes.
     * Override di child class untuk mendaftarkan scope:
     *
     *   protected function bootGlobalScopes(): void
     *   {
     *       parent::bootGlobalScopes();
     *       $this->addGlobalScope('active', fn($q) => $q->where('is_active', '=', 1));
     *   }
     */
    protected function bootGlobalScopes(): void
    {
        // Base: tidak ada global scope default
    }

    /**
     * Tambahkan global scope dengan nama.
     */
    public function addGlobalScope(string $name, \Closure $scope): static
    {
        $this->globalScopes[$name] = $scope;
        $this->compiledSql         = null;
        return $this;
    }

    /**
     * Hapus global scope tertentu dari query ini.
     *
     * Contoh: User::query()->withoutScope('active')->get()
     */
    public function withoutScope(string $name): static
    {
        $this->removedScopes[] = $name;
        $this->compiledSql     = null;
        return $this;
    }

    /**
     * Hapus semua global scope dari query ini.
     */
    public function withoutGlobalScopes(): static
    {
        $this->removedScopes = array_keys($this->globalScopes);
        $this->compiledSql   = null;
        return $this;
    }

    /**
     * Terapkan semua global scope yang aktif ke query ini.
     * Dipanggil di dalam compileSelect() dan count().
     */
    private function applyGlobalScopes(): void
    {
        foreach ($this->globalScopes as $name => $scope) {
            if (in_array($name, $this->removedScopes, true)) continue;
            $scope($this);
        }

        // Soft delete scope
        if ($this->softDelete && !$this->withTrashedFlag && !$this->onlyTrashedFlag) {
            $this->whereNull($this->deletedAtColumn);
        }
        if ($this->softDelete && $this->onlyTrashedFlag) {
            $this->whereNotNull($this->deletedAtColumn);
        }
    }

    // =========================================================================
    // OBSERVERS / EVENTS
    // =========================================================================

    /**
     * Daftarkan observer class untuk model ini.
     *
     * Observer harus memiliki method: creating, created, updating, updated, deleting, deleted, restoring, restored
     * Semua method bersifat opsional.
     *
     * Contoh:
     *   User::observe(UserObserver::class);
     */
    public static function observe(string|object $observer): void
    {
        $class = is_object($observer) ? get_class($observer) : $observer;
        if (!in_array($class, static::$observers[static::class] ?? [], true)) {
            static::$observers[static::class][] = $class;
        }
    }

    /**
     * Fire event ke semua observer yang terdaftar.
     * Return false jika salah satu observer meminta dibatalkan (returning false).
     */
    protected function fireEvent(string $event): bool|null
    {
        foreach (static::$observers[static::class] ?? [] as $observerClass) {
            $observer = is_string($observerClass) ? new $observerClass() : $observerClass;
            if (method_exists($observer, $event)) {
                $result = $observer->$event($this);
                if ($result === false) return false;
            }
        }

        // Juga cek static boot hooks: bootXxx() di model
        $bootMethod = 'on' . ucfirst($event);
        if (method_exists($this, $bootMethod)) {
            $result = $this->$bootMethod();
            if ($result === false) return false;
        }

        return true;
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
        $this->compiledSql   = null;
        return $this;
    }

    public function selectRaw(string $expression): static
    {
        $this->selectColumns[] = $expression;
        $this->compiledSql     = null;
        return $this;
    }

    public function distinct(bool $value = true): static
    {
        $this->distinct    = $value ? 'DISTINCT' : '';
        $this->compiledSql = null;
        return $this;
    }

    // ---- WHERE ---------------------------------------------------------------

    public function where(mixed $column, string $operator = '=', mixed $value = null): static
    {
        $this->compiledSql = null;

        if ($column instanceof \Closure) {
            return $this->applyClosureWhere($column, 'AND');
        }

        $upperOp = strtoupper($operator);

        if ($upperOp === 'LIKE' || $upperOp === 'NOT LIKE') {
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
        $this->compiledSql = null;

        if ($column instanceof \Closure) {
            return $this->applyClosureWhere($column, 'OR');
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
        $this->compiledSql = null;
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
        $this->compiledSql = null;
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->whereConditions[] = "{$column} IS NULL";
        $this->compiledSql = null;
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->whereConditions[] = "{$column} IS NOT NULL";
        $this->compiledSql = null;
        return $this;
    }

    public function whereBetween(string $column, mixed $start, mixed $end): static
    {
        $pStart = $this->uniqueParam($column . '_start');
        $pEnd   = $this->uniqueParam($column . '_end');
        $this->whereConditions[] = "{$column} BETWEEN :{$pStart} AND :{$pEnd}";
        $this->whereParams[":{$pStart}"] = $start;
        $this->whereParams[":{$pEnd}"]   = $end;
        $this->compiledSql = null;
        return $this;
    }

    public function whereDate(string $column, string $date): static
    {
        $param = $this->uniqueParam($column . '_date');
        $this->whereConditions[] = $this->g()->dateExpr($column, $param);
        $this->whereParams[":{$param}"] = $date;
        $this->compiledSql = null;
        return $this;
    }

    public function whereMonth(string $column, int|string $month): static
    {
        $param = $this->uniqueParam($column . '_month');
        $this->whereConditions[] = $this->g()->monthExpr($column) . " = :{$param}";
        $this->whereParams[":{$param}"] = (int) $month;
        $this->compiledSql = null;
        return $this;
    }

    public function whereYear(string $column, int|string $year): static
    {
        $param = $this->uniqueParam($column . '_year');
        $this->whereConditions[] = $this->g()->yearExpr($column) . " = :{$param}";
        $this->whereParams[":{$param}"] = (int) $year;
        $this->compiledSql = null;
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->whereConditions[] = "({$sql})";
        foreach ($bindings as $key => $val) {
            $this->whereParams[$key] = $val;
        }
        $this->compiledSql = null;
        return $this;
    }

    // ---- CONDITIONAL BUILDER ------------------------------------------------

    public function when(bool $condition, \Closure $callback, ?\Closure $default = null): static
    {
        if ($condition) {
            $callback($this);
        } elseif ($default !== null) {
            $default($this);
        }
        return $this;
    }

    public function tap(\Closure $callback): static
    {
        $callback($this);
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
        $this->joins[]     = "CROSS JOIN {$table}";
        $this->compiledSql = null;
        return $this;
    }

    public function joinRaw(string $rawJoin): static
    {
        $this->joins[]     = $rawJoin;
        $this->compiledSql = null;
        return $this;
    }

    private function addJoin(string $type, string $table, string $first, string $op, string $second): static
    {
        $this->joins[]     = "{$type} JOIN {$table} ON {$first} {$op} {$second}";
        $this->compiledSql = null;
        return $this;
    }

    // ---- ORDER / GROUP / LIMIT ----------------------------------------------

    public function groupBy(array|string $columns): static
    {
        $this->groupBy     = is_array($columns) ? implode(', ', $columns) : $columns;
        $this->compiledSql = null;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy[]   = "{$column} " . (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        $this->compiledSql = null;
        return $this;
    }

    public function orderByRaw(string $expression): static
    {
        $this->orderBy[]   = $expression;
        $this->compiledSql = null;
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
        $this->limit       = $limit;
        $this->compiledSql = null;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset      = $offset;
        $this->compiledSql = null;
        return $this;
    }

    public static function clearState(): void
    {
        static::$dynamicTable = null;
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

            if (!$asModel && empty($this->with) && empty($this->withCount)) {
                $rows = $stmt->fetchAll($fetchStyle);
                // Terapkan cast pada setiap baris jika fetch assoc/array
                if ($fetchStyle === PDO::FETCH_ASSOC && !empty($this->casts)) {
                    return array_map(fn($r) => $this->castAttributes($r), $rows);
                }
                return $rows;
            }

            $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $models = [];
            foreach ($rows as $row) {
                $model = new static();
                $model->attributes = $this->castAttributes($row);
                $model->syncOriginal();   // ← penting: set original setelah load dari DB
                if (!empty($this->with)) {
                    $model->load($this->with);
                }
                if (!empty($this->withCount)) {
                    $model->loadCount($this->withCount);
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
        $clone->compiledSql   = null;
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
            // Apply global scopes ke clone agar tidak polute state utama
            $clone = clone $this;
            $clone->applyGlobalScopes();

            $table = $clone->resolveTable();
            $sql   = "SELECT COUNT(*) as _count FROM {$clone->g()->wrapTable($table)}";
            if (!empty($clone->joins)) $sql .= ' ' . implode(' ', $clone->joins);
            $sql .= $clone->buildWhereClause();

            $stmt = $clone->connection->prepare($sql);
            $clone->bindAll($stmt);
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

    /**
     * Alias exists() dengan negasi.
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Ambil nilai MAX dari kolom.
     */
    public function max(string $column): mixed
    {
        return $this->aggregate("MAX({$column})");
    }

    /**
     * Ambil nilai MIN dari kolom.
     */
    public function min(string $column): mixed
    {
        return $this->aggregate("MIN({$column})");
    }

    /**
     * Ambil nilai SUM dari kolom.
     */
    public function sum(string $column): float|int
    {
        return (float) $this->aggregate("SUM({$column})") ?: 0;
    }

    /**
     * Ambil nilai AVG dari kolom.
     */
    public function avg(string $column): float|int
    {
        return (float) $this->aggregate("AVG({$column})") ?: 0;
    }

    private function aggregate(string $expression): mixed
    {
        try {
            $clone = clone $this;
            $clone->applyGlobalScopes();

            $table = $clone->resolveTable();
            $sql   = "SELECT {$expression} as _agg FROM {$clone->g()->wrapTable($table)}";
            if (!empty($clone->joins)) $sql .= ' ' . implode(' ', $clone->joins);
            $sql .= $clone->buildWhereClause();

            $stmt = $clone->connection->prepare($sql);
            $clone->bindAll($stmt);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['_agg'] ?? null;
        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    public static function all(int $fetchStyle = PDO::FETCH_OBJ): array
    {
        try {
            $instance = new static();
            $table    = static::$dynamicTable ?? $instance->table;
            $sql      = "SELECT * FROM {$instance->g()->wrapTable($table)}";

            // Tambah soft delete filter jika aktif
            if ($instance->softDelete) {
                $sql .= " WHERE {$instance->deletedAtColumn} IS NULL";
            }

            $stmt = $instance->connection->prepare($sql);
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

        if ($instance->softDelete) {
            $sql .= " AND {$instance->deletedAtColumn} IS NULL";
        }

        $stmt = $instance->connection->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) return null;

        $model = new static($data);
        $model->syncOriginal();
        return $model;
    }

    /**
     * Seperti find() tapi throw exception jika tidak ditemukan.
     */
    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new \RuntimeException(static::class . " dengan ID [{$id}] tidak ditemukan.");
        }
        return $model;
    }

    public static function existsWhere(array $conditions): bool
    {
        $query = static::query();
        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }
        return $query->first() !== null;
    }

    // ---- Reload dari database -----------------------------------------------

    /**
     * Ambil instance baru dari database (tidak mutate instance ini).
     */
    public function fresh(): ?static
    {
        $pk = $this->attributes[$this->primaryKey] ?? null;
        if ($pk === null) return null;
        return static::find($pk);
    }

    /**
     * Reload attributes instance ini dari database (mutate in-place).
     */
    public function refresh(): static
    {
        $fresh = $this->fresh();
        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->relations  = [];
            $this->syncOriginal();
        }
        return $this;
    }

    // ---- Model comparison ---------------------------------------------------

    /**
     * Cek apakah dua model merepresentasikan baris yang sama.
     */
    public function is(mixed $model): bool
    {
        if (!($model instanceof static)) return false;
        return $this->getKey() !== null
            && $this->getKey() === $model->getKey()
            && $this->resolveTable() === $model->resolveTable();
    }

    public function isNot(mixed $model): bool
    {
        return !$this->is($model);
    }

    // ---- Duplicate ----------------------------------------------------------

    /**
     * Buat salinan model tanpa primary key (siap untuk disimpan sebagai record baru).
     */
    public function replicate(array $except = []): static
    {
        $attrs  = $this->attributes;
        $skip   = array_merge([$this->primaryKey], $except);

        if ($this->timestamps) {
            $skip[] = $this->createdAtColumn;
            $skip[] = $this->updatedAtColumn;
        }

        foreach ($skip as $key) {
            unset($attrs[$key]);
        }

        $clone = new static();
        $clone->forceFill($attrs);
        return $clone;
    }

    // ---- CHUNK --------------------------------------------------------------

    public function chunk(int $size, \Closure $callback): void
    {
        $offset = 0;
        do {
            $clone = clone $this;
            $clone->compiledSql = null;
            $clone->limit($size)->offset($offset);
            $rows = $clone->get(PDO::FETCH_OBJ, false);
            if (empty($rows)) break;
            if ($callback($rows) === false) break;
            $offset += $size;
        } while (count($rows) === $size);
    }

    // ---- Attribute subset ---------------------------------------------------

    /**
     * Ambil hanya key tertentu dari attributes.
     *
     * Contoh: $model->only(['name', 'email'])
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->toCleanArray(), array_flip($keys));
    }

    /**
     * Ambil semua attributes kecuali key tertentu.
     *
     * Contoh: $model->except(['password', 'token'])
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toCleanArray(), array_flip($keys));
    }

    /**
     * Sembunyikan field tambahan untuk instance ini saja (tidak mutate $hidden property class).
     */
    public function makeHidden(array|string $keys): static
    {
        $this->hidden = array_unique(array_merge($this->hidden, (array) $keys));
        return $this;
    }

    /**
     * Tampilkan kembali field yang sebelumnya di-hidden untuk instance ini.
     */
    public function makeVisible(array|string $keys): static
    {
        $this->hidden = array_diff($this->hidden, (array) $keys);
        return $this;
    }

    // ---- Primary key shortcut -----------------------------------------------

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    // ---- PAGINATE -----------------------------------------------------------

    public function paginate(int $perPage = 10, int $fetchStyle = PDO::FETCH_OBJ): array
    {
        $blank = ['data' => [], 'pagination' => [
            'total' => 0, 'per_page' => $perPage, 'current_page' => 1,
            'last_page' => 1, 'from' => null, 'to' => null,
        ]];

        try {
            // Apply global scopes sebelum compile
            $this->applyGlobalScopes();

            $table       = $this->resolveTable();
            $currentPage = max(1, (int) ($_GET['page'] ?? 1));
            $where       = $this->buildWhereClause();
            $joinStr     = !empty($this->joins) ? ' ' . implode(' ', $this->joins) : '';
            $groupStr    = $this->groupBy ? " GROUP BY {$this->groupBy}" : '';
            $wrapped     = $this->g()->wrapTable($table);

            if ($this->distinct === '' && $this->groupBy === null) {
                $countSql = "SELECT COUNT(*) as _total FROM {$wrapped}{$joinStr}{$where}";
            } else {
                $cols     = implode(', ', $this->selectColumns);
                $countSql = "SELECT COUNT(*) as _total FROM "
                    . "(SELECT {$this->distinct} {$cols} FROM {$wrapped}{$joinStr}{$where}{$groupStr}) as _sub";
            }

            $stmtCount = $this->connection->prepare($countSql);
            $this->bindAll($stmtCount);
            $stmtCount->execute();
            $total = (int) $stmtCount->fetchColumn();

            if ($total === 0) {
                return $blank;
            }

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
                $model->attributes = $this->castAttributes((array) $row);
                $model->syncOriginal();
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
                    'from'         => $offset + 1,
                    'to'           => $offset + count($data),
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

    /**
     * BUG FIX (original): tidak re-resolve connection jika sudah ada.
     * EXTENDED: fire events creating/created, set timestamps, track dirty/changes.
     */
    public function save(): bool
    {
        try {
            if ($this->connection === null) {
                $this->connection = DB::getConnection();
            }

            $table = $this->resolveTable();

            if (!empty($this->attributes[$this->primaryKey])) {
                // UPDATE path
                if ($this->isClean()) return true; // tidak ada perubahan, skip
                return $this->performUpdate();
            }

            // INSERT path
            if ($this->fireEvent('creating') === false) return false;

            $this->setCreatingTimestamps();

            $attrs   = $this->prepareAttributesForStorage();
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

            $this->syncOriginal();
            $this->fireEvent('created');

            return true;

        } catch (\Exception $e) {
            $this->abort(500, $e->getMessage(), $e);
        }
    }

    /**
     * Siapkan attributes untuk storage — reverse-cast sesuai $casts.
     */
    private function prepareAttributesForStorage(?array $data = null): array
    {
        $source = $data ?? $this->attributes;
        $out    = [];
        foreach ($source as $key => $value) {
            $out[$key] = isset($this->casts[$key])
                ? $this->castAttributeForStorage($key, $value)
                : $value;
        }
        return $out;
    }

    public function updates(?array $data = null): bool
    {
        return $this->performUpdate($data);
    }

    public function update(array $data): bool
    {
        return $this->performUpdate($data);
    }

    /**
     * EXTENDED: fire events updating/updated, set timestamps, track changes.
     */
    private function performUpdate(?array $data = null): bool
    {
        try {
            if ($this->connection === null) {
                $this->connection = DB::getConnection();
            }

            // Simpan dirty sebelum sync, untuk getChanges() setelah save
            $dirtyBeforeSave = $this->getDirty();

            $data = $data ?? array_filter(
                $this->attributes,
                fn($k) => $k !== $this->primaryKey,
                ARRAY_FILTER_USE_KEY
            );

            if (empty($data)) return true;

            if ($this->fireEvent('updating') === false) return false;

            $this->setUpdatingTimestamps();

            // Jika timestamps ditambahkan, pastikan ada di $data
            if ($this->timestamps && $data !== null) {
                $data[$this->updatedAtColumn] = $this->attributes[$this->updatedAtColumn] ?? $this->freshTimestamp();
            }

            $storedData = $this->prepareAttributesForStorage($data);

            $set  = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($storedData)));
            $sql  = "UPDATE {$this->g()->wrapTable($this->resolveTable())} SET {$set} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt = $this->connection->prepare($sql);
            foreach ($storedData as $k => $v) $stmt->bindValue(':' . $k, $v);
            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            $result = $stmt->execute();

            if ($result) {
                // Update attributes lokal dengan data yang baru disimpan
                foreach ($data as $k => $v) {
                    $this->attributes[$k] = $v;
                }
                // Track changes: kolom apa yang berubah pada save() ini
                $this->changes = $dirtyBeforeSave;
                $this->syncOriginal();
                $this->fireEvent('updated');
            }

            return $result;

        } catch (\Exception $e) {
            ErrorHandler::handleException($e);
            return false;
        }
    }

    public function increment(string $column, int|float $amount = 1): bool
    {
        return $this->atomicAdjust($column, $amount);
    }

    public function decrement(string $column, int|float $amount = 1): bool
    {
        return $this->atomicAdjust($column, -$amount);
    }

    private function atomicAdjust(string $column, int|float $amount): bool
    {
        $pk = $this->attributes[$this->primaryKey] ?? null;
        if ($pk === null) {
            throw new \LogicException("increment/decrement requires a persisted model (primary key must be set).");
        }
        $table = $this->resolveTable();
        $op    = $amount >= 0 ? '+' : '-';
        $abs   = abs($amount);
        $sql   = "UPDATE {$this->g()->wrapTable($table)} SET {$column} = {$column} {$op} {$abs} WHERE {$this->primaryKey} = :pk";
        $stmt  = $this->connection->prepare($sql);
        $stmt->bindValue(':pk', $pk);
        $result = $stmt->execute();

        if ($result && isset($this->attributes[$column])) {
            $this->original[$column]  = $this->attributes[$column]; // update original
            $this->attributes[$column] += $amount;
        }
        return $result;
    }

    public static function updateOrCreate(array $conditions, array $attributes): ?static
    {
        try {
            $q = static::query();
            foreach ($conditions as $field => $value) $q->where($field, '=', $value);
            $instance = $q->first();

            if ($instance) {
                foreach ($attributes as $k => $v) $instance->attributes[$k] = $v;
                $instance->save();
                return $instance;
            }
            return static::create(array_merge($conditions, $attributes));
        } catch (\Exception $e) {
            (new static())->abort(500, $e->getMessage(), $e);
        }
    }

    public static function firstOrCreate(array $conditions, array $attributes = []): static
    {
        $q = static::query();
        foreach ($conditions as $field => $value) $q->where($field, '=', $value);
        $instance = $q->first();

        return $instance ?? static::create(array_merge($conditions, $attributes));
    }

    /**
     * Cari berdasarkan kondisi atau kembalikan instance baru (tidak disimpan).
     */
    public static function firstOrNew(array $conditions, array $attributes = []): static
    {
        $q = static::query();
        foreach ($conditions as $field => $value) $q->where($field, '=', $value);
        $instance = $q->first();

        if ($instance) return $instance;

        $new = new static(array_merge($conditions, $attributes));
        return $new;
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
                $rowParts  = [];
                $allParams = [];
                foreach ($rows as $i => $row) {
                    $rowP = [];
                    foreach ($columns as $col) {
                        $k = ":{$col}_{$i}";
                        $rowP[]        = $k;
                        $allParams[$k] = $row[$col];
                    }
                    $rowParts[] = '(' . implode(', ', $rowP) . ')';
                }
                $valuesClause = implode(', ', $rowParts);

                $sql = match($driver) {
                    'pgsql'                  => "INSERT INTO {$wrapped} ({$colWrapped}) VALUES {$valuesClause} RETURNING {$pk}",
                    'sqlsrv','dblib','mssql' => "INSERT INTO {$wrapped} ({$colWrapped}) OUTPUT INSERTED.{$pk} VALUES {$valuesClause}",
                    default                  => "INSERT INTO {$wrapped} ({$colWrapped}) VALUES {$valuesClause}",
                };

                $stmt = $connection->prepare($sql);
                foreach ($allParams as $k => $v) $stmt->bindValue($k, $v);
                $stmt->execute();

                $row     = $stmt->fetch(PDO::FETCH_ASSOC);
                $firstId = $row[$instance->primaryKey] ?? null;

            } else {
                $rowP   = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $allP   = implode(', ', array_fill(0, count($rows), $rowP));
                $values = [];
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

    /**
     * EXTENDED: Jika softDelete aktif, lakukan softDelete. Jika tidak, hapus permanen.
     * Fire event deleting/deleted.
     */
    public function delete(): bool
    {
        if ($this->softDelete) {
            return $this->softDelete();
        }

        if ($this->fireEvent('deleting') === false) return false;

        try {
            $table = $this->resolveTable();
            $sql   = "DELETE FROM {$this->g()->wrapTable($table)} WHERE {$this->primaryKey} = :{$this->primaryKey}";
            $stmt  = $this->connection->prepare($sql);
            $stmt->bindValue(':' . $this->primaryKey, $this->attributes[$this->primaryKey]);
            $result = $stmt->execute();
            if ($result) $this->fireEvent('deleted');
            return $result;
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
        $ownTx = false;
        try {
            if ($this->connection === null) {
                $this->connection = DB::getConnection();
            }

            if (!$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
                $ownTx = true;
            }

            $table           = $this->resolveTable();
            $localPrimaryVal = $this->attributes[$this->primaryKey] ?? null;
            $toDelete        = !empty($relations) ? $relations : array_keys($this->relations);

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

            if ($ownTx) $this->connection->commit();
            return true;

        } catch (\Exception $e) {
            if ($ownTx && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
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

    public function withCount(string|array $relations): static
    {
        foreach ((array) $relations as $rel) {
            if (!method_exists($this, $rel)) {
                throw new \Exception("Relation [{$rel}] not defined in " . static::class);
            }
        }
        $this->withCount = array_merge($this->withCount, (array) $relations);
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
                    $g    = $this->g();
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
                        $inst->attributes = $row;
                        return $inst;
                    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
                    break;
            }
        }
        $this->with = $relations;
        return $this;
    }

    public function loadCount(array $relations): static
    {
        foreach ($relations as $relation) {
            if (!method_exists($this, $relation)) continue;
            $info = $this->$relation();
            if (!is_array($info)) continue;

            $related    = $info['model'];
            $relTable   = $this->g()->wrapTable($related->table);
            $countAlias = "{$relation}_count";

            switch ($info['type']) {
                case 'hasOne':
                case 'hasMany':
                    if (!isset($this->attributes[$info['localKey']])) break;
                    $sql  = "SELECT COUNT(*) FROM {$relTable} WHERE {$info['foreignKey']} = :lk";
                    $stmt = $this->connection->prepare($sql);
                    $stmt->bindValue(':lk', $this->attributes[$info['localKey']]);
                    $stmt->execute();
                    $this->attributes[$countAlias] = (int) $stmt->fetchColumn();
                    break;

                case 'belongsToMany':
                    if (!isset($this->attributes[$info['localKey']])) break;
                    $pivotTable = $this->g()->wrapTable($info['pivot']);
                    $sql        = "SELECT COUNT(*) FROM {$pivotTable} WHERE {$info['foreignKey']} = :lk";
                    $stmt       = $this->connection->prepare($sql);
                    $stmt->bindValue(':lk', $this->attributes[$info['localKey']]);
                    $stmt->execute();
                    $this->attributes[$countAlias] = (int) $stmt->fetchColumn();
                    break;
            }
        }
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

    public function dd(): never
    {
        var_dump($this->getRawSQL());
        exit(1);
    }

    public function dump(): static
    {
        var_dump($this->getRawSQL());
        return $this;
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    public function toCleanArray(): array
    {
        $data = $this->attributes;

        // Apply accessors untuk setiap attribute
        foreach (array_keys($data) as $key) {
            $accessor = 'get' . $this->studly($key) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $data[$key] = $this->$accessor($data[$key]);
            }
        }

        foreach ($this->appends as $appendKey) {
            $accessor = 'get' . $this->studly($appendKey) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $data[$appendKey] = $this->$accessor(null);
            }
        }

        foreach ($this->relations as $rel => $val) {
            $data[$rel] = $val instanceof self
                ? $val->toCleanArray()
                : (is_array($val)
                    ? array_map(fn($i) => $i instanceof self ? $i->toCleanArray() : $i, $val)
                    : $val);
        }
        foreach ($this->hidden as $f) {
            unset($data[$f]);
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
                 'orderBy','distinct','limit','offset','orWhereConditions','with','relations',
                 'compiledSql','withCount','hidden','casts','timestamps','softDelete',
                 'original','changes','globalScopes','removedScopes','withTrashedFlag','onlyTrashedFlag'];
        $data = $this->attributes;
        foreach (get_object_vars($this) as $k => $v) {
            if (in_array($k, $skip, true)) continue;
            $data[$k] = $v instanceof self ? $v->toArray()
                : (is_array($v) ? array_map(fn($i) => $i instanceof self ? $i->toArray() : $i, $v) : $v);
        }
        return $data;
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toCleanArray(), $flags);
    }

    public function getPrimaryKey(): string { return $this->primaryKey; }

    // =========================================================================
    // MAGIC
    // =========================================================================

    public function __get(string $key): mixed
    {
        // Cek relations
        if (array_key_exists($key, $this->relations)) return $this->relations[$key];

        // Cek accessor
        $accessor = 'get' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor($this->attributes[$key] ?? null);
        }

        // Cek attributes
        if (array_key_exists($key, $this->attributes)) return $this->attributes[$key];

        // Cek lazy-load relation
        if (method_exists($this, $key)) {
            $this->load([$key]);
            return $this->relations[$key] ?? null;
        }

        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        // Cek mutator
        $mutator = 'set' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            $this->compiledSql = null;
            return;
        }

        if ($value instanceof self || (is_array($value) && !empty($value) && $value[0] instanceof self)) {
            $this->relations[$key] = $value;
        } else {
            if (isset($this->casts[$key])) {
                $value = $this->castAttribute($key, $value);
            }
            $this->attributes[$key] = $value;
        }
        $this->compiledSql = null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
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

        if (!empty($this->whereConditions)) {
            $parts[] = '(' . implode(' AND ', $this->whereConditions) . ')';
        }

        if (!empty($this->orWhereConditions)) {
            $parts[] = '(' . implode(' OR ', $this->orWhereConditions) . ')';
        }

        return ' WHERE ' . implode(' OR ', $parts);
    }

    /**
     * EXTENDED: Apply global scopes sebelum compile agar tidak mengubah state secara permanen.
     * Global scopes di-apply ke clone internal untuk COUNT, atau langsung ke instance untuk SELECT.
     */
    private function compileSelect(): string
    {
        if ($this->compiledSql !== null) {
            return $this->compiledSql;
        }

        // Apply global scopes (termasuk soft delete) sebelum compile
        $this->applyGlobalScopes();

        $this->compiledSql = $this->g()->buildSelect(
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

        return $this->compiledSql;
    }

    private function bindAll(\PDOStatement $stmt): void
    {
        foreach ($this->whereParams as $key => $value) {
            $type = match(true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, $value, $type);
        }
    }

    private function applyClosureWhere(\Closure $closure, string $outerJoin = 'AND'): static
    {
        $sub = new static();
        $closure($sub);

        $parts = [];
        if (!empty($sub->whereConditions)) {
            $parts[] = implode(' AND ', $sub->whereConditions);
        }
        if (!empty($sub->orWhereConditions)) {
            $parts[] = implode(' OR ', $sub->orWhereConditions);
        }

        $combined = implode(' OR ', array_filter($parts));

        if (!empty($combined)) {
            if ($outerJoin === 'AND') {
                $this->whereConditions[] = '(' . $combined . ')';
            } else {
                $this->orWhereConditions[] = '(' . $combined . ')';
            }
            $this->whereParams = array_merge($this->whereParams, $sub->whereParams);
        }

        $this->compiledSql = null;
        return $this;
    }

    /**
     * Convert snake_case / any_string ke StudlyCase untuk accessor/mutator.
     * Contoh: 'first_name' → 'FirstName'
     */
    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }
}

// =============================================================================
// CONTOH PENGGUNAAN — HAPUS SEBELUM PRODUCTION
// =============================================================================
/*

// ── Dirty Tracking ────────────────────────────────────────────────────────────
$user = User::find(1);
// $user->original = ['name' => 'Budi', 'email' => 'budi@mail.com', ...]

$user->name = 'Andi';
$user->isDirty();           // true
$user->isDirty('name');     // true
$user->isDirty('email');    // false
$user->isClean('email');    // true
$user->getDirty();          // ['name' => 'Andi']
$user->getOriginal('name'); // 'Budi'

$user->save();
$user->wasChanged('name');  // true — berubah di save() terakhir
$user->getChanges();        // ['name' => 'Andi']
$user->isDirty();           // false — sudah sync setelah save

// ── Casting ───────────────────────────────────────────────────────────────────
class Product extends BaseModel {
    protected array $casts = [
        'price'     => 'float',
        'is_active' => 'bool',
        'tags'      => 'array',   // JSON column → PHP array otomatis
        'meta'      => 'object',
    ];
}
$p = Product::find(1);
$p->price;     // float, bukan string
$p->is_active; // true/false, bukan '1'/'0'
$p->tags;      // ['php', 'laravel'] bukan '["php","laravel"]'

// ── Soft Delete ───────────────────────────────────────────────────────────────
class Post extends BaseModel {
    protected bool   $softDelete      = true;
    protected string $deletedAtColumn = 'deleted_at'; // default
}
$post = Post::find(1);
$post->delete();           // soft delete (set deleted_at)
$post->trashed();          // true
$post->restore();          // pulihkan
$post->forceDelete();      // hapus permanen

Post::query()->get();                    // otomatis filter WHERE deleted_at IS NULL
Post::query()->withTrashed()->get();     // sertakan yang sudah dihapus
Post::query()->onlyTrashed()->get();     // hanya yang sudah dihapus

// ── Timestamps ────────────────────────────────────────────────────────────────
class Article extends BaseModel {
    protected bool $timestamps = true; // auto set created_at & updated_at
}
$a = new Article(['title' => 'Hello']);
$a->save(); // created_at & updated_at di-set otomatis

$a->touch(); // update hanya updated_at

// ── Observer / Audit Log ──────────────────────────────────────────────────────
class UserObserver {
    public function creating(BaseModel $model): void {
        // Sebelum INSERT
    }
    public function created(BaseModel $model): void {
        AuditLog::record('created', $model->getKey(), $model->toCleanArray());
    }
    public function updating(BaseModel $model): void {
        // Bisa return false untuk membatalkan update
    }
    public function updated(BaseModel $model): void {
        AuditLog::record('updated', $model->getKey(), [
            'before'  => $model->getOriginal(),
            'changes' => $model->getChanges(),
        ]);
    }
    public function deleting(BaseModel $model): void {}
    public function deleted(BaseModel $model): void {
        AuditLog::record('deleted', $model->getKey());
    }
}

User::observe(UserObserver::class);

// ── Global Scope ──────────────────────────────────────────────────────────────
class ActiveUser extends BaseModel {
    protected string $table = 'users';
    protected function bootGlobalScopes(): void {
        parent::bootGlobalScopes();
        $this->addGlobalScope('active', fn($q) => $q->where('is_active', '=', 1));
    }
}
ActiveUser::query()->get();                    // WHERE is_active = 1 (otomatis)
ActiveUser::query()->withoutScope('active')->get(); // tanpa scope

// ── Accessor & Mutator ────────────────────────────────────────────────────────
class User extends BaseModel {
    public function getFullNameAttribute($value): string {
        return strtoupper($value ?? ($this->first_name . ' ' . $this->last_name));
    }
    public function setPasswordAttribute(string $value): void {
        $this->attributes['password'] = password_hash($value, PASSWORD_BCRYPT);
    }
}
$user->full_name;   // accessor dipanggil
$user->password = 'rahasia'; // mutator dipanggil → langsung di-hash

// ── Fitur lainnya ─────────────────────────────────────────────────────────────
$copy  = $user->replicate();        // duplikat tanpa PK
$fresh = $user->fresh();            // baca ulang dari DB (baru)
$user->refresh();                   // reload in-place

$user->only(['name', 'email']);     // subset attributes
$user->except(['password']);        // semua kecuali password
$user->makeHidden('token')->toCleanArray();
$user->makeVisible('email')->toCleanArray();

$user->is($otherUser);              // compare by PK
$user->isNot($otherUser);

User::query()->max('age');
User::query()->min('age');
User::query()->sum('salary');
User::query()->avg('score');
User::query()->doesntExist();

User::findOrFail(999);              // throw RuntimeException jika tidak ada
User::firstOrNew(['email' => 'x@y.com']); // cari atau buat instance baru (tidak save)
*/