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
use Alto\Font\Subset\HintingPolicy;
use Alto\Font\Subset\LayoutPolicy;
use Alto\Font\Subset\SubsetOptions;
use Alto\Font\Subset\UnicodeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubsetOptions::class)]
final class SubsetOptionsTest extends TestCase
{
    public function testItUsesConservativeDefaults(): void
    {
        $unicodes = UnicodeSet::fromCodepoints([0x41]);
        $options = new SubsetOptions($unicodes);

        self::assertSame($unicodes, $options->unicodes);
        self::assertSame(HintingPolicy::Keep, $options->hinting);
        self::assertSame(GlyphIdPolicy::Preserve, $options->glyphIds);
        self::assertSame(LayoutPolicy::Preserve, $options->layout);
    }

    public function testItRetainsExplicitPolicies(): void
    {
        $unicodes = UnicodeSet::fromCodepoints([0x41, 0x42]);
        $options = new SubsetOptions(
            $unicodes,
            HintingPolicy::Drop,
            GlyphIdPolicy::Compact,
            LayoutPolicy::Drop,
        );

        self::assertSame($unicodes, $options->unicodes);
        self::assertSame(HintingPolicy::Drop, $options->hinting);
        self::assertSame(GlyphIdPolicy::Compact, $options->glyphIds);
        self::assertSame(LayoutPolicy::Drop, $options->layout);
    }
}
