<?php

namespace Bpjs\Framework\Database\Grammar;

interface GrammarInterface
{
    /** Wrap identifier: table/column name dengan quote yang tepat */
    public function wrapIdentifier(string $name): string;

    /** Wrap nama tabel (bisa include schema) */
    public function wrapTable(string $table): string;

    /** Bangun LIMIT + OFFSET clause */
    public function buildLimitOffset(?int $limit, ?int $offset): string;

    /**
     * Bangun SELECT lengkap TANPA limit/offset.
     * SQL Server perlu TOP di sini, bukan di akhir.
     */
    public function buildSelect(
        string $distinct,
        array  $columns,
        string $table,
        array  $joins,
        string $whereClause,
        string $groupBy,
        array  $orderBy,
        ?int   $limit,
        ?int   $offset
    ): string;

    /** INSERT — return SQL + apakah pakai RETURNING */
    public function buildInsert(string $table, array $columns, string $primaryKey): array;

    /** Cara ambil last insert ID setelah execute INSERT */
    public function resolveLastInsertId(\PDO $pdo, \PDOStatement $stmt, string $table, string $primaryKey): int|string|null;

    /** FOR UPDATE / LOCK IN SHARE MODE / dll */
    public function lockForUpdate(): string;
    public function lockForShare(): string;

    /** MONTH() expression */
    public function monthExpr(string $column): string;

    /** YEAR() expression */
    public function yearExpr(string $column): string;

    /** WHERE date expression */
    public function dateExpr(string $column, string $paramName): string;

    /** Nama driver (mysql, pgsql, sqlite, sqlsrv) */
    public function driverName(): string;
}