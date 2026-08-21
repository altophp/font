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
use Alto\Font\OpenType\Woff2KnownTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Woff2KnownTags::class)]
final class Woff2KnownTagsTest extends TestCase
{
    public function testItMapsKnownTagsInBothDirections(): void
    {
        self::assertSame(0, Woff2KnownTags::indexOf('cmap'));
        self::assertSame('glyf', Woff2KnownTags::at(10));
        self::assertNull(Woff2KnownTags::indexOf('TEST'));
    }

    public function testItRejectsAnInvalidKnownTagIndex(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('tag index 63 is invalid');

        Woff2KnownTags::at(63);
    }
}
