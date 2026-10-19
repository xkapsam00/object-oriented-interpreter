<?php

declare(strict_types=1);

namespace IPP\Interpreter\Runtime;

use IPP\Interpreter\RuntimeObject\SolObject;

/**
 * Immutable method execution context carrying receiver, current class, and variable scope.
 */
final class ExecutionContext
{
    public function __construct(
        private readonly string $currentClassName,
        private readonly SolObject $receiver,
        private readonly Environment $environment
    ) {
    }

    /**
     * Returns class where currently executing method was resolved.
     */
    public function getCurrentClassName(): string
    {
        return $this->currentClassName;
    }

    /**
     * Returns dynamic receiver object for current execution.
     */
    public function getReceiver(): SolObject
    {
        return $this->receiver;
    }

    /**
     * Returns environment used for variable lookup/definition.
     */
    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    /**
     * Defines variable in current execution environment.
     */
    public function define(string $name, SolObject $value): void
    {
        $this->environment->define($name, $value);
    }

    /**
     * Assigns variable using lexical scope chain (nearest existing binding).
     */
    public function write(string $name, SolObject $value): void
    {
        $this->environment->write($name, $value);
    }

    /**
     * Looks up variable using current execution environment chain.
     */
    public function lookup(string $name): SolObject
    {
        return $this->environment->lookup($name);
    }

    /**
     * Creates cloned context with same receiver/class but different environment.
     */
    public function withEnvironment(Environment $environment): self
    {
        return new self(
            $this->currentClassName,
            $this->receiver,
            $environment
        );
    }
}
