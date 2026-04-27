<?php

namespace Bpjs\Framework\Database\Grammar;

class SQLiteGrammar implements GrammarInterface
{
    public function driverName(): string { return 'sqlite'; }

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
        return [
            'sql'       => "INSERT INTO {$this->wrapTable($table)} ({$cols}) VALUES ({$params})",
            'returning' => false,
        ];
    }

    public function resolveLastInsertId(\PDO $pdo, \PDOStatement $stmt, string $table, string $primaryKey): int|string|null
    {
        return $pdo->lastInsertId() ?: null;
    }

    // SQLite tidak support row-level lock — diabaikan agar tidak error
    public function lockForUpdate(): string { return ''; }
    public function lockForShare(): string  { return ''; }

    public function monthExpr(string $column): string
    {
        return "CAST(strftime('%m', {$column}) AS INTEGER)";
    }

    public function yearExpr(string $column): string
    {
        return "CAST(strftime('%Y', {$column}) AS INTEGER)";
    }

    public function dateExpr(string $column, string $paramName): string
    {
        return "date({$column}) = :{$paramName}";
    }
}