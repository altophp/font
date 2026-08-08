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

namespace Alto\Font\Tests\Binary;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Table\TableRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BinaryReader::class)]
final class BinaryReaderTest extends TestCase
{
    public function testItReadsBigEndianValues(): void
    {
        $reader = new BinaryReader("\x7F\x80\x12\x34\xFF\xFE\x40\x00\x00\x00\x00\x2A\xFF\xFF\xFF\xFEABCD", 'fixture');

        self::assertSame(20, $reader->length());
        self::assertSame(127, $reader->uint8(0));
        self::assertSame(-128, $reader->int8(1));
        self::assertSame(0x1234, $reader->uint16(2));
        self::assertSame(-2, $reader->int16(4));
        self::assertSame(1.0, $reader->fixed2Dot14(6));
        self::assertSame(42, $reader->uint32(8));
        self::assertSame(-2, $reader->int32(12));
        self::assertSame('ABCD', $reader->string(16, 4));
    }

    public function testItReadsSignedFixed16Dot16Values(): void
    {
        $reader = new BinaryReader("\x00\x01\x80\x00\xFF\xFE\x00\x00", 'fixture');

        self::assertSame(1.5, $reader->fixed16Dot16(0));
        self::assertSame(-2.0, $reader->fixed16Dot16(4));
    }

    public function testItCreatesReadersForTableRecords(): void
    {
        $table = (new BinaryReader('xxxxnameyyyy', 'font'))->table(new TableRecord('name', 4, 4));

        self::assertSame('name', $table->string(0, 4));
    }

    public function testItRejectsOutOfBoundsReads(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Font data read out of bounds in fixture');

        (new BinaryReader('abc', 'fixture'))->uint32(0);
    }
}
