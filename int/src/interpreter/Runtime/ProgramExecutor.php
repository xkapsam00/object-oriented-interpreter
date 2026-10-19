<?php

declare(strict_types=1);

namespace IPP\Interpreter\Runtime;

use IPP\Interpreter\Execution\AttributeAccessResolver;
use IPP\Interpreter\Execution\BlockExecutor;
use IPP\Interpreter\Execution\BlockMessageDispatcher;
use IPP\Interpreter\Execution\BuiltinMethodDispatcher;
use IPP\Interpreter\Execution\ClassMessageDispatcher;
use IPP\Interpreter\Execution\ExpressionEvaluator;
use IPP\Interpreter\Execution\MessageDispatcher;
use IPP\Interpreter\Execution\MethodResolver;
use IPP\Interpreter\InputModel\Program;
use IPP\Interpreter\RuntimeObject\ClassObject;

class ProgramExecutor
{
    public function __construct()
    {
    }

    /**
     * Bootstraps the runtime by creating an instance of Main and sending it the run message.
     */
    public function execute(Program $program, Runtime $runtime): void
    {
        // Build method/attribute resolution services from the analyzed program model.
        $methodResolver = MethodResolver::forProgram($program);

        // Compose the central dispatcher that routes messages to built-in, class, or block handlers.
        $messageDispatcher = new MessageDispatcher(
            $methodResolver,
            new AttributeAccessResolver($methodResolver),
            new BuiltinMethodDispatcher($methodResolver, $runtime),
            new ClassMessageDispatcher($methodResolver, $runtime),
            new BlockMessageDispatcher()
        );

        // Expression and block execution both rely on message sends, so wire them around dispatcher.
        $expressionEvaluator = new ExpressionEvaluator($messageDispatcher);
        $blockExecutor = new BlockExecutor($expressionEvaluator);
        $messageDispatcher->attachBlockExecutor($blockExecutor);

        // Program entrypoint contract: instantiate Main and invoke its run method.
        $mainClass = new ClassObject('Main');
        $mainInstance = $expressionEvaluator->sendMessage($mainClass, 'new');
        $expressionEvaluator->sendMessage($mainInstance, 'run');
    }
}
