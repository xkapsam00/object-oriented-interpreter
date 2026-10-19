<?php

/**
 * This module defines top-level program structure in the SOL-XML input model.
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
 * `<program language="..." description="...">`
 *   `<class ...>...</class>`
 *   ...
 * `</program>`
 */
class Program
{
    public readonly string $language;
    public readonly ?string $description;

    /**
     * @var list<ClassDef>
     */
    public readonly array $classes;

    /**
     * @param list<ClassDef> $classes
     */
    private function __construct(string $language, ?string $description, array $classes)
    {
        $this->language = $language;
        $this->description = $description;
        $this->classes = $classes;
    }

    public static function fromXml(DOMElement $element): self
    {
        XmlHelper::assertTag($element, 'program');
        XmlHelper::assertOnlyAttributes($element, ['language', 'description']);
        XmlHelper::assertNoUnexpectedText($element);

        $classes = [];
        foreach (XmlHelper::childElements($element) as $child) {
            if ($child->tagName !== 'class') {
                throw new XmlValidationException(
                    sprintf('Unexpected <%s> inside <program>.', $child->tagName)
                );
            }

            $classes[] = ClassDef::fromXml($child);
        }

        return new self(
            XmlHelper::requireAttribute($element, 'language'),
            XmlHelper::optionalAttribute($element, 'description'),
            $classes
        );
    }
}
