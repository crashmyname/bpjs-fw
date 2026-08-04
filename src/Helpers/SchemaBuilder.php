<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Helpers\ColumnDefinition;

/**
 * SchemaBuilder — Fluent query builder untuk DDL (Data Definition Language).
 *
 * Mendukung driver: mysql, pgsql (PostgreSQL), sqlsrv (SQL Server), sqlite.
 *
 * Cara penggunaan:
 *   $schema = new SchemaBuilder('users');
 *   $schema->id()
 *          ->string('name', 100)->notNullable()
 *          ->string('email')->notNullable()->unique()
 *          ->boolean('is_active')->default(1)
 *          ->timestamps();
 *   echo $schema->buildCreateSQL();
 *
 * @version 2.0
 */
class SchemaBuilder
{
    protected string $table;
    protected string $driver;

    /** @var ColumnDefinition[] */
    protected array $columns = [];

    /** @var string[] */
    protected array $constraints     = [];

    /** @var string[] */
    protected array $primaryKeys     = [];

    /** @var string[] */
    protected array $tableOptions    = [];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * @param string      $table   Nama tabel
     * @param string|null $driver  Driver DB; null = baca dari env('DB_CONNECTION')
     */
    public function __construct(string $table, ?string $driver = null)
    {
        $this->table  = $table;
        $this->driver = $driver ?? (function_exists('env') ? env('DB_CONNECTION', 'mysql') : 'mysql');
    }

    // =========================================================================
    // WRAP / UTILITY
    // =========================================================================

    protected function wrap(string $value): string
    {
        return match ($this->driver) {
            'mysql'  => "`{$value}`",
            'pgsql'  => "\"{$value}\"",
            'sqlsrv' => "[{$value}]",
            default  => $value,
        };
    }

    protected function wrapList(array $columns): string
    {
        return implode(', ', array_map(fn($c) => $this->wrap($c), $columns));
    }

    // =========================================================================
    // SHORTCUT — Primary Key & Common
    // =========================================================================

    /**
     * Kolom ID auto-increment BIGINT (primary key).
     * MySQL: BIGINT AUTO_INCREMENT PRIMARY KEY
     * PostgreSQL: BIGSERIAL PRIMARY KEY
     * SQL Server: BIGINT IDENTITY(1,1) PRIMARY KEY
     */
    public function id(string $name = 'id'): static
    {
        $column = new ColumnDefinition('BIGINT', $name);
        $column->autoIncrement()->primary();
        $this->columns[] = $column;
        return $this;
    }

    /**
     * Kolom UUID sebagai primary key.
     * MySQL/SQLite: CHAR(36) PRIMARY KEY
     * PostgreSQL: UUID PRIMARY KEY
     * SQL Server: UNIQUEIDENTIFIER PRIMARY KEY
     */
    public function uuidPrimary(string $name = 'id'): static
    {
        $column = new ColumnDefinition('CHAR(36)', $name);
        $column->primary()->notNullable();
        $this->columns[] = $column;
        return $this;
    }

    /**
     * Composite primary key.
     * Memanggil buildCreateSQL() akan menambahkan PRIMARY KEY (col1, col2).
     */
    public function primaryKey(array $columns): static
    {
        $this->primaryKeys = $columns;
        return $this;
    }

    /**
     * Auto-add created_at dan updated_at (DATETIME / TIMESTAMP, nullable).
     */
    public function timestamps(): static
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
        return $this;
    }

    /**
     * Auto-add deleted_at untuk soft delete.
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->dateTime($column)->nullable();
        return $this;
    }

    /**
     * Shortcut: created_at + updated_at + deleted_at.
     */
    public function timestampsWithSoftDelete(): static
    {
        $this->timestamps();
        $this->softDeletes();
        return $this;
    }

    /**
     * Pasangan kolom untuk polymorphic relation: {name}_id + {name}_type.
     */
    public function morphs(string $name): static
    {
        $this->unsignedBigInteger("{$name}_id")->notNullable();
        $this->string("{$name}_type", 191)->notNullable();
        $this->index(["{$name}_id", "{$name}_type"]);
        return $this;
    }

    /**
     * Nullable morphs.
     */
    public function nullableMorphs(string $name): static
    {
        $this->unsignedBigInteger("{$name}_id")->nullable();
        $this->string("{$name}_type", 191)->nullable();
        $this->index(["{$name}_id", "{$name}_type"]);
        return $this;
    }

    /**
     * Kolom remember_token (VARCHAR 100, nullable). Untuk auth.
     */
    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    // =========================================================================
    // STRING TYPES
    // =========================================================================

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn("VARCHAR($length)", $name);
    }

    public function char(string $name, int $length = 1): ColumnDefinition
    {
        return $this->addColumn("CHAR($length)", $name);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn("TEXT", $name);
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn("MEDIUMTEXT", $name);
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn("LONGTEXT", $name);
    }

    /**
     * UUID kolom (bukan primary key).
     * MySQL/SQLite: CHAR(36), PostgreSQL: UUID, SQL Server: UNIQUEIDENTIFIER
     */
    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn("CHAR(36)", $name);
    }

    /**
     * IP Address: VARCHAR(45) — mendukung IPv4 dan IPv6.
     */
    public function ipAddress(string $name = 'ip_address'): ColumnDefinition
    {
        return $this->string($name, 45);
    }

    /**
     * MAC Address: VARCHAR(17).
     */
    public function macAddress(string $name = 'mac_address'): ColumnDefinition
    {
        return $this->string($name, 17);
    }

    // =========================================================================
    // NUMERIC TYPES
    // =========================================================================

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn("INT", $name);
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->addColumn("INT", $name)->unsigned();
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn("BIGINT", $name);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn("BIGINT", $name)->unsigned();
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn("SMALLINT", $name);
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn("TINYINT", $name);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn("TINYINT(1)", $name);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn("FLOAT", $name);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn("DOUBLE", $name);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn("DECIMAL($precision,$scale)", $name);
    }

    public function unsignedDecimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn("DECIMAL($precision,$scale)", $name)->unsigned();
    }

    // =========================================================================
    // DATE / TIME TYPES
    // =========================================================================

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn("DATE", $name);
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn("DATETIME", $name);
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn("TIME", $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn("TIMESTAMP", $name);
    }

    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn("YEAR", $name);
    }

    // =========================================================================
    // SPECIAL TYPES
    // =========================================================================

    public function enum(string $name, array $values): ColumnDefinition
    {
        $escaped = array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $values);
        return $this->addColumn("ENUM(" . implode(',', $escaped) . ")", $name);
    }

    public function set(string $name, array $values): ColumnDefinition
    {
        $escaped = array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $values);
        return $this->addColumn("SET(" . implode(',', $escaped) . ")", $name);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn("JSON", $name);
    }

    public function blob(string $name): ColumnDefinition
    {
        return $this->addColumn("BLOB", $name);
    }

    public function binary(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn("BINARY($length)", $name);
    }

    /**
     * Kolom raw SQL — gunakan jika tipe tidak tersedia di atas.
     *
     * @param string $name       Nama kolom
     * @param string $rawType    Tipe + modifier dalam SQL mentah
     *                           Contoh: 'GEOMETRY NOT NULL', 'POINT DEFAULT NULL'
     */
    public function rawColumn(string $name, string $rawType): static
    {
        // Masukkan sebagai raw SQL langsung ke constraints (tidak melalui ColumnDefinition)
        $this->constraints[] = $this->wrap($name) . " " . $rawType;
        return $this;
    }

    // =========================================================================
    // CONSTRAINTS
    // =========================================================================

    /**
     * Tambahkan INDEX.
     *
     * @param string|array $columns   Kolom yang diindex
     * @param string|null  $name      Nama index kustom; null = auto-generate
     */
    public function index(string|array $columns, ?string $name = null): static
    {
        $cols    = (array) $columns;
        $idxName = $name ?? "idx_{$this->table}_" . implode('_', $cols);
        $wrapped = $this->wrapList($cols);
        $this->constraints[] = "INDEX " . $this->wrap($idxName) . " ($wrapped)";
        return $this;
    }

    /**
     * Tambahkan UNIQUE constraint (level tabel).
     *
     * @param string|array $columns   Kolom yang diindex unique
     * @param string|null  $name      Nama index kustom
     */
    public function unique(string|array $columns, ?string $name = null): static
    {
        $cols    = (array) $columns;
        $idxName = $name ?? "uniq_{$this->table}_" . implode('_', $cols);
        $wrapped = $this->wrapList($cols);
        $this->constraints[] = "UNIQUE INDEX " . $this->wrap($idxName) . " ($wrapped)";
        return $this;
    }

    /**
     * Tambahkan FULLTEXT index (MySQL only).
     *
     * @param string|array $columns
     * @param string|null  $name
     */
    public function fullText(string|array $columns, ?string $name = null): static
    {
        if ($this->driver !== 'mysql') return $this;

        $cols    = (array) $columns;
        $idxName = $name ?? "ft_{$this->table}_" . implode('_', $cols);
        $wrapped = $this->wrapList($cols);
        $this->constraints[] = "FULLTEXT INDEX " . $this->wrap($idxName) . " ($wrapped)";
        return $this;
    }

    /**
     * Tambahkan SPATIAL index (MySQL only).
     */
    public function spatialIndex(string $column, ?string $name = null): static
    {
        if ($this->driver !== 'mysql') return $this;

        $idxName = $name ?? "spatial_{$this->table}_{$column}";
        $this->constraints[] = "SPATIAL INDEX " . $this->wrap($idxName) . " (" . $this->wrap($column) . ")";
        return $this;
    }

    // =========================================================================
    // BUILD SQL
    // =========================================================================

    /**
     * Build SQL CREATE TABLE.
     *
     * @param array $options  Opsi tambahan:
     *   - engine  (string) MySQL engine: InnoDB, MyISAM, dll. Default: InnoDB
     *   - charset (string) MySQL charset. Default: utf8mb4
     *   - collate (string) MySQL collation. Default: utf8mb4_unicode_ci
     *   - comment (string) MySQL table comment
     */
    public function buildCreateSQL(array $options = []): string
    {
        $columnsSQL = [];
        $foreignSQL = [];

        foreach ($this->columns as $col) {
            $built = $col->build($this->driver);

            if (str_contains($built, "FOREIGN KEY")) {
                [$main, $fk] = explode(",\n    FOREIGN KEY", $built, 2);
                $columnsSQL[] = trim($main);
                $foreignSQL[] = "FOREIGN KEY" . $fk;
            } else {
                $columnsSQL[] = $built;
            }
        }

        // Composite primary key
        if (!empty($this->primaryKeys)) {
            $columnsSQL[] = "PRIMARY KEY (" . $this->wrapList($this->primaryKeys) . ")";
        }

        $all  = array_merge($columnsSQL, $this->constraints, $foreignSQL);
        $body = implode(",\n    ", array_filter($all));
        $sql  = "CREATE TABLE " . $this->wrap($this->table) . " (\n    {$body}\n)";

        // MySQL-specific table options
        if ($this->driver === 'mysql') {
            $engine  = $options['engine']  ?? 'InnoDB';
            $charset = $options['charset'] ?? 'utf8mb4';
            $collate = $options['collate'] ?? 'utf8mb4_unicode_ci';
            $sql    .= " ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}";

            if (isset($options['comment'])) {
                $escaped = str_replace("'", "''", $options['comment']);
                $sql .= " COMMENT='{$escaped}'";
            }
        }

        return $sql;
    }

    /**
     * Build SQL DROP TABLE IF EXISTS.
     */
    public function buildDropSQL(): string
    {
        return "DROP TABLE IF EXISTS " . $this->wrap($this->table);
    }

    /**
     * Build SQL TRUNCATE TABLE.
     */
    public function buildTruncateSQL(): string
    {
        return match ($this->driver) {
            'sqlite' => "DELETE FROM " . $this->wrap($this->table),
            default  => "TRUNCATE TABLE " . $this->wrap($this->table),
        };
    }

    /**
     * Build SQL RENAME TABLE.
     *
     * @param string $newName  Nama tabel baru
     */
    public function buildRenameSQL(string $newName): string
    {
        $old = $this->wrap($this->table);
        $new = $this->wrap($newName);

        return match ($this->driver) {
            'sqlsrv' => "EXEC sp_rename {$old}, {$new}",
            default  => "RENAME TABLE {$old} TO {$new}",
        };
    }

    /**
     * Build SQL ALTER TABLE — tambah kolom.
     *
     * Panggil setelah mendefinisikan kolom via metode di atas.
     * Hanya kolom terakhir yang ditambahkan yang akan di-alter.
     *
     * @param ColumnDefinition|null $column  Kolom yang ditambahkan; null = ambil kolom terakhir
     */
    public function buildAddColumnSQL(?ColumnDefinition $column = null): string
    {
        $col = $column ?? end($this->columns);
        if (!$col) throw new \LogicException("Tidak ada kolom yang didefinisikan.");

        $built = $col->build($this->driver);
        return "ALTER TABLE " . $this->wrap($this->table) . " ADD COLUMN {$built}";
    }

    /**
     * Build SQL ALTER TABLE — drop kolom.
     *
     * @param string $columnName  Nama kolom yang akan dihapus
     */
    public function buildDropColumnSQL(string $columnName): string
    {
        return "ALTER TABLE " . $this->wrap($this->table)
            . " DROP COLUMN " . $this->wrap($columnName);
    }

    /**
     * Build SQL ALTER TABLE — rename kolom.
     *
     * @param string $from  Nama kolom lama
     * @param string $to    Nama kolom baru
     */
    public function buildRenameColumnSQL(string $from, string $to): string
    {
        return match ($this->driver) {
            'mysql', 'pgsql', 'sqlite' =>
                "ALTER TABLE " . $this->wrap($this->table)
                . " RENAME COLUMN " . $this->wrap($from)
                . " TO " . $this->wrap($to),

            'sqlsrv' =>
                "EXEC sp_rename '" . $this->table . "." . $from . "', '{$to}', 'COLUMN'",
        };
    }

    /**
     * Build SQL ALTER TABLE — modifikasi definisi kolom.
     *
     * @param ColumnDefinition $column  Kolom baru (nama sama dengan yang dimodifikasi)
     */
    public function buildModifyColumnSQL(ColumnDefinition $column): string
    {
        $built = $column->build($this->driver);

        return match ($this->driver) {
            'pgsql'  => "ALTER TABLE " . $this->wrap($this->table) . " ALTER COLUMN {$built}",
            'sqlsrv' => "ALTER TABLE " . $this->wrap($this->table) . " ALTER COLUMN {$built}",
            default  => "ALTER TABLE " . $this->wrap($this->table) . " MODIFY COLUMN {$built}",
        };
    }

    /**
     * Build SQL ADD INDEX.
     *
     * @param string|array $columns
     * @param string|null  $indexName
     */
    public function buildAddIndexSQL(string|array $columns, ?string $indexName = null): string
    {
        $cols    = (array) $columns;
        $idxName = $indexName ?? "idx_{$this->table}_" . implode('_', $cols);
        $wrapped = $this->wrapList($cols);

        return "CREATE INDEX " . $this->wrap($idxName)
            . " ON " . $this->wrap($this->table) . " ({$wrapped})";
    }

    /**
     * Build SQL DROP INDEX.
     *
     * @param string $indexName  Nama index yang akan dihapus
     */
    public function buildDropIndexSQL(string $indexName): string
    {
        return match ($this->driver) {
            'mysql'  => "DROP INDEX " . $this->wrap($indexName) . " ON " . $this->wrap($this->table),
            'sqlsrv' => "DROP INDEX " . $this->wrap($this->table) . "." . $this->wrap($indexName),
            default  => "DROP INDEX " . $this->wrap($indexName),
        };
    }

    /**
     * Build SQL ADD FOREIGN KEY.
     *
     * @param string      $column      Kolom lokal
     * @param string      $refTable    Tabel yang direferensikan
     * @param string      $refColumn   Kolom yang direferensikan
     * @param string|null $fkName      Nama foreign key; null = auto-generate
     * @param string      $onDelete    CASCADE | SET NULL | RESTRICT | NO ACTION
     * @param string      $onUpdate    CASCADE | SET NULL | RESTRICT | NO ACTION
     */
    public function buildAddForeignKeySQL(
        string $column,
        string $refTable,
        string $refColumn,
        ?string $fkName = null,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT'
    ): string {
        $name = $fkName ?? "fk_{$this->table}_{$column}";
        return "ALTER TABLE " . $this->wrap($this->table)
            . " ADD CONSTRAINT " . $this->wrap($name)
            . " FOREIGN KEY (" . $this->wrap($column) . ")"
            . " REFERENCES " . $this->wrap($refTable) . "(" . $this->wrap($refColumn) . ")"
            . " ON DELETE " . strtoupper($onDelete)
            . " ON UPDATE " . strtoupper($onUpdate);
    }

    /**
     * Build SQL DROP FOREIGN KEY.
     */
    public function buildDropForeignKeySQL(string $fkName): string
    {
        return match ($this->driver) {
            'mysql'  => "ALTER TABLE " . $this->wrap($this->table) . " DROP FOREIGN KEY " . $this->wrap($fkName),
            'pgsql',
            'sqlite' => "ALTER TABLE " . $this->wrap($this->table) . " DROP CONSTRAINT " . $this->wrap($fkName),
            'sqlsrv' => "ALTER TABLE " . $this->wrap($this->table) . " DROP CONSTRAINT " . $this->wrap($fkName),
            default  => "ALTER TABLE " . $this->wrap($this->table) . " DROP FOREIGN KEY " . $this->wrap($fkName),
        };
    }

    // =========================================================================
    // INTERNAL HELPER
    // =========================================================================

    protected function addColumn(string $type, string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($type, $name);
        $this->columns[] = $column;
        return $column;
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getTable(): string  { return $this->table; }
    public function getDriver(): string { return $this->driver; }

    /** @return ColumnDefinition[] */
    public function getColumns(): array { return $this->columns; }
}