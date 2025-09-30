<?php

namespace WpOps\Dashboard\Database;

use PDO;
use PDOException;

/**
 * Database Connection
 * Singleton pattern for database connectivity
 */
class Connection
{
    private static ?Connection $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $dbname = $_ENV['DB_NAME'] ?? 'wpops';
        $username = $_ENV['DB_USER'] ?? 'root';
        $password = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query and return results
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return single row
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Insert data into table
     */
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update data in table
     */
    public function update(string $table, array $data, array $where): int
    {
        $setParts = [];
        foreach (array_keys($data) as $key) {
            $setParts[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $setParts);

        $whereParts = [];
        foreach (array_keys($where) as $key) {
            $whereParts[] = "{$key} = :where_{$key}";
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$whereClause}";
        
        // Prefix where parameters to avoid conflicts
        $params = $data;
        foreach ($where as $key => $value) {
            $params["where_{$key}"] = $value;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }

    /**
     * Delete data from table
     */
    public function delete(string $table, array $where): int
    {
        $whereParts = [];
        foreach (array_keys($where) as $key) {
            $whereParts[] = "{$key} = :{$key}";
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "DELETE FROM {$table} WHERE {$whereClause}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);
        
        return $stmt->rowCount();
    }

    /**
     * Select data from table
     */
    public function select(string $table, array $where = [], string $orderBy = '', int $limit = 0): array
    {
        $sql = "SELECT * FROM {$table}";
        
        if (!empty($where)) {
            $whereParts = [];
            foreach (array_keys($where) as $key) {
                $whereParts[] = "{$key} = :{$key}";
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }

        return $this->query($sql, $where);
    }

    /**
     * Select single row from table
     */
    public function selectOne(string $table, array $where = []): ?array
    {
        $results = $this->select($table, $where, '', 1);
        return $results[0] ?? null;
    }

    /**
     * Count rows in table
     */
    public function count(string $table, array $where = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$table}";
        
        if (!empty($where)) {
            $whereParts = [];
            foreach (array_keys($where) as $key) {
                $whereParts[] = "{$key} = :{$key}";
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }

        $result = $this->queryOne($sql, $where);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->pdo->rollback();
    }

    /**
     * Execute transaction with callback
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}