<?php

namespace Nexus\ImportExport\Services;

use Nexus\ImportExport\Definitions\ImportDefinition;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;

final class ImportDefinitionRegistry
{
    /** @return class-string<ImportDefinition> */
    public function classFor(string $type): string
    {
        $class = config("bulk-imports.definitions.{$type}");

        if (! is_string($class) || ! is_subclass_of($class, ImportDefinition::class)) {
            throw new ImportConfigurationException("Import type [{$type}] is not registered.");
        }

        return $class;
    }

    public function make(string $type): ImportDefinition
    {
        $definition = app($this->classFor($type));
        $definition->validateConfiguration();

        return $definition;
    }

    public function makeClass(string $class): ImportDefinition
    {
        if (! is_subclass_of($class, ImportDefinition::class)) {
            throw new ImportConfigurationException("[{$class}] is not an ImportDefinition.");
        }

        $registered = array_values(config('bulk-imports.definitions', []));
        if (! in_array($class, $registered, true)) {
            throw new ImportConfigurationException("Import definition [{$class}] must be registered before dispatch.");
        }

        $definition = app($class);
        $definition->validateConfiguration();

        return $definition;
    }

    public function typeFor(string $class): string
    {
        $type = array_search($class, config('bulk-imports.definitions', []), true);

        if (! is_string($type)) {
            throw new ImportConfigurationException("Import definition [{$class}] is not registered.");
        }

        return $type;
    }
}
