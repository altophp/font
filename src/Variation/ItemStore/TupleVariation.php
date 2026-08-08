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
use Alto\Font\Variation\VariationDeltas;

final readonly class TupleVariation
{
    /**
     * @param list<int>|null $pointNumbers null means all points
     * @param list<int>      $xDeltas
     * @param list<int>      $yDeltas
     */
    public function __construct(
        public TupleRegion $region,
        public ?array $pointNumbers,
        public array $xDeltas,
        public array $yDeltas,
    ) {}

    public function deltas(int $pointCount, NormalizedCoordinates $coordinates, FontVariations $variations): VariationDeltas
    {
        $scalar = $this->region->scalar($coordinates, $variations);
        $deltas = VariationDeltas::zero($pointCount);

        if (0.0 === $scalar) {
            return $deltas;
        }

        $pointNumbers = $this->pointNumbers ?? range(0, $pointCount - 1);
        $x = $deltas->x;
        $y = $deltas->y;

        foreach ($pointNumbers as $index => $pointNumber) {
            if ($pointNumber >= $pointCount) {
                continue;
            }

            $x[$pointNumber] = ($this->xDeltas[$index] ?? 0) * $scalar;
            $y[$pointNumber] = ($this->yDeltas[$index] ?? 0) * $scalar;
        }

        return new VariationDeltas(array_values($x), array_values($y));
    }
}
