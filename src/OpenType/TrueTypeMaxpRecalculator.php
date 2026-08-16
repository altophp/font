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
 * @internal
 */
final readonly class TrueTypeMaxpRecalculator
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;
    private const int WE_HAVE_INSTRUCTIONS = 0x0100;

    private const int X_SHORT_VECTOR = 0x02;
    private const int Y_SHORT_VECTOR = 0x04;
    private const int REPEAT_FLAG = 0x08;
    private const int X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR = 0x10;
    private const int Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR = 0x20;

    /**
     * @param list<int>        $glyphOffsets
     * @param array<int, true> $retainedGlyphs
     */
    public static function recalculate(
        string $maxp,
        string $glyf,
        array $glyphOffsets,
        array $retainedGlyphs,
    ): string {
        if (\strlen($maxp) < 32) {
            throw new InvalidFontException('TrueType maxp version 1.0 table is truncated.');
        }

        $reader = new BinaryReader($maxp, 'maxp geometry recalculation');

        if (0x00010000 !== $reader->uint32(0)) {
            throw new InvalidFontException('TrueType outlines require maxp version 1.0.');
        }

        $glyphCount = \count($glyphOffsets) - 1;

        if ($glyphCount < 1 || $glyphCount > 0xFFFF) {
            throw new InvalidFontException('TrueType glyph offsets do not define a valid glyph count.');
        }

        foreach ($retainedGlyphs as $glyphId => $_retained) {
            if ($glyphId < 0 || $glyphId >= $glyphCount) {
                throw new InvalidFontException(\sprintf('Retained glyph ID %d is outside the maxp glyph range.', $glyphId));
            }
        }

        /** @var array<int, array{points: int, contours: int, composite: bool, components: int, depth: int, instructions: int}> $profiles */
        $profiles = [];
        /** @var array<int, true> $visiting */
        $visiting = [];
        $maxPoints = 0;
        $maxContours = 0;
        $maxCompositePoints = 0;
        $maxCompositeContours = 0;
        $maxSizeOfInstructions = 0;
        $maxComponentElements = 0;
        $maxComponentDepth = 0;

        foreach (array_keys($retainedGlyphs) as $glyphId) {
            $profile = self::glyphProfile(
                $glyf,
                $glyphOffsets,
                $retainedGlyphs,
                $glyphId,
                $profiles,
                $visiting,
            );
            $maxSizeOfInstructions = max($maxSizeOfInstructions, $profile['instructions']);

            if ($profile['composite']) {
                $maxCompositePoints = max($maxCompositePoints, $profile['points']);
                $maxCompositeContours = max($maxCompositeContours, $profile['contours']);
                $maxComponentElements = max($maxComponentElements, $profile['components']);
                $maxComponentDepth = max($maxComponentDepth, $profile['depth']);
            } else {
                $maxPoints = max($maxPoints, $profile['points']);
                $maxContours = max($maxContours, $profile['contours']);
            }
        }

        foreach ([
            4 => $glyphCount,
            6 => $maxPoints,
            8 => $maxContours,
            10 => $maxCompositePoints,
            12 => $maxCompositeContours,
            26 => $maxSizeOfInstructions,
            28 => $maxComponentElements,
            30 => $maxComponentDepth,
        ] as $offset => $value) {
            $maxp = substr_replace($maxp, self::uint16($value), $offset, 2);
        }

        return $maxp;
    }

    /**
     * @param list<int>                                           $glyphOffsets
     * @param array<int, true>                                    $retainedGlyphs
     * @param array<int, array{points: int, contours: int, composite: bool, components: int, depth: int, instructions: int}> $profiles
     * @param array<int, true>                                    $visiting
     *
     * @return array{points: int, contours: int, composite: bool, components: int, depth: int, instructions: int}
     */
    private static function glyphProfile(
        string $glyf,
        array $glyphOffsets,
        array $retainedGlyphs,
        int $glyphId,
        array &$profiles,
        array &$visiting,
    ): array {
        if (isset($profiles[$glyphId])) {
            return $profiles[$glyphId];
        }

        if (isset($visiting[$glyphId])) {
            throw new InvalidFontException(\sprintf('Compound glyph cycle detected at glyph ID %d.', $glyphId));
        }

        [$start, $end] = self::glyphBounds($glyf, $glyphOffsets, $glyphId);

        if ($start === $end) {
            return $profiles[$glyphId] = [
                'points' => 0,
                'contours' => 0,
                'composite' => false,
                'components' => 0,
                'depth' => 0,
                'instructions' => 0,
            ];
        }

        $glyph = new BinaryReader(substr($glyf, $start, $end - $start), \sprintf('glyf glyph %d maxp recalculation', $glyphId));
        $contourCount = $glyph->int16(0);

        if ($contourCount >= 0) {
            $profile = self::simpleGlyphProfile($glyph, $contourCount);
            return $profiles[$glyphId] = $profile;
        }

        $visiting[$glyphId] = true;

        try {
            $profile = self::compositeGlyphProfile(
                $glyph,
                $glyf,
                $glyphOffsets,
                $retainedGlyphs,
                $glyphId,
                $profiles,
                $visiting,
            );
        } finally {
            unset($visiting[$glyphId]);
        }

        return $profiles[$glyphId] = $profile;
    }

    /**
     * @return array{points: int, contours: int, composite: false, components: 0, depth: 0, instructions: int}
     */
    private static function simpleGlyphProfile(BinaryReader $glyph, int $contourCount): array
    {
        $cursor = 10;
        $pointCount = 0;
        $previousEndPoint = -1;

        for ($contour = 0; $contour < $contourCount; ++$contour) {
            $endPoint = $glyph->uint16($cursor);

            if ($endPoint <= $previousEndPoint) {
                throw new InvalidFontException('TrueType simple glyph contour endpoints are not strictly increasing.');
            }

            $previousEndPoint = $endPoint;
            $pointCount = $endPoint + 1;
            $cursor += 2;
        }

        $instructionLength = $glyph->uint16($cursor);
        $cursor += 2 + $instructionLength;

        if ($cursor > $glyph->length()) {
            throw new InvalidFontException('TrueType simple glyph instructions exceed the glyph bounds.');
        }

        $flags = [];

        while (\count($flags) < $pointCount) {
            $flag = $glyph->uint8($cursor++);
            $repeat = 1;

            if (0 !== ($flag & self::REPEAT_FLAG)) {
                $repeat += $glyph->uint8($cursor++);
            }

            if (\count($flags) + $repeat > $pointCount) {
                throw new InvalidFontException('TrueType simple glyph flag repetitions exceed its point count.');
            }

            for ($index = 0; $index < $repeat; ++$index) {
                $flags[] = $flag;
            }
        }

        foreach ($flags as $flag) {
            $cursor += 0 !== ($flag & self::X_SHORT_VECTOR)
                ? 1
                : (0 !== ($flag & self::X_IS_SAME_OR_POSITIVE_X_SHORT_VECTOR) ? 0 : 2);
        }

        foreach ($flags as $flag) {
            $cursor += 0 !== ($flag & self::Y_SHORT_VECTOR)
                ? 1
                : (0 !== ($flag & self::Y_IS_SAME_OR_POSITIVE_Y_SHORT_VECTOR) ? 0 : 2);
        }

        if ($cursor > $glyph->length()) {
            throw new InvalidFontException('TrueType simple glyph coordinates exceed the glyph bounds.');
        }

        return [
            'points' => $pointCount,
            'contours' => $contourCount,
            'composite' => false,
            'components' => 0,
            'depth' => 0,
            'instructions' => $instructionLength,
        ];
    }

    /**
     * @param list<int>                                           $glyphOffsets
     * @param array<int, true>                                    $retainedGlyphs
     * @param array<int, array{points: int, contours: int, composite: bool, components: int, depth: int, instructions: int}> $profiles
     * @param array<int, true>                                    $visiting
     *
     * @return array{points: int, contours: int, composite: true, components: int, depth: int, instructions: int}
     */
    private static function compositeGlyphProfile(
        BinaryReader $glyph,
        string $glyf,
        array $glyphOffsets,
        array $retainedGlyphs,
        int $glyphId,
        array &$profiles,
        array &$visiting,
    ): array {
        $cursor = 10;
        $points = 0;
        $contours = 0;
        $components = 0;
        $depth = 0;

        do {
            $flags = $glyph->uint16($cursor);
            $componentGlyphId = $glyph->uint16($cursor + 2);
            $cursor += 4;
            ++$components;

            if (!isset($retainedGlyphs[$componentGlyphId])) {
                throw new InvalidFontException(\sprintf(
                    'Compound glyph %d references discarded glyph ID %d.',
                    $glyphId,
                    $componentGlyphId,
                ));
            }

            $transformFlags = $flags & (self::WE_HAVE_A_SCALE | self::WE_HAVE_AN_X_AND_Y_SCALE | self::WE_HAVE_A_TWO_BY_TWO);

            if (0 !== $transformFlags && 0 !== ($transformFlags & ($transformFlags - 1))) {
                throw new InvalidFontException('TrueType compound glyph uses conflicting transform flags.');
            }

            $cursor += 0 !== ($flags & self::ARG_1_AND_2_ARE_WORDS) ? 4 : 2;
            $cursor += match ($transformFlags) {
                self::WE_HAVE_A_SCALE => 2,
                self::WE_HAVE_AN_X_AND_Y_SCALE => 4,
                self::WE_HAVE_A_TWO_BY_TWO => 8,
                default => 0,
            };

            if ($cursor > $glyph->length()) {
                throw new InvalidFontException('TrueType compound glyph components exceed the glyph bounds.');
            }

            $component = self::glyphProfile(
                $glyf,
                $glyphOffsets,
                $retainedGlyphs,
                $componentGlyphId,
                $profiles,
                $visiting,
            );
            $points += $component['points'];
            $contours += $component['contours'];
            $depth = max($depth, $component['depth'] + 1);

            if ($points > 0xFFFF || $contours > 0xFFFF) {
                throw new InvalidFontException('TrueType compound glyph geometry exceeds maxp uint16 bounds.');
            }
        } while (0 !== ($flags & self::MORE_COMPONENTS));

        $instructionLength = 0;

        if (0 !== ($flags & self::WE_HAVE_INSTRUCTIONS)) {
            $instructionLength = $glyph->uint16($cursor);
            $cursor += 2 + $instructionLength;

            if ($cursor > $glyph->length()) {
                throw new InvalidFontException('TrueType compound glyph instructions exceed the glyph bounds.');
            }
        }

        return [
            'points' => $points,
            'contours' => $contours,
            'composite' => true,
            'components' => $components,
            'depth' => $depth,
            'instructions' => $instructionLength,
        ];
    }

    /**
     * @param list<int> $glyphOffsets
     *
     * @return array{int, int}
     */
    private static function glyphBounds(string $glyf, array $glyphOffsets, int $glyphId): array
    {
        $start = $glyphOffsets[$glyphId] ?? null;
        $end = $glyphOffsets[$glyphId + 1] ?? null;

        if (null === $start || null === $end || $start < 0 || $end < $start || $end > \strlen($glyf)) {
            throw new InvalidFontException(\sprintf('Glyph ID %d has invalid glyf offsets.', $glyphId));
        }

        return [$start, $end];
    }

    private static function uint16(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new InvalidFontException(\sprintf('TrueType maxp value %d exceeds uint16 bounds.', $value));
        }

        return pack('n', $value);
    }
}
