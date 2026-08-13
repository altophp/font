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

namespace Alto\Font\Glyph;

use Alto\Font\Exception\InvalidFontException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class GlyphId
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new InvalidFontException(\sprintf('Glyph ID must be zero or positive, got %d.', $value));
        }
    }
}
