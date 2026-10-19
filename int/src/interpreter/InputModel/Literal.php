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

/** `<literal class="Integer|String|Nil|True|False|class" value="..."/>` */
class Literal
{
    public readonly string $classId;
    public readonly string $value;

    private function __construct(string $classId, string $value)
    {
        $this->classId = $classId;
        $this->value = $value;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'literal');
        XmlHelper::assertOnlyAttributes($element, ['class', 'value']);
        XmlHelper::assertNoUnexpectedText($element);

        if (count(XmlHelper::childElements($element)) > 0) {
            throw new XmlValidationException('<literal> must not have child elements.');
        }

        return new self(
            XmlHelper::requireAttribute($element, 'class'),
            XmlHelper::requireAttribute($element, 'value')
        );
    }
}
