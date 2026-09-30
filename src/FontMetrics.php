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

namespace Alto\Font;

use Alto\Font\Geometry\BoundingBox;

/**
 * Declared default-instance metrics. Distances are unscaled font units.
 * Optional values are null when their source table or metric is absent.
 */
final readonly class FontMetrics
{
    public function __construct(
        public int $unitsPerEm,
        public int $ascender,
        public int $descender,
        public int $lineGap,
        public BoundingBox $bounds,
        public ?int $capHeight = null,
        public ?int $xHeight = null,
        public ?float $italicAngle = null,
        public ?bool $isFixedPitch = null,
        public ?int $embeddingFlags = null,
    ) {}
}
