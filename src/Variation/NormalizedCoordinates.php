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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class NormalizedCoordinates
{
    /**
     * @param array<string, float> $values
     */
    public function __construct(public array $values) {}

    public function value(string $axisTag): float
    {
        return $this->values[$axisTag] ?? 0.0;
    }
}
