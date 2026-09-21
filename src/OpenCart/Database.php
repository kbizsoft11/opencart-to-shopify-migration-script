<?php
/**
 * OpenCart Database Connection Handler
 */

namespace OpenCart;

use PDO;
use PDOException;

class Database
{
    private PDO $pdo;
    private string $prefix;
    
    public function __construct(array $config)
    {
        $this->prefix = $config['prefix'];
        
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function getPrefix(): string
    {
        return $this->prefix;
    }
    
    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \Exception("Query failed: " . $e->getMessage() . "\nSQL: " . $sql);
        }
    }
    
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }
    
    public function fetchColumn(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function table(string $table): string
    {
        return $this->prefix . $table;
    }
    
    public function tableExists(string $table): bool
    {
        $fullTable = $this->table($table);
        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        return in_array($fullTable, $tables);
    }
    
    public function getPDO(): PDO
    {
        return $this->pdo;
    }
}
