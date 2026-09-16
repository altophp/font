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
use Alto\Font\OpenType\Layout\LookupListTable;
use Alto\Font\OpenType\Layout\LookupTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LookupListTable::class)]
final class LookupListTableTest extends TestCase
{
    public function testItPreservesDirectLookupsWhenTheirOffsetsFit(): void
    {
        $lookups = [
            new LookupTable(1, 0, null, ['one']),
            new LookupTable(2, 0, null, ['two']),
        ];

        self::assertSame(
            self::offsetList([self::lookup(1, 'one'), self::lookup(2, 'two')]),
            LookupListTable::build($lookups, 7, 'GSUB'),
        );
    }

    public function testItPreservesAnExtensionLookupWhenItsOffsetsFit(): void
    {
        $lookup = new LookupTable(4, 0x0010, 9, ['one', 'two'], true);
        $expected = self::u16(1)
            . self::u16(4)
            . self::u16(7)
            . self::u16(0x0010)
            . self::u16(2)
            . self::u16(12)
            . self::u16(20)
            . self::u16(9)
            . self::u16(1)
            . self::u16(4)
            . pack('N', 16)
            . self::u16(1)
            . self::u16(4)
            . pack('N', 11)
            . 'one'
            . 'two';

        self::assertSame($expected, LookupListTable::build([$lookup], 7, 'GSUB'));
    }

    #[DataProvider('extensionTypes')]
    public function testItMovesLargeLookupPayloadsBehindExtensionWrappers(int $extensionType, string $tag): void
    {
        $largePayload = str_repeat('a', 0xFFF6);
        $lookups = [
            new LookupTable(1, 0x0010, 42, [$largePayload]),
            new LookupTable(4, 0, null, ['small'], true),
        ];
        $output = LookupListTable::build($lookups, $extensionType, $tag);
        $reader = new BinaryReader($output, 'lookup list');
        $firstLookup = $reader->uint16(2);
        $secondLookup = $reader->uint16(4);

        self::assertSame(2, $reader->uint16(0));
        self::assertSame([6, 24], [$firstLookup, $secondLookup]);
        self::assertSame([$extensionType, 0x0010, 1, 10, 42], [
            $reader->uint16($firstLookup),
            $reader->uint16($firstLookup + 2),
            $reader->uint16($firstLookup + 4),
            $reader->uint16($firstLookup + 6),
            $reader->uint16($firstLookup + 8),
        ]);
        self::assertSame([$extensionType, 0, 1, 8], [
            $reader->uint16($secondLookup),
            $reader->uint16($secondLookup + 2),
            $reader->uint16($secondLookup + 4),
            $reader->uint16($secondLookup + 6),
        ]);

        $firstWrapper = $firstLookup + $reader->uint16($firstLookup + 6);
        $secondWrapper = $secondLookup + $reader->uint16($secondLookup + 6);
        $firstPayload = $firstWrapper + $reader->uint32($firstWrapper + 4);
        $secondPayload = $secondWrapper + $reader->uint32($secondWrapper + 4);

        self::assertSame([1, 1], [$reader->uint16($firstWrapper), $reader->uint16($secondWrapper)]);
        self::assertSame([1, 4], [$reader->uint16($firstWrapper + 2), $reader->uint16($secondWrapper + 2)]);
        self::assertSame(40, $firstPayload);
        self::assertSame($firstPayload + \strlen($largePayload), $secondPayload);
        self::assertSame($largePayload, $reader->string($firstPayload, \strlen($largePayload)));
        self::assertSame('small', $reader->string($secondPayload, 5));
    }

    public function testItMovesSubtablesBehindExtensionsWhenAnInternalOffsetOverflows(): void
    {
        $output = LookupListTable::build([
            new LookupTable(1, 0, null, [str_repeat('a', 0xFFFF), 'small']),
        ], 7, 'GSUB');
        $reader = new BinaryReader($output, 'lookup list');
        $lookup = $reader->uint16(2);

        self::assertSame(7, $reader->uint16($lookup));
        self::assertSame([10, 18], [$reader->uint16($lookup + 6), $reader->uint16($lookup + 8)]);
    }

    public function testItRejectsLookupCountsBeyondOpenTypeLimits(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('lookup count exceeds OpenType limits');

        LookupListTable::build(
            array_fill(0, 0x10000, new LookupTable(1, 0, null, ['x'])),
            7,
            'GSUB',
        );
    }

    public function testItRejectsExtensionHeadersBeyondOpenTypeLimits(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('lookup headers exceed a 16-bit OpenType offset');

        LookupListTable::build(
            array_fill(0, 5000, new LookupTable(1, 0, null, ['12345678'])),
            7,
            'GSUB',
        );
    }

    public function testItRejectsExtensionWrapperOffsetsBeyondOpenTypeLimits(): void
    {
        $this->expectException(UnsupportedFontException::class);
        $this->expectExceptionMessage('extension wrappers exceed a 16-bit OpenType offset');

        LookupListTable::build([
            new LookupTable(1, 0, null, array_fill(0, 8192, 'x'), extension: true),
        ], 7, 'GSUB');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function extensionTypes(): iterable
    {
        yield 'GSUB' => [7, 'GSUB'];
        yield 'GPOS' => [9, 'GPOS'];
    }

    private static function lookup(int $type, string $subtable, int $flag = 0, ?int $markFilteringSet = null): string
    {
        $headerLength = null === $markFilteringSet ? 8 : 10;

        return self::u16($type)
            . self::u16($flag)
            . self::u16(1)
            . self::u16($headerLength)
            . (null === $markFilteringSet ? '' : self::u16($markFilteringSet))
            . $subtable;
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

}
