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

use Alto\Font\Glyph\Contour;
use Alto\Font\Glyph\GlyphId;
use Alto\Font\Glyph\GlyphOutline;
use Alto\Font\Glyph\PathCommand;
use Alto\Font\Tests\Fixtures\ContourAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphOutline::class)]
final class GlyphOutlineTest extends TestCase
{
    use ContourAssertions;

    public function testItReportsEmptyOutlines(): void
    {
        self::assertTrue(new GlyphOutline(new GlyphId(0), [])->isEmpty());
    }

    public function testItTransformsContours(): void
    {
        $outline = new GlyphOutline(new GlyphId(1), [
            new Contour([PathCommand::lineTo(10.0, 20.0)]),
        ]);

        $transformed = $outline->transform(1.0, 0.0, 0.0, 1.0, 5.0, 10.0);

        self::assertFalse($transformed->isEmpty());
        self::assertSame('L 15 30', self::describeContour($transformed->contours[0]));
    }
}
