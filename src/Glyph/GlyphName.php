<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Font\Glyph;

/**
 * Derives UTF-8 text from glyph names using the Adobe Glyph List specification.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GlyphName
{
    /**
     * @var array<string, string>|null
     */
    private static ?array $glyphList = null;

    /**
     * @var array<string, string>|null
     */
    private static ?array $zapfDingbats = null;

    /**
     * Unknown components contribute an empty string. No Unicode normalization is applied.
     *
     * Enable $zapfDingbats only for the font whose PostScript name is ZapfDingbats.
     */
    public static function toUnicode(string $name, bool $zapfDingbats = false): string
    {
        $period = strpos($name, '.');

        if (false !== $period) {
            $name = substr($name, 0, $period);
        }

        if ('' === $name) {
            return '';
        }

        self::$glyphList ??= self::loadMapping('glyphlist');

        if ($zapfDingbats) {
            self::$zapfDingbats ??= self::loadMapping('zapfdingbats');
        }

        $text = '';

        foreach (explode('_', $name) as $component) {
            $text .= ($zapfDingbats ? self::$zapfDingbats[$component] ?? null : null)
                ?? self::$glyphList[$component]
                ?? self::unicodeComponent($component);
        }

        return $text;
    }

    /**
     * @param 'glyphlist'|'zapfdingbats' $name
     *
     * @return array<string, string>
     */
    private static function loadMapping(string $name): array
    {
        // The generated, bundled tables are verified against Adobe's originals in tests.
        /** @var array<string, string> $mapping */
        $mapping = require __DIR__ . '/../../resources/glyph-list/' . $name . '.php';

        return $mapping;
    }

    private static function unicodeComponent(string $component): string
    {
        $length = \strlen($component);

        if (str_starts_with($component, 'uni')) {
            if ($length < 7 || 0 !== ($length - 3) % 4 || strspn($component, '0123456789ABCDEF', 3) !== $length - 3) {
                return '';
            }

            $text = '';

            for ($offset = 3; $offset < $length; $offset += 4) {
                $codepoint = (int) hexdec(substr($component, $offset, 4));

                if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                    return '';
                }

                $text .= self::utf8($codepoint);
            }

            return $text;
        }

        if ($length < 5 || $length > 7 || 'u' !== $component[0] || strspn($component, '0123456789ABCDEF', 1) !== $length - 1) {
            return '';
        }

        $codepoint = (int) hexdec(substr($component, 1));

        if ($codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            return '';
        }

        return self::utf8($codepoint);
    }

    private static function utf8(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return pack('C', $codepoint);
        }

        if ($codepoint < 0x800) {
            return pack('C*', 0xC0 | ($codepoint >> 6), 0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            return pack('C*', 0xE0 | ($codepoint >> 12), 0x80 | (($codepoint >> 6) & 0x3F), 0x80 | ($codepoint & 0x3F));
        }

        return pack('C*', 0xF0 | ($codepoint >> 18), 0x80 | (($codepoint >> 12) & 0x3F), 0x80 | (($codepoint >> 6) & 0x3F), 0x80 | ($codepoint & 0x3F));
    }
}
