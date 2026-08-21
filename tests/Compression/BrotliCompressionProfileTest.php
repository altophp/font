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

namespace Alto\Font\Tests\Compression;

use Alto\Font\Compression\BrotliCompressionProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrotliCompressionProfile::class)]
final class BrotliCompressionProfileTest extends TestCase
{
    public function testItExposesTheExpectedCompressionLevels(): void
    {
        self::assertSame(5, BrotliCompressionProfile::Fast->value);
        self::assertSame(11, BrotliCompressionProfile::Maximum->value);
        self::assertSame(
            [BrotliCompressionProfile::Fast, BrotliCompressionProfile::Maximum],
            BrotliCompressionProfile::cases(),
        );
    }
}
