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
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\GposCompactor;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GposCompactor::class)]
final class GposCompactorTest extends TestCase
{
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
        self::assertSame($matrix, $reader->string($offset + 16, \strlen($matrix)));
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
        $lookup = self::u16($lookupType)
            . self::u16(0)
            . self::u16(1)
            . self::u16(8)
            . $subtable;
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

    private static function anchor(int $x, int $y): string
    {
        return self::u16(1) . self::i16($x) . self::i16($y);
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
