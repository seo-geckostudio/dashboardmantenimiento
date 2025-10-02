<?php

namespace WpOps\Agent\Database;

use PDO;

/**
 * Database Helper
 * Provides convenient methods for database operations
 */
class DatabaseHelper
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
     * Select single row from table
     */
    public function selectOne(string $table, array $where = []): ?array
    {
        $sql = "SELECT * FROM {$table}";
        
        if (!empty($where)) {
            $whereParts = [];
            foreach (array_keys($where) as $key) {
                $whereParts[] = "{$key} = :{$key}";
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);
        $result = $stmt->fetch();
        
        return $result ?: null;
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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);
        
        return $stmt->fetchAll();
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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);
        $result = $stmt->fetch();
        
        return (int) ($result['count'] ?? 0);
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
     * Get the underlying PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepare a statement
     */
    public function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    /**
     * Execute a statement
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}