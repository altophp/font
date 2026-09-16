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
use Alto\Font\OpenType\GdefCompactor;
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdefCompactor::class)]
final class GdefCompactorTest extends TestCase
{
    public function testItCompactsVersionOnePointTwoSubtables(): void
    {
        $glyphClasses = ClassDefinitionTable::build([2 => 1, 4 => 2, 5 => 3]);
        $attachList = self::coverageRecordList(
            [2, 4],
            [self::u16(2) . self::u16(1) . self::u16(3), self::u16(1) . self::u16(8)],
        );
        $ligatureCarets = self::coverageRecordList(
            [5, 7],
            [self::offsetList([self::u16(1) . self::i16(300)]), self::offsetList([self::u16(2) . self::u16(4)])],
        );
        $markAttachClasses = ClassDefinitionTable::build([5 => 2, 7 => 3]);
        $markGlyphSets = self::markGlyphSets([[5, 7], [2, 8]]);
        $gdef = self::gdef12($glyphClasses, $attachList, $ligatureCarets, $markAttachClasses, $markGlyphSets);
        $mapping = GlyphIdMap::fromRetained(10, [2 => true, 5 => true, 8 => true]);
        $reader = new BinaryReader(GdefCompactor::compact($gdef, $mapping), 'compacted GDEF');

        self::assertSame(1, $reader->uint16(0));
        self::assertSame(2, $reader->uint16(2));
        self::assertSame(
            [1 => 1, 2 => 3],
            ClassDefinitionTable::parse($reader, 0, $reader->uint16(4)),
        );

        $attachOffset = $reader->uint16(6);
        self::assertSame([1], CoverageTable::parse($reader, $attachOffset, $reader->uint16($attachOffset)));
        self::assertSame(1, $reader->uint16($attachOffset + 2));
        $pointTable = $attachOffset + $reader->uint16($attachOffset + 4);
        self::assertSame([1, 3], [$reader->uint16($pointTable + 2), $reader->uint16($pointTable + 4)]);

        $ligatureOffset = $reader->uint16(8);
        self::assertSame([2], CoverageTable::parse($reader, $ligatureOffset, $reader->uint16($ligatureOffset)));
        $ligature = $ligatureOffset + $reader->uint16($ligatureOffset + 4);
        $caret = $ligature + $reader->uint16($ligature + 2);
        self::assertSame(1, $reader->uint16($caret));
        self::assertSame(300, $reader->int16($caret + 2));

        self::assertSame(
            [2 => 2],
            ClassDefinitionTable::parse($reader, 0, $reader->uint16(10)),
        );

        $markSetsOffset = $reader->uint16(12);
        self::assertSame(1, $reader->uint16($markSetsOffset));
        self::assertSame(2, $reader->uint16($markSetsOffset + 2));
        self::assertSame(
            [2],
            CoverageTable::parse($reader, $markSetsOffset, $reader->uint32($markSetsOffset + 4)),
        );
        self::assertSame(
            [1, 3],
            CoverageTable::parse($reader, $markSetsOffset, $reader->uint32($markSetsOffset + 8)),
        );
    }

    public function testItPreservesAFinalItemVariationStore(): void
    {
        $store = self::variationStore();
        $gdef = self::u16(1) . self::u16(3)
            . str_repeat("\0", 10)
            . self::u32(18)
            . $store;
        $compacted = GdefCompactor::compact($gdef, GlyphIdMap::fromRetained(2, []));
        $reader = new BinaryReader($compacted, 'GDEF with store');
        $storeOffset = $reader->uint32(14);

        self::assertSame($store, $reader->string($storeOffset, \strlen($store)));
    }

    public function testItRejectsAttachmentCountsThatDoNotMatchCoverage(): void
    {
        $emptyClasses = ClassDefinitionTable::build([]);
        $attachList = self::coverageRecordList([2], []);
        $gdef = self::gdef12(
            $emptyClasses,
            $attachList,
            self::coverageRecordList([], []),
            $emptyClasses,
            self::markGlyphSets([]),
        );

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('attachment count does not match coverage');

        GdefCompactor::compact($gdef, GlyphIdMap::fromRetained(3, [2 => true]));
    }

    #[DataProvider('caretAdjustments')]
    public function testItRelocatesCaretAdjustments(string $device): void
    {
        // Padding forces relocation; dropping glyph 2 also renumbers the ligature.
        $caret = self::u16(3) . self::i16(-300) . self::u16(12) . str_repeat("\0", 6) . $device;
        $ligatureCarets = self::coverageRecordList(
            [2, 5],
            [self::offsetList([self::u16(1) . self::i16(100)]), self::offsetList([$caret])],
        );
        $reader = new BinaryReader(
            GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatureCarets), GlyphIdMap::fromRetained(6, [5 => true])),
            'GDEF caret adjustments',
        );
        $list = $reader->uint16(8);
        self::assertSame([1], CoverageTable::parse($reader, $list, $reader->uint16($list)));
        $ligature = $list + $reader->uint16($list + 4);
        $outputCaret = $ligature + $reader->uint16($ligature + 2);

        self::assertSame(3, $reader->uint16($outputCaret));
        self::assertSame(-300, $reader->int16($outputCaret + 2));
        self::assertSame(6, $reader->uint16($outputCaret + 4));
        self::assertSame($device, $reader->string($outputCaret + 6, \strlen($device)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function caretAdjustments(): iterable
    {
        yield 'two-bit deltas' => [pack('n*', 10, 18, 1, 0x4000, 0xC000)];
        yield 'four-bit deltas' => [pack('n*', 10, 14, 2, 0x1234, 0xF000)];
        yield 'eight-bit deltas' => [pack('n*', 10, 12, 3, 0x01FF, 0x0200)];
        yield 'variation index' => [pack('n*', 2, 1, 0x8000)];
    }

    public function testItPreservesACaretWithNoAdjustment(): void
    {
        $caret = pack('n*', 3, 300, 0);
        $ligatures = self::coverageRecordList([2], [self::offsetList([$caret])]);
        $reader = new BinaryReader(
            GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatures), GlyphIdMap::fromRetained(3, [2 => true])),
            'GDEF no adjustment',
        );
        $list = $reader->uint16(8);
        $ligature = $list + $reader->uint16($list + 4);
        $offset = $ligature + $reader->uint16($ligature + 2);
        self::assertSame($caret, $reader->string($offset, 6));
    }

    public function testItRejectsTruncatedCaretAdjustments(): void
    {
        $caret = pack('n*', 3, 300, 0xFFFF);
        $ligatures = self::coverageRecordList([2], [self::offsetList([$caret])]);
        $this->expectException(InvalidFontException::class);

        GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatures), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    public function testItRelocatesAnItemVariationStoreBeforeOtherSubtables(): void
    {
        $glyphClasses = ClassDefinitionTable::build([1 => 1]);
        $store = self::variationStore();
        $gdef = self::u16(1) . self::u16(3)
            . self::u16(18 + \strlen($store))
            . str_repeat("\0", 8)
            . self::u32(18)
            . $store . $glyphClasses;
        $reader = new BinaryReader(GdefCompactor::compact($gdef, GlyphIdMap::fromRetained(2, [1 => true])), 'reordered GDEF');

        self::assertSame([1 => 1], ClassDefinitionTable::parse($reader, 0, $reader->uint16(4)));
        self::assertSame($store, $reader->string($reader->uint32(14), \strlen($store)));
        self::assertSame(18 + \strlen($glyphClasses) + \strlen($store), $reader->length());
    }

    public function testItRejectsAnItemVariationStoreOverlappingAnotherSubtable(): void
    {
        $gdef = self::u16(1) . self::u16(3) . self::u16(18)
            . str_repeat("\0", 8) . self::u32(18) . self::variationStore();
        $this->expectException(InvalidFontException::class);

        GdefCompactor::compact($gdef, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsAClassDefinitionExtendingIntoTheVariationStore(): void
    {
        $gdef = pack('n*', 1, 3, 18, 0, 0, 0, 0) . pack('N', 24)
            . pack('n*', 1, 0, 2) . self::variationStore();
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('overlaps another subtable');

        GdefCompactor::compact($gdef, GlyphIdMap::fromRetained(2, [1 => true]));
    }

    public function testItRejectsInvalidCaretAdjustmentOffsets(): void
    {
        $caret = pack('n*', 3, 300, 2);
        $ligatures = self::coverageRecordList([2], [self::offsetList([$caret])]);
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('overlaps the caret value');

        GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatures), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('does not support version 2.0');

        GdefCompactor::compact(self::u16(2) . self::u16(0) . str_repeat("\0", 8), GlyphIdMap::fromRetained(1, []));
    }

    public function testItRejectsNullAttachmentPointOffsets(): void
    {
        $attachList = self::u16(6) . self::u16(1) . self::u16(0) . CoverageTable::build([2]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('attachment point offset must not be NULL');

        GdefCompactor::compact(self::gdefWith(attachList: $attachList), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    public function testItRejectsLigatureCountsThatDoNotMatchCoverage(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ligature caret count does not match coverage');

        GdefCompactor::compact(
            self::gdefWith(ligatureCarets: self::coverageRecordList([2], [])),
            GlyphIdMap::fromRetained(3, [2 => true]),
        );
    }

    public function testItRejectsNullLigatureGlyphOffsets(): void
    {
        $ligatures = self::u16(6) . self::u16(1) . self::u16(0) . CoverageTable::build([2]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('ligature glyph offset must not be NULL');

        GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatures), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    public function testItRejectsNullCaretValueOffsets(): void
    {
        $ligatures = self::coverageRecordList([2], [self::u16(1) . self::u16(0)]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('caret value offset must not be NULL');

        GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatures), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    public function testItRejectsInvalidCaretValueFormats(): void
    {
        $ligatures = self::coverageRecordList([2], [self::offsetList([self::u16(4) . self::u16(0)])]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('caret value format 4 is invalid');

        GdefCompactor::compact(self::gdefWith(ligatureCarets: $ligatures), GlyphIdMap::fromRetained(3, [2 => true]));
    }

    public function testItRejectsUnsupportedMarkGlyphSetFormats(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('mark glyph sets format 1 only');

        GdefCompactor::compact(
            self::gdefWith(markGlyphSets: self::u16(2) . self::u16(0)),
            GlyphIdMap::fromRetained(1, []),
        );
    }

    public function testItRejectsNullMarkGlyphSetCoverageOffsets(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('coverage offset must not be NULL');

        GdefCompactor::compact(
            self::gdefWith(markGlyphSets: self::u16(1) . self::u16(1) . self::u32(0)),
            GlyphIdMap::fromRetained(1, []),
        );
    }

    private static function gdefWith(
        ?string $attachList = null,
        ?string $ligatureCarets = null,
        ?string $markGlyphSets = null,
    ): string {
        $emptyClasses = ClassDefinitionTable::build([]);

        return self::gdef12(
            $emptyClasses,
            $attachList ?? self::coverageRecordList([], []),
            $ligatureCarets ?? self::coverageRecordList([], []),
            $emptyClasses,
            $markGlyphSets ?? self::markGlyphSets([]),
        );
    }

    private static function gdef12(
        string $glyphClasses,
        string $attachList,
        string $ligatureCarets,
        string $markAttachClasses,
        string $markGlyphSets,
    ): string {
        $cursor = 14;
        $header = self::u16(1) . self::u16(2);

        foreach ([$glyphClasses, $attachList, $ligatureCarets, $markAttachClasses, $markGlyphSets] as $table) {
            $header .= self::u16($cursor);
            $cursor += \strlen($table);
        }

        return $header . $glyphClasses . $attachList . $ligatureCarets . $markAttachClasses . $markGlyphSets;
    }

    /**
     * @param list<list<int>> $sets
     */
    private static function markGlyphSets(array $sets): string
    {
        $cursor = 4 + \count($sets) * 4;
        $header = self::u16(1) . self::u16(\count($sets));
        $data = '';

        foreach ($sets as $glyphs) {
            $coverage = CoverageTable::build($glyphs);
            $header .= self::u32($cursor);
            $data .= $coverage;
            $cursor += \strlen($coverage);
        }

        return $header . $data;
    }

    /**
     * @param list<int>    $glyphs
     * @param list<string> $records
     */
    private static function coverageRecordList(array $glyphs, array $records): string
    {
        $cursor = 4 + \count($records) * 2;
        $offsets = '';
        $data = '';

        foreach ($records as $record) {
            $offsets .= self::u16($cursor);
            $data .= $record;
            $cursor += \strlen($record);
        }

        return self::u16($cursor)
            . self::u16(\count($records))
            . $offsets
            . $data
            . CoverageTable::build($glyphs);
    }

    /**
     * @param list<string> $items
     */
    private static function offsetList(array $items): string
    {
        $cursor = 2 + \count($items) * 2;
        $header = self::u16(\count($items));
        $data = '';

        foreach ($items as $item) {
            $header .= self::u16($cursor);
            $data .= $item;
            $cursor += \strlen($item);
        }

        return $header . $data;
    }

    private static function i16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function variationStore(): string
    {
        return self::u16(1) . self::u32(8) . self::u16(0) . self::u16(0) . self::u16(0);
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
