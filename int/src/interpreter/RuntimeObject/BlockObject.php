<?php

declare(strict_types=1);

namespace IPP\Interpreter\RuntimeObject;

use IPP\Interpreter\InputModel\Block;
use IPP\Interpreter\Runtime\Environment;
use IPP\Interpreter\Runtime\ExecutionContext;

/**
 * Runtime closure object storing block AST and captured execution state.
 */
final class BlockObject extends SolObject
{
    public function __construct(
        private readonly Block $block,
        private readonly Environment $capturedEnvironment,
        private readonly ?ExecutionContext $homeContext = null,
    ) {
        parent::__construct('Block', null);
    }

    /**
     * Returns block AST definition to execute.
     */
    public function getBlock(): Block
    {
        return $this->block;
    }

    /**
     * Returns lexical environment captured when block literal was created.
     */
    public function getCapturedEnvironment(): Environment
    {
        return $this->capturedEnvironment;
    }

    /**
     * Returns method context captured for self/super semantics, if available.
     */
    public function getHomeContext(): ?ExecutionContext
    {
        return $this->homeContext;
    }
}
