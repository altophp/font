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
use Alto\Font\OpenType\Layout\ItemVariationStoreTable;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\ItemStore\ItemVariationStore;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemVariationStoreTable::class)]
final class ItemVariationStoreTableTest extends TestCase
{
    public function testItRelocatesSharedAndNullDataWithoutCopyingGaps(): void
    {
        $regions = pack('n*', 1, 2, 0, 0x4000, 0x4000, 0, 0x4000, 0x4000);
        $longData = pack('n*', 2, 0x8001, 2, 0, 1)
            . pack('NnNn', 70000, 0xFFF6, 0xFFFEEE90, 20);
        $shortData = pack('n*', 2, 1, 2, 0, 1) . pack('nCnC', 500, 251, 0xFE0C, 7);
        $source = 'xx' . pack('nNnNNNN', 1, 46, 4, 65, 0, 65, 27)
            . 'gap' . $shortData . 'gap' . $regions . 'gap' . $longData . 'unrelated';

        $parsed = ItemVariationStoreTable::parse(new BinaryReader($source, 'reordered store'), 2, 1);

        self::assertSame([[2, 26], [29, 45], [48, 64], [67, 89]], $parsed['ranges']);
        self::assertSame(
            pack('nNnNNNN', 1, 24, 4, 40, 0, 40, 62) . $regions . $longData . $shortData,
            $parsed['data'],
        );

        $variations = new FontVariations([new VariationAxis('wght', 100.0, 400.0, 900.0)]);
        $evaluated = ItemVariationStore::parse(new BinaryReader($parsed['data'], 'relocated store'), $variations);
        $coordinates = new NormalizedCoordinates(['wght' => 1.0]);

        self::assertSame(69990.0, $evaluated->delta(0, 0, $coordinates));
        self::assertSame(-69980.0, $evaluated->delta(2, 1, $coordinates));
        self::assertSame(0.0, $evaluated->delta(1, 42, $coordinates));
        self::assertSame(495.0, $evaluated->delta(3, 0, $coordinates));
        self::assertSame(-493.0, $evaluated->delta(3, 1, $coordinates));
    }

    #[DataProvider('malformedStores')]
    public function testItRejectsMalformedStores(string $data, string $message): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage($message);

        ItemVariationStoreTable::parse(new BinaryReader($data, 'malformed store'), 0, 1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedStores(): iterable
    {
        $store = self::store();

        yield 'truncated header' => [substr($store, 0, 7), 'ItemVariationStore is invalid'];
        yield 'unsupported format' => [substr_replace($store, pack('n', 2), 0, 2), 'ItemVariationStore is invalid'];
        yield 'region list in header' => [substr_replace($store, pack('N', 8), 2, 4), 'header is invalid'];
        yield 'truncated offsets' => [substr($store, 0, 10), 'header is invalid'];
        yield 'axis mismatch' => [substr_replace($store, pack('n', 2), 12, 2), 'axis count does not match fvar'];
        yield 'reserved region count bit' => [substr_replace($store, pack('n', 0x8001), 14, 2), 'region count uses reserved bits'];
        yield 'region list beyond end' => [substr_replace($store, pack('N', 100), 2, 4), 'region list offset is invalid'];
        yield 'truncated region list' => [substr($store, 0, 20), 'region list length is invalid'];
        yield 'data in header' => [substr_replace($store, pack('N', 8), 8, 4), 'data offset is invalid'];
        yield 'truncated data header' => [substr($store, 0, 28), 'data header length is invalid'];
        yield 'invalid word count' => [substr_replace($store, pack('n', 2), 24, 2), 'word delta count is invalid'];
        yield 'invalid long word count' => [substr_replace($store, pack('n', 0x8002), 24, 2), 'word delta count is invalid'];
        yield 'invalid region index' => [substr_replace($store, pack('n', 1), 28, 2), 'region index is invalid'];
        yield 'truncated delta data' => [substr($store, 0, -1), 'data length is invalid'];
        yield 'truncated long delta data' => [substr_replace($store, pack('n', 0x8001), 24, 2), 'data length is invalid'];
        yield 'overlapping region and data' => [pack('nNnNnnn', 1, 12, 1, 12, 1, 0, 0), 'structures overlap'];
        yield 'partially shared data' => [
            pack('nNnNN', 1, 16, 2, 26, 28)
                . pack('n*', 1, 1, 0, 0x4000, 0x4000) . str_repeat("\0", 8),
            'structures overlap',
        ];
    }

    private static function store(): string
    {
        return pack('nNnN', 1, 12, 1, 22)
            . pack('n*', 1, 1, 0, 0x4000, 0x4000)
            . pack('n*', 1, 1, 1, 0, 100);
    }
}
