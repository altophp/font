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
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\CoverageTable;
use Alto\Font\OpenType\Layout\PairPositioningTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PairPositioningTable::class)]
final class PairPositioningTableTest extends TestCase
{
    #[DataProvider('recordCounts')]
    public function testItSplitsOnlyBeyondTheLastRepresentableEvenOffset(int $count, int $expectedSets): void
    {
        $records = (static function () use ($count): \Generator {
            for ($index = 0; $index < $count; ++$index) {
                yield ['secondGlyphId' => $index, 'data' => '', 'devices' => []];
            }
        })();
        $sets = PairPositioningTable::splitRecords($records, 0);
        self::assertCount($expectedSets, $sets);
        self::assertSame(65522, \strlen($sets[0]));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function recordCounts(): iterable
    {
        yield 'last representable offset' => [32760, 1];
        yield 'one extra record' => [32761, 2];
    }

    public function testItDeduplicatesDeviceDataAcrossBothValuesAndRecords(): void
    {
        $device = pack('n*', 12, 12, 1, 0x4000);
        $record = ['secondGlyphId' => 1, 'data' => pack('n2', 0, 0), 'devices' => [
            ['offset' => 0, 'base' => 0, 'data' => $device],
            ['offset' => 2, 'base' => 0, 'data' => $device],
        ]];
        $sets = PairPositioningTable::splitRecords([$record, [...$record, 'secondGlyphId' => 2]], 0);
        self::assertSame([pack('n*', 2, 1, 14, 14, 2, 14, 14) . $device], $sets);
    }

    public function testItRejectsOneDeviceRecordThatCannotFitIntoAnyChunk(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('lookup 3 contains a pair record');
        PairPositioningTable::splitRecords([[
            'secondGlyphId' => 1,
            'data' => pack('n', 0),
            'devices' => [['offset' => 0, 'base' => 0, 'data' => pack('n*', 0, 65535, 3) . str_repeat("\0", 65536)]],
        ]], 3);
    }

    public function testItKeepsRepeatedFirstGlyphChunksInSeparateSubtables(): void
    {
        $sets = [pack('n2', 1, 2), pack('n2', 1, 3), pack('n2', 1, 4)];
        $tables = PairPositioningTable::buildSubtables([1, 1, 2], $sets, 0, 0);
        self::assertCount(2, $tables);

        foreach ([[1], [1, 2]] as $index => $expectedCoverage) {
            $reader = new BinaryReader($tables[$index], 'split pair coverage');
            self::assertSame($expectedCoverage, CoverageTable::parse($reader, 0, $reader->uint16(2)));
        }
    }

    public function testItBuildsAnEmptySubtableWhenNoPairsRemain(): void
    {
        self::assertSame([], PairPositioningTable::splitRecords([], 0));
        $tables = PairPositioningTable::buildSubtables([], [], 4, 0);
        self::assertCount(1, $tables);
        $reader = new BinaryReader($tables[0], 'empty pair table');
        self::assertSame(0, $reader->uint16(8));
        self::assertSame([], CoverageTable::parse($reader, 0, $reader->uint16(2)));
    }

    public function testItRejectsAnUnsplitPairSetInsteadOfWrappingOffsets(): void
    {
        $this->expectException(UnsupportedFontException::class);
        PairPositioningTable::build([1], [str_repeat("\0", 65524)], 0, 0);
    }
}
