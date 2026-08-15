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
final readonly class SfntMetricsRecalculator
{
    /**
     * @param list<int>        $glyphOffsets
     * @param array<int, true> $retainedGlyphs
     *
     * @return array{head: string, hhea: string}
     */
    public static function recalculate(
        string $head,
        string $hhea,
        string $hmtx,
        string $glyf,
        array $glyphOffsets,
        array $retainedGlyphs,
    ): array {
        if (\strlen($head) < 54) {
            throw new InvalidFontException('SFNT head table is truncated.');
        }

        if (\strlen($hhea) < 36) {
            throw new InvalidFontException('SFNT hhea table is truncated.');
        }

        $hheaReader = new BinaryReader($hhea, 'hhea metrics recalculation');
        $numberOfHMetrics = $hheaReader->uint16(34);

        if (0 === $numberOfHMetrics || $numberOfHMetrics > \count($glyphOffsets) - 1) {
            throw new InvalidFontException('SFNT hhea numberOfHMetrics is invalid.');
        }

        $globalBounds = null;
        $advanceWidthMax = 0;
        $minLeftSideBearing = null;
        $minRightSideBearing = null;
        $xMaxExtent = null;

        $glyphCount = \count($glyphOffsets) - 1;

        for ($glyphId = 0; $glyphId < $glyphCount; ++$glyphId) {
            $start = $glyphOffsets[$glyphId] ?? null;
            $end = $glyphOffsets[$glyphId + 1] ?? null;

            if (null === $start || null === $end || $start < 0 || $end < $start || $end > \strlen($glyf)) {
                throw new InvalidFontException(\sprintf('Glyph ID %d has invalid glyf offsets.', $glyphId));
            }

            [$advanceWidth, $leftSideBearing] = self::horizontalMetric($hmtx, $glyphId, $numberOfHMetrics);
            $advanceWidthMax = max($advanceWidthMax, $advanceWidth);

            if (isset($retainedGlyphs[$glyphId]) && $start !== $end) {
                $glyph = new BinaryReader(substr($glyf, $start, $end - $start), \sprintf('glyf glyph %d metrics', $glyphId));
                $xMin = $glyph->int16(2);
                $yMin = $glyph->int16(4);
                $xMax = $glyph->int16(6);
                $yMax = $glyph->int16(8);
                $globalBounds = null === $globalBounds
                    ? [$xMin, $yMin, $xMax, $yMax]
                    : [
                        min($globalBounds[0], $xMin),
                        min($globalBounds[1], $yMin),
                        max($globalBounds[2], $xMax),
                        max($globalBounds[3], $yMax),
                    ];
                $width = $xMax - $xMin;
                $rightSideBearing = $advanceWidth - $leftSideBearing - $width;
                $extent = $leftSideBearing + $width;
                $minLeftSideBearing = null === $minLeftSideBearing ? $leftSideBearing : min($minLeftSideBearing, $leftSideBearing);
                $minRightSideBearing = null === $minRightSideBearing ? $rightSideBearing : min($minRightSideBearing, $rightSideBearing);
                $xMaxExtent = null === $xMaxExtent ? $extent : max($xMaxExtent, $extent);
            }
        }

        $globalBounds ??= [0, 0, 0, 0];
        $head = substr_replace($head, self::int16($globalBounds[0]), 36, 2);
        $head = substr_replace($head, self::int16($globalBounds[1]), 38, 2);
        $head = substr_replace($head, self::int16($globalBounds[2]), 40, 2);
        $head = substr_replace($head, self::int16($globalBounds[3]), 42, 2);
        $hhea = substr_replace($hhea, self::uint16($advanceWidthMax), 10, 2);
        $hhea = substr_replace($hhea, self::int16($minLeftSideBearing ?? 0), 12, 2);
        $hhea = substr_replace($hhea, self::int16($minRightSideBearing ?? 0), 14, 2);
        $hhea = substr_replace($hhea, self::int16($xMaxExtent ?? 0), 16, 2);

        return ['head' => $head, 'hhea' => $hhea];
    }

    /**
     * @return array{int, int}
     */
    private static function horizontalMetric(string $hmtx, int $glyphId, int $numberOfHMetrics): array
    {
        $reader = new BinaryReader($hmtx, 'hmtx metrics recalculation');

        if ($glyphId < $numberOfHMetrics) {
            return [$reader->uint16($glyphId * 4), $reader->int16($glyphId * 4 + 2)];
        }

        $advanceWidth = $reader->uint16(($numberOfHMetrics - 1) * 4);
        $leftSideBearingOffset = $numberOfHMetrics * 4 + ($glyphId - $numberOfHMetrics) * 2;

        return [$advanceWidth, $reader->int16($leftSideBearingOffset)];
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function int16(int $value): string
    {
        if ($value < -32768 || $value > 32767) {
            throw new InvalidFontException(\sprintf('SFNT metric value %d exceeds int16 bounds.', $value));
        }

        return pack('n', $value & 0xFFFF);
    }
}
