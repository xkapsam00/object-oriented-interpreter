<?php

/**
 * This module defines statement nodes in the SOL-XML input model.
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

/**
 * `<assign order="...">`
 *   `<var name="..."/>`
 *   `<expr>...</expr>`
 * `</assign>`
 */
class Assign extends OrderedElement
{
    public readonly Variable $target;
    public readonly Expr $expr;

    private function __construct(int $order, Variable $target, Expr $expr)
    {
        parent::__construct($order);
        $this->target = $target;
        $this->expr = $expr;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'assign');
        XmlHelper::assertOnlyAttributes($element, ['order']);
        XmlHelper::assertNoUnexpectedText($element);

        $target = null;
        $expr = null;

        foreach (XmlHelper::childElements($element) as $child) {
            if ($child->tagName === 'var') {
                if ($target !== null) {
                    throw new XmlValidationException('<assign> may only contain one <var>.');
                }

                $target = Variable::fromXml($child);
                continue;
            }

            if ($child->tagName === 'expr') {
                if ($expr !== null) {
                    throw new XmlValidationException('<assign> may only contain one <expr>.');
                }

                $expr = Expr::fromXml($child);
                continue;
            }

            throw new XmlValidationException(
                sprintf('Unexpected <%s> inside <assign>.', $child->tagName)
            );
        }

        if ($target === null || $expr === null) {
            throw new XmlValidationException('<assign> must contain both <var> and <expr>.');
        }

        return new self(
            XmlHelper::parseIntAttribute($element, 'order'),
            $target,
            $expr
        );
    }
}
