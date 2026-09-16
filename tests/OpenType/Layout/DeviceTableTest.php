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
use Alto\Font\Exception\UnsupportedFontException;
use Alto\Font\OpenType\Layout\DeviceTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceTable::class)]
final class DeviceTableTest extends TestCase
{
    public function testItRelocatesSharedDataRelativeToEachParent(): void
    {
        $data = pack('n*', 12, 12, 1, 0x4000);
        $output = DeviceTable::append(str_repeat("\0", 12), [
            ['offset' => 4, 'base' => 0, 'data' => $data],
            ['offset' => 10, 'base' => 6, 'data' => $data],
        ]);
        $reader = new BinaryReader($output, 'shared devices');
        self::assertSame(12, $reader->uint16(4));
        self::assertSame(6, $reader->uint16(10));
        self::assertSame(20, $reader->length());
        self::assertSame($data, DeviceTable::copy($reader, 12));
    }

    public function testItPreservesVariationIndexesInsteadOfTreatingThemAsSizes(): void
    {
        $data = pack('n*', 5, 2, 0x8000);
        self::assertSame($data, DeviceTable::copy(new BinaryReader($data, 'variation index'), 0));
    }

    #[DataProvider('invalidDevices')]
    public function testItRejectsMalformedData(string $data): void
    {
        $this->expectException(InvalidFontException::class);
        DeviceTable::copy(new BinaryReader($data, 'invalid device'), 0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDevices(): iterable
    {
        yield 'invalid format' => [pack('n*', 12, 12, 4)];
        yield 'reversed sizes' => [pack('n*', 13, 12, 1, 0)];
        yield 'truncated second word' => [pack('n*', 10, 18, 1, 0)];
        yield 'truncated variation index' => [pack('n*', 0, 0)];
    }

    public function testItRejectsUnrepresentableOffsets(): void
    {
        $this->expectException(UnsupportedFontException::class);
        DeviceTable::append(str_repeat("\0", 65536), [
            ['offset' => 0, 'base' => 0, 'data' => pack('n*', 0, 0, 0x8000)],
        ]);
    }
}
