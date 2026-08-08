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

namespace Alto\Font\Tests\Variation;

use Alto\Font\Variation\VariationInstance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariationInstance::class)]
final class VariationInstanceTest extends TestCase
{
    public function testItStoresNamedInstanceData(): void
    {
        $instance = new VariationInstance('Bold Condensed', ['wght' => 800.0, 'wdth' => 75.0], 'Family-BoldCondensed');

        self::assertSame('Bold Condensed', $instance->subfamilyName);
        self::assertSame(['wght' => 800.0, 'wdth' => 75.0], $instance->coordinates);
        self::assertSame('Family-BoldCondensed', $instance->postScriptName);
    }
}
