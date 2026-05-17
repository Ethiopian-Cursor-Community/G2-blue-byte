<?php
/**
 * Lightweight database wrapper using PDO
 */
class DB {
    private static ?DB $instance = null;
    private PDO $pdo;

    private function __construct() {
        $this->connect();
    }

    private function connect(): void {
        $port = defined('DB_PORT') ? (int) DB_PORT : 3306;
        $hosts = [DB_HOST];
        if (DB_HOST === 'localhost') {
            $hosts[] = '127.0.0.1';
        }
        $lastError = null;
        foreach (array_unique($hosts) as $host) {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            try {
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 5,
                ]);
                return;
            } catch (PDOException $e) {
                $lastError = $e;
            }
        }
        if ($lastError instanceof PDOException) {
            throw $lastError;
        }
        throw new RuntimeException('Database connection failed.');
    }

    private function isConnectionError(PDOException $e): bool {
        $msg = strtolower($e->getMessage());
        $code = (int) $e->getCode();
        return $code === 2006
            || $code === 2013
            || str_contains($msg, 'server has gone away')
            || str_contains($msg, 'lost connection');
    }

    /**
     * Run DB operation with one reconnect retry on dropped MySQL connection.
     */
    private function runWithReconnectRetry(callable $op): mixed {
        try {
            return $op();
        } catch (PDOException $e) {
            if (!$this->isConnectionError($e)) {
                if (function_exists('qb_log_error')) {
                    qb_log_error('SQL Error: ' . $e->getMessage());
                }
                throw $e;
            }
            if (function_exists('qb_log_info')) {
                qb_log_info('DB connection dropped. Attempting reconnect...');
            }
            // Reconnect once and retry the exact same operation.
            try {
                $this->connect();
                return $op();
            } catch (PDOException $e2) {
                if (function_exists('qb_log_error')) {
                    qb_log_error('SQL Error after reconnect: ' . $e2->getMessage());
                }
                throw $e2;
            }
        }
    }

    public static function getInstance(): DB {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $row = $this->runWithReconnectRetry(function () use ($sql, $params) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        });
        return $row ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->runWithReconnectRetry(function () use ($sql, $params) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    public function execute(string $sql, array $params = []): int {
        return (int) $this->runWithReconnectRetry(function () use ($sql, $params) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        });
    }

    /** Run INSERT and return new row id (third arg ignored; kept for call-site compatibility). */
    public function insert(string $sql, array $params = [], mixed $_unused = null): int {
        return (int) $this->runWithReconnectRetry(function () use ($sql, $params) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $this->pdo->lastInsertId();
        });
    }

    public function lastInsertId(): int {
        return (int)$this->pdo->lastInsertId();
    }

    public function query(string $sql): array {
        return $this->runWithReconnectRetry(function () use ($sql) {
            return $this->pdo->query($sql)->fetchAll();
        });
    }
}

function db(): DB {
    return DB::getInstance();
}
