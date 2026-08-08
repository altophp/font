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

namespace Alto\Font\Tests\Variation\ItemStore;

use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\ItemStore\TupleRegion;
use Alto\Font\Variation\ItemStore\TupleVariation;
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TupleVariation::class)]
final class TupleVariationTest extends TestCase
{
    public function testItAppliesAllPointDeltasWithScalar(): void
    {
        $variation = new TupleVariation(TupleRegion::fromPeak([1.0]), null, [10, 20], [30, 40]);

        $deltas = $variation->deltas(2, new NormalizedCoordinates(['wght' => 0.5]), self::variations());

        self::assertSame([5.0, 10.0], $deltas->x);
        self::assertSame([15.0, 20.0], $deltas->y);
    }

    public function testItAppliesSparseDeltas(): void
    {
        $variation = new TupleVariation(TupleRegion::fromPeak([1.0]), [1, 3], [10, 20], [30, 40]);

        $deltas = $variation->deltas(4, new NormalizedCoordinates(['wght' => 1.0]), self::variations());

        self::assertSame([0.0, 10.0, 0.0, 20.0], $deltas->x);
        self::assertSame([0.0, 30.0, 0.0, 40.0], $deltas->y);
    }

    public function testItReturnsZeroDeltasWhenTheRegionDoesNotApply(): void
    {
        $variation = new TupleVariation(TupleRegion::fromPeak([1.0]), null, [10, 20], [30, 40]);

        $deltas = $variation->deltas(2, new NormalizedCoordinates(['wght' => 0.0]), self::variations());

        self::assertSame([0.0, 0.0], $deltas->x);
        self::assertSame([0.0, 0.0], $deltas->y);
    }

    public function testItIgnoresSparsePointsOutsideTheGlyphPointCount(): void
    {
        $variation = new TupleVariation(TupleRegion::fromPeak([1.0]), [1, 3], [10, 20], [30, 40]);

        $deltas = $variation->deltas(2, new NormalizedCoordinates(['wght' => 1.0]), self::variations());

        self::assertSame([0.0, 10.0], $deltas->x);
        self::assertSame([0.0, 30.0], $deltas->y);
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([new VariationAxis('wght', 100.0, 400.0, 900.0)]);
    }
}
