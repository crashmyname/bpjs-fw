<?php

namespace Bpjs\Framework\Database\Grammar;

class GrammarFactory
{
    /**
     * Map driver name PDO ke class Grammar.
     * Daftarkan engine baru di sini cukup satu baris.
     */
    private static array $map = [
        'mysql'  => MySqlGrammar::class,
        'mariadb'=> MySqlGrammar::class,   // MariaDB kompatibel dengan MySQL grammar
        'pgsql'  => PostgresGrammar::class,
        'sqlite' => SQLiteGrammar::class,
        'sqlite2'=> SQLiteGrammar::class,
        'sqlsrv' => SqlServerGrammar::class,
        'dblib'  => SqlServerGrammar::class, // FreeTDS / dblib PDO driver untuk MSSQL
        'mssql'  => SqlServerGrammar::class,
    ];

    /** Cache instance agar tidak re-instantiate setiap query */
    private static array $instances = [];

    public static function make(string $driver): GrammarInterface
    {
        $driver = strtolower($driver);

        if (isset(self::$instances[$driver])) {
            return self::$instances[$driver];
        }

        $class = self::$map[$driver]
            ?? throw new \RuntimeException("Unsupported database driver: [{$driver}]. Supported: " . implode(', ', array_keys(self::$map)));

        return self::$instances[$driver] = new $class();
    }

    /** Daftarkan custom grammar dari luar framework */
    public static function extend(string $driver, string $grammarClass): void
    {
        if (!is_a($grammarClass, GrammarInterface::class, true)) {
            throw new \InvalidArgumentException("{$grammarClass} must implement GrammarInterface.");
        }
        self::$map[strtolower($driver)] = $grammarClass;
        unset(self::$instances[strtolower($driver)]); // reset cache
    }
}