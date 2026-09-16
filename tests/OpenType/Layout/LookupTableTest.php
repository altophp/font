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

use Alto\Font\OpenType\Layout\LookupTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LookupTable::class)]
final class LookupTableTest extends TestCase
{
    public function testItKeepsACompactedLookupBeforeSerialization(): void
    {
        $lookup = new LookupTable(4, 0x0010, 3, ['first', 'second'], true);

        self::assertSame(4, $lookup->type);
        self::assertSame(0x0010, $lookup->flag);
        self::assertSame(3, $lookup->markFilteringSet);
        self::assertSame(['first', 'second'], $lookup->subtables);
        self::assertTrue($lookup->extension);
    }

    public function testItRejectsAnEmptyLookup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain at least one subtable');

        new LookupTable(1, 0, null, []);
    }
}
