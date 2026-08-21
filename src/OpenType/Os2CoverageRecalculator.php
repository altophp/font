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

namespace Alto\Font\OpenType;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;

/**
 * Recalculates OS/2 Unicode and code page coverage.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class Os2CoverageRecalculator
{
    /**
     * OpenType 1.9.1 OS/2 Unicode range assignments.
     *
     * @var list<array{int, int, int}>
     */
    private const array UNICODE_RANGES = [
        [0x0000, 0x007F, 0], [0x0080, 0x00FF, 1], [0x0100, 0x017F, 2],
        [0x0180, 0x024F, 3], [0x0250, 0x02AF, 4], [0x1D00, 0x1D7F, 4],
        [0x1D80, 0x1DBF, 4], [0x02B0, 0x02FF, 5], [0xA700, 0xA71F, 5],
        [0x0300, 0x036F, 6], [0x1DC0, 0x1DFF, 6], [0x0370, 0x03FF, 7],
        [0x2C80, 0x2CFF, 8], [0x0400, 0x04FF, 9], [0x0500, 0x052F, 9],
        [0x2DE0, 0x2DFF, 9], [0xA640, 0xA69F, 9], [0x0530, 0x058F, 10],
        [0x0590, 0x05FF, 11], [0xA500, 0xA63F, 12], [0x0600, 0x06FF, 13],
        [0x0750, 0x077F, 13], [0x07C0, 0x07FF, 14], [0x0900, 0x097F, 15],
        [0x0980, 0x09FF, 16], [0x0A00, 0x0A7F, 17], [0x0A80, 0x0AFF, 18],
        [0x0B00, 0x0B7F, 19], [0x0B80, 0x0BFF, 20], [0x0C00, 0x0C7F, 21],
        [0x0C80, 0x0CFF, 22], [0x0D00, 0x0D7F, 23], [0x0E00, 0x0E7F, 24],
        [0x0E80, 0x0EFF, 25], [0x10A0, 0x10FF, 26], [0x2D00, 0x2D2F, 26],
        [0x1B00, 0x1B7F, 27], [0x1100, 0x11FF, 28], [0x1E00, 0x1EFF, 29],
        [0x2C60, 0x2C7F, 29], [0xA720, 0xA7FF, 29], [0x1F00, 0x1FFF, 30],
        [0x2000, 0x206F, 31], [0x2E00, 0x2E7F, 31], [0x2070, 0x209F, 32],
        [0x20A0, 0x20CF, 33], [0x20D0, 0x20FF, 34], [0x2100, 0x214F, 35],
        [0x2150, 0x218F, 36], [0x2190, 0x21FF, 37], [0x27F0, 0x27FF, 37],
        [0x2900, 0x297F, 37], [0x2B00, 0x2BFF, 37], [0x2200, 0x22FF, 38],
        [0x2A00, 0x2AFF, 38], [0x27C0, 0x27EF, 38], [0x2980, 0x29FF, 38],
        [0x2300, 0x23FF, 39], [0x2400, 0x243F, 40], [0x2440, 0x245F, 41],
        [0x2460, 0x24FF, 42], [0x2500, 0x257F, 43], [0x2580, 0x259F, 44],
        [0x25A0, 0x25FF, 45], [0x2600, 0x26FF, 46], [0x2700, 0x27BF, 47],
        [0x3000, 0x303F, 48], [0x3040, 0x309F, 49], [0x30A0, 0x30FF, 50],
        [0x31F0, 0x31FF, 50], [0x3100, 0x312F, 51], [0x31A0, 0x31BF, 51],
        [0x3130, 0x318F, 52], [0xA840, 0xA87F, 53], [0x3200, 0x32FF, 54],
        [0x3300, 0x33FF, 55], [0xAC00, 0xD7AF, 56], [0x10900, 0x1091F, 58],
        [0x4E00, 0x9FFF, 59], [0x2E80, 0x2EFF, 59], [0x2F00, 0x2FDF, 59],
        [0x2FF0, 0x2FFF, 59], [0x3400, 0x4DBF, 59], [0x20000, 0x2A6DF, 59],
        [0x3190, 0x319F, 59], [0xE000, 0xF8FF, 60], [0x31C0, 0x31EF, 61],
        [0xF900, 0xFAFF, 61], [0x2F800, 0x2FA1F, 61], [0xFB00, 0xFB4F, 62],
        [0xFB50, 0xFDFF, 63], [0xFE20, 0xFE2F, 64], [0xFE10, 0xFE1F, 65],
        [0xFE30, 0xFE4F, 65], [0xFE50, 0xFE6F, 66], [0xFE70, 0xFEFF, 67],
        [0xFF00, 0xFFEF, 68], [0xFFF0, 0xFFFF, 69], [0x0F00, 0x0FFF, 70],
        [0x0700, 0x074F, 71], [0x0780, 0x07BF, 72], [0x0D80, 0x0DFF, 73],
        [0x1000, 0x109F, 74], [0x1200, 0x137F, 75], [0x1380, 0x139F, 75],
        [0x2D80, 0x2DDF, 75], [0x13A0, 0x13FF, 76], [0x1400, 0x167F, 77],
        [0x1680, 0x169F, 78], [0x16A0, 0x16FF, 79], [0x1780, 0x17FF, 80],
        [0x19E0, 0x19FF, 80], [0x1800, 0x18AF, 81], [0x2800, 0x28FF, 82],
        [0xA000, 0xA48F, 83], [0xA490, 0xA4CF, 83], [0x1700, 0x171F, 84],
        [0x1720, 0x173F, 84], [0x1740, 0x175F, 84], [0x1760, 0x177F, 84],
        [0x10300, 0x1032F, 85], [0x10330, 0x1034F, 86], [0x10400, 0x1044F, 87],
        [0x1D000, 0x1D0FF, 88], [0x1D100, 0x1D1FF, 88], [0x1D200, 0x1D24F, 88],
        [0x1D400, 0x1D7FF, 89], [0xF0000, 0xFFFFD, 90], [0x100000, 0x10FFFD, 90],
        [0xFE00, 0xFE0F, 91], [0xE0100, 0xE01EF, 91], [0xE0000, 0xE007F, 92],
        [0x1900, 0x194F, 93], [0x1950, 0x197F, 94], [0x1980, 0x19DF, 95],
        [0x1A00, 0x1A1F, 96], [0x2C00, 0x2C5F, 97], [0x2D30, 0x2D7F, 98],
        [0x4DC0, 0x4DFF, 99], [0xA800, 0xA82F, 100], [0x10000, 0x1007F, 101],
        [0x10080, 0x100FF, 101], [0x10100, 0x1013F, 101], [0x10140, 0x1018F, 102],
        [0x10380, 0x1039F, 103], [0x103A0, 0x103DF, 104], [0x10450, 0x1047F, 105],
        [0x10480, 0x104AF, 106], [0x10800, 0x1083F, 107], [0x10A00, 0x10A5F, 108],
        [0x1D300, 0x1D35F, 109], [0x12000, 0x123FF, 110], [0x12400, 0x1247F, 110],
        [0x1D360, 0x1D37F, 111], [0x1B80, 0x1BBF, 112], [0x1C00, 0x1C4F, 113],
        [0x1C50, 0x1C7F, 114], [0xA880, 0xA8DF, 115], [0xA900, 0xA92F, 116],
        [0xA930, 0xA95F, 117], [0xAA00, 0xAA5F, 118], [0x10190, 0x101CF, 119],
        [0x101D0, 0x101FF, 120], [0x102A0, 0x102DF, 121], [0x10280, 0x1029F, 121],
        [0x10920, 0x1093F, 121], [0x1F030, 0x1F09F, 122], [0x1F000, 0x1F02F, 122],
    ];

    /**
     * Prunes source-declared functional ranges against the subset cmap.
     *
     * A mapped codepoint never enables a range that the source font did not
     * declare functional.
     *
     * @param array<int, int> $unicodeMappings
     */
    public static function recalculate(string $os2, array $unicodeMappings): string
    {
        if (\strlen($os2) < 68) {
            throw new InvalidFontException('SFNT OS/2 table is truncated before its character coverage fields.');
        }

        $reader = new BinaryReader($os2, 'OS/2 coverage recalculation');
        $version = $reader->uint16(0);

        if ($version > 5) {
            throw new InvalidFontException(\sprintf('Unsupported OS/2 table version %d.', $version));
        }

        $coveredRanges = [0, 0, 0, 0];
        $firstCodepoint = null;
        $lastCodepoint = null;

        foreach (array_keys($unicodeMappings) as $codepoint) {
            if ($codepoint < 0 || $codepoint > 0x10FFFF) {
                throw new InvalidFontException(\sprintf('Invalid cmap codepoint U+%X for OS/2 coverage.', $codepoint));
            }

            $firstCodepoint = null === $firstCodepoint ? $codepoint : min($firstCodepoint, $codepoint);
            $lastCodepoint = null === $lastCodepoint ? $codepoint : max($lastCodepoint, $codepoint);

            if ($codepoint > 0xFFFF) {
                $coveredRanges[1] |= 1 << (57 - 32);
            }

            foreach (self::UNICODE_RANGES as [$start, $end, $bit]) {
                if ($codepoint >= $start && $codepoint <= $end) {
                    $word = intdiv($bit, 32);
                    $coveredRanges[$word] |= 1 << ($bit % 32);
                }
            }
        }

        for ($word = 0; $word < 4; ++$word) {
            $sourceRanges = $reader->uint32(42 + $word * 4);
            $os2 = substr_replace($os2, pack('N', $sourceRanges & $coveredRanges[$word]), 42 + $word * 4, 4);
        }

        $firstIndex = null === $firstCodepoint || $firstCodepoint > 0xFFFF ? 0xFFFF : $firstCodepoint;
        $lastIndex = null === $lastCodepoint || $lastCodepoint > 0xFFFF ? 0xFFFF : $lastCodepoint;
        $os2 = substr_replace($os2, pack('n', $firstIndex), 64, 2);

        return substr_replace($os2, pack('n', $lastIndex), 66, 2);
    }
}
