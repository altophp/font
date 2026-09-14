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
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\VvarCompactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VvarCompactor::class)]
final class VvarCompactorTest extends TestCase
{
    public function testItRemapsEveryExplicitDeltaSetIndexMap(): void
    {
        $store = self::itemVariationStore();
        $vvar = self::vvar(
            $store,
            advance: self::map([[0, 1], [0, 2], [0, 3], [0, 4], [0, 5]]),
            top: self::map([[0, 6], [0, 7], [0, 8], [0, 9], [0, 10]]),
            bottom: self::map([[0, 11], [0, 12], [0, 13], [0, 14], [0, 15]]),
            verticalOrigin: self::map([[0, 16], [0, 17], [0, 18], [0, 19], [0, 20]]),
        );

        $compacted = self::compact($vvar, GlyphIdMap::fromRetained(5, [4 => true]));
        $reader = new BinaryReader($compacted, 'compacted VVAR');

        self::assertSame(24, $reader->uint32(4));
        self::assertSame($store, $reader->string(24, \strlen($store)));
        self::assertSame([[0, 1], [0, 5]], self::readMap($reader, $reader->uint32(8)));
        self::assertSame([[0, 6], [0, 10]], self::readMap($reader, $reader->uint32(12)));
        self::assertSame([[0, 11], [0, 15]], self::readMap($reader, $reader->uint32(16)));
        self::assertSame([[0, 16], [0, 20]], self::readMap($reader, $reader->uint32(20)));
    }

    public function testItMaterializesImplicitAdvancesAndPreservesAbsentOptionalMappings(): void
    {
        $vvar = self::vvar(
            self::itemVariationStore(),
            advance: null,
            top: self::map([[0, 5], [0, 6], [0, 7], [0, 8]]),
        );

        $compacted = self::compact($vvar, GlyphIdMap::fromRetained(4, [3 => true]));
        $reader = new BinaryReader($compacted, 'implicit compacted VVAR');

        self::assertSame([[0, 0], [0, 3]], self::readMap($reader, $reader->uint32(8)));
        self::assertSame([[0, 5], [0, 8]], self::readMap($reader, $reader->uint32(12)));
        self::assertSame(0, $reader->uint32(16));
        self::assertSame(0, $reader->uint32(20));
    }

    public function testItCanonicalizesSafePhysicalMappingOrder(): void
    {
        $store = self::itemVariationStore();
        $bottom = self::map([[0, 9], [0, 10]]);
        $top = self::map([[0, 3], [0, 4]]);
        $storeOffset = 24;
        $bottomOffset = $storeOffset + \strlen($store);
        $topOffset = $bottomOffset + \strlen($bottom);
        $vvar = self::u16(1)
            . self::u16(0)
            . self::u32($storeOffset)
            . self::u32(0)
            . self::u32($topOffset)
            . self::u32($bottomOffset)
            . self::u32(0)
            . $store
            . $bottom
            . $top;

        $compacted = self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
        $reader = new BinaryReader($compacted, 'canonical VVAR');

        self::assertLessThan($reader->uint32(16), $reader->uint32(12));
        self::assertSame([[0, 3], [0, 4]], self::readMap($reader, $reader->uint32(12)));
        self::assertSame([[0, 9], [0, 10]], self::readMap($reader, $reader->uint32(16)));
    }

    public function testItReadsFormatOneMappingsAndWritesCompactFormatZero(): void
    {
        $vvar = self::vvar(
            self::itemVariationStore(),
            advance: self::map([[0, 1], [0, 2], [0, 3]], format: 1),
        );
        $compacted = self::compact($vvar, GlyphIdMap::fromRetained(3, [2 => true]));
        $reader = new BinaryReader($compacted, 'format one input VVAR');
        $advanceOffset = $reader->uint32(8);

        self::assertSame(0, $reader->uint8($advanceOffset));
        self::assertSame([[0, 1], [0, 3]], self::readMap($reader, $advanceOffset));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $vvar = substr_replace(self::vvar(self::itemVariationStore()), self::u16(2), 0, 2);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('version 1.0 only');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsInvalidItemVariationStoreOffsets(): void
    {
        $vvar = substr_replace(self::vvar(self::itemVariationStore()), self::u32(23), 4, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('item variation store offset is invalid');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItAcceptsMappingsBeforeTheVariationStore(): void
    {
        $map = self::map([[0, 1]]);
        $store = self::itemVariationStore();
        $vvar = self::u16(1)
            . self::u16(0)
            . self::u32(24 + \strlen($map))
            . self::u32(24)
            . self::u32(0)
            . self::u32(0)
            . self::u32(0)
            . $map
            . $store;

        $compacted = self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
        $reader = new BinaryReader($compacted, 'reordered compacted VVAR');

        self::assertSame(24, $reader->uint32(4));
        self::assertSame($store, $reader->string(24, \strlen($store)));
        self::assertSame([[0, 1], [0, 1]], self::readMap($reader, $reader->uint32(8)));
    }

    public function testItRejectsInvalidMappingOffsets(): void
    {
        $vvar = self::vvar(self::itemVariationStore(), advance: self::map([[0, 1]]));
        $vvar = substr_replace($vvar, self::u32(\strlen($vvar)), 8, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('advance height mapping offset is invalid');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsUnsupportedMappingFormats(): void
    {
        $vvar = self::vvar(self::itemVariationStore(), advance: "\x02\0\0\0");

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('mapping supports formats 0 and 1 only');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsReservedMappingEntryBits(): void
    {
        $vvar = self::vvar(self::itemVariationStore(), advance: "\0\x40\0\1\0");

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('mapping entry format is invalid');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsEmptyMappings(): void
    {
        $vvar = self::vvar(self::itemVariationStore(), advance: "\0\0\0\0");

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('mapping must contain at least one entry');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsTruncatedMappings(): void
    {
        $vvar = self::vvar(self::itemVariationStore(), advance: "\0\x10\0\2\0");

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('mapping length is invalid');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsTruncatedMappingHeaders(): void
    {
        $vvar = self::vvar(self::itemVariationStore(), advance: "\0");

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('mapping length is invalid');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsOverlappingMappings(): void
    {
        $vvar = self::vvar(
            self::itemVariationStore(),
            advance: self::map([[0, 1]]),
            top: self::map([[0, 2]]),
        );
        $vvar = substr_replace($vvar, substr($vvar, 8, 4), 12, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('delta-set mappings overlap');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsItemVariationStoreDataThatOverlapsMappings(): void
    {
        $store = self::itemVariationStore();
        $vvar = self::vvar($store, advance: self::map([[0, 1]]));
        $dataOffset = 24 + 8;
        $vvar = substr_replace($vvar, self::u32(\strlen($store) - 2), $dataOffset, 4);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('overlaps a delta-set mapping');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsOverlappingItemVariationStoreStructures(): void
    {
        $store = self::u16(1)
            . self::u32(12)
            . self::u16(1)
            . self::u32(14)
            . self::u16(1)
            . str_repeat("\0", 6);
        $vvar = self::vvar($store);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('structures overlap');

        self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItPreservesNullItemVariationDataOffsets(): void
    {
        $store = self::itemVariationStore();
        $store = substr_replace($store, self::u32(0), 8, 4);
        $vvar = self::vvar($store);

        $compacted = self::compact($vvar, GlyphIdMap::fromRetained(2, [1 => true]));

        self::assertSame($store, substr($compacted, 24, \strlen($store)));
    }

    public function testItRejectsAxisCountsThatDifferFromFvar(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('axis count does not match fvar');

        VvarCompactor::compact(
            self::vvar(self::itemVariationStore()),
            GlyphIdMap::fromRetained(2, [1 => true]),
            2,
        );
    }

    public function testItRejectsLongDeltaWords(): void
    {
        $store = substr_replace(self::itemVariationStore(), self::u16(0x8001), 24, 2);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('does not support 32-bit delta words');

        self::compact(self::vvar($store), GlyphIdMap::fromRetained(2, [1 => true]));
    }

    /**
     * @param list<array{0: int, 1: int}> $entries
     */
    private static function map(array $entries, int $format = 0): string
    {
        $innerBitCount = 5;
        $data = '';

        foreach ($entries as [$outerIndex, $innerIndex]) {
            $data .= pack('n', ($outerIndex << $innerBitCount) | $innerIndex);
        }

        $count = 0 === $format ? self::u16(\count($entries)) : self::u32(\count($entries));

        return pack('C', $format) . "\x14" . $count . $data;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function readMap(BinaryReader $reader, int $offset): array
    {
        self::assertSame(0, $reader->uint8($offset));
        $entryFormat = $reader->uint8($offset + 1);
        $innerBitCount = ($entryFormat & 0x0F) + 1;
        $entrySize = (($entryFormat & 0x30) >> 4) + 1;
        $innerMask = (1 << $innerBitCount) - 1;
        $count = $reader->uint16($offset + 2);
        $cursor = $offset + 4;
        $entries = [];

        for ($index = 0; $index < $count; ++$index) {
            $entry = 0;

            for ($byte = 0; $byte < $entrySize; ++$byte) {
                $entry = ($entry << 8) | $reader->uint8($cursor++);
            }

            $entries[] = [$entry >> $innerBitCount, $entry & $innerMask];
        }

        return $entries;
    }

    private static function itemVariationStore(): string
    {
        $regionList = self::u16(1)
            . self::u16(1)
            . self::u16(0)
            . self::u16(0x4000)
            . self::u16(0x4000);
        $itemData = self::u16(32)
            . self::u16(1)
            . self::u16(1)
            . self::u16(0)
            . str_repeat("\0\1", 32);

        return self::u16(1)
            . self::u32(12)
            . self::u16(1)
            . self::u32(12 + \strlen($regionList))
            . $regionList
            . $itemData;
    }

    private static function compact(string $vvar, GlyphIdMap $glyphIds): string
    {
        return VvarCompactor::compact($vvar, $glyphIds, 1);
    }

    private static function vvar(
        string $store,
        ?string $advance = null,
        ?string $top = null,
        ?string $bottom = null,
        ?string $verticalOrigin = null,
    ): string {
        $cursor = 24 + \strlen($store);
        $offsets = [];
        $data = '';

        foreach ([$advance, $top, $bottom, $verticalOrigin] as $mapping) {
            $offsets[] = null === $mapping ? 0 : $cursor;
            $cursor += null === $mapping ? 0 : \strlen($mapping);
            $data .= $mapping ?? '';
        }

        return self::u16(1)
            . self::u16(0)
            . self::u32(24)
            . self::u32($offsets[0])
            . self::u32($offsets[1])
            . self::u32($offsets[2])
            . self::u32($offsets[3])
            . $store
            . $data;
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
