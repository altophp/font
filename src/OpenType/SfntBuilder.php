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

use Alto\Font\Exception\InvalidFontException;

/**
 * Builds standalone SFNT data from font tables.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class SfntBuilder
{
    public static function withChecksumAdjustment(SfntDocument $document): SfntDocument
    {
        $head = $document->table('head');

        if (null === $head || \strlen($head) < 12) {
            throw new InvalidFontException('SFNT head table is truncated.');
        }

        $head = substr_replace($head, "\0\0\0\0", 8, 4);
        $document = $document->withTables(['head' => $head]);
        $tags = $document->tableTags();
        [$searchRange, $entrySelector, $rangeShift] = self::searchParameters(\count($tags));
        $sfntOffset = 12 + \count($tags) * 16;
        $records = '';
        $tableChecksum = 0;

        foreach ($tags as $tag) {
            $table = $document->table($tag);

            if (null === $table) {
                throw new InvalidFontException(\sprintf('SFNT table "%s" disappeared while calculating checksums.', $tag));
            }

            $checksum = SfntChecksum::calculate($table);
            $tableChecksum = ($tableChecksum + $checksum) & 0xFFFFFFFF;
            $records .= $tag
                . self::uint32($checksum)
                . self::uint32($sfntOffset)
                . self::uint32(\strlen($table));
            $sfntOffset += \strlen($table) + (4 - \strlen($table) % 4) % 4;
        }

        $headerAndRecords = $document->flavor
            . self::uint16(\count($tags))
            . self::uint16($searchRange)
            . self::uint16($entrySelector)
            . self::uint16($rangeShift)
            . $records;
        $fontChecksum = (SfntChecksum::calculate($headerAndRecords) + $tableChecksum) & 0xFFFFFFFF;
        $adjustment = (0xB1B0AFBA - $fontChecksum) & 0xFFFFFFFF;

        return $document->withTables([
            'head' => substr_replace($head, self::uint32($adjustment), 8, 4),
        ]);
    }

    /**
     * @param array<string, string> $tables
     */
    public static function build(string $flavor, array $tables): string
    {
        if (4 !== \strlen($flavor)) {
            throw new InvalidFontException('SFNT flavor must contain exactly 4 bytes.');
        }

        if ([] === $tables) {
            throw new InvalidFontException('SFNT must contain at least one table.');
        }

        if (isset($tables['head'])) {
            if (\strlen($tables['head']) < 12) {
                throw new InvalidFontException('SFNT head table is truncated.');
            }

            $tables['head'] = substr_replace($tables['head'], "\0\0\0\0", 8, 4);
        }

        ksort($tables);
        $numTables = \count($tables);
        [$searchRange, $entrySelector, $rangeShift] = self::searchParameters($numTables);
        $sfntOffset = 12 + $numTables * 16;
        $records = '';
        $tableChecksum = 0;

        foreach ($tables as $tag => $table) {
            if (4 !== \strlen($tag)) {
                throw new InvalidFontException(\sprintf('SFNT table tag "%s" must contain exactly 4 bytes.', $tag));
            }

            $checksum = SfntChecksum::calculate($table);
            $tableChecksum = ($tableChecksum + $checksum) & 0xFFFFFFFF;
            $records .= $tag
                . self::uint32($checksum)
                . self::uint32($sfntOffset)
                . self::uint32(\strlen($table));
            $sfntOffset += \strlen($table) + (4 - \strlen($table) % 4) % 4;
        }

        $headerAndRecords = $flavor
            . self::uint16($numTables)
            . self::uint16($searchRange)
            . self::uint16($entrySelector)
            . self::uint16($rangeShift)
            . $records;

        if (isset($tables['head'])) {
            $fontChecksum = (SfntChecksum::calculate($headerAndRecords) + $tableChecksum) & 0xFFFFFFFF;
            $adjustment = (0xB1B0AFBA - $fontChecksum) & 0xFFFFFFFF;
            $tables['head'] = substr_replace($tables['head'], self::uint32($adjustment), 8, 4);
        }

        $sfnt = $headerAndRecords;

        foreach ($tables as $table) {
            $sfnt .= self::pad4($table);
        }

        return $sfnt;
    }

    /**
     * @return array{int, int, int}
     */
    private static function searchParameters(int $numTables): array
    {
        $maxPowerOfTwo = 1;
        $entrySelector = 0;

        while ($maxPowerOfTwo * 2 <= $numTables) {
            $maxPowerOfTwo *= 2;
            ++$entrySelector;
        }

        $searchRange = $maxPowerOfTwo * 16;

        return [$searchRange, $entrySelector, $numTables * 16 - $searchRange];
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private static function pad4(string $data): string
    {
        return $data . str_repeat("\0", (4 - \strlen($data) % 4) % 4);
    }
}
