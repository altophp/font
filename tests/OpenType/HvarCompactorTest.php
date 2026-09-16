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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('mappingPlacements')]
    public function testItAcceptsMappingsBeforeAndInsideVariationStoreGaps(bool $inside): void
    {
        $source = self::hvar();
        $reader = new BinaryReader($source, 'source HVAR');
        $mapStart = $reader->uint32(8);
        $maps = substr($source, $mapStart);
        $store = substr($source, 20, $mapStart - 20);
        $storeReader = new BinaryReader($store, 'source store');
        $insert = $inside ? 12 : 0;

        if ($inside) {
            $store = substr_replace($store, self::u32($storeReader->uint32(2) + \strlen($maps)), 2, 4);
            $store = substr_replace($store, self::u32($storeReader->uint32(8) + \strlen($maps)), 8, 4);
        }

        $header = self::u16(1) . self::u16(0) . self::u32($inside ? 20 : 20 + \strlen($maps));

        foreach ([8, 12, 16] as $field) {
            $header .= self::u32(20 + $insert + $reader->uint32($field) - $mapStart);
        }

        $reordered = $header . substr($store, 0, $insert) . $maps . substr($store, $insert) . 'unrelated';
        $glyphIds = GlyphIdMap::fromRetained(5, [4 => true]);

        self::assertSame(HvarCompactor::compact($source, $glyphIds), HvarCompactor::compact($reordered, $glyphIds));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function mappingPlacements(): iterable
    {
        yield 'before store' => [false];
        yield 'inside store gaps' => [true];
    }

    public function testItRemapsSharedMappings(): void
    {
        $source = self::hvar();
        $shared = substr_replace($source, substr($source, 12, 4), 16, 4);
        $glyphIds = GlyphIdMap::fromRetained(5, [4 => true]);

        self::assertSame(HvarCompactor::compact($source, $glyphIds), HvarCompactor::compact($shared, $glyphIds));
    }

    public function testItRejectsOverlappingMappings(): void
    {
        $hvar = self::hvar();
        $offset = \strlen($hvar);
        $hvar .= "\0\0\0\x08\0\0\0\x01\0\0\0\0";
        $hvar = substr_replace($hvar, self::u32($offset), 8, 4);
        $hvar = substr_replace($hvar, self::u32($offset + 4), 12, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('delta-set mappings overlap');

        HvarCompactor::compact($hvar, GlyphIdMap::fromRetained(5, [1 => true]));
    }

    public function testItRejectsMappingsOverlappingVariationDeltas(): void
    {
        $hvar = self::hvar();
        $reader = new BinaryReader($hvar, 'source HVAR');
        $data = 20 + $reader->uint32(28);
        $mapOffset = $data + 10;
        $hvar = substr_replace($hvar, "\0\0\0\x01\0", $mapOffset, 5);
        $hvar = substr_replace($hvar, self::u32($mapOffset), 8, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ItemVariationStore overlaps a delta-set mapping');

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
        $this->expectExceptionMessage('Unsupported DeltaSetIndexMap format 2');

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
