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

namespace Alto\Font\Tests\Exception;

use Alto\Font\Exception\FontExceptionInterface;
use Alto\Font\Exception\UnsupportedFontException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UnsupportedFontException::class)]
final class UnsupportedFontExceptionTest extends TestCase
{
    public function testItRetainsExceptionContext(): void
    {
        $previous = new RuntimeException('root cause');
        $exception = new UnsupportedFontException('unsupported font', 17, $previous);

        self::assertInstanceOf(FontExceptionInterface::class, $exception);
        self::assertSame('unsupported font', $exception->getMessage());
        self::assertSame(17, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
