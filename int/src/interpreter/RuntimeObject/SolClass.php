<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

/**
 * Runtime class metadata used for inheritance lookup and default instance behavior.
 */
final class SolClass
{
    /**
     * @param array<string, RuntimeMethod> $methodsBySelector
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $parentClassName,
        private readonly array $methodsBySelector = [],
        private readonly ?string $declaredInternalValueRoot = null,
        private readonly mixed $declaredDefaultInstanceValue = null,
        private readonly bool $hasDeclaredDefaultInstanceValue = false
    ) {
    }

    /**
     * Returns runtime class name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns direct parent class name or null for root classes.
     */
    public function getParentClassName(): ?string
    {
        return $this->parentClassName;
    }

    /**
     * Returns method by selector when declared directly on this class.
     */
    public function findMethod(string $selector): ?RuntimeMethod
    {
        return $this->methodsBySelector[$selector] ?? null;
    }

    /**
     * Returns class that defines compatible internal value payload, if any.
     */
    public function getDeclaredInternalValueRoot(): ?string
    {
        return $this->declaredInternalValueRoot;
    }

    /**
     * Returns true when class explicitly declares default instance value.
     */
    public function hasDeclaredDefaultInstanceValue(): bool
    {
        return $this->hasDeclaredDefaultInstanceValue;
    }

    /**
     * Returns class-declared default instance value.
     */
    public function getDeclaredDefaultInstanceValue(): mixed
    {
        return $this->declaredDefaultInstanceValue;
    }
}
