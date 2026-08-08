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
use Alto\Font\OpenType\Table\NameTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameTable::class)]
final class NameTableTest extends TestCase
{
    public function testItParsesUtf16AndMacRomanNames(): void
    {
        $family = self::utf16beAscii('Family');
        $subfamily = 'Regular';
        $strings = $family . $subfamily . 'Ignored';
        $records = self::u16(3) . self::u16(1) . self::u16(0x0409) . self::u16(1) . self::u16(\strlen($family)) . self::u16(0);
        $records .= self::u16(1) . self::u16(0) . self::u16(0) . self::u16(2) . self::u16(\strlen($subfamily)) . self::u16(\strlen($family));
        $records .= self::u16(2) . self::u16(0) . self::u16(0) . self::u16(3) . self::u16(7) . self::u16(\strlen($family) + \strlen($subfamily));
        $reader = new BinaryReader(self::u16(0) . self::u16(3) . self::u16(42) . $records . $strings, 'name');

        self::assertSame([
            1 => 'Family',
            2 => 'Regular',
        ], NameTable::parse($reader));
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function utf16beAscii(string $value): string
    {
        $encoded = '';

        foreach (str_split($value) as $character) {
            $encoded .= "\0" . $character;
        }

        return $encoded;
    }
}
