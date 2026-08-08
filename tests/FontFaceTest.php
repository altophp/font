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

namespace Alto\Font\Tests;

use Alto\Font\FontFace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontFace::class)]
final class FontFaceTest extends TestCase
{
    public function testItExposesNamesByNameId(): void
    {
        $face = new FontFace('/tmp/font.ttf', 1000, 800, -200, 12, ['name'], [
            1 => 'Family',
            2 => 'Regular',
        ]);

        self::assertSame('Family', $face->name(1));
        self::assertNull($face->name(4));
    }
}
