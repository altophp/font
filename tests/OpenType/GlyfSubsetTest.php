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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Font;
use Alto\Font\OpenType\GlyfSubset;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyfSubset::class)]
final class GlyfSubsetTest extends TestCase
{
    public function testItCarriesTheSubsetDocumentCountsAndWarnings(): void
    {
        $path = sys_get_temp_dir() . '/alto-font-glyf-subset-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::write($path);
        $document = Font::fromFile($path)->sfntDocument();

        $subset = new GlyfSubset($document, 2, 3, ['layout preserved']);

        self::assertSame($document, $subset->document);
        self::assertSame(2, $subset->mappedCodepointCount);
        self::assertSame(3, $subset->retainedGlyphCount);
        self::assertSame(['layout preserved'], $subset->warnings);
    }
}
