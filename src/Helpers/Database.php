<?php
namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Core\Request;
use PDO;
use PDOException;
use Exception;

class Database
{
    protected static ?PDO $pdo = null;
    protected static bool $isConnected = false;
    protected static array $connectionPool = [];
    protected static string $currentConnection = 'default';
    protected static int $reconnectAttempts = 3;
    protected static int $connectionTimeout = 5; // detik

    /**
     * Mendapatkan koneksi database
     * 
     * @param string|null $connectionName Nama koneksi (default: null = pakai default)
     * @return PDO
     */
    public static function connection(?string $connectionName = null): PDO
    {
        // Jika tidak ada nama koneksi, pakai default
        if ($connectionName === null) {
            $connectionName = config('database.default', 'mysql');
        }
        
        $connectionKey = "database.connections.$connectionName";

        // Jika koneksi sudah ada dan aktif, return
        if (isset(self::$connectionPool[$connectionName]) && self::$connectionPool[$connectionName] !== null) {
            try {
                // Test koneksi masih aktif
                self::$connectionPool[$connectionName]->query('SELECT 1');
                return self::$connectionPool[$connectionName];
            } catch (PDOException $e) {
                // Koneksi mati, hapus dari pool
                unset(self::$connectionPool[$connectionName]);
                self::$pdo = null;
                self::$isConnected = false;
                
                error_log("Database connection lost [{$connectionName}]: " . $e->getMessage() . ". Reconnecting...");
            }
        }

        // Jika koneksi tidak ditemukan atau mati, buat baru
        if (!config($connectionKey)) {
            return self::handleConnectionError(
                "Database connection [$connectionName] not defined in config."
            );
        }

        $attempts = 0;
        $lastException = null;

        while ($attempts < self::$reconnectAttempts) {
            try {
                $pdo = self::connect($connectionKey);
                self::$connectionPool[$connectionName] = $pdo;
                self::$currentConnection = $connectionName;
                self::$isConnected = true;
                self::$pdo = $pdo;
                
                return $pdo;
            } catch (PDOException $e) {
                $lastException = $e;
                $attempts++;
                
                if ($attempts < self::$reconnectAttempts) {
                    error_log("Database connection attempt {$attempts} failed. Retrying...");
                    sleep(1); // Tunggu 1 detik sebelum retry
                }
            }
        }

        self::$isConnected = false;
        return self::handleConnectionError(
            "Database connection failed after " . self::$reconnectAttempts . " attempts: " . ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    /**
     * Get connection pool status
     */
    public static function getPoolStatus(): array
    {
        $status = [];
        foreach (self::$connectionPool as $name => $conn) {
            $status[$name] = $conn !== null ? 'active' : 'closed';
        }
        return $status;
    }

    /**
     * Cek apakah koneksi masih aktif
     */
    public static function ping(?string $connectionName = null): bool
    {
        $connectionName = $connectionName ?? self::$currentConnection;
        
        if (!isset(self::$connectionPool[$connectionName]) || self::$connectionPool[$connectionName] === null) {
            return false;
        }

        try {
            self::$connectionPool[$connectionName]->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            unset(self::$connectionPool[$connectionName]);
            if ($connectionName === self::$currentConnection) {
                self::$pdo = null;
                self::$isConnected = false;
            }
            return false;
        }
    }

    /**
     * Handle connection error dengan response yang sesuai
     */
    protected static function handleConnectionError($message)
    {
        // Log error untuk debugging
        error_log($message);

        // Jika debug mode, tampilkan error
        if (env('APP_DEBUG', false) === true || env('APP_DEBUG') === 'true') {
            throw new Exception($message);
        }

        // Untuk AJAX/JSON request
        if (Request::isAjax() || self::isJsonRequest()) {
            header('Content-Type: application/json', true, 500);
            echo json_encode([
                'statusCode' => 500,
                'error' => 'Internal Server Error',
                'message' => env('APP_DEBUG', false) ? $message : null
            ]);
            exit;
        }

        // Untuk Web request
        if (class_exists('Bpjs\\Framework\\Helpers\\View')) {
            return View::error(500);
        }

        // Fallback
        http_response_code(500);
        echo "<!DOCTYPE html>
        <html>
        <head><title>500 Internal Server Error</title></head>
        <body>
            <h1>500 Internal Server Error</h1>
            <p>Maaf, terjadi kesalahan pada server. Silakan coba lagi nanti.</p>
        </body>
        </html>";
        exit;
    }

    /**
     * Cek apakah request meminta JSON response
     */
    protected static function isJsonRequest(): bool
    {
        return isset($_SERVER['HTTP_ACCEPT']) && 
               strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    }

    /**
     * Connect ke database dengan config
     */
    protected static function connect(string $baseKey): PDO
    {
        $driver = config("$baseKey.driver", 'mysql');
        $host = config("$baseKey.host", '127.0.0.1');
        $port = config("$baseKey.port", '3306');
        $dbname = config("$baseKey.database", 'bpjs');
        $charset = config("$baseKey.charset", 'utf8mb4');
        $username = config("$baseKey.username", 'root');
        $password = config("$baseKey.password", '');
        
        // Default options yang lebih baik untuk production
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => self::$connectionTimeout,
            PDO::ATTR_PERSISTENT => false,
        ];

        $options = config("$baseKey.options", $defaultOptions);

        // Tambahkan option khusus untuk MySQL
        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES $charset";
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            // Tambahkan option untuk SSL jika diperlukan
            if (config("$baseKey.ssl_ca", false)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = config("$baseKey.ssl_ca");
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        // Build DSN
        $dsn = match ($driver) {
            'mysql' => "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset",
            'pgsql' => "pgsql:host=$host;port=$port;dbname=$dbname",
            'sqlite' => "sqlite:$dbname",
            'sqlsrv' => "sqlsrv:Server=$host,$port;Database=$dbname",
            default => throw new Exception("Driver [$driver] not supported.")
        };

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            
            // Set session timeout untuk MySQL
            if ($driver === 'mysql') {
                $pdo->exec("SET SESSION wait_timeout = 28800"); // 8 jam
                $pdo->exec("SET SESSION interactive_timeout = 28800");
                $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            }
            
            return $pdo;
        } catch (PDOException $e) {
            error_log("PDO Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Disconnect dari database
     */
    public static function disconnect(?string $connectionName = null): void
    {
        if ($connectionName === null) {
            // Disconnect semua
            foreach (self::$connectionPool as $name => $conn) {
                self::$connectionPool[$name] = null;
            }
            self::$pdo = null;
            self::$isConnected = false;
        } else {
            // Disconnect spesifik connection
            if (isset(self::$connectionPool[$connectionName])) {
                self::$connectionPool[$connectionName] = null;
                unset(self::$connectionPool[$connectionName]);
            }
            if ($connectionName === self::$currentConnection) {
                self::$pdo = null;
                self::$isConnected = false;
            }
        }
    }

    /**
     * Get PDO connection status
     */
    public static function isConnected(?string $connectionName = null): bool
    {
        $connectionName = $connectionName ?? self::$currentConnection;
        
        if (!isset(self::$connectionPool[$connectionName]) || self::$connectionPool[$connectionName] === null) {
            return false;
        }

        try {
            self::$connectionPool[$connectionName]->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get last insert ID
     */
    public static function lastInsertId(?string $name = null): string
    {
        $pdo = self::connection();
        return $pdo->lastInsertId($name);
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        $pdo = self::connection();
        return $pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        $pdo = self::connection();
        return $pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollBack(): bool
    {
        $pdo = self::connection();
        return $pdo->rollBack();
    }

    /**
     * Execute query dengan parameter binding (prepared statement)
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $pdo = self::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): mixed
    {
        return self::query($sql, $params)->fetch();
    }

    /**
     * Fetch single column value
     */
    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return self::query($sql, $params)->fetchColumn($column);
    }
}