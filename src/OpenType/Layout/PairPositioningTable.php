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

namespace Alto\Font\OpenType\Layout;

use Alto\Font\Exception\UnsupportedFontException;

/**
 * Serializes explicit glyph pairs, including class-pair fallback records.
 *
 * @internal
 */
final readonly class PairPositioningTable
{
    // Leave room for a single-PairSet header before the trailing Coverage table.
    private const int MAX_PAIR_SET_LENGTH = 0xFFFF - 12;

    /**
     * Device patch offsets are relative to the combined value records, excluding
     * secondGlyphId. Device offsets in the resulting binary are PairSet-relative.
     *
     * @param iterable<array{secondGlyphId: int, data: string, devices: list<array{offset: int, base: int, data: string}>}> $pairs
     *
     * @return list<string>
     */
    public static function splitRecords(iterable $pairs, int $lookupIndex): array
    {
        $sets = [];
        $records = '';
        $recordCount = 0;
        $devices = [];
        $deviceData = [];
        $deviceDataLength = 0;

        foreach ($pairs as $pair) {
            $record = pack('n', $pair['secondGlyphId']) . $pair['data'];
            $newDeviceData = [];

            foreach ($pair['devices'] as $device) {
                if (!isset($deviceData[$device['data']])) {
                    $newDeviceData[$device['data']] = \strlen($device['data']);
                }
            }

            $candidateLength = 2 + \strlen($records) + \strlen($record)
                + $deviceDataLength + array_sum($newDeviceData);

            if ($candidateLength > self::MAX_PAIR_SET_LENGTH && 0 !== $recordCount) {
                $sets[] = DeviceTable::append(pack('n', $recordCount) . $records, $devices);
                $records = '';
                $recordCount = 0;
                $devices = [];
                $deviceData = [];
                $deviceDataLength = 0;
            }

            $recordOffset = 4 + \strlen($records);
            $records .= $record;
            ++$recordCount;

            foreach ($pair['devices'] as $device) {
                $devices[] = [
                    'offset' => $recordOffset + $device['offset'],
                    'base' => $device['base'],
                    'data' => $device['data'],
                ];

                if (!isset($deviceData[$device['data']])) {
                    $deviceData[$device['data']] = true;
                    $deviceDataLength += \strlen($device['data']);
                }
            }

            if (2 + \strlen($records) + $deviceDataLength > self::MAX_PAIR_SET_LENGTH) {
                throw new UnsupportedFontException(\sprintf(
                    'GPOS lookup %d contains a pair record that exceeds PairPos format 1 limits.',
                    $lookupIndex,
                ));
            }
        }

        if (0 !== $recordCount) {
            $sets[] = DeviceTable::append(pack('n', $recordCount) . $records, $devices);
        }

        return $sets;
    }

    /**
     * @param list<int>    $firstGlyphs
     * @param list<string> $pairSets
     *
     * @return non-empty-list<string>
     */
    public static function buildSubtables(array $firstGlyphs, array $pairSets, int $valueFormat1, int $valueFormat2): array
    {
        $subtables = [];
        $groupGlyphs = [];
        $groupPairSets = [];
        $groupDataLength = 0;

        foreach ($pairSets as $index => $pairSet) {
            $nextCoverageOffset = 10 + (\count($groupPairSets) + 1) * 2 + $groupDataLength + \strlen($pairSet);
            // Chunks of the same first glyph must stay in distinct subtables:
            // a miss in one PairSet must allow the following chunk to match.
            if ([] !== $groupPairSets && ($nextCoverageOffset > 0xFFFF || end($groupGlyphs) === $firstGlyphs[$index])) {
                $subtables[] = self::build($groupGlyphs, $groupPairSets, $valueFormat1, $valueFormat2);
                $groupGlyphs = [];
                $groupPairSets = [];
                $groupDataLength = 0;
            }

            $groupGlyphs[] = $firstGlyphs[$index];
            $groupPairSets[] = $pairSet;
            $groupDataLength += \strlen($pairSet);
        }

        $subtables[] = self::build($groupGlyphs, $groupPairSets, $valueFormat1, $valueFormat2);

        return $subtables;
    }

    /**
     * @param list<int>    $firstGlyphs
     * @param list<string> $pairSets
     */
    public static function build(array $firstGlyphs, array $pairSets, int $valueFormat1, int $valueFormat2): string
    {
        $cursor = 10 + \count($pairSets) * 2;
        $offsets = '';

        foreach ($pairSets as $pairSet) {
            $offsets .= self::offset16($cursor);
            $cursor += \strlen($pairSet);
        }

        return pack('n', 1) . self::offset16($cursor)
            . pack('n3', $valueFormat1, $valueFormat2, \count($pairSets))
            . $offsets . implode('', $pairSets) . CoverageTable::build($firstGlyphs);
    }

    private static function offset16(int $value): string
    {
        if ($value > 0xFFFF) {
            throw new UnsupportedFontException('Compacted PairPos data exceeds a 16-bit OpenType offset.');
        }

        return pack('n', $value);
    }
}
