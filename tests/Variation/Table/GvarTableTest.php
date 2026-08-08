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
use Alto\Font\Variation\Table\GvarTable;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GvarTable::class)]
final class GvarTableTest extends TestCase
{
    public function testItParsesAllPointTupleDeltas(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::allPointGlyphData()]), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 3, new NormalizedCoordinates(['wght' => 0.5, 'wdth' => 0.0]));

        self::assertSame([5.0, 10.0, 15.0], $deltas->x);
        self::assertSame([0.0, 0.0, 0.0], $deltas->y);
    }

    public function testItParsesPrivatePointNumberTupleDeltas(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::sparseGlyphData()]), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 5, new NormalizedCoordinates(['wght' => 0.0, 'wdth' => -1.0]));

        self::assertSame([0.0, 20.0, 0.0, -20.0, 0.0], $deltas->x);
        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0], $deltas->y);
    }

    public function testItParsesSharedTupleDeltas(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvarWithSharedTuple(self::sharedTupleGlyphData()), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 3, new NormalizedCoordinates(['wght' => 0.5, 'wdth' => 0.0]));

        self::assertSame([5.0, 10.0, 15.0], $deltas->x);
        self::assertSame([0.0, 0.0, 0.0], $deltas->y);
    }

    public function testItParsesSharedPointNumbers(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::sharedPointNumbersGlyphData()]), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 5, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));

        self::assertSame([0.0, 20.0, 0.0, -20.0, 0.0], $deltas->x);
        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0], $deltas->y);
    }

    public function testItParsesEmptySharedPointNumbersAsAllPoints(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::emptySharedPointNumbersGlyphData()]), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 2, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));

        self::assertSame([7.0, 9.0], $deltas->x);
        self::assertSame([0.0, 0.0], $deltas->y);
    }

    public function testItParsesWidePointNumberCounts(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::widePointNumberCountGlyphData()]), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 2, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));

        self::assertSame([11.0, 0.0], $deltas->x);
        self::assertSame([0.0, 0.0], $deltas->y);
    }

    public function testItParsesIntermediateRegions(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::intermediateGlyphData()]), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 3, new NormalizedCoordinates(['wght' => 0.25, 'wdth' => 0.0]));

        self::assertSame([5.0, 10.0, 15.0], $deltas->x);
        self::assertSame([0.0, 0.0, 0.0], $deltas->y);
    }

    public function testItParsesLongOffsetsAndWordDeltas(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::wordDeltaGlyphData()], longOffsets: true), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 2, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));

        self::assertSame([300.0, -300.0], $deltas->x);
        self::assertSame([0.0, 0.0], $deltas->y);
    }

    public function testItReturnsNoDeltasForGlyphsWithoutVariationData(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar(['']), 'gvar'), self::variations(), 1);

        $deltas = $table->deltasForGlyph(0, 3, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));

        self::assertSame([0.0, 0.0, 0.0], $deltas->x);
        self::assertSame([], $table->tupleVariationsForGlyph(0, 3));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported gvar table version 2.0.');

        GvarTable::parse(new BinaryReader(self::u16(2) . self::u16(0) . str_repeat("\0", 16), 'gvar'), self::variations(), 1);
    }

    public function testItRejectsAxisCountMismatches(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('gvar axis count 1 does not match fvar axis count 2.');

        GvarTable::parse(new BinaryReader(self::u16(1) . self::u16(0) . self::u16(1) . str_repeat("\0", 14), 'gvar'), self::variations(), 1);
    }

    public function testItRejectsGlyphCountMismatches(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('gvar glyph count 1 does not match maxp glyph count 2.');

        GvarTable::parse(new BinaryReader(self::gvar(['']), 'gvar'), self::variations(), 2);
    }

    public function testItRejectsOutOfBoundsPointNumbers(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::sparseGlyphData()]), 'gvar'), self::variations(), 1);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('gvar point number 3 is outside point count 3.');

        $table->deltasForGlyph(0, 3, new NormalizedCoordinates(['wght' => 0.0, 'wdth' => -1.0]));
    }

    public function testItRejectsOutOfBoundsSharedTupleIndexes(): void
    {
        $table = GvarTable::parse(new BinaryReader(self::gvar([self::outOfBoundsSharedTupleGlyphData()]), 'gvar'), self::variations(), 1);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('gvar shared tuple index 0 is out of bounds.');

        $table->deltasForGlyph(0, 3, new NormalizedCoordinates(['wght' => 1.0, 'wdth' => 0.0]));
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);
    }

    /**
     * @param list<string> $glyphData
     */
    private static function gvar(array $glyphData, bool $longOffsets = false): string
    {
        $glyphCount = \count($glyphData);
        $offsets = [];
        $offset = 0;
        $data = '';

        foreach ($glyphData as $dataForGlyph) {
            $offsets[] = $offset;
            $padded = self::pad2($dataForGlyph);
            $data .= $padded;
            $offset += \strlen($padded);
        }

        $offsets[] = $offset;
        $offsetData = '';

        foreach ($offsets as $glyphOffset) {
            $offsetData .= $longOffsets ? self::u32($glyphOffset) : self::u16(intdiv($glyphOffset, 2));
        }

        $dataOffset = 20 + \strlen($offsetData);

        return self::u16(1) . self::u16(0) . self::u16(2) . self::u16(0) . self::u32(0)
            . self::u16($glyphCount) . self::u16($longOffsets ? 0x0001 : 0) . self::u32($dataOffset)
            . $offsetData . $data;
    }

    private static function gvarWithSharedTuple(string $glyphData): string
    {
        $glyphData = self::pad2($glyphData);
        $offsetData = self::u16(0) . self::u16(intdiv(\strlen($glyphData), 2));
        $sharedTuples = self::f2dot14(1.0) . self::f2dot14(0.0);
        $sharedTuplesOffset = 20 + \strlen($offsetData);
        $glyphDataOffset = $sharedTuplesOffset + \strlen($sharedTuples);

        return self::u16(1) . self::u16(0) . self::u16(2) . self::u16(1) . self::u32($sharedTuplesOffset)
            . self::u16(1) . self::u16(0) . self::u32($glyphDataOffset)
            . $offsetData . $sharedTuples . $glyphData;
    }

    private static function allPointGlyphData(): string
    {
        return self::u16(1)
            . self::u16(12)
            . self::u16(5)
            . self::u16(0x8000)
            . self::f2dot14(1.0)
            . self::f2dot14(0.0)
            . self::deltaBytes([10, 20, 30])
            . self::zeroDeltas(3);
    }

    private static function sparseGlyphData(): string
    {
        return self::u16(1)
            . self::u16(12)
            . self::u16(10)
            . self::u16(0x8000 | 0x2000)
            . self::f2dot14(0.0)
            . self::f2dot14(-1.0)
            . self::pointNumbers([1, 3])
            . self::deltaBytes([20, -20])
            . self::zeroDeltas(2);
    }

    private static function sharedTupleGlyphData(): string
    {
        return self::u16(1)
            . self::u16(8)
            . self::u16(5)
            . self::u16(0)
            . self::deltaBytes([10, 20, 30])
            . self::zeroDeltas(3);
    }

    private static function sharedPointNumbersGlyphData(): string
    {
        return self::u16(0x8000 | 1)
            . self::u16(12)
            . self::u16(5)
            . self::u16(0x8000)
            . self::f2dot14(1.0)
            . self::f2dot14(0.0)
            . self::pointNumbers([1, 3])
            . self::deltaBytes([20, -20])
            . self::zeroDeltas(2);
    }

    private static function emptySharedPointNumbersGlyphData(): string
    {
        return self::u16(0x8000 | 1)
            . self::u16(12)
            . self::u16(5)
            . self::u16(0x8000)
            . self::f2dot14(1.0)
            . self::f2dot14(0.0)
            . self::u8(0)
            . self::deltaBytes([7, 9])
            . self::zeroDeltas(2);
    }

    private static function widePointNumberCountGlyphData(): string
    {
        return self::u16(1)
            . self::u16(12)
            . self::u16(7)
            . self::u16(0x8000 | 0x2000)
            . self::f2dot14(1.0)
            . self::f2dot14(0.0)
            . "\x80\x01"
            . self::u8(0)
            . self::u8(0)
            . self::deltaBytes([11])
            . self::zeroDeltas(1);
    }

    private static function intermediateGlyphData(): string
    {
        return self::u16(1)
            . self::u16(20)
            . self::u16(5)
            . self::u16(0x8000 | 0x4000)
            . self::f2dot14(0.5)
            . self::f2dot14(0.0)
            . self::f2dot14(0.0)
            . self::f2dot14(0.0)
            . self::f2dot14(1.0)
            . self::f2dot14(0.0)
            . self::deltaBytes([10, 20, 30])
            . self::zeroDeltas(3);
    }

    private static function wordDeltaGlyphData(): string
    {
        return self::u16(1)
            . self::u16(12)
            . self::u16(6)
            . self::u16(0x8000)
            . self::f2dot14(1.0)
            . self::f2dot14(0.0)
            . self::deltaWords([300, -300])
            . self::zeroDeltas(2);
    }

    private static function outOfBoundsSharedTupleGlyphData(): string
    {
        return self::u16(1)
            . self::u16(8)
            . self::u16(5)
            . self::u16(0)
            . self::deltaBytes([10, 20, 30])
            . self::zeroDeltas(3);
    }

    /**
     * @param list<int> $points
     */
    private static function pointNumbers(array $points): string
    {
        $data = self::u8(\count($points));
        $previous = 0;
        $deltas = [];

        foreach ($points as $point) {
            $deltas[] = $point - $previous;
            $previous = $point;
        }

        return $data . self::u8(\count($deltas) - 1) . implode('', array_map(
            static fn(int $delta): string => self::u8($delta),
            $deltas,
        ));
    }

    /**
     * @param list<int> $deltas
     */
    private static function deltaBytes(array $deltas): string
    {
        return self::u8(\count($deltas) - 1) . implode('', array_map(
            static fn(int $delta): string => pack('c', $delta),
            $deltas,
        ));
    }

    /**
     * @param list<int> $deltas
     */
    private static function deltaWords(array $deltas): string
    {
        return self::u8(0x40 | (\count($deltas) - 1)) . implode('', array_map(
            static fn(int $delta): string => self::u16($delta),
            $deltas,
        ));
    }

    private static function zeroDeltas(int $count): string
    {
        return self::u8(0x80 | ($count - 1));
    }

    private static function pad2(string $data): string
    {
        return $data . str_repeat("\0", \strlen($data) % 2);
    }

    private static function u8(int $value): string
    {
        return pack('C', $value & 0xFF);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
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
