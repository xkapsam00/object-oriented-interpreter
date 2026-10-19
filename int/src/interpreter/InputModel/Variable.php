<?php

/**
 * This module defines a leaf node in the SOL-XML input model.
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

use DOMElement;

/** `<var name="..."/>` */
class Variable
{
    public readonly string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'var');
        XmlHelper::assertOnlyAttributes($element, ['name']);
        XmlHelper::assertNoUnexpectedText($element);

        if (count(XmlHelper::childElements($element)) > 0) {
            throw new XmlValidationException('<var> must not have child elements.');
        }

        return new self(XmlHelper::requireAttribute($element, 'name'));
    }
}
