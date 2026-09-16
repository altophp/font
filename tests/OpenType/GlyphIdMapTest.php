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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\GlyphIdMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphIdMap::class)]
final class GlyphIdMapTest extends TestCase
{
    public function testItMapsRetainedGlyphsInSourceOrder(): void
    {
        $mapping = GlyphIdMap::fromRetained(8, [5 => true, 2 => true, 7 => true]);

        self::assertCount(4, $mapping);
        self::assertSame(8, $mapping->sourceGlyphCount());
        self::assertSame(0, $mapping->newId(0));
        self::assertSame(1, $mapping->newId(2));
        self::assertSame(2, $mapping->newId(5));
        self::assertSame(3, $mapping->newId(7));
        self::assertNull($mapping->newId(1));
        self::assertNull($mapping->newId(8));
        self::assertSame(5, $mapping->oldId(2));
        self::assertSame([0 => 0, 2 => 1, 5 => 2, 7 => 3], iterator_to_array($mapping->pairs()));
    }

    public function testItRejectsAnInvalidSourceGlyphCount(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('source glyph count must be between 1 and 65535');

        GlyphIdMap::fromRetained(0, []);
    }

    public function testItRejectsARetainedGlyphOutsideTheSourceFont(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Retained glyph ID 8 is outside the source font.');

        GlyphIdMap::fromRetained(8, [8 => true]);
    }

    public function testItRejectsAnUnknownSubsetGlyphId(): void
    {
        $mapping = GlyphIdMap::fromRetained(8, [2 => true]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Subset glyph ID 2 does not exist.');

        $mapping->oldId(2);
    }
}
