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
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\Font;
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\HvarCompactor;
use Alto\Font\Tests\Fixtures\TinyTrueTypeFont;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\Table\HvarTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HvarCompactor::class)]
final class HvarCompactorTest extends TestCase
{
    public function testItRemapsExplicitDeltaSetIndexMaps(): void
    {
        $font = self::variableFont();
        $hvar = $font->sfntDocument()->table('HVAR');
        $variations = $font->variations();
        self::assertNotNull($hvar);
        self::assertNotNull($variations);

        $compacted = HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [4 => true]));
        $table = HvarTable::parse(new BinaryReader($compacted, 'compacted HVAR'), $variations);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame(100.0, $table->advanceWidthDelta(0, $coordinates));
        self::assertSame(100.0, $table->advanceWidthDelta(1, $coordinates));
        self::assertSame(5.0, $table->leftSideBearingDelta(1, $coordinates));
    }

    public function testItMaterializesAnImplicitAdvanceMapping(): void
    {
        $font = self::variableFont();
        $hvar = $font->sfntDocument()->table('HVAR');
        $variations = $font->variations();
        self::assertNotNull($hvar);
        self::assertNotNull($variations);
        $hvar = substr_replace($hvar, pack('N', 0), 8, 4);

        $compacted = HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
        $table = HvarTable::parse(new BinaryReader($compacted, 'implicit compacted HVAR'), $variations);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame(100.0, $table->advanceWidthDelta(0, $coordinates));
        self::assertSame(5.0, $table->advanceWidthDelta(1, $coordinates));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $hvar = self::hvar();
        $hvar = substr_replace($hvar, self::u16(2), 0, 2);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('version 1.0 only');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsInvalidItemVariationStoreOffsets(): void
    {
        $hvar = substr_replace(self::hvar(), self::u32(19), 4, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('item variation store offset is invalid');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsIncompleteSideBearingMappings(): void
    {
        $hvar = substr_replace(self::hvar(), self::u32(0), 16, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('must both be present or both be NULL');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsMappingsBeforeTheVariationStore(): void
    {
        $hvar = substr_replace(self::hvar(), self::u32(20), 8, 4);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('requires mappings after the ItemVariationStore');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsInvalidDeltaSetMapOffsets(): void
    {
        $hvar = self::hvar();
        $hvar = substr_replace($hvar, self::u32(\strlen($hvar)), 8, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('delta-set mapping offset is invalid');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsInvalidDeltaSetMapFormats(): void
    {
        $hvar = self::hvar();
        $reader = new BinaryReader($hvar, 'HVAR');
        $advanceOffset = $reader->uint32(8);
        $hvar = substr_replace($hvar, "\x02", $advanceOffset, 1);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('mapping format is invalid');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsEmptyDeltaSetMaps(): void
    {
        $hvar = self::hvar();
        $reader = new BinaryReader($hvar, 'HVAR');
        $advanceOffset = $reader->uint32(8);
        $hvar = substr_replace($hvar, "\0\0", $advanceOffset + 2, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('must contain at least one entry');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    private static function hvar(): string
    {
        $hvar = self::variableFont()->sfntDocument()->table('HVAR');
        self::assertNotNull($hvar);

        return $hvar;
    }

    private static function variableFont(): Font
    {
        $path = sys_get_temp_dir() . '/alto-font-hvar-compactor-' . bin2hex(random_bytes(4)) . '.ttf';
        TinyTrueTypeFont::writeVariableWithHvar($path);

        return Font::fromFile($path);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
