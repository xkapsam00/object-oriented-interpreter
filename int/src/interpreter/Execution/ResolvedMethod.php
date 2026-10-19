<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\RuntimeObject\RuntimeMethod;

/**
 * Immutable lookup result containing both resolved method body and its owner class.
 */
final class ResolvedMethod
{
    /**
     * @param RuntimeMethod $method Runtime callable selected by inheritance lookup.
     * @param string $ownerClassName Class where the selector was found.
     */
    public function __construct(
        private readonly RuntimeMethod $method,
        private readonly string $ownerClassName
    ) {
    }

    /**
     * Returns the runtime method selected by method lookup.
     */
    public function getMethod(): RuntimeMethod
    {
        return $this->method;
    }

    /**
     * Returns class name that owns the selected method implementation.
     */
    public function getOwnerClassName(): string
    {
        return $this->ownerClassName;
    }
}
