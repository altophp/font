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
use Alto\Font\OpenType\GdefCompactor;
use Alto\Font\OpenType\GlyphIdMap;
use Alto\Font\OpenType\Layout\ClassDefinitionTable;
use Alto\Font\OpenType\Layout\CoverageTable;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $store = "variation store";
        $gdef = self::u16(1) . self::u16(3)
            . str_repeat("\0", 10)
            . self::u32(18)
            . $store;
        $compacted = GdefCompactor::compact($gdef, GlyphIdMap::fromRetained(2, []));
        $reader = new BinaryReader($compacted, 'GDEF with store');
        $storeOffset = $reader->uint32(14);

        self::assertSame($store, $reader->string($storeOffset, \strlen($store)));
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

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
