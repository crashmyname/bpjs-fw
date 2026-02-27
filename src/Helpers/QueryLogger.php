<?php
namespace Bpjs\Framework\Helpers;

class QueryLogger
{
    protected static array $queries = [];

    public static function add($sql, $params = [], $time = 0, $model = null)
    {
        self::$queries[] = [
            'sql' => $sql,
            'params' => $params,
            'time_ms' => $time,
            'model' => $model
        ];
    }

    public static function all()
    {
        return self::$queries;
    }

    public static function clear()
    {
        self::$queries = [];
    }
}