<?php
namespace Puleeno\Rake\WordPress;

use Ramphor\Sql as SqlBuilder;
use Ramphor\Rake\Abstracts\Driver as DriverAbstract;
use Ramphor\Rake\Constracts\SQLQueryBuilder;

class Driver extends DriverAbstract
{
    public const DRIVER_NAME = 'wordpress';

    protected $dbInstance;

    public function __construct()
    {
        $this->dbInstance = $GLOBALS['wpdb'];
    }

    public function name()
    {
        return self::DRIVER_NAME;
    }

    public function prefix()
    {
        return $this->dbInstance
            ->prefix;
    }

    public function query(SqlBuilder $query)
    {
        return $this->dbInstance
            ->query($query);
    }

    public function get(SqlBuilder $query, $classToMap = null)
    {
        $results = $this->dbInstance
            ->get_results($query);

        if (empty($classToMap)) {
            return $results;
        }
        return $this->mapResults($results, $classToMap);
    }

    public function row(SqlBuilder $query)
    {
        return $this->dbInstance
            ->get_row($query);
    }

    public function var(SqlBuilder $query)
    {
        return $this->dbInstance
            ->get_var($query);
    }

    public function exists(SqlBuilder $query)
    {
        return $this->dbInstance
            ->get_var($query) != null;
    }

    public function insert(SqlBuilder $query)
    {
        if ($this->dbInstance->query($query)) {
            return $this->dbInstance->insert_id;
        }
    }

    public function raw_query($sql)
    {
        return $this->dbInstance
            ->query($sql);
    }
}
