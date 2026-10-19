<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

/**
 * Base runtime object carrying dynamic class name and optional internal value.
 */
abstract class SolObject
{
    protected function __construct(protected string $className, protected mixed $value)
    {
    }

    /**
     * Returns dynamic runtime class name of this object.
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Returns internal object value (primitive payload or null).
     */
    public function getValue(): mixed
    {
        return $this->value;
    }
}
