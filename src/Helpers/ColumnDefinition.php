<?php

namespace Bpjs\Framework\Helpers;

/**
 * ColumnDefinition — Representasi satu kolom dalam skema database.
 *
 * Mendukung driver: mysql, pgsql (PostgreSQL), sqlsrv (SQL Server), sqlite.
 *
 * Contoh penggunaan:
 *   $col = new ColumnDefinition('VARCHAR(255)', 'email');
 *   $col->notNullable()->unique()->default('');
 *   echo $col->build('mysql');
 *   // `email` VARCHAR(255) NOT NULL UNIQUE DEFAULT ''
 *
 * @version 2.0
 */
class ColumnDefinition
{
    protected string  $name;
    protected string  $type;
    protected array   $modifiers    = [];
    protected bool    $nullable     = false;
    protected bool    $unsigned     = false;
    protected bool    $hasPrimary   = false;
    protected bool    $hasAutoInc   = false;

    // Foreign key
    protected ?string $fkRefColumn  = null;
    protected ?string $fkRefTable   = null;
    protected ?string $fkOnDelete   = null;
    protected ?string $fkOnUpdate   = null;

    // Extras
    protected ?string $comment      = null;
    protected ?string $afterColumn  = null;
    protected bool    $first        = false;
    protected ?string $check        = null;
    protected ?string $charset      = null;
    protected ?string $collation    = null;
    protected ?string $generatedAs  = null;  // generated column expression

    public function __construct(string $type, string $name)
    {
        $this->type = strtoupper($type);
        $this->name = $name;
    }

    // =========================================================================
    // NULLABILITY
    // =========================================================================

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;
        return $this;
    }

    public function notNullable(): static
    {
        $this->nullable = false;
        return $this;
    }

    // =========================================================================
    // MODIFIERS
    // =========================================================================

    public function default(mixed $value): static
    {
        if ($value === null) {
            $this->modifiers['default'] = "DEFAULT NULL";
        } elseif (is_bool($value)) {
            $this->modifiers['default'] = "DEFAULT " . ($value ? '1' : '0');
        } elseif (is_int($value) || is_float($value)) {
            $this->modifiers['default'] = "DEFAULT {$value}";
        } elseif (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            $this->modifiers['default'] = "DEFAULT CURRENT_TIMESTAMP";
        } else {
            // Escape single quotes dalam string
            $escaped = str_replace("'", "''", (string) $value);
            $this->modifiers['default'] = "DEFAULT '{$escaped}'";
        }
        return $this;
    }

    public function unique(): static
    {
        $this->modifiers['unique'] = "UNIQUE";
        return $this;
    }

    public function autoIncrement(): static
    {
        $this->hasAutoInc = true;
        return $this;
    }

    public function primary(): static
    {
        $this->hasPrimary = true;
        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;
        return $this;
    }

    /**
     * Tambahkan komentar pada kolom.
     * Hanya didukung MySQL.
     */
    public function comment(string $text): static
    {
        $this->comment = $text;
        return $this;
    }

    /**
     * Tambahkan kolom setelah kolom lain (MySQL only: ALTER TABLE).
     */
    public function after(string $column): static
    {
        $this->afterColumn = $column;
        return $this;
    }

    /**
     * Tambahkan kolom di posisi pertama (MySQL only: ALTER TABLE).
     */
    public function first(): static
    {
        $this->first = true;
        return $this;
    }

    /**
     * Tambahkan CHECK constraint pada kolom.
     * Didukung MySQL 8+, PostgreSQL, SQL Server, SQLite.
     *
     * @param string $expression  Contoh: "age >= 0 AND age <= 150"
     */
    public function check(string $expression): static
    {
        $this->check = $expression;
        return $this;
    }

    /**
     * Set charset kolom (MySQL only).
     * Contoh: 'utf8mb4'
     */
    public function charset(string $charset): static
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set collation kolom (MySQL only).
     * Contoh: 'utf8mb4_unicode_ci'
     */
    public function collation(string $collation): static
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Generated / computed column (MySQL 5.7+, PostgreSQL, SQL Server).
     * Jika $stored = true: STORED/PERSISTED; false: VIRTUAL.
     *
     * @param string $expression  Contoh: "CONCAT(first_name, ' ', last_name)"
     */
    public function generatedAs(string $expression, bool $stored = false): static
    {
        $this->generatedAs = $expression;
        $this->modifiers['generated_stored'] = $stored ? 'stored' : 'virtual';
        return $this;
    }

    // =========================================================================
    // FOREIGN KEY
    // =========================================================================

    /**
     * Tentukan kolom yang direferensikan (di tabel lain).
     *
     * @param string $column  Nama kolom di tabel yang direferensikan
     */
    public function references(string $column): static
    {
        $this->fkRefColumn = $column;
        return $this;
    }

    /**
     * Tentukan tabel yang direferensikan.
     *
     * @param string $table  Nama tabel yang direferensikan
     */
    public function on(string $table): static
    {
        $this->fkRefTable = $table;
        return $this;
    }

    /**
     * Aksi saat baris di tabel parent dihapus.
     * Nilai: CASCADE, SET NULL, RESTRICT, NO ACTION, SET DEFAULT
     */
    public function onDelete(string $action): static
    {
        $this->fkOnDelete = strtoupper($action);
        return $this;
    }

    /**
     * Aksi saat baris di tabel parent diupdate.
     * Nilai: CASCADE, SET NULL, RESTRICT, NO ACTION, SET DEFAULT
     */
    public function onUpdate(string $action): static
    {
        $this->fkOnUpdate = strtoupper($action);
        return $this;
    }

    // =========================================================================
    // BUILD
    // =========================================================================

    /**
     * Kompilasi definisi kolom menjadi SQL string.
     *
     * @param string $driver  mysql | pgsql | sqlsrv | sqlite
     * @return string
     */
    public function build(string $driver): string
    {
        $wrappedName = $this->wrapId($this->name, $driver);
        $type        = $this->resolveType($driver);

        // Generated / computed column
        if ($this->generatedAs !== null) {
            return $this->buildGenerated($wrappedName, $type, $driver);
        }

        $sql = "{$wrappedName} {$type}";

        // UNSIGNED (MySQL only)
        if ($this->unsigned && $driver === 'mysql') {
            $sql .= " UNSIGNED";
        }

        // CHARACTER SET & COLLATE (MySQL only)
        if ($this->charset && $driver === 'mysql') {
            $sql .= " CHARACTER SET {$this->charset}";
        }
        if ($this->collation && $driver === 'mysql') {
            $sql .= " COLLATE {$this->collation}";
        }

        // NULL / NOT NULL (skip untuk SERIAL / IDENTITY types)
        if (!$this->isAutoType($type)) {
            $sql .= $this->nullable ? " NULL" : " NOT NULL";
        }

        // DEFAULT
        if (isset($this->modifiers['default'])) {
            $sql .= " " . $this->modifiers['default'];
        }

        // AUTO_INCREMENT / IDENTITY (MySQL)
        if ($this->hasAutoInc && $driver === 'mysql') {
            $sql .= " AUTO_INCREMENT";
        }

        // PRIMARY KEY
        if ($this->hasPrimary) {
            $sql .= " PRIMARY KEY";
        }

        // UNIQUE
        if (isset($this->modifiers['unique'])) {
            $sql .= " UNIQUE";
        }

        // CHECK
        if ($this->check !== null) {
            $sql .= " CHECK ({$this->check})";
        }

        // COMMENT (MySQL only)
        if ($this->comment !== null && $driver === 'mysql') {
            $escaped = str_replace("'", "''", $this->comment);
            $sql .= " COMMENT '{$escaped}'";
        }

        // AFTER / FIRST (MySQL ALTER TABLE only)
        if ($this->afterColumn !== null && $driver === 'mysql') {
            $sql .= " AFTER " . $this->wrapId($this->afterColumn, $driver);
        } elseif ($this->first && $driver === 'mysql') {
            $sql .= " FIRST";
        }

        // FOREIGN KEY
        if ($this->fkRefColumn !== null && $this->fkRefTable !== null) {
            return $this->buildWithForeignKey($sql, $driver);
        }

        return $sql;
    }

    private function buildGenerated(string $wrappedName, string $type, string $driver): string
    {
        $expr   = $this->generatedAs;
        $stored = ($this->modifiers['generated_stored'] ?? 'virtual') === 'stored';

        return match ($driver) {
            'mysql'  => "{$wrappedName} {$type} AS ({$expr}) " . ($stored ? 'STORED' : 'VIRTUAL'),
            'pgsql'  => "{$wrappedName} {$type} GENERATED ALWAYS AS ({$expr}) STORED",
            'sqlsrv' => "{$wrappedName} AS ({$expr})" . ($stored ? ' PERSISTED' : ''),
            default  => "{$wrappedName} {$type} AS ({$expr})",
        };
    }

    private function buildWithForeignKey(string $colSql, string $driver): string
    {
        $fkCol   = $this->wrapId($this->name, $driver);
        $refTable = $this->wrapId($this->fkRefTable, $driver);
        $refCol  = $this->wrapId($this->fkRefColumn, $driver);

        $fk = "FOREIGN KEY ({$fkCol}) REFERENCES {$refTable}({$refCol})";

        if ($this->fkOnDelete) {
            $fk .= " ON DELETE {$this->fkOnDelete}";
        }
        if ($this->fkOnUpdate) {
            $fk .= " ON UPDATE {$this->fkOnUpdate}";
        }

        return "{$colSql},\n    {$fk}";
    }

    // =========================================================================
    // TYPE MAPPING
    // =========================================================================

    protected function resolveType(string $driver): string
    {
        $type = $this->type;

        return match ($driver) {

            'pgsql' => match (true) {
                $this->hasAutoInc && str_contains($type, 'BIGINT') => 'BIGSERIAL',
                $this->hasAutoInc && str_contains($type, 'INT')    => 'SERIAL',
                default => match ($type) {
                    'INT'                    => 'INTEGER',
                    'TINYINT(1)', 'BOOLEAN'  => 'BOOLEAN',
                    'DATETIME', 'TIMESTAMP'  => 'TIMESTAMP',
                    'DOUBLE'                 => 'DOUBLE PRECISION',
                    'FLOAT'                  => 'REAL',
                    'LONGTEXT', 'MEDIUMTEXT' => 'TEXT',
                    'BLOB'                   => 'BYTEA',
                    'JSON'                   => 'JSONB',
                    'YEAR'                   => 'SMALLINT',
                    'TINYINT'                => 'SMALLINT',
                    'SMALLINT'               => 'SMALLINT',
                    'CHAR(36)'               => 'UUID',
                    default                  => $type,
                }
            },

            'sqlsrv' => match (true) {
                $this->hasAutoInc => 'BIGINT IDENTITY(1,1)',
                default => match ($type) {
                    'INT'                    => 'INT',
                    'BIGINT'                 => 'BIGINT',
                    'TINYINT', 'TINYINT(1)' => 'BIT',
                    'BOOLEAN'                => 'BIT',
                    'SMALLINT'               => 'SMALLINT',
                    'LONGTEXT', 'MEDIUMTEXT',
                    'TEXT'                   => 'NVARCHAR(MAX)',
                    'DATETIME'               => 'DATETIME2',
                    'TIMESTAMP'              => 'DATETIME2',
                    'JSON'                   => 'NVARCHAR(MAX)',
                    'BLOB'                   => 'VARBINARY(MAX)',
                    'DOUBLE'                 => 'FLOAT',
                    'YEAR'                   => 'SMALLINT',
                    'CHAR(36)'               => 'UNIQUEIDENTIFIER',
                    default                  => $type,
                }
            },

            'sqlite' => match ($type) {
                'INT', 'BIGINT', 'TINYINT', 'SMALLINT',
                'TINYINT(1)', 'BOOLEAN', 'YEAR'         => 'INTEGER',
                'VARCHAR', 'CHAR', 'MEDIUMTEXT',
                'LONGTEXT', 'ENUM', 'SET', 'UUID',
                'CHAR(36)'                              => 'TEXT',
                'DATETIME', 'TIMESTAMP'                 => 'DATETIME',
                'DOUBLE', 'FLOAT'                       => 'REAL',
                'BLOB'                                  => 'BLOB',
                default                                 => $type,
            },

            default => $type, // mysql — gunakan type langsung
        };
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function wrapId(string $value, string $driver): string
    {
        return match ($driver) {
            'mysql'  => "`{$value}`",
            'pgsql'  => "\"{$value}\"",
            'sqlsrv' => "[{$value}]",
            default  => $value,
        };
    }

    protected function isAutoType(string $type): bool
    {
        return str_contains($type, 'SERIAL')
            || str_contains($type, 'IDENTITY');
    }

    // =========================================================================
    // GETTERS (untuk SchemaBuilder)
    // =========================================================================

    public function getName(): string       { return $this->name; }
    public function getType(): string       { return $this->type; }
    public function isNullable(): bool      { return $this->nullable; }
    public function hasForeignKey(): bool   { return $this->fkRefColumn !== null && $this->fkRefTable !== null; }
}