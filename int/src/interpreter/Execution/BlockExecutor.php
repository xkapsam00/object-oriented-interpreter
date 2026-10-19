<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\InputModel\Block;
use IPP\Interpreter\InputModel\Expr;
use IPP\Interpreter\Runtime\Environment;
use IPP\Interpreter\Runtime\ExecutionContext;
use IPP\Interpreter\RuntimeObject\BlockObject;
use IPP\Interpreter\RuntimeObject\PlainObject;
use IPP\Interpreter\RuntimeObject\SolObject;

class BlockExecutor
{
    public function __construct(private readonly ExpressionEvaluator $expressionEvaluator)
    {
    }

    /**
     * Executes a block in the provided environment and returns the value
     * of the last evaluated assignment expression.
     */
    public function execute(Block $blockDef, ExecutionContext $context): SolObject
    {
        // Method/block execution in context uses context-aware expression lookup rules.
        return $this->executeAssignments(
            $blockDef,
            function (Expr $exprDef) use ($context): SolObject {
                return $this->expressionEvaluator->evaluate($exprDef, $context);
            },
            static function (string $name, SolObject $value) use ($context): void {
                $context->write($name, $value);
            }
        );
    }

    /**
     * Executes block in plain environment without method receiver/class context.
     */
    public function executeInEnvironment(Block $blockDef, Environment $environment): SolObject
    {
        return $this->executeAssignments(
            $blockDef,
            function (Expr $exprDef) use ($environment): SolObject {
                return $this->expressionEvaluator->evaluateInEnvironment($exprDef, $environment);
            },
            static function (string $name, SolObject $value) use ($environment): void {
                $environment->write($name, $value);
            }
        );
    }

    /**
     * Executes captured block object with provided call arguments.
     *
     * @param array<int, SolObject> $arguments
     */
    public function executeClosure(BlockObject $closure, array $arguments): SolObject
    {
        $blockDef = $closure->getBlock();
        // Invocation scope extends captured lexical scope and holds call-time parameters.
        $environment = new Environment($closure->getCapturedEnvironment());
        $context = $closure->getHomeContext();

        if (count($arguments) !== $blockDef->arity) {
            throw new InterpreterError(
                ErrorCode::INT_DNU,
                'Closure argument arity mismatch.'
            );
        }

        foreach ($blockDef->parameters as $index => $parameterDef) {
            $environment->define($parameterDef->name, $arguments[$index]);
        }

        if ($context !== null) {
            // Preserve home receiver/class context while swapping to invocation environment.
            $context = $context->withEnvironment($environment);
            return $this->execute($blockDef, $context);
        }

        return $this->executeInEnvironment($blockDef, $environment);
    }

    /**
     * Runs block assignments in order and returns value of the last assignment.
     *
     * @param callable(Expr): SolObject $evaluateExpr
     * @param callable(string, SolObject): void $defineValue
     */
    private function executeAssignments(
        Block $blockDef,
        callable $evaluateExpr,
        callable $defineValue
    ): SolObject {
        $value = PlainObject::nil();

        foreach ($blockDef->assigns as $assignDef) {
            $value = $evaluateExpr($assignDef->expr);
            $defineValue($assignDef->target->name, $value);
        }

        return $value;
    }
}
