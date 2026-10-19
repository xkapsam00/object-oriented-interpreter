<?php

/**
 * This module defines the ErrorCode enum, which contains all error codes specified by
 * the project assignment.
 *
 * IPP: You should not need to modify this file. However, you may add additional helper methods
 *      to the ErrorCode enum.
 *
 * Author: Ondřej Ondryáš <iondryas@fit.vut.cz>
 *
 * AI usage notice: The author used OpenAI Codex to create the implementation of this
 *                  module based on its Python counterpart.
 */

declare(strict_types=1);

namespace IPP\Interpreter\Exception;

/** Error codes for the interpreter, used as program exit codes. */
enum ErrorCode: int
{
    // General errors (10–19 + 99)
    case GENERAL_OPTIONS = 10; // missing required CLI parameter or forbidden parameter combination
    case GENERAL_INPUT = 11; // error opening input files (nonexistent, insufficient permissions, etc.)
    case GENERAL_OTHER = 99; // unexpected internal error (uncategorized)

    // Interpreter / XML errors
    case INT_XML = 20; // invalid XML input (not well-formed / cannot be parsed)
    case INT_STRUCTURE = 42; // unexpected XML structure (nesting, missing required attrs, etc.)

    // Static semantic errors
    case SEM_MAIN = 31; // missing Main class or its instance method run
    case SEM_UNDEF = 32; // use of undefined/uninitialized variable/parameter/class/method
    case SEM_ARITY = 33; // arity error for block assigned to selector in method definition
    case SEM_COLLISION = 34; // assignment to a block's formal parameter (on LHS of assignment)
    case SEM_ERROR = 35; // other static semantic error (e.g., class redefinition, name collisions)

    // Runtime interpreter errors
    case INT_DNU = 51; // receiver does not understand the message (excluding instance-attr creation)
    case INT_OTHER = 52; // other runtime errors (e.g., wrong operand types)
    case INT_INVALID_ARG = 53; // invalid argument value (e.g., division by zero)
    case INT_INST_ATTR = 54; // attempt to create instance attribute colliding with a method

    public function fire(?string $message = null): never
    {
        if ($message !== null && $message !== '') {
            fwrite(STDERR, sprintf("Error %d: %s\n", $this->value, $message));
        } else {
            fwrite(STDERR, sprintf("Error %d (%s)\n", $this->value, $this->name));
        }

        exit($this->value);
    }
}
