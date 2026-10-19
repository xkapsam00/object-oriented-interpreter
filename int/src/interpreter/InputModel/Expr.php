<?php

/**
 * This module defines expression nodes in the SOL-XML input model.
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
 * `<expr>` contains exactly one child, one of:
 * - `<literal .../>`
 * - `<var .../>`
 * - `<block ...>...</block>`
 * - `<send ...>...</send>`
 */
class Expr
{
    public readonly ?Literal $literal;
    public readonly ?Variable $variable;
    public readonly ?Block $block;
    public readonly ?Send $send;

    private function __construct(?Literal $literal, ?Variable $variable, ?Block $block, ?Send $send)
    {
        $presentCount = 0;
        foreach ([$literal, $variable, $block, $send] as $child) {
            if ($child !== null) {
                $presentCount++;
            }
        }

        if ($presentCount !== 1) {
            throw new XmlValidationException(
                '<expr> must contain exactly one of: literal|var|block|send.'
            );
        }

        $this->literal = $literal;
        $this->variable = $variable;
        $this->block = $block;
        $this->send = $send;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'expr');
        XmlHelper::assertOnlyAttributes($element, []);
        XmlHelper::assertNoUnexpectedText($element);

        $children = XmlHelper::childElements($element);
        if (count($children) !== 1) {
            throw new XmlValidationException(
                '<expr> must contain exactly one child element.'
            );
        }

        $child = $children[0];

        return match ($child->tagName) {
            'literal' => new self(Literal::fromXml($child), null, null, null),
            'var' => new self(null, Variable::fromXml($child), null, null),
            'block' => new self(null, null, Block::fromXml($child), null),
            'send' => new self(null, null, null, Send::fromXml($child)),
            default => throw new XmlValidationException(
                sprintf('Unexpected <%s> inside <expr>.', $child->tagName)
            ),
        };
    }
}
