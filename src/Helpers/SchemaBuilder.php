<?php
namespace Bpjs\Framework\Helpers;
use Bpjs\Framework\Helpers\ColumnDefinition;

class SchemaBuilder
{
    protected string $table;
    protected array $columns = [];
    protected array $constraints = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function index(string|array $columns)
    {
        $cols = is_array($columns) ? implode('`, `', $columns) : $columns;
        $this->constraints[] = "INDEX (`$cols`)";
        return $this;
    }

    public function unique(string|array $columns)
    {
        $cols = is_array($columns) ? implode('`, `', $columns) : $columns;
        $this->constraints[] = "UNIQUE (`$cols`)";
        return $this;
    }

    public function id(string $name = 'id')
    {
        $column = new ColumnDefinition('BIGINT', $name);
        $column->autoIncrement()->primary();
        $this->columns[] = $column;
        return $this;
    }

    public function string(string $name, int $length = 255)
    {
        $column = new ColumnDefinition("VARCHAR($length)", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function integer(string $name)
    {
        $column = new ColumnDefinition("INT", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function bigInteger(string $name)
    {
        $column = new ColumnDefinition("BIGINT",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function text(string $name)
    {
        $column = new ColumnDefinition("TEXT",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function boolean(string $name)
    {
        $column = new ColumnDefinition("TINYINT(1)", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function date(string $name)
    {
        $column = new ColumnDefinition("DATE",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function dateTime(string $name)
    {
        $column = new ColumnDefinition("DATETIME",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function time(string $name)
    {
        $column = new ColumnDefinition("TIME",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function decimal(string $name, string $length = '10,2')
    {
        $column = new ColumnDefinition("DECIMAL($length)",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function float(string $name)
    {
        $column = new ColumnDefinition("FLOAT",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function double(string $name)
    {
        $column = new ColumnDefinition("DOUBLE",$name);
        $this->columns[] = $column;
        return $column;
    }

    public function enum(string $name, array $values)
    {
        $escaped = array_map(fn($v) => "'$v'", $values);
        $column = new ColumnDefinition("ENUM(" . implode(',', $escaped) . ")", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function set(string $name, array $values)
    {
        $escaped = array_map(fn($v) => "'$v'", $values);
        $column = new ColumnDefinition("SET(" . implode(',', $escaped) . ")", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function json(string $name)
    {
        $column = new ColumnDefinition("JSON", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function blob(string $name)
    {
        $column = new ColumnDefinition("BLOB", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function binary(string $name, int $length = 255)
    {
        $column = new ColumnDefinition("BINARY($length)", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function char(string $name, int $length = 1)
    {
        $column = new ColumnDefinition("CHAR($length)", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function mediumText(string $name)
    {
        $column = new ColumnDefinition("MEDIUMTEXT", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function longText(string $name)
    {
        $column = new ColumnDefinition("LONGTEXT", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function year(string $name)
    {
        $column = new ColumnDefinition("YEAR", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function timestamp(string $name)
    {
        $column = new ColumnDefinition("TIMESTAMP", $name);
        $this->columns[] = $column;
        return $column;
    }

    public function buildCreateSQL(): string
    {
        $columnsSQL = [];
        $foreigns = [];

        foreach ($this->columns as $col) {
            $build = $col->build();
            if (str_contains($build, "FOREIGN KEY")) {
                [$main, $fk] = explode(",\n    FOREIGN KEY", $build, 2);
                $columnsSQL[] = $main;
                $foreigns[] = "FOREIGN KEY" . $fk;
            } else {
                $columnsSQL[] = $build;
            }
        }

        $all = array_merge($columnsSQL, $this->constraints, $foreigns);
        return "CREATE TABLE `{$this->table}` (\n    " . implode(",\n    ", $all) . "\n)";
    }

    public function buildDropSQL(): string
    {
        return "DROP TABLE IF EXISTS `{$this->table}`";
    }
}
