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
 * `<block arity="...">`
 *   `<parameter name="..." order="..."/>`
 *   ...
 *   `<assign order="...">...</assign>`
 *   ...
 * `</block>`
 */
class Block
{
    public readonly int $arity;

    /**
     * @var list<Parameter>
     */
    public readonly array $parameters;

    /**
     * @var list<Assign>
     */
    public readonly array $assigns;

    /**
     * @param list<Parameter> $parameters
     * @param list<Assign> $assigns
     */
    private function __construct(int $arity, array $parameters, array $assigns)
    {
        // Keep deterministic order according to explicit XML `order` attribute.
        usort(
            $parameters,
            static fn (Parameter $left, Parameter $right): int => $left->order <=> $right->order
        );
        usort(
            $assigns,
            static fn (Assign $left, Assign $right): int => $left->order <=> $right->order
        );

        $this->arity = $arity;
        $this->parameters = $parameters;
        $this->assigns = $assigns;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'block');
        XmlHelper::assertOnlyAttributes($element, ['arity']);
        XmlHelper::assertNoUnexpectedText($element);

        $parameters = [];
        $assigns = [];

        foreach (XmlHelper::childElements($element) as $child) {
            if ($child->tagName === 'parameter') {
                $parameters[] = Parameter::fromXml($child);
                continue;
            }

            if ($child->tagName === 'assign') {
                $assigns[] = Assign::fromXml($child);
                continue;
            }

            throw new XmlValidationException(
                sprintf('Unexpected <%s> inside <block>.', $child->tagName)
            );
        }

        return new self(
            XmlHelper::parseIntAttribute($element, 'arity'),
            $parameters,
            $assigns
        );
    }

    /**
     * Creates an empty built-in block with arity 0 and no statements.
     */
    public static function empty(): self
    {
        return new self(0, [], []);
    }
}
