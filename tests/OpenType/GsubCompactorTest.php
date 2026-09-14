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
use Alto\Font\OpenType\GsubCompactor;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use Alto\Font\OpenType\Table\GsubTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GsubCompactor::class)]
final class GsubCompactorTest extends TestCase
{
    public function testItUsesExtensionLookupsWhenTheLookupListOffsetsOverflow(): void
    {
        $glyphCount = 16384;
        $glyphs = range(0, $glyphCount - 1);
        $coverage = CoverageTable::build($glyphs);
        $largeSingle = self::u16(2)
            . self::u16(6 + $glyphCount * 2)
            . self::u16($glyphCount)
            . pack('n*', ...$glyphs)
            . $coverage;
        $smallSingle = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([0]);
        $retained = array_fill_keys($glyphs, true);
        $output = GsubCompactor::compact(
            self::layoutWithTwoExtensionLookups(7, 1, $largeSingle, $smallSingle),
            GlyphIdMap::fromRetained($glyphCount, $retained),
        );
        $reader = new BinaryReader($output, 'large compacted GSUB');
        $lookupList = $reader->uint16(8);
        $firstLookup = $lookupList + $reader->uint16($lookupList + 2);
        $secondLookup = $lookupList + $reader->uint16($lookupList + 4);
        $secondWrapper = $secondLookup + $reader->uint16($secondLookup + 6);

        self::assertSame(2, $reader->uint16($lookupList));
        self::assertSame([7, 7], [$reader->uint16($firstLookup), $reader->uint16($secondLookup)]);
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
            . self::u16(6 + $glyphCount * 2)
            . self::u16($glyphCount)
            . pack('n*', ...$glyphs)
            . $compactCoverage;
        $smallSingle = self::u16(1) . self::u16(6) . self::u16(0) . CoverageTable::build([0]);
        $lookup = self::lookupWithSubtables(1, [$largeSingle, $smallSingle]);
        $output = GsubCompactor::compact(
            self::gsubWithLookup($lookup),
            GlyphIdMap::fromRetained($glyphCount, array_fill_keys($glyphs, true)),
        );
        $reader = new BinaryReader($output, 'large compacted GSUB lookup');
        $lookupList = $reader->uint16(8);
        $newLookup = $lookupList + $reader->uint16($lookupList + 2);
        $secondWrapper = $newLookup + $reader->uint16($newLookup + 8);

        self::assertSame([7, 2], [$reader->uint16($newLookup), $reader->uint16($newLookup + 4)]);
        self::assertSame(1, $reader->uint16($secondWrapper + 2));
        self::assertGreaterThan(0xFFFF, $reader->uint32($secondWrapper + 4));
    }

    public function testItCanonicallyReordersTopLevelSections(): void
    {
        $single = self::u16(1) . self::u16(6) . self::u16(3) . CoverageTable::build([2]);
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
        $output = GsubCompactor::compact($source, GlyphIdMap::fromRetained(6, [2 => true, 5 => true]));
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
        $single = self::u16(1) . self::u16(6) . self::u16(3) . CoverageTable::build([2]);
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
        $output = GsubCompactor::compact($source, GlyphIdMap::fromRetained(6, [2 => true, 5 => true]));
        $reader = new BinaryReader($output, 'compacted GSUB 1.1');
        $newFeatureVariationsOffset = $reader->uint32(10);

        self::assertSame([1, 1], [$reader->uint16(0), $reader->uint16(2)]);
        self::assertGreaterThan(18, $newFeatureVariationsOffset);
        self::assertSame($featureVariations, $reader->string($newFeatureVariationsOffset, 8));
    }

    public function testItRemapsSingleSubstitutionAndDropsUnavailablePairs(): void
    {
        $coverage = CoverageTable::build([2, 4]);
        $single = self::u16(2)
            . self::u16(10)
            . self::u16(2)
            . self::u16(5)
            . self::u16(8)
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(9, [2 => true, 5 => true, 8 => true]);
        $compacted = GsubCompactor::compact(self::gsub(1, $single), $mapping);
        [$reader, $offset] = self::firstSubtable($compacted);

        self::assertSame(2, $reader->uint16($offset));
        self::assertSame(1, $reader->uint16($offset + 4));
        self::assertSame(2, $reader->uint16($offset + 6));
        self::assertSame(
            [1],
            CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)),
        );
    }

    public function testItConvertsDeltaSingleSubstitutionToExplicitRemappedPairs(): void
    {
        $single = self::u16(1)
            . self::u16(6)
            . self::u16(3)
            . CoverageTable::build([2]);
        $mapping = GlyphIdMap::fromRetained(6, [2 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(1, $single), $mapping));

        self::assertSame(2, $reader->uint16($offset));
        self::assertSame(1, $reader->uint16($offset + 4));
        self::assertSame(2, $reader->uint16($offset + 6));
        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
    }

    public function testItCompactsFormatTwoCoverage(): void
    {
        $coverage = self::u16(2)
            . self::u16(2)
            . self::u16(2) . self::u16(2) . self::u16(0)
            . self::u16(4) . self::u16(4) . self::u16(1);
        $single = self::u16(2)
            . self::u16(10)
            . self::u16(2)
            . self::u16(5)
            . self::u16(8)
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(9, [2 => true, 4 => true, 5 => true, 8 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(1, $single), $mapping));

        self::assertSame([1, 2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame([3, 4], [$reader->uint16($offset + 6), $reader->uint16($offset + 8)]);
    }

    public function testItCompactsLigaturesThroughAnExtensionLookup(): void
    {
        $ligature = self::u16(8)
            . self::u16(2)
            . self::u16(5);
        $set = self::u16(1) . self::u16(4) . $ligature;
        $coverage = CoverageTable::build([2]);
        $subtable = self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . $coverage;
        $extension = self::u16(1) . self::u16(4) . self::u32(8) . $subtable;
        $mapping = GlyphIdMap::fromRetained(9, [2 => true, 5 => true, 8 => true]);
        $compacted = GsubCompactor::compact(self::gsub(7, $extension), $mapping);
        [$reader, $offset, $lookupType] = self::firstSubtable($compacted);

        self::assertSame(7, $lookupType);
        self::assertSame(1, $reader->uint16($offset));
        self::assertSame(4, $reader->uint16($offset + 2));

        $ligatureSubtable = $offset + $reader->uint32($offset + 4);
        self::assertSame(
            [1],
            CoverageTable::parse(
                $reader,
                $ligatureSubtable,
                $reader->uint16($ligatureSubtable + 2),
            ),
        );
        $ligatureSet = $ligatureSubtable + $reader->uint16($ligatureSubtable + 6);
        $newLigature = $ligatureSet + $reader->uint16($ligatureSet + 2);
        self::assertSame(3, $reader->uint16($newLigature));
        self::assertSame(2, $reader->uint16($newLigature + 2));
        self::assertSame(2, $reader->uint16($newLigature + 4));
    }

    public function testItRemapsMultipleSubstitutionSequences(): void
    {
        $sequenceOne = self::u16(2) . self::u16(4) . self::u16(5);
        $sequenceTwo = self::u16(1) . self::u16(3);
        $coverage = self::u16(1) . self::u16(2) . self::u16(1) . self::u16(2);
        $subtable = self::u16(1)
            . self::u16(10 + \strlen($sequenceOne) + \strlen($sequenceTwo))
            . self::u16(2)
            . self::u16(10)
            . self::u16(10 + \strlen($sequenceOne))
            . $sequenceOne
            . $sequenceTwo
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 4 => true, 5 => true]);
        $compacted = GsubCompactor::compact(self::gsub(2, $subtable), $mapping);
        $reader = new BinaryReader($compacted, 'test compacted multiple GSUB');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);
        $multiple = $lookup + $reader->uint16($lookup + 6);
        $sequence = $multiple + $reader->uint16($multiple + 6);

        self::assertSame(1, $reader->uint16($multiple));
        self::assertSame(1, $reader->uint16($multiple + 4));
        self::assertSame(2, $reader->uint16($sequence));
        self::assertSame([2, 3], [$reader->uint16($sequence + 2), $reader->uint16($sequence + 4)]);
    }

    public function testItRemapsAlternateSubstitutionSetsAndDropsEmptySets(): void
    {
        $firstSet = self::u16(2) . self::u16(5) . self::u16(6);
        $secondSet = self::u16(1) . self::u16(7);
        $headerLength = 10;
        $coverageOffset = $headerLength + \strlen($firstSet) + \strlen($secondSet);
        $subtable = self::u16(1)
            . self::u16($coverageOffset)
            . self::u16(2)
            . self::u16($headerLength)
            . self::u16($headerLength + \strlen($firstSet))
            . $firstSet
            . $secondSet
            . CoverageTable::build([2, 4]);
        $mapping = GlyphIdMap::fromRetained(8, [2 => true, 4 => true, 6 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(3, $subtable), $mapping));

        self::assertSame(1, $reader->uint16($offset));
        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(1, $reader->uint16($offset + 4));
        $set = $offset + $reader->uint16($offset + 6);
        self::assertSame(1, $reader->uint16($set));
        self::assertSame(3, $reader->uint16($set + 2));
    }

    public function testItRemapsContextFormatOneGlyphRules(): void
    {
        $rule = self::u16(3)
            . self::u16(1)
            . self::u16(4)
            . self::u16(5)
            . self::u16(1)
            . self::u16(0);
        $set = self::offsetList([$rule]);
        $subtable = self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . CoverageTable::build([2]);
        $mapping = GlyphIdMap::fromRetained(6, [2 => true, 4 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(5, $subtable), $mapping));
        $ruleSet = $offset + $reader->uint16($offset + 6);
        $newRule = $ruleSet + $reader->uint16($ruleSet + 2);

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(3, $reader->uint16($newRule));
        self::assertSame([2, 3], [$reader->uint16($newRule + 4), $reader->uint16($newRule + 6)]);
        self::assertSame([1, 0], [$reader->uint16($newRule + 8), $reader->uint16($newRule + 10)]);
    }

    public function testItRemapsContextFormatTwoClasses(): void
    {
        $rule = self::u16(2)
            . self::u16(1)
            . self::u16(2)
            . self::u16(1)
            . self::u16(0);
        $set = self::offsetList([$rule]);
        $classes = ClassDefinitionTable::build([2 => 1, 4 => 2]);
        $headerLength = 12;
        $classOffset = $headerLength + \strlen($set);
        $coverageOffset = $classOffset + \strlen($classes);
        $subtable = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16($classOffset)
            . self::u16(2)
            . self::u16(0)
            . self::u16($headerLength)
            . $set
            . $classes
            . CoverageTable::build([2]);
        $mapping = GlyphIdMap::fromRetained(5, [2 => true, 4 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(5, $subtable), $mapping));
        $ruleSet = $offset + $reader->uint16($offset + 10);
        $newRule = $ruleSet + $reader->uint16($ruleSet + 2);

        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(
            [1 => 1, 2 => 2],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 4)),
        );
        self::assertSame(2, $reader->uint16($newRule));
        self::assertSame(2, $reader->uint16($newRule + 4));
        self::assertSame([1, 0], [$reader->uint16($newRule + 6), $reader->uint16($newRule + 8)]);
    }

    public function testItRemapsContextFormatThreeCoverages(): void
    {
        $firstCoverage = CoverageTable::build([2, 3]);
        $secondCoverage = CoverageTable::build([4]);
        $headerLength = 14;
        $subtable = self::u16(3)
            . self::u16(2)
            . self::u16(1)
            . self::u16($headerLength)
            . self::u16($headerLength + \strlen($firstCoverage))
            . self::u16(1)
            . self::u16(0)
            . $firstCoverage
            . $secondCoverage;
        $mapping = GlyphIdMap::fromRetained(5, [2 => true, 4 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(5, $subtable), $mapping));

        self::assertSame(3, $reader->uint16($offset));
        self::assertSame([1], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 6)));
        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 8)));
        self::assertSame([1, 0], [$reader->uint16($offset + 10), $reader->uint16($offset + 12)]);
    }

    public function testItRemapsReverseChainedSingleSubstitutionThroughAnExtension(): void
    {
        $inputCoverage = CoverageTable::build([2, 4]);
        $backtrackCoverage = CoverageTable::build([1]);
        $lookaheadCoverage = CoverageTable::build([5]);
        $headerLength = 18;
        $subtable = self::u16(1)
            . self::u16($headerLength)
            . self::u16(1)
            . self::u16($headerLength + \strlen($inputCoverage))
            . self::u16(1)
            . self::u16($headerLength + \strlen($inputCoverage) + \strlen($backtrackCoverage))
            . self::u16(2)
            . self::u16(6)
            . self::u16(7)
            . $inputCoverage
            . $backtrackCoverage
            . $lookaheadCoverage;
        $extension = self::u16(1) . self::u16(8) . self::u32(8) . $subtable;
        $mapping = GlyphIdMap::fromRetained(8, [1 => true, 2 => true, 5 => true, 6 => true]);
        [$reader, $offset, $lookupType] = self::firstSubtable(GsubCompactor::compact(self::gsub(7, $extension), $mapping));
        $reverse = $offset + $reader->uint32($offset + 4);

        self::assertSame(7, $lookupType);
        self::assertSame(8, $reader->uint16($offset + 2));
        self::assertSame([2], CoverageTable::parse($reader, $reverse, $reader->uint16($reverse + 2)));
        self::assertSame([1], CoverageTable::parse($reader, $reverse, $reader->uint16($reverse + 6)));
        self::assertSame([3], CoverageTable::parse($reader, $reverse, $reader->uint16($reverse + 10)));
        self::assertSame(1, $reader->uint16($reverse + 12));
        self::assertSame(4, $reader->uint16($reverse + 14));
    }

    public function testItRemapsChainedContextFormatThreeLookups(): void
    {
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 4 => true]);
        $compacted = GsubCompactor::compact(self::chainedGsub(), $mapping);
        $closure = GsubTable::parse(new BinaryReader($compacted, 'test compacted GSUB'))
            ->glyphClosure([1 => true], 3);

        self::assertSame([1, 2], array_keys($closure));
    }

    public function testItRemapsChainedContextFormatOneRules(): void
    {
        $rule = self::u16(1) . self::u16(2)
            . self::u16(2) . self::u16(4)
            . self::u16(1) . self::u16(5)
            . self::u16(1) . self::u16(1) . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $coverage = self::coverage(1);
        $subtable = self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . $coverage;
        $mapping = GlyphIdMap::fromRetained(6, [1 => true, 2 => true, 4 => true, 5 => true]);
        $compacted = GsubCompactor::compact(self::gsub(6, $subtable), $mapping);
        $reader = new BinaryReader($compacted, 'test compacted chained GSUB');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);
        $chain = $lookup + $reader->uint16($lookup + 6);
        $ruleSet = $chain + $reader->uint16($chain + 6);
        $newRule = $ruleSet + $reader->uint16($ruleSet + 2);

        self::assertSame(2, $reader->uint16($newRule + 2));
        self::assertSame(3, $reader->uint16($newRule + 6));
        self::assertSame(4, $reader->uint16($newRule + 10));
    }

    public function testItRemapsChainedContextFormatTwoClasses(): void
    {
        $rule = self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(0)
            . self::u16(0);
        $set = self::u16(1) . self::u16(4) . $rule;
        $backtrackClasses = ClassDefinitionTable::build([2 => 1]);
        $inputClasses = ClassDefinitionTable::build([4 => 1, 5 => 7]);
        $lookaheadClasses = ClassDefinitionTable::build([5 => 1]);
        $headerLength = 16;
        $backtrackOffset = $headerLength + \strlen($set);
        $inputOffset = $backtrackOffset + \strlen($backtrackClasses);
        $lookaheadOffset = $inputOffset + \strlen($inputClasses);
        $coverageOffset = $lookaheadOffset + \strlen($lookaheadClasses);
        $subtable = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16($backtrackOffset)
            . self::u16($inputOffset)
            . self::u16($lookaheadOffset)
            . self::u16(2)
            . self::u16(0)
            . self::u16($headerLength)
            . $set
            . $backtrackClasses
            . $inputClasses
            . $lookaheadClasses
            . CoverageTable::build([4]);
        $mapping = GlyphIdMap::fromRetained(6, [2 => true, 4 => true, 5 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(6, $subtable), $mapping));

        self::assertSame([2], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
        self::assertSame(
            [1 => 1],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 4)),
        );
        self::assertSame(
            [2 => 1, 3 => 7],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6)),
        );
        self::assertSame(
            [3 => 1],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)),
        );
        self::assertSame(0, $reader->uint16($offset + 12));
        self::assertNotSame(0, $reader->uint16($offset + 14));
    }

    public function testItAcceptsNullOptionalChainedContextClassDefinitions(): void
    {
        $rule = self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u16(0);
        $set = self::offsetList([$rule]);
        $inputClasses = ClassDefinitionTable::build([4 => 1]);
        $headerLength = 16;
        $inputOffset = $headerLength + \strlen($set);
        $coverageOffset = $inputOffset + \strlen($inputClasses);
        $subtable = self::u16(2)
            . self::u16($coverageOffset)
            . self::u16(0)
            . self::u16($inputOffset)
            . self::u16(0)
            . self::u16(2)
            . self::u16(0)
            . self::u16($headerLength)
            . $set
            . $inputClasses
            . CoverageTable::build([4]);
        $mapping = GlyphIdMap::fromRetained(5, [4 => true]);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(self::gsub(6, $subtable), $mapping));

        self::assertSame(
            [],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 4)),
        );
        self::assertSame(
            [],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)),
        );
        self::assertSame(
            [1 => 1],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6)),
        );
    }

    public function testItRejectsASequenceCountThatDoesNotMatchCoverage(): void
    {
        $subtable = self::u16(1)
            . self::u16(8)
            . self::u16(0)
            . self::u16(0)
            . self::coverage(1);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('sequence count does not match coverage');

        GsubCompactor::compact(
            self::gsub(2, $subtable),
            GlyphIdMap::fromRetained(2, [1 => true]),
        );
    }

    public function testItRejectsNestedExtensionLookups(): void
    {
        $extension = self::u16(1) . self::u16(7) . self::u32(8) . self::u16(1);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('extension types are invalid or inconsistent');

        GsubCompactor::compact(
            self::gsub(7, $extension),
            GlyphIdMap::fromRetained(2, [1 => true]),
        );
    }

    public function testItPreservesNullContextRuleSetsAsEmptySets(): void
    {
        $subtable = self::u16(1)
            . self::u16(8)
            . self::u16(1)
            . self::u16(0)
            . self::coverage(1);
        [$reader, $offset] = self::firstSubtable(GsubCompactor::compact(
            self::gsub(5, $subtable),
            GlyphIdMap::fromRetained(2, [1 => true]),
        ));

        self::assertSame(0, $reader->uint16($offset + 4));
        self::assertSame([], CoverageTable::parse($reader, $offset, $reader->uint16($offset + 2)));
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('malformedCompactions')]
    public function testItRejectsMalformedCompactionSources(string $gsub, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        GsubCompactor::compact(
            $gsub,
            GlyphIdMap::fromRetained(10, array_fill_keys(range(0, 9), true)),
        );
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>, string}>
     */
    public static function malformedCompactions(): iterable
    {
        yield 'unsupported version' => [
            substr_replace(self::gsub(1, self::u16(1)), self::u16(2), 0, 2),
            UnsupportedFontException::class,
            'versions 1.0 and 1.1 only',
        ];
        yield 'invalid script list offset' => [
            substr_replace(self::gsub(1, self::u16(1)), self::u16(0), 4, 2),
            InvalidFontException::class,
            'script list offset is invalid',
        ];
        yield 'NULL lookup' => [
            self::patchLookupList(self::gsub(1, self::u16(1)), 2, 0),
            InvalidFontException::class,
            'lookup 0 offset must not be NULL',
        ];
        yield 'empty lookup' => [
            self::patchLookup(self::gsub(1, self::u16(1)), 4, 0),
            InvalidFontException::class,
            'must contain at least one subtable',
        ];
        yield 'NULL subtable' => [
            self::patchLookup(self::gsub(1, self::u16(1)), 6, 0),
            InvalidFontException::class,
            'subtable offset must not be NULL',
        ];
        yield 'NULL extension' => [
            self::patchLookup(self::gsub(7, self::u16(1)), 6, 0),
            InvalidFontException::class,
            'extension offset must not be NULL',
        ];
        yield 'unsupported extension format' => [
            self::gsub(7, self::u16(2)),
            UnsupportedFontException::class,
            'extension format is not supported',
        ];
        yield 'invalid extension offset' => [
            self::gsub(7, self::u16(1) . self::u16(1) . self::u32(4)),
            InvalidFontException::class,
            'extension offset is invalid',
        ];
        yield 'unsupported single format' => [
            self::gsub(1, self::u16(3)),
            UnsupportedFontException::class,
            'type 1 format 3 is not supported',
        ];
        yield 'NULL coverage' => [
            self::gsub(1, self::u16(2) . self::u16(0) . self::u16(0)),
            InvalidFontException::class,
            'coverage offset must not be NULL',
        ];
        yield 'unsupported coverage' => [
            self::gsub(1, self::u16(2) . self::u16(8) . self::u16(0) . self::u16(0) . self::u16(3)),
            UnsupportedFontException::class,
            'coverage format 3 is not supported',
        ];
        yield 'reversed coverage range' => [
            self::gsub(1, self::u16(2) . self::u16(8) . self::u16(0) . self::u16(0)
                . self::u16(2) . self::u16(1) . self::u16(2) . self::u16(1) . self::u16(0)),
            InvalidFontException::class,
            'coverage range is reversed',
        ];
        yield 'unsupported multiple format' => [
            self::gsub(2, self::u16(2)),
            UnsupportedFontException::class,
            'type 2 requires format 1',
        ];
        yield 'NULL sequence' => [
            self::gsub(2, self::u16(1) . self::u16(8) . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'sequence offset must not be NULL',
        ];
        yield 'invalid alternate format' => [
            self::gsub(3, self::u16(2)),
            InvalidFontException::class,
            'type 3 format must be 1',
        ];
        yield 'mismatched alternate sets' => [
            self::gsub(3, self::u16(1) . self::u16(8) . self::u16(0) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'alternate-set count does not match coverage',
        ];
        yield 'invalid alternate set offset' => [
            self::gsub(3, self::u16(1) . self::u16(8) . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'alternate-set offset is invalid',
        ];
        yield 'invalid alternate coverage offset' => [
            self::gsub(3, self::u16(1) . self::u16(6) . self::u16(1)
                . self::u16(1) . self::u16(1) . self::u16(1)),
            InvalidFontException::class,
            'alternate coverage offset is invalid',
        ];
        yield 'unsupported ligature format' => [
            self::gsub(4, self::u16(2)),
            UnsupportedFontException::class,
            'type 4 format is not supported',
        ];
        yield 'mismatched ligature sets' => [
            self::gsub(4, self::u16(1) . self::u16(8) . self::u16(0) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'ligature-set count does not match coverage',
        ];
        yield 'NULL ligature' => [
            self::gsub(4, self::u16(1) . self::u16(12) . self::u16(1) . self::u16(8)
                . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'ligature offset must not be NULL',
        ];
        yield 'invalid context format' => [
            self::gsub(5, self::u16(4)),
            InvalidFontException::class,
            'type 5 format must be 1, 2, or 3',
        ];
        yield 'invalid context sequence rule offset' => [
            self::gsub(5, self::u16(1) . self::u16(12) . self::u16(1) . self::u16(8)
                . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'sequence rule offset is invalid',
        ];
        yield 'mismatched context rule sets' => [
            self::gsub(5, self::u16(1) . self::u16(8) . self::u16(0) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'sequence rule-set count does not match coverage',
        ];
        yield 'invalid context coverage offset' => [
            self::gsub(5, self::u16(1) . self::u16(6) . self::u16(1)
                . self::u16(1) . self::u16(1) . self::u16(1)),
            InvalidFontException::class,
            'context coverage offset is invalid',
        ];
        yield 'invalid context rule-set offset' => [
            self::gsub(5, self::u16(1) . self::u16(8) . self::u16(1) . self::u16(6) . self::coverage(1)),
            InvalidFontException::class,
            'sequence rule-set offset is invalid',
        ];
        $classOutsideSource = ClassDefinitionTable::build([10 => 1]);
        yield 'context class glyph outside source font' => [
            self::gsub(5, self::u16(2)
                . self::u16(12 + \strlen($classOutsideSource))
                . self::u16(12)
                . self::u16(2)
                . self::u16(0)
                . self::u16(0)
                . $classOutsideSource
                . self::coverage(1)),
            InvalidFontException::class,
            'references glyph 10 outside the source font',
        ];
        $invalidInitialClass = ClassDefinitionTable::build([1 => 2]);
        yield 'context initial class without rule set' => [
            self::gsub(5, self::u16(2)
                . self::u16(12 + \strlen($invalidInitialClass))
                . self::u16(12)
                . self::u16(2)
                . self::u16(0)
                . self::u16(0)
                . $invalidInitialClass
                . self::coverage(1)),
            InvalidFontException::class,
            'class sequence rule-set count is invalid',
        ];
        $contextClasses = ClassDefinitionTable::build([1 => 0]);
        yield 'invalid context class rule-set offset' => [
            self::gsub(5, self::u16(2)
                . self::u16(10 + \strlen($contextClasses))
                . self::u16(10)
                . self::u16(1)
                . self::u16(2)
                . $contextClasses
                . self::coverage(1)),
            InvalidFontException::class,
            'class sequence rule-set offset is invalid',
        ];
        $invalidClassRuleSet = self::u16(1) . self::u16(2);
        yield 'invalid context class rule offset' => [
            self::gsub(5, self::u16(2)
                . self::u16(10 + \strlen($invalidClassRuleSet) + \strlen($contextClasses))
                . self::u16(10 + \strlen($invalidClassRuleSet))
                . self::u16(1)
                . self::u16(10)
                . $invalidClassRuleSet
                . $contextClasses
                . self::coverage(1)),
            InvalidFontException::class,
            'class sequence rule offset is invalid',
        ];
        yield 'invalid format three context coverage offset' => [
            self::gsub(5, self::u16(3) . self::u16(1) . self::u16(0) . self::u16(6)),
            InvalidFontException::class,
            'context coverage offset is invalid',
        ];
        $zeroGlyphRule = self::u16(0) . self::u16(0);
        $zeroGlyphSet = self::offsetList([$zeroGlyphRule]);
        yield 'empty context glyph rule' => [
            self::gsub(5, self::u16(1)
                . self::u16(8 + \strlen($zeroGlyphSet))
                . self::u16(1)
                . self::u16(8)
                . $zeroGlyphSet
                . self::coverage(1)),
            InvalidFontException::class,
            'sequence rule glyph count must not be zero',
        ];
        $zeroClassRule = self::u16(0) . self::u16(0);
        $zeroClassSet = self::offsetList([$zeroClassRule]);
        yield 'empty context class rule' => [
            self::gsub(5, self::u16(2)
                . self::u16(10 + \strlen($zeroClassSet) + \strlen($contextClasses))
                . self::u16(10 + \strlen($zeroClassSet))
                . self::u16(1)
                . self::u16(10)
                . $zeroClassSet
                . $contextClasses
                . self::coverage(1)),
            InvalidFontException::class,
            'class sequence rule glyph count must not be zero',
        ];
        yield 'empty context coverage sequence' => [
            self::gsub(5, self::u16(3) . self::u16(0) . self::u16(0)),
            InvalidFontException::class,
            'coverage sequence must not be empty',
        ];
        yield 'missing nested context lookup' => [
            self::gsub(5, self::u16(3) . self::u16(1) . self::u16(1) . self::u16(12)
                . self::u16(0) . self::u16(1) . self::coverage(1)),
            InvalidFontException::class,
            'sequence record references missing lookup 1',
        ];
        yield 'missing context input position' => [
            self::gsub(5, self::u16(3) . self::u16(1) . self::u16(1) . self::u16(12)
                . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'sequence record references missing input position 1',
        ];
        yield 'unsupported chained context format' => [
            self::gsub(6, self::u16(4)),
            UnsupportedFontException::class,
            'type 6 uses an unsupported format',
        ];
        yield 'missing chained context input position' => [
            self::gsub(6, self::u16(3) . self::u16(0) . self::u16(1) . self::u16(16)
                . self::u16(0) . self::u16(1) . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'sequence record references missing input position 1',
        ];
        yield 'invalid reverse context format' => [
            self::gsub(8, self::u16(2)),
            InvalidFontException::class,
            'type 8 format must be 1',
        ];
        yield 'mismatched reverse substitutions' => [
            self::gsub(8, self::u16(1) . self::u16(10) . self::u16(0) . self::u16(0)
                . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'reverse-substitution count does not match coverage',
        ];
        yield 'invalid reverse context coverage offset' => [
            self::gsub(8, self::u16(1) . self::u16(14) . self::u16(1) . self::u16(0)
                . self::u16(0) . self::u16(1) . self::u16(2) . self::coverage(1)),
            InvalidFontException::class,
            'reverse-context coverage offset is invalid',
        ];
        yield 'invalid reverse input coverage offset' => [
            self::gsub(8, self::u16(1) . self::u16(8) . self::u16(0) . self::u16(0)
                . self::u16(1) . self::u16(1) . self::u16(1)),
            InvalidFontException::class,
            'reverse input coverage offset is invalid',
        ];
    }

    private static function chainedGsub(): string
    {
        $single = self::u16(2)
            . self::u16(8)
            . self::u16(1)
            . self::u16(4)
            . self::coverage(1);
        $chain = self::u16(3)
            . self::u16(0)
            . self::u16(1)
            . self::u16(16)
            . self::u16(0)
            . self::u16(1)
            . self::u16(0)
            . self::u16(0)
            . self::coverage(1);
        $lookupList = self::offsetList([
            self::lookup(1, $single),
            self::lookup(6, $chain),
        ]);
        $scriptList = self::u16(0);
        $featureList = self::u16(1)
            . 'test'
            . self::u16(8)
            . self::u16(0)
            . self::u16(1)
            . self::u16(1);
        $scriptOffset = 10;
        $featureOffset = $scriptOffset + \strlen($scriptList);
        $lookupOffset = $featureOffset + \strlen($featureList);

        return self::u16(1)
            . self::u16(0)
            . self::u16($scriptOffset)
            . self::u16($featureOffset)
            . self::u16($lookupOffset)
            . $scriptList
            . $featureList
            . $lookupList;
    }

    private static function gsub(int $lookupType, string $subtable): string
    {
        $lookupList = self::offsetList([self::lookup($lookupType, $subtable)]);
        $scriptList = self::u16(0);
        $featureList = self::u16(0);

        return self::u16(1)
            . self::u16(0)
            . self::u16(10)
            . self::u16(10 + \strlen($scriptList))
            . self::u16(10 + \strlen($scriptList) + \strlen($featureList))
            . $scriptList
            . $featureList
            . $lookupList;
    }

    /**
     * @return array{BinaryReader, int, int}
     */
    private static function firstSubtable(string $gsub): array
    {
        $reader = new BinaryReader($gsub, 'compacted GSUB');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);

        return [$reader, $lookup + $reader->uint16($lookup + 6), $reader->uint16($lookup)];
    }

    private static function patchLookupList(string $gsub, int $relativeOffset, int $value): string
    {
        $reader = new BinaryReader($gsub, 'GSUB');

        return substr_replace($gsub, self::u16($value), $reader->uint16(8) + $relativeOffset, 2);
    }

    private static function patchLookup(string $gsub, int $relativeOffset, int $value): string
    {
        $reader = new BinaryReader($gsub, 'GSUB');
        $lookupList = $reader->uint16(8);
        $lookup = $lookupList + $reader->uint16($lookupList + 2);

        return substr_replace($gsub, self::u16($value), $lookup + $relativeOffset, 2);
    }

    private static function lookup(int $type, string $subtable): string
    {
        return self::u16($type)
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

    private static function gsubWithLookup(string $lookup): string
    {
        $lookupList = self::offsetList([$lookup]);

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

    private static function coverage(int $glyphId): string
    {
        return self::u16(1) . self::u16(1) . self::u16($glyphId);
    }

    /**
     * @param list<string> $items
     */
    private static function offsetList(array $items): string
    {
        $header = self::u16(\count($items));
        $data = '';
        $offset = 2 + \count($items) * 2;

        foreach ($items as $item) {
            $header .= self::u16($offset);
            $data .= $item;
            $offset += \strlen($item);
        }

        return $header . $data;
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
