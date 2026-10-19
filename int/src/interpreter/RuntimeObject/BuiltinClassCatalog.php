<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

/**
 * Defines built-in class metadata and class-side selector capabilities.
 */
final class BuiltinClassCatalog
{
    /**
     * Returns runtime metadata for all built-in classes.
     *
     * @return array<string, SolClass>
     */
    public static function createRuntimeClasses(): array
    {
        return [
            'Object' => new SolClass('Object', null, [], null, null, true),
            'Integer' => new SolClass('Integer', 'Object', [], 'Integer', 0, true),
            'String' => new SolClass('String', 'Object', [], 'String', '', true),
            'Nil' => new SolClass('Nil', 'Object', [], 'Nil', null, true),
            'Block' => new SolClass('Block', 'Object', [], null, null, true),
            'True' => new SolClass('True', 'Object', [], 'True', true, true),
            'False' => new SolClass('False', 'Object', [], 'False', false, true),
            'Class' => new SolClass('Class', 'Object', [], null, null, true),
        ];
    }

    /**
     * Returns user-visible built-in class names.
     *
     * @return list<string>
     */
    public static function userVisibleClassNames(): array
    {
        return ['Object', 'Integer', 'String', 'Nil', 'True', 'False', 'Block'];
    }

    /**
     * Returns true when class name belongs to user-visible built-in classes.
     */
    public static function isUserVisibleBuiltinClass(string $className): bool
    {
        return in_array($className, self::userVisibleClassNames(), true);
    }

    /**
     * Returns true when class-side selector is valid for built-in class.
     */
    public static function isValidStaticSelector(string $className, string $selector): bool
    {
        return in_array($selector, self::staticSelectorsFor($className), true);
    }

    /**
     * Returns class-side selectors supported by specific built-in class.
     *
     * @return list<string>
     */
    public static function staticSelectorsFor(string $className): array
    {
        return match ($className) {
            'Object', 'Integer', 'Nil', 'True', 'False', 'Block' => ['new', 'from:'],
            'String' => ['new', 'from:', 'read'],
            default => [],
        };
    }
}
