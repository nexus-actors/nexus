<?php
declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use Fp\Functional\Option\Option;
use LogicException;
use ReflectionClass;

/**
 * @psalm-api
 *
 * Bidirectional mapping between message class names and their wire-format type identifiers.
 */
final class TypeRegistry
{
    /** @var array<string, string> type name -> class name */
    private array $nameToClass = [];

    /** @var array<string, string> class name -> type name */
    private array $classToName = [];

    /**
     * Registers a bidirectional mapping between a class name and a type name.
     *
     * @throws LogicException If the type name is already registered
     */
    public function register(string $className, string $typeName): void
    {
        if (isset($this->nameToClass[$typeName])) {
            throw new LogicException(
                "Type name '{$typeName}' is already registered to class '{$this->nameToClass[$typeName]}'",
            );
        }

        $this->nameToClass[$typeName] = $className;
        $this->classToName[$className] = $typeName;
    }

    /**
     * Reads the #[MessageType] attribute from the given class and registers it.
     *
     * @param class-string $className
     *
     * @throws LogicException If the class has no #[MessageType] attribute or the type name is already registered
     */
    public function registerFromAttribute(string $className): void
    {
        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(MessageType::class);

        if ($attributes === []) {
            throw new LogicException("Class '{$className}' does not have a #[MessageType] attribute");
        }

        $messageType = $attributes[0]->newInstance();
        $this->register($className, $messageType->name);
    }

    /**
     * Returns the type name for a class, or None if not registered.
     *
     * @return Option<string>
     */
    public function nameForClass(string $className): Option
    {
        if (isset($this->classToName[$className])) {
            return Option::some($this->classToName[$className]);
        }

        /** @var Option<string> $none */
        $none = Option::none();

        return $none;
    }

    /**
     * Returns the class name for a type name, or None if not registered.
     *
     * @return Option<string>
     */
    public function classForName(string $typeName): Option
    {
        if (isset($this->nameToClass[$typeName])) {
            return Option::some($this->nameToClass[$typeName]);
        }

        /** @var Option<string> $none */
        $none = Option::none();

        return $none;
    }
}
