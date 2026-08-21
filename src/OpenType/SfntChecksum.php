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
 * Calculates OpenType table checksums.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @internal
 */
final readonly class SfntChecksum
{
    public static function calculate(string $data): int
    {
        $sum = 0;

        for ($offset = 0, $length = \strlen($data); $offset < $length; $offset += 262144) {
            $chunk = substr($data, $offset, min(262144, $length - $offset));
            $words = unpack('N*', self::pad4($chunk));

            if (!\is_array($words)) {
                throw new InvalidFontException('Could not calculate SFNT checksum.');
            }

            foreach ($words as $value) {
                if (!\is_int($value)) {
                    throw new InvalidFontException('Could not calculate SFNT checksum.');
                }

                $sum = ($sum + $value) & 0xFFFFFFFF;
            }
        }

        return $sum;
    }

    private static function pad4(string $data): string
    {
        return $data . str_repeat("\0", (4 - \strlen($data) % 4) % 4);
    }
}
