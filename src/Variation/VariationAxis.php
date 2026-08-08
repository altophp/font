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

namespace Alto\Font\Variation;

final readonly class VariationAxis
{
    public const int HIDDEN_AXIS = 0x0001;

    public function __construct(
        public string $tag,
        public float $minimum,
        public float $default,
        public float $maximum,
        public int $flags = 0,
        public ?string $name = null,
    ) {}

    public function clamp(float $value): float
    {
        return max($this->minimum, min($this->maximum, $value));
    }

    public function isHidden(): bool
    {
        return 0 !== ($this->flags & self::HIDDEN_AXIS);
    }
}
