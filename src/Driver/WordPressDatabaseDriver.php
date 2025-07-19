<?php

namespace Puleeno\Rake\WordPress\Driver;

use Rake\Contracts\Database\DatabaseDriverInterface;

/**
 * WordPress Database Driver
 * Handles low-level database operations using WordPress $wpdb
 */
class WordPressDatabaseDriver implements DatabaseDriverInterface
{
    /**
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Execute a query and return results
     *
     * @param string $query
     * @param array $params
     * @return array|false
     */
    public function query(string $query, array $params = [])
    {
        if (!empty($params)) {
            global $wpdb;
            $query = $wpdb->prepare($query, $params);
        }
        $result = $this->wpdb->get_results($query, ARRAY_A);
        return $result !== null ? $result : false;
    }

    /**
     * Execute a query without returning results
     *
     * @param string $query
     * @param array $params
     * @return int Number of affected rows
     */
    public function execute(string $query, array $params = []): int
    {
        if (!empty($params)) {
            global $wpdb;
            $query = $wpdb->prepare($query, $params);
        }
        $result = $this->wpdb->query($query);
        return $result !== false ? $this->wpdb->rows_affected : 0;
    }

    /**
     * Establish a database connection.
     *
     * @return void
     */
    public function connect()
    {
        // WordPress tự quản lý kết nối
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    public function close()
    {
        // WordPress tự quản lý kết nối
    }

    /**
     * Begin a transaction
     *
     * @return void
     */
    public function beginTransaction()
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Commit a transaction
     *
     * @return void
     */
    public function commit()
    {
        $this->wpdb->query('COMMIT');
    }

    /**
     * Rollback a transaction
     *
     * @return void
     */
    public function rollback()
    {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * Get the last insert ID
     *
     * @return int
     */
    public function lastInsertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get the number of affected rows
     *
     * @return int
     */
    public function affectedRows(): int
    {
        return (int) $this->wpdb->rows_affected;
    }

    /**
     * Escape a string for safe SQL usage
     *
     * @param string $string
     * @return string
     */
    public function escape(string $string): string
    {
        return $this->wpdb->_real_escape($string);
    }

    /**
     * Get the last error message
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->wpdb->last_error;
    }

    /**
     * Check if a table exists
     *
     * @param string $tableName
     * @return bool
     */
    public function tableExists(string $tableName): bool
    {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $tableName
            )
        );
        return $result !== null;
    }

    /**
     * Get table structure
     *
     * @param string $tableName
     * @return array
     */
    public function getTableStructure(string $tableName): array
    {
        $columns = $this->wpdb->get_results(
            "DESCRIBE `$tableName`",
            ARRAY_A
        );

        $structure = [];
        foreach ($columns as $column) {
            $structure[$column['Field']] = [
                'type' => $column['Type'],
                'null' => $column['Null'] === 'YES',
                'key' => $column['Key'],
                'default' => $column['Default'],
                'extra' => $column['Extra']
            ];
        }

        return $structure;
    }

    /**
     * Get table indexes
     *
     * @param string $tableName
     * @return array
     */
    public function getTableIndexes(string $tableName): array
    {
        $indexes = $this->wpdb->get_results(
            "SHOW INDEX FROM `$tableName`",
            ARRAY_A
        );

        $result = [];
        foreach ($indexes as $index) {
            $indexName = $index['Key_name'];
            if (!isset($result[$indexName])) {
                $result[$indexName] = [
                    'name' => $indexName,
                    'fields' => [],
                    'unique' => $index['Non_unique'] == 0
                ];
            }
            $result[$indexName]['fields'][] = $index['Column_name'];
        }

        return $result;
    }

    /**
     * Get database charset
     *
     * @return string
     */
    public function getCharset(): string
    {
        return $this->wpdb->charset;
    }

    /**
     * Get database collation
     *
     * @return string
     */
    public function getCollation(): string
    {
        return $this->wpdb->collate;
    }

    /**
     * Get database name
     *
     * @return string
     */
    public function getDatabaseName(): string
    {
        return DB_NAME;
    }

    /**
     * Get WordPress database instance
     *
     * @return \wpdb
     */
    public function getWpdb(): \wpdb
    {
        return $this->wpdb;
    }
}