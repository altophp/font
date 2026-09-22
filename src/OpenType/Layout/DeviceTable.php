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

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Exception\UnsupportedFontException;

/**
 * Copies and relocates Device and VariationIndex tables used by layout values.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class DeviceTable
{
    public static function copy(BinaryReader $reader, int $offset): string
    {
        $startSize = $reader->uint16($offset);
        $endSize = $reader->uint16($offset + 2);
        $format = $reader->uint16($offset + 4);

        if (0x8000 === $format) {
            return $reader->string($offset, 6);
        }

        if (!\in_array($format, [1, 2, 3], true) || $endSize < $startSize) {
            throw new InvalidFontException('OpenType device table is invalid.');
        }

        $bitsPerValue = 1 << $format;
        $wordCount = intdiv(($endSize - $startSize + 1) * $bitsPerValue + 15, 16);

        return $reader->string($offset, 6 + $wordCount * 2);
    }

    /**
     * @param list<array{offset: int, base: int, data: string}> $devices
     */
    public static function append(string $table, array $devices): string
    {
        $offsetsByData = [];

        foreach ($devices as $device) {
            $deviceOffset = $offsetsByData[$device['data']] ?? null;

            if (null === $deviceOffset) {
                $deviceOffset = \strlen($table);
                $offsetsByData[$device['data']] = $deviceOffset;
                $table .= $device['data'];
            }

            $relativeOffset = $deviceOffset - $device['base'];

            if ($relativeOffset < 0 || $relativeOffset > 0xFFFF) {
                throw new UnsupportedFontException('Compacted device data exceeds a 16-bit OpenType offset.');
            }

            $table = substr_replace($table, pack('n', $relativeOffset), $device['offset'], 2);
        }

        return $table;
    }
}
