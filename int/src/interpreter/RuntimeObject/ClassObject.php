<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

/**
 * Runtime object representing class literal/identifier for class-side message sends.
 */
final class ClassObject extends SolObject
{
    public function __construct(private readonly string $representedClassName)
    {
        parent::__construct('Class', $representedClassName);
    }

    /**
     * Returns class name represented by this class object.
     */
    public function getRepresentedClassName(): string
    {
        return $this->representedClassName;
    }
}
