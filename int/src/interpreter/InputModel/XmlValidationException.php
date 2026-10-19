<?php

/**
 * This module defines exceptions raised by input model XML validation.
 *
 * IPP: You can freely add exception classes as needed.
 *
 * Author: Ondřej Ondryáš <iondryas@fit.vut.cz>
 *
 * AI usage notice: The author used OpenAI Codex to create the implementation of this
 *                  module based on its Python counterpart.
 */

declare(strict_types=1);

namespace IPP\Interpreter\InputModel;

use RuntimeException;

/** Raised when input SOL-XML does not match the expected structural model. */
class XmlValidationException extends RuntimeException
{
}
