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

namespace Alto\Font\Text;

use Alto\Font\Exception\InvalidFontException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class UnicodeString
{
    /**
     * @return list<int>
     */
    public static function codepoints(string $text): array
    {
        if (1 !== preg_match('//u', $text)) {
            throw new InvalidFontException('Text must be valid UTF-8.');
        }

        $codepoints = [];
        $i = 0;
        $length = \strlen($text);

        while ($i < $length) {
            $byte = \ord($text[$i]);

            if ($byte < 0x80) {
                $codepoints[] = $byte;
                ++$i;
                continue;
            }

            if (($byte & 0xE0) === 0xC0) {
                $codepoints[] = (($byte & 0x1F) << 6) | (\ord($text[$i + 1]) & 0x3F);
                $i += 2;
                continue;
            }

            if (($byte & 0xF0) === 0xE0) {
                $codepoints[] = (($byte & 0x0F) << 12) | ((\ord($text[$i + 1]) & 0x3F) << 6) | (\ord($text[$i + 2]) & 0x3F);
                $i += 3;
                continue;
            }

            $codepoints[] = (($byte & 0x07) << 18) | ((\ord($text[$i + 1]) & 0x3F) << 12) | ((\ord($text[$i + 2]) & 0x3F) << 6) | (\ord($text[$i + 3]) & 0x3F);
            $i += 4;
        }

        return $codepoints;
    }
}
