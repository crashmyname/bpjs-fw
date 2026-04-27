<?php
namespace Bpjs\Framework\Core;

use ReflectionClass;

class Container
{
    public function make(string $class)
    {
        $reflector = new ReflectionClass($class);

        if (! $reflector->isInstantiable()) {
            throw new \Exception("Class {$class} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if (! $constructor) {
            return new $class;
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (! $type) {
                throw new \Exception(
                    "Cannot resolve dependency {$param->getName()}"
                );
            }

            $dependencies[] = $this->make((string)$type);
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
