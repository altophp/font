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

use Alto\Font\Variation\NormalizedCoordinates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizedCoordinates::class)]
final class NormalizedCoordinatesTest extends TestCase
{
    public function testItReturnsKnownValuesAndZeroForMissingAxes(): void
    {
        $coordinates = new NormalizedCoordinates(['wght' => 0.75]);

        self::assertSame(0.75, $coordinates->value('wght'));
        self::assertSame(0.0, $coordinates->value('wdth'));
    }
}
