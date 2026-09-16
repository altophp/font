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
use Alto\Font\OpenType\GposCompactor;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GposCompactor::class)]
final class GposCompactorTest extends TestCase
{
    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('malformedLookupProvider')]
    public function testItRejectsMalformedLookupStructures(string $gpos, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        GposCompactor::compact($gpos, GlyphIdMap::fromRetained(3, [2 => true]));
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>, string}>
     */
    public static function malformedLookupProvider(): iterable
    {
        $header = self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(12)
            . self::u16(14)
            . self::u16(0)
            . self::u16(0);

        yield 'NULL lookup offset' => [
            $header . self::u16(1) . self::u16(0),
            InvalidFontException::class,
            'lookup 0 offset must not be NULL',
        ];
        yield 'empty lookup' => [
            self::gposWithLookup(self::u16(1) . self::u16(0) . self::u16(0)),
            InvalidFontException::class,
            'must contain at least one subtable',
        ];
        yield 'NULL subtable offset' => [
            self::gposWithLookup(self::u16(1) . self::u16(0) . self::u16(1) . self::u16(0)),
            InvalidFontException::class,
            'subtable offset must not be NULL',
        ];
        yield 'NULL extension offset' => [
            self::gposWithLookup(self::u16(9) . self::u16(0) . self::u16(1) . self::u16(0)),
            InvalidFontException::class,
            'extension offset must not be NULL',
        ];
        yield 'extension format' => [
            self::gpos(9, self::u16(2) . self::u16(1) . self::u32(8)),
            UnsupportedFontException::class,
            'extension format is not supported',
        ];
        yield 'recursive extension type' => [
            self::gpos(9, self::u16(1) . self::u16(9) . self::u32(8)),
            UnsupportedFontException::class,
            'extension types are invalid or inconsistent',
        ];
        yield 'short extension offset' => [
            self::gpos(9, self::u16(1) . self::u16(1) . self::u32(4)),
            InvalidFontException::class,
            'extension offset is invalid',
        ];
        yield 'unknown lookup type' => [
            self::gpos(10, self::u16(1)),
            UnsupportedFontException::class,
            'type 10 is not supported yet',
        ];
    }

    #[DataProvider('overlappingLookupHeaderProvider')]
    public function testItRejectsSubtablesOverlappingLookupHeaders(int $type, bool $markFiltering): void
    {
        // A format-1 subtable can otherwise masquerade as markFilteringSet.
        $single = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([]);
        $subtable = 9 === $type ? self::u16(1) . self::u16(1) . self::u32(8) . $single : $single;
        $lookup = self::u16($type) . self::u16($markFiltering ? 0x10 : 0)
            . self::u16(1) . self::u16($markFiltering ? 8 : 6) . $subtable;
        $this->expectException(InvalidFontException::class);
        GposCompactor::compact(self::gposWithLookup($lookup), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function overlappingLookupHeaderProvider(): iterable
    {
        yield 'ordinary subtable over offsets' => [1, false];
        yield 'extension subtable over offsets' => [9, false];
        yield 'ordinary subtable over mark filtering set' => [1, true];
        yield 'extension subtable over mark filtering set' => [9, true];
    }

    #[DataProvider('unsupportedSubtableFormatProvider')]
    public function testItRejectsUnsupportedSubtableFormats(int $lookupType, string $message): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage($message);

        GposCompactor::compact(
            self::gpos($lookupType, self::u16(0xFFFF)),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unsupportedSubtableFormatProvider(): iterable
    {
        yield 'single adjustment' => [1, 'type 1 format 65535 is not supported'];
        yield 'pair adjustment' => [2, 'type 2 format 65535 is not supported'];
        yield 'cursive attachment' => [3, 'type 3 requires format 1'];
        yield 'mark to base' => [4, 'type 4 requires format 1'];
        yield 'mark to ligature' => [5, 'type 5 requires format 1'];
        yield 'mark to mark' => [6, 'type 6 requires format 1'];
        yield 'contextual positioning' => [7, 'type 7 uses an unsupported format'];
        yield 'chained contextual positioning' => [8, 'type 8 uses an unsupported format'];
    }

    #[DataProvider('malformedSubtableProvider')]
    public function testItRejectsMalformedSubtableStructures(int $lookupType, string $subtable, string $message): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        GposCompactor::compact(
            self::gpos($lookupType, $subtable),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function malformedSubtableProvider(): iterable
    {
        $coverage = CoverageTable::build([2]);
        $emptyCoverage = CoverageTable::build([]);
        $classes = ClassDefinitionTable::build([2 => 1]);
        $contextHeaderLength = 10;
        $chainedHeaderLength = 14;

        yield 'cursive coverage count' => [
            3,
            self::u16(1) . self::u16(6) . self::u16(0) . $coverage,
            'cursive record count does not match coverage',
        ];
        yield 'empty contextual coverage sequence' => [
            7,
            self::u16(3) . self::u16(0) . self::u16(0),
            'contextual glyph count must not be zero',
        ];
        yield 'contextual starting class outside rule sets' => [
            7,
            self::u16(2)
                . self::u16($contextHeaderLength)
                . self::u16($contextHeaderLength + \strlen($coverage))
                . self::u16(1)
                . self::u16(0)
                . $coverage
                . $classes,
            'contextual class is outside its rule-set array',
        ];
        yield 'chained coverage count' => [
            8,
            self::u16(1) . self::u16(6) . self::u16(0) . $coverage,
            'chained rule-set count does not match coverage',
        ];
        yield 'chained starting class outside rule sets' => [
            8,
            self::u16(2)
                . self::u16($chainedHeaderLength)
                . self::u16(0)
                . self::u16($chainedHeaderLength + \strlen($coverage))
                . self::u16(0)
                . self::u16(1)
                . self::u16(0)
                . $coverage
                . $classes,
            'chained input class is outside its rule-set array',
        ];

        foreach ([
            'mark to base' => [4, 'mark/base array counts do not match coverage'],
            'mark to ligature' => [5, 'mark/ligature array counts do not match coverage'],
            'mark to mark' => [6, 'mark array counts do not match coverage'],
        ] as $name => [$lookupType, $message]) {
            $secondCoverageOffset = 12 + \strlen($coverage);
            $firstArrayOffset = $secondCoverageOffset + \strlen($emptyCoverage);
            $secondArrayOffset = $firstArrayOffset + 2;

            yield $name . ' coverage count' => [
                $lookupType,
                self::u16(1)
                    . self::u16(12)
                    . self::u16($secondCoverageOffset)
                    . self::u16(1)
                    . self::u16($firstArrayOffset)
                    . self::u16($secondArrayOffset)
                    . $coverage
                    . $emptyCoverage
                    . self::u16(0)
                    . self::u16(0),
                $message,
            ];
        }
    }

    public function testItUsesExtensionLookupsWhenTheLookupListOffsetsOverflow(): void
    {
        $glyphCount = 16384;
        $glyphs = range(0, $glyphCount - 1);
        $coverage = CoverageTable::build($glyphs);
        $largeSingle = self::u16(2)
            . self::u16(8 + $glyphCount * 2)
            . self::u16(0x0004)
            . self::u16($glyphCount)
            . str_repeat("\0\0", $glyphCount)
            . $coverage;
        $smallSingle = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([0]);
        $retained = array_fill_keys($glyphs, true);
        $output = GposCompactor::compact(
            self::layoutWithTwoExtensionLookups(9, 1, $largeSingle, $smallSingle),
            GlyphIdMap::fromRetained($glyphCount, $retained),
        );
        $reader = new BinaryReader($output, 'large compacted GPOS');
        $lookupList = $reader->uint16(8);
        $firstLookup = $lookupList + $reader->uint16($lookupList + 2);
        $secondLookup = $lookupList + $reader->uint16($lookupList + 4);
        $secondWrapper = $secondLookup + $reader->uint16($secondLookup + 6);

        self::assertSame(2, $reader->uint16($lookupList));
        self::assertSame([9, 9], [$reader->uint16($firstLookup), $reader->uint16($secondLookup)]);
        self::assertSame(1, $reader->uint16($secondWrapper + 2));
        self::assertGreaterThan(0xFFFF, $reader->uint32($secondWrapper + 4));
    }

    public function testItUsesAnExtensionLookupWhenASubtableOffsetOverflows(): void
    {
        $glyphCount = 16384;
        $glyphs = range(0, $glyphCount - 1);
        $compactCoverage = self::u16(2)
            . self::u16(1)
            . self::u16(0)
            . self::u16($glyphCount - 1)
            . self::u16(0);
        $largeSingle = self::u16(2)
            . self::u16(8 + $glyphCount * 2)
            . self::u16(0x0004)
            . self::u16($glyphCount)
            . str_repeat("\0\0", $glyphCount)
            . $compactCoverage;
        $smallSingle = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([0]);
        $lookup = self::lookupWithSubtables(1, [$largeSingle, $smallSingle]);
        $output = GposCompactor::compact(
            self::gposWithLookup($lookup),
            GlyphIdMap::fromRetained($glyphCount, array_fill_keys($glyphs, true)),
        );
        $reader = new BinaryReader($output, 'large compacted GPOS lookup');
        $lookupList = $reader->uint16(8);
        $newLookup = $lookupList + $reader->uint16($lookupList + 2);
        $secondWrapper = $newLookup + $reader->uint16($newLookup + 8);

        self::assertSame([9, 2], [$reader->uint16($newLookup), $reader->uint16($newLookup + 4)]);
        self::assertSame(1, $reader->uint16($secondWrapper + 2));
        self::assertGreaterThan(0xFFFF, $reader->uint32($secondWrapper + 4));
    }

    public function testItCanonicallyReordersTopLevelSections(): void
    {
        $single = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([2]);
        $lookupList = self::u16(1) . self::u16(4) . self::lookup(1, $single);
        $scriptList = self::u16(0);
        $featureList = self::u16(0);
        $source = self::u16(1)
            . self::u16(0)
            . self::u16(10 + \strlen($lookupList))
            . self::u16(12 + \strlen($lookupList))
            . self::u16(10)
            . $lookupList
            . $scriptList
            . $featureList;
        $output = GposCompactor::compact($source, GlyphIdMap::fromRetained(3, [2 => true]));
        [$reader, $offset] = self::firstSubtable($output);

        self::assertSame([10, 12, 14], [
            $reader->uint16(4),
            $reader->uint16(6),
            $reader->uint16(8),
        ]);
        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
    }

    public function testItPreservesVersionOnePointOneFeatureVariations(): void
    {
        $single = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([2]);
        $scriptList = self::u16(0);
        $featureList = self::u16(0);
        $lookupList = self::u16(1) . self::u16(4) . self::lookup(1, $single);
        $featureVariations = self::u16(1) . self::u16(0) . self::u32(0);
        $featureVariationsOffset = 14 + \strlen($scriptList) + \strlen($featureList) + \strlen($lookupList);
        $source = self::u16(1)
            . self::u16(1)
            . self::u16(14)
            . self::u16(16)
            . self::u16(18)
            . self::u32($featureVariationsOffset)
            . $scriptList
            . $featureList
            . $lookupList
            . $featureVariations;
        $output = GposCompactor::compact($source, GlyphIdMap::fromRetained(3, [2 => true]));
        $reader = new BinaryReader($output, 'compacted GPOS 1.1');
        $newFeatureVariationsOffset = $reader->uint32(10);

        self::assertSame([1, 1], [$reader->uint16(0), $reader->uint16(2)]);
        self::assertGreaterThan(18, $newFeatureVariationsOffset);
        self::assertSame($featureVariations, $reader->string($newFeatureVariationsOffset, 8));
    }

    public function testItCompactsSinglePositioningFormatOneWithADeviceTable(): void
    {
        $coverage = CoverageTable::build([2]);
        $device = self::u16(10) . self::u16(11) . self::u16(1) . self::u16(0x4000);
        $single = self::u16(1)
            . self::u16(10)
            . self::u16(0x0044)
            . self::i16(-20)
            . self::u16(10 + \strlen($coverage))
            . $coverage
            . $device;
        $mapping = GlyphIdMap::fromRetained(3, [2 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(1, $single), $mapping));

        self::assertSame(1, $reader->uint16($offset));
        self::assertSame(-20, $reader->int16($offset + 6));
        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));

        $newDevice = $offset + $reader->uint16($offset + 8);
        self::assertSame(10, $reader->uint16($newDevice));
        self::assertSame(11, $reader->uint16($newDevice + 2));
        self::assertSame(1, $reader->uint16($newDevice + 4));
        self::assertSame(0x4000, $reader->uint16($newDevice + 6));
    }

    public function testItFiltersSinglePositioningFormatTwoValuesWithTheirCoverage(): void
    {
        $coverage = CoverageTable::build([2, 4]);
        $single = self::u16(2)
            . self::u16(12)
            . self::u16(0x0004)
            . self::u16(2)
            . self::i16(-20)
            . self::i16(-40)
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(5, [4 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(1, $single), $mapping));

        self::assertSame(2, $reader->uint16($offset));
        self::assertSame(1, $reader->uint16($offset + 6));
        self::assertSame(-40, $reader->int16($offset + 8));
        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
    }

    public function testItCompactsPairPositioningFormatOneThroughAnExtensionLookup(): void
    {
        $pairSetOne = self::u16(2)
            . self::u16(5) . self::i16(-20)
            . self::u16(6) . self::i16(-30);
        $pairSetTwo = self::u16(1) . self::u16(8) . self::i16(-40);
        $coverage = CoverageTable::build([2, 4]);
        $pair = self::u16(1)
            . self::u16(14 + \strlen($pairSetOne) + \strlen($pairSetTwo))
            . self::u16(0x0004)
            . self::u16(0)
            . self::u16(2)
            . self::u16(14)
            . self::u16(14 + \strlen($pairSetOne))
            . $pairSetOne
            . $pairSetTwo
            . $coverage;
        $extension = self::u16(1) . self::u16(2) . self::u32(8) . $pair;
        $mapping = GlyphIdMap::fromRetained(10, [2 => true, 5 => true, 8 => true]);
        [$reader, $offset, $lookupType] = self::firstSubtable(GposCompactor::compact(self::gpos(9, $extension), $mapping));

        self::assertSame(9, $lookupType);
        self::assertSame(1, $reader->uint16($offset));
        self::assertSame(2, $reader->uint16($offset + 2));
        $pairOffset = $offset + $reader->uint32($offset + 4);
        self::assertSame([1], CoverageTable::parse($reader, $pairOffset, $reader->uint16($pairOffset + 2)));
        self::assertSame(1, $reader->uint16($pairOffset + 8));
        $pairSet = $pairOffset + $reader->uint16($pairOffset + 10);
        self::assertSame(1, $reader->uint16($pairSet));
        self::assertSame(2, $reader->uint16($pairSet + 2));
        self::assertSame(-20, $reader->int16($pairSet + 4));
    }

    public function testItRemapsPairPositioningFormatTwoClasses(): void
    {
        $coverage = CoverageTable::build([2, 4]);
        $classOne = ClassDefinitionTable::build([2 => 1, 4 => 2]);
        $classTwo = ClassDefinitionTable::build([5 => 1, 8 => 2]);
        $matrix = implode('', array_map(self::i16(...), [0, -10, -20, -30, -40, -50, -60, -70, -80]));
        $coverageOffset = 16 + \strlen($matrix);
        $classOneOffset = $coverageOffset + \strlen($coverage);
        $classTwoOffset = $classOneOffset + \strlen($classOne);
        $pair = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0x0004)
            . self::u16(0)
            . self::u16($classOneOffset)
            . self::u16($classTwoOffset)
            . self::u16(3)
            . self::u16(3)
            . $matrix
            . $coverage
            . $classOne
            . $classTwo;
        $mapping = GlyphIdMap::fromRetained(10, [2 => true, 5 => true, 8 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(2, $pair), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame([1 => 1], ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)));
        self::assertSame(
            [2 => 1, 3 => 2],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 10)),
        );
        self::assertSame([2, 3], [$reader->uint16($offset + 12), $reader->uint16($offset + 14)]);
        self::assertSame(
            [0, 0, 0, -30, -40, -50],
            array_map(fn(int $index): int => $reader->int16($offset + 16 + $index * 2), range(0, 5)),
        );
    }

    public function testItSplitsPairClassRowsWhenDenseClassDefinitionsOverflow(): void
    {
        $glyphCount = 12001;
        $coverage = CoverageTable::build(range(1, 12000));
        $classOne = self::u16(1) . self::u16(1) . self::u16(12000);

        for ($glyphId = 1; $glyphId <= 12000; ++$glyphId) {
            $classOne .= self::u16(1 + $glyphId % 2);
        }

        $classTwo = ClassDefinitionTable::build([]);
        $matrix = self::i16(0) . self::i16(-10) . self::i16(-20);
        $coverageOffset = 16 + \strlen($matrix);
        $classTwoOffset = $coverageOffset + \strlen($coverage);
        $classOneOffset = $classTwoOffset + \strlen($classTwo);
        $pair = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0x0004)
            . self::u16(0)
            . self::u16($classOneOffset)
            . self::u16($classTwoOffset)
            . self::u16(3)
            . self::u16(1)
            . $matrix
            . $coverage
            . $classTwo
            . $classOne;
        $retained = array_fill_keys(range(0, $glyphCount - 1), true);
        $output = GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained($glyphCount, $retained),
        );
        $reader = new BinaryReader($output, 'split PairPos format 2');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);

        self::assertSame(2, $reader->uint16($lookup));
        self::assertSame(2, $reader->uint16($lookup + 4));

        foreach ([1 => -10, 2 => -20] as $index => $adjustment) {
            $subtable = $lookup + $reader->uint16($lookup + 4 + $index * 2);
            $subtableCoverage = CoverageTable::parse($reader, $subtable, $reader->uint16($subtable + 2));

            self::assertSame(2, $reader->uint16($subtable));
            self::assertCount(6000, $subtableCoverage);
            self::assertSame([2, 1], [$reader->uint16($subtable + 12), $reader->uint16($subtable + 14)]);
            self::assertSame([0, $adjustment], [
                $reader->int16($subtable + 16),
                $reader->int16($subtable + 18),
            ]);
        }
    }

    public function testItPreservesImplicitPairClassesWhileDenselyRemapping(): void
    {
        $coverage = CoverageTable::build([1, 2]);
        $classOne = ClassDefinitionTable::build([2 => 2]);
        $classTwo = ClassDefinitionTable::build([3 => 2]);
        $matrix = implode('', array_map(self::i16(...), [0, -1, -2, -10, -11, -12, -20, -21, -22]));
        $coverageOffset = 16 + \strlen($matrix);
        $classOneOffset = $coverageOffset + \strlen($coverage);
        $classTwoOffset = $classOneOffset + \strlen($classOne);
        $pair = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0x0004)
            . self::u16(0)
            . self::u16($classOneOffset)
            . self::u16($classTwoOffset)
            . self::u16(3)
            . self::u16(3)
            . $matrix
            . $coverage
            . $classOne
            . $classTwo;
        $mapping = GlyphIdMap::fromRetained(5, [1 => true, 2 => true, 3 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(2, $pair), $mapping));

        self::assertSame([1, 2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame([2 => 1], ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)));
        self::assertSame([3 => 1], ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 10)));
        self::assertSame([2, 2], [$reader->uint16($offset + 12), $reader->uint16($offset + 14)]);
        self::assertSame(
            [0, -2, -20, -22],
            array_map(fn(int $index): int => $reader->int16($offset + 16 + $index * 2), range(0, 3)),
        );
    }

    public function testItFallsBackToPairGlyphRecordsWhenAClassRowCannotFit(): void
    {
        $glyphCount = 12001;
        $classTwo = self::u16(1) . self::u16(0) . self::u16($glyphCount);

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $classTwo .= self::u16(1 + $glyphId % 2);
        }

        $variationIndex = self::u16(3) . self::u16(7) . self::u16(0x8000);
        $matrix = self::u16(22) . self::u16(22) . self::u16(22);
        $coverage = CoverageTable::build([0]);
        $classOne = ClassDefinitionTable::build([]);
        $coverageOffset = 16 + \strlen($matrix) + \strlen($variationIndex);
        $classOneOffset = $coverageOffset + \strlen($coverage);
        $classTwoOffset = $classOneOffset + \strlen($classOne);
        $pair = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0x0010)
            . self::u16(0)
            . self::u16($classOneOffset)
            . self::u16($classTwoOffset)
            . self::u16(1)
            . self::u16(3)
            . $matrix
            . $variationIndex
            . $coverage
            . $classOne
            . $classTwo;
        $retained = array_fill_keys(range(0, $glyphCount - 1), true);
        [$reader, $subtable] = self::firstSubtable(GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained($glyphCount, $retained),
        ));

        self::assertSame(1, $reader->uint16($subtable));
        self::assertSame([0], CoverageTable::parse($reader, $subtable, $reader->uint16($subtable + 2)));
        $pairSet = $subtable + $reader->uint16($subtable + 10);
        self::assertSame($glyphCount, $reader->uint16($pairSet));
        self::assertSame([0, $glyphCount - 1], [
            $reader->uint16($pairSet + 2),
            $reader->uint16($pairSet + 2 + ($glyphCount - 1) * 4),
        ]);
        $device = $pairSet + $reader->uint16($pairSet + 4);
        self::assertSame([3, 7, 0x8000], [
            $reader->uint16($device),
            $reader->uint16($device + 2),
            $reader->uint16($device + 4),
        ]);
    }

    public function testItSplitsPairGlyphFallbackRecordsAcrossSubtables(): void
    {
        $glyphCount = 12001;
        $classTwo = self::u16(1) . self::u16(0) . self::u16($glyphCount);

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $classTwo .= self::u16(1 + $glyphId % 2);
        }

        $variationIndex = self::u16(3) . self::u16(7) . self::u16(0x8000);
        $matrix = str_repeat(self::i16(-10) . self::u16(28), 3);
        $coverage = CoverageTable::build([0]);
        $classOne = ClassDefinitionTable::build([]);
        $coverageOffset = 16 + \strlen($matrix) + \strlen($variationIndex);
        $classOneOffset = $coverageOffset + \strlen($coverage);
        $classTwoOffset = $classOneOffset + \strlen($classOne);
        $pair = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0x0011)
            . self::u16(0)
            . self::u16($classOneOffset)
            . self::u16($classTwoOffset)
            . self::u16(1)
            . self::u16(3)
            . $matrix
            . $variationIndex
            . $coverage
            . $classOne
            . $classTwo;
        $output = GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained($glyphCount, array_fill_keys(range(0, $glyphCount - 1), true)),
        );
        $reader = new BinaryReader($output, 'split PairPos format 1 fallback');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);

        self::assertSame(9, $reader->uint16($lookup));
        self::assertSame(2, $reader->uint16($lookup + 4));
        $recordCounts = [];
        $firstSecondGlyphs = [];

        for ($index = 0; $index < 2; ++$index) {
            $extension = $lookup + $reader->uint16($lookup + 6 + $index * 2);
            self::assertSame([1, 2], [$reader->uint16($extension), $reader->uint16($extension + 2)]);
            $subtable = $extension + $reader->uint32($extension + 4);
            self::assertSame(1, $reader->uint16($subtable));
            self::assertSame([0], CoverageTable::parse($reader, $subtable, $reader->uint16($subtable + 2)));
            $pairSet = $subtable + $reader->uint16($subtable + 10);
            $recordCounts[] = $reader->uint16($pairSet);
            $firstSecondGlyphs[] = $reader->uint16($pairSet + 2);
        }

        self::assertSame([10919, 1082], $recordCounts);
        self::assertSame([0, 10919], $firstSecondGlyphs);
    }

    public function testItRelocatesPairVariationIndexesRelativeToThePairSet(): void
    {
        $variationIndex = self::u16(3) . self::u16(7) . self::u16(0x8000);
        $pairSet = self::u16(1)
            . self::u16(5)
            . self::i16(-20)
            . self::u16(8)
            . $variationIndex;
        $coverage = CoverageTable::build([2]);
        $pair = self::u16(1)
            . self::u16(12 + \strlen($pairSet))
            . self::u16(0x0044)
            . self::u16(0)
            . self::u16(1)
            . self::u16(12)
            . $pairSet
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [2 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(2, $pair), $mapping));
        $newPairSet = $offset + $reader->uint16($offset + 10);
        $newVariationIndex = $newPairSet + $reader->uint16($newPairSet + 6);

        self::assertSame(-20, $reader->int16($newPairSet + 4));
        self::assertSame(3, $reader->uint16($newVariationIndex));
        self::assertSame(7, $reader->uint16($newVariationIndex + 2));
        self::assertSame(0x8000, $reader->uint16($newVariationIndex + 4));
    }

    public function testItSplitsLargePairPositioningFormatOneWithoutChangingPairOrder(): void
    {
        $pairSetOne = self::pairSetWithSecondGlyphs(20000);
        $pairSetTwo = self::pairSetWithSecondGlyphs(20000);
        $coverage = CoverageTable::build([20001, 20002]);
        $pairSetOneOffset = 14 + \strlen($coverage);
        $pairSetTwoOffset = $pairSetOneOffset + \strlen($pairSetOne);
        $pair = self::u16(1)
            . self::u16(14)
            . self::u16(0)
            . self::u16(0)
            . self::u16(2)
            . self::u16($pairSetOneOffset)
            . self::u16($pairSetTwoOffset)
            . $coverage
            . $pairSetOne
            . $pairSetTwo;
        $glyphs = range(0, 20002);
        $output = GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained(20003, array_fill_keys($glyphs, true)),
        );
        $reader = new BinaryReader($output, 'split compacted GPOS');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);

        self::assertSame(2, $reader->uint16($lookup + 4));

        foreach ([0 => 20001, 1 => 20002] as $index => $firstGlyph) {
            $subtable = $lookup + $reader->uint16($lookup + 6 + $index * 2);
            self::assertSame([$firstGlyph], CoverageTable::parse(
                $reader,
                $subtable,
                $reader->uint16($subtable + 2),
            ));
            self::assertSame(1, $reader->uint16($subtable + 8));
            $pairSet = $subtable + $reader->uint16($subtable + 10);
            self::assertSame(20000, $reader->uint16($pairSet));
            self::assertSame(1, $reader->uint16($pairSet + 2));
            self::assertSame(20000, $reader->uint16($pairSet + 40000));
        }
    }

    public function testItSplitsAnIndividuallyOversizedPairSet(): void
    {
        $pairSet = self::pairSetWithSecondGlyphs(32762);
        $coverage = CoverageTable::build([32762]);
        $pair = self::u16(1) . self::u16(12) . self::u16(0) . self::u16(0)
            . self::u16(1) . self::u16(12 + \strlen($coverage)) . $coverage . $pairSet;
        $output = GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained(32763, array_fill_keys(range(0, 32762), true)),
        );
        $reader = new BinaryReader($output, 'oversized pair set');
        $subtables = self::pairSubtables($reader);
        self::assertCount(2, $subtables);
        $secondGlyphs = [];

        foreach ($subtables as $subtable) {
            self::assertSame([32762], CoverageTable::parse($reader, $subtable, $reader->uint16($subtable + 2)));
            $set = $subtable + $reader->uint16($subtable + 10);

            for ($index = 0; $index < $reader->uint16($set); ++$index) {
                $secondGlyphs[] = $reader->uint16($set + 2 + $index * 2);
            }
        }

        self::assertSame(range(1, 32762), $secondGlyphs);
    }

    public function testItFiltersAnOversizedPairSetBeforeSplitting(): void
    {
        $pairSet = self::pairSetWithSecondGlyphs(32762);
        $coverage = CoverageTable::build([32762]);
        $pair = self::u16(1) . self::u16(12) . self::u16(0) . self::u16(0)
            . self::u16(1) . self::u16(12 + \strlen($coverage)) . $coverage . $pairSet;
        [$reader, $subtable] = self::firstSubtable(GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained(32763, [2 => true, 32762 => true]),
        ));
        self::assertCount(1, self::pairSubtables($reader));
        self::assertSame([2], CoverageTable::parse($reader, $subtable, $reader->uint16($subtable + 2)));
        $set = $subtable + $reader->uint16($subtable + 10);
        self::assertSame([2, 1, 2], [$reader->uint16($set), $reader->uint16($set + 2), $reader->uint16($set + 4)]);
    }

    public function testItSplitsPairSetsWithBothValuesAndDeviceAndVariationAdjustments(): void
    {
        $count = 6552;
        $device = self::u16(12) . self::u16(12) . self::u16(1) . self::u16(0x4000);
        $variation = self::u16(3) . self::u16(7) . self::u16(0x8000);
        $records = '';

        for ($glyph = 1; $glyph <= $count; ++$glyph) {
            $records .= self::u16($glyph) . self::i16(-20) . self::u16(65522)
                . self::i16(30) . self::u16(65530);
        }

        $coverage = CoverageTable::build([2]);
        $pair = self::u16(1) . self::u16(12) . self::u16(0x44) . self::u16(0x11)
            . self::u16(1) . self::u16(12 + \strlen($coverage)) . $coverage
            . self::u16($count) . $records . $device . $variation;
        $output = GposCompactor::compact(
            self::gpos(2, $pair),
            GlyphIdMap::fromRetained($count + 1, array_fill_keys(range(0, $count), true)),
        );
        $reader = new BinaryReader($output, 'pair set with device and variation');
        $subtables = self::pairSubtables($reader);
        self::assertCount(2, $subtables);
        $nextGlyph = 1;

        foreach ($subtables as $subtable) {
            self::assertSame([2], CoverageTable::parse($reader, $subtable, $reader->uint16($subtable + 2)));
            self::assertSame([0x44, 0x11], [$reader->uint16($subtable + 4), $reader->uint16($subtable + 6)]);
            $set = $subtable + $reader->uint16($subtable + 10);

            for ($index = 0; $index < $reader->uint16($set); ++$index) {
                $record = $set + 2 + $index * 10;
                self::assertSame($nextGlyph++, $reader->uint16($record));
                self::assertSame(-20, $reader->int16($record + 2));
                self::assertSame(30, $reader->int16($record + 6));
                self::assertSame($device, $reader->string($set + $reader->uint16($record + 4), 8));
                self::assertSame($variation, $reader->string($set + $reader->uint16($record + 8), 6));
            }
        }

        self::assertSame($count + 1, $nextGlyph);
    }

    /**
     * @return list<int>
     */
    private static function pairSubtables(BinaryReader $reader): array
    {
        $list = $reader->uint16(8);
        $lookup = $list + $reader->uint16($list + 2);
        $subtables = [];

        for ($index = 0; $index < $reader->uint16($lookup + 4); ++$index) {
            $subtable = $lookup + $reader->uint16($lookup + 6 + $index * 2);

            if (9 === $reader->uint16($lookup)) {
                self::assertSame(2, $reader->uint16($subtable + 2));
                $subtable += $reader->uint32($subtable + 4);
            }

            $subtables[] = $subtable;
        }

        return $subtables;
    }

    public function testItCompactsMarkToBasePositioning(): void
    {
        $markCoverage = CoverageTable::build([5, 7]);
        $baseCoverage = CoverageTable::build([2, 8]);
        $markArray = self::u16(2)
            . self::u16(0) . self::u16(10)
            . self::u16(0) . self::u16(16)
            . self::anchor(100, 200)
            . self::anchor(300, 400);
        $baseArray = self::u16(2)
            . self::u16(6)
            . self::u16(12)
            . self::anchor(500, 600)
            . self::anchor(700, 800);
        $markCoverageOffset = 12;
        $baseCoverageOffset = $markCoverageOffset + \strlen($markCoverage);
        $markArrayOffset = $baseCoverageOffset + \strlen($baseCoverage);
        $baseArrayOffset = $markArrayOffset + \strlen($markArray);
        $subtable = self::u16(1)
            . self::u16($markCoverageOffset)
            . self::u16($baseCoverageOffset)
            . self::u16(1)
            . self::u16($markArrayOffset)
            . self::u16($baseArrayOffset)
            . $markCoverage
            . $baseCoverage
            . $markArray
            . $baseArray;
        $mapping = GlyphIdMap::fromRetained(10, [2 => true, 5 => true, 8 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(4, $subtable), $mapping));

        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame([1, 3], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4)));
        $newMarkArray = $offset + $reader->uint16($offset + 8);
        self::assertSame(1, $reader->uint16($newMarkArray));
        $markAnchor = $newMarkArray + $reader->uint16($newMarkArray + 4);
        self::assertSame(100, $reader->int16($markAnchor + 2));
        self::assertSame(200, $reader->int16($markAnchor + 4));
        $newBaseArray = $offset + $reader->uint16($offset + 10);
        self::assertSame(2, $reader->uint16($newBaseArray));
        $secondBaseAnchor = $newBaseArray + $reader->uint16($newBaseArray + 4);
        self::assertSame(700, $reader->int16($secondBaseAnchor + 2));
        self::assertSame(800, $reader->int16($secondBaseAnchor + 4));
    }

    public function testItCompactsCursivePositioningAndRelocatesAnchorDevices(): void
    {
        $coverage = CoverageTable::build([2, 5]);
        $variationIndex = self::u16(3) . self::u16(7) . self::u16(0x8000);
        $entry = self::u16(3)
            . self::i16(100)
            . self::i16(200)
            . self::u16(10)
            . self::u16(0)
            . $variationIndex;
        $exit = self::anchor(300, 400);
        $subtable = self::u16(1)
            . self::u16(6 + 8 + \strlen($entry) + \strlen($exit))
            . self::u16(2)
            . self::u16(14)
            . self::u16(14 + \strlen($entry))
            . self::u16(0)
            . self::u16(0)
            . $entry
            . $exit
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [2 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(3, $subtable), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(1, $reader->uint16($offset + 4));
        self::assertNotSame(0, $reader->uint16($offset + 8));
        $newEntry = $offset + $reader->uint16($offset + 6);
        self::assertSame(3, $reader->uint16($newEntry));
        $newVariationIndex = $newEntry + $reader->uint16($newEntry + 6);
        self::assertSame(3, $reader->uint16($newVariationIndex));
        self::assertSame(7, $reader->uint16($newVariationIndex + 2));
        self::assertSame(0x8000, $reader->uint16($newVariationIndex + 4));
    }

    public function testItCompactsMarkToLigaturePositioning(): void
    {
        $markCoverage = CoverageTable::build([5, 7]);
        $ligatureCoverage = CoverageTable::build([2, 8]);
        $markArray = self::u16(2)
            . self::u16(0) . self::u16(10)
            . self::u16(0) . self::u16(16)
            . self::anchor(100, 200)
            . self::anchor(300, 400);
        $firstAttach = self::u16(1) . self::u16(4) . self::anchor(500, 600);
        $secondAttach = self::u16(2)
            . self::u16(6)
            . self::u16(12)
            . self::anchor(700, 800)
            . self::anchor(900, 1000);
        $ligatureArray = self::u16(2)
            . self::u16(6)
            . self::u16(6 + \strlen($firstAttach))
            . $firstAttach
            . $secondAttach;
        $markCoverageOffset = 12;
        $ligatureCoverageOffset = $markCoverageOffset + \strlen($markCoverage);
        $markArrayOffset = $ligatureCoverageOffset + \strlen($ligatureCoverage);
        $ligatureArrayOffset = $markArrayOffset + \strlen($markArray);
        $subtable = self::u16(1)
            . self::u16($markCoverageOffset)
            . self::u16($ligatureCoverageOffset)
            . self::u16(1)
            . self::u16($markArrayOffset)
            . self::u16($ligatureArrayOffset)
            . $markCoverage
            . $ligatureCoverage
            . $markArray
            . $ligatureArray;
        $mapping = GlyphIdMap::fromRetained(10, [5 => true, 8 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(5, $subtable), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4)));
        $newLigatureArray = $offset + $reader->uint16($offset + 10);
        self::assertSame(1, $reader->uint16($newLigatureArray));
        $newAttach = $newLigatureArray + $reader->uint16($newLigatureArray + 2);
        self::assertSame(2, $reader->uint16($newAttach));
        $secondAnchor = $newAttach + $reader->uint16($newAttach + 4);
        self::assertSame(900, $reader->int16($secondAnchor + 2));
        self::assertSame(1000, $reader->int16($secondAnchor + 4));
    }

    public function testItCompactsMarkToMarkPositioning(): void
    {
        $markOneCoverage = CoverageTable::build([5, 7]);
        $markTwoCoverage = CoverageTable::build([2, 8]);
        $markOneArray = self::u16(2)
            . self::u16(0) . self::u16(10)
            . self::u16(0) . self::u16(16)
            . self::anchor(100, 200)
            . self::anchor(300, 400);
        $markTwoArray = self::u16(2)
            . self::u16(6)
            . self::u16(12)
            . self::anchor(500, 600)
            . self::anchor(700, 800);
        $markOneCoverageOffset = 12;
        $markTwoCoverageOffset = $markOneCoverageOffset + \strlen($markOneCoverage);
        $markOneArrayOffset = $markTwoCoverageOffset + \strlen($markTwoCoverage);
        $markTwoArrayOffset = $markOneArrayOffset + \strlen($markOneArray);
        $subtable = self::u16(1)
            . self::u16($markOneCoverageOffset)
            . self::u16($markTwoCoverageOffset)
            . self::u16(1)
            . self::u16($markOneArrayOffset)
            . self::u16($markTwoArrayOffset)
            . $markOneCoverage
            . $markTwoCoverage
            . $markOneArray
            . $markTwoArray;
        $mapping = GlyphIdMap::fromRetained(10, [5 => true, 8 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(6, $subtable), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4)));
        $newMarkTwoArray = $offset + $reader->uint16($offset + 10);
        self::assertSame(1, $reader->uint16($newMarkTwoArray));
        $anchor = $newMarkTwoArray + $reader->uint16($newMarkTwoArray + 2);
        self::assertSame(700, $reader->int16($anchor + 2));
        self::assertSame(800, $reader->int16($anchor + 4));
    }

    public function testItRemapsContextFormatOneRules(): void
    {
        $rule = self::u16(3)
            . self::u16(1)
            . self::u16(4)
            . self::u16(5)
            . self::u16(2)
            . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $coverage = CoverageTable::build([2]);
        $subtable = self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [2 => true, 4 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(7, $subtable), $mapping));
        $ruleSet = $offset + $reader->uint16($offset + 6);
        $newRule = $ruleSet + $reader->uint16($ruleSet + 2);

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(2, $reader->uint16($newRule + 4));
        self::assertSame(3, $reader->uint16($newRule + 6));
        self::assertSame(2, $reader->uint16($newRule + 8));
    }

    public function testItRemapsContextFormatTwoClasses(): void
    {
        $rule = self::u16(2) . self::u16(1) . self::u16(2) . self::u16(1) . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $coverage = CoverageTable::build([2, 4]);
        $classes = ClassDefinitionTable::build([2 => 1, 4 => 2, 5 => 5]);
        $setCount = 3;
        $setOffset = 8 + $setCount * 2;
        $coverageOffset = $setOffset + \strlen($set);
        $classOffset = $coverageOffset + \strlen($coverage);
        $subtable = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16($classOffset)
            . self::u16($setCount)
            . self::u16(0)
            . self::u16($setOffset)
            . self::u16(0)
            . $set
            . $coverage
            . $classes;
        $mapping = GlyphIdMap::fromRetained(6, [2 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(7, $subtable), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(
            [1 => 1, 2 => 5],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 4)),
        );
        self::assertSame(0, $reader->uint16($offset + 8));
        self::assertNotSame(0, $reader->uint16($offset + 10));
    }

    public function testItRemapsContextFormatThreeCoverages(): void
    {
        $coverageOne = CoverageTable::build([2, 4]);
        $coverageTwo = CoverageTable::build([5]);
        $headerLength = 6 + 4 + 4;
        $subtable = self::u16(3)
            . self::u16(2)
            . self::u16(1)
            . self::u16($headerLength)
            . self::u16($headerLength + \strlen($coverageOne))
            . self::u16(1)
            . self::u16(0)
            . $coverageOne
            . $coverageTwo;
        $mapping = GlyphIdMap::fromRetained(6, [4 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(7, $subtable), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 6)));
        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 8)));
        self::assertSame(1, $reader->uint16($offset + 10));
    }

    public function testItRemapsChainedContextFormatOneRules(): void
    {
        $rule = self::u16(1) . self::u16(2)
            . self::u16(2) . self::u16(4)
            . self::u16(1) . self::u16(5)
            . self::u16(1) . self::u16(1) . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $coverage = CoverageTable::build([1]);
        $subtable = self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 2 => true, 4 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(8, $subtable), $mapping));
        $ruleSet = $offset + $reader->uint16($offset + 6);
        $newRule = $ruleSet + $reader->uint16($ruleSet + 2);

        self::assertSame(2, $reader->uint16($newRule + 2));
        self::assertSame(3, $reader->uint16($newRule + 6));
        self::assertSame(4, $reader->uint16($newRule + 10));
    }

    public function testItRemapsChainedContextFormatTwoClasses(): void
    {
        $rule = self::u16(1)
            . self::u16(1)
            . self::u16(2)
            . self::u16(2)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(0)
            . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $coverage = CoverageTable::build([2, 4]);
        $backtrackClasses = ClassDefinitionTable::build([1 => 1]);
        $inputClasses = ClassDefinitionTable::build([2 => 1, 4 => 2, 5 => 5]);
        $lookaheadClasses = ClassDefinitionTable::build([6 => 1]);
        $setCount = 3;
        $setOffset = 12 + $setCount * 2;
        $coverageOffset = $setOffset + \strlen($set);
        $backtrackOffset = $coverageOffset + \strlen($coverage);
        $inputOffset = $backtrackOffset + \strlen($backtrackClasses);
        $lookaheadOffset = $inputOffset + \strlen($inputClasses);
        $subtable = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16($backtrackOffset)
            . self::u16($inputOffset)
            . self::u16($lookaheadOffset)
            . self::u16($setCount)
            . self::u16(0)
            . self::u16($setOffset)
            . self::u16(0)
            . $set
            . $coverage
            . $backtrackClasses
            . $inputClasses
            . $lookaheadClasses;
        $mapping = GlyphIdMap::fromRetained(7, [1 => true, 2 => true, 5 => true, 6 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(8, $subtable), $mapping));

        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(
            [2 => 1, 3 => 5],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6)),
        );
        self::assertSame(0, $reader->uint16($offset + 12));
        self::assertNotSame(0, $reader->uint16($offset + 14));
    }

    public function testItRemapsChainedContextFormatThreeCoverages(): void
    {
        $coverages = [
            CoverageTable::build([1]),
            CoverageTable::build([2, 4]),
            CoverageTable::build([5]),
            CoverageTable::build([6]),
        ];
        $headerLength = 2 + 2 + 2 + 2 + 4 + 2 + 2 + 2 + 4;
        $coverageOffset = $headerLength;
        $subtable = self::u16(3)
            . self::u16(1)
            . self::u16($coverageOffset)
            . self::u16(2)
            . self::u16($coverageOffset + \strlen($coverages[0]))
            . self::u16($coverageOffset + \strlen($coverages[0]) + \strlen($coverages[1]))
            . self::u16(1)
            . self::u16($coverageOffset + \strlen($coverages[0]) + \strlen($coverages[1]) + \strlen($coverages[2]))
            . self::u16(1)
            . self::u16(1)
            . self::u16(0)
            . implode('', $coverages);
        $mapping = GlyphIdMap::fromRetained(7, [1 => true, 4 => true, 5 => true, 6 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(8, $subtable), $mapping));

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 4)));
        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 8)));
        self::assertSame([3], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 10)));
        self::assertSame([4], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 14)));
        self::assertSame(1, $reader->uint16($offset + 16));
    }

    public function testItMaterializesNullableChainedContextClassDefinitions(): void
    {
        $coverage = CoverageTable::build([2]);
        $inputClasses = ClassDefinitionTable::build([2 => 0]);
        $setCount = 1;
        $coverageOffset = 12 + $setCount * 2;
        $inputOffset = $coverageOffset + \strlen($coverage);
        $subtable = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0)
            . self::u16($inputOffset)
            . self::u16(0)
            . self::u16($setCount)
            . self::u16(0)
            . $coverage
            . $inputClasses;
        $mapping = GlyphIdMap::fromRetained(3, [2 => true]);
        [$reader, $offset] = self::firstSubtable(GposCompactor::compact(self::gpos(8, $subtable), $mapping));

        $backtrackOffset = $reader->uint16($offset + 4);
        $lookaheadOffset = $reader->uint16($offset + 8);
        self::assertNotSame(0, $backtrackOffset);
        self::assertNotSame(0, $lookaheadOffset);
        self::assertSame([], ClassDefinitionTable::parse($reader, $offset, $backtrackOffset));
        self::assertSame([], ClassDefinitionTable::parse($reader, $offset, $lookaheadOffset));
    }

    public function testItRejectsOutOfRangeContextPositioningSequenceIndexes(): void
    {
        $coverage = CoverageTable::build([2]);
        $headerLength = 6 + 2 + 4;
        $subtable = self::u16(3)
            . self::u16(1)
            . self::u16(1)
            . self::u16($headerLength)
            . self::u16(1)
            . self::u16(0)
            . $coverage;

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('positioning record sequence index is out of range');

        GposCompactor::compact(
            self::gpos(7, $subtable),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    public function testItRejectsOutOfRangeContextPositioningLookupIndexes(): void
    {
        $coverage = CoverageTable::build([2]);
        $headerLength = 6 + 2 + 4;
        $subtable = self::u16(3)
            . self::u16(1)
            . self::u16(1)
            . self::u16($headerLength)
            . self::u16(0)
            . self::u16(1)
            . $coverage;

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('positioning record lookup index is out of range');

        GposCompactor::compact(
            self::gpos(7, $subtable),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    public function testItRejectsLigatureAttachmentWithNoComponents(): void
    {
        $markCoverage = CoverageTable::build([2]);
        $ligatureCoverage = CoverageTable::build([3]);
        $markArray = self::u16(1) . self::u16(0) . self::u16(6) . self::anchor(10, 20);
        $ligatureArray = self::u16(1) . self::u16(4) . self::u16(0);
        $markCoverageOffset = 12;
        $ligatureCoverageOffset = $markCoverageOffset + \strlen($markCoverage);
        $markArrayOffset = $ligatureCoverageOffset + \strlen($ligatureCoverage);
        $ligatureArrayOffset = $markArrayOffset + \strlen($markArray);
        $subtable = self::u16(1)
            . self::u16($markCoverageOffset)
            . self::u16($ligatureCoverageOffset)
            . self::u16(1)
            . self::u16($markArrayOffset)
            . self::u16($ligatureArrayOffset)
            . $markCoverage
            . $ligatureCoverage
            . $markArray
            . $ligatureArray;

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ligature component count must not be zero');

        GposCompactor::compact(
            self::gpos(5, $subtable),
            GlyphIdMap::fromRetained(4, [2 => true, 3 => true]),
        );
    }

    public function testItRejectsSinglePositioningCountsThatDoNotMatchCoverage(): void
    {
        $single = self::u16(2)
            . self::u16(8)
            . self::u16(0)
            . self::u16(0)
            . CoverageTable::build([2]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('single-adjustment count does not match coverage');

        GposCompactor::compact(
            self::gpos(1, $single),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    public function testItRejectsReservedValueFormatBits(): void
    {
        $single = self::u16(1)
            . self::u16(6)
            . self::u16(0x0100)
            . CoverageTable::build([2]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('value format contains reserved bits');

        GposCompactor::compact(
            self::gpos(1, $single),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    /**
     * @return array{BinaryReader, int, int}
     */
    private static function firstSubtable(string $gpos): array
    {
        $reader = new BinaryReader($gpos, 'compacted GPOS');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);

        return [$reader, $lookup + $reader->uint16($lookup + 6), $reader->uint16($lookup)];
    }

    private static function gpos(int $lookupType, string $subtable): string
    {
        $scriptList = self::u16(0);
        $featureList = self::u16(0);
        $lookup = self::lookup($lookupType, $subtable);
        $lookupList = self::u16(1) . self::u16(4) . $lookup;

        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(10 + \strlen($scriptList))
            . self::u16(10 + \strlen($scriptList) + \strlen($featureList))
            . $scriptList
            . $featureList
            . $lookupList;
    }

    private static function lookup(int $lookupType, string $subtable): string
    {
        return self::u16($lookupType)
            . self::u16(0)
            . self::u16(1)
            . self::u16(8)
            . $subtable;
    }

    /**
     * @param non-empty-list<string> $subtables
     */
    private static function lookupWithSubtables(int $type, array $subtables): string
    {
        $header = self::u16($type) . self::u16(0) . self::u16(\count($subtables));
        $data = '';
        $cursor = 6 + \count($subtables) * 2;

        foreach ($subtables as $subtable) {
            $header .= self::u16($cursor);
            $data .= $subtable;
            $cursor += \strlen($subtable);
        }

        return $header . $data;
    }

    private static function gposWithLookup(string $lookup): string
    {
        $lookupList = self::u16(1) . self::u16(4) . $lookup;

        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(12)
            . self::u16(14)
            . self::u16(0)
            . self::u16(0)
            . $lookupList;
    }

    private static function layoutWithTwoExtensionLookups(
        int $extensionType,
        int $actualType,
        string $firstSubtable,
        string $secondSubtable,
    ): string {
        $lookupHeader = self::u16($extensionType)
            . self::u16(0)
            . self::u16(1)
            . self::u16(8);
        $lookupList = self::u16(2)
            . self::u16(6)
            . self::u16(22)
            . $lookupHeader
            . self::u16(1)
            . self::u16($actualType)
            . self::u32(24)
            . $lookupHeader
            . self::u16(1)
            . self::u16($actualType)
            . self::u32(8 + \strlen($firstSubtable))
            . $firstSubtable
            . $secondSubtable;

        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(12)
            . self::u16(14)
            . self::u16(0)
            . self::u16(0)
            . $lookupList;
    }

    private static function anchor(int $x, int $y): string
    {
        return self::u16(1) . self::i16($x) . self::i16($y);
    }

    private static function pairSetWithSecondGlyphs(int $count): string
    {
        $records = '';

        for ($glyphId = 1; $glyphId <= $count; ++$glyphId) {
            $records .= self::u16($glyphId);
        }

        return self::u16($count) . $records;
    }

    private static function i16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
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
