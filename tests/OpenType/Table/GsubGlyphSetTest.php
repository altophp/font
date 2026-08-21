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

namespace Alto\Font\Tests\OpenType\Table;

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Table\GsubGlyphSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GsubGlyphSet::class)]
final class GsubGlyphSetTest extends TestCase
{
    public function testExplicitSetsContainOnlyTheirMembersAndIntersectRetainedGlyphs(): void
    {
        $set = GsubGlyphSet::explicit([2, 4, 4]);

        self::assertTrue($set->contains(2));
        self::assertTrue($set->contains(4));
        self::assertFalse($set->contains(3));
        self::assertTrue($set->intersects([1 => true, 4 => true]));
        self::assertFalse($set->intersects([1 => true, 3 => true]));
    }

    public function testAClassCanBeRestrictedToTheLookupCoverage(): void
    {
        $set = GsubGlyphSet::fromClass(
            [2 => 1, 3 => 1, 4 => 2],
            1,
            [2, 4, 5],
        );

        self::assertTrue($set->contains(2));
        self::assertFalse($set->contains(3));
        self::assertFalse($set->contains(4));
        self::assertFalse($set->contains(5));
    }

    public function testClassZeroIncludesUnassignedGlyphsWithoutEnumeratingTheFont(): void
    {
        $set = GsubGlyphSet::fromClass([2 => 1, 3 => 0, 5 => 2], 0);

        self::assertTrue($set->contains(0));
        self::assertTrue($set->contains(3));
        self::assertTrue($set->contains(4));
        self::assertFalse($set->contains(2));
        self::assertFalse($set->contains(5));
    }

    public function testAClassWithoutCoverageContainsEveryExplicitClassMember(): void
    {
        $set = GsubGlyphSet::fromClass([2 => 1, 3 => 2, 4 => 1], 1);

        self::assertTrue($set->contains(2));
        self::assertFalse($set->contains(3));
        self::assertTrue($set->contains(4));
    }

    public function testItRejectsAnExplicitGlyphOutsideTheFont(): void
    {
        $set = GsubGlyphSet::explicit([5]);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('invalid glyph ID 5 for a font with 5 glyphs');

        $set->assertValid(5);
    }

    public function testItRejectsAnExcludedClassZeroGlyphOutsideTheFont(): void
    {
        $set = GsubGlyphSet::fromClass([5 => 1], 0);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('invalid glyph ID 5 for a font with 5 glyphs');

        $set->assertValid(5);
    }

    public function testItAcceptsMembersAtBothGlyphIdBoundaries(): void
    {
        GsubGlyphSet::explicit([0, 4])->assertValid(5);

        $this->addToAssertionCount(1);
    }
}
