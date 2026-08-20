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
use Alto\Font\Exception\InvalidTextException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidTextException::class)]
final class InvalidTextExceptionTest extends TestCase
{
    public function testItPreservesExceptionContext(): void
    {
        $previous = new \RuntimeException('previous');
        $exception = new InvalidTextException('invalid text', 17, $previous);

        self::assertSame('invalid text', $exception->getMessage());
        self::assertSame(17, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertInstanceOf(FontExceptionInterface::class, $exception);
        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
    }
}
