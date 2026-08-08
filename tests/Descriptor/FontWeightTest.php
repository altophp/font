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

namespace Alto\Font\Tests\Descriptor;

use Alto\Font\Descriptor\FontWeight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontWeight::class)]
final class FontWeightTest extends TestCase
{
    public function testItCreatesCommonWeights(): void
    {
        self::assertSame(400, FontWeight::normal()->value);
        self::assertSame(700, FontWeight::bold()->value);
    }

    #[DataProvider('subfamilies')]
    public function testItInfersWeightFromSubfamily(string $subfamily, int $expected): void
    {
        self::assertSame($expected, FontWeight::fromSubfamily($subfamily)->value);
    }

    public function testItFormatsCssValue(): void
    {
        self::assertSame('650', (new FontWeight(650))->css());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function subfamilies(): iterable
    {
        yield 'thin' => ['Thin', 100];
        yield 'extra light' => ['Extra Light', 200];
        yield 'ultra light' => ['Ultra Light', 200];
        yield 'light' => ['Light', 300];
        yield 'regular' => ['Regular', 400];
        yield 'medium' => ['Medium', 500];
        yield 'semi bold' => ['Semi Bold', 600];
        yield 'demi bold' => ['Demi Bold', 600];
        yield 'bold' => ['Bold', 700];
        yield 'extra bold' => ['Extra Bold', 800];
        yield 'ultra bold' => ['Ultra Bold', 800];
        yield 'black' => ['Black', 900];
        yield 'heavy' => ['Heavy', 900];
    }
}
