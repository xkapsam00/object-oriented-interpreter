<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\InputModel\Expr;
use IPP\Interpreter\Runtime\Environment;
use IPP\Interpreter\Runtime\ExecutionContext;
use IPP\Interpreter\RuntimeObject\BlockObject;
use IPP\Interpreter\RuntimeObject\PlainObject;
use IPP\Interpreter\RuntimeObject\SolObject;

class ExpressionEvaluator
{
    public function __construct(private readonly MessageDispatcher $messageDispatcher)
    {
    }

    /**
     * Sends a message to receiver using central message dispatch rules.
     *
     * @param array<int, SolObject> $arguments
     */
    public function sendMessage(
        SolObject $receiver,
        string $selector,
        array $arguments = [],
        ?ExecutionContext $context = null
    ): SolObject {
        return $this->messageDispatcher->sendMessage($receiver, $selector, $arguments, $context);
    }

    /**
     * Evaluates expression in method context using context environment and receiver metadata.
     */
    public function evaluate(Expr $exprDef, ExecutionContext $context): SolObject
    {
        return $this->evaluateInternal($exprDef, $context->getEnvironment(), $context);
    }

    /**
     * Evaluates expression in standalone environment without method execution context.
     */
    public function evaluateInEnvironment(Expr $exprDef, Environment $environment): SolObject
    {
        return $this->evaluateInternal($exprDef, $environment);
    }

    /**
     * Dispatches expression evaluation by expression kind.
     */
    private function evaluateInternal(
        Expr $exprDef,
        Environment $environment,
        ?ExecutionContext $context = null
    ): SolObject {
        if ($exprDef->literal !== null) {
            return $this->createLiteralObject($exprDef);
        }

        if ($exprDef->variable !== null) {
            return $this->evaluateVariable($exprDef, $environment, $context);
        }

        if ($exprDef->send !== null) {
            return $this->evaluateSend($exprDef, $environment, $context);
        }

        if ($exprDef->block !== null) {
            // Block captures current lexical environment and optional method context.
            return new BlockObject($exprDef->block, $environment, $context);
        }

        throw new InterpreterError(
            ErrorCode::INT_OTHER,
            'Invalid expression: no content'
        );
    }

    private function evaluateSend(
        Expr $exprDef,
        Environment $environment,
        ?ExecutionContext $context = null
    ): SolObject {
        $send = $exprDef->send;
        if ($send === null) {
            throw new InterpreterError(ErrorCode::INT_OTHER, 'Expected send expression.');
        }

        return $this->evaluateSendExprInternal(
            $send->receiver,
            $send->selector,
            $this->evaluateSendArguments($exprDef, $environment, $context),
            $environment,
            $context
        );
    }

    /**
     * Converts literal expression into a corresponding runtime object.
     */
    private function createLiteralObject(Expr $exprDef): SolObject
    {
        $literal = $exprDef->literal;
        if ($literal === null) {
            throw new InterpreterError(ErrorCode::INT_OTHER, 'Expected literal expression.');
        }

        return match ($literal->classId) {
            'Integer' => PlainObject::integer((int) $literal->value),
            'String' => PlainObject::string($literal->value),
            'Nil' => PlainObject::nil(),
            'True' => PlainObject::boolean(true),
            'False' => PlainObject::boolean(false),
            'class' => $this->messageDispatcher->createClassObject($literal->value),
            default => throw new InterpreterError(
                ErrorCode::INT_OTHER,
                "Unsupported literal class: {$literal->classId}"
            ),
        };
    }

    /**
     * Resolves variable expressions from context/environment, including self/super variables.
     */
    private function evaluateVariable(
        Expr $exprDef,
        Environment $environment,
        ?ExecutionContext $context = null
    ): SolObject {
        $variable = $exprDef->variable;
        if ($variable === null) {
            throw new InterpreterError(ErrorCode::INT_OTHER, 'Expected variable expression.');
        }
        $varName = $variable->name;

        if (($varName === 'self' || $varName === 'super') && $context !== null) {
            return $context->getReceiver();
        }

        return $context !== null
            ? $context->lookup($varName)
            : $environment->lookup($varName);
    }

    /**
     * Evaluates send argument expressions from left to right.
     *
     * @return array<int, SolObject>
     */
    private function evaluateSendArguments(
        Expr $exprDef,
        Environment $environment,
        ?ExecutionContext $context = null
    ): array {
        $arguments = [];
        $send = $exprDef->send;
        if ($send === null) {
            throw new InterpreterError(ErrorCode::INT_OTHER, 'Expected send expression.');
        }

        foreach ($send->args as $argDef) {
            $arguments[] = $this->evaluateInternal($argDef->expr, $environment, $context);
        }

        return $arguments;
    }

    /**
     * Evaluates send receiver and dispatches special self/super sends when applicable.
     *
     * @param array<int, SolObject> $arguments
     */
    private function evaluateSendExprInternal(
        Expr $receiverExpr,
        string $selector,
        array $arguments,
        Environment $environment,
        ?ExecutionContext $context = null
    ): SolObject {
        if (
            $receiverExpr->variable !== null
            && $receiverExpr->variable->name === 'super'
            && $context !== null
        ) {
            // super message keeps dynamic receiver but shifts method lookup to parent class.
            return $this->messageDispatcher->sendSuperMessage($context, $selector, $arguments);
        }

        $receiver = $this->evaluateInternal($receiverExpr, $environment, $context);

        if (
            $receiverExpr->variable !== null
            && $receiverExpr->variable->name === 'self'
            && $context !== null
        ) {
            // self message preserves lookup semantics implemented by dispatcher.
            return $this->messageDispatcher->sendSelfMessage($context, $selector, $arguments);
        }

        return $this->messageDispatcher->sendMessage($receiver, $selector, $arguments, $context);
    }
}
