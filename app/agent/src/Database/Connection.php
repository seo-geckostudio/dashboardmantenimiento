<?php

namespace WpOps\Agent\Database;

use PDO;
use PDOException;
use Exception;

/**
 * Database Connection Singleton
 */
class Connection
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Get database connection instance
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::connect();
        }
        
        return self::$instance;
    }

    /**
     * Get PDO instance (alias for getInstance)
     */
    public function getPdo(): PDO
    {
        return self::getInstance();
    }

    /**
     * Initialize database connection
     */
    private static function connect(): void
    {
        try {
            $dsn = $_ENV['DB_DSN'] ?? 'mysql:host=localhost;dbname=wpops;charset=utf8mb4';
            $username = $_ENV['DB_USER'] ?? 'wpops';
            $password = $_ENV['DB_PASS'] ?? '';

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            self::$instance = new PDO($dsn, $username, $password, $options);
            
            // Test connection
            self::$instance->query('SELECT 1');
            
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool
    {
        return self::getInstance()->rollBack();
    }

    /**
     * Execute query with parameters
     */
    public static function execute(string $query, array $params = []): \PDOStatement
    {
        $stmt = self::getInstance()->prepare($query);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $query, array $params = []): ?array
    {
        $stmt = self::execute($query, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $query, array $params = []): array
    {
        $stmt = self::execute($query, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insert record and return ID
     */
    public static function insert(string $table, array $data): int
    {
        $columns = implode(',', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::execute($query, $data);
        
        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * Update records
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "{$column} = :{$column}";
        }
        $setClause = implode(', ', $setParts);
        
        $query = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $stmt = self::execute($query, array_merge($data, $whereParams));
        
        return $stmt->rowCount();
    }

    /**
     * Delete records
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $query = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::execute($query, $params);
        
        return $stmt->rowCount();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}