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

namespace Alto\Font\Tests\Metadata;

use Alto\Font\Metadata\FontFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FontFormat::class)]
final class FontFormatTest extends TestCase
{
    #[DataProvider('paths')]
    public function testItInfersFormatFromPath(string $path, FontFormat $expected): void
    {
        self::assertSame($expected, FontFormat::fromPath($path));
    }

    /**
     * @return iterable<string, array{string, FontFormat}>
     */
    public static function paths(): iterable
    {
        yield 'ttf' => ['font.ttf', FontFormat::TrueType];
        yield 'otf' => ['font.otf', FontFormat::OpenType];
        yield 'woff' => ['font.woff', FontFormat::Woff];
        yield 'woff2' => ['font.woff2', FontFormat::Woff2];
        yield 'ttc' => ['font.ttc', FontFormat::TrueTypeCollection];
        yield 'otc' => ['font.otc', FontFormat::TrueTypeCollection];
        yield 'case insensitive' => ['font.TTF', FontFormat::TrueType];
        yield 'unknown' => ['font.bin', FontFormat::Unknown];
    }
}
