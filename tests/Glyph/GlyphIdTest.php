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

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Glyph\GlyphId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphId::class)]
final class GlyphIdTest extends TestCase
{
    public function testItAcceptsZeroAndPositiveValues(): void
    {
        self::assertSame(0, (new GlyphId(0))->value);
        self::assertSame(12, (new GlyphId(12))->value);
    }

    public function testItRejectsNegativeValues(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Glyph ID must be zero or positive');

        new GlyphId(-1);
    }
}
