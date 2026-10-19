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

/** `<arg order="..."><expr>...</expr></arg>` */
class Arg extends OrderedElement
{
    public readonly Expr $expr;

    private function __construct(int $order, Expr $expr)
    {
        parent::__construct($order);
        $this->expr = $expr;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'arg');
        XmlHelper::assertOnlyAttributes($element, ['order']);
        XmlHelper::assertNoUnexpectedText($element);

        $children = XmlHelper::childElements($element);
        if (count($children) !== 1 || $children[0]->tagName !== 'expr') {
            throw new XmlValidationException('<arg> must contain exactly one <expr> child.');
        }

        return new self(
            XmlHelper::parseIntAttribute($element, 'order'),
            Expr::fromXml($children[0])
        );
    }
}
