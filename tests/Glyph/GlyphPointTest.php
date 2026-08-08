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

namespace Alto\Font\Tests\Glyph;

use Alto\Font\Glyph\GlyphPoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphPoint::class)]
final class GlyphPointTest extends TestCase
{
    public function testItCreatesMidpointsOnCurve(): void
    {
        $midpoint = GlyphPoint::midpoint(
            new GlyphPoint(10.0, 20.0, false),
            new GlyphPoint(30.0, 60.0, false),
        );

        self::assertSame(20.0, $midpoint->x);
        self::assertSame(40.0, $midpoint->y);
        self::assertTrue($midpoint->onCurve);
    }
}
