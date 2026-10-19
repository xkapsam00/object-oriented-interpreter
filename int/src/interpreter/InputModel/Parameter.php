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

/** `<parameter name="..." order="..."/>` */
class Parameter extends OrderedElement
{
    public readonly string $name;

    private function __construct(string $name, int $order)
    {
        parent::__construct($order);
        $this->name = $name;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'parameter');
        XmlHelper::assertOnlyAttributes($element, ['name', 'order']);
        XmlHelper::assertNoUnexpectedText($element);

        if (count(XmlHelper::childElements($element)) > 0) {
            throw new XmlValidationException('<parameter> must not have child elements.');
        }

        return new self(
            XmlHelper::requireAttribute($element, 'name'),
            XmlHelper::parseIntAttribute($element, 'order')
        );
    }
}
