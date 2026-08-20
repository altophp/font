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

namespace Alto\Font\Tests\Subset;

use Alto\Font\Subset\GlyphIdPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphIdPolicy::class)]
final class GlyphIdPolicyTest extends TestCase
{
    public function testItExposesTheSupportedPolicies(): void
    {
        self::assertSame([GlyphIdPolicy::Preserve, GlyphIdPolicy::Compact], GlyphIdPolicy::cases());
    }
}
