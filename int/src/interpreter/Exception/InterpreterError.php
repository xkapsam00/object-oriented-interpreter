<?php

/**
 * This module defines the custom exception classes used by the interpreter to represent various
 * error conditions that can occur during interpretation.
 *
 * IPP: You can freely modify this file and add any additional exception classes.
 *      However, the InterpreterError class must be used as a base for any exceptions that control
 *      the outcome of the interpretation (i.e., those that are caught in solint.php and cause
 *      the interpreter to exit with a specific error code).
 *
 * Author: Ondřej Ondryáš <iondryas@fit.vut.cz>
 *
 * AI usage notice: The author used OpenAI Codex to create the implementation of this
 *                  module based on its Python counterpart.
 */

declare(strict_types=1);

namespace IPP\Interpreter\Exception;

use RuntimeException;
use Throwable;

/**
 * A general exception class for errors that occur during interpretation.
 * It includes an error code enum instance that can be used to determine
 * the appropriate exit code for the program.
 */
class InterpreterError extends RuntimeException
{
    public readonly ErrorCode $errorCode;

    public function __construct(ErrorCode $errorCode, string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode->value, $previous);
        $this->errorCode = $errorCode;
    }
}
