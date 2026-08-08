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

namespace Alto\Font\Tests\OpenType\Table;

use Alto\Font\OpenType\Table\TableRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableRecord::class)]
final class TableRecordTest extends TestCase
{
    public function testItStoresTableLocation(): void
    {
        $record = new TableRecord('glyf', 128, 256);

        self::assertSame('glyf', $record->tag);
        self::assertSame(128, $record->offset);
        self::assertSame(256, $record->length);
    }
}
