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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FontExceptionInterfaceTest extends TestCase
{
    public function testItIsAThrowableContract(): void
    {
        $exception = new class ('failure') extends \RuntimeException implements FontExceptionInterface {};

        self::assertInstanceOf(\Throwable::class, $exception);
        self::assertInstanceOf(FontExceptionInterface::class, $exception);
    }
}
