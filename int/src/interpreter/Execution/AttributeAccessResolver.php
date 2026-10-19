<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\RuntimeObject\PlainObject;
use IPP\Interpreter\RuntimeObject\SolObject;

/**
 * Resolves missing instance selectors as attribute getter/setter operations.
 */
final class AttributeAccessResolver
{
    public function __construct(private readonly MethodResolver $methodResolver)
    {
    }

    /**
     * Resolves unknown selector as getter/setter by selector arity or raises DNU.
     *
     * @param array<int, SolObject> $arguments
     */
    public function handleMissingInstanceMethod(
        SolObject $receiver,
        string $selector,
        array $arguments,
        ?string $attributeCollisionSearchStartClass
    ): SolObject {
        $selectorArity = substr_count($selector, ':');

        return match ($selectorArity) {
            0 => $this->resolveAttributeGetter($receiver, $selector),
            1 => count($arguments) === 1 ? $this->resolveAttributeSetter(
                $receiver,
                $selector,
                $arguments[0],
                $attributeCollisionSearchStartClass
            ) : $this->throwDoesNotUnderstand($selector),
            default => $this->throwDoesNotUnderstand($selector),
        };
    }

    /**
     * Resolves zero-arity selector as attribute read.
     */
    private function resolveAttributeGetter(SolObject $receiver, string $attributeName): SolObject
    {
        if ($receiver instanceof PlainObject && $receiver->hasAttribute($attributeName)) {
            return $receiver->getAttribute($attributeName);
        }

        return $this->throwDoesNotUnderstand($attributeName);
    }

    /**
     * Resolves one-arity selector as attribute write with method-collision guard.
     */
    private function resolveAttributeSetter(
        SolObject $receiver,
        string $selector,
        SolObject $value,
        ?string $attributeCollisionSearchStartClass
    ): SolObject {
        $attributeName = substr($selector, 0, -1);

        if (
            $attributeCollisionSearchStartClass !== null
            && $this->methodResolver->findMethodDefinition($attributeCollisionSearchStartClass, $attributeName) !== null
        ) {
            throw new InterpreterError(
                ErrorCode::INT_INST_ATTR,
                "Attribute {$attributeName} collides with an existing method."
            );
        }

        if (!$receiver instanceof PlainObject) {
            return $this->throwDoesNotUnderstand($selector);
        }

        $receiver->setAttribute($attributeName, $value);
        return $receiver;
    }

    /**
     * Throws does-not-understand runtime error for unresolved selector.
     */
    private function throwDoesNotUnderstand(string $selector): never
    {
        throw new InterpreterError(
            ErrorCode::INT_DNU,
            "Receiver does not understand selector {$selector}."
        );
    }
}
