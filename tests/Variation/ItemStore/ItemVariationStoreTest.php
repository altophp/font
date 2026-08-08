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

namespace Alto\Font\Tests\Variation\ItemStore;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\ItemStore\ItemVariationStore;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemVariationStore::class)]
final class ItemVariationStoreTest extends TestCase
{
    public function testItCalculatesInterpolatedItemDeltas(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(), 'store'), self::variations());

        self::assertSame(50.0, $store->delta(0, 0, new NormalizedCoordinates(['wght' => 0.5, 'wdth' => 0.0])));
        self::assertSame(-120.0, $store->delta(0, 0, new NormalizedCoordinates(['wght' => 0.0, 'wdth' => -1.0])));
        self::assertSame(5.0, $store->delta(0, 1, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0])));
    }

    public function testItReturnsZeroForNullDataAndNoVariationIndexes(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(nullData: true), 'store'), self::variations());
        $coordinates = new NormalizedCoordinates(['wght' => 1.0, 'wdth' => -1.0]);

        self::assertSame(0.0, $store->delta(0, 0, $coordinates));
        self::assertSame(0.0, $store->delta(0xFFFF, 0xFFFF, $coordinates));
    }

    public function testItParsesLongWordDeltas(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(longWords: true), 'store'), self::variations());

        self::assertSame(300.0, $store->delta(0, 0, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0])));
        self::assertSame(-30.0, $store->delta(0, 1, new NormalizedCoordinates(['wght' => 0.0, 'wdth' => -1.0])));
    }

    public function testItRejectsReservedRegionCountBits(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('VariationRegionList region count uses reserved bits.');

        ItemVariationStore::parse(new BinaryReader(self::store(regionCount: 0x8000), 'store'), self::variations());
    }

    public function testItParsesByteDeltasAfterWordDeltas(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(wordDeltaCount: 1), 'store'), self::variations());

        self::assertSame(50.0, $store->delta(0, 0, new NormalizedCoordinates(['wght' => 0.5, 'wdth' => 0.0])));
        self::assertSame(-10.0, $store->delta(0, 1, new NormalizedCoordinates(['wght' => 0.0, 'wdth' => -1.0])));
    }

    public function testItParsesShortDeltasAfterLongWordDeltas(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(longWords: true, wordDeltaCount: 1), 'store'), self::variations());

        self::assertSame(300.0, $store->delta(0, 0, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0])));
        self::assertSame(-30.0, $store->delta(0, 1, new NormalizedCoordinates(['wght' => 0.0, 'wdth' => -1.0])));
    }

    public function testItRejectsUnsupportedFormats(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported ItemVariationStore format 2.');

        ItemVariationStore::parse(new BinaryReader(self::u16(2) . str_repeat("\0", 6), 'store'), self::variations());
    }

    public function testItRejectsAxisCountMismatches(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('VariationRegionList axis count 1 does not match fvar axis count 2.');

        ItemVariationStore::parse(new BinaryReader(self::store(axisCount: 1), 'store'), self::variations());
    }

    public function testItRejectsInvalidWordDeltaCounts(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ItemVariationData word delta count exceeds region index count.');

        ItemVariationStore::parse(new BinaryReader(self::store(wordDeltaCount: 3), 'store'), self::variations());
    }

    public function testItRejectsOutOfBoundsRegionIndexes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Variation region index 99 is out of bounds.');

        ItemVariationStore::parse(new BinaryReader(self::store(regionIndexes: [99, 1]), 'store'), self::variations());
    }

    public function testItRejectsOutOfBoundsOuterIndexes(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(), 'store'), self::variations());

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Item variation outer index 5 is out of bounds.');

        $store->delta(5, 0, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));
    }

    public function testItRejectsOutOfBoundsInnerIndexes(): void
    {
        $store = ItemVariationStore::parse(new BinaryReader(self::store(), 'store'), self::variations());

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Item variation inner index 5 is out of bounds.');

        $store->delta(0, 5, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));
    }

    /**
     * @param list<int> $regionIndexes
     */
    private static function store(
        bool $nullData = false,
        bool $longWords = false,
        int $axisCount = 2,
        int $regionCount = 2,
        int $wordDeltaCount = 2,
        array $regionIndexes = [0, 1],
    ): string {
        $regionList = self::regionList($axisCount, $regionCount);
        $itemData = $longWords
            ? self::itemDataLongWords($wordDeltaCount, $regionIndexes)
            : self::itemData($wordDeltaCount, $regionIndexes);
        $regionListOffset = 12;
        $itemDataOffset = $nullData ? 0 : $regionListOffset + \strlen($regionList);

        return self::u16(1)
            . self::u32($regionListOffset)
            . self::u16(1)
            . self::u32($itemDataOffset)
            . $regionList
            . ($nullData ? '' : $itemData);
    }

    private static function regionList(int $axisCount, int $regionCount = 2): string
    {
        return self::u16($axisCount)
            . self::u16($regionCount)
            . self::f2dot14(0.0) . self::f2dot14(1.0) . self::f2dot14(1.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0) . self::f2dot14(0.0);
    }

    /**
     * @param list<int> $regionIndexes
     */
    private static function itemData(int $wordDeltaCount, array $regionIndexes): string
    {
        $deltaData = 1 === $wordDeltaCount
            ? self::i16(100) . self::i8(-120) . self::i16(5) . self::i8(-10)
            : self::i16(100) . self::i16(-120) . self::i16(5) . self::i16(-10);

        return self::u16(2)
            . self::u16($wordDeltaCount)
            . self::u16(2)
            . self::u16($regionIndexes[0])
            . self::u16($regionIndexes[1])
            . $deltaData;
    }

    /**
     * @param list<int> $regionIndexes
     */
    private static function itemDataLongWords(int $wordDeltaCount, array $regionIndexes): string
    {
        $deltaData = 1 === $wordDeltaCount
            ? self::i32(300) . self::i16(-300) . self::i32(30) . self::i16(-30)
            : self::i32(300) . self::i32(-300) . self::i32(30) . self::i32(-30);

        return self::u16(2)
            . self::u16(0x8000 | $wordDeltaCount)
            . self::u16(2)
            . self::u16($regionIndexes[0])
            . self::u16($regionIndexes[1])
            . $deltaData;
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function i16(int $value): string
    {
        return self::u16($value);
    }

    private static function i8(int $value): string
    {
        return pack('C', $value & 0xFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value);
    }

    private static function i32(int $value): string
    {
        return self::u32($value);
    }

    private static function f2dot14(float $value): string
    {
        return self::u16((int) round($value * 16384.0));
    }
}
