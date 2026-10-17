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

namespace Alto\Font\Tests\OpenType\Layout;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\CoverageTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoverageTable::class)]
final class CoverageTableTest extends TestCase
{
    public function testItParsesBothCoverageFormats(): void
    {
        $formatOne = self::u16(1) . self::u16(3) . self::u16(2) . self::u16(5) . self::u16(9);
        $formatTwo = self::u16(2)
            . self::u16(2)
            . self::u16(2) . self::u16(3) . self::u16(0)
            . self::u16(7) . self::u16(8) . self::u16(2);

        self::assertSame([2, 5, 9], CoverageTable::parse(new BinaryReader("\0\0\0\0" . $formatOne, 'coverage one'), 0, 4));
        self::assertSame([2, 3, 7, 8], CoverageTable::parse(new BinaryReader("\0\0\0\0" . $formatTwo, 'coverage two'), 0, 4));
    }

    public function testItBuildsASortedUniqueCoverage(): void
    {
        $coverage = CoverageTable::build([9, 2, 9, 5]);

        self::assertSame([2, 5, 9], CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'built coverage'), 0, 2));
    }

    public function testItBuildsRangesForConsecutiveGlyphs(): void
    {
        $coverage = CoverageTable::build(range(10, 19));

        self::assertSame(
            self::u16(2) . self::u16(1) . self::u16(10) . self::u16(19) . self::u16(0),
            $coverage,
        );
        self::assertSame(range(10, 19), CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'range coverage'), 0, 2));
    }

    public function testItNumbersCoverageIndexesAcrossRanges(): void
    {
        $coverage = CoverageTable::build([...range(2, 5), ...range(20, 29), 40, 41]);

        self::assertSame(
            self::u16(2) . self::u16(3)
            . self::u16(2) . self::u16(5) . self::u16(0)
            . self::u16(20) . self::u16(29) . self::u16(4)
            . self::u16(40) . self::u16(41) . self::u16(14),
            $coverage,
        );
        self::assertSame(
            [...range(2, 5), ...range(20, 29), 40, 41],
            CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'ranged coverage'), 0, 2),
        );
    }

    public function testItKeepsTheGlyphListForSparseGlyphs(): void
    {
        $coverage = CoverageTable::build([1, 3, 5, 7]);

        self::assertSame(self::u16(1) . self::u16(4) . self::u16(1) . self::u16(3) . self::u16(5) . self::u16(7), $coverage);
    }

    public function testItKeepsTheGlyphListWhenBothEncodingsHaveTheSameSize(): void
    {
        $coverage = CoverageTable::build([1, 2, 3, 10, 11, 12]);

        self::assertSame(self::u16(1), substr($coverage, 0, 2));
        self::assertSame(16, \strlen($coverage));
        self::assertSame([1, 2, 3, 10, 11, 12], CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'tie coverage'), 0, 2));
    }

    public function testItBuildsAnEmptyCoverage(): void
    {
        $coverage = CoverageTable::build([]);

        self::assertSame(self::u16(1) . self::u16(0), $coverage);
        self::assertSame([], CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'empty coverage'), 0, 2));
    }

    public function testItRejectsAnUnorderedFormatOneCoverage(): void
    {
        $coverage = self::u16(1) . self::u16(2) . self::u16(5) . self::u16(5);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('strictly increasing');

        CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'invalid coverage'), 0, 2);
    }

    public function testItRejectsNullOffsets(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('offset must not be NULL');

        CoverageTable::parse(new BinaryReader('', 'null coverage'), 0, 0);
    }

    public function testItRejectsCoverageIndexesThatReorderGlyphRanges(): void
    {
        $coverage = self::u16(2) . self::u16(2)
            . self::u16(2) . self::u16(2) . self::u16(1)
            . self::u16(5) . self::u16(5) . self::u16(0);

        $this->expectException(InvalidFontException::class);

        CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'reordered indexes'), 0, 2);
    }

    public function testItRejectsUnsupportedFormats(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('format 3 is not supported');

        CoverageTable::parse(new BinaryReader("\0\0" . self::u16(3), 'unsupported coverage'), 0, 2);
    }

    public function testItRejectsOverlappingFormatTwoRanges(): void
    {
        $coverage = self::u16(2)
            . self::u16(2)
            . self::u16(2) . self::u16(4) . self::u16(0)
            . self::u16(4) . self::u16(5) . self::u16(3);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ranges are invalid or overlap');

        CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'overlapping coverage'), 0, 2);
    }

    public function testItRejectsOverlappingCoverageIndexes(): void
    {
        $coverage = self::u16(2)
            . self::u16(2)
            . self::u16(2) . self::u16(3) . self::u16(0)
            . self::u16(5) . self::u16(5) . self::u16(1);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('indexes overlap');

        CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'overlapping indexes'), 0, 2);
    }

    public function testItRejectsNonContiguousCoverageIndexes(): void
    {
        $coverage = self::u16(2)
            . self::u16(1)
            . self::u16(2) . self::u16(3) . self::u16(1);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('indexes are not contiguous');

        CoverageTable::parse(new BinaryReader("\0\0" . $coverage, 'non-contiguous indexes'), 0, 2);
    }

    public function testItRejectsOutOfRangeGlyphsWhenBuilding(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('glyph ID 65536 is invalid');

        CoverageTable::build([0x10000]);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
