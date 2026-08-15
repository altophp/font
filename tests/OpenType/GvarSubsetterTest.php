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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Font;
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\GvarSubsetter;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\Table\GvarTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GvarSubsetter::class)]
final class GvarSubsetterTest extends TestCase
{
    public function testItPreservesRetainedGlyphVariationDataAndEmptiesOtherOffsets(): void
    {
        $font = self::variableFont();
        $gvar = $font->sfntDocument()->table('gvar');
        $variations = $font->variations();
        self::assertNotNull($gvar);
        self::assertNotNull($variations);

        $subset = GvarSubsetter::subset($gvar, 5, [0 => true, 1 => true]);
        $table = GvarTable::parse(new BinaryReader($subset, 'subset gvar'), $variations, 5);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame([-20.0, 0.0, 20.0, 0.0, 40.0], $table->deltasForGlyph(1, 5, $coordinates)->x);
        self::assertSame([], $table->tupleVariationsForGlyph(4, 5));
    }

    public function testItActuallyRemovesDiscardedGlyphVariationData(): void
    {
        $font = self::variableFont();
        $gvar = $font->sfntDocument()->table('gvar');
        self::assertNotNull($gvar);

        $subset = GvarSubsetter::subset($gvar, 5, [0 => true]);

        self::assertLessThan(\strlen($gvar), \strlen($subset));
    }

    public function testItCompactsGlyphVariationOffsets(): void
    {
        $font = self::variableFont();
        $gvar = $font->sfntDocument()->table('gvar');
        $variations = $font->variations();
        self::assertNotNull($gvar);
        self::assertNotNull($variations);

        $compacted = GvarSubsetter::compact($gvar, 5, GlyphIdMap::fromRetained(5, [1 => true, 4 => true]));
        $table = GvarTable::parse(new BinaryReader($compacted, 'compacted gvar'), $variations, 3);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame([-20.0, 0.0, 20.0, 0.0, 40.0], $table->deltasForGlyph(1, 5, $coordinates)->x);
        self::assertSame([], $table->tupleVariationsForGlyph(2, 5));
    }

    public function testItRejectsGlyphCountMismatches(): void
    {
        $font = self::variableFont();
        $gvar = $font->sfntDocument()->table('gvar');
        self::assertNotNull($gvar);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('gvar glyph count 5 does not match maxp glyph count 4');

        GvarSubsetter::subset($gvar, 4, [0 => true]);
    }

    public function testItRejectsGlyphDataOverlappingTheOffsetArray(): void
    {
        $gvar = self::gvar();
        $gvar = substr_replace($gvar, pack('N', 20), 16, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('glyph variation data overlaps');

        GvarSubsetter::subset($gvar, 5, [0 => true]);
    }

    public function testItRejectsSharedTuplesOverlappingTheHeader(): void
    {
        $gvar = self::gvar();
        $gvar = substr_replace($gvar, pack('n', 1), 6, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('shared tuples overlap');

        GvarSubsetter::subset($gvar, 5, [0 => true]);
    }

    public function testItRejectsSharedTuplesOverlappingGlyphData(): void
    {
        $gvar = self::gvar();
        $gvar = substr_replace($gvar, pack('n', 1), 6, 2);
        $gvar = substr_replace($gvar, pack('N', 32), 8, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('shared tuples overlap');

        GvarSubsetter::subset($gvar, 5, [0 => true]);
    }

    private static function gvar(): string
    {
        $gvar = self::variableFont()->sfntDocument()->table('gvar');
        self::assertNotNull($gvar);

        return $gvar;
    }

    private static function variableFont(): Font
    {
        $path = sys_get_temp_dir() . '/alto-font-gvar-subsetter-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithGvar($path);

        return Font::fromFile($path);
    }
}
