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

use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariationAxis::class)]
final class VariationAxisTest extends TestCase
{
    public function testItClampsValuesToAxisRange(): void
    {
        $axis = new VariationAxis('wght', 100.0, 400.0, 900.0);

        self::assertSame(100.0, $axis->clamp(50.0));
        self::assertSame(500.0, $axis->clamp(500.0));
        self::assertSame(900.0, $axis->clamp(1200.0));
    }

    public function testItReportsHiddenAxes(): void
    {
        self::assertTrue(new VariationAxis('opsz', 8.0, 14.0, 72.0, VariationAxis::HIDDEN_AXIS)->isHidden());
        self::assertFalse(new VariationAxis('wght', 100.0, 400.0, 900.0)->isHidden());
    }
}
