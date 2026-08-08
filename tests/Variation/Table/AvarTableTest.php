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

namespace Alto\Font\Tests\Variation\Table;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\Variation\FontVariations;
use Alto\Font\Variation\Table\AvarTable;
use Alto\Font\Variation\VariationAxis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AvarTable::class)]
final class AvarTableTest extends TestCase
{
    public function testItMapsCoordinatesPiecewise(): void
    {
        $map = new AvarTable([
            'wght' => [
                [-1.0, -1.0],
                [0.0, 0.0],
                [0.5, 0.75],
                [1.0, 1.0],
            ],
        ]);

        self::assertSame(-1.0, $map->map('wght', -1.0));
        self::assertSame(0.375, $map->map('wght', 0.25));
        self::assertSame(0.875, $map->map('wght', 0.75));
        self::assertSame(0.5, $map->map('wdth', 0.5));
    }

    public function testItFallsBackToTheLastMappedValueAfterTheLastSegment(): void
    {
        $map = new AvarTable([
            'wght' => [
                [-1.0, -1.0],
                [0.0, 0.0],
            ],
        ]);

        self::assertSame(0.0, $map->map('wght', 0.5));
    }

    public function testItFallsBackToTheFirstMappedValueBeforeTheFirstSegment(): void
    {
        $map = new AvarTable([
            'wght' => [
                [0.0, 0.0],
                [1.0, 1.0],
            ],
        ]);

        self::assertSame(0.0, $map->map('wght', -0.5));
    }

    public function testItParsesAvarTables(): void
    {
        $map = AvarTable::parse(new BinaryReader(
            self::u16(1) . self::u16(0) . self::u16(0) . self::u16(2)
            . self::u16(4)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(0.5) . self::f2dot14(0.75)
            . self::f2dot14(1.0) . self::f2dot14(1.0)
            . self::u16(3)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(1.0) . self::f2dot14(1.0),
            'avar',
        ), self::variations());

        self::assertSame(0.375, $map->map('wght', 0.25));
        self::assertSame(0.5, $map->map('wdth', 0.5));
    }

    public function testItIgnoresInvalidMapsWithoutRequiredAnchors(): void
    {
        $map = AvarTable::parse(new BinaryReader(
            self::u16(1) . self::u16(0) . self::u16(0) . self::u16(1)
            . self::u16(2)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0)
            . self::f2dot14(1.0) . self::f2dot14(1.0),
            'avar',
        ), new FontVariations([new VariationAxis('wght', 100.0, 400.0, 900.0)]));

        self::assertSame(0.25, $map->map('wght', 0.25));
    }

    public function testItIgnoresInvalidMapsWithNonIncreasingCoordinates(): void
    {
        $map = AvarTable::parse(new BinaryReader(
            self::u16(1) . self::u16(0) . self::u16(0) . self::u16(1)
            . self::u16(4)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0)
            . self::f2dot14(0.0) . self::f2dot14(0.0)
            . self::f2dot14(0.0) . self::f2dot14(0.5)
            . self::f2dot14(1.0) . self::f2dot14(1.0),
            'avar',
        ), new FontVariations([new VariationAxis('wght', 100.0, 400.0, 900.0)]));

        self::assertSame(0.25, $map->map('wght', 0.25));
    }

    public function testItIgnoresInvalidMapsWithDecreasingOutputCoordinates(): void
    {
        $map = AvarTable::parse(new BinaryReader(
            self::u16(1) . self::u16(0) . self::u16(0) . self::u16(1)
            . self::u16(4)
            . self::f2dot14(-1.0) . self::f2dot14(-1.0)
            . self::f2dot14(0.0) . self::f2dot14(0.5)
            . self::f2dot14(0.5) . self::f2dot14(0.25)
            . self::f2dot14(1.0) . self::f2dot14(1.0),
            'avar',
        ), new FontVariations([new VariationAxis('wght', 100.0, 400.0, 900.0)]));

        self::assertSame(0.25, $map->map('wght', 0.25));
    }

    public function testItRejectsUnsupportedVersions(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('Unsupported avar table version 2.0.');

        AvarTable::parse(new BinaryReader(self::u16(2) . self::u16(0) . self::u16(0) . self::u16(0), 'avar'), self::variations());
    }

    public function testItRejectsAxisCountMismatches(): void
    {
        $this->expectException(InvalidFontException::class);
        $this->expectExceptionMessage('avar axis count 1 does not match fvar axis count 2.');

        AvarTable::parse(new BinaryReader(self::u16(1) . self::u16(0) . self::u16(0) . self::u16(1), 'avar'), self::variations());
    }

    private static function variations(): FontVariations
    {
        return new FontVariations([
            new VariationAxis('wght', 100.0, 400.0, 900.0),
            new VariationAxis('wdth', 75.0, 100.0, 125.0),
        ]);
    }

    private static function u16(int $value): string
    {
        return pack('n', $value & 0xFFFF);
    }

    private static function f2dot14(float $value): string
    {
        return self::u16((int) round($value * 16384.0));
    }
}
