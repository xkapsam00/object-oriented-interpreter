<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\Runtime\Environment;
use IPP\Interpreter\Runtime\ExecutionContext;
use IPP\Interpreter\RuntimeObject\BlockObject;
use IPP\Interpreter\RuntimeObject\ClassObject;
use IPP\Interpreter\RuntimeObject\RuntimeMethod;
use IPP\Interpreter\RuntimeObject\SolObject;

final class MessageDispatcher
{
    private readonly MethodResolver $methodResolver;
    private readonly AttributeAccessResolver $attributeAccessResolver;
    private readonly BuiltinMethodDispatcher $builtinMethodDispatcher;
    private readonly ClassMessageDispatcher $classMessageDispatcher;
    private readonly BlockMessageDispatcher $blockMessageDispatcher;
    private ?BlockExecutor $blockExecutor = null;

    public function __construct(
        MethodResolver $methodResolver,
        AttributeAccessResolver $attributeAccessResolver,
        BuiltinMethodDispatcher $builtinMethodDispatcher,
        ClassMessageDispatcher $classMessageDispatcher,
        BlockMessageDispatcher $blockMessageDispatcher
    ) {
        $this->methodResolver = $methodResolver;
        $this->attributeAccessResolver = $attributeAccessResolver;
        $this->builtinMethodDispatcher = $builtinMethodDispatcher;
        $this->classMessageDispatcher = $classMessageDispatcher;
        $this->blockMessageDispatcher = $blockMessageDispatcher;
    }

    /**
     * Attaches block executor after construction once the execution graph is fully wired.
     */
    public function attachBlockExecutor(BlockExecutor $blockExecutor): void
    {
        // Block execution is wired after construction to break circular dependency:
        // dispatcher needs block executor, block executor needs expression evaluator,
        // evaluator needs dispatcher.
        $this->blockExecutor = $blockExecutor;
    }

    /**
     * Creates a runtime class object for known classes and rejects unknown class names.
     */
    public function createClassObject(string $className): ClassObject
    {
        if (!$this->methodResolver->isKnownClass($className)) {
            throw new InterpreterError(
                ErrorCode::SEM_UNDEF,
                "Undefined class: {$className}"
            );
        }

        return new ClassObject($className);
    }

    /**
     * Dispatches a regular message send to the provided receiver.
     *
     * @param array<int, SolObject> $arguments
     */
    public function sendMessage(
        SolObject $receiver,
        string $selector,
        array $arguments = [],
        ?ExecutionContext $callerContext = null
    ): SolObject {
        // Regular message send starts lookup from the receiver class.
        return $this->dispatchInstanceMessage(
            $receiver,
            $selector,
            $arguments,
            $receiver->getClassName(),
            $receiver->getClassName(),
            $callerContext
        );
    }

    /**
     * Dispatches a self send using the current execution receiver.
     *
     * @param array<int, SolObject> $arguments
     */
    public function sendSelfMessage(
        ExecutionContext $context,
        string $selector,
        array $arguments = []
    ): SolObject {
        // self keeps dynamic receiver and starts method lookup from that receiver class.
        return $this->dispatchInstanceMessage(
            $context->getReceiver(),
            $selector,
            $arguments,
            $context->getReceiver()->getClassName(),
            $context->getCurrentClassName(),
            $context
        );
    }

    /**
     * Dispatches a super send starting method lookup in the parent class.
     *
     * @param array<int, SolObject> $arguments
     */
    public function sendSuperMessage(
        ExecutionContext $context,
        string $selector,
        array $arguments = []
    ): SolObject {
        // super skips the current class and starts in its direct parent.
        $startClass = $this->getParentClassName($context->getCurrentClassName());

        return $this->dispatchInstanceMessage(
            $context->getReceiver(),
            $selector,
            $arguments,
            $startClass,
            $startClass,
            $context
        );
    }

    /**
     * Routes instance-level messages according to receiver type and lookup precedence.
     *
     * @param array<int, SolObject> $arguments
     */
    private function dispatchInstanceMessage(
        SolObject $receiver,
        string $selector,
        array $arguments,
        ?string $methodSearchStartClass,
        ?string $attributeCollisionSearchStartClass,
        ?ExecutionContext $callerContext = null
    ): SolObject {
        // Blocks first try built-ins (e.g. value/value:) and then block-specific behavior.
        if ($receiver instanceof BlockObject) {
            $builtinResult = $this->builtinMethodDispatcher->dispatchBuiltinInstanceMessage(
                $this,
                $receiver,
                $selector,
                $arguments
            );
            if ($builtinResult !== null) {
                return $builtinResult;
            }

            return $this->blockMessageDispatcher->dispatchBlockMessage(
                $this->requireBlockExecutor(),
                $receiver,
                $selector,
                $arguments
            );
        }

        if ($receiver instanceof ClassObject) {
            return $this->classMessageDispatcher->dispatchClassMessage($receiver, $selector, $arguments);
        }

        // For plain instances, user-defined methods have precedence over built-ins.
        $methodLookup = $methodSearchStartClass !== null
            ? $this->methodResolver->findMethodDefinition($methodSearchStartClass, $selector)
            : null;

        if ($methodLookup !== null) {
            return $this->invokeUserMethod(
                $receiver,
                $methodLookup->getOwnerClassName(),
                $methodLookup->getMethod(),
                $arguments
            );
        }

        $builtinResult = $this->builtinMethodDispatcher->dispatchBuiltinInstanceMessage(
            $this,
            $receiver,
            $selector,
            $arguments
        );
        if ($builtinResult !== null) {
            return $builtinResult;
        }

        // Last fallback: interpret unknown selector as attribute access helper
        // (for templates that support getter/setter message conventions).
        return $this->attributeAccessResolver->handleMissingInstanceMethod(
            $receiver,
            $selector,
            $arguments,
            $attributeCollisionSearchStartClass
        );
    }

    /**
     * Returns the parent class name for a class or null if the class has no parent.
     */
    public function getParentClassName(string $className): ?string
    {
        return $this->methodResolver->getParentClassName($className);
    }

    /**
     * Executes a resolved user-defined method in a freshly prepared call context.
     *
     * @param array<int, SolObject> $arguments
     */
    private function invokeUserMethod(
        SolObject $receiver,
        string $ownerClassName,
        RuntimeMethod $methodDef,
        array $arguments
    ): SolObject {
        $blockExecutor = $this->requireBlockExecutor();
        $context = $this->createMethodContext(
            $receiver,
            $ownerClassName,
            $methodDef,
            $arguments
        );

        return $methodDef->execute($blockExecutor, $context);
    }

    /**
     * Creates a method execution context and binds formal parameters to arguments.
     *
     * @param array<int, SolObject> $arguments
     */
    private function createMethodContext(
        SolObject $receiver,
        string $ownerClassName,
        RuntimeMethod $methodDef,
        array $arguments
    ): ExecutionContext {
        // Runtime arity guard protects against malformed sends at execution time.
        if (count($arguments) !== $methodDef->getArity()) {
            throw new InterpreterError(
                ErrorCode::INT_DNU,
                "Method {$methodDef->getSelector()} arity mismatch."
            );
        }

        $environment = new Environment();

        // Bind formal parameters positionally into a fresh method-local scope.
        foreach ($methodDef->getParameterNames() as $index => $parameterName) {
            $environment->define($parameterName, $arguments[$index]);
        }

        return new ExecutionContext(
            $ownerClassName,
            $receiver,
            $environment
        );
    }

    /**
     * Returns the configured block executor or fails if dispatcher wiring is incomplete.
     */
    private function requireBlockExecutor(): BlockExecutor
    {
        if ($this->blockExecutor === null) {
            throw new InterpreterError(
                ErrorCode::INT_OTHER,
                'Block executor is not configured in message dispatcher.'
            );
        }

        return $this->blockExecutor;
    }
}
