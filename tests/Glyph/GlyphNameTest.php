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

namespace Alto\Font\Tests\Glyph;

use Alto\Font\Glyph\GlyphName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphName::class)]
final class GlyphNameTest extends TestCase
{
    #[DataProvider('names')]
    public function testItConvertsGlyphNames(string $name, string $expected): void
    {
        self::assertSame($expected, GlyphName::toUnicode($name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'Latin accent' => ['eacute', 'é'];
        yield 'AGL name before Unicode prefix' => ['u', 'u'];
        yield 'case sensitive AGL' => ['Eacute', 'É'];
        yield 'Greek' => ['Alpha', 'Α'];
        yield 'Cyrillic' => ['Acyrillic', 'А'];
        yield 'multiple scalars in AGL' => ['dalethatafpatah', "\u{05D3}\u{05B2}"];
        yield 'private use retained' => ['Ogoneksmall', "\u{F6FB}"];
        yield 'AGL ligature retained' => ['fi', "\u{FB01}"];
        yield 'underscore ligature' => ['f_f_i', 'ffi'];
        yield 'suffix stripped' => ['A.swash', 'A'];
        yield 'first period only' => ['A.swash.alt', 'A'];
        yield 'suffix before decomposition' => ['T.swash_h', 'T'];
        yield 'suffix applies to all components' => ['T_h.swash', 'Th'];
        yield 'unknown component omitted' => ['A_unknown_B', 'AB'];
        yield 'empty components omitted' => ['_A__B_', 'AB'];
        yield 'malformed component omitted' => ['A_uniD800_B', 'AB'];
        yield 'component case' => ['A_UNI0042_B', 'AB'];
        yield 'AGL specification example' => ['Lcommaaccent_uni20AC0308_u1040C.alternate', "\u{013B}\u{20AC}\u{0308}\u{1040C}"];
        yield 'BMP sequence' => ['uni00410301', "A\u{0301}"];
        yield 'BMP u form' => ['u00E9', 'é'];
        yield 'five digit BMP with leading zero' => ['u00041', 'A'];
        yield 'six digit BMP with leading zeros' => ['u000041', 'A'];
        yield 'supplementary scalar' => ['u1F600', "\u{1F600}"];
        yield 'NUL followed by mapped character' => ['uni0000_A', "\0A"];
    }

    #[DataProvider('unknownNames')]
    public function testItReturnsEmptyForUnmappedNames(string $name): void
    {
        self::assertSame('', GlyphName::toUnicode($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownNames(): iterable
    {
        foreach ([
            '', '.notdef', '.null', 'unknown', 'EACUTE', '___', 'uni',
            'uni004', 'uni00410', 'uni004100', 'uni0041000', 'uni0041FFFF0',
            'uni20ac', 'uni004g', 'uni+041', 'uni 041', 'uni0041\n',
            'uniD800', 'uniDFFF', 'uni0041D800', 'uniD801DC0C',
            'u041', 'u0000041', 'u1f600', 'u1G600', 'u+0041',
            'uD800', 'uDFFF', 'u0D800', 'u00DFFF', 'u110000', 'uFFFFFF',
            'U0041', 'Uni0041', 'UNI0041', '/A', 'é',
        ] as $name) {
            yield $name => [$name];
        }

        yield 'trailing newline' => ["uni0041\n"];
        yield 'trailing newline in u form' => ["u0041\n"];
        yield 'embedded NUL' => ["u00\00041"];
        yield 'invalid UTF-8' => ["\xFF"];
    }

    public function testItEncodesUnicodeScalarBoundaries(): void
    {
        foreach ([0, 0x7F, 0x80, 0x7FF, 0x800, 0xD7FF, 0xE000, 0xFFFF, 0x10000, 0x10FFFF] as $codepoint) {
            $expected = iconv('UTF-32BE', 'UTF-8', pack('N', $codepoint));
            self::assertNotFalse($expected);
            self::assertSame($expected, GlyphName::toUnicode(sprintf('u%04X', $codepoint)));

            if ($codepoint <= 0xFFFF) {
                self::assertSame($expected, GlyphName::toUnicode(sprintf('uni%04X', $codepoint)));
            }
        }
    }

    public function testItOnlyUsesDingbatNamesWhenRequested(): void
    {
        self::assertSame('', GlyphName::toUnicode('a1'));
        self::assertSame("\u{2701}", GlyphName::toUnicode('a1', zapfDingbats: true));
        self::assertSame("\u{2701}A\u{1F600}", GlyphName::toUnicode('a1_A_u1F600.alt', zapfDingbats: true));
        self::assertSame('é', GlyphName::toUnicode('eacute', zapfDingbats: true));
        self::assertSame('', GlyphName::toUnicode('a999', zapfDingbats: true));
        self::assertSame('', GlyphName::toUnicode('a1'));
        self::assertSame('A', GlyphName::toUnicode('a1_A'));
    }

    public function testItMatchesEveryAuthoritativeAdobeMapping(): void
    {
        foreach (['glyphlist' => 4281, 'zapfdingbats' => 201] as $list => $expectedCount) {
            $lines = file(__DIR__ . '/../../resources/glyph-list/' . $list . '.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertNotFalse($lines);
            $count = 0;

            foreach ($lines as $line) {
                if (str_starts_with($line, '#')) {
                    continue;
                }

                [$name, $hex] = explode(';', $line);
                $bytes = hex2bin(str_replace(' ', '', $hex));
                self::assertNotFalse($bytes);
                $expected = iconv('UTF-16BE', 'UTF-8', $bytes);
                self::assertNotFalse($expected);
                self::assertSame($expected, GlyphName::toUnicode($name, zapfDingbats: 'zapfdingbats' === $list), $name);
                self::assertSame($expected, GlyphName::toUnicode($name . '.alt', zapfDingbats: 'zapfdingbats' === $list), $name . '.alt');
                ++$count;
            }

            self::assertSame($expectedCount, $count);
        }
    }

    public function testItHandlesLongNamesWithoutRecursionOrTruncation(): void
    {
        self::assertSame(str_repeat('A', 16384), GlyphName::toUnicode('uni' . str_repeat('0041', 16384)));
        self::assertSame(str_repeat('AB', 16384), GlyphName::toUnicode(str_repeat('A_unknown_B_', 16384)));
        self::assertSame('A', GlyphName::toUnicode('A.' . str_repeat('ignored', 16384)));
        self::assertSame('', GlyphName::toUnicode('uni' . str_repeat('0041', 16384) . 'D800'));
        self::assertSame('', GlyphName::toUnicode('uni' . str_repeat('0041', 16384) . '00g1'));
        self::assertSame('', GlyphName::toUnicode(str_repeat('_', 65536)));
    }
}
