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

use Alto\Font\Descriptor\FontStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontStyle::class)]
final class FontStyleTest extends TestCase
{
    #[DataProvider('subfamilies')]
    public function testItInfersStyleFromSubfamily(string $subfamily, FontStyle $expected): void
    {
        self::assertSame($expected, FontStyle::fromSubfamily($subfamily));
    }

    /**
     * @return iterable<string, array{string, FontStyle}>
     */
    public static function subfamilies(): iterable
    {
        yield 'regular' => ['Regular', FontStyle::Normal];
        yield 'italic' => ['Bold Italic', FontStyle::Italic];
        yield 'oblique' => ['Medium Oblique', FontStyle::Oblique];
    }
}
