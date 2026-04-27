<?php

namespace Bpjs\Framework\Database\Grammar;

class SqlServerGrammar implements GrammarInterface
{
    public function driverName(): string { return 'sqlsrv'; }

    public function wrapIdentifier(string $name): string
    {
        if ($name === '*') return '*';
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    public function wrapTable(string $table): string
    {
        // support [schema].[table] atau schema.table
        return implode('.', array_map(
            fn($p) => $this->wrapIdentifier($p),
            explode('.', $table)
        ));
    }

    /**
     * SQL Server pakai OFFSET … FETCH NEXT … ROWS ONLY (SQL Server 2012+).
     * Jika ada LIMIT tanpa OFFSET, kita pakai TOP di buildSelect().
     * Jika ada OFFSET, wajib ada ORDER BY — dihandle di buildSelect().
     */
    public function buildLimitOffset(?int $limit, ?int $offset): string
    {
        // Ditangani di buildSelect() agar bisa sisipkan TOP atau FETCH NEXT
        return '';
    }

    public function buildSelect(
        string $distinct, array $columns, string $table,
        array $joins, string $whereClause, string $groupBy,
        array $orderBy, ?int $limit, ?int $offset
    ): string {
        $cols = implode(', ', $columns);

        // Kasus: LIMIT tanpa OFFSET → pakai TOP (kompatibel SQL Server lama)
        if ($limit !== null && $offset === null) {
            $sql = "SELECT {$distinct} TOP {$limit} {$cols} FROM {$this->wrapTable($table)}";
            if ($joins)       $sql .= ' ' . implode(' ', $joins);
            if ($whereClause) $sql .= $whereClause;
            if ($groupBy)     $sql .= " GROUP BY {$groupBy}";
            if ($orderBy)     $sql .= ' ORDER BY ' . implode(', ', $orderBy);
            return $sql;
        }

        // Kasus: ada OFFSET → wajib pakai OFFSET … FETCH NEXT (SQL Server 2012+)
        // SQL Server WAJIB punya ORDER BY jika ada OFFSET FETCH
        $sql = "SELECT {$distinct} {$cols} FROM {$this->wrapTable($table)}";
        if ($joins)       $sql .= ' ' . implode(' ', $joins);
        if ($whereClause) $sql .= $whereClause;
        if ($groupBy)     $sql .= " GROUP BY {$groupBy}";

        // Pastikan selalu ada ORDER BY jika pakai OFFSET FETCH
        if (empty($orderBy)) {
            $orderBy = ['(SELECT NULL)'];  // dummy ORDER untuk SQL Server
        }
        $sql .= ' ORDER BY ' . implode(', ', $orderBy);

        if ($offset !== null || $limit !== null) {
            $off  = $offset ?? 0;
            $sql .= " OFFSET {$off} ROWS";
            if ($limit !== null) {
                $sql .= " FETCH NEXT {$limit} ROWS ONLY";
            }
        }

        return $sql;
    }

    public function buildInsert(string $table, array $columns, string $primaryKey): array
    {
        $cols   = implode(', ', array_map(fn($c) => $this->wrapIdentifier($c), $columns));
        $params = ':' . implode(', :', $columns);
        // OUTPUT INSERTED untuk dapat ID yang baru diinsert
        $pk     = $this->wrapIdentifier($primaryKey);
        return [
            'sql'       => "INSERT INTO {$this->wrapTable($table)} ({$cols}) OUTPUT INSERTED.{$pk} VALUES ({$params})",
            'returning' => true, // SQL Server OUTPUT mirip PostgreSQL RETURNING
        ];
    }

    /**
     * SQL Server pakai OUTPUT INSERTED — baca dari result set.
     */
    public function resolveLastInsertId(\PDO $pdo, \PDOStatement $stmt, string $table, string $primaryKey): int|string|null
    {
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row[$primaryKey] ?? null;
    }

    // SQL Server: UPDLOCK hint di dalam WITH(), bukan suffix
    // Untuk kesederhanaan PDO kita pakai WITH (UPDLOCK, ROWLOCK) sebagai hint tabel
    // Tapi karena tidak bisa inject ke tengah SQL yang sudah jadi,
    // kita return suffix yang kompatibel secara umum.
    public function lockForUpdate(): string { return ' WITH (UPDLOCK, ROWLOCK)'; }
    public function lockForShare(): string  { return ' WITH (HOLDLOCK, ROWLOCK)'; }

    public function monthExpr(string $column): string { return "MONTH({$column})"; }
    public function yearExpr(string $column): string  { return "YEAR({$column})"; }

    public function dateExpr(string $column, string $paramName): string
    {
        // SQL Server: CAST ke DATE
        return "CAST({$column} AS DATE) = :{$paramName}";
    }
}