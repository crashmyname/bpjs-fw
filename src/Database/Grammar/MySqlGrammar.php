<?php

namespace Bpjs\Framework\Database\Grammar;

class MySqlGrammar extends Grammar
{
    public function limitOffset($limit, $offset)
    {
        return "LIMIT {$limit} OFFSET {$offset}";
    }

    public function month($column)
    {
        return "MONTH({$column})";
    }

    public function year($column)
    {
        return "YEAR({$column})";
    }

    public function wrap($value)
    {
        return "`{$value}`";
    }

    public function lockForUpdate()
    {
        return "FOR UPDATE";
    }

    public function sharedLock()
    {
        return "LOCK IN SHARE MODE";
    }
}