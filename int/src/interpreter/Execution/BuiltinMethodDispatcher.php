<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\Runtime\Runtime;
use IPP\Interpreter\RuntimeObject\BlockObject;
use IPP\Interpreter\RuntimeObject\PlainObject;
use IPP\Interpreter\RuntimeObject\SolObject;

/**
 * Handles built-in message semantics for runtime objects.
 */
final class BuiltinMethodDispatcher
{
    public function __construct(
        private readonly MethodResolver $methodResolver,
        private readonly Runtime $runtime
    ) {
    }

    /**
     * Tries to resolve built-in selector for receiver class and returns null when not handled.
     *
     * @param array<int, SolObject> $arguments
     */
    public function dispatchBuiltinInstanceMessage(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        string $selector,
        array $arguments
    ): ?SolObject {
        $argumentCount = count($arguments);
        $booleanResult = $this->dispatchBooleanBuiltinMessage(
            $messageDispatcher,
            $receiver,
            $selector,
            $arguments,
            $argumentCount
        );
        if ($booleanResult !== null) {
            return $booleanResult;
        }

        if ($this->isIntegerObject($receiver)) {
            return $this->dispatchIntegerBuiltinMessage(
                $messageDispatcher,
                $receiver,
                $selector,
                $arguments,
                $argumentCount
            ) ?? $this->dispatchObjectBuiltinMessage($receiver, $selector, $arguments, $argumentCount)
                ?? $this->dispatchIntrospectionBuiltinMessage($receiver, $selector, $argumentCount);
        }

        if ($this->isStringObject($receiver)) {
            return $this->dispatchStringBuiltinMessage($receiver, $selector, $arguments, $argumentCount)
                ?? $this->dispatchStringObjectBuiltinMessage($receiver, $selector, $argumentCount)
                ?? $this->dispatchObjectBuiltinMessage($receiver, $selector, $arguments, $argumentCount)
                ?? $this->dispatchIntrospectionBuiltinMessage($receiver, $selector, $argumentCount);
        }

        if ($this->isBlockReceiver($receiver)) {
            return $this->dispatchBlockBuiltinMessage(
                $messageDispatcher,
                $receiver,
                $selector,
                $arguments,
                $argumentCount
            ) ?? $this->dispatchObjectBuiltinMessage($receiver, $selector, $arguments, $argumentCount)
                ?? $this->dispatchIntrospectionBuiltinMessage($receiver, $selector, $argumentCount);
        }

        return $this->dispatchObjectBuiltinMessage($receiver, $selector, $arguments, $argumentCount)
            ?? $this->dispatchIntrospectionBuiltinMessage($receiver, $selector, $argumentCount);
    }

    /**
     * Dispatches selectors shared by all objects.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchObjectBuiltinMessage(
        SolObject $receiver,
        string $selector,
        array $arguments,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'identicalTo:' => $argumentCount === 1 ? $this->booleanObject($receiver === $arguments[0]) : null,
            'equalTo:' => $argumentCount === 1 ? $this->dispatchBuiltinEqualTo($receiver, $arguments[0]) : null,
            'asString' => $argumentCount === 0 ? $this->dispatchBuiltinAsString($receiver) : null,
            default => null,
        };
    }

    /**
     * Dispatches integer-specific selectors.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchIntegerBuiltinMessage(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        string $selector,
        array $arguments,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'greaterThan:' => $argumentCount === 1 ? $this->dispatchBuiltinGreaterThan($receiver, $arguments[0]) : null,
            'plus:' => $argumentCount === 1
                ? $this->dispatchBuiltinIntegerBinaryOp(
                    $receiver,
                    $arguments[0],
                    static fn (int $a, int $b): int => $a + $b
                )
                : null,
            'minus:' => $argumentCount === 1
                ? $this->dispatchBuiltinIntegerBinaryOp(
                    $receiver,
                    $arguments[0],
                    static fn (int $a, int $b): int => $a - $b
                )
                : null,
            'multiplyBy:' => $argumentCount === 1
                ? $this->dispatchBuiltinIntegerBinaryOp(
                    $receiver,
                    $arguments[0],
                    static fn (int $a, int $b): int => $a * $b
                )
                : null,
            'divBy:' => $argumentCount === 1 ? $this->dispatchBuiltinDivBy($receiver, $arguments[0]) : null,
            'timesRepeat:' => $argumentCount === 1
                ? $this->dispatchBuiltinTimesRepeat($messageDispatcher, $receiver, $arguments[0])
                : null,
            default => null,
        };
    }

    /**
     * Dispatches string-specific selectors.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchStringBuiltinMessage(
        SolObject $receiver,
        string $selector,
        array $arguments,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'concatenateWith:' => $argumentCount === 1
                ? $this->dispatchBuiltinConcatenateWith($receiver, $arguments[0])
                : null,
            'startsWith:endsBefore:' => $argumentCount === 2
                ? $this->dispatchBuiltinStartsWithEndsBefore($receiver, $arguments[0], $arguments[1])
                : null,
            'length' => $argumentCount === 0 ? $this->dispatchBuiltinLength($receiver) : null,
            default => null,
        };
    }

    /**
     * Dispatches selectors available only on concrete String objects.
     */
    private function dispatchStringObjectBuiltinMessage(
        SolObject $receiver,
        string $selector,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'asInteger' => $argumentCount === 0 ? $this->dispatchBuiltinAsInteger($receiver) : null,
            'print' => $argumentCount === 0 ? $this->dispatchBuiltinPrint($receiver) : null,
            default => null,
        };
    }

    /**
     * Dispatches block-specific selectors.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchBlockBuiltinMessage(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        string $selector,
        array $arguments,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'whileTrue:' => $argumentCount === 1
                ? $this->dispatchBuiltinWhileTrue($messageDispatcher, $receiver, $arguments[0])
                : null,
            default => null,
        };
    }

    /**
     * Dispatches generic introspection selectors (isNumber, isString, ...).
     */
    private function dispatchIntrospectionBuiltinMessage(
        SolObject $receiver,
        string $selector,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'isNumber' => $argumentCount === 0 ? $this->booleanObject($this->isIntegerObject($receiver)) : null,
            'isString' => $argumentCount === 0 ? $this->booleanObject($this->isStringObject($receiver)) : null,
            'isBlock' => $argumentCount === 0 ? $this->booleanObject($this->isBlockReceiver($receiver)) : null,
            'isNil' => $argumentCount === 0 ? $this->booleanObject($this->isNilObject($receiver)) : null,
            'isBoolean' => $argumentCount === 0 ? $this->booleanObject($this->isBooleanReceiver($receiver)) : null,
            default => null,
        };
    }

    /**
     * Dispatches boolean selectors by first resolving receiver truth value.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchBooleanBuiltinMessage(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        string $selector,
        array $arguments,
        int $argumentCount
    ): ?SolObject {
        if ($this->methodResolver->isSubclassOfOrSame($receiver->getClassName(), 'True')) {
            return $this->dispatchBooleanBuiltinByValue(
                $messageDispatcher,
                true,
                $selector,
                $arguments,
                $argumentCount
            );
        }

        if ($this->methodResolver->isSubclassOfOrSame($receiver->getClassName(), 'False')) {
            return $this->dispatchBooleanBuiltinByValue(
                $messageDispatcher,
                false,
                $selector,
                $arguments,
                $argumentCount
            );
        }

        return null;
    }

    /**
     * Dispatches boolean behavior for concrete true/false value.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchBooleanBuiltinByValue(
        MessageDispatcher $messageDispatcher,
        bool $booleanValue,
        string $selector,
        array $arguments,
        int $argumentCount
    ): ?SolObject {
        return match ($selector) {
            'not' => $argumentCount === 0 ? $this->booleanObject(!$booleanValue) : null,
            'and:' => $argumentCount === 1
                ? $this->dispatchBooleanAnd($messageDispatcher, $booleanValue, $arguments[0])
                : null,
            'or:' => $argumentCount === 1
                ? $this->dispatchBooleanOr($messageDispatcher, $booleanValue, $arguments[0])
                : null,
            'ifTrue:ifFalse:' => $argumentCount === 2
                ? $this->dispatchBooleanIfTrueIfFalse($messageDispatcher, $booleanValue, $arguments[0], $arguments[1])
                : null,
            default => null,
        };
    }

    /**
     * Implements short-circuit boolean and: evaluation.
     */
    private function dispatchBooleanAnd(
        MessageDispatcher $messageDispatcher,
        bool $booleanValue,
        SolObject $argument
    ): SolObject {
        if (!$booleanValue) {
            return $this->booleanObject(false);
        }

        return $this->sendMessage($messageDispatcher, $argument, 'value');
    }

    /**
     * Implements short-circuit boolean or: evaluation.
     */
    private function dispatchBooleanOr(
        MessageDispatcher $messageDispatcher,
        bool $booleanValue,
        SolObject $argument
    ): SolObject {
        if ($booleanValue) {
            return $this->booleanObject(true);
        }

        return $this->sendMessage($messageDispatcher, $argument, 'value');
    }

    /**
     * Evaluates one of two branch blocks according to receiver boolean value.
     */
    private function dispatchBooleanIfTrueIfFalse(
        MessageDispatcher $messageDispatcher,
        bool $booleanValue,
        SolObject $trueBranch,
        SolObject $falseBranch
    ): SolObject {
        return $this->sendMessage(
            $messageDispatcher,
            $booleanValue ? $trueBranch : $falseBranch,
            'value'
        );
    }

    /**
     * Implements value equality semantics used by equalTo:.
     */
    private function dispatchBuiltinEqualTo(SolObject $receiver, SolObject $argument): SolObject
    {
        if ($this->isIntegerObject($receiver) && $this->isIntegerObject($argument)) {
            return $this->booleanObject($receiver->getValue() === $argument->getValue());
        }

        if ($this->isStringObject($receiver) && $this->isStringObject($argument)) {
            return $this->booleanObject($receiver->getValue() === $argument->getValue());
        }

        return $this->booleanObject($receiver === $argument);
    }

    /**
     * Converts receiver to String according to built-in conversion rules.
     */
    private function dispatchBuiltinAsString(SolObject $receiver): SolObject
    {
        if ($this->isStringObject($receiver)) {
            return $receiver;
        }

        if ($this->isIntegerObject($receiver)) {
            return PlainObject::string((string) $receiver->getValue());
        }

        if ($this->isNilObject($receiver)) {
            return PlainObject::string('nil');
        }

        if ($this->isTrueObject($receiver)) {
            return PlainObject::string('true');
        }

        if ($this->isFalseObject($receiver)) {
            return PlainObject::string('false');
        }

        return PlainObject::string('');
    }

    /**
     * Parses integer from String receiver or returns nil on invalid input.
     */
    private function dispatchBuiltinAsInteger(SolObject $receiver): SolObject
    {
        $value = (string) $receiver->getValue();
        if (preg_match('/^[+-]?[0-9]+$/', $value) === 1) {
            return PlainObject::integer((int) $value);
        }

        return PlainObject::nil();
    }

    /**
     * Compares two integer objects and returns boolean result.
     */
    private function dispatchBuiltinGreaterThan(SolObject $receiver, SolObject $argument): SolObject
    {
        [$leftValue, $rightValue] = $this->requireIntegerPair($receiver, $argument);

        return $this->booleanObject($leftValue > $rightValue);
    }

    /**
     * Applies integer binary operation and wraps result back to Integer object.
     *
     * @param callable(int, int): int $operation
     */
    private function dispatchBuiltinIntegerBinaryOp(
        SolObject $receiver,
        SolObject $argument,
        callable $operation
    ): SolObject {
        [$leftValue, $rightValue] = $this->requireIntegerPair($receiver, $argument);

        return PlainObject::integer($operation($leftValue, $rightValue));
    }

    /**
     * Divides integer receiver by integer argument with zero guard.
     */
    private function dispatchBuiltinDivBy(SolObject $receiver, SolObject $argument): SolObject
    {
        [$leftValue, $rightValue] = $this->requireIntegerPair($receiver, $argument);

        if ($rightValue === 0) {
            throw new InterpreterError(
                ErrorCode::INT_INVALID_ARG,
                'Division by zero.'
            );
        }

        return PlainObject::integer(intdiv($leftValue, $rightValue));
    }

    /**
     * Repeatedly evaluates condition block and body block while condition is True.
     */
    private function dispatchBuiltinWhileTrue(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        SolObject $argument
    ): SolObject {
        $lastValue = PlainObject::nil();

        while (true) {
            $condition = $this->sendMessage($messageDispatcher, $receiver, 'value');

            if ($this->methodResolver->isSubclassOfOrSame($condition->getClassName(), 'False')) {
                return $lastValue;
            }

            if (!$this->methodResolver->isSubclassOfOrSame($condition->getClassName(), 'True')) {
                throw new InterpreterError(
                    ErrorCode::INT_OTHER,
                    'whileTrue: condition must evaluate to True or False.'
                );
            }

            $lastValue = $this->sendMessage($messageDispatcher, $argument, 'value');
        }
    }

    /**
     * Invokes block argument count times with 1-based iteration index.
     */
    private function dispatchBuiltinTimesRepeat(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        SolObject $argument
    ): SolObject {
        if (!$this->understandsValueWithOneArg($argument)) {
            throw new InterpreterError(
                ErrorCode::INT_DNU,
                'timesRepeat: expects an argument that understands value:.'
            );
        }

        $count = (int) $receiver->getValue();
        $lastValue = PlainObject::nil();

        for ($iteration = 1; $iteration <= $count; $iteration++) {
            $lastValue = $this->sendMessage(
                $messageDispatcher,
                $argument,
                'value:',
                [PlainObject::integer($iteration)]
            );
        }

        return $lastValue;
    }

    /**
     * Checks whether receiver supports message value: with one argument.
     */
    private function understandsValueWithOneArg(SolObject $receiver): bool
    {
        if ($receiver instanceof BlockObject) {
            return $receiver->getBlock()->arity === 1;
        }

        return $this->methodResolver->findMethodDefinition($receiver->getClassName(), 'value:') !== null;
    }

    /**
     * Validates numeric operands and extracts raw integer values.
     *
     * @return array{0: int, 1: int}
     */
    private function requireIntegerPair(SolObject $receiver, SolObject $argument): array
    {
        if (!$this->methodResolver->isSubclassOfOrSame($argument->getClassName(), 'Integer')) {
            throw new InterpreterError(
                ErrorCode::INT_OTHER,
                'Numeric operation requires Integer argument.'
            );
        }

        return [(int) $receiver->getValue(), (int) $argument->getValue()];
    }

    /**
     * Concatenates two strings or returns nil when any operand is not String-compatible.
     */
    private function dispatchBuiltinConcatenateWith(SolObject $receiver, SolObject $argument): SolObject
    {
        if (
            !$this->methodResolver->isSubclassOfOrSame($receiver->getClassName(), 'String')
            || !$this->methodResolver->isSubclassOfOrSame($argument->getClassName(), 'String')
        ) {
            return PlainObject::nil();
        }

        return PlainObject::string((string) $receiver->getValue() . (string) $argument->getValue());
    }

    /**
     * Returns substring [start, endBefore) using 1-based indices, or nil on invalid bounds.
     */
    private function dispatchBuiltinStartsWithEndsBefore(
        SolObject $receiver,
        SolObject $startArgument,
        SolObject $endArgument
    ): SolObject {
        if (!$this->isIntegerObject($startArgument) || !$this->isIntegerObject($endArgument)) {
            return PlainObject::nil();
        }

        $start = (int) $startArgument->getValue();
        $endBefore = (int) $endArgument->getValue();

        if ($start <= 0 || $endBefore <= 0) {
            return PlainObject::nil();
        }

        if (($endBefore - $start) <= 0) {
            return PlainObject::string('');
        }

        $value = (string) $receiver->getValue();
        $length = strlen($value);
        $sliceStart = $start - 1;
        $sliceEndExclusive = min($endBefore - 1, $length);
        $sliceLength = max(0, $sliceEndExclusive - $sliceStart);

        return PlainObject::string(substr($value, $sliceStart, $sliceLength));
    }

    /**
     * Returns String length as Integer object.
     */
    private function dispatchBuiltinLength(SolObject $receiver): SolObject
    {
        return PlainObject::integer(strlen((string) $receiver->getValue()));
    }

    /**
     * Writes String receiver to runtime output and returns receiver.
     */
    private function dispatchBuiltinPrint(SolObject $receiver): SolObject
    {
        $this->runtime->write((string) $receiver->getValue());
        return $receiver;
    }

    /**
     * Creates canonical runtime boolean object.
     */
    private function booleanObject(bool $value): SolObject
    {
        return PlainObject::boolean($value);
    }

    /**
     * Checks whether receiver is instance of True or False class hierarchy.
     */
    private function isBooleanReceiver(SolObject $receiver): bool
    {
        return $this->isTrueObject($receiver) || $this->isFalseObject($receiver);
    }

    /**
     * Checks Integer class compatibility (including subclasses).
     */
    private function isIntegerObject(SolObject $receiver): bool
    {
        return $this->isInstanceOf($receiver, 'Integer');
    }

    /**
     * Checks String class compatibility (including subclasses).
     */
    private function isStringObject(SolObject $receiver): bool
    {
        return $this->isInstanceOf($receiver, 'String');
    }

    /**
     * Checks Nil class compatibility (including subclasses).
     */
    private function isNilObject(SolObject $receiver): bool
    {
        return $this->isInstanceOf($receiver, 'Nil');
    }

    /**
     * Checks True class compatibility (including subclasses).
     */
    private function isTrueObject(SolObject $receiver): bool
    {
        return $this->isInstanceOf($receiver, 'True');
    }

    /**
     * Checks False class compatibility (including subclasses).
     */
    private function isFalseObject(SolObject $receiver): bool
    {
        return $this->isInstanceOf($receiver, 'False');
    }

    /**
     * Checks whether receiver can be treated as executable block.
     */
    private function isBlockReceiver(SolObject $receiver): bool
    {
        return $receiver instanceof BlockObject || $this->isInstanceOf($receiver, 'Block');
    }

    /**
     * Checks receiver inheritance against target class.
     */
    private function isInstanceOf(SolObject $receiver, string $className): bool
    {
        return $this->methodResolver->isSubclassOfOrSame($receiver->getClassName(), $className);
    }

    /**
     * Sends message using main dispatcher.
     *
     * @param array<int, SolObject> $arguments
     */
    private function sendMessage(
        MessageDispatcher $messageDispatcher,
        SolObject $receiver,
        string $selector,
        array $arguments = []
    ): SolObject {
        return $messageDispatcher->sendMessage($receiver, $selector, $arguments);
    }
}
