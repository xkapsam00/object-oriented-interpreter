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
 * `<class name="..." parent="...">`
 *   `<method selector="...">...</method>`
 *   ...
 * `</class>`
 */
class ClassDef
{
    public readonly string $name;
    public readonly string $parent;

    /**
     * @var list<Method>
     */
    public readonly array $methods;

    /**
     * @param list<Method> $methods
     */
    private function __construct(string $name, string $parent, array $methods)
    {
        $this->name = $name;
        $this->parent = $parent;
        $this->methods = $methods;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'class');
        XmlHelper::assertOnlyAttributes($element, ['name', 'parent']);
        XmlHelper::assertNoUnexpectedText($element);

        $methods = [];
        foreach (XmlHelper::childElements($element) as $child) {
            if ($child->tagName !== 'method') {
                throw new XmlValidationException(
                    sprintf('Unexpected <%s> inside <class>.', $child->tagName)
                );
            }

            $methods[] = Method::fromXml($child);
        }

        return new self(
            XmlHelper::requireAttribute($element, 'name'),
            XmlHelper::requireAttribute($element, 'parent'),
            $methods
        );
    }
}
