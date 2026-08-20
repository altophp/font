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
 * Removes TrueType instructions and hinting metadata.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class TrueTypeHintingStripper
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;
    private const int WE_HAVE_INSTRUCTIONS = 0x0100;

    public static function stripGlyph(string $glyph): string
    {
        if ('' === $glyph) {
            return '';
        }

        $reader = new BinaryReader($glyph, 'TrueType glyph hint stripping');
        $contourCount = $reader->int16(0);

        if ($contourCount >= 0) {
            return self::stripSimpleGlyph($glyph, $reader, $contourCount);
        }

        return self::stripCompoundGlyph($glyph, $reader);
    }

    public static function stripMaxp(string $maxp): string
    {
        if (\strlen($maxp) < 6) {
            throw new InvalidFontException('SFNT maxp table is truncated.');
        }

        if (\strlen($maxp) < 32) {
            return $maxp;
        }

        $maxp = substr_replace($maxp, "\0\1", 14, 2);

        foreach ([16, 18, 20, 22, 24, 26] as $offset) {
            $maxp = substr_replace($maxp, "\0\0", $offset, 2);
        }

        return $maxp;
    }

    private static function stripSimpleGlyph(string $glyph, BinaryReader $reader, int $contourCount): string
    {
        $instructionLengthOffset = 10 + $contourCount * 2;
        $instructionLength = $reader->uint16($instructionLengthOffset);
        $instructionsEnd = $instructionLengthOffset + 2 + $instructionLength;

        if ($instructionsEnd > \strlen($glyph)) {
            throw new InvalidFontException('TrueType simple glyph instructions exceed the glyph bounds.');
        }

        return substr($glyph, 0, $instructionLengthOffset)
            . "\0\0"
            . substr($glyph, $instructionsEnd);
    }

    private static function stripCompoundGlyph(string $glyph, BinaryReader $reader): string
    {
        $cursor = 10;
        $flagOffsets = [];

        do {
            $flagOffsets[] = $cursor;
            $flags = $reader->uint16($cursor);
            $cursor += 4;
            $cursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;

            if (0 !== ($flags & self::WE_HAVE_A_SCALE)) {
                $cursor += 2;
            } elseif (0 !== ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE)) {
                $cursor += 4;
            } elseif (0 !== ($flags & self::WE_HAVE_A_TWO_BY_TWO)) {
                $cursor += 8;
            }

            if ($cursor > \strlen($glyph)) {
                throw new InvalidFontException('TrueType compound glyph components exceed the glyph bounds.');
            }
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        foreach ($flagOffsets as $flagOffset) {
            $componentFlags = $reader->uint16($flagOffset) & ~self::WE_HAVE_INSTRUCTIONS;
            $glyph = substr_replace($glyph, self::uint16($componentFlags), $flagOffset, 2);
        }

        if (0 === ($flags & self::WE_HAVE_INSTRUCTIONS)) {
            return $glyph;
        }

        $instructionLength = $reader->uint16($cursor);
        $instructionsEnd = $cursor + 2 + $instructionLength;

        if ($instructionsEnd > \strlen($glyph)) {
            throw new InvalidFontException('TrueType compound glyph instructions exceed the glyph bounds.');
        }

        return substr($glyph, 0, $cursor) . substr($glyph, $instructionsEnd);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }
}
