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

namespace Alto\Font\Tests\OpenType\Layout;

use Alto\Font\Binary\BinaryReader;
use Alto\Font\Exception\InvalidFontException;
use Alto\Font\OpenType\Layout\LookupHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LookupHeader::class)]
final class LookupHeaderTest extends TestCase
{
    public function testItPreservesAliasedAndReorderedSubtableOffsets(): void
    {
        $header = LookupHeader::parse(new BinaryReader(pack('n*', 1, 0, 3, 14, 12, 14, 1, 1), 'shared lookup'), 0, 'GSUB', 0);

        self::assertSame(1, $header->type);
        self::assertSame(0, $header->flag);
        self::assertNull($header->markFilteringSet);
        self::assertSame([14, 12, 14], $header->subtableOffsets);
    }

    public function testItReadsAMarkFilteringSetAtANonzeroLookupBase(): void
    {
        $data = "padding" . pack('n*', 9, 0x10, 1, 10, 27, 1, 1, 0, 8);
        $header = LookupHeader::parse(new BinaryReader($data, 'filtered lookup'), 7, 'GPOS', 3);

        self::assertSame(9, $header->type);
        self::assertSame(0x10, $header->flag);
        self::assertSame(27, $header->markFilteringSet);
        self::assertSame([10], $header->subtableOffsets);
    }

    #[DataProvider('invalidHeaders')]
    public function testItRejectsInvalidHeaders(string $data): void
    {
        $this->expectException(InvalidFontException::class);

        LookupHeader::parse(new BinaryReader($data, 'invalid lookup'), 0, 'GSUB', 0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'empty lookup' => [pack('n*', 1, 0, 0)];
        yield 'truncated offset array' => [pack('n*', 1, 0, 2, 8)];
        yield 'truncated mark filtering set' => [pack('n*', 1, 0x10, 1, 10)];
        yield 'NULL subtable' => [pack('n*', 1, 0, 1, 0)];
        yield 'offset array overlap' => [pack('n*', 1, 0, 1, 6, 1)];
        yield 'mark filtering overlap' => [pack('n*', 1, 0x10, 1, 8, 1)];
        yield 'outside source' => [pack('n*', 1, 0, 1, 10, 1)];
    }
}
