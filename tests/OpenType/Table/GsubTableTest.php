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

namespace Alto\Font\Tests\OpenType\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Table\GsubTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GsubTable::class)]
final class GsubTableTest extends TestCase
{
    /**
     * @param list<int> $expected
     */
    #[DataProvider('substitutionLookups')]
    public function testItComputesConservativeClosureForSupportedLookups(int $lookupType, string $subtable, array $expected): void
    {
        $closure = GsubTable::parse(new BinaryReader(self::gsub($lookupType, $subtable), 'GSUB'))
            ->glyphClosure([1 => true], 5);
        $glyphs = array_keys($closure);
        sort($glyphs);

        self::assertSame($expected, $glyphs);
    }

    /**
     * @return iterable<string, array{int, string, list<int>}>
     */
    public static function substitutionLookups(): iterable
    {
        yield 'single format 1' => [1, self::singleFormat1(1, 1), [1, 2]];
        yield 'single format 2' => [1, self::singleFormat2(1, 3), [1, 3]];
        yield 'multiple' => [2, self::multiple(1, [2, 3]), [1, 2, 3]];
        yield 'alternate' => [3, self::alternate(1, [2, 3]), [1, 2, 3]];
        yield 'ligature' => [4, self::ligature(1, 2, 4), [1, 2, 4]];
        yield 'extension' => [7, self::extension(1, self::singleFormat2(1, 2)), [1, 2]];
        yield 'reverse chain' => [8, self::reverse(1, 2), [1, 2]];
    }

    public function testItUsesCoverageFormatTwoIndexes(): void
    {
        $coverage = self::u16(2)
            . self::u16(1)
            . self::u16(1)
            . self::u16(2)
            . self::u16(0);
        $subtable = self::u16(2)
            . self::u16(10)
            . self::u16(2)
            . self::u16(3)
            . self::u16(4)
            . $coverage;
        $closure = GsubTable::parse(new BinaryReader(self::gsub(1, $subtable), 'GSUB'))
            ->glyphClosure([2 => true], 5);

        self::assertArrayHasKey(4, $closure);
    }

    public function testItComputesTransitiveClosureAcrossLookups(): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup(1, self::singleFormat2(1, 2)),
            self::lookup(1, self::singleFormat2(2, 3)),
        ]);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'GSUB'))->glyphClosure([1 => true], 5);
        $glyphs = array_keys($closure);
        sort($glyphs);

        self::assertSame([1, 2, 3], $glyphs);
    }

    #[DataProvider('contextualLookups')]
    public function testItComputesConservativeClosureForContextualLookupFormats(int $lookupType, string $subtable): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup($lookupType, $subtable),
            self::lookup(1, self::singleFormat2(1, 4)),
        ], [0]);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'GSUB'))
            ->glyphClosure([1 => true, 2 => true, 3 => true], 6);
        $glyphs = array_keys($closure);
        sort($glyphs);

        self::assertSame([1, 2, 3, 4], $glyphs);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function contextualLookups(): iterable
    {
        yield 'context format 1' => [5, self::contextFormat1(1, 2)];
        yield 'context format 2' => [5, self::contextFormat2(1, 2)];
        yield 'context format 3' => [5, self::contextFormat3(1, 2)];
        yield 'chained context format 1' => [6, self::chainedContextFormat1(2, 1, 3)];
        yield 'chained context format 2' => [6, self::chainedContextFormat2(2, 1, 3)];
        yield 'chained context format 3' => [6, self::chainedContextFormat3(2, 1, 3)];
    }

    public function testItDoesNotApplyAContextualLookupUntilEveryParticipantIsRetained(): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup(6, self::chainedContextFormat3(2, 1, 3)),
            self::lookup(1, self::singleFormat2(1, 4)),
        ], [0]);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'GSUB'))
            ->glyphClosure([1 => true, 2 => true], 6);

        self::assertSame([1, 2], array_keys($closure));
    }

    public function testItMatchesClassZeroWithoutExpandingItIntoTheSubset(): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup(5, self::contextFormat2ClassZero(1)),
            self::lookup(1, self::singleFormat2(1, 4)),
        ], [0]);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'GSUB'))
            ->glyphClosure([1 => true, 2 => true], 6);
        $glyphs = array_keys($closure);
        sort($glyphs);

        self::assertSame([1, 2, 4], $glyphs);
    }

    public function testVersionOnePointOneFeatureVariationsConservativelyRootEveryLookup(): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup(1, self::singleFormat2(1, 2)),
            self::lookup(1, self::singleFormat2(3, 4)),
        ], [0], featureVariations: true);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'GSUB 1.1'))
            ->glyphClosure([3 => true], 5);

        self::assertSame([3, 4], array_keys($closure));
    }

    public function testItParsesFormatOneClassDefinitionsInContextRules(): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup(5, self::contextFormat2WithFormatOneClasses(1, 2)),
            self::lookup(1, self::singleFormat2(1, 4)),
        ], [0]);
        $closure = GsubTable::parse(new BinaryReader($gsub, 'GSUB format-one classes'))
            ->glyphClosure([1 => true, 2 => true], 6);

        self::assertSame([1, 2, 4], array_keys($closure));
    }

    public function testItAcceptsEmptyFormatTwoCoverage(): void
    {
        $table = GsubTable::parse(new BinaryReader(
            self::gsub(1, self::singleWithCoverage(self::u16(2) . self::u16(0))),
            'GSUB empty coverage',
        ));

        self::assertSame([1 => true], $table->glyphClosure([1 => true], 2));
    }

    public function testItSkipsNullContextRuleSets(): void
    {
        $context = self::u16(1)
            . self::u16(8)
            . self::u16(1)
            . self::u16(0)
            . self::coverage(1);
        $table = GsubTable::parse(new BinaryReader(self::gsub(5, $context), 'GSUB NULL context set'));

        self::assertSame([1 => true], $table->glyphClosure([1 => true], 2));
    }

    public function testItRejectsFeatureLookupIndexesOutsideTheLookupList(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('feature 0 references lookup index 1 outside lookup count 1');

        GsubTable::parse(new BinaryReader(self::gsubWithLookups([
            self::lookup(1, self::singleFormat2(1, 2)),
        ], [1]), 'GSUB'));
    }

    public function testItRejectsNestedExtensionSubstitutions(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('contains a nested extension substitution');

        GsubTable::parse(new BinaryReader(
            self::gsub(7, self::extension(7, self::u16(1))),
            'GSUB',
        ));
    }

    public function testItRejectsReversedCoverageRanges(): void
    {
        $coverage = self::u16(2)
            . self::u16(1)
            . self::u16(2)
            . self::u16(1)
            . self::u16(0);
        $single = self::u16(2)
            . self::u16(8)
            . self::u16(1)
            . self::u16(3)
            . $coverage;

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('coverage range start must not exceed its end');

        GsubTable::parse(new BinaryReader(self::gsub(1, $single), 'GSUB'));
    }

    public function testItRejectsContextualLookupRecordsOutsideTheLookupList(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('references lookup index 1 outside lookup count 1');

        GsubTable::parse(new BinaryReader(self::gsub(5, self::contextFormat3(1, 2)), 'GSUB'));
    }

    public function testItRejectsContextualSequenceIndexesOutsideTheInputSequence(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('sequence index 2 is outside input count 2');

        GsubTable::parse(new BinaryReader(self::gsub(5, self::contextFormat3(1, 2, 2, 0)), 'GSUB'));
    }

    public function testItFailsClosedWhenAContextualRuleReferencesAnotherContextualLookup(): void
    {
        $gsub = self::gsubWithLookups([
            self::lookup(5, self::contextFormat3(1, 2)),
            self::lookup(5, self::contextFormat3WithoutRecords(1)),
        ], [0]);

        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('references contextual lookup index 1');

        GsubTable::parse(new BinaryReader($gsub, 'GSUB'));
    }

    public function testItFailsClosedForUnsupportedContextualFormats(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('type 6 uses unsupported format 4');

        GsubTable::parse(new BinaryReader(self::gsub(6, self::u16(4)), 'GSUB'));
    }

    public function testItFailsClosedForUnsupportedSubtableFormats(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('type 1 uses unsupported format 3');

        GsubTable::parse(new BinaryReader(self::gsub(1, self::u16(3)), 'GSUB'));
    }

    public function testItRejectsGlyphReferencesOutsideTheFont(): void
    {
        $table = GsubTable::parse(new BinaryReader(self::gsub(1, self::singleFormat2(1, 5)), 'GSUB'));

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('invalid glyph ID 5');

        $table->glyphClosure([1 => true], 5);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('malformedTables')]
    public function testItRejectsMalformedTables(string $table, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        GsubTable::parse(new BinaryReader($table, 'malformed GSUB'));
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>, string}>
     */
    public static function malformedTables(): iterable
    {
        yield 'unsupported version' => [
            self::u16(2) . self::u16(0) . str_repeat("\0", 6),
            UnsupportedFontException::class,
            'version 2.0 is not supported',
        ];
        yield 'NULL lists' => [
            self::u16(1) . self::u16(0) . self::u16(10) . self::u16(0) . self::u16(0),
            InvalidFontException::class,
            'list offsets must not be NULL',
        ];
        yield 'NULL feature' => [
            self::withPatchedUInt16(self::gsub(1, self::singleFormat2(1, 2)), 18, 0),
            InvalidFontException::class,
            'feature 0 offset must not be NULL',
        ];
        yield 'NULL lookup' => [
            self::withPatchedUInt16(
                self::gsub(1, self::singleFormat2(1, 2)),
                self::lookupListOffset(self::gsub(1, self::singleFormat2(1, 2))) + 2,
                0,
            ),
            InvalidFontException::class,
            'lookup 0 offset must not be NULL',
        ];
        yield 'unsupported lookup type' => [
            self::gsub(9, self::u16(1)),
            UnsupportedFontException::class,
            'unsupported type 9',
        ];
        yield 'unsupported multiple format' => [
            self::gsub(2, self::u16(2)),
            UnsupportedFontException::class,
            'type 2 uses unsupported format 2',
        ];
        yield 'unsupported alternate format' => [
            self::gsub(3, self::u16(2)),
            UnsupportedFontException::class,
            'type 3 uses unsupported format 2',
        ];
        yield 'unsupported ligature format' => [
            self::gsub(4, self::u16(2)),
            UnsupportedFontException::class,
            'type 4 uses unsupported format 2',
        ];
        yield 'unsupported context format' => [
            self::gsub(5, self::u16(4)),
            UnsupportedFontException::class,
            'type 5 uses unsupported format 4',
        ];
        yield 'unsupported reverse format' => [
            self::gsub(8, self::u16(2)),
            UnsupportedFontException::class,
            'type 8 uses unsupported format 2',
        ];
        yield 'empty lookup' => [
            self::gsubWithLookups([self::u16(1) . self::u16(0) . self::u16(0)]),
            InvalidFontException::class,
            'at least one subtable',
        ];
        yield 'NULL subtable' => [
            self::gsubWithLookups([self::u16(1) . self::u16(0) . self::u16(1) . self::u16(0)]),
            InvalidFontException::class,
            'subtable 0 offset must not be NULL',
        ];
        yield 'NULL multiple sequence' => [
            self::gsub(2, self::u16(1) . self::u16(8) . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'sequence offset must not be NULL',
        ];
        yield 'NULL alternate set' => [
            self::gsub(3, self::u16(1) . self::u16(8) . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'alternate-set offset must not be NULL',
        ];
        yield 'NULL ligature set' => [
            self::gsub(4, self::u16(1) . self::u16(8) . self::u16(1) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'ligature-set offset must not be NULL',
        ];
        yield 'NULL ligature' => [
            self::gsub(4, self::ligatureWithSet(1, self::u16(1) . self::u16(0))),
            InvalidFontException::class,
            'ligature offset must not be NULL',
        ];
        yield 'single-component ligature' => [
            self::gsub(4, self::ligatureWithSet(1, self::u16(1) . self::u16(4) . self::u16(2) . self::u16(1))),
            InvalidFontException::class,
            'at least two components',
        ];
        yield 'unsupported extension format' => [
            self::gsub(7, self::u16(2) . self::u16(1) . self::u32(8)),
            UnsupportedFontException::class,
            'type 7 uses unsupported format 2',
        ];
        yield 'NULL extension' => [
            self::gsub(7, self::u16(1) . self::u16(1) . self::u32(0)),
            InvalidFontException::class,
            'extension offset must not be NULL',
        ];
        yield 'NULL coverage' => [
            self::gsub(1, self::u16(2) . self::u16(0) . self::u16(0)),
            InvalidFontException::class,
            'coverage offset must not be NULL',
        ];
        yield 'mismatched single substitution count' => [
            self::gsub(1, self::u16(2) . self::u16(8) . self::u16(0) . self::u16(0) . self::coverage(1)),
            InvalidFontException::class,
            'single substitution count 0 does not match coverage count 1',
        ];
        yield 'unsupported coverage' => [
            self::gsub(1, self::u16(2) . self::u16(8) . self::u16(0) . self::u16(0) . self::u16(3)),
            UnsupportedFontException::class,
            'coverage uses unsupported format 3',
        ];
        yield 'duplicate coverage indexes' => [
            self::gsub(1, self::singleWithCoverage(self::rangeCoverage(0, 0))),
            InvalidFontException::class,
            'duplicate coverage indexes',
        ];
        yield 'non-contiguous coverage indexes' => [
            self::gsub(1, self::singleWithCoverage(self::rangeCoverage(1))),
            InvalidFontException::class,
            'coverage indexes are not contiguous',
        ];
        yield 'NULL context rule' => [
            self::gsub(5, self::contextFormatOneWithSet(1, self::u16(1) . self::u16(0))),
            InvalidFontException::class,
            'context-rule offset must not be NULL',
        ];
        yield 'NULL class definition' => [
            self::gsub(5, self::u16(2) . self::u16(12) . str_repeat("\0", 8) . self::coverage(1)),
            InvalidFontException::class,
            'class-definition offset must not be NULL',
        ];
        yield 'unsupported class definition' => [
            self::gsub(5, self::u16(2) . self::u16(14) . self::u16(12) . str_repeat("\0", 6) . self::u16(3) . self::coverage(1)),
            UnsupportedFontException::class,
            'class definition uses unsupported format 3',
        ];
    }

    private static function gsub(int $lookupType, string $subtable): string
    {
        return self::gsubWithLookups([self::lookup($lookupType, $subtable)]);
    }

    /**
     * @param list<string>   $lookups
     * @param list<int>|null $featureLookupIndexes
     */
    private static function gsubWithLookups(
        array $lookups,
        ?array $featureLookupIndexes = null,
        bool $featureVariations = false,
    ): string {
        $featureLookupIndexes ??= array_keys($lookups);
        $feature = self::u16(0)
            . self::u16(\count($featureLookupIndexes))
            . self::glyphs($featureLookupIndexes);
        $featureList = self::u16(1)
            . 'test'
            . self::u16(8)
            . $feature;
        $lookupOffsets = '';
        $lookupData = '';
        $offset = 2 + \count($lookups) * 2;

        foreach ($lookups as $lookup) {
            $lookupOffsets .= self::u16($offset);
            $lookupData .= $lookup;
            $offset += \strlen($lookup);
        }

        $headerLength = $featureVariations ? 14 : 10;
        $scriptList = self::u16(0);
        $lookupList = self::u16(\count($lookups)) . $lookupOffsets . $lookupData;
        $featureListOffset = $headerLength + \strlen($scriptList);
        $lookupListOffset = $featureListOffset + \strlen($featureList);
        $featureVariationsOffset = $lookupListOffset + \strlen($lookupList);
        $header = self::u16(1)
            . self::u16($featureVariations ? 1 : 0)
            . self::u16($headerLength)
            . self::u16($featureListOffset)
            . self::u16($lookupListOffset);

        if ($featureVariations) {
            $header .= self::u32($featureVariationsOffset);
        }

        return $header
            . $scriptList
            . $featureList
            . $lookupList
            . ($featureVariations ? self::u16(1) . self::u16(0) . self::u32(0) : '');
    }

    private static function lookup(int $lookupType, string $subtable): string
    {
        return self::u16($lookupType) . self::u16(0) . self::u16(1) . self::u16(8) . $subtable;
    }

    private static function lookupListOffset(string $gsub): int
    {
        return new BinaryReader($gsub, 'GSUB')->uint16(8);
    }

    private static function withPatchedUInt16(string $data, int $offset, int $value): string
    {
        return substr_replace($data, self::u16($value), $offset, 2);
    }

    private static function ligatureWithSet(int $glyphId, string $set): string
    {
        return self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . self::coverage($glyphId);
    }

    private static function singleWithCoverage(string $coverage): string
    {
        return self::u16(2) . self::u16(8) . self::u16(0) . self::u16(0) . $coverage;
    }

    private static function rangeCoverage(int ...$indexes): string
    {
        $data = self::u16(2) . self::u16(\count($indexes));

        foreach (array_values($indexes) as $glyph => $index) {
            $data .= self::u16($glyph + 1) . self::u16($glyph + 1) . self::u16($index);
        }

        return $data;
    }

    private static function singleFormat1(int $inputGlyphId, int $delta): string
    {
        return self::u16(1) . self::u16(6) . self::u16($delta) . self::coverage($inputGlyphId);
    }

    private static function singleFormat2(int $inputGlyphId, int $outputGlyphId): string
    {
        return self::u16(2) . self::u16(8) . self::u16(1) . self::u16($outputGlyphId) . self::coverage($inputGlyphId);
    }

    /**
     * @param list<int> $outputGlyphIds
     */
    private static function multiple(int $inputGlyphId, array $outputGlyphIds): string
    {
        $sequence = self::u16(\count($outputGlyphIds)) . self::glyphs($outputGlyphIds);

        return self::u16(1)
            . self::u16(8 + \strlen($sequence))
            . self::u16(1)
            . self::u16(8)
            . $sequence
            . self::coverage($inputGlyphId);
    }

    /**
     * @param list<int> $outputGlyphIds
     */
    private static function alternate(int $inputGlyphId, array $outputGlyphIds): string
    {
        $set = self::u16(\count($outputGlyphIds)) . self::glyphs($outputGlyphIds);

        return self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . self::coverage($inputGlyphId);
    }

    private static function ligature(int $firstGlyphId, int $secondGlyphId, int $ligatureGlyphId): string
    {
        $set = self::u16(1)
            . self::u16(4)
            . self::u16($ligatureGlyphId)
            . self::u16(2)
            . self::u16($secondGlyphId);

        return self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . self::coverage($firstGlyphId);
    }

    private static function contextFormat1(int $firstGlyphId, int $secondGlyphId): string
    {
        $rule = self::u16(2)
            . self::u16(1)
            . self::u16($secondGlyphId)
            . self::lookupRecord(0, 1);
        $set = self::u16(1) . self::u16(4) . $rule;

        return self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . self::coverage($firstGlyphId);
    }

    private static function contextFormat2(int $firstGlyphId, int $secondGlyphId): string
    {
        $rule = self::u16(2)
            . self::u16(1)
            . self::u16(2)
            . self::lookupRecord(0, 1);
        $set = self::u16(1) . self::u16(4) . $rule;
        $classDefinition = self::classDefinition([
            $firstGlyphId => 1,
            $secondGlyphId => 2,
        ]);
        $headerLength = 12;

        return self::u16(2)
            . self::u16($headerLength + \strlen($set) + \strlen($classDefinition))
            . self::u16($headerLength + \strlen($set))
            . self::u16(2)
            . self::u16(0)
            . self::u16($headerLength)
            . $set
            . $classDefinition
            . self::coverage($firstGlyphId);
    }

    private static function contextFormat2ClassZero(int $firstGlyphId): string
    {
        $rule = self::u16(2)
            . self::u16(1)
            . self::u16(0)
            . self::lookupRecord(0, 1);
        $set = self::u16(1) . self::u16(4) . $rule;
        $classDefinition = self::classDefinition([$firstGlyphId => 1]);
        $headerLength = 12;

        return self::u16(2)
            . self::u16($headerLength + \strlen($set) + \strlen($classDefinition))
            . self::u16($headerLength + \strlen($set))
            . self::u16(2)
            . self::u16(0)
            . self::u16($headerLength)
            . $set
            . $classDefinition
            . self::coverage($firstGlyphId);
    }

    private static function contextFormat2WithFormatOneClasses(int $firstGlyphId, int $secondGlyphId): string
    {
        $rule = self::u16(2)
            . self::u16(1)
            . self::u16(2)
            . self::lookupRecord(0, 1);
        $set = self::u16(1) . self::u16(4) . $rule;
        $classDefinition = self::u16(1)
            . self::u16($firstGlyphId)
            . self::u16(2)
            . self::u16(1)
            . self::u16(2);
        $headerLength = 12;

        return self::u16(2)
            . self::u16($headerLength + \strlen($set) + \strlen($classDefinition))
            . self::u16($headerLength + \strlen($set))
            . self::u16(2)
            . self::u16(0)
            . self::u16($headerLength)
            . $set
            . $classDefinition
            . self::coverage($firstGlyphId);
    }

    private static function contextFormatOneWithSet(int $glyphId, string $set): string
    {
        return self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . self::coverage($glyphId);
    }

    private static function contextFormat3(
        int $firstGlyphId,
        int $secondGlyphId,
        int $sequenceIndex = 0,
        int $lookupIndex = 1,
    ): string {
        $firstCoverage = self::coverage($firstGlyphId);
        $secondCoverage = self::coverage($secondGlyphId);
        $headerLength = 14;

        return self::u16(3)
            . self::u16(2)
            . self::u16(1)
            . self::u16($headerLength)
            . self::u16($headerLength + \strlen($firstCoverage))
            . self::lookupRecord($sequenceIndex, $lookupIndex)
            . $firstCoverage
            . $secondCoverage;
    }

    private static function contextFormat3WithoutRecords(int $glyphId): string
    {
        return self::u16(3)
            . self::u16(1)
            . self::u16(0)
            . self::u16(8)
            . self::coverage($glyphId);
    }

    private static function chainedContextFormat1(int $backtrackGlyphId, int $inputGlyphId, int $lookaheadGlyphId): string
    {
        $rule = self::u16(1)
            . self::u16($backtrackGlyphId)
            . self::u16(1)
            . self::u16(1)
            . self::u16($lookaheadGlyphId)
            . self::u16(1)
            . self::lookupRecord(0, 1);
        $set = self::u16(1) . self::u16(4) . $rule;

        return self::u16(1)
            . self::u16(8 + \strlen($set))
            . self::u16(1)
            . self::u16(8)
            . $set
            . self::coverage($inputGlyphId);
    }

    private static function chainedContextFormat2(int $backtrackGlyphId, int $inputGlyphId, int $lookaheadGlyphId): string
    {
        $rule = self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::u16(1)
            . self::lookupRecord(0, 1);
        $set = self::u16(1) . self::u16(4) . $rule;
        $backtrackClasses = self::classDefinition([$backtrackGlyphId => 1]);
        $inputClasses = self::classDefinition([$inputGlyphId => 1]);
        $lookaheadClasses = self::classDefinition([$lookaheadGlyphId => 1]);
        $headerLength = 16;
        $backtrackOffset = $headerLength + \strlen($set);
        $inputOffset = $backtrackOffset + \strlen($backtrackClasses);
        $lookaheadOffset = $inputOffset + \strlen($inputClasses);
        $coverageOffset = $lookaheadOffset + \strlen($lookaheadClasses);

        return self::u16(2)
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
            . self::coverage($inputGlyphId);
    }

    private static function chainedContextFormat3(int $backtrackGlyphId, int $inputGlyphId, int $lookaheadGlyphId): string
    {
        $backtrackCoverage = self::coverage($backtrackGlyphId);
        $inputCoverage = self::coverage($inputGlyphId);
        $lookaheadCoverage = self::coverage($lookaheadGlyphId);
        $headerLength = 20;

        return self::u16(3)
            . self::u16(1)
            . self::u16($headerLength)
            . self::u16(1)
            . self::u16($headerLength + \strlen($backtrackCoverage))
            . self::u16(1)
            . self::u16($headerLength + \strlen($backtrackCoverage) + \strlen($inputCoverage))
            . self::u16(1)
            . self::lookupRecord(0, 1)
            . $backtrackCoverage
            . $inputCoverage
            . $lookaheadCoverage;
    }

    /**
     * @param array<int, int> $classesByGlyph
     */
    private static function classDefinition(array $classesByGlyph): string
    {
        $ranges = '';

        foreach ($classesByGlyph as $glyphId => $classId) {
            $ranges .= self::u16($glyphId) . self::u16($glyphId) . self::u16($classId);
        }

        return self::u16(2) . self::u16(\count($classesByGlyph)) . $ranges;
    }

    private static function lookupRecord(int $sequenceIndex, int $lookupIndex): string
    {
        return self::u16($sequenceIndex) . self::u16($lookupIndex);
    }

    private static function extension(int $lookupType, string $subtable): string
    {
        return self::u16(1) . self::u16($lookupType) . self::u32(8) . $subtable;
    }

    private static function reverse(int $inputGlyphId, int $outputGlyphId): string
    {
        return self::u16(1)
            . self::u16(12)
            . self::u16(0)
            . self::u16(0)
            . self::u16(1)
            . self::u16($outputGlyphId)
            . self::coverage($inputGlyphId);
    }

    private static function coverage(int ...$glyphIds): string
    {
        return self::u16(1) . self::u16(\count($glyphIds)) . self::glyphs(array_values($glyphIds));
    }

    /**
     * @param list<int> $glyphIds
     */
    private static function glyphs(array $glyphIds): string
    {
        return implode('', array_map(static fn(int $glyphId): string => self::u16($glyphId), $glyphIds));
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
