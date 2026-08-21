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
 * Stores point deltas produced by font variations.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class VariationDeltas
{
    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    public function __construct(
        public array $x,
        public array $y,
    ) {}

    public static function zero(int $pointCount): self
    {
        return new self(
            array_fill(0, $pointCount, 0.0),
            array_fill(0, $pointCount, 0.0),
        );
    }

    public function add(self $other): self
    {
        $x = $this->x;
        $y = $this->y;

        foreach ($other->x as $index => $delta) {
            $x[$index] += $delta;
        }

        foreach ($other->y as $index => $delta) {
            $y[$index] += $delta;
        }

        return new self(array_values($x), array_values($y));
    }
}
