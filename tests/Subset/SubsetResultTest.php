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

namespace Alto\Font\Tests\Subset;

use Alto\Font\Font;
use Alto\Font\Subset\SubsetResult;
use Alto\Font\Subset\UnicodeSet;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubsetResult::class)]
final class SubsetResultTest extends TestCase
{
    public function testItExposesSubsetMetricsAndWarnings(): void
    {
        $path = sys_get_temp_dir() . '/alto-font-subset-result-' . bin2hex(random_bytes(8)) . '.ttf';
        TinyTrueTypeFont::write($path);

        try {
            $font = Font::fromFile($path);
            $unicodes = UnicodeSet::fromCodepoints([0x41, 0x42]);
            $result = new SubsetResult($font, $unicodes, 2, 4, 3, 128, ['hinting removed']);

            self::assertSame($font, $result->font);
            self::assertSame($unicodes, $result->requestedUnicodes);
            self::assertSame(2, $result->mappedCodepointCount);
            self::assertSame(4, $result->originalGlyphCount);
            self::assertSame(3, $result->retainedGlyphCount);
            self::assertSame(128, $result->sfntSize);
            self::assertSame(['hinting removed'], $result->warnings);
        } finally {
            @unlink($path);
        }
    }

    public function testWarningsAreEmptyByDefault(): void
    {
        $path = sys_get_temp_dir() . '/alto-font-subset-result-' . bin2hex(random_bytes(8)) . '.ttf';
        TinyTrueTypeFont::write($path);

        try {
            $result = new SubsetResult(
                Font::fromFile($path),
                UnicodeSet::fromCodepoints([0x41]),
                1,
                4,
                2,
                96,
            );

            self::assertSame([], $result->warnings);
        } finally {
            @unlink($path);
        }
    }
}
