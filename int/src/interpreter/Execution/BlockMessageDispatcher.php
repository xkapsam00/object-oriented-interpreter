<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\RuntimeObject\BlockObject;
use IPP\Interpreter\RuntimeObject\SolObject;

/**
 * Handles selector-to-arity mapping for block invocation messages.
 */
final class BlockMessageDispatcher
{
    /**
     * Dispatches block value/value:/value:value:... messages and executes closure body.
     *
     * @param array<int, SolObject> $arguments
     */
    public function dispatchBlockMessage(
        BlockExecutor $blockExecutor,
        BlockObject $receiver,
        string $selector,
        array $arguments
    ): SolObject {
        if ($selector !== $this->buildBlockValueSelector(count($arguments))) {
            throw new InterpreterError(
                ErrorCode::INT_DNU,
                "Block does not understand selector {$selector}."
            );
        }

        return $blockExecutor->executeClosure($receiver, $arguments);
    }

    /**
     * Builds canonical block selector string for a given argument arity.
     */
    private function buildBlockValueSelector(int $arity): string
    {
        if ($arity === 0) {
            return 'value';
        }

        return str_repeat('value:', $arity);
    }
}
