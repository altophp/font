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
use Alto\Font\Exception\UnsupportedFontException;

/**
 * Compacts vertical metrics and recalculates their header extrema.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class VerticalMetricsCompactor
{
    /**
     * @param list<int> $glyphOffsets
     *
     * @return array{vhea: string, vmtx: string}
     */
    public static function compact(
        string $vhea,
        string $sourceVmtx,
        string $glyf,
        array $glyphOffsets,
        GlyphIdMap $glyphIds,
    ): array {
        if (\strlen($vhea) < 36) {
            throw new InvalidFontException('SFNT vhea table is truncated.');
        }

        if (\count($glyphOffsets) !== \count($glyphIds) + 1) {
            throw new InvalidFontException('Compacted vertical metrics require one glyf offset per output glyph.');
        }

        $vheaReader = new BinaryReader($vhea, 'vhea glyph compaction');

        if (!\in_array($vheaReader->uint32(0), [0x00010000, 0x00011000], true)) {
            throw new UnsupportedFontException('Compact vertical metrics support vhea versions 1.0 and 1.1 only.');
        }

        if (0 !== $vheaReader->int16(32)) {
            throw new InvalidFontException('SFNT vhea metricDataFormat must be zero.');
        }

        $sourceMetricCount = $vheaReader->uint16(34);
        $sourceGlyphCount = $glyphIds->sourceGlyphCount();

        if (0 === $sourceMetricCount || $sourceMetricCount > $sourceGlyphCount) {
            throw new InvalidFontException('SFNT vhea numOfLongVerMetrics is invalid.');
        }

        $expectedLength = $sourceMetricCount * 4 + ($sourceGlyphCount - $sourceMetricCount) * 2;

        if (\strlen($sourceVmtx) !== $expectedLength) {
            throw new InvalidFontException('SFNT vmtx table length is inconsistent with vhea and maxp.');
        }

        $sourceReader = new BinaryReader($sourceVmtx, 'vmtx glyph compaction');
        $lastAdvanceHeight = $sourceReader->uint16(($sourceMetricCount - 1) * 4);
        $metrics = [];

        foreach ($glyphIds->pairs() as $oldGlyphId => $_newGlyphId) {
            if ($oldGlyphId < $sourceMetricCount) {
                $metrics[] = [
                    $sourceReader->uint16($oldGlyphId * 4),
                    $sourceReader->int16($oldGlyphId * 4 + 2),
                ];
            } else {
                $metrics[] = [
                    $lastAdvanceHeight,
                    $sourceReader->int16($sourceMetricCount * 4 + ($oldGlyphId - $sourceMetricCount) * 2),
                ];
            }
        }

        $metricCount = self::longMetricCount($metrics);
        $vmtx = '';

        foreach ($metrics as $glyphId => [$advanceHeight, $topSideBearing]) {
            if ($glyphId < $metricCount) {
                $vmtx .= self::uint16($advanceHeight);
            }

            $vmtx .= self::int16($topSideBearing);
        }

        $advanceHeightMax = 0;
        $minTopSideBearing = null;
        $minBottomSideBearing = null;
        $yMaxExtent = null;

        foreach ($metrics as $glyphId => [$advanceHeight, $topSideBearing]) {
            $advanceHeightMax = max($advanceHeightMax, $advanceHeight);
            $start = $glyphOffsets[$glyphId];
            $end = $glyphOffsets[$glyphId + 1];

            if ($start < 0 || $end < $start || $end > \strlen($glyf)) {
                throw new InvalidFontException(\sprintf('Glyph ID %d has invalid glyf offsets.', $glyphId));
            }

            if ($start === $end) {
                continue;
            }

            $glyph = new BinaryReader(substr($glyf, $start, $end - $start), \sprintf('glyf glyph %d vertical metrics', $glyphId));
            $height = $glyph->int16(8) - $glyph->int16(4);
            $bottomSideBearing = $advanceHeight - $topSideBearing - $height;
            $extent = $topSideBearing + $height;
            $minTopSideBearing = null === $minTopSideBearing ? $topSideBearing : min($minTopSideBearing, $topSideBearing);
            $minBottomSideBearing = null === $minBottomSideBearing ? $bottomSideBearing : min($minBottomSideBearing, $bottomSideBearing);
            $yMaxExtent = null === $yMaxExtent ? $extent : max($yMaxExtent, $extent);
        }

        $vhea = substr_replace($vhea, self::uint16($advanceHeightMax), 10, 2);
        $vhea = substr_replace($vhea, self::int16($minTopSideBearing ?? 0), 12, 2);
        $vhea = substr_replace($vhea, self::int16($minBottomSideBearing ?? 0), 14, 2);
        $vhea = substr_replace($vhea, self::int16($yMaxExtent ?? 0), 16, 2);
        $vhea = substr_replace($vhea, self::uint16($metricCount), 34, 2);

        return ['vhea' => $vhea, 'vmtx' => $vmtx];
    }

    /**
     * @param list<array{int, int}> $metrics
     */
    private static function longMetricCount(array $metrics): int
    {
        if ([] === $metrics) {
            throw new \LogicException('Vertical metric compaction requires at least one glyph.');
        }

        $lastAdvanceHeight = $metrics[array_key_last($metrics)][0];

        for ($glyphId = \count($metrics) - 2; $glyphId >= 0; --$glyphId) {
            if ($metrics[$glyphId][0] !== $lastAdvanceHeight) {
                return $glyphId + 2;
            }
        }

        return 1;
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
