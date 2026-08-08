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

use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontVariations::class)]
final class FontVariationsTest extends TestCase
{
    public function testItIndexesAxesAndBuildsDefaultCoordinates(): void
    {
        $variations = new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);

        self::assertTrue($variations->hasAxis('wght'));
        self::assertFalse($variations->hasAxis('opsz'));
        self::assertSame('wdth', $variations->axis('wdth')->tag);
        self::assertSame(['wght' => 400.0, 'wdth' => 100.0], $variations->defaults()->values);
    }

    public function testItRejectsUnknownAxes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Variation axis "opsz" is not defined');

        (new FontVariations([new VariationAxis('wght', 100.0, 400.0, 900.0)]))->axis('opsz');
    }

    public function testItRejectsDuplicateAxes(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Duplicate variation axis "wght".');

        new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wght', 100.0, 400.0, 900.0),
        ]);
    }

    public function testItRejectsInvalidAxisRanges(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Variation axis "wght" has invalid min/default/max values.');

        new FontVariations([new VariationAxis('wght', 900.0, 400.0, 100.0)]);
    }
}
