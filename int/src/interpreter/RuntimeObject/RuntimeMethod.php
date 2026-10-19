<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

use Closure;
use IPP\Interpreter\Execution\BlockExecutor;
use IPP\Interpreter\Runtime\ExecutionContext;

/**
 * Immutable runtime method descriptor with executable callback.
 */
final class RuntimeMethod
{
    private readonly Closure $executor;

    /**
     * @param array<int, string> $parameterNames
     */
    public function __construct(
        private readonly string $selector,
        private readonly int $arity,
        private readonly array $parameterNames,
        callable $executor
    ) {
        $this->executor = Closure::fromCallable($executor);
    }

    /**
     * Returns method selector.
     */
    public function getSelector(): string
    {
        return $this->selector;
    }

    /**
     * Returns required argument count for this method.
     */
    public function getArity(): int
    {
        return $this->arity;
    }

    /**
     * @return array<int, string>
     */
    public function getParameterNames(): array
    {
        return $this->parameterNames;
    }

    /**
     * Executes method body callback with prepared executor and context.
     */
    public function execute(BlockExecutor $blockExecutor, ExecutionContext $context): SolObject
    {
        return ($this->executor)($blockExecutor, $context);
    }
}
