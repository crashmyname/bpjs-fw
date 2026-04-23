<?php

namespace Bpjs\Framework\Database\Grammar;

abstract class Grammar
{
    abstract public function limitOffset($limit, $offset);
    abstract public function month($column);
    abstract public function year($column);
    abstract public function wrap($value);
    abstract public function lockForUpdate();
    abstract public function sharedLock();
}