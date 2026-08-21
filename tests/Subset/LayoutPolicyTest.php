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

use Alto\Font\Subset\LayoutPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LayoutPolicy::class)]
final class LayoutPolicyTest extends TestCase
{
    public function testItExposesTheSupportedPolicies(): void
    {
        self::assertSame(
            [LayoutPolicy::Preserve, LayoutPolicy::SubstitutionsOnly, LayoutPolicy::Drop],
            LayoutPolicy::cases(),
        );
    }
}
