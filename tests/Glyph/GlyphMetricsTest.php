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

use Alto\Font\Glyph\GlyphId;
use Alto\Font\Glyph\GlyphMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphMetrics::class)]
final class GlyphMetricsTest extends TestCase
{
    public function testItStoresGlyphMetrics(): void
    {
        $glyphId = new GlyphId(3);
        $metrics = new GlyphMetrics($glyphId, advanceWidth: 600, leftSideBearing: -10);

        self::assertSame($glyphId, $metrics->glyphId);
        self::assertSame(600, $metrics->advanceWidth);
        self::assertSame(-10, $metrics->leftSideBearing);
    }
}
