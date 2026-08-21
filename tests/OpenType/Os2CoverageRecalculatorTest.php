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
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Os2CoverageRecalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Os2CoverageRecalculator::class)]
final class Os2CoverageRecalculatorTest extends TestCase
{
    public function testItPrunesUnicodeRangesAndRecalculatesCharacterIndexes(): void
    {
        $os2 = Os2CoverageRecalculator::recalculate(self::os2(), [
            0x0041 => 1,
            0x2200 => 2,
            0x10300 => 3,
            0xA800 => 4,
        ]);
        $reader = new BinaryReader($os2, 'recalculated OS/2');

        self::assertSame([0x00000001, 0x02000040, 0x00200000, 0x00000010], [
            $reader->uint32(42),
            $reader->uint32(46),
            $reader->uint32(50),
            $reader->uint32(54),
        ]);
        self::assertSame(0x0041, $reader->uint16(64));
        self::assertSame(0xFFFF, $reader->uint16(66));
        self::assertSame("\xA5", $reader->string(90, 1));
    }

    public function testItDoesNotClaimFunctionalityAbsentFromTheSource(): void
    {
        $source = self::os2([0x00000002, 0, 0, 0]);
        $os2 = Os2CoverageRecalculator::recalculate($source, [0x0041 => 1]);
        $reader = new BinaryReader($os2, 'conservative OS/2');

        self::assertSame(0, $reader->uint32(42));
        self::assertSame(0x0041, $reader->uint16(64));
        self::assertSame(0x0041, $reader->uint16(66));
    }

    public function testItSetsBothNonBmpAndSpecificSupplementaryRangeBits(): void
    {
        $os2 = Os2CoverageRecalculator::recalculate(self::os2(), [0x20000 => 1]);
        $reader = new BinaryReader($os2, 'supplementary OS/2');

        self::assertSame((1 << (57 - 32)) | (1 << (59 - 32)), $reader->uint32(46));
        self::assertSame(0xFFFF, $reader->uint16(64));
        self::assertSame(0xFFFF, $reader->uint16(66));
    }

    public function testItClearsCoverageForAnEmptyCmap(): void
    {
        $os2 = Os2CoverageRecalculator::recalculate(self::os2(), []);
        $reader = new BinaryReader($os2, 'empty OS/2');

        self::assertSame([0, 0, 0, 0], [
            $reader->uint32(42),
            $reader->uint32(46),
            $reader->uint32(50),
            $reader->uint32(54),
        ]);
        self::assertSame([0xFFFF, 0xFFFF], [$reader->uint16(64), $reader->uint16(66)]);
    }

    #[DataProvider('supportedVersions')]
    public function testItSupportsEveryDefinedOs2Version(int $version): void
    {
        $os2 = Os2CoverageRecalculator::recalculate(self::os2(version: $version), [0x0041 => 1]);

        self::assertSame($version, (new BinaryReader($os2, 'versioned OS/2'))->uint16(0));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function supportedVersions(): iterable
    {
        for ($version = 0; $version <= 5; ++$version) {
            yield 'version ' . $version => [$version];
        }
    }

    public function testItRejectsATruncatedTable(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('truncated before its character coverage fields');

        Os2CoverageRecalculator::recalculate(str_repeat("\0", 67), []);
    }

    public function testItRejectsAnUnknownVersion(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported OS/2 table version 6');

        Os2CoverageRecalculator::recalculate(self::os2(version: 6), []);
    }

    public function testItRejectsAnInvalidCodepoint(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Invalid cmap codepoint U+110000');

        Os2CoverageRecalculator::recalculate(self::os2(), [0x110000 => 1]);
    }

    /**
     * @param array{int, int, int, int} $ranges
     */
    private static function os2(array $ranges = [0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF], int $version = 4): string
    {
        $os2 = str_repeat("\0", 96);
        $os2 = substr_replace($os2, pack('n', $version), 0, 2);

        foreach ($ranges as $index => $range) {
            $os2 = substr_replace($os2, pack('N', $range), 42 + $index * 4, 4);
        }

        return substr_replace($os2, "\xA5", 90, 1);
    }
}
