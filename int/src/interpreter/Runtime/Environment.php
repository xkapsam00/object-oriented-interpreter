<?php

declare(strict_types=1);

namespace IPP\Interpreter\Runtime;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\RuntimeObject\SolObject;

/**
 * Lexical variable scope with optional parent-chain lookup.
 */
class Environment
{
    /**
     * @var array<string, SolObject>
     */
    private array $locals = [];

    public function __construct(private readonly ?Environment $parent = null)
    {
    }

    /**
     * Defines or overwrites a local variable in current scope.
     */
    public function define(string $name, SolObject $value): void
    {
        $this->locals[$name] = $value;
    }

    /**
     * Assigns variable to nearest existing scope, otherwise creates local.
     */
    public function write(string $name, SolObject $value): void
    {
        if ($this->assignExisting($name, $value)) {
            return;
        }

        $this->locals[$name] = $value;
    }

    /**
     * Resolves variable in current scope and then recursively in parent scopes.
     */
    public function lookup(string $name): SolObject
    {
        if (array_key_exists($name, $this->locals)) {
            return $this->locals[$name];
        }

        if ($this->parent !== null) {
            return $this->parent->lookup($name);
        }

        throw new InterpreterError(
            ErrorCode::SEM_UNDEF,
            "Undefined variable: {$name}"
        );
    }

    /**
     * Tries to assign into an already-defined variable in this scope chain.
     */
    private function assignExisting(string $name, SolObject $value): bool
    {
        if (array_key_exists($name, $this->locals)) {
            $this->locals[$name] = $value;
            return true;
        }

        if ($this->parent !== null) {
            return $this->parent->assignExisting($name, $value);
        }

        return false;
    }
}
