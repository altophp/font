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
use Alto\Font\Variation\NormalizedCoordinates;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TupleRegion::class)]
final class TupleRegionTest extends TestCase
{
    public function testItCreatesImplicitRegionsFromPeakTuples(): void
    {
        $region = TupleRegion::fromPeak([1.0, -1.0, 0.0]);

        self::assertSame([0.0, -1.0, 0.0], $region->start);
        self::assertSame([1.0, -1.0, 0.0], $region->peak);
        self::assertSame([1.0, 0.0, 0.0], $region->end);
    }

    public function testItComputesScalarsForPeakAndIntermediateCoordinates(): void
    {
        $variations = self::variations();
        $region = TupleRegion::fromPeak([1.0, -1.0]);

        self::assertSame(1.0, $region->scalar(new NormalizedCoordinates(['wght' => 1.0, 'wdth' => -1.0]), $variations));
        self::assertSame(0.25, $region->scalar(new NormalizedCoordinates(['wght' => 0.5, 'wdth' => -0.5]), $variations));
        self::assertSame(0.0, $region->scalar(new NormalizedCoordinates(['wght' => -0.5, 'wdth' => -0.5]), $variations));
    }

    public function testItIgnoresZeroPeakAxesWhenComputingScalars(): void
    {
        $region = TupleRegion::fromPeak([0.0]);

        self::assertSame(1.0, $region->scalar(new NormalizedCoordinates(['wght' => -1.0]), new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
        ])));
    }

    public function testItRejectsInvalidRegionsWithZeroScalar(): void
    {
        $region = new TupleRegion([1.0], [0.5], [0.0]);

        self::assertSame(0.0, $region->scalar(new NormalizedCoordinates(['wght' => 0.5]), new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
        ])));
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);
    }
}
