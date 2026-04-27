<?php

namespace Bpjs\Framework\Database\Grammar;

class PostgresGrammar implements GrammarInterface
{
    public function driverName(): string { return 'pgsql'; }

    public function wrapIdentifier(string $name): string
    {
        if ($name === '*') return '*';
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function wrapTable(string $table): string
    {
        return implode('.', array_map(
            fn($p) => $this->wrapIdentifier($p),
            explode('.', $table)
        ));
    }

    public function buildLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null)  $sql .= ' LIMIT '  . $limit;
        if ($offset !== null) $sql .= ' OFFSET ' . $offset;
        return $sql;
    }

    public function buildSelect(
        string $distinct, array $columns, string $table,
        array $joins, string $whereClause, string $groupBy,
        array $orderBy, ?int $limit, ?int $offset
    ): string {
        $cols = implode(', ', $columns);
        $sql  = "SELECT {$distinct} {$cols} FROM {$this->wrapTable($table)}";
        if ($joins)       $sql .= ' ' . implode(' ', $joins);
        if ($whereClause) $sql .= $whereClause;
        if ($groupBy)     $sql .= " GROUP BY {$groupBy}";
        if ($orderBy)     $sql .= ' ORDER BY ' . implode(', ', $orderBy);
        $sql .= $this->buildLimitOffset($limit, $offset);
        return $sql;
    }

    public function buildInsert(string $table, array $columns, string $primaryKey): array
    {
        $cols   = implode(', ', array_map(fn($c) => $this->wrapIdentifier($c), $columns));
        $params = ':' . implode(', :', $columns);
        $pk     = $this->wrapIdentifier($primaryKey);
        return [
            'sql'       => "INSERT INTO {$this->wrapTable($table)} ({$cols}) VALUES ({$params}) RETURNING {$pk}",
            'returning' => true,
        ];
    }

    /**
     * PostgreSQL pakai RETURNING — last ID ada di result set, bukan lastInsertId().
     */
    public function resolveLastInsertId(\PDO $pdo, \PDOStatement $stmt, string $table, string $primaryKey): int|string|null
    {
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row[$primaryKey] ?? null;
    }

    public function lockForUpdate(): string { return ' FOR UPDATE'; }
    public function lockForShare(): string  { return ' FOR SHARE'; }

    public function monthExpr(string $column): string { return "EXTRACT(MONTH FROM {$column})"; }
    public function yearExpr(string $column): string  { return "EXTRACT(YEAR FROM {$column})"; }

    public function dateExpr(string $column, string $paramName): string
    {
        // PostgreSQL butuh cast ke DATE agar perbandingan string benar
        return "{$column}::date = :{$paramName}";
    }
}