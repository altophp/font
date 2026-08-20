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

namespace Alto\Font\Tests\Text;

use Alto\Font\Exception\InvalidTextException;
use Alto\Font\Text\UnicodeString;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnicodeString::class)]
final class UnicodeStringTest extends TestCase
{
    public function testItReturnsUnicodeCodepoints(): void
    {
        self::assertSame([0x41, 0xE9, 0x20AC, 0x1F600], UnicodeString::codepoints('Aé€😀'));
    }

    public function testItRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidTextException::class);
        $this->expectExceptionMessage('Text must be valid UTF-8.');

        UnicodeString::codepoints("\xFF");
    }
}
