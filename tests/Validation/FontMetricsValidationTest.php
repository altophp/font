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

namespace Alto\Font\Tests\Validation;

use Alto\Font\Font;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FontMetricsValidationTest extends TestCase
{
    #[DataProvider('fonts')]
    public function testDeclaredMetricsAgreeWithFontTools(string $filename): void
    {
        $path = __DIR__ . '/../Fixtures/Fonts/' . $filename;
        $expected = FontValidationTools::run([
            FontValidationTools::python(), '-c', <<<'PYTHON'
import json
import sys
from fontTools.ttLib import TTFont

with TTFont(sys.argv[1]) as font:
    head, hhea = font['head'], font['hhea']
    post, os2 = font.get('post'), font.get('OS/2')
    print(json.dumps(dict(
        unitsPerEm=head.unitsPerEm,
        ascender=hhea.ascent,
        descender=hhea.descent,
        lineGap=hhea.lineGap,
        bounds={name: getattr(head, name) for name in ('xMin', 'yMin', 'xMax', 'yMax')},
        capHeight=getattr(os2, 'sCapHeight', 0) or None,
        xHeight=getattr(os2, 'sxHeight', 0) or None,
        italicAngle=post.italicAngle if post else None,
        isFixedPitch=bool(post.isFixedPitch) if post else None,
        embeddingFlags=os2.fsType if os2 else None,
    )))
PYTHON,
            $path,
        ]);
        self::assertJsonStringEqualsJsonString($expected, json_encode(Font::fromFile($path)->metrics(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fonts(): iterable
    {
        yield 'Arabic' => ['NotoNaskhArabic-Regular.ttf'];
        yield 'Devanagari' => ['NotoSansDevanagari-Regular.ttf'];
        yield 'CJK' => ['AltoCorpusCJK.ttf'];
        yield 'variable default' => ['AltoCorpusVariable.ttf'];
    }
}
