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

namespace Alto\Font\Descriptor;

enum FontStyle: string
{
    case Normal = 'normal';
    case Italic = 'italic';
    case Oblique = 'oblique';

    public static function fromSubfamily(string $subfamily): self
    {
        $normalized = strtolower($subfamily);

        if (str_contains($normalized, 'italic')) {
            return self::Italic;
        }

        if (str_contains($normalized, 'oblique')) {
            return self::Oblique;
        }

        return self::Normal;
    }
}
