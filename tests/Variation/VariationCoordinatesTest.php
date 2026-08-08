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
use Alto\Font\Variation\Table\AvarTable;
use Alto\Font\Variation\VariationAxis;
use Alto\Font\Variation\VariationCoordinates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariationCoordinates::class)]
final class VariationCoordinatesTest extends TestCase
{
    public function testItReadsValuesWithDefaultsAndClamping(): void
    {
        $variations = self::variations();
        $coordinates = new VariationCoordinates(['wght' => 1200]);

        self::assertSame(900.0, $coordinates->value('wght', $variations));
        self::assertSame(100.0, $coordinates->value('wdth', $variations));
    }

    public function testItCreatesDefaultsAndCopiesWithChangedValues(): void
    {
        $coordinates = VariationCoordinates::defaults(self::variations())->with('wght', 800.0);

        self::assertSame(['wght' => 800.0, 'wdth' => 100.0], $coordinates->values);
    }

    public function testItResolvesAllAxesAndRejectsUnknownOnes(): void
    {
        $resolved = (new VariationCoordinates(['wght' => 800]))->resolve(self::variations());

        self::assertSame(['wght' => 800.0, 'wdth' => 100.0], $resolved->values);

        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Variation axis "opsz" is not defined');

        (new VariationCoordinates(['opsz' => 14]))->resolve(self::variations());
    }

    public function testItNormalizesCoordinates(): void
    {
        $variations = self::variations();

        self::assertSame(-1.0, (new VariationCoordinates(['wght' => 100]))->normalized($variations)->value('wght'));
        self::assertSame(0.0, (new VariationCoordinates(['wght' => 400]))->normalized($variations)->value('wght'));
        self::assertSame(0.5, (new VariationCoordinates(['wght' => 650]))->normalized($variations)->value('wght'));
        self::assertSame(1.0, (new VariationCoordinates(['wght' => 1200]))->normalized($variations)->value('wght'));
        self::assertSame(-0.5, (new VariationCoordinates(['wdth' => 87.5]))->normalized($variations)->value('wdth'));
    }

    public function testItNormalizesDegenerateAxisRangesToZero(): void
    {
        $variations = new FontVariations([
            new VariationAxis('test', 400.0, 400.0, 400.0),
            new VariationAxis('lo', 400.0, 400.0, 900.0),
            new VariationAxis('hi', 100.0, 400.0, 400.0),
        ]);

        self::assertSame(0.0, (new VariationCoordinates(['test' => 300]))->normalized($variations)->value('test'));
        self::assertSame(0.0, (new VariationCoordinates(['test' => 500]))->normalized($variations)->value('test'));
        self::assertSame(0.0, (new VariationCoordinates(['lo' => 300]))->normalized($variations)->value('lo'));
        self::assertSame(0.0, (new VariationCoordinates(['hi' => 500]))->normalized($variations)->value('hi'));
    }

    public function testItAppliesAvarTablesToNormalizedCoordinates(): void
    {
        $variations = self::variations();
        $avar = new AvarTable([
            'wght' => [
                [-1.0, -1.0],
                [0.0, 0.0],
                [0.5, 0.75],
                [1.0, 1.0],
            ],
        ]);

        self::assertSame(0.375, (new VariationCoordinates(['wght' => 525]))->normalized($variations, $avar)->value('wght'));
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);
    }
}
