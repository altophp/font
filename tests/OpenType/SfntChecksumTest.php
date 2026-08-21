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

namespace Alto\Font\Tests\OpenType;

use Alto\Font\OpenType\SfntChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SfntChecksum::class)]
final class SfntChecksumTest extends TestCase
{
    public function testItCalculatesChecksumsWithFourBytePadding(): void
    {
        self::assertSame(0, SfntChecksum::calculate(''));
        self::assertSame(0x01020300, SfntChecksum::calculate("\x01\x02\x03"));
    }

    public function testItWrapsUnsignedThirtyTwoBitSums(): void
    {
        self::assertSame(1, SfntChecksum::calculate("\xFF\xFF\xFF\xFF\x00\x00\x00\x02"));
    }

    public function testItCalculatesLargeInputsAcrossInternalChunks(): void
    {
        $data = str_repeat("\x12\x34\x56\x78", 65_537);

        self::assertSame($this->referenceChecksum($data), SfntChecksum::calculate($data));
    }

    private function referenceChecksum(string $data): int
    {
        $padding = (4 - (strlen($data) % 4)) % 4;
        $data .= str_repeat("\0", $padding);
        $sum = 0;

        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += 4) {
            $word = (ord($data[$offset]) << 24)
                | (ord($data[$offset + 1]) << 16)
                | (ord($data[$offset + 2]) << 8)
                | ord($data[$offset + 3]);
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
