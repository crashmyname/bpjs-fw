<?php

namespace Bpjs\Framework\Database\Grammar;
class SqlServerGrammar extends Grammar
{
    public function limitOffset($limit, $offset)
    {
        return "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
    }

    public function month($column)
    {
        return "MONTH({$column})";
    }

    public function year($column)
    {
        return "YEAR({$column})";
    }

    public function lockForUpdate()
    {
        return "";
    }

    public function sharedLock()
    {
        return "";
    }

    public function wrap($value)
    {
        return "[{$value}]";
    }
}