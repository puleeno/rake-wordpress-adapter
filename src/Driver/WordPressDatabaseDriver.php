<?php

namespace Puleeno\Rake\WordPress\Driver;

use Rake\Abstracts\Database\DatabaseDriverAbstract;

/**
 * WordPress Database Driver
 * Implement DatabaseAdapterInterface sử dụng wpdb
 */
class WordPressDatabaseDriver extends DatabaseDriverAbstract
{
    /**
     * The WordPress wpdb instance.
     *
     * @var \wpdb|null
     */
    protected $wpdb = null;

    /**
     * Assign the wpdb object from outside (e.g. via wpcrawlflow).
     *
     * @param \wpdb $wpdb
     * @return void
     */
    public function setWpdb($wpdb): void
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Establish a database connection (noop for wpdb).
     *
     * @return void
     */
    public function connect()
    {
        // wpdb is already connected by WordPress
    }

    /**
     * Execute a query and return results.
     *
     * @param string $query
     * @param array $params
     * @return mixed
     */
    public function query(string $query, array $params = [])
    {
        if ($params) {
            $query = $this->wpdb->prepare($query, $params);
        }
        return $this->wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Execute a statement (insert, update, delete).
     *
     * @param string $query
     * @param array $params
     * @return int
     */
    public function execute(string $query, array $params = []): int
    {
        if ($params) {
            $query = $this->wpdb->prepare($query, $params);
        }
        $this->wpdb->query($query);
        return $this->wpdb->rows_affected;
    }

    /**
     * Begin a transaction.
     *
     * @return void
     */
    public function beginTransaction()
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction.
     *
     * @return void
     */
    public function commit()
    {
        $this->wpdb->query('COMMIT');
    }

    /**
     * Rollback the current transaction.
     *
     * @return void
     */
    public function rollback()
    {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * Close the database connection (noop for wpdb).
     *
     * @return void
     */
    public function close()
    {
        // wpdb does not support manual close
    }
}
