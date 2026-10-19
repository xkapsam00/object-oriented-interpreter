<?php

/**
 * This module defines statement/expression nodes in the SOL-XML input model.
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
 * `<send selector="...">`
 *   `<expr>...</expr>` receiver expression (required)
 *   `<arg order="1"><expr>...</expr></arg>` optional ordered args
 * `</send>`
 */
class Send
{
    public readonly string $selector;
    public readonly Expr $receiver;

    /**
     * @var list<Arg>
     */
    public readonly array $args;

    /**
     * @param list<Arg> $args
     */
    private function __construct(string $selector, Expr $receiver, array $args)
    {
        usort($args, static fn (Arg $left, Arg $right): int => $left->order <=> $right->order);

        $this->selector = $selector;
        $this->receiver = $receiver;
        $this->args = $args;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'send');
        XmlHelper::assertOnlyAttributes($element, ['selector']);
        XmlHelper::assertNoUnexpectedText($element);

        $receiver = null;
        $args = [];

        foreach (XmlHelper::childElements($element) as $child) {
            if ($child->tagName === 'expr') {
                if ($receiver !== null) {
                    throw new XmlValidationException('<send> must contain exactly one receiver <expr>.');
                }

                $receiver = Expr::fromXml($child);
                continue;
            }

            if ($child->tagName === 'arg') {
                $args[] = Arg::fromXml($child);
                continue;
            }

            throw new XmlValidationException(
                sprintf('Unexpected <%s> inside <send>.', $child->tagName)
            );
        }

        if ($receiver === null) {
            throw new XmlValidationException('<send> is missing receiver <expr>.');
        }

        return new self(
            XmlHelper::requireAttribute($element, 'selector'),
            $receiver,
            $args
        );
    }
}
