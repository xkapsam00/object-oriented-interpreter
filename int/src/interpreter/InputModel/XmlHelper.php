<?php

/**
 * This module defines helper utilities for strict XML structure validation.
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
use DOMText;

final class XmlHelper
{
    /**
     * @param list<string> $allowedAttributes
     */
    public static function assertOnlyAttributes(DOMElement $element, array $allowedAttributes): void
    {
        foreach ($element->attributes as $attributeNode) {
            if (!in_array($attributeNode->name, $allowedAttributes, true)) {
                throw new XmlValidationException(
                    sprintf(
                        'Unexpected attribute "%s" in <%s>.',
                        $attributeNode->name,
                        $element->tagName
                    )
                );
            }
        }
    }

    public static function assertTag(DOMElement $element, string $expectedTag): void
    {
        if ($element->tagName !== $expectedTag) {
            throw new XmlValidationException(
                sprintf('Expected <%s>, got <%s>.', $expectedTag, $element->tagName)
            );
        }
    }

    public static function requireAttribute(DOMElement $element, string $name): string
    {
        if (!$element->hasAttribute($name)) {
            throw new XmlValidationException(
                sprintf('Missing attribute "%s" in <%s>.', $name, $element->tagName)
            );
        }

        return $element->getAttribute($name);
    }

    public static function optionalAttribute(DOMElement $element, string $name): ?string
    {
        if (!$element->hasAttribute($name)) {
            return null;
        }

        return $element->getAttribute($name);
    }

    public static function parseIntAttribute(DOMElement $element, string $name): int
    {
        $value = self::requireAttribute($element, $name);
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new XmlValidationException(
                sprintf('Attribute "%s" in <%s> must be an integer.', $name, $element->tagName)
            );
        }

        return (int) $value;
    }

    /**
     * @return list<DOMElement>
     */
    public static function childElements(DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $childNode) {
            if ($childNode instanceof DOMElement) {
                $children[] = $childNode;
            }
        }

        return $children;
    }

    public static function assertNoUnexpectedText(DOMElement $element): void
    {
        foreach ($element->childNodes as $childNode) {
            if (
                $childNode instanceof DOMText
                && trim($childNode->wholeText) !== ''
            ) {
                throw new XmlValidationException(
                    sprintf('Element <%s> contains unexpected text.', $element->tagName)
                );
            }
        }
    }
}
