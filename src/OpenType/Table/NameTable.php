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

namespace Alto\Font\OpenType\Table;

use Alto\Font\Binary\BinaryReader;

/**
 * Parses names from an OpenType name table.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class NameTable
{
    /**
     * @return array<int, string>
     */
    public static function parse(BinaryReader $reader): array
    {
        $count = $reader->uint16(2);
        $stringOffset = $reader->uint16(4);
        $names = [];

        for ($i = 0; $i < $count; ++$i) {
            $recordOffset = 6 + $i * 12;
            $platformId = $reader->uint16($recordOffset);
            $encodingId = $reader->uint16($recordOffset + 2);
            $nameId = $reader->uint16($recordOffset + 6);
            $length = $reader->uint16($recordOffset + 8);
            $offset = $reader->uint16($recordOffset + 10);
            $raw = $reader->string($stringOffset + $offset, $length);

            $decoded = self::decode($raw, $platformId, $encodingId);

            if (null !== $decoded && !isset($names[$nameId])) {
                $names[$nameId] = $decoded;
            }
        }

        ksort($names);

        return $names;
    }

    private static function decode(string $raw, int $platformId, int $encodingId): ?string
    {
        if (3 === $platformId || (0 === $platformId && $encodingId <= 4)) {
            $decoded = iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);

            return \is_string($decoded) ? $decoded : null;
        }

        if (1 === $platformId && 0 === $encodingId) {
            return $raw;
        }

        return null;
    }
}
