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
use Alto\Font\Font;
use Alto\Font\OpenType\CmapBuilder;
use Alto\Font\OpenType\Table\CmapTable;
use Alto\Font\Subset\UnicodeRange;
use Alto\Font\Subset\UnicodeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CmapTable::class)]
final class CmapTableTest extends TestCase
{
    public function testItMapsCodepointsFromFormat4Subtables(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(4) . self::u16(32) . self::u16(0) . self::u16(4)
            . self::u16(0) . self::u16(0) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF)
            . self::u16(0xFFC0) . self::u16(1)
            . self::u16(0) . self::u16(0),
            'cmap',
        ));

        self::assertSame(1, $cmap->glyphIdForCodepoint(65));
        self::assertNull($cmap->glyphIdForCodepoint(66));
        self::assertCount(1, $cmap);
    }

    public function testItMapsFormat4SubtablesThroughGlyphIdArrays(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(4) . self::u16(36) . self::u16(0) . self::u16(4)
            . self::u16(0) . self::u16(0) . self::u16(0)
            . self::u16(66) . self::u16(0xFFFF) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF)
            . self::u16(1) . self::u16(1)
            . self::u16(4) . self::u16(0)
            . self::u16(4) . self::u16(0),
            'cmap',
        ));

        self::assertSame(5, $cmap->glyphIdForCodepoint(65));
        self::assertSame(0, $cmap->glyphIdForCodepoint(66));
        self::assertSame([65 => 5, 66 => 0], iterator_to_array($cmap->mappings()));
        self::assertCount(2, $cmap);
    }

    public function testItIgnoresFormat4GlyphArrayOffsetsOutsideTheSubtable(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(4) . self::u16(32) . self::u16(0) . self::u16(4)
            . self::u16(0) . self::u16(0) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF) . self::u16(0)
            . self::u16(65) . self::u16(0xFFFF)
            . self::u16(1) . self::u16(1)
            . self::u16(4) . self::u16(0),
            'cmap',
        ));

        self::assertNull($cmap->glyphIdForCodepoint(65));
        self::assertSame([], iterator_to_array($cmap->mappings()));
        self::assertCount(0, $cmap);
    }

    public function testItCountsOnlyCodepointsReachableThroughTheGlyphIdArray(): void
    {
        // One segment of four codepoints, but a glyphIdArray holding two entries.
        // The idRangeOffset counts from its own slot: two slots to the array start.
        $cmap = CmapTable::parse(self::cmap(self::format4(
            [[65, 68, 0, 4]],
            self::u16(7) . self::u16(8),
        )));

        self::assertSame([65 => 7, 66 => 8], iterator_to_array($cmap->mappings()));
        self::assertSame(8, $cmap->glyphIdForCodepoint(66));
        self::assertNull($cmap->glyphIdForCodepoint(67));
        self::assertCount(2, $cmap);
    }

    public function testItMapsCodepointsFromFormat12Subtables(): void
    {
        $cmap = CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(10) . self::u32(12)
            . self::u16(12) . self::u16(0) . self::u32(28) . self::u32(0) . self::u32(1)
            . self::u32(0x1F600) . self::u32(0x1F601) . self::u32(7),
            'cmap',
        ));

        self::assertSame(7, $cmap->glyphIdForCodepoint(0x1F600));
        self::assertSame(8, $cmap->glyphIdForCodepoint(0x1F601));
        self::assertNull($cmap->glyphIdForCodepoint(0x1F602));
        self::assertNull($cmap->glyphIdForCodepoint(0x1F5FF));
        self::assertCount(2, $cmap);
    }

    public function testItKeepsAFullRangeFormat12GroupWithoutExpandingIt(): void
    {
        $reader = self::cmap(self::format12([[0, 0x10FFFF, 1]]), 3, 10);
        $before = memory_get_usage();
        $cmap = CmapTable::parse($reader);

        self::assertLessThan(1 << 20, memory_get_usage() - $before, 'The map must not be expanded per codepoint.');
        self::assertCount(0x110000, $cmap);
        self::assertSame(1, $cmap->glyphIdForCodepoint(0));
        self::assertSame(0x110000, $cmap->glyphIdForCodepoint(0x10FFFF));
        self::assertNull($cmap->glyphIdForCodepoint(-1));
        self::assertNull($cmap->glyphIdForCodepoint(0x110000));
    }

    public function testItRejectsFormat12GroupsBeyondUnicode(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('cmap format 12 group 0 ends beyond U+10FFFF.');

        CmapTable::parse(self::cmap(self::format12([[0, 0xFFFFFFFF, 1]]), 3, 10));
    }

    public function testItRejectsFormat12GroupsThatStartAfterTheyEnd(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('cmap format 12 group 0 starts after it ends.');

        CmapTable::parse(self::cmap(self::format12([[0x5A, 0x41, 1]]), 3, 10));
    }

    /**
     * @param list<array{int, int, int}> $groups
     */
    #[DataProvider('provideUnsortedFormat12Groups')]
    public function testItRejectsUnsortedOrOverlappingFormat12Groups(array $groups): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('cmap format 12 groups must be sorted and must not overlap.');

        CmapTable::parse(self::cmap(self::format12($groups), 3, 10));
    }

    /**
     * @return iterable<string, array{list<array{int, int, int}>}>
     */
    public static function provideUnsortedFormat12Groups(): iterable
    {
        yield 'overlapping' => [[[0x41, 0x5A, 1], [0x50, 0x60, 2]]];
        yield 'descending' => [[[0x61, 0x7A, 1], [0x41, 0x5A, 2]]];
        yield 'repeated' => [[[0x41, 0x41, 1], [0x41, 0x41, 2]]];
    }

    public function testItRejectsOverlappingFormat4Segments(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('cmap format 4 segments must be sorted and must not overlap.');

        CmapTable::parse(self::cmap(self::format4([[0, 0xFFFE, 1, 0], [0, 0xFFFE, 1, 0], [0xFFFF, 0xFFFF, 1, 0]])));
    }

    public function testItRejectsFormat4SegmentsThatStartAfterTheyEnd(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('cmap format 4 segment 0 starts after it ends.');

        CmapTable::parse(self::cmap(self::format4([[0x5A, 0x41, 1, 0], [0xFFFF, 0xFFFF, 1, 0]])));
    }

    public function testItIteratesMappingsInCodepointOrder(): void
    {
        $cmap = self::sampleFormat4();

        self::assertSame(
            [65 => 1, 66 => 2, 67 => 3, 0x100 => 9, 0x101 => 0, 0x102 => 11, 0x200 => 20],
            iterator_to_array($cmap->mappings()),
        );
        self::assertCount(7, $cmap);
    }

    public function testItRestrictsMappingsToAUnicodeSet(): void
    {
        $cmap = self::sampleFormat4();

        self::assertSame(
            [66 => 2, 67 => 3, 0x100 => 9, 0x101 => 0],
            iterator_to_array($cmap->mappings(UnicodeSet::fromRanges([UnicodeRange::between(66, 0x101)]))),
        );
        self::assertSame(
            [65 => 1, 0x102 => 11, 0x200 => 20],
            iterator_to_array($cmap->mappings(UnicodeSet::fromCodepoints([0x200, 65, 0x102, 0x150]))),
        );
        self::assertSame([], iterator_to_array($cmap->mappings(UnicodeSet::fromCodepoints([64, 68, 0x1FF]))));
        self::assertSame([], iterator_to_array($cmap->mappings(UnicodeSet::fromCodepoints([]))));
    }

    #[DataProvider('provideBundledFonts')]
    public function testItMatchesTheExpandedMapOfBundledFonts(string $path): void
    {
        $data = Font::fromFile($path)->sfntDocument()->table('cmap');
        self::assertNotNull($data);

        self::assertMatchesExpandedMap(new BinaryReader($data, 'cmap'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBundledFonts(): iterable
    {
        foreach ([
            'Inter-Regular-latin.woff2',
            'NotoNaskhArabic-Regular.ttf',
            'NotoSansDevanagari-Regular.ttf',
            'AltoCorpusCJK.ttf',
            'AltoCorpusVariable.ttf',
        ] as $name) {
            yield $name => [\dirname(__DIR__, 2) . '/Fixtures/Fonts/' . $name];
        }
    }

    public function testItMatchesTheExpandedMapOfAFormat12Table(): void
    {
        $mappings = [];

        for ($codepoint = 0x20; $codepoint <= 0x7E; ++$codepoint) {
            $mappings[$codepoint] = $codepoint - 0x1D;
        }

        $mappings += [0xE9 => 200, 0x10000 => 300, 0x10001 => 301, 0x1F600 => 400, 0x10FFFF => 500];
        $reader = new BinaryReader(CmapBuilder::build($mappings), 'cmap');

        self::assertSame(12, $reader->uint16($reader->uint32(4 + 3 * 8 + 4)));
        self::assertMatchesExpandedMap($reader);
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported cmap table version.');

        CmapTable::parse(new BinaryReader(self::u16(1) . self::u16(0), 'cmap'));
    }

    public function testItRejectsCmapsWithoutSupportedSubtables(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('No supported cmap format 4 or 12 subtable found.');

        CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(1) . self::u32(12)
            . self::u16(0) . str_repeat("\0", 10),
            'cmap',
        ));
    }

    public function testItRejectsUnsupportedFormat12ReservedValues(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported cmap format 12 reserved value.');

        CmapTable::parse(new BinaryReader(
            self::u16(0) . self::u16(1)
            . self::u16(3) . self::u16(10) . self::u32(12)
            . self::u16(12) . self::u16(1) . self::u32(16) . self::u32(0) . self::u32(0),
            'cmap',
        ));
    }

    /**
     * Compares lookups, iteration and count against a per-codepoint expansion
     * of the same subtable, the representation the parser used before.
     */
    private static function assertMatchesExpandedMap(BinaryReader $reader): void
    {
        $expanded = self::expand($reader);
        $cmap = CmapTable::parse($reader);

        self::assertNotSame([], $expanded);
        self::assertSame($expanded, iterator_to_array($cmap->mappings()));
        self::assertCount(\count($expanded), $cmap);

        foreach ($expanded as $codepoint => $glyphId) {
            self::assertSame($glyphId, $cmap->glyphIdForCodepoint($codepoint));

            foreach ([$codepoint - 1, $codepoint + 1] as $neighbour) {
                if (!isset($expanded[$neighbour])) {
                    self::assertNull($cmap->glyphIdForCodepoint($neighbour));
                }
            }
        }
    }

    /**
     * Expands the preferred format 12 or format 4 subtable one codepoint at a time.
     *
     * @return array<int, int>
     */
    private static function expand(BinaryReader $reader): array
    {
        $format4 = null;
        $format12 = null;

        for ($i = 0, $count = $reader->uint16(2); $i < $count; ++$i) {
            $platformId = $reader->uint16(4 + $i * 8);
            $encodingId = $reader->uint16(6 + $i * 8);
            $offset = $reader->uint32(8 + $i * 8);

            if (0 !== $platformId && (3 !== $platformId || !\in_array($encodingId, [1, 10], true))) {
                continue;
            }

            match ($reader->uint16($offset)) {
                4 => $format4 = $offset,
                12 => $format12 = $offset,
                default => null,
            };
        }

        $map = [];

        if (null !== $format12) {
            for ($group = 0, $groups = $reader->uint32($format12 + 12); $group < $groups; ++$group) {
                $start = $reader->uint32($format12 + 16 + $group * 12);
                $end = $reader->uint32($format12 + 20 + $group * 12);
                $glyphId = $reader->uint32($format12 + 24 + $group * 12);

                for ($codepoint = $start; $codepoint <= $end; ++$codepoint) {
                    $map[$codepoint] = $glyphId + ($codepoint - $start);
                }
            }

            return $map;
        }

        self::assertNotNull($format4);
        $length = $reader->uint16($format4 + 2);
        $segCount = intdiv($reader->uint16($format4 + 6), 2);
        $endCodes = $format4 + 14;
        $startCodes = $endCodes + $segCount * 2 + 2;
        $idDeltas = $startCodes + $segCount * 2;
        $idRangeOffsets = $idDeltas + $segCount * 2;

        for ($segment = 0; $segment < $segCount; ++$segment) {
            $end = $reader->uint16($endCodes + $segment * 2);
            $start = $reader->uint16($startCodes + $segment * 2);
            $delta = $reader->int16($idDeltas + $segment * 2);
            $rangeOffset = $reader->uint16($idRangeOffsets + $segment * 2);

            if (0xFFFF === $start && 0xFFFF === $end) {
                continue;
            }

            for ($codepoint = $start; $codepoint <= $end; ++$codepoint) {
                if (0 === $rangeOffset) {
                    $map[$codepoint] = ($codepoint + $delta) & 0xFFFF;
                    continue;
                }

                $glyphOffset = $idRangeOffsets + $segment * 2 + $rangeOffset + ($codepoint - $start) * 2;

                if ($glyphOffset + 2 > $format4 + $length) {
                    continue;
                }

                $glyphId = $reader->uint16($glyphOffset);
                $map[$codepoint] = 0 === $glyphId ? 0 : ($glyphId + $delta) & 0xFFFF;
            }
        }

        return $map;
    }

    /**
     * Three segments: a delta run, a glyphIdArray run holding a zero, a lone codepoint.
     */
    private static function sampleFormat4(): CmapTable
    {
        // Segment 1 reads from the glyphIdArray: idRangeOffset counts from its own
        // slot, two slots before the array start.
        return CmapTable::parse(self::cmap(self::format4(
            [[65, 67, (1 - 65) & 0xFFFF, 0], [0x100, 0x102, 0, 2 * 3], [0x200, 0x200, (20 - 0x200) & 0xFFFF, 0]],
            self::u16(9) . self::u16(0) . self::u16(11),
        )));
    }

    private static function cmap(string $subtable, int $platformId = 3, int $encodingId = 1): BinaryReader
    {
        return new BinaryReader(
            self::u16(0) . self::u16(1) . self::u16($platformId) . self::u16($encodingId) . self::u32(12) . $subtable,
            'cmap',
        );
    }

    /**
     * @param list<array{int, int, int, int}> $segments [startCode, endCode, idDelta, idRangeOffset], terminator appended
     */
    private static function format4(array $segments, string $glyphIdArray = ''): string
    {
        $segments[] = [0xFFFF, 0xFFFF, 1, 0];
        $segCount = \count($segments);
        $endCodes = '';
        $startCodes = '';
        $idDeltas = '';
        $idRangeOffsets = '';

        foreach ($segments as [$start, $end, $delta, $rangeOffset]) {
            $endCodes .= self::u16($end);
            $startCodes .= self::u16($start);
            $idDeltas .= self::u16($delta);
            $idRangeOffsets .= self::u16($rangeOffset);
        }

        $body = self::u16(0) . self::u16($segCount * 2) . self::u16(0) . self::u16(0) . self::u16(0)
            . $endCodes . self::u16(0) . $startCodes . $idDeltas . $idRangeOffsets . $glyphIdArray;

        return self::u16(4) . self::u16(4 + \strlen($body)) . $body;
    }

    /**
     * @param list<array{int, int, int}> $groups [startCharCode, endCharCode, startGlyphId]
     */
    private static function format12(array $groups): string
    {
        $data = '';

        foreach ($groups as [$start, $end, $glyphId]) {
            $data .= self::u32($start) . self::u32($end) . self::u32($glyphId);
        }

        return self::u16(12) . self::u16(0) . self::u32(16 + \strlen($data)) . self::u32(0) . self::u32(\count($groups)) . $data;
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('N', $value);
    }
}
