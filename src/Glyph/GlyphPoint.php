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

final readonly class GlyphPoint
{
    public function __construct(
        public float $x,
        public float $y,
        public bool $onCurve,
    ) {}

    public static function midpoint(self $a, self $b): self
    {
        return new self(($a->x + $b->x) / 2.0, ($a->y + $b->y) / 2.0, true);
    }
}
