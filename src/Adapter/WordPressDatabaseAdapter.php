<?php
namespace Puleeno\Rake\WordPress\Adapter;

use Rake\Abstracts\Database\Adapter\DatabaseAdapterAbstract;

class WordPressDatabaseAdapter extends DatabaseAdapterAbstract
{
    /**
     * @var \Puleeno\Rake\WordPress\Driver\WordPressDatabaseDriver
     */
    protected $driver;

    /**
     * Constructor.
     *
     * @param \Puleeno\Rake\WordPress\Driver\WordPressDatabaseDriver $driver
     */
    public function __construct($driver)
    {
        $this->driver = $driver;
    }

    /**
     * Get the underlying database driver instance.
     *
     * @return \Puleeno\Rake\WordPress\Driver\WordPressDatabaseDriver
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * Insert data into a table.
     *
     * @param string $table
     * @param array $data
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        global $wpdb;
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    /**
     * Update data in a table.
     *
     * @param string $table
     * @param array $data
     * @param array $where
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        global $wpdb;
        $wpdb->update($table, $data, $where);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Delete data from a table.
     *
     * @param string $table
     * @param array $where
     * @return int Number of affected rows
     */
    public function delete(string $table, array $where): int
    {
        global $wpdb;
        $wpdb->delete($table, $where);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Select data from a table.
     *
     * @param string $table
     * @param array $columns
     * @param array $where
     * @return array
     */
    public function select(string $table, array $columns = ['*'], array $where = []): array
    {
        global $wpdb;
        $cols = implode(',', $columns);
        $sql = "SELECT $cols FROM `$table`";
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $k => $v) {
                $conditions[] = $wpdb->prepare("`$k` = %s", $v);
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get a query builder for a table (not implemented for WordPress, return null).
     *
     * @param string $table
     * @return null
     */
    public function table(string $table)
    {
        return null;
    }

    /**
     * Run a set of operations in a transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        $this->driver->beginTransaction();
        try {
            $result = $callback($this);
            $this->driver->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->driver->rollback();
            throw $e;
        }
    }
}
