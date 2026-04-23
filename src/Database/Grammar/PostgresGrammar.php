<?php

namespace Bpjs\Framework\Database\Grammar;

class PostgresGrammar extends Grammar
{
    public function limitOffset($limit, $offset)
    {
        return "LIMIT {$limit} OFFSET {$offset}";
    }

    public function month($column)
    {
        return "EXTRACT(MONTH FROM {$column})";
    }

    public function year($column)
    {
        return "EXTRACT(YEAR FROM {$column})";
    }

    public function wrap($value)
    {
        return "\"{$value}\"";
    }

    public function lockForUpdate()
    {
        return "FOR UPDATE";
    }

    public function sharedLock()
    {
        return "FOR SHARE";
    }
}