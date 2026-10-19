<?php

/**
 * This module defines base building blocks for SOL-XML data model elements.
 *
 * IPP: You should not need to modify input model files. If you find it necessary
 *      to modify them, consult your intentions on the project forum first.
 *
 * Author: Ondřej Ondryáš <iondryas@fit.vut.cz>
 *
 * AI usage notice: The author used OpenAI Codex to create the implementation of this
 *                  module based on its Python counterpart.
 */

declare(strict_types=1);

namespace IPP\Interpreter\InputModel;

/** Represents any XML element with an `order` attribute. */
abstract class OrderedElement
{
    public readonly int $order;

    protected function __construct(int $order)
    {
        $this->order = $order;
    }
}
