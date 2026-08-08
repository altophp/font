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

namespace Alto\Font\Tests\Variation;

use Alto\Font\Variation\VariationDeltas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariationDeltas::class)]
final class VariationDeltasTest extends TestCase
{
    public function testItCreatesZeroDeltas(): void
    {
        $deltas = VariationDeltas::zero(3);

        self::assertSame([0.0, 0.0, 0.0], $deltas->x);
        self::assertSame([0.0, 0.0, 0.0], $deltas->y);
    }

    public function testItAddsDeltas(): void
    {
        $deltas = new VariationDeltas([1.0, 2.0], [3.0, 4.0]);

        $sum = $deltas->add(new VariationDeltas([5.0, 6.0], [7.0, 8.0]));

        self::assertSame([6.0, 8.0], $sum->x);
        self::assertSame([10.0, 12.0], $sum->y);
    }
}
