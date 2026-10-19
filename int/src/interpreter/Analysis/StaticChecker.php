<?php

declare(strict_types=1);

namespace IPP\Interpreter\Analysis;

use IPP\Interpreter\Exception\ErrorCode;
use IPP\Interpreter\Exception\InterpreterError;
use IPP\Interpreter\InputModel\Block;
use IPP\Interpreter\InputModel\ClassDef;
use IPP\Interpreter\InputModel\Expr;
use IPP\Interpreter\InputModel\Literal;
use IPP\Interpreter\InputModel\Program;
use IPP\Interpreter\RuntimeObject\BuiltinClassCatalog;

/**
 * Performs static semantic validation of the parsed SOL26 program before execution starts.
 */
class StaticChecker
{
    /**
     * Runs all static checks required before interpretation.
     */
    public function check(Program $program): void
    {
        $this->checkEntryPointMainRun($program);
        $this->checkDuplicateClassDefinitions($program);

        $classIndex = $this->buildClassIndex($program);

        $this->checkUndefinedParentClasses($program, $classIndex);
        $this->checkDuplicateMethodDefinitions($program);
        $this->checkMethodSelectorArity($program);
        $this->checkAllBlocksStaticSemantics($program, $classIndex);
    }

    /**
     * Ensures program entry point exists as Main>>run with zero arity.
     */
    private function checkEntryPointMainRun(Program $program): void
    {
        // The program must define class Main with a zero-arity instance method run.
        foreach ($program->classes as $classDef) {
            if ($classDef->name !== 'Main') {
                continue;
            }

            foreach ($classDef->methods as $methodDef) {
                if ($methodDef->selector === 'run' && $methodDef->block->arity === 0) {
                    return;
                }
            }

            throw new InterpreterError(
                ErrorCode::SEM_MAIN,
                'Class Main is present but does not contain a valid run method.'
            );
        }

        throw new InterpreterError(ErrorCode::SEM_MAIN, 'Missing class Main');
    }

    /**
     * Rejects duplicate class definitions in global class namespace.
     */
    private function checkDuplicateClassDefinitions(Program $program): void
    {
        /** @var array<string, true> $seen */
        $seen = [];

        // Class names are globally visible, so redefining a class is a static error.
        foreach ($program->classes as $classDef) {
            if (isset($seen[$classDef->name])) {
                throw new InterpreterError(
                    ErrorCode::SEM_ERROR,
                    "Duplicate class definition: {$classDef->name}"
                );
            }

            $seen[$classDef->name] = true;
        }
    }

    /**
     * Rejects duplicate method selectors declared within a single class.
     */
    private function checkDuplicateMethodDefinitions(Program $program): void
    {
        foreach ($program->classes as $classDef) {
            /** @var array<string, true> $seen */
            $seen = [];

            // SOL26 does not support method overloading within the same class.
            foreach ($classDef->methods as $methodDef) {
                if (isset($seen[$methodDef->selector])) {
                    throw new InterpreterError(
                        ErrorCode::SEM_ERROR,
                        "Duplicate method definition in class {$classDef->name}: {$methodDef->selector}"
                    );
                }

                $seen[$methodDef->selector] = true;
            }
        }
    }

    /**
     * Verifies selector arity matches declared block parameter arity for every method.
     */
    private function checkMethodSelectorArity(Program $program): void
    {
        foreach ($program->classes as $classDef) {
            foreach ($classDef->methods as $methodDef) {
                // The number of selector parts must match the number of block parameters.
                $selectorArity = substr_count($methodDef->selector, ':');
                $blockArity = $methodDef->block->arity;

                if ($selectorArity !== $blockArity) {
                    throw new InterpreterError(
                        ErrorCode::SEM_ARITY,
                        "Method {$methodDef->selector} in class {$classDef->name} "
                        . "has mismatched selector and block arity."
                    );
                }
            }
        }
    }

    /**
     * @param array<string, ClassDef> $classIndex
     */
    private function checkAllBlocksStaticSemantics(Program $program, array $classIndex): void
    {
        /** @var array<string, true> $methodPseudoVariables */
        $methodPseudoVariables = ['self' => true, 'super' => true];

        /** @var array<string, true> $builtinVariables */
        $builtinVariables = ['nil' => true, 'true' => true, 'false' => true];

        // Static block checks must also be applied to method bodies and nested block literals.
        foreach ($program->classes as $classDef) {
            foreach ($classDef->methods as $methodDef) {
                $this->checkSingleBlockStaticSemantics(
                    $methodDef->block,
                    $classIndex,
                    $builtinVariables + $methodPseudoVariables,
                    [],
                    $methodPseudoVariables
                );
            }
        }
    }

    /**
     * Performs variable/assignment checks for one block and recursively for nested expressions.
     *
     * @param array<string, ClassDef> $classIndex
     * @param array<string, true> $visibleNames
     * @param array<string, true> $parameterNames
     * @param array<string, true> $pseudoVariables
     */
    private function checkSingleBlockStaticSemantics(
        Block $blockDef,
        array $classIndex,
        array $visibleNames,
        array $parameterNames,
        array $pseudoVariables
    ): void {
        $currentVisibleNames = $visibleNames;
        $currentParameterNames = $parameterNames;

        /** @var array<string, true> $parameterNamesInBlock */
        $parameterNamesInBlock = [];

        // Parameter names must be unique within a single block.
        foreach ($blockDef->parameters as $paramDef) {
            if (isset($parameterNamesInBlock[$paramDef->name])) {
                throw new InterpreterError(
                    ErrorCode::SEM_ERROR,
                    "Duplicate parameter name in block: {$paramDef->name}"
                );
            }

            $parameterNamesInBlock[$paramDef->name] = true;
            $currentParameterNames[$paramDef->name] = true;
            $currentVisibleNames[$paramDef->name] = true;
        }

        foreach ($blockDef->assigns as $assignDef) {
            $targetName = $assignDef->target->name;

            // Formal parameters are immutable, even when captured by nested blocks.
            if (isset($currentParameterNames[$targetName])) {
                throw new InterpreterError(
                    ErrorCode::SEM_COLLISION,
                    "Assignment to block parameter: {$targetName}"
                );
            }

            if (isset($pseudoVariables[$targetName])) {
                throw new InterpreterError(
                    ErrorCode::SEM_ERROR,
                    "Assignment to immutable pseudo-variable: {$targetName}"
                );
            }

            $this->checkExprStaticSemantics(
                $assignDef->expr,
                $classIndex,
                $currentVisibleNames,
                $currentParameterNames,
                $pseudoVariables
            );

            // A local variable becomes visible only after its first assignment.
            $currentVisibleNames[$targetName] = true;
        }
    }

    /**
     * Builds a lookup table for user-defined classes.
     *
     * @return array<string, ClassDef>
     */
    private function buildClassIndex(Program $program): array
    {
        $classIndex = [];

        foreach ($program->classes as $classDef) {
            $classIndex[$classDef->name] = $classDef;
        }

        return $classIndex;
    }

    /**
     * Verifies that every declared parent class exists either as a built-in class
     * or as a user-defined class in the program and that inheritance chains are acyclic.
     *
     * @param array<string, ClassDef> $classIndex
     */
    private function checkUndefinedParentClasses(Program $program, array $classIndex): void
    {
        foreach ($program->classes as $classDef) {
            /** @var array<string, true> $visited */
            $visited = [];
            $currentParent = $classDef->parent;

            while (!BuiltinClassCatalog::isUserVisibleBuiltinClass($currentParent)) {
                if (!isset($classIndex[$currentParent])) {
                    throw new InterpreterError(
                        ErrorCode::SEM_UNDEF,
                        "Undefined parent class {$currentParent} for class {$classDef->name}."
                    );
                }

                if (isset($visited[$currentParent])) {
                    throw new InterpreterError(
                        ErrorCode::SEM_ERROR,
                        "Cyclic inheritance detected for class {$classDef->name}."
                    );
                }

                $visited[$currentParent] = true;
                $currentParent = $classIndex[$currentParent]->parent;
            }
        }
    }

    /**
     * Returns true for user-defined and built-in classes that are known to the interpreter.
     *
     * @param array<string, ClassDef> $classIndex
     */
    private function isKnownClass(string $className, array $classIndex): bool
    {
        return isset($classIndex[$className]) || BuiltinClassCatalog::isUserVisibleBuiltinClass($className);
    }

    /**
     * Returns true when a class-side selector is statically valid for the given class name.
     */
    private function isValidStaticSelector(string $className, string $selector): bool
    {
        if (BuiltinClassCatalog::isUserVisibleBuiltinClass($className)) {
            return BuiltinClassCatalog::isValidStaticSelector($className, $selector);
        }

        return in_array($selector, ['new', 'from:'], true);
    }

    private function isClassLiteral(Literal $literal): bool
    {
        // SOL-XML uses lowercase class="class" for class identifier literals.
        return $literal->classId === 'class';
    }

    /**
     * Combined traversal for block static semantics, class literal validation
     * and statically known class-side message validation.
     *
     * @param array<string, ClassDef> $classIndex
     * @param array<string, true> $visibleNames
     * @param array<string, true> $parameterNames
     * @param array<string, true> $pseudoVariables
     */
    private function checkExprStaticSemantics(
        Expr $exprDef,
        array $classIndex,
        array $visibleNames,
        array $parameterNames,
        array $pseudoVariables
    ): void {
        if ($exprDef->literal !== null) {
            if (
                $this->isClassLiteral($exprDef->literal)
                && !$this->isKnownClass($exprDef->literal->value, $classIndex)
            ) {
                throw new InterpreterError(
                    ErrorCode::SEM_UNDEF,
                    "Undefined class reference: {$exprDef->literal->value}."
                );
            }

            return;
        }

        if ($exprDef->variable !== null) {
            if (!isset($visibleNames[$exprDef->variable->name])) {
                throw new InterpreterError(
                    ErrorCode::SEM_UNDEF,
                    "Undefined variable reference: {$exprDef->variable->name}."
                );
            }

            return;
        }

        if ($exprDef->block !== null) {
            $this->checkSingleBlockStaticSemantics(
                $exprDef->block,
                $classIndex,
                $visibleNames,
                $parameterNames,
                $pseudoVariables
            );
            return;
        }

        if ($exprDef->send === null) {
            return;
        }

        $this->checkExprStaticSemantics(
            $exprDef->send->receiver,
            $classIndex,
            $visibleNames,
            $parameterNames,
            $pseudoVariables
        );

        foreach ($exprDef->send->args as $argExpr) {
            $this->checkExprStaticSemantics(
                $argExpr->expr,
                $classIndex,
                $visibleNames,
                $parameterNames,
                $pseudoVariables
            );
        }

        $receiverLiteral = $exprDef->send->receiver->literal;
        if ($receiverLiteral === null || !$this->isClassLiteral($receiverLiteral)) {
            return;
        }

        $className = $receiverLiteral->value;
        if (!$this->isKnownClass($className, $classIndex)) {
            throw new InterpreterError(
                ErrorCode::SEM_UNDEF,
                "Undefined class reference: {$className}."
            );
        }

        if (!$this->isValidStaticSelector($className, $exprDef->send->selector)) {
            throw new InterpreterError(
                ErrorCode::SEM_UNDEF,
                "Undefined class-side selector {$exprDef->send->selector} for class {$className}."
            );
        }
    }
}
