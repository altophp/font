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
use Alto\Font\Exception\GlyphNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphNotFoundException::class)]
final class GlyphNotFoundExceptionTest extends TestCase
{
    public function testItPreservesExceptionContext(): void
    {
        $previous = new \RuntimeException('previous');
        $exception = new GlyphNotFoundException('missing glyph', 17, $previous);

        self::assertSame('missing glyph', $exception->getMessage());
        self::assertSame(17, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertInstanceOf(FontExceptionInterface::class, $exception);
    }
}
