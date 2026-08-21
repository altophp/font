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

/**
 * Builds OpenType character mapping tables.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class CmapBuilder
{
    /**
     * @param array<int, int> $mappings
     */
    public static function build(array $mappings): string
    {
        ksort($mappings, \SORT_NUMERIC);
        $format4 = self::format4($mappings);
        $format12 = self::format12($mappings);

        if (null === $format4) {
            $offset = 20;

            return self::uint16(0)
                . self::uint16(2)
                . self::record(0, 4, $offset)
                . self::record(3, 10, $offset)
                . $format12;
        }

        $format4Offset = 36;
        $format12Offset = $format4Offset + \strlen($format4);

        return self::uint16(0)
            . self::uint16(4)
            . self::record(0, 3, $format4Offset)
            . self::record(3, 1, $format4Offset)
            . self::record(0, 4, $format12Offset)
            . self::record(3, 10, $format12Offset)
            . $format4
            . $format12;
    }

    /**
     * @param array<int, int> $mappings
     */
    private static function format4(array $mappings): ?string
    {
        /** @var list<array{start: int, end: int, glyphStart: int, glyphEnd: int}> $segments */
        $segments = [];

        foreach ($mappings as $codepoint => $glyphId) {
            if ($codepoint < 0 || $codepoint >= 0xFFFF || $glyphId < 0 || $glyphId > 0xFFFF) {
                continue;
            }

            $last = \count($segments) - 1;

            if ($last >= 0
                && $codepoint === $segments[$last]['end'] + 1
                && $glyphId === $segments[$last]['glyphEnd'] + 1
            ) {
                $previous = $segments[$last];
                $segments[$last] = [
                    'start' => $previous['start'],
                    'end' => $codepoint,
                    'glyphStart' => $previous['glyphStart'],
                    'glyphEnd' => $glyphId,
                ];
                continue;
            }

            $segments[] = [
                'start' => $codepoint,
                'end' => $codepoint,
                'glyphStart' => $glyphId,
                'glyphEnd' => $glyphId,
            ];
        }

        $segments[] = ['start' => 0xFFFF, 'end' => 0xFFFF, 'glyphStart' => 0, 'glyphEnd' => 0];
        $segCount = \count($segments);
        $length = 16 + $segCount * 8;

        if ($length > 0xFFFF) {
            return null;
        }

        $maxPowerOfTwo = 1;
        $entrySelector = 0;

        while ($maxPowerOfTwo * 2 <= $segCount) {
            $maxPowerOfTwo *= 2;
            ++$entrySelector;
        }

        $endCodes = '';
        $startCodes = '';
        $deltas = '';
        $rangeOffsets = '';

        foreach ($segments as $segment) {
            $endCodes .= self::uint16($segment['end']);
            $startCodes .= self::uint16($segment['start']);
            $deltas .= self::uint16(($segment['glyphStart'] - $segment['start']) & 0xFFFF);
            $rangeOffsets .= self::uint16(0);
        }

        return self::uint16(4)
            . self::uint16($length)
            . self::uint16(0)
            . self::uint16($segCount * 2)
            . self::uint16($maxPowerOfTwo * 2)
            . self::uint16($entrySelector)
            . self::uint16($segCount * 2 - $maxPowerOfTwo * 2)
            . $endCodes
            . self::uint16(0)
            . $startCodes
            . $deltas
            . $rangeOffsets;
    }

    /**
     * @param array<int, int> $mappings
     */
    private static function format12(array $mappings): string
    {
        /** @var list<array{start: int, end: int, glyphStart: int, glyphEnd: int}> $groups */
        $groups = [];

        foreach ($mappings as $codepoint => $glyphId) {
            if ($codepoint < 0 || $codepoint > 0x10FFFF || $glyphId < 0) {
                continue;
            }

            $last = \count($groups) - 1;

            if ($last >= 0
                && $codepoint === $groups[$last]['end'] + 1
                && $glyphId === $groups[$last]['glyphEnd'] + 1
            ) {
                $previous = $groups[$last];
                $groups[$last] = [
                    'start' => $previous['start'],
                    'end' => $codepoint,
                    'glyphStart' => $previous['glyphStart'],
                    'glyphEnd' => $glyphId,
                ];
                continue;
            }

            $groups[] = [
                'start' => $codepoint,
                'end' => $codepoint,
                'glyphStart' => $glyphId,
                'glyphEnd' => $glyphId,
            ];
        }

        $data = '';

        foreach ($groups as $group) {
            $data .= self::uint32($group['start'])
                . self::uint32($group['end'])
                . self::uint32($group['glyphStart']);
        }

        return self::uint16(12)
            . self::uint16(0)
            . self::uint32(16 + \strlen($data))
            . self::uint32(0)
            . self::uint32(\count($groups))
            . $data;
    }

    private static function record(int $platformId, int $encodingId, int $offset): string
    {
        return self::uint16($platformId) . self::uint16($encodingId) . self::uint32($offset);
    }

    private static function uint16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function uint32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
