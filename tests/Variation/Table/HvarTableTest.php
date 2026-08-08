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

namespace Alto\Font\Tests\Variation\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\Table\HvarTable;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HvarTable::class)]
final class HvarTableTest extends TestCase
{
    public function testItCalculatesMappedAdvanceAndLeftSideBearingDeltas(): void
    {
        $hvar = HvarTable::parse(new BinaryReader(self::hvar(mapped: true, sideBearings: true), 'HVAR'), self::variations());
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]);

        self::assertSame(100.0, $hvar->advanceWidthDelta(1, $coordinates));
        self::assertSame(5.0, $hvar->leftSideBearingDelta(1, $coordinates));
        self::assertSame(5.0, $hvar->rightSideBearingDelta(1, $coordinates));
    }

    public function testItUsesImplicitGlyphIdMappingsWhenAdvanceMapIsNull(): void
    {
        $hvar = HvarTable::parse(new BinaryReader(self::hvar(mapped: false, sideBearings: false), 'HVAR'), self::variations());

        self::assertSame(5.0, $hvar->advanceWidthDelta(1, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0])));
        self::assertSame(0.0, $hvar->leftSideBearingDelta(1, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0])));
        self::assertSame(0.0, $hvar->rightSideBearingDelta(1, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0])));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported HVAR table version 2.0.');

        HvarTable::parse(new BinaryReader(self::u16(2) . self::u16(0) . str_repeat("\0", 16), 'HVAR'), self::variations());
    }

    public function testItRejectsNullItemVariationStores(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('HVAR item variation store offset must not be NULL.');

        HvarTable::parse(new BinaryReader(self::u16(1) . self::u16(0) . str_repeat("\0", 16), 'HVAR'), self::variations());
    }

    public function testItRejectsIncompleteSideBearingMappings(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('HVAR side-bearing mappings must both be present or both be NULL.');

        HvarTable::parse(new BinaryReader(self::hvar(mapped: true, sideBearings: false, invalidSideBearingPair: true), 'HVAR'), self::variations());
    }

    public function testItWrapsInvalidMappingsWithContext(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Invalid HVAR advanceWidth mapping: Unsupported DeltaSetIndexMap format 9.');

        HvarTable::parse(new BinaryReader(self::hvar(mapped: true, sideBearings: false, invalidAdvanceMap: true), 'HVAR'), self::variations());
    }

    private static function hvar(
        bool $mapped,
        bool $sideBearings,
        bool $invalidSideBearingPair = false,
        bool $invalidAdvanceMap = false,
    ): string {
        $store = self::store();
        $advanceMap = $invalidAdvanceMap ? self::u8(9) . self::u8(0) : self::map([0, 0, 1, 0, 0]);
        $leftSideBearingMap = self::map([1, 1, 1, 1, 1]);
        $rightSideBearingMap = self::map([1, 1, 1, 1, 1]);
        $offset = 20;
        $storeOffset = $offset;
        $offset += \strlen($store);
        $advanceMapOffset = $mapped ? $offset : 0;
        $offset += $mapped ? \strlen($advanceMap) : 0;
        $leftSideBearingMapOffset = $sideBearings || $invalidSideBearingPair ? $offset : 0;
        $offset += $sideBearings || $invalidSideBearingPair ? \strlen($leftSideBearingMap) : 0;
        $rightSideBearingMapOffset = $sideBearings ? $offset : 0;

        return self::u16(1)
            . self::u16(0)
            . self::u32($storeOffset)
            . self::u32($advanceMapOffset)
            . self::u32($leftSideBearingMapOffset)
            . self::u32($rightSideBearingMapOffset)
            . $store
            . ($mapped ? $advanceMap : '')
            . ($sideBearings || $invalidSideBearingPair ? $leftSideBearingMap : '')
            . ($sideBearings ? $rightSideBearingMap : '');
    }

    private static function store(): string
    {
        $regionList = self::regionList();
        $itemData = self::itemData();
        $regionListOffset = 12;
        $itemDataOffset = $regionListOffset + \strlen($regionList);

        return self::u16(1)
            . self::u32($regionListOffset)
            . self::u16(1)
            . self::u32($itemDataOffset)
            . $regionList
            . $itemData;
    }

    private static function regionList(): string
    {
        return self::u16(2)
            . self::u16(2)
            . self::f2dot14(0.0) . self::f2dot14(1.0) . self::f2dot14(1.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0) . self::f2dot14(0.0);
    }

    private static function itemData(): string
    {
        return self::u16(2)
            . self::u16(2)
            . self::u16(2)
            . self::u16(0)
            . self::u16(1)
            . self::i16(100)
            . self::i16(-120)
            . self::i16(5)
            . self::i16(-10);
    }

    /**
     * @param list<int> $innerIndexes
     */
    private static function map(array $innerIndexes): string
    {
        return self::u8(0) . self::u8(0) . self::u16(\count($innerIndexes)) . implode('', array_map(
            static fn(int $innerIndex): string => self::u8($innerIndex),
            $innerIndexes,
        ));
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);
    }

    private static function u8(int $value): string
    {
        return pack('C', $value & 0xFF);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function i16(int $value): string
    {
        return self::u16($value);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value);
    }

    private static function f2dot14(float $value): string
    {
        return self::u16((int) round($value * 16384.0));
    }
}
