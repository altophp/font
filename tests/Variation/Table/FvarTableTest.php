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

namespace Alto\Font\Tests\Variation\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\Table\FvarTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FvarTable::class)]
final class FvarTableTest extends TestCase
{
    public function testItParsesAxesAndNamedInstances(): void
    {
        $variations = FvarTable::parse(new BinaryReader(self::fvar(), 'fvar'), [
            256 => 'Weight',
            257 => 'Width',
            258 => 'Regular',
            259 => 'Bold Condensed',
            260 => 'Family-Regular',
            261 => 'Family-BoldCondensed',
        ]);

        self::assertInstanceOf(FontVariations::class, $variations);
        self::assertCount(2, $variations->axes);
        self::assertSame('wght', $variations->axes[0]->tag);
        self::assertSame('Weight', $variations->axes[0]->name);
        self::assertSame(100.0, $variations->axes[0]->minimum);
        self::assertSame(400.0, $variations->axes[0]->default);
        self::assertSame(900.0, $variations->axes[0]->maximum);
        self::assertSame('wdth', $variations->axes[1]->tag);
        self::assertSame('Width', $variations->axes[1]->name);
        self::assertCount(2, $variations->instances);
        self::assertSame('Regular', $variations->instances[0]->subfamilyName);
        self::assertSame(['wght' => 400.0, 'wdth' => 100.0], $variations->instances[0]->coordinates);
        self::assertSame('Family-Regular', $variations->instances[0]->postScriptName);
        self::assertSame('Bold Condensed', $variations->instances[1]->subfamilyName);
    }

    public function testItTreatsZeroAxesAsNonVariable(): void
    {
        $table = self::u16(1) . self::u16(0) . self::u16(16) . self::u16(2)
            . self::u16(0) . self::u16(20) . self::u16(0) . self::u16(4);

        self::assertNull(FvarTable::parse(new BinaryReader($table, 'fvar')));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported fvar table version 2.0.');

        FvarTable::parse(new BinaryReader(self::u16(2) . self::u16(0) . str_repeat("\0", 12), 'fvar'));
    }

    public function testItRejectsInvalidRecordSizes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Invalid fvar axis record size 18.');

        $table = self::u16(1) . self::u16(0) . self::u16(16) . self::u16(2)
            . self::u16(1) . self::u16(18) . self::u16(0) . self::u16(8);

        FvarTable::parse(new BinaryReader($table, 'fvar'));
    }

    public function testItRejectsInvalidInstanceRecordSizes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Invalid fvar instance record size 10.');

        $table = self::u16(1) . self::u16(0) . self::u16(16) . self::u16(2)
            . self::u16(2) . self::u16(20) . self::u16(1) . self::u16(10)
            . str_repeat("\0", 40);

        FvarTable::parse(new BinaryReader($table, 'fvar'));
    }

    private static function fvar(): string
    {
        return self::u16(1)
            . self::u16(0)
            . self::u16(16)
            . self::u16(2)
            . self::u16(2)
            . self::u16(20)
            . self::u16(2)
            . self::u16(14)
            . 'wght'
            . self::fixed(100.0)
            . self::fixed(400.0)
            . self::fixed(900.0)
            . self::u16(0)
            . self::u16(256)
            . 'wdth'
            . self::fixed(75.0)
            . self::fixed(100.0)
            . self::fixed(125.0)
            . self::u16(0)
            . self::u16(257)
            . self::u16(258)
            . self::u16(0)
            . self::fixed(400.0)
            . self::fixed(100.0)
            . self::u16(260)
            . self::u16(259)
            . self::u16(0)
            . self::fixed(800.0)
            . self::fixed(75.0)
            . self::u16(261);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function fixed(float $value): string
    {
        return pack('N', (int) round($value * 65536.0));
    }
}
