<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\InputModel\Block;
use IPP\Interpreter\Runtime\Environment;
use IPP\Interpreter\Runtime\Runtime;
use IPP\Interpreter\RuntimeObject\BlockObject;
use IPP\Interpreter\RuntimeObject\ClassObject;
use IPP\Interpreter\RuntimeObject\PlainObject;
use IPP\Interpreter\RuntimeObject\SolObject;

/**
 * Handles class-side selectors such as new, from:, and String read.
 */
final class ClassMessageDispatcher
{
    private ?Block $emptyBuiltinBlock = null;

    public function __construct(
        private readonly MethodResolver $methodResolver,
        private readonly Runtime $runtime
    ) {
    }

    /**
     * Dispatches class-side message for class object receiver.
     *
     * @param array<int, SolObject> $arguments
     */
    public function dispatchClassMessage(ClassObject $receiver, string $selector, array $arguments): SolObject
    {
        $className = $receiver->getRepresentedClassName();

        if ($selector === 'new' && count($arguments) === 0) {
            return $this->instantiateClass($className);
        }

        if ($selector === 'from:' && count($arguments) === 1) {
            return $this->instantiateFrom($className, $arguments[0]);
        }

        if ($className === 'String' && $selector === 'read' && count($arguments) === 0) {
            return PlainObject::string($this->runtime->readLine());
        }

        // Unknown class-side selectors are specified as semantic error 32.
        // StaticChecker should catch this in source programs; this runtime throw is a defensive fallback.
        throw new InterpreterError(
            ErrorCode::SEM_UNDEF,
            "Class {$className} does not understand class-side selector {$selector}."
        );
    }

    /**
     * Creates fresh instance of class with class-defined default internal value.
     */
    private function instantiateClass(string $className): SolObject
    {
        if ($className === 'Nil') {
            return PlainObject::nil();
        }

        if ($className === 'Block') {
            return new BlockObject($this->getEmptyBuiltinBlock(), new Environment());
        }

        return PlainObject::plain($className, $this->methodResolver->getDefaultInstanceValue($className));
    }

    /**
     * Creates instance from source object, copying compatible attributes/internal value.
     */
    private function instantiateFrom(string $className, SolObject $source): SolObject
    {
        if ($className === 'Nil') {
            return PlainObject::nil();
        }

        if ($className === 'Block') {
            if ($source instanceof BlockObject) {
                return new BlockObject(
                    $source->getBlock(),
                    $source->getCapturedEnvironment(),
                    $source->getHomeContext()
                );
            }

            return $this->instantiateClass('Block');
        }

        $instance = $this->instantiateClass($className);
        if (!$instance instanceof PlainObject) {
            throw new InterpreterError(
                ErrorCode::INT_OTHER,
                "Cannot instantiate class {$className} as PlainObject."
            );
        }

        if ($source instanceof PlainObject) {
            $instance->copyAttributesFrom($source);
        }

        $internalValueRoot = $this->methodResolver->getInternalValueRoot($className);
        if ($internalValueRoot !== null) {
            if (!$this->methodResolver->isSubclassOfOrSame($source->getClassName(), $internalValueRoot)) {
                throw new InterpreterError(
                    ErrorCode::INT_INVALID_ARG,
                    "Object of class {$source->getClassName()} cannot initialize {$className}."
                );
            }

            $instance->setValue($source->getValue());
        }

        return $instance;
    }

    /**
     * Returns cached empty block definition used by built-in Block new/from:.
     */
    private function getEmptyBuiltinBlock(): Block
    {
        if ($this->emptyBuiltinBlock !== null) {
            return $this->emptyBuiltinBlock;
        }

        $this->emptyBuiltinBlock = Block::empty();
        return $this->emptyBuiltinBlock;
    }
}
