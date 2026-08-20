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
        $inputClasses = ClassDefinitionTable::build([4 => 1]);
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
            [2 => 1],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 6)),
        );
        self::assertSame(
            [3 => 1],
            ClassDefinitionTable::parse($reader, $offset, $reader->uint16($offset + 8)),
        );
        self::assertSame(0, $reader->uint16($offset + 12));
        self::assertNotSame(0, $reader->uint16($offset + 14));
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
            'version 1.0 only',
        ];
        yield 'unordered lists' => [
            substr_replace(self::gsub(1, self::u16(1)), self::u16(0), 4, 2),
            UnsupportedFontException::class,
            'requires ordered script, feature, and lookup lists',
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
        yield 'unsupported chained context format' => [
            self::gsub(6, self::u16(4)),
            UnsupportedFontException::class,
            'type 6 uses an unsupported format',
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
