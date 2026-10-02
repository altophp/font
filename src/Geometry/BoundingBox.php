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

namespace Alto\Font\Geometry;

/**
 * Unscaled bounds declared by the font, not a tight rasterized ink rectangle.
 */
final readonly class BoundingBox
{
    public function __construct(
        public int $xMin,
        public int $yMin,
        public int $xMax,
        public int $yMax,
    ) {}
}
