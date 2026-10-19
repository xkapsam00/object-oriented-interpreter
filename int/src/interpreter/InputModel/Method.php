<?php

/**
 * This module defines program-structure nodes in the SOL-XML input model.
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
 * `<method selector="...">`
 *   `<block arity="...">...</block>`
 * `</method>`
 */
class Method
{
    public readonly string $selector;
    public readonly Block $block;

    private function __construct(string $selector, Block $block)
    {
        $this->selector = $selector;
        $this->block = $block;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'method');
        XmlHelper::assertOnlyAttributes($element, ['selector']);
        XmlHelper::assertNoUnexpectedText($element);

        $children = XmlHelper::childElements($element);
        if (count($children) !== 1 || $children[0]->tagName !== 'block') {
            throw new XmlValidationException('<method> must contain exactly one <block> child.');
        }

        return new self(
            XmlHelper::requireAttribute($element, 'selector'),
            Block::fromXml($children[0])
        );
    }
}
