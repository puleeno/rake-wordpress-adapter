<?php

namespace Puleeno\Rake\WordPress\Adapter;

use Exception;
use Rake\Contracts\Database\Adapter\DatabaseAdapterInterface;
use Puleeno\Rake\WordPress\Driver\WordPressDatabaseDriver;

/**
 * WordPress Database Adapter
 * Handles high-level database operations using WordPress
 */
class WordPressDatabaseAdapter implements DatabaseAdapterInterface
{
    /**
     * @var WordPressDatabaseDriver
     */
    private $driver;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->driver = new WordPressDatabaseDriver();
    }

    /**
     * Get the database driver
     *
     * @return WordPressDatabaseDriver
     */
    public function getDriver(): WordPressDatabaseDriver
    {
        return $this->driver;
    }

        /**
     * Insert data into a table
     *
     * @param string $table
     * @param array $data
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        global $wpdb;

        $result = $wpdb->insert($table, $data);
        return $result !== false ? $wpdb->insert_id : 0;
    }

        /**
     * Update data in a table
     *
     * @param string $table
     * @param array $data
     * @param array $where
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        global $wpdb;

        $result = $wpdb->update($table, $data, $where);
        return $result !== false ? $wpdb->rows_affected : 0;
    }

    /**
     * Delete data from a table
     *
     * @param string $table
     * @param array $where
     * @return int Number of affected rows
     */
    public function delete(string $table, array $where): int
    {
        global $wpdb;

        $result = $wpdb->delete($table, $where);
        return $result !== false ? $wpdb->rows_affected : 0;
    }

    /**
     * Select data from a table
     *
     * @param string $table
     * @param array $columns
     * @param array $where
     * @param int $limit
     * @param array $orderBy
     * @return array
     */
    public function select(string $table, array $columns = ['*'], array $where = [], int $limit = 0, array $orderBy = []): array
    {
        global $wpdb;

        $columnsStr = implode(', ', $columns);
        $sql = "SELECT $columnsStr FROM `$table`";

        // Add WHERE clause
        if (!empty($where)) {
            $whereConditions = [];
            $whereValues = [];

            foreach ($where as $key => $value) {
                if (is_array($value)) {
                    // Handle LIKE conditions
                    $whereConditions[] = "`$key` LIKE %s";
                    $whereValues[] = $value[0];
                } else {
                    $whereConditions[] = "`$key` = %s";
                    $whereValues[] = $value;
                }
            }

            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }

        // Add ORDER BY clause
        if (!empty($orderBy)) {
            $orderConditions = [];
            foreach ($orderBy as $column => $direction) {
                $orderConditions[] = "`$column` $direction";
            }
            $sql .= " ORDER BY " . implode(', ', $orderConditions);
        }

        // Add LIMIT clause
        if ($limit > 0) {
            $sql .= " LIMIT $limit";
        }

        // Prepare and execute query
        if (!empty($whereValues)) {
            $sql = $wpdb->prepare($sql, $whereValues);
        }

        $result = $wpdb->get_results($sql, ARRAY_A);
        return $result !== null ? $result : [];
    }

    /**
     * Get a single row from a table
     *
     * @param string $table
     * @param array $columns
     * @param array $where
     * @return array|null
     */
    public function get(string $table, array $columns = ['*'], array $where = []): ?array
    {
        $result = $this->select($table, $columns, $where, 1);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Count rows in a table
     *
     * @param string $table
     * @param array $where
     * @return int
     */
    public function count(string $table, array $where = []): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM `$table`";

        if (!empty($where)) {
            $whereConditions = [];
            $whereValues = [];

            foreach ($where as $key => $value) {
                $whereConditions[] = "`$key` = %s";
                $whereValues[] = $value;
            }

            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }

        if (!empty($whereValues)) {
            $sql = $wpdb->prepare($sql, $whereValues);
        }

        $result = $wpdb->get_var($sql);
        return (int) $result;
    }

    /**
     * Check if a table exists
     *
     * @param string $tableName
     * @return bool
     */
    public function tableExists(string $tableName): bool
    {
        return $this->driver->tableExists($tableName);
    }

    /**
     * Get table structure
     *
     * @param string $tableName
     * @return array
     */
    public function getTableStructure(string $tableName): array
    {
        return $this->driver->getTableStructure($tableName);
    }

    /**
     * Get table indexes
     *
     * @param string $tableName
     * @return array
     */
    public function getTableIndexes(string $tableName): array
    {
        return $this->driver->getTableIndexes($tableName);
    }

    /**
     * Get database schema
     *
     * @return array
     */
    public function getDatabaseSchema(): array
    {
        global $wpdb;

        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_A);
        $schema = [];

        foreach ($tables as $table) {
            $tableName = array_values($table)[0];

            // Skip WordPress core tables if needed
            if (strpos($tableName, $wpdb->prefix) !== 0) {
                continue;
            }

            $schema[$tableName] = [
                'fields' => $this->getTableStructure($tableName),
                'indexes' => $this->getTableIndexes($tableName)
            ];
        }

        return $schema;
    }

    /**
     * Execute raw SQL query
     *
     * @param string $sql
     * @return array|false
     */
    public function query(string $sql)
    {
        return $this->driver->query($sql);
    }

    /**
     * Execute raw SQL without returning results
     *
     * @param string $sql
     * @return bool
     */
    public function execute(string $sql): bool
    {
        global $wpdb;
        error_log('=== WPDB EXECUTE ===');
        error_log('SQL: ' . $sql);
        $result = $wpdb->query($sql);
        error_log('Result: ' . ($result === false ? 'FALSE' : $result));
        error_log('Last Error: ' . $wpdb->last_error);
        error_log('Last Query: ' . $wpdb->last_query);
        return $result !== false;
    }

    /**
     * Begin a transaction
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        $this->driver->beginTransaction();
        return true;
    }

    /**
     * Commit a transaction
     *
     * @return bool
     */
    public function commit(): bool
    {
        $this->driver->commit();
        return true;
    }

    /**
     * Rollback a transaction
     *
     * @return bool
     */
    public function rollback(): bool
    {
        $this->driver->rollback();
        return true;
    }

    /**
     * Get the last insert ID
     *
     * @return int
     */
    public function lastInsertId(): int
    {
        return $this->driver->lastInsertId();
    }

    /**
     * Get the number of affected rows
     *
     * @return int
     */
    public function affectedRows(): int
    {
        return $this->driver->affectedRows();
    }

    /**
     * Get the last error message
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->driver->getLastError();
    }

    /**
     * Escape a string for safe SQL usage
     *
     * @param string $string
     * @return string
     */
    public function escape(string $string): string
    {
        return $this->driver->escape($string);
    }

    /**
     * Get a query builder for a table.
     *
     * @param string $table
     * @return mixed
     */
    public function table(string $table)
    {
        // For now, return a simple table wrapper
        return new class($this, $table) {
            private $adapter;
            private $table;

            public function __construct($adapter, $table) {
                $this->adapter = $adapter;
                $this->table = $table;
            }

            public function insert(array $data) {
                return $this->adapter->insert($this->table, $data);
            }

            public function update(array $data, array $where) {
                return $this->adapter->update($this->table, $data, $where);
            }

            public function delete(array $where) {
                return $this->adapter->delete($this->table, $where);
            }

            public function select(array $columns = ['*'], array $where = [], int $limit = 0, array $orderBy = []) {
                return $this->adapter->select($this->table, $columns, $where, $limit, $orderBy);
            }

            public function count(array $where = []) {
                return $this->adapter->count($this->table, $where);
            }
        };
    }

    /**
     * Migrate (create or update) table from schema definition
     * @param array $schema
     * @return bool
     */
    public function migrate(array $schema): bool
    {
        try {
            // For now, return true as migration is handled by MigrationManager
            // This method can be extended to handle direct schema migration
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Run a set of operations in a transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        try {
            $this->beginTransaction();

            $result = $callback($this);

            $this->commit();

            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}