<?php
namespace Bpjs\Framework\Helpers;

class ColumnDefinition
{
    protected string $name;
    protected string $type;
    protected array $modifiers = [];
    protected ?string $foreignKey = null;
    protected ?string $foreignTable = null;
    protected ?string $onDelete = null;
    protected bool $nullable = false; // ← tambah ini

    public function __construct(string $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function nullable()
    {
        $this->nullable = true;
        return $this;
    }

    public function notNullable()
    {
        $this->nullable = false;
        return $this;
    }

    public function default($value)
    {
        if (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            $this->modifiers[] = "DEFAULT CURRENT_TIMESTAMP";
        } else {
            $val = is_string($value) ? "'$value'" : $value;
            $this->modifiers[] = "DEFAULT $val";
        }
        return $this;
    }

    public function unique()
    {
        $this->modifiers[] = 'UNIQUE';
        return $this;
    }

    public function autoIncrement()
    {
        $this->modifiers[] = 'AUTO_INCREMENT';
        return $this;
    }

    public function primary()
    {
        $this->modifiers[] = 'PRIMARY KEY';
        return $this;
    }

    public function foreign()
    {
        $this->foreignKey = $this->name;
        return $this;
    }

    public function references(string $column)
    {
        $this->foreignKey = $column;
        return $this;
    }

    public function on(string $table)
    {
        $this->foreignTable = $table;
        return $this;
    }

    public function onDelete(string $action)
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function build(string $driver): string
    {
        $name = $this->wrap($this->name, $driver);
        $type = $this->mapType($this->type, $driver);

        $sql = "{$name} {$type}";

        if (!$this->isSerial($type)) {
            $sql .= $this->nullable ? ' NULL' : ' NOT NULL';
        }

        foreach ($this->modifiers as $modifier) {

            if ($driver === 'pgsql' && $modifier === 'AUTO_INCREMENT') {
                continue;
            }

            if ($driver === 'pgsql' && $modifier === 'PRIMARY KEY') {
                $sql .= ' PRIMARY KEY';
                continue;
            }

            $sql .= " {$modifier}";
        }

        if ($this->foreignKey && $this->foreignTable) {

            $fkName = $this->wrap($this->name, $driver);
            $fkTable = $this->wrap($this->foreignTable, $driver);
            $fkColumn = $this->wrap($this->foreignKey, $driver);

            $fk = "FOREIGN KEY ({$fkName}) REFERENCES {$fkTable}({$fkColumn})";

            if ($this->onDelete) {
                $fk .= " ON DELETE {$this->onDelete}";
            }

            return "{$sql},\n    {$fk}";
        }

        return $sql;
    }

    protected function wrap(string $value, string $driver): string
    {
        return match ($driver) {
            'mysql'  => "`$value`",
            'pgsql'  => "\"$value\"",
            default  => $value,
        };
    }

    protected function mapType(string $type, string $driver): string
    {
        if ($driver === 'pgsql') {
            if (in_array('AUTO_INCREMENT', $this->modifiers)) {

                if (str_contains($type, 'BIGINT')) {
                    return 'BIGSERIAL';
                }

                return 'SERIAL';
            }

            return match (strtoupper($type)) {
                'DATETIME' => 'TIMESTAMP',
                'TINYINT(1)' => 'BOOLEAN',
                default => $type
            };
        }

        return $type;
    }

    protected function isSerial(string $type): bool
    {
        return str_contains($type, 'SERIAL');
    }
}
