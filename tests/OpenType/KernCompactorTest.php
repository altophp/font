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
use Alto\Font\OpenType\KernCompactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KernCompactor::class)]
final class KernCompactorTest extends TestCase
{
    public function testItRemapsAndFiltersFormatZeroPairs(): void
    {
        $kern = self::kern([
            self::subtable([
                [1, 4, -80],
                [1, 7, 10],
                [2, 4, -40],
                [4, 5, 30],
                [4, 7, -20],
            ], coverage: 0x0009),
        ]);
        $compacted = KernCompactor::compact($kern, GlyphIdMap::fromRetained(8, [1 => true, 4 => true, 7 => true]));
        $reader = new BinaryReader($compacted, 'compacted kern');

        self::assertSame(0, $reader->uint16(0));
        self::assertSame(1, $reader->uint16(2));
        self::assertSame(32, $reader->uint16(6));
        self::assertSame(0x0009, $reader->uint16(8));
        self::assertSame(3, $reader->uint16(10));
        self::assertSame(12, $reader->uint16(12));
        self::assertSame(1, $reader->uint16(14));
        self::assertSame(6, $reader->uint16(16));
        self::assertSame([1, 2, -80], self::pair($reader, 18));
        self::assertSame([1, 3, 10], self::pair($reader, 24));
        self::assertSame([2, 3, -20], self::pair($reader, 30));
        self::assertSame(36, $reader->length());
    }

    public function testItPreservesMultipleSubtablesAndEmptyResults(): void
    {
        $kern = self::kern([
            self::subtable([[1, 2, -10]], coverage: 0x0001),
            self::subtable([[3, 4, 25]], coverage: 0x0006),
        ]);
        $compacted = KernCompactor::compact($kern, GlyphIdMap::fromRetained(5, [1 => true, 2 => true]));
        $reader = new BinaryReader($compacted, 'compacted kern');

        self::assertSame(2, $reader->uint16(2));
        self::assertSame(20, $reader->uint16(6));
        self::assertSame([1, 2, -10], self::pair($reader, 18));
        self::assertSame(14, $reader->uint16(26));
        self::assertSame(0x0006, $reader->uint16(28));
        self::assertSame(0, $reader->uint16(30));
        self::assertSame(0, $reader->uint16(32));
        self::assertSame(0, $reader->uint16(34));
        self::assertSame(0, $reader->uint16(36));
        self::assertSame(38, $reader->length());
    }

    public function testItAcceptsNullSubtablePadding(): void
    {
        $subtable = self::subtable([[1, 2, -10]]) . "\0\0";
        $subtable = substr_replace($subtable, self::u16(22), 2, 2);

        $compacted = KernCompactor::compact(self::kern([$subtable]), self::mapping());

        self::assertSame(self::kern([self::subtable([[1, 2, -10]])]), $compacted);
    }

    public function testItRejectsNonNullSubtablePadding(): void
    {
        $subtable = self::subtable([[1, 2, -10]]) . "\0\1";
        $subtable = substr_replace($subtable, self::u16(22), 2, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('padding must contain only NULL bytes');

        KernCompactor::compact(self::kern([$subtable]), self::mapping());
    }

    public function testItRejectsUnsupportedTableVersions(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('OpenType version 0 only');

        KernCompactor::compact(self::u16(1) . self::u16(1) . self::subtable([]), self::mapping());
    }

    public function testItRejectsEmptyTables(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('at least one subtable');

        KernCompactor::compact(self::u16(0) . self::u16(0), self::mapping());
    }

    public function testItRejectsUnsupportedSubtableVersions(): void
    {
        $subtable = substr_replace(self::subtable([]), self::u16(1), 0, 2);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('subtable version 0 only');

        KernCompactor::compact(self::kern([$subtable]), self::mapping());
    }

    public function testItRejectsUnsupportedSubtableFormats(): void
    {
        $subtable = substr_replace(self::subtable([]), self::u16(0x0201), 4, 2);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('format 0 only');

        KernCompactor::compact(self::kern([$subtable]), self::mapping());
    }

    public function testItRejectsReservedCoverageFlags(): void
    {
        $subtable = substr_replace(self::subtable([]), self::u16(0x0011), 4, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('coverage contains reserved flags');

        KernCompactor::compact(self::kern([$subtable]), self::mapping());
    }

    #[DataProvider('invalidLengthTables')]
    public function testItRejectsInvalidSubtableLengths(string $kern, string $message): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        KernCompactor::compact($kern, self::mapping());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLengthTables(): iterable
    {
        yield 'short header' => [self::u16(0) . self::u16(1) . self::u16(0) . self::u16(12) . str_repeat("\0", 8), 'length is invalid'];
        yield 'outside table' => [self::u16(0) . self::u16(1) . self::u16(0) . self::u16(20) . str_repeat("\0", 10), 'read out of bounds'];

        $mismatched = substr_replace(self::subtable([]), self::u16(1), 6, 2);
        yield 'pair count mismatch' => [self::kern([$mismatched]), 'length does not match its pair count'];

        yield 'trailing bytes' => [self::kern([self::subtable([])]) . "\0\0", 'outside its declared subtables'];
    }

    public function testItRejectsInvalidSearchParameters(): void
    {
        $subtable = substr_replace(self::subtable([[1, 2, -10]]), self::u16(12), 8, 2);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('search parameters are invalid');

        KernCompactor::compact(self::kern([$subtable]), self::mapping());
    }

    public function testItRejectsGlyphIdsOutsideTheSourceFont(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('references a glyph outside the source font');

        KernCompactor::compact(self::kern([self::subtable([[1, 8, -10]])]), self::mapping());
    }

    /**
     * @param list<array{int, int, int}> $pairs
     */
    #[DataProvider('unorderedPairs')]
    public function testItRejectsPairsThatAreNotStrictlyIncreasing(array $pairs): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('pairs must be strictly increasing');

        KernCompactor::compact(self::kern([self::subtable($pairs)]), self::mapping());
    }

    /**
     * @return iterable<string, array{list<array{int, int, int}>}>
     */
    public static function unorderedPairs(): iterable
    {
        yield 'descending' => [[[2, 1, -10], [1, 4, -20]]];
        yield 'duplicate' => [[[1, 4, -10], [1, 4, -20]]];
    }

    private static function mapping(): GlyphIdMap
    {
        return GlyphIdMap::fromRetained(8, [1 => true, 2 => true, 4 => true, 7 => true]);
    }

    /**
     * @param list<string> $subtables
     */
    private static function kern(array $subtables): string
    {
        return self::u16(0) . self::u16(\count($subtables)) . implode('', $subtables);
    }

    /**
     * @param list<array{int, int, int}> $pairs
     */
    private static function subtable(array $pairs, int $coverage = 0x0001): string
    {
        $pairCount = \count($pairs);
        [$searchRange, $entrySelector, $rangeShift] = self::searchParameters($pairCount);
        $data = '';

        foreach ($pairs as [$left, $right, $value]) {
            $data .= self::u16($left) . self::u16($right) . self::u16($value);
        }

        return self::u16(0)
            . self::u16(14 + $pairCount * 6)
            . self::u16($coverage)
            . self::u16($pairCount)
            . self::u16($searchRange)
            . self::u16($entrySelector)
            . self::u16($rangeShift)
            . $data;
    }

    /**
     * @return array{int, int, int}
     */
    private static function pair(BinaryReader $reader, int $offset): array
    {
        return [$reader->uint16($offset), $reader->uint16($offset + 2), $reader->int16($offset + 4)];
    }

    /**
     * @return array{int, int, int}
     */
    private static function searchParameters(int $pairCount): array
    {
        if (0 === $pairCount) {
            return [0, 0, 0];
        }

        $maximumPowerOfTwo = 1;
        $entrySelector = 0;

        while ($maximumPowerOfTwo * 2 <= $pairCount) {
            $maximumPowerOfTwo *= 2;
            ++$entrySelector;
        }

        $searchRange = $maximumPowerOfTwo * 6;

        return [$searchRange, $entrySelector, $pairCount * 6 - $searchRange];
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
