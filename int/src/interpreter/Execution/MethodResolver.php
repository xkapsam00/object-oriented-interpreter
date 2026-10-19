<?php

declare(strict_types=1);

namespace IPP\Interpreter\Execution;

use IPP\Interpreter\InputModel\ClassDef;
use IPP\Interpreter\InputModel\Program;
use IPP\Interpreter\Runtime\ExecutionContext;
use IPP\Interpreter\RuntimeObject\BuiltinClassCatalog;
use IPP\Interpreter\RuntimeObject\RuntimeMethod;
use IPP\Interpreter\RuntimeObject\SolClass;
use IPP\Interpreter\RuntimeObject\SolObject;

final class MethodResolver
{
    /**
     * @var array<string, SolClass>
     */
    private array $classIndex = [];

    public function __construct()
    {
        $this->classIndex = BuiltinClassCatalog::createRuntimeClasses();
    }

    /**
     * Creates a resolver preloaded with built-ins and classes from a parsed program.
     */
    public static function forProgram(Program $program): self
    {
        $resolver = new self();
        $resolver->setProgram($program);

        return $resolver;
    }

    /**
     * Rebuilds class index using built-ins plus user-defined classes from program input.
     */
    public function setProgram(Program $program): void
    {
        $this->classIndex = BuiltinClassCatalog::createRuntimeClasses();

        foreach ($program->classes as $classDef) {
            $this->classIndex[$classDef->name] = $this->createUserDefinedClass($classDef);
        }
    }

    /**
     * Returns direct parent class name for the given class or null when no parent exists.
     */
    public function getParentClassName(string $className): ?string
    {
        return $this->getClass($className)?->getParentClassName();
    }

    /**
     * Checks whether the class exists in runtime class index.
     */
    public function isKnownClass(string $className): bool
    {
        return $this->getClass($className) !== null;
    }

    /**
     * Returns true when class is the same as ancestor or inherits from it.
     */
    public function isSubclassOfOrSame(string $className, string $ancestorClassName): bool
    {
        $currentClassName = $className;

        // Walk parent chain upward until root or ancestor match.
        while ($currentClassName !== null) {
            if ($currentClassName === $ancestorClassName) {
                return true;
            }

            $currentClassName = $this->getParentClassName($currentClassName);
        }

        return false;
    }

    /**
     * Resolves internal value root by walking inheritance from class to its ancestors.
     */
    public function getInternalValueRoot(string $className): ?string
    {
        $currentClassName = $className;

        while ($currentClassName !== null) {
            $class = $this->getClass($currentClassName);
            if ($class === null) {
                return null;
            }

            $root = $class->getDeclaredInternalValueRoot();
            if ($root !== null) {
                return $root;
            }

            $currentClassName = $class->getParentClassName();
        }

        return null;
    }

    /**
     * Resolves default instance value, inheriting declaration from nearest ancestor.
     */
    public function getDefaultInstanceValue(string $className): mixed
    {
        $currentClassName = $className;

        while ($currentClassName !== null) {
            $class = $this->getClass($currentClassName);
            if ($class === null) {
                return null;
            }

            if ($class->hasDeclaredDefaultInstanceValue()) {
                return $class->getDeclaredDefaultInstanceValue();
            }

            $currentClassName = $class->getParentClassName();
        }

        return null;
    }

    /**
     * Finds method definition by selector using standard inheritance lookup.
     */
    public function findMethodDefinition(string $className, string $selector): ?ResolvedMethod
    {
        $currentClassName = $className;

        // First matching method in upward lookup order wins.
        while ($currentClassName !== null) {
            $class = $this->getClass($currentClassName);
            if ($class === null) {
                return null;
            }

            $method = $class->findMethod($selector);
            if ($method !== null) {
                return new ResolvedMethod($method, $currentClassName);
            }

            $currentClassName = $class->getParentClassName();
        }

        return null;
    }

    /**
     * Returns runtime class representation by name or null when class is unknown.
     */
    public function getClass(string $className): ?SolClass
    {
        return $this->classIndex[$className] ?? null;
    }

    /**
     * Builds runtime method objects from input model method definitions.
     *
     * @return array<string, RuntimeMethod>
     */
    private function buildMethodMap(ClassDef $classDef): array
    {
        $methodsBySelector = [];

        foreach ($classDef->methods as $methodDef) {
            $parameterNames = [];
            foreach ($methodDef->block->parameters as $parameterDef) {
                $parameterNames[] = $parameterDef->name;
            }

            $block = $methodDef->block;
            $methodsBySelector[$methodDef->selector] = new RuntimeMethod(
                $methodDef->selector,
                $methodDef->block->arity,
                $parameterNames,
                static function (BlockExecutor $blockExecutor, ExecutionContext $context) use ($block): SolObject {
                    return $blockExecutor->execute($block, $context);
                }
            );
        }

        return $methodsBySelector;
    }

    /**
     * Converts a parsed class definition into runtime class representation.
     */
    private function createUserDefinedClass(ClassDef $classDef): SolClass
    {
        return new SolClass(
            $classDef->name,
            $classDef->parent,
            $this->buildMethodMap($classDef)
        );
    }
}
