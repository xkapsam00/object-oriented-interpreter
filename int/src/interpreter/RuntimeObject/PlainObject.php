<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

use InvalidArgumentException;

/**
 * Generic mutable runtime instance with optional attributes and internal value.
 */
final class PlainObject extends SolObject
{
    private static ?self $nilSingleton = null;
    private static ?self $trueSingleton = null;
    private static ?self $falseSingleton = null;

    /**
     * @var array<string, SolObject>
     */
    private array $attributes = [];

    public function __construct(string $className, mixed $value)
    {
        parent::__construct($className, $value);
    }

    /**
     * Creates plain runtime object for arbitrary class/value combination.
     */
    public static function plain(string $className, mixed $value): self
    {
        if ($className === 'Nil') {
            return self::nil();
        }

        if ($className === 'True') {
            return self::boolean(true);
        }

        if ($className === 'False') {
            return self::boolean(false);
        }

        return new self($className, $value);
    }

    /**
     * Creates Integer runtime object.
     */
    public static function integer(int $value): self
    {
        return self::plain('Integer', $value);
    }

    /**
     * Creates String runtime object.
     */
    public static function string(string $value): self
    {
        return self::plain('String', $value);
    }

    /**
     * Creates Nil runtime object.
     */
    public static function nil(): self
    {
        if (self::$nilSingleton === null) {
            self::$nilSingleton = new self('Nil', null);
        }

        return self::$nilSingleton;
    }

    /**
     * Creates canonical boolean runtime object (True/False).
     */
    public static function boolean(bool $value): self
    {
        if ($value) {
            if (self::$trueSingleton === null) {
                self::$trueSingleton = new self('True', true);
            }

            return self::$trueSingleton;
        }

        if (self::$falseSingleton === null) {
            self::$falseSingleton = new self('False', false);
        }

        return self::$falseSingleton;
    }

    /**
     * Returns true when instance attribute exists.
     */
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Returns attribute value or throws when attribute is undefined.
     */
    public function getAttribute(string $name): SolObject
    {
        if (!$this->hasAttribute($name)) {
            throw new InvalidArgumentException("Undefined attribute: {$name}");
        }

        return $this->attributes[$name];
    }

    /**
     * Defines or overwrites instance attribute.
     */
    public function setAttribute(string $name, SolObject $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Updates internal object value payload.
     */
    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    /**
     * Performs a shallow copy of instance attributes.
     */
    public function copyAttributesFrom(PlainObject $other): void
    {
        $this->attributes = $other->attributes;
    }
}
