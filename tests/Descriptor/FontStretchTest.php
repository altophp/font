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

use Alto\Font\Descriptor\FontStretch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontStretch::class)]
final class FontStretchTest extends TestCase
{
    public function testItCreatesNormalStretch(): void
    {
        self::assertSame(100, FontStretch::normal()->percentage);
    }

    #[DataProvider('subfamilies')]
    public function testItInfersStretchFromSubfamily(string $subfamily, int $expected): void
    {
        self::assertSame($expected, FontStretch::fromSubfamily($subfamily)->percentage);
    }

    public function testItFormatsCssValue(): void
    {
        self::assertSame('87%', (new FontStretch(87))->css());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function subfamilies(): iterable
    {
        yield 'ultra condensed' => ['Ultra Condensed', 50];
        yield 'extra condensed' => ['Extra Condensed', 62];
        yield 'condensed' => ['Condensed', 75];
        yield 'semi condensed' => ['Semi Condensed', 87];
        yield 'regular' => ['Regular', 100];
        yield 'semi expanded' => ['Semi Expanded', 112];
        yield 'expanded' => ['Expanded', 125];
        yield 'extra expanded' => ['Extra Expanded', 150];
        yield 'ultra expanded' => ['Ultra Expanded', 200];
    }
}
