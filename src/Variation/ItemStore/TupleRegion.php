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

namespace Alto\Font\Variation\ItemStore;

use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\NormalizedCoordinates;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TupleRegion
{
    /**
     * @param list<float> $start
     * @param list<float> $peak
     * @param list<float> $end
     */
    public function __construct(
        public array $start,
        public array $peak,
        public array $end,
    ) {}

    /**
     * @param list<float> $peak
     */
    public static function fromPeak(array $peak): self
    {
        $start = [];
        $end = [];

        foreach ($peak as $value) {
            if ($value < 0.0) {
                $start[] = -1.0;
                $end[] = 0.0;
                continue;
            }

            if ($value > 0.0) {
                $start[] = 0.0;
                $end[] = 1.0;
                continue;
            }

            $start[] = 0.0;
            $end[] = 0.0;
        }

        return new self($start, $peak, $end);
    }

    public function scalar(NormalizedCoordinates $coordinates, FontVariations $variations): float
    {
        $scalar = 1.0;

        foreach ($variations->axes as $index => $axis) {
            $peak = $this->peak[$index] ?? 0.0;

            if (0.0 === $peak) {
                continue;
            }

            $start = $this->start[$index] ?? 0.0;
            $end = $this->end[$index] ?? 0.0;

            if ($start > $peak || $peak > $end) {
                return 0.0;
            }

            $value = $coordinates->value($axis->tag);

            if ($value === $peak) {
                continue;
            }

            if ($value <= $start || $value >= $end) {
                return 0.0;
            }

            if ($value < $peak) {
                $scalar *= ($value - $start) / ($peak - $start);
                continue;
            }

            $scalar *= ($end - $value) / ($end - $peak);
        }

        return $scalar;
    }
}
