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
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Layout\DeltaSetIndexMapTable;
use Alto\Font\Variation\ItemStore\DeltaSetIndexMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeltaSetIndexMapTable::class)]
final class DeltaSetIndexMapTableTest extends TestCase
{
    /**
     * @param array{int, int} $entry
     */
    #[DataProvider('packedEntries')]
    public function testItBuildsTheSmallestEntryWidth(array $entry, string $packed): void
    {
        $data = DeltaSetIndexMapTable::build([$entry]);

        self::assertSame($packed, $data);
        self::assertSame($entry, DeltaSetIndexMap::parse(new BinaryReader($data, 'built map'))->deltaSetIndex(0));
    }

    /**
     * @return iterable<string, array{array{int, int}, string}>
     */
    public static function packedEntries(): iterable
    {
        yield 'one byte' => [[1, 1], "\0\0\0\1\x03"];
        yield 'two bytes' => [[128, 1], "\0\x10\0\1\x01\x01"];
        yield 'three bytes' => [[32768, 1], "\0\x20\0\1\x01\x00\x01"];
        yield 'four bytes and no variation sentinel' => [[65535, 65535], "\0\x3F\0\1\xFF\xFF\xFF\xFF"];
    }

    public function testItReportsOnlyTheOccupiedSourceBytes(): void
    {
        $source = 'xx' . "\1\x3F\0\0\0\1\0\x05\0\x09" . 'unrelated';

        self::assertSame(
            ['entries' => [[5, 9]], 'end' => 12],
            DeltaSetIndexMapTable::parse(new BinaryReader($source, 'embedded map'), 2),
        );
    }

    #[DataProvider('truncatedMaps')]
    public function testItRejectsTruncatedMapsBeforeReadingEntries(string $source): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('length is invalid');

        DeltaSetIndexMapTable::parse(new BinaryReader($source, 'truncated map'), 0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truncatedMaps(): iterable
    {
        yield 'format zero header' => ["\0"];
        yield 'format one header' => ["\1\0\0\0"];
        yield 'large format one count' => ["\1\x3F\xFF\xFF\xFF\xFF"];
        yield 'format zero data' => ["\0\x3F\0\1\0"];
    }
}
